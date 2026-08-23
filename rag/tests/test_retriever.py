# -*- coding: utf-8 -*-
"""
Тесты ретривера RAG (ТЗ Э2.1-c): top-k по косинусной близости.

Ретривер эмбеддит контент чанков один раз при построении, а на запрос
возвращает k ближайших с оценкой. Метаданные чанка (id/book/chapter_hint)
сохраняются. Работает на любом эмбеддере, соответствующем контракту.
"""
import pytest

from rag.service.embedder import HashingEmbedder
from rag.service.retriever import Retriever


CHUNKS = [
    {"id": "a1", "book": "Мир в доме", "chapter_hint": "гл.1",
     "content": "Мир в доме строится на любви и уважении между супругами."},
    {"id": "a2", "book": "Мир в доме", "chapter_hint": "гл.2",
     "content": "Уважение к жене и терпение — основа согласия в семье."},
    {"id": "b1", "book": "Разное", "chapter_hint": None,
     "content": "Квартальная налоговая отчётность и бухгалтерский баланс."},
    {"id": "b2", "book": "Разное", "chapter_hint": None,
     "content": "Прогноз погоды на выходные: дождь и сильный ветер."},
]


@pytest.fixture
def retriever():
    return Retriever(CHUNKS, HashingEmbedder(dim=256))


class TestBuild:
    def test_indexes_all_chunks(self, retriever):
        assert retriever.size == 4


class TestSearch:
    def test_returns_k_results(self, retriever):
        res = retriever.search("любовь и уважение в семье", k=2)
        assert len(res) == 2

    def test_scores_descending(self, retriever):
        res = retriever.search("уважение в семье", k=4)
        scores = [r["score"] for r in res]
        assert scores == sorted(scores, reverse=True)

    def test_relevant_chunk_ranks_first(self, retriever):
        res = retriever.search("любовь и уважение между супругами", k=1)
        assert res[0]["id"] in ("a1", "a2"), "семейный запрос должен поднять семейные чанки"

    def test_result_carries_metadata(self, retriever):
        res = retriever.search("семья", k=1)
        r = res[0]
        for key in ("id", "book", "chapter_hint", "content", "score"):
            assert key in r

    def test_k_capped_at_corpus_size(self, retriever):
        res = retriever.search("что угодно", k=100)
        assert len(res) == 4

    def test_k_zero_returns_empty(self, retriever):
        assert retriever.search("текст", k=0) == []

    def test_empty_query_returns_empty(self, retriever):
        # запрос без известных слов → нулевой вектор → не выдаём мусор
        assert retriever.search("", k=3) == []
