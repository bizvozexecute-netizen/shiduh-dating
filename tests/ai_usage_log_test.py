# -*- coding: utf-8 -*-
"""
B1 этапа «До идеала»: журнал обращений к модели и потолок ответа.

Расход считался только как tokens_used в строке ответа чата: узнать, сколько
ушло на резюме, обоснования пар и классификацию ответов опроса, было нельзя —
как и ответить на вопрос «сколько мы потратили за месяц» без ручного SQL.
Появляется ai_usage_log: строка на КАЖДЫЙ вызов модели, включая заглушку и
неудачные попытки, с видом вызова, моделью, токенами, задержкой и текстом
ошибки. Из него админ-экран (C9) рисует расход.

Второе: max_tokens не передавался вовсе — длинный ответ мог упереться в
таймаут и потерять весь вызов. Теперь потолок задаётся настройкой.

    python -m pytest tests/ai_usage_log_test.py -q
"""
import os
import sys
import time

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    BASE, clear_ai_settings, dbq, fake_calls, login, post_json, set_fake_mode,
    setting, start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
CHAT = "/api/ai_chat.php"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def setup_module(module):
    start_fake_server()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()


def clear_log():
    dbq("DELETE FROM `ai_usage_log`")


def rows(where="1"):
    """Строки журнала как список словарей (порядок колонок фиксирован)."""
    cols = ("id", "user_id", "kind", "provider", "model", "prompt_tokens",
            "completion_tokens", "total_tokens", "latency_ms", "attempts",
            "streamed", "ok", "error")
    out = dbq("SELECT %s FROM `ai_usage_log` WHERE %s ORDER BY id" % (", ".join("`%s`" % c for c in cols), where))
    res = []
    for line in (out or "").splitlines():
        if not line.strip():
            continue
        res.append(dict(zip(cols, line.split("\t"))))
    return res


def say(text, mode="ok", **kw):
    set_fake_mode(mode, **kw)
    return post_json(CHAT, sid_of(CANDIDATE), {"message": text})


# ------------------------------------------------------------------ таблица ---

def test_usage_log_table_exists():
    assert dbq("SHOW TABLES LIKE 'ai_usage_log'"), "нет таблицы ai_usage_log"


def test_chat_message_writes_one_row():
    use_fake_provider()
    clear_log()
    code, js = say("учёт расхода 51413")
    assert code == 200, js
    r = rows()
    assert len(r) == 1, "ожидалась одна строка журнала, получено %d" % len(r)
    row = r[0]
    assert row["kind"] == "chat", row
    assert row["provider"] == "openrouter", row
    assert row["model"] == "test/model", row
    assert row["ok"] == "1", row
    assert row["total_tokens"] == "18", row
    assert row["user_id"] == dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE), row
    assert int(row["latency_ms"]) > 0, "не записана задержка: " + str(row)
    assert row["streamed"] == "0", row


def test_stub_provider_is_logged_too():
    """Заглушка тоже строка журнала: иначе по логу нельзя отличить «не
    обращались» от «обращались к заглушке»."""
    clear_ai_settings()
    clear_log()
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "заглушка 27182"})
    assert code == 200, js
    r = rows()
    assert len(r) == 1 and r[0]["provider"] == "stub", r
    assert r[0]["total_tokens"] == "0", r


def test_retries_are_counted_in_one_row():
    """Три попытки одного вызова — одна строка с attempts=3, а не три строки:
    иначе счётчик обращений врал бы втрое на каждой сетевой ошибке."""
    use_fake_provider()
    clear_log()
    say("ретраи 31415", mode="fail_then_ok", fail_first=2)
    r = rows()
    assert len(r) == 1, r
    assert r[0]["attempts"] == "3", r
    assert r[0]["ok"] == "1", r


def test_failed_call_is_logged_with_error():
    use_fake_provider()
    clear_log()
    code, js = say("отказ 60221", mode="always_500")
    assert code == 503, js
    r = rows()
    assert len(r) == 1, r
    assert r[0]["ok"] == "0", r
    assert r[0]["error"], "не записана причина отказа"
    assert r[0]["attempts"] == "3", r


def test_error_text_does_not_leak_key_or_internal_url():
    """Журнал читает админ-экран, поэтому в него нельзя класть текст
    провайдера как есть: режим bad_request отдаёт 400 с фрагментом ключа и
    внутренним адресом в сообщении."""
    use_fake_provider()
    clear_log()
    say("утечка 77441", mode="bad_request")
    r = rows()
    assert len(r) == 1, r
    err = r[0]["error"]
    assert "sk-" not in err or "sk-***" in err, "в журнал попал фрагмент ключа: " + err
    assert "internal" not in err.lower(), "в журнал попал внутренний адрес: " + err
    assert len(err) <= 200, "текст ошибки не обрезан: %d символов" % len(err)


def test_kinds_are_distinguished():
    """Вид вызова обязателен: без него расход на чат, резюме и классификацию
    сливается в одну кучу и оптимизировать нечего."""
    use_fake_provider()
    clear_log()
    say("вид вызова 99887")
    kinds = {row["kind"] for row in rows()}
    assert kinds == {"chat"}, kinds
    # список допустимых значений задан в схеме
    col = dbq("SHOW COLUMNS FROM `ai_usage_log` LIKE 'kind'")
    for kind in ("chat", "summary", "explain", "classify", "psychotype", "greet"):
        assert kind in col, "вид %s не разрешён схемой: %s" % (kind, col)


# ------------------------------------------------------------- max_tokens ---

def test_max_tokens_is_sent_to_provider():
    use_fake_provider()
    setting("max_tokens", "700")
    code, js = say("потолок 12345", mode="echo_payload")
    assert code == 200, js
    assert "MAXTOK:700" in js.get("reply", ""), "max_tokens не ушёл провайдеру: " + str(js)[:200]


def test_max_tokens_is_configurable():
    use_fake_provider()
    setting("max_tokens", "321")
    code, js = say("потолок 54321", mode="echo_payload")
    assert "MAXTOK:321" in js.get("reply", ""), str(js)[:200]
    setting("max_tokens", "700")


# ------------------------------------------------------------------- схема ---

def test_chat_messages_have_thread_rating_stopped():
    """Колонки под чат v2: тред (для «нового диалога»), оценка ответа и
    признак остановленной генерации."""
    for col in ("thread", "rating", "stopped"):
        assert dbq("SHOW COLUMNS FROM `ai_chat_messages` LIKE '%s'" % col), \
            "нет колонки ai_chat_messages.%s" % col


def test_existing_messages_got_default_thread():
    """Миграция не должна оставить старые сообщения вне тредов — иначе они
    выпадут из истории текущего диалога."""
    orphans = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE `thread` IS NULL OR `thread` < 1")
    assert orphans == "0", "сообщений без треда: " + orphans
