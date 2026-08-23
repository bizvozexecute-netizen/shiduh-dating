# -*- coding: utf-8 -*-
"""
Эмбеддеры для RAG-ретривера (ТЗ Э2.1).

Единая точка подмены. Контракт (`Embedder`): `.embed(texts) -> np.ndarray`
формы (n, dim), L2-нормированная, детерминированная; свойства `.name`, `.dim`.

- `HashingEmbedder` — оффлайн, без ключа: мешок слов по стабильному хешу
  (crc32) со знаком. Косинус на нём отражает лексическое пересечение —
  этого хватает, чтобы построить и протестировать ретривер и сервис уже
  сейчас, до прихода ключа OpenRouter.
- `OpenRouterEmbedder` — реальные семантические эмбеддинги (Э2.1-b). Ждёт
  ключ; подключается как замена HashingEmbedder без изменений в ретривере.
"""
from __future__ import annotations

import re
import zlib
from typing import List

import numpy as np

# Слова: буквы (вкл. кириллицу) и цифры. Регистр не важен — приводим к нижнему.
_WORD_RE = re.compile(r"\w+", re.UNICODE)


def _tokenize(text: str) -> List[str]:
    return _WORD_RE.findall(text.lower())


class HashingEmbedder:
    """Детерминированный оффлайн-эмбеддер (bag-of-words по хешу)."""

    name = "hashing-v1"

    def __init__(self, dim: int = 512):
        if dim <= 0:
            raise ValueError("dim должен быть положительным")
        self._dim = dim

    @property
    def dim(self) -> int:
        return self._dim

    def embed(self, texts: List[str]) -> np.ndarray:
        out = np.zeros((len(texts), self._dim), dtype=np.float32)
        for i, text in enumerate(texts):
            for token in _tokenize(text or ""):
                # стабильный (между процессами) хеш; знак — из старшего бита,
                # чтобы разные слова не только складывались, но и гасили друг друга
                h = zlib.crc32(token.encode("utf-8"))
                bucket = h % self._dim
                sign = 1.0 if (h >> 31) & 1 else -1.0
                out[i, bucket] += sign
            norm = np.linalg.norm(out[i])
            if norm > 0:
                out[i] /= norm
        return out


class OpenRouterEmbedder:
    """
    Реальные эмбеддинги через OpenAI-совместимый API (OpenRouter) — Э2.1-b.

    Пока нет ключа, используется HashingEmbedder. Класс готов к включению:
    задать ключ/модель в конфиге и выбрать embedder='openrouter'.
    """

    name = "openrouter"

    def __init__(self, api_key: str, model: str, base_url: str, dim: int,
                 timeout: float = 30.0, max_items: int = 256, max_tokens: int = 200000):
        if not api_key:
            raise ValueError("OpenRouterEmbedder требует api_key (ключ OpenRouter)")
        self._api_key = api_key
        self._model = model
        self._base_url = base_url.rstrip("/")
        self._dim = dim
        self._timeout = timeout
        self._max_items = max_items
        self._max_tokens = max_tokens

    @property
    def dim(self) -> int:
        return self._dim

    def _batches(self, texts: List[str]):
        # Эндпоинт ограничивает и число входов, и суммарные токены на запрос
        # (у OpenAI text-embedding-3-small — 300k). Дробим, оценивая токены
        # грубо как ~символы/4, с запасом от лимита.
        batch: List[str] = []
        tok = 0
        for t in texts:
            est = len(t) // 4 + 1
            if batch and (len(batch) >= self._max_items or tok + est > self._max_tokens):
                yield batch
                batch, tok = [], 0
            batch.append(t)
            tok += est
        if batch:
            yield batch

    def embed(self, texts: List[str]) -> np.ndarray:
        import httpx  # локальный импорт: не тянем зависимость, пока эмбеддер не выбран

        texts = list(texts)
        if not texts:
            return np.zeros((0, self._dim), dtype=np.float32)

        rows: List[list] = []
        for batch in self._batches(texts):
            resp = httpx.post(
                self._base_url + "/embeddings",
                headers={"Authorization": "Bearer " + self._api_key},
                json={"model": self._model, "input": batch},
                timeout=self._timeout,
            )
            resp.raise_for_status()
            # порядок ответа не гарантирован (прокси) — восстанавливаем по index
            data = sorted(resp.json()["data"], key=lambda r: r["index"])
            rows.extend(row["embedding"] for row in data)

        vecs = np.array(rows, dtype=np.float32)
        # самокоррекция размерности от реального ответа модели
        self._dim = vecs.shape[1]
        norms = np.linalg.norm(vecs, axis=1, keepdims=True)
        norms[norms == 0] = 1.0
        return vecs / norms
