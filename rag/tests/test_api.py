# -*- coding: utf-8 -*-
"""
Тесты HTTP-API RAG-ретривера (ТЗ Э2.1-c). FastAPI TestClient — без живого
сервера. PHP-сайт будет ходить сюда за релевантными фрагментами книг.

  GET  /health            → статус, число чанков, имя эмбеддера, dim
  POST /retrieve {query,k} → top-k фрагментов с оценками
"""
import pytest
from fastapi.testclient import TestClient

from rag.service.app import create_app
from rag.service.embedder import HashingEmbedder
from rag.service.retriever import Retriever


CHUNKS = [
    {"id": "a1", "book": "Мир в доме", "chapter_hint": "гл.1",
     "content": "Мир в доме строится на любви и уважении между супругами."},
    {"id": "a2", "book": "Мир в доме", "chapter_hint": "гл.2",
     "content": "Терпение и уважение к жене — основа согласия в семье."},
    {"id": "b1", "book": "Разное", "chapter_hint": None,
     "content": "Квартальная налоговая отчётность и баланс."},
    {"id": "b2", "book": "Разное", "chapter_hint": None,
     "content": "Прогноз погоды: дождь и ветер на выходных."},
]


@pytest.fixture
def client():
    retriever = Retriever(CHUNKS, HashingEmbedder(dim=256))
    return TestClient(create_app(retriever, k_default=3, k_max=5))


class TestHealth:
    def test_ok(self, client):
        r = client.get("/health")
        assert r.status_code == 200
        body = r.json()
        assert body["status"] == "ok"
        assert body["chunks"] == 4
        assert body["embedder"] == "hashing-v1"
        assert body["dim"] == 256


class TestRetrieve:
    def test_returns_k_results(self, client):
        r = client.post("/retrieve", json={"query": "уважение в семье", "k": 2})
        assert r.status_code == 200
        res = r.json()["results"]
        assert len(res) == 2

    def test_scores_descending_and_shaped(self, client):
        r = client.post("/retrieve", json={"query": "любовь и уважение между супругами", "k": 4})
        res = r.json()["results"]
        scores = [x["score"] for x in res]
        assert scores == sorted(scores, reverse=True)
        for x in res:
            for key in ("id", "book", "chapter_hint", "content", "score"):
                assert key in x

    def test_relevant_first(self, client):
        r = client.post("/retrieve", json={"query": "согласие и уважение в семье", "k": 1})
        assert r.json()["results"][0]["id"] in ("a1", "a2")

    def test_default_k_when_omitted(self, client):
        r = client.post("/retrieve", json={"query": "семья"})
        assert len(r.json()["results"]) == 3  # k_default=3

    def test_k_clamped_to_max(self, client):
        # k=100, k_max=5, корпус=4 → не больше 4, и точно не 100
        r = client.post("/retrieve", json={"query": "семья", "k": 100})
        assert r.status_code == 200
        assert len(r.json()["results"]) == 4

    def test_missing_query_422(self, client):
        r = client.post("/retrieve", json={"k": 3})
        assert r.status_code == 422

    def test_blank_query_400(self, client):
        r = client.post("/retrieve", json={"query": "   ", "k": 3})
        assert r.status_code == 400

    def test_echoes_query_and_k(self, client):
        r = client.post("/retrieve", json={"query": "семья", "k": 2})
        body = r.json()
        assert body["query"] == "семья"
        assert body["k"] == 2
