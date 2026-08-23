# -*- coding: utf-8 -*-
"""
Ретривер RAG (ТЗ Э2.1-c): top-k чанков по косинусной близости.

Контент чанков эмбеддится один раз при построении в матрицу (N, dim).
Так как векторы L2-нормированы, косинус = скалярное произведение, а top-k —
одно матричное умножение. Эмбеддер задаётся снаружи (заглушка сейчас,
OpenRouter позже) — ретривер от его выбора не зависит.
"""
from __future__ import annotations

from typing import Dict, List

import numpy as np


class Retriever:
    def __init__(self, chunks: List[Dict], embedder):
        self._chunks = list(chunks)
        self._embedder = embedder
        if self._chunks:
            self._matrix = embedder.embed([c.get("content", "") for c in self._chunks])
        else:
            self._matrix = np.zeros((0, embedder.dim), dtype=np.float32)

    @property
    def size(self) -> int:
        return len(self._chunks)

    @property
    def dim(self) -> int:
        return self._matrix.shape[1] if self._matrix.size else self._embedder.dim

    @property
    def embedder_name(self) -> str:
        return self._embedder.name

    def search(self, query: str, k: int) -> List[Dict]:
        if k <= 0 or not self._chunks:
            return []

        qvec = self._embedder.embed([query])[0]
        if not np.any(qvec):
            # запрос без известных слов → нулевой вектор: релевантности нет
            return []

        scores = self._matrix @ qvec  # косинус (векторы нормированы)
        k = min(k, len(self._chunks))
        # argpartition — top-k без полной сортировки всего массива
        top = np.argpartition(-scores, k - 1)[:k]
        top = top[np.argsort(-scores[top])]

        results = []
        for idx in top:
            c = self._chunks[int(idx)]
            results.append({
                "id": c.get("id"),
                "book": c.get("book"),
                "chapter_hint": c.get("chapter_hint"),
                "content": c.get("content"),
                "score": round(float(scores[idx]), 6),
            })
        return results
