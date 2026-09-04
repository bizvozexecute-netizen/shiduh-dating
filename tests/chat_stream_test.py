# -*- coding: utf-8 -*-
"""
B3 этапа «До идеала»: потоковая выдача ответа консультанта.

ТЗ (Э0.2) просит «потоковый вывод, если бэкенд/фронт позволяют; если нет —
честный индикатор ожидания». Ни того, ни другого не было: пользователь ждал
молча по 10–20 секунд, и пауза выглядела как зависание.

Транспорт проверен пробником до начала работы: Apache в образе отдаёт байты
по мере отправки (mod_deflate не трогает text/event-stream), встроенный сервер
PHP — тоже, поэтому фейковый LLM может отдавать поток так же, как настоящий.

Что здесь проверяется:
  - ответ приходит частями, а не одним куском в конце;
  - собранный из частей текст совпадает с сохранённым в базе;
  - раунд с инструментами работает под потоком (модель просит запись в
    анкету, потом договаривает);
  - обрыв клиентом сохраняет уже показанный текст с пометкой, а не теряет его;
  - ошибка посреди потока НЕ сохраняется как ответ;
  - ошибки до начала потока приходят обычным JSON с настоящим кодом.

    python -m pytest tests/chat_stream_test.py -q
"""
import json
import os
import sys
import time

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, fake_calls, login, post_json, set_fake_mode,
    setting, sse_post, start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
CHAT = "/api/ai_chat.php"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid():
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE))


def setup_module(module):
    start_fake_server()
    use_fake_provider()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()


def wipe():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid())


def events_of(evts, name):
    return [e for e in evts if e[1] == name]


def payloads_of(evts, name):
    return [e[2] for e in evts if e[1] == name]


def stream(text, mode="ok", abort_after=None, **kw):
    """abort_after — параметр ЧТЕНИЯ (закрыть соединение после N кусков),
    остальное — настройки фейковой модели."""
    set_fake_mode(mode, **kw)
    return sse_post(CHAT, sid_of(CANDIDATE), {"message": text}, abort_after=abort_after)


# ------------------------------------------------------------- сам поток ---

def test_stream_delivers_chunks_and_matches_stored_text():
    wipe()
    status, ctype, evts = stream("поток 51413")
    assert status == 200, status
    assert ctype.startswith("text/event-stream"), ctype

    deltas = payloads_of(evts, "delta")
    assert len(deltas) >= 2, "ответ пришёл одним куском: %d частей" % len(deltas)

    done = payloads_of(evts, "done")
    assert done, "нет события завершения: %s" % [e[1] for e in evts]
    joined = "".join(d["text"] for d in deltas)
    assert joined == done[0]["reply"], "склейка частей не совпала с итогом"

    stored = dbq("SELECT content FROM ai_chat_messages WHERE id=%d" % done[0]["message_id"])
    assert stored == done[0]["reply"], "в базе сохранён другой текст"


def test_chunks_arrive_over_time_not_at_once():
    """Главное свойство потока: части приходят ПО ХОДУ. Если сервер или прокси
    буферизуют, все события упадут разом в конце — и смысл теряется."""
    wipe()
    long_text = "медленно 27182 " + "расскажу о себе подробно и обстоятельно " * 3
    status, ctype, evts = stream(long_text, mode="ok", chunk_delay_ms=200)
    deltas = [e for e in evts if e[1] == "delta"]
    assert len(deltas) >= 3, "мало частей: %d" % len(deltas)
    spread = deltas[-1][0] - deltas[0][0]
    assert spread > 0.4, "все части пришли разом (разброс %.2f с)" % spread


def test_meta_comes_before_text():
    """Клиенту нужен id реплики до появления текста: по нему он повторяет
    отправку без дубля, если связь оборвётся."""
    wipe()
    status, ctype, evts = stream("мета 31415")
    names = [e[1] for e in evts]
    assert "meta" in names, names
    assert names.index("meta") < names.index("delta"), names
    meta = payloads_of(evts, "meta")[0]
    newest = dbq("SELECT MAX(id) FROM ai_chat_messages WHERE user_id=%d AND role='user'" % uid())
    assert str(meta["user_message_id"]) == newest, meta


def test_heartbeat_keeps_connection_alive():
    """Пока модель думает, по каналу должны идти служебные строки: без них
    прокси и мобильные сети рвут «молчащее» соединение."""
    wipe()
    status, ctype, evts = stream("сердцебиение 60221", mode="ok", pre_delay_ms=12000)
    assert any(e[1] == "comment" for e in evts), "нет служебных строк за 12 секунд ожидания"


# -------------------------------------------------- инструменты под потоком ---

def test_tool_round_works_under_stream():
    wipe()
    dbq("UPDATE Message204 SET City='' WHERE User_ID=%d" % uid())
    status, ctype, evts = stream("живу в Ашдоде 99887", mode="tool_call",
                                 tool_args={"city": "Ашдод"})
    assert status == 200, status
    tools = payloads_of(evts, "tool")
    assert tools, "нет события об инструменте: %s" % [e[1] for e in evts]
    assert "City" in tools[0].get("applied", []), tools
    assert dbq("SELECT City FROM Message204 WHERE User_ID=%d LIMIT 1" % uid()) == "Ашдод"
    assert payloads_of(evts, "done"), "диалог не завершился после инструмента"
    assert fake_calls() == 2, "ожидались два вызова модели: %s" % fake_calls()


def test_safety_signal_is_not_shown_to_candidate():
    """X-3: сигнал шадхану ставится молча. Кандидат не должен увидеть его ни
    в тексте, ни в служебных событиях."""
    wipe()
    status, ctype, evts = stream("мне очень тяжело 12345", mode="risk_tool")
    tools = payloads_of(evts, "tool")
    assert all(t.get("name") != "report_safety_concern" for t in tools), tools
    raw = json.dumps(evts, ensure_ascii=False)
    assert "safety" not in raw and "сигнал" not in raw.lower(), "сигнал виден кандидату"


# ------------------------------------------------------------ обрыв и сбои ---

def test_abort_keeps_partial_answer_marked():
    """Пользователь нажал «Остановить»: показанный текст уже прочитан, терять
    его нельзя — но и выдавать за полный ответ тоже."""
    wipe()
    status, ctype, evts = stream("остановлюсь 77441 " + "длинный ответ " * 8,
                                 mode="ok", chunk_delay_ms=300, abort_after=2)
    time.sleep(1.5)     # даём серверу дописать строку после обрыва
    row = dbq("SELECT stopped, LENGTH(content) FROM ai_chat_messages "
              "WHERE user_id=%d AND role='assistant' ORDER BY id DESC LIMIT 1" % uid())
    assert row, "частичный ответ не сохранён"
    stopped, length = row.split("\t")
    assert stopped == "1", "ответ не помечен как прерванный"
    assert int(length) > 0, "сохранён пустой ответ"


def test_error_mid_stream_is_not_saved_as_answer():
    """Сбой посреди выдачи — не ответ консультанта: сохранять обрывок как
    полноценную реплику нельзя, человек её не подтверждал."""
    wipe()
    status, ctype, evts = stream("сбой посреди 54321 " + "текст ответа " * 6,
                                 mode="ok", break_after=2)
    errs = payloads_of(evts, "error")
    assert errs, "нет события ошибки: %s" % [e[1] for e in evts]
    assert errs[0].get("partial") is True, errs
    n = dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='assistant'" % uid())
    assert n == "0", "обрывок сохранён как ответ"


def test_errors_before_stream_are_plain_json():
    """Отказ по лимиту или длине приходит до открытия потока — обычным JSON
    с настоящим кодом, чтобы клиент не разбирал события ради «слишком длинно»."""
    wipe()
    status, ctype, evts = sse_post(CHAT, sid_of(CANDIDATE), {"message": "я" * 4100})
    assert status == 400, status
    assert "text/event-stream" not in ctype, ctype


def test_provider_failure_before_first_chunk_is_retryable():
    wipe()
    status, ctype, evts = stream("провайдер лёг 13579", mode="always_500")
    errs = payloads_of(evts, "error")
    assert errs, [e[1] for e in evts]
    assert errs[0].get("retryable") is True, errs
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='assistant'"
               % uid()) == "0"


# ------------------------------------------------------------------ фолбэк ---

def test_non_stream_request_still_returns_json():
    """Клиент без поддержки потока (или прокси, режущий его) обязан получить
    прежний JSON — иначе чат просто перестанет работать."""
    wipe()
    set_fake_mode("ok")
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "обычный ответ 24680"})
    assert code == 200, js
    assert js.get("reply"), js
    assert js.get("message_id"), js


def test_stub_provider_streams_too():
    """Пока ключа нет, поток должен работать на заглушке: иначе интерфейс
    нельзя ни показать заказчику, ни проверить."""
    wipe()
    clear_ai_settings()
    try:
        status, ctype, evts = sse_post(CHAT, sid_of(CANDIDATE), {"message": "заглушка потоком 86420"})
        assert status == 200, status
        deltas = payloads_of(evts, "delta")
        assert len(deltas) >= 2, "заглушка отдала ответ одним куском"
        assert payloads_of(evts, "done"), [e[1] for e in evts]
    finally:
        use_fake_provider()


def test_usage_log_marks_streamed_calls():
    wipe()
    dbq("DELETE FROM `ai_usage_log`")
    stream("учёт потока 11223")
    streamed = dbq("SELECT streamed FROM ai_usage_log ORDER BY id DESC LIMIT 1")
    assert streamed == "1", "вызов не помечен как потоковый: %s" % streamed
