# -*- coding: utf-8 -*-
"""
Тесты чистки текста книг для RAG.

Артефакты, которые чистка обязана убрать, взяты из реальных книг в books/:
переносы слов, мягкие переносы, колонтитулы (номер страницы + название
книги на каждой странице), схлопывание пустых строк. Буквицы в начале
разделов ('Благодаря' -> 'олагодаря') автоматически не восстановить —
их не трогаем, эмбеддинги устойчивы к локальному шуму.
"""
from rag.ingest.clean import (
    join_hyphenated,
    strip_soft_hyphens,
    strip_running_headers,
    collapse_whitespace,
    clean_text,
)


class TestJoinHyphenated:
    def test_joins_word_split_by_hyphen_at_line_end(self):
        # «домоча-\nдцам» -> «домочадцам»
        assert join_hyphenated("домоча-\nдцам") == "домочадцам"

    def test_joins_across_spaces_around_newline(self):
        assert join_hyphenated("изъян, по-\n   казывающий") == "изъян, показывающий"

    def test_does_not_touch_hyphen_inside_line(self):
        # настоящее слово через дефис, не разорванное переносом
        assert join_hyphenated("во-первых, это важно") == "во-первых, это важно"

    def test_keeps_other_newlines(self):
        assert join_hyphenated("конец строки\nновая строка") == "конец строки\nновая строка"


class TestSoftHyphens:
    def test_removes_soft_hyphen(self):
        assert strip_soft_hyphens("мило­сердие") == "милосердие"

    def test_removes_soft_hyphen_before_newline(self):
        assert strip_soft_hyphens("по­\nказывающий") == "по\nказывающий"


class TestRunningHeaders:
    def test_removes_standalone_page_number(self):
        raw = "180\nмочь надо многим — неевреям"
        assert strip_running_headers(raw) == "мочь надо многим — неевреям"

    def test_removes_page_number_with_book_title(self):
        raw = "180 | Women's Wisdom\nlows that every single child"
        assert strip_running_headers(raw) == "lows that every single child"

    def test_removes_repeated_uppercase_header(self):
        # заголовок-колонтитул повторяется на многих страницах
        raw = ("САД МИРА\nПервый абзац текста.\n"
               "САД МИРА\nВторой абзац текста.")
        out = strip_running_headers(raw)
        assert "САД МИРА" not in out
        assert "Первый абзац текста." in out
        assert "Второй абзац текста." in out

    def test_keeps_body_line_with_digits(self):
        # строка с числом внутри осмысленного текста — не колонтитул
        raw = "В 2020 году произошло событие."
        assert strip_running_headers(raw) == "В 2020 году произошло событие."


class TestCollapseWhitespace:
    def test_collapses_multiple_spaces(self):
        assert collapse_whitespace("слово    другое") == "слово другое"

    def test_single_newline_becomes_space(self):
        # одиночный перевод строки — визуальный перенос внутри абзаца
        assert collapse_whitespace("строка одна\nстрока два") == "строка одна строка два"

    def test_paragraph_break_preserved(self):
        assert collapse_whitespace("абзац\n\nследующий") == "абзац\n\nследующий"

    def test_collapses_three_plus_newlines_to_paragraph(self):
        assert collapse_whitespace("абзац\n\n\n\nследующий") == "абзац\n\nследующий"

    def test_trims_edges(self):
        assert collapse_whitespace("  \n текст \n  ") == "текст"


class TestCleanTextPipeline:
    def test_joins_soft_hyphen_at_line_end(self):
        # реальный случай из книги: слово разорвано мягким переносом U+00AD
        # в конце строки — «хо­\nрошо» должно склеиться в «хорошо»
        out = clean_text("жить дей­\nствительно хо­\nрошо каждый день.")
        assert "действительно" in out
        assert "хорошо" in out
        assert "­" not in out

    def test_reflows_paragraph_lines(self):
        # строки одного абзаца склеиваются в сплошной текст
        out = clean_text("Первая часть мысли\nвторая часть той же мысли.")
        assert out == "Первая часть мысли вторая часть той же мысли."

    def test_full_pipeline_on_realistic_fragment(self):
        raw = (
            "181\n"
            "ПРАКТИЧЕСКОЕ РУКОВОДСТВО ПО МИРУ В СЕМЬЕ\n"
            "Отсюда мы понимаем, сколь далеко должна прости-\n"
            "раться благодарность. Песок не живое суще-\n"
            "ство.\n"
            "\n\n\n"
            "182\n"
            "САД МИРА\n"
            "И если нужно быть благодарным."
        )
        out = clean_text(raw)
        assert "простираться" in out
        assert "существо" in out
        assert "181" not in out
        assert "182" not in out
        assert "ПРАКТИЧЕСКОЕ РУКОВОДСТВО ПО МИРУ В СЕМЬЕ" not in out
        assert "САД МИРА" not in out
        # осмысленный текст сохранён
        assert "благодарность" in out
        assert "И если нужно быть благодарным." in out
        # нет тройных переводов строки
        assert "\n\n\n" not in out
