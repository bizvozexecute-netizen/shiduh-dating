# -*- coding: utf-8 -*-
"""
B7 этапа «До идеала»: оценка ответов консультанта.

Зачем. Понять, где модель отвечает мимо, сейчас можно только одним способом —
читать чужие переписки целиком. Это и дорого по времени, и неприлично: в них
человек рассказывает о себе живому шадхану, а не аудитории. Палец вверх/вниз
даёт тот же сигнал, не открывая содержания: администратору видно, сколько
ответов помечены плохими, и только по ним есть смысл заглядывать глубже.

Что защищаем:
  - оценивают ТОЛЬКО свои ответы консультанта. Чужой id и своя же реплика —
    неотличимый отказ 404: по разнице кодов можно было бы перебором узнать,
    какие id вообще существуют;
  - оценка снимается (0 → NULL), а не застревает: нажал не туда — поправил;
  - значения кроме 1/-1/0 не принимаем вовсе, чтобы в колонке не заводился
    третий смысл.

    python -m pytest tests/chat_feedback_test.py -q
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, get_json, get_text, login, post_json, set_fake_mode,
    start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
OTHER = "u091ff2b36c83@example.local"       # User_ID 50 — чужая переписка
RATE = "/api/ai_rate.php"
CHAT = "/api/ai_chat.php"
HISTORY = "/api/ai_history.php"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid_of(email):
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % email))


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    set_fake_mode("ok")
    wipe(CANDIDATE)
    wipe(OTHER)


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe(CANDIDATE)
    wipe(OTHER)


def wipe(email):
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid_of(email))
    dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d" % uid_of(email))


def say(email, text):
    set_fake_mode("ok")
    return post_json(CHAT, sid_of(email), {"message": text})


def reply_id(email, text="как дела 90210"):
    code, js = say(email, text)
    assert code == 200, js
    return int(js["message_id"])


def rating_of(mid):
    got = dbq("SELECT IFNULL(rating, 'NULL') FROM `ai_chat_messages` WHERE id=%d" % mid)
    return got


def rate(email, message_id, value):
    return post_json(RATE, sid_of(email), {"message_id": message_id, "rating": value})


# ------------------------------------------------------------- сама оценка ---

def test_rates_own_reply_up():
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    code, js = rate(CANDIDATE, mid, 1)
    assert code == 200, js
    assert rating_of(mid) == "1", "оценка не сохранена: " + rating_of(mid)


def test_rates_own_reply_down():
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    code, js = rate(CANDIDATE, mid, -1)
    assert code == 200, js
    assert rating_of(mid) == "-1", "оценка не сохранена: " + rating_of(mid)


def test_rating_can_be_taken_back():
    """Нажал не туда — должен иметь возможность снять, а не жить с этим."""
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    rate(CANDIDATE, mid, -1)
    code, js = rate(CANDIDATE, mid, 0)
    assert code == 200, js
    assert rating_of(mid) == "NULL", "оценка не снята: " + rating_of(mid)


def test_rating_replaces_previous():
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    rate(CANDIDATE, mid, 1)
    rate(CANDIDATE, mid, -1)
    assert rating_of(mid) == "-1", "оценка не перезаписана: " + rating_of(mid)


def test_rating_visible_in_history():
    """Оценка должна пережить перезагрузку страницы — иначе человек нажмёт
    второй раз и решит, что кнопка не работает."""
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    rate(CANDIDATE, mid, 1)
    code, js = get_json(HISTORY, sid_of(CANDIDATE))
    assert code == 200, js
    rated = [m for m in js["messages"] if m["id"] == mid]
    assert rated, "ответа нет в истории"
    assert rated[0]["rating"] == 1, "оценка не отдана клиенту: %s" % rated[0]


# --------------------------------------------------------------- отказы ---

def test_cannot_rate_foreign_message():
    """Чужая переписка — не наше дело даже на уровне «существует ли такой id»."""
    wipe(CANDIDATE)
    wipe(OTHER)
    mid = reply_id(OTHER, "чужая реплика 13579")
    code, js = rate(CANDIDATE, mid, 1)
    assert code == 404, "чужое сообщение оценено: %s %s" % (code, js)
    assert rating_of(mid) == "NULL", "чужая оценка записана"


def test_cannot_rate_own_message():
    """Оценивается ответ консультанта, а не собственная реплика."""
    wipe(CANDIDATE)
    code, js = say(CANDIDATE, "моя реплика 24680")
    own = int(js["user_message_id"])
    code, js = rate(CANDIDATE, own, 1)
    assert code == 404, "оценена собственная реплика: %s %s" % (code, js)


def test_missing_message_is_not_distinguishable():
    """Тот же код, что и у чужого: иначе перебором видно, какие id заняты."""
    wipe(CANDIDATE)
    code, js = rate(CANDIDATE, 999999999, 1)
    assert code == 404, "%s %s" % (code, js)


def test_bad_rating_value_rejected():
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    for bad in (5, -2, "хорошо"):
        code, js = rate(CANDIDATE, mid, bad)
        assert code == 400, "принято значение %r: %s" % (bad, code)
    assert rating_of(mid) == "NULL", "мусор записан в оценку"


def test_requires_auth():
    code, js = post_json(RATE, None, {"message_id": 1, "rating": 1})
    assert code == 403, code


def test_requires_csrf():
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE)
    code, js = post_json(RATE, sid_of(CANDIDATE), {"message_id": mid, "rating": 1}, csrf=False)
    assert code == 403, "принято без токена: %s" % code
    assert rating_of(mid) == "NULL", "оценка записана без токена"


def test_get_not_allowed():
    code, js = get_json(RATE, sid_of(CANDIDATE))
    assert code == 405, code


# ---------------------------------------------------------------- страница ---

def test_page_renders_rating_state():
    """Состояние оценки печатает сервер: после перезагрузки нажатое не должно
    выглядеть ненажатым, иначе человек жмёт второй раз и снимает оценку."""
    wipe(CANDIDATE)
    mid = reply_id(CANDIDATE, "страница 55555")
    rate(CANDIDATE, mid, 1)
    code, html = get_text("/my/ai-consultant/", sid_of(CANDIDATE))
    assert code == 200, code
    start = html.find('data-id="%d"' % mid)
    assert start > 0, "ответа нет на странице"
    block = html[start:start + 3000]
    assert 'data-rate="1" aria-pressed="true"' in block, "оценка не отражена на странице"
    assert 'data-rate="-1" aria-pressed="false"' in block, "вторая кнопка в неверном состоянии"


def test_own_message_has_no_rating_buttons():
    wipe(CANDIDATE)
    code, js = say(CANDIDATE, "моя реплика без оценки 66666")
    own = int(js["user_message_id"])
    code, html = get_text("/my/ai-consultant/", sid_of(CANDIDATE))
    start = html.find('data-id="%d"' % own)
    end = html.find("</article>", start)
    assert "sh-rate" not in html[start:end], "кнопки оценки у собственной реплики"
