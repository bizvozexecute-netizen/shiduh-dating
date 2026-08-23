# RAG-сервис

Индексация книг для ИИ-консультанта (ТЗ Э2.1). Отдельный Python-сервис —
решение заказчика; PHP-сайт ходит к нему по HTTP.

## Статус

- ✅ **Фаза 1 — оффлайн-индексация**: извлечение → чистка → нарезка на чанки.
- ✅ **Фаза 2 — ретривер + FastAPI (Э2.1-c)**: косинусный top-k, HTTP-API.
  Работает уже сейчас на оффлайн-эмбеддере (заглушка, без ключа).
- ⛔ **Э2.1-b — реальные эмбеддинги** (OpenRouter). Ждёт ключ; включается
  подменой эмбеддера (`RAG_EMBEDDER=openrouter` + `OPENROUTER_API_KEY`),
  ретривер и API не меняются.

## Структура

```
rag/
├── ingest/             фаза 1: books/ → chunks.jsonl
│   ├── extract.py      PDF (текстовый слой) и DOCX → сырой текст
│   ├── clean.py        чистка: переносы, колонтитулы, рефлоу абзацев
│   ├── chunk.py        нарезка ~500–800 токенов с перекрытием ~100
│   └── run_ingest.py   конвейер
├── service/            фаза 2: ретривер + API
│   ├── embedder.py     HashingEmbedder (оффлайн) + OpenRouterEmbedder (ждёт ключ)
│   ├── retriever.py    косинусный top-k над чанками
│   ├── config.py       настройки из окружения, фабрики
│   └── app.py          FastAPI: /health, /retrieve
├── tests/              69 тестов (pytest)
├── data/               chunks.jsonl (в .gitignore — воспроизводится)
└── requirements.txt
```

## Запуск

Из корня проекта:

```bash
pip install -r rag/requirements.txt
python -m rag.ingest.run_ingest --books books --out rag/data/chunks.jsonl
python -m pytest rag/tests/ -q

# сервис ретривера (оффлайн-эмбеддер по умолчанию):
uvicorn --factory rag.service.app:get_app --host 127.0.0.1 --port 8100
```

## API

- `GET /health` → `{status, chunks, embedder, dim}`
- `POST /retrieve` `{query, k?}` → `{query, k, results:[{id, book, chapter_hint, content, score}]}`
  (`k` по умолчанию 5, ограничен `RAG_K_MAX`=20; `score` — косинус в [-1..1]).

## Настройки (переменные окружения)

| Переменная | Дефолт | Назначение |
|---|---|---|
| `RAG_CHUNKS_PATH` | `rag/data/chunks.jsonl` | путь к чанкам |
| `RAG_EMBEDDER` | `hashing` | `hashing` (оффлайн) или `openrouter` |
| `RAG_EMBED_DIM` | `512` | размерность вектора заглушки |
| `OPENROUTER_API_KEY` | — | ключ; без него `openrouter` откатывается на `hashing` |
| `RAG_EMBED_MODEL` | `text-embedding-3-small` | модель эмбеддингов |
| `RAG_K_DEFAULT` / `RAG_K_MAX` | `5` / `20` | k по умолчанию и максимум |

## Что важно знать

- **Книги — в `books/`, не в репозитории** (авторское право + размер). Приходят от заказчика отдельно.
- **3 книги индексируются, 1 — скан без текстового слоя** и честно пропускается (`run_ingest` печатает «ПРОПУЩЕНА (scan)»). Для неё нужен OCR или текстовая версия.
- Токены считаются `tiktoken` (cl100k_base) — тем же счётом, что увидит эмбеддинг-модель.
- Формат строки `chunks.jsonl` соответствует будущей таблице `rag_chunks` из ТЗ: `id, book, chapter_hint, content, token_count` (+ `embedding` добавит Э2.1-b).
- **Эмбеддер — единственная точка подмены.** `HashingEmbedder` (мешок слов по стабильному хешу) даёт лексическую близость: этого хватает построить и протестировать ретривер и API до ключа. С ключом включается `OpenRouterEmbedder` — семантика лучше, интерфейс тот же.
- Подстановка чанков в промпт и правила цитирования (Э2.1-d) — на стороне ИИ-консультанта; сервис отвечает только за поиск.
