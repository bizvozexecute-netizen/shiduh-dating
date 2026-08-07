# -*- coding: utf-8 -*-
"""
Оффлайн-конвейер индексации книг: извлечение -> чистка -> нарезка.

Результат — JSONL с чанками (без эмбеддингов; их считает следующая фаза,
когда будет ключ провайдера). Формат строки соответствует будущей таблице
rag_chunks из ТЗ: book, chapter_hint, content, token_count.

Запуск:
    python -m rag.ingest.run_ingest --books books --out rag/data/chunks.jsonl
"""
import argparse
import glob
import hashlib
import json
import os
import sys

from rag.ingest.extract import extract
from rag.ingest.clean import clean_text
from rag.ingest.chunk import chunk_text, count_tokens

# Человекочитаемые имена книг вместо длинных имён файлов.
BOOK_TITLES = {
    "Сад мира. Практическое руководство для настоящего мужчины (Аруш Шалом) (Z-Library)":
        "Сад мира (для мужчины) — Шалом Аруш",
    "_OceanofPDF.com_Womens_Wisdom_-_The_Garden_of_Peace_for_Women_-_Shalom_Arush":
        "The Garden of Peace for Women — Shalom Arush",
    "твой дом": "Твой дом",
    "document_compress 2": "document_compress 2",
}


def title_for(path):
    stem = os.path.splitext(os.path.basename(path))[0]
    return BOOK_TITLES.get(stem, stem)


def ingest_file(path, target_tokens=650, overlap_tokens=100):
    """Один файл -> список чанков-словарей. Скан пропускается (skipped)."""
    text, is_scan = extract(path)
    title = title_for(path)
    if is_scan:
        return {"book": title, "skipped": "scan", "chunks": []}

    cleaned = clean_text(text)
    pieces = chunk_text(cleaned, target_tokens, overlap_tokens)
    chunks = []
    for i, content in enumerate(pieces):
        cid = hashlib.sha1(("%s#%d" % (title, i)).encode("utf-8")).hexdigest()[:16]
        chunks.append({
            "id": cid,
            "book": title,
            "chapter_hint": None,   # заполнит следующая фаза при желании
            "chunk_index": i,
            "content": content,
            "token_count": count_tokens(content),
        })
    return {"book": title, "skipped": None, "chunks": chunks}


def main(argv=None):
    ap = argparse.ArgumentParser(description="Индексация книг в чанки для RAG")
    ap.add_argument("--books", default="books", help="папка с книгами")
    ap.add_argument("--out", default="rag/data/chunks.jsonl", help="выходной JSONL")
    ap.add_argument("--target", type=int, default=650)
    ap.add_argument("--overlap", type=int, default=100)
    args = ap.parse_args(argv)

    paths = sorted(
        glob.glob(os.path.join(args.books, "*.pdf"))
        + glob.glob(os.path.join(args.books, "*.docx"))
    )
    if not paths:
        print("В папке %s книг не найдено." % args.books, file=sys.stderr)
        return 1

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    total_chunks, total_tokens = 0, 0
    with open(args.out, "w", encoding="utf-8") as f:
        for path in paths:
            res = ingest_file(path, args.target, args.overlap)
            if res["skipped"]:
                print("  ПРОПУЩЕНА (%s): %s" % (res["skipped"], res["book"]))
                continue
            for ch in res["chunks"]:
                f.write(json.dumps(ch, ensure_ascii=False) + "\n")
            n = len(res["chunks"])
            toks = sum(c["token_count"] for c in res["chunks"])
            total_chunks += n
            total_tokens += toks
            print("  %-46s %4d чанков, %6d токенов" % (res["book"][:46], n, toks))

    print("\nИтого: %d чанков, %d токенов -> %s" % (total_chunks, total_tokens, args.out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
