# -*- coding: utf-8 -*-
"""
Нарезка очищенного текста книги на чанки для эмбеддингов.

ТЗ Э2.1: ~500-800 токенов на чанк, перекрытие ~100. Режем по границам
предложений (не рвём фразу), соседние чанки перекрываются хвостом, чтобы
на стыке не терялся контекст. Токены считаем tiktoken cl100k_base —
это кодировка эмбеддинг-моделей OpenAI, значит счёт совпадёт с тем, что
увидит модель на этапе эмбеддингов.
"""
import re
import tiktoken

_ENC = tiktoken.get_encoding("cl100k_base")


def count_tokens(text):
    if not text:
        return 0
    return len(_ENC.encode(text))


# Конец предложения: . ! ? … возможно с закрывающей кавычкой/скобкой,
# за которым пробел/перевод строки или конец текста.
_SENT_END = re.compile(r'(?<=[.!?…])["»”)]*\s+')


def split_sentences(text):
    """Грубое деление на предложения с сохранением знаков препинания.

    Сокращения вроде «т. д.» дадут лишний разрыв — для чанкинга это
    несущественно, чанк всё равно собирается из нескольких предложений.
    """
    parts = _SENT_END.split(text.strip())
    return [p.strip() for p in parts if p and p.strip()]


def _split_long_sentence(sentence, hard_max):
    """Дробит предложение длиннее hard_max по словам, не превышая предел."""
    words = sentence.split()
    pieces, cur = [], []
    for w in words:
        if cur and count_tokens(" ".join(cur + [w])) > hard_max:
            pieces.append(" ".join(cur))
            cur = [w]
        else:
            cur.append(w)
    if cur:
        pieces.append(" ".join(cur))
    return pieces


def chunk_text(text, target_tokens=650, overlap_tokens=100, hard_max=800):
    """Режет текст на перекрывающиеся чанки по границам предложений.

    Возвращает список строк. Пустой текст -> пустой список.
    """
    text = (text or "").strip()
    if not text:
        return []

    # Предварительно разбиваем слишком длинные предложения, чтобы ни один
    # фрагмент не превышал hard_max.
    sentences = []
    for s in split_sentences(text):
        if count_tokens(s) > hard_max:
            sentences.extend(_split_long_sentence(s, hard_max))
        else:
            sentences.append(s)

    if not sentences:
        return []

    chunks = []
    cur, cur_tokens = [], 0

    for sent in sentences:
        st = count_tokens(sent)
        # Закрываем текущий чанк, если добавление предложения выводит за
        # целевой размер, а в чанке уже что-то есть.
        if cur and cur_tokens + st > target_tokens:
            chunks.append(" ".join(cur))
            # Новый чанк начинаем с хвоста предыдущего — перекрытие.
            cur, cur_tokens = _tail_overlap(cur, overlap_tokens)
        cur.append(sent)
        cur_tokens += st

    if cur:
        chunks.append(" ".join(cur))

    return chunks


def _tail_overlap(sentences, overlap_tokens):
    """Возвращает (хвостовые предложения, их токены), дающие ~overlap_tokens
    для затравки следующего чанка. При overlap_tokens=0 — пусто."""
    if overlap_tokens <= 0:
        return [], 0
    tail, tokens = [], 0
    for sent in reversed(sentences):
        st = count_tokens(sent)
        if tail and tokens + st > overlap_tokens:
            break
        if not tail and st > overlap_tokens:
            # первое же хвостовое предложение больше окна перекрытия
            # (например, огромный разбитый фрагмент) — перекрывать нечем,
            # иначе следующий чанк раздуется за hard_max
            break
        tail.insert(0, sent)
        tokens += st
    return tail, tokens
