# -*- coding: utf-8 -*-
"""
Замечание приёмки 2026-10: «изменения по ходу разговора не вносятся в
анкету; консультант пишет „внесли изменения“, а анкета не меняется».

Что проверяем:
  - поля, которые пишет ИИ (кашрут, шаббат, «о себе», «что важно в семье»),
    видны человеку в форме анкеты — раньше записанное было не на виду;
  - сервер сообщает о фактической записи (tool_events.applied → плашка);
  - отказ записи (нет такого варианта, нет анкеты) сервер дописывает к
    ответу сам, независимо от слов модели;
  - переформулированное предложение («г. Ашдод» вместо «Ашдод») не сбивает
    подтверждение: «да» применяется, а не уходит в круг «поменять?»;
  - у шадхана своей анкеты нет: ИИ не пишет в анкету его кандидата.

    python -m pytest tests/ai_fill_honesty_test.py -q
"""
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, backup_row, clear_ai_settings, dbq, login, post_json,  # noqa: E402
                      restore_row, set_fake_mode, start_fake_server,
                      stop_fake_server, use_fake_provider)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44, одна анкета
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CHAT = "/api/ai_chat.php"
EDIT_PAGE = "/my/modify/"

FIELDS = ("City", "Kashrut_level", "Shabbat_level", "About_self", "Family_values")

_SID = {}
_SAVED = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid_of(email):
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % email))


def anketa_of(email):
    return int(dbq("SELECT Message_ID FROM Message204 WHERE User_ID=%d ORDER BY Message_ID LIMIT 1"
                   % uid_of(email)))


def wipe(email=CANDIDATE):
    u = uid_of(email)
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % u)
    dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d" % u)
    dbq("DELETE FROM `ai_field_writes` WHERE user_id=%d" % u)


def field(name, anketa=None):
    return dbq("SELECT IFNULL(`%s`,'') FROM Message204 WHERE Message_ID=%d"
               % (name, anketa or anketa_of(CANDIDATE)))


def set_field(name, value):
    dbq("UPDATE Message204 SET `%s`=%s WHERE Message_ID=%d"
        % (name, "NULL" if value is None else "'%s'" % value, anketa_of(CANDIDATE)))


def say(text, mode="ok", email=CANDIDATE, **kw):
    set_fake_mode(mode, **kw)
    return post_json(CHAT, sid_of(email), {"message": text})


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    _SAVED["row"] = backup_row("Message204", anketa_of(CANDIDATE), FIELDS)
    wipe()
    wipe(SHADHAN)


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()
    wipe(SHADHAN)
    if _SAVED.get("row"):
        restore_row("Message204", anketa_of(CANDIDATE), _SAVED["row"])


# ------------------------------------------------------ записанное на виду ---

def test_ai_fields_are_in_the_anketa_form():
    req = urllib.request.Request(BASE + EDIT_PAGE)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid_of(CANDIDATE))
    html = urllib.request.urlopen(req, timeout=120).read().decode("utf-8", "replace")
    for f in FIELDS[1:]:
        assert 'name="f_%s"' % f in html, "поля %s нет в форме анкеты — записанное ИИ не видно" % f


# ------------------------------------------------------ правда от сервера ---

def test_applied_write_is_reported_by_server():
    wipe()
    set_field("City", None)
    code, js = say("я живу в Ашдоде", mode="tool_call", tool_args={"city": "Ашдод"})
    assert code == 200, js
    assert field("City") == "Ашдод"
    applied = [c for ev in js.get("tool_events", []) for c in ev.get("applied", [])]
    assert "City" in applied, "сервер не сообщил о записи: %s" % js.get("tool_events")


def test_value_outside_dictionary_is_explained():
    wipe()
    set_field("Shabbat_level", None)
    code, js = say("шаббат держу иногда", mode="tool_call", tool_args={"shabbat_level": "иногда"})
    assert code == 200, js
    assert field("Shabbat_level") in ("", "0"), "записано значение вне справочника"
    reply = js.get("reply", "")
    assert "не записано" in reply and "Частично" in reply, \
        "сервер не объяснил отказ и не дал вариантов: " + reply


def test_rephrased_proposal_does_not_loop():
    """«Ашдод» → «г. Ашдод» в ходе согласия — тот же вопрос, «да» применяется."""
    wipe()
    set_field("City", "Москва")
    code, js = say("переехал в Ашдод", mode="tool_call", tool_args={"city": "Ашдод"})
    assert code == 200, js
    assert field("City") == "Москва", "заполненное поле перетёрто без согласия"
    code, js = say("да, поменяйте", mode="propose_then_confirm", tool_args={"city": "г. Ашдод"})
    assert code == 200, js
    assert field("City") == "Ашдод", \
        "согласие на переформулированный вопрос не применено: %s / %s" % (field("City"), js.get("reply"))


def test_claim_without_tool_gets_second_chance():
    """«Запишу в анкету» без вызова инструмента — сервер просит модель
    исправиться, и запись действительно происходит."""
    wipe()
    set_field("City", None)
    code, js = say("живу в Ашдоде", mode="claim_then_tool", tool_args={"city": "Ашдод"})
    assert code == 200, js
    assert field("City") == "Ашдод", "после служебной просьбы запись не произошла"
    assert "Запишу" not in js.get("reply", ""), "клиенту ушёл черновик с обещанием"
    assert "ничего не записано" not in js.get("reply", "")


def test_stubborn_claim_is_corrected_by_server():
    """Модель так и не записала — сервер сам говорит человеку правду."""
    wipe()
    set_field("City", None)
    code, js = say("живу в Ашдоде", mode="claim_stubborn")
    assert code == 200, js
    assert field("City") in ("", "0")
    assert "в анкету ничего не записано" in js.get("reply", ""), js.get("reply")


def test_shadhan_chat_does_not_write_clients_anketa():
    """У шадхана своей анкеты нет: рассказ о себе не попадает к его кандидату."""
    wipe(SHADHAN)
    client = anketa_of(SHADHAN)          # самая старая анкета, которой он владеет
    before = field("About_self", client)
    code, js = say("я люблю походы", email=SHADHAN, mode="tool_call",
                   tool_args={"about_self": "маркер-шадхана-90210"})
    assert code == 200, js
    assert field("About_self", client) == before, "ИИ записал рассказ шадхана в анкету клиента"
    assert "анкеты, привязанной" in js.get("reply", ""), \
        "сервер не сказал, что записать некуда: " + js.get("reply", "")
