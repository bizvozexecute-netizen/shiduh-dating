# -*- coding: utf-8 -*-
"""
Конфигурация RAG-сервиса (ТЗ Э2.1-c). Настройки — из окружения с дефолтами;
фабрики эмбеддера и ретривера; чтение чанков из jsonl (формат фазы 1).

Выбор эмбеддера: по умолчанию оффлайн-заглушка HashingEmbedder. Значение
'openrouter' включает реальные эмбеддинги, но ТОЛЬКО при наличии ключа —
иначе безопасный фолбэк на заглушку (Э2.1-b ещё ждёт ключ).
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from typing import Dict, List

from .embedder import HashingEmbedder, OpenRouterEmbedder


def _env(name: str, default: str) -> str:
    v = os.environ.get(name)
    return v if v not in (None, "") else default


@dataclass
class Settings:
    chunks_path: str = "rag/data/chunks.jsonl"
    embedder: str = "hashing"           # 'hashing' | 'openrouter'
    embed_dim: int = 512
    embed_model: str = "text-embedding-3-small"
    openrouter_api_key: str = ""
    openrouter_base_url: str = "https://openrouter.ai/api/v1"
    k_default: int = 5
    k_max: int = 20

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            chunks_path=_env("RAG_CHUNKS_PATH", cls.chunks_path),
            embedder=_env("RAG_EMBEDDER", cls.embedder),
            embed_dim=int(_env("RAG_EMBED_DIM", str(cls.embed_dim))),
            embed_model=_env("RAG_EMBED_MODEL", cls.embed_model),
            openrouter_api_key=_env("OPENROUTER_API_KEY", ""),
            openrouter_base_url=_env("OPENROUTER_BASE_URL", cls.openrouter_base_url),
            k_default=int(_env("RAG_K_DEFAULT", str(cls.k_default))),
            k_max=int(_env("RAG_K_MAX", str(cls.k_max))),
        )


def make_embedder(settings: Settings):
    if settings.embedder == "openrouter" and settings.openrouter_api_key:
        return OpenRouterEmbedder(
            api_key=settings.openrouter_api_key,
            model=settings.embed_model,
            base_url=settings.openrouter_base_url,
            dim=settings.embed_dim,
        )
    # дефолт и безопасный фолбэк (нет ключа) — оффлайн-заглушка
    return HashingEmbedder(dim=settings.embed_dim)


def load_chunks(path: str) -> List[Dict]:
    if not os.path.exists(path):
        raise FileNotFoundError("Файл чанков не найден: " + path)
    rows: List[Dict] = []
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line:
                rows.append(json.loads(line))
    return rows


def build_retriever(settings: Settings):
    from .retriever import Retriever
    return Retriever(load_chunks(settings.chunks_path), make_embedder(settings))
