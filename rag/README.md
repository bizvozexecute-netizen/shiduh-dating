# RAG-сервис

Индексация книг для ИИ-консультанта (ТЗ Э2.1). Отдельный Python-сервис —
решение заказчика; PHP-сайт ходит к нему по HTTP.

## Статус

- ✅ **Фаза 1 — оффлайн-индексация** (эта часть): извлечение → чистка → нарезка на чанки.
- ⛔ Фаза 2 — эмбеддинги + ретривер + FastAPI. Ждёт ключа OpenRouter.

## Структура

```
rag/
├── ingest/
│   ├── extract.py      PDF (текстовый слой) и DOCX → сырой текст
│   ├── clean.py        чистка: переносы, колонтитулы, рефлоу абзацев
│   ├── chunk.py        нарезка ~500–800 токенов с перекрытием ~100
│   └── run_ingest.py   конвейер: books/ → chunks.jsonl
├── tests/              33 теста (pytest)
├── data/               chunks.jsonl (в .gitignore — воспроизводится)
└── requirements.txt
```

## Запуск

Из корня проекта:

```bash
pip install -r rag/requirements.txt
python -m rag.ingest.run_ingest --books books --out rag/data/chunks.jsonl
python -m pytest rag/tests/ -q
```

## Что важно знать

- **Книги — в `books/`, не в репозитории** (авторское право + размер). Приходят от заказчика отдельно.
- **3 книги индексируются, 1 — скан без текстового слоя** и честно пропускается (`run_ingest` печатает «ПРОПУЩЕНА (scan)»). Для неё нужен OCR или текстовая версия.
- Токены считаются `tiktoken` (cl100k_base) — тем же счётом, что увидит эмбеддинг-модель.
- Формат строки `chunks.jsonl` соответствует будущей таблице `rag_chunks` из ТЗ: `id, book, chapter_hint, content, token_count` (+ `embedding` добавит фаза 2).
