# -*- coding: utf-8 -*-
"""
Чистка сырого текста, извлечённого из книг, перед нарезкой на чанки.

Убирает артефакты оцифровки, портящие эмбеддинги и цитаты: переносы слов
(дефис и мягкий перенос U+00AD в конце строки), колонтитулы (номер страницы
+ название книги), лишние пустые строки, а также склеивает визуальные
переносы строк внутри абзаца в сплошной текст. Не пытается восстановить
буквицы в начале разделов ('Благодаря' -> 'олагодаря') — это ненадёжно,
а локальный шум эмбеддингам не мешает.
"""
import re

SOFT_HYPHEN = "­"


def strip_soft_hyphens(text):
    """Убирает мягкие переносы U+00AD, оставшиеся внутри строк."""
    return text.replace(SOFT_HYPHEN, "")


def join_hyphenated(text):
    """Склеивает слово, разорванное переносом в конце строки.

    Знаком переноса считается дефис ИЛИ мягкий перенос U+00AD прямо перед
    разрывом строки: 'домоча-\\nдцам' и 'раз\\u00ad\\nный' -> одно слово.
    Дефис внутри строки (во-первых) не трогаем.
    """
    return re.sub(r"(\w)[-­][ \t]*\n[ \t]*(\w)", r"\1\2", text)


# Колонтитулы: строка-номер страницы, «номер | Название», либо строка целиком
# из прописных (кириллица/латиница). Повторяются на каждой странице.
_PAGE_NUM = re.compile(r"^\s*\d{1,4}\s*$")
_PAGE_NUM_TITLE = re.compile(r"^\s*\d{1,4}\s*[|]\s*.+$")
_UPPER_HEADER = re.compile(r"^\s*[A-ZА-ЯЁ][A-ZА-ЯЁ0-9 '’|.\-—]{2,}\s*$")


def _is_header_line(line):
    if _PAGE_NUM.match(line):
        return True
    if _PAGE_NUM_TITLE.match(line):
        return True
    if _UPPER_HEADER.match(line):
        letters = [c for c in line if c.isalpha()]
        if letters and all(c.isupper() for c in letters):
            return True
    return False


def strip_running_headers(text):
    """Удаляет строки-колонтитулы: номера страниц и повторяющиеся заголовки
    в верхнем регистре."""
    kept = [ln for ln in text.split("\n") if not _is_header_line(ln)]
    return "\n".join(kept)


def collapse_whitespace(text):
    """Схлопывает пробелы и пустые строки.

    Одиночный перевод строки — визуальный перенос внутри абзаца — становится
    пробелом (рефлоу). Пустая строка (одна или несколько подряд) — граница
    абзаца, сводится к одному разрыву.
    """
    text = "\n".join(ln.strip() for ln in text.split("\n"))
    paragraphs = []
    for para in re.split(r"\n[ \t]*\n+", text):
        para = re.sub(r"[ \t]*\n[ \t]*", " ", para)   # переносы строк -> пробел
        para = re.sub(r"[ \t]+", " ", para).strip()
        if para:
            paragraphs.append(para)
    return "\n\n".join(paragraphs)


def clean_text(raw):
    """Полный конвейер чистки одной книги.

    Порядок важен: сначала склеиваем переносы слов (дефис ИЛИ мягкий перенос
    в конце строки), и только потом убираем оставшиеся мягкие переносы. Иначе
    strip_soft_hyphens сотрёт знак переноса раньше, чем join их склеит, и
    слово останется разорванным.
    """
    text = join_hyphenated(raw)
    text = strip_soft_hyphens(text)
    text = strip_running_headers(text)
    text = collapse_whitespace(text)
    return text
