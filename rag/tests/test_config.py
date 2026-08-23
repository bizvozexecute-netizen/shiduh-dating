# -*- coding: utf-8 -*-
"""
Тесты конфигурации RAG-сервиса (ТЗ Э2.1-c).

Настройки — из окружения с дефолтами. Выбор эмбеддера: по умолчанию
оффлайн-заглушка; 'openrouter' — только когда задан ключ (Э2.1-b).
Чанки читаются из jsonl (формат фазы 1).
"""
import json

import pytest

from rag.service.config import Settings, make_embedder, load_chunks
from rag.service.embedder import HashingEmbedder, OpenRouterEmbedder


class TestMakeEmbedder:
    def test_default_is_hashing(self):
        emb = make_embedder(Settings(embedder="hashing", embed_dim=128))
        assert isinstance(emb, HashingEmbedder)
        assert emb.dim == 128

    def test_openrouter_without_key_falls_back_to_hashing(self):
        # выбрали openrouter, но ключа нет → безопасный фолбэк на заглушку
        emb = make_embedder(Settings(embedder="openrouter", openrouter_api_key=""))
        assert isinstance(emb, HashingEmbedder)

    def test_openrouter_with_key(self):
        emb = make_embedder(Settings(
            embedder="openrouter", openrouter_api_key="sk-test",
            embed_model="text-embedding-3-small", embed_dim=1536,
        ))
        assert isinstance(emb, OpenRouterEmbedder)


class TestLoadChunks:
    def test_reads_jsonl(self, tmp_path):
        p = tmp_path / "chunks.jsonl"
        rows = [
            {"id": "1", "book": "B", "chapter_hint": None, "content": "первый"},
            {"id": "2", "book": "B", "chapter_hint": "x", "content": "второй"},
        ]
        p.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows), encoding="utf-8")
        loaded = load_chunks(str(p))
        assert len(loaded) == 2
        assert loaded[0]["content"] == "первый"

    def test_skips_blank_lines(self, tmp_path):
        p = tmp_path / "chunks.jsonl"
        p.write_text('{"id":"1","content":"a"}\n\n  \n{"id":"2","content":"b"}\n', encoding="utf-8")
        assert len(load_chunks(str(p))) == 2

    def test_missing_file_raises(self):
        with pytest.raises(FileNotFoundError):
            load_chunks("nope/does_not_exist.jsonl")
