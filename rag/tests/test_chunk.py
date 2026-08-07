# -*- coding: utf-8 -*-
"""
Тесты нарезки текста на чанки для RAG.

ТЗ Э2.1: чанки ~500-800 токенов с перекрытием ~100. Токены считаем
tiktoken (cl100k_base — кодировка эмбеддинг-моделей OpenAI). Нарезка идёт
по границам предложений, чтобы не рвать фразу посередине, и соседние
чанки перекрываются, чтобы контекст не терялся на стыке.
"""
import pytest
from rag.ingest.chunk import count_tokens, split_sentences, chunk_text


def make_sentences(n):
    # уникальные предложения (~20 токенов): нужны, чтобы перекрытие чанков
    # было проверяемо — на одинаковых предложениях его не отличить от повтора
    return " ".join(
        "Мир в доме под номером {} строится на милосердии и любви к близким.".format(i)
        for i in range(n)
    )


class TestCountTokens:
    def test_counts_positive(self):
        assert count_tokens("Благодарность и мир") > 0

    def test_empty_is_zero(self):
        assert count_tokens("") == 0

    def test_longer_text_more_tokens(self):
        assert count_tokens("слово " * 100) > count_tokens("слово " * 10)


class TestSplitSentences:
    def test_splits_on_period(self):
        s = split_sentences("Первое предложение. Второе предложение.")
        assert len(s) == 2

    def test_keeps_terminator(self):
        s = split_sentences("Вопрос? Ответ!")
        assert s[0].endswith("?")
        assert s[1].endswith("!")

    def test_no_empty_pieces(self):
        s = split_sentences("Текст.   \n\n  Ещё текст.")
        assert all(piece.strip() for piece in s)


class TestChunkText:
    def test_empty_text_no_chunks(self):
        assert chunk_text("") == []

    def test_short_text_single_chunk(self):
        text = "Одно короткое предложение о мире в доме."
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100)
        assert len(chunks) == 1
        assert chunks[0] == text

    def test_long_text_splits_into_several(self):
        text = make_sentences(200)  # ~4000 токенов
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100)
        assert len(chunks) >= 4

    def test_every_chunk_within_hard_max(self):
        text = make_sentences(300)
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100, hard_max=800)
        for c in chunks:
            assert count_tokens(c) <= 800, "чанк превысил жёсткий предел"

    def test_chunks_are_not_trivially_small(self):
        # кроме последнего, чанки должны быть близки к целевому размеру
        text = make_sentences(300)
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100)
        for c in chunks[:-1]:
            assert count_tokens(c) >= 400, "чанк слишком мелкий — нарезка неверна"

    def test_adjacent_chunks_overlap(self):
        text = make_sentences(300)
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100)
        assert len(chunks) >= 2
        # хвостовое предложение первого чанка встречается во втором
        first_sentences = split_sentences(chunks[0])
        tail = first_sentences[-1]
        assert tail in chunks[1], "соседние чанки не перекрываются"

    def test_overlap_zero_gives_no_overlap(self):
        text = make_sentences(300)
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=0)
        first_sentences = split_sentences(chunks[0])
        assert first_sentences[-1] not in chunks[1]

    def test_no_content_lost(self):
        # каждое исходное предложение попадает хотя бы в один чанк
        text = make_sentences(120)
        chunks = chunk_text(text, target_tokens=650, overlap_tokens=100)
        joined = " ".join(chunks)
        for sent in split_sentences(text):
            assert sent in joined, "предложение потеряно при нарезке"

    def test_oversized_single_sentence_is_split(self):
        # одно предложение длиннее hard_max должно быть разбито, а не выброшено
        giant = "слово " * 2000 + "."
        chunks = chunk_text(giant, target_tokens=650, overlap_tokens=100, hard_max=800)
        assert len(chunks) >= 3
        for c in chunks:
            assert count_tokens(c) <= 800
