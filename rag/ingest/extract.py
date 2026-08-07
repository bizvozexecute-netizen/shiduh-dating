# -*- coding: utf-8 -*-
"""
Извлечение сырого текста из книг: PDF (текстовый слой) и DOCX.

Сканы без текстового слоя не поддерживаются — их надо прогнать через OCR
отдельно. Функция extract_pdf возвращает и текст, и признак «похоже на
скан», чтобы конвейер мог честно отказаться, а не молча выдать пустоту.
"""
import os


def extract_pdf(path):
    """PDF -> (текст, is_probably_scan).

    Страницы склеиваются через разрыв абзаца. Если извлечённого текста
    почти нет, а картинок много — это скан, is_probably_scan=True.
    """
    import fitz  # pymupdf

    doc = fitz.open(path)
    pages, total_chars, total_images = [], 0, 0
    for pg in doc:
        t = pg.get_text("text")
        pages.append(t)
        total_chars += len(t.strip())
        total_images += len(pg.get_images())
    n = doc.page_count
    doc.close()

    avg_chars = total_chars / n if n else 0
    is_scan = avg_chars < 80 and total_images > 0
    return "\n\n".join(pages), is_scan


def extract_docx(path):
    """DOCX -> текст. Абзацы разделяются пустой строкой."""
    from docx import Document

    doc = Document(path)
    paras = [p.text for p in doc.paragraphs if p.text and p.text.strip()]
    return "\n\n".join(paras)


def extract(path):
    """Диспетчер по расширению. Возвращает (текст, is_scan)."""
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        return extract_pdf(path)
    if ext == ".docx":
        return extract_docx(path), False
    raise ValueError("Неподдерживаемый формат: %s" % ext)
