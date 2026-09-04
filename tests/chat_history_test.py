# -*- coding: utf-8 -*-
"""
B2 этапа «До идеала»: история чата, лимиты и честный ответ эндпоинта.

Что было не так:
  - страница консультанта читала ВСЮ переписку без LIMIT и печатала её
    целиком: у активного пользователя это растёт без предела;
  - ответ эндпоинта — {reply, tokens}: без id и времени клиент не может ни
    оценить ответ, ни повторить отправку без дубля, ни показать время;
  - сообщение длиннее 4000 символов молча обрезалось — человек не узнавал,
    что до модели дошла половина;
  - параллельные отправки не мешали друг другу: две вкладки жгли два платных
    вызова и путали порядок реплик;
  - 4-байтные символы (эмодзи) в тексте ПОЛЬЗОВАТЕЛЯ рвали строку: колонка
    в utf8 (3 байта), MySQL молча обрезает по первому такому символу.

    python -m pytest tests/chat_history_test.py -q
"""
import json
import os
import re
import sys
import threading
import time
import urllib.error
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    BASE, browser_headers, clear_ai_settings, dbq, login, post_json,
    set_fake_mode, setting, start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
CHAT = "/api/ai_chat.php"
HISTORY = "/api/ai_history.php"
PAGE = "/my/ai-consultant/"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid_of(email):
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % email))


def setup_module(module):
    start_fake_server()
    clear_ai_settings()          # заглушка: тесты про транспорт, не про модель
    wipe()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()


def wipe():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid_of(CANDIDATE))


def seed(n, thread=1):
    """n пар «вопрос-ответ» прямо в базе — быстрее, чем гонять их через модель."""
    uid = uid_of(CANDIDATE)
    rows = []
    for i in range(n):
        rows.append("(%d,'user','сообщение %d',0,%d,NOW())" % (uid, i, thread))
        rows.append("(%d,'assistant','ответ %d',0,%d,NOW())" % (uid, i, thread))
    dbq("INSERT INTO `ai_chat_messages` (user_id, role, content, tokens_used, thread, created_at) "
        "VALUES " + ",".join(rows))


def get(path, sid=None):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.urlopen(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace"), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)


def history(params="", sid=None):
    code, body, headers = get(HISTORY + ("?" + params if params else ""), sid or sid_of(CANDIDATE))
    try:
        return code, json.loads(body), headers
    except ValueError:
        return code, {"_raw": body[:200]}, headers


# ------------------------------------------------------- страница и история ---

def test_page_renders_only_last_messages():
    """Страница печатает хвост переписки, а не всю: иначе у активного
    пользователя разметка растёт без предела."""
    wipe()
    seed(40)                      # 80 сообщений
    code, html, _ = get(PAGE, sid_of(CANDIDATE))
    assert code == 200, code
    shown = len(re.findall(r'class="[^"]*sh-msg[^"]*"[^>]*data-id="\d+"', html))
    assert 0 < shown <= 30, "на странице %d реплик, ожидалось не больше 30" % shown
    assert 'data-has-more="1"' in html, "не помечено, что есть более ранние сообщения"


def test_history_endpoint_paginates_backwards():
    wipe()
    seed(40)
    code, js, headers = history("limit=30")
    assert code == 200, js
    assert len(js["messages"]) == 30, len(js["messages"])
    assert js["has_more"] is True, js
    assert headers.get("Cache-Control", "").startswith("no-store"), headers.get("Cache-Control")

    oldest = js["messages"][0]["id"]
    code, older, _ = history("limit=30&before_id=%d" % oldest)
    assert code == 200, older
    assert older["messages"], "вторая страница пуста"
    assert all(m["id"] < oldest for m in older["messages"]), "страницы пересекаются"

    # до начала переписки
    code, first, _ = history("limit=30&before_id=%d" % older["messages"][0]["id"])
    assert first["has_more"] is False, first


def test_history_returns_ids_and_times():
    wipe()
    seed(1)
    code, js, _ = history("limit=10")
    m = js["messages"][-1]
    for field in ("id", "role", "content", "created_at"):
        assert field in m, "нет поля %s: %s" % (field, m)
    assert re.match(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$", m["created_at"]), m["created_at"]


def test_history_after_id_returns_only_newer():
    wipe()
    seed(3)
    code, js, _ = history("limit=100")
    mid = js["messages"][2]["id"]
    code, js2, _ = history("after_id=%d" % mid)
    assert all(m["id"] > mid for m in js2["messages"]), "after_id вернул старое"


def test_history_limit_is_capped():
    wipe()
    seed(60)
    code, js, _ = history("limit=100000")
    assert len(js["messages"]) <= 100, "лимит не ограничен сверху: %d" % len(js["messages"])


def test_history_requires_auth():
    code, body, _ = get(HISTORY, None)
    assert code == 403, code


def test_history_shows_only_own_messages():
    """Чужая переписка не должна быть доступна по подбору номера треда."""
    wipe()
    seed(2)
    code, js, _ = history("thread=999")
    assert js["messages"] == [], "выдана переписка чужого треда: %s" % js


# --------------------------------------------------------- ответ эндпоинта ---

def test_reply_carries_id_and_time():
    wipe()
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "привет 51413"})
    assert code == 200, js
    for field in ("reply", "message_id", "user_message_id", "created_at"):
        assert field in js, "нет поля %s: %s" % (field, js)
    stored = dbq("SELECT content FROM ai_chat_messages WHERE id=%d" % js["message_id"])
    assert stored == js["reply"], "message_id указывает не на этот ответ"


def test_too_long_message_is_rejected_not_truncated():
    """Молчаливая обрезка хуже отказа: человек уверен, что модель прочла всё."""
    wipe()
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "я" * 4100})
    assert code == 400, "длинное сообщение принято: %s" % code
    assert js.get("code") == "too_long", js
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d" % uid_of(CANDIDATE)) == "0", \
        "обрезанное сообщение всё-таки сохранено"


def test_four_byte_emoji_in_user_text_does_not_truncate_row():
    """Колонка в utf8 (3 байта). MySQL обрезает строку по первому 4-байтному
    символу — конец сообщения пропадал молча."""
    wipe()
    tail = "конец сообщения 27182"
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "начало \U0001F49D " + tail})
    assert code == 200, js
    stored = dbq("SELECT content FROM ai_chat_messages WHERE user_id=%d AND role='user' LIMIT 1"
                 % uid_of(CANDIDATE))
    assert tail in stored, "хвост сообщения потерян: %r" % stored


def test_retry_of_does_not_duplicate_user_message():
    """Повтор отправки после сетевого сбоя не должен плодить дубли реплики."""
    wipe()
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "повтор 31415"})
    assert code == 200, js
    first_user_id = js["user_message_id"]
    code, js2 = post_json(CHAT, sid_of(CANDIDATE),
                          {"message": "повтор 31415", "retry_of": str(first_user_id)})
    assert code == 200, js2
    assert js2["user_message_id"] == first_user_id, "создана вторая копия сообщения"
    n = dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='user'" % uid_of(CANDIDATE))
    assert n == "1", "реплик пользователя: %s" % n
    # Опаснее всего случай «сервер ответил, ответ не дошёл»: повтор обязан
    # вернуть уже сохранённый ответ, а не оплатить второй вызов модели.
    assert js2["message_id"] == js["message_id"], "повтор создал второй ответ"
    m = dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='assistant'"
            % uid_of(CANDIDATE))
    assert m == "1", "ответов консультанта: %s" % m


# ---------------------------------------------------------------- лимиты ---

def test_burst_limit_blocks_rapid_fire():
    """Часовой лимит не спасает от залпа: тридцать сообщений можно отправить
    за секунду и получить тридцать платных вызовов."""
    wipe()
    setting("burst_per_minute", "6")
    codes = []
    for i in range(8):
        code, _ = post_json(CHAT, sid_of(CANDIDATE), {"message": "залп %d" % i})
        codes.append(code)
    assert 429 in codes, "залп не остановлен: %s" % codes
    assert codes[0] == 200, "первое сообщение не должно отклоняться: %s" % codes


def test_parallel_message_gets_conflict():
    """Две вкладки не должны жечь два платных вызова одновременно."""
    wipe()
    clear_ai_settings()
    setting("burst_per_minute", "60")
    use_fake_provider()
    set_fake_mode("ok", delay_ms=700)      # ответ придёт не мгновенно

    results = []

    def send(text):
        results.append(post_json(CHAT, sid_of(CANDIDATE), {"message": text})[0])

    t1 = threading.Thread(target=send, args=("параллель А",))
    t2 = threading.Thread(target=send, args=("параллель Б",))
    t1.start()
    time.sleep(0.25)
    t2.start()
    t1.join()
    t2.join()
    assert 409 in results, "параллельная отправка прошла: %s" % results
