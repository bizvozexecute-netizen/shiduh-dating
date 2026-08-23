# -*- coding: utf-8 -*-
"""
HTTP-API RAG-ретривера (ТЗ Э2.1-c). Отдельный Python-сервис (решение
заказчика): PHP-сайт ходит сюда за релевантными фрагментами книг.

  GET  /health             — статус, число чанков, эмбеддер, dim
  POST /retrieve {query,k} — top-k фрагментов по косинусной близости

Эндпоинт подстановки чанков в промпт и правила цитирования (Э2.1-d) —
на стороне вызывающего ИИ-консультанта; здесь только поиск.
"""
from __future__ import annotations

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field


class RetrieveRequest(BaseModel):
    query: str = Field(..., min_length=1)
    k: int | None = None


def create_app(retriever, k_default: int = 5, k_max: int = 20) -> FastAPI:
    app = FastAPI(title="RAG-ретривер", version="1.0")

    @app.get("/health")
    def health():
        return {
            "status": "ok",
            "chunks": retriever.size,
            "embedder": retriever.embedder_name,
            "dim": retriever.dim,
        }

    @app.post("/retrieve")
    def retrieve(req: RetrieveRequest):
        query = req.query.strip()
        if not query:
            raise HTTPException(status_code=400, detail="Пустой запрос")
        k = req.k if req.k is not None else k_default
        k = max(1, min(int(k), k_max))
        return {"query": query, "k": k, "results": retriever.search(query, k)}

    return app


def get_app() -> FastAPI:
    """Фабрика для uvicorn: строит ретривер из окружения (реальные чанки)."""
    from .config import Settings, build_retriever
    settings = Settings.from_env()
    return create_app(build_retriever(settings), settings.k_default, settings.k_max)
