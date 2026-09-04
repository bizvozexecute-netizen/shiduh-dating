# -*- coding: utf-8 -*-
"""
B5 этапа «До идеала»: первая реплика консультанта и быстрые ответы.

Человек открывал чат и видел чистый лист с приглашением «напишите сообщение».
О чём писать — непонятно, и разговор не начинался. Теперь консультант
здоровается первым и предлагает, с чего начать.

Два требования, которые легко нарушить:
  - приветствие сохраняется как обычная реплика, иначе модель его не видит и
    в следующем ответе здоровается заново;
  - оно НЕ пересоздаётся при каждом открытии страницы: иначе история
    засоряется, а при живом ключе каждое открытие стоит денег.

Быстрые ответы считает сервер и только из фактов (какие поля анкеты пусты,
есть ли неотвеченный сценарий опроса) — без обращения к модели: подсказка
не должна стоить денег и меняться от запуска к запуску.

    python -m pytest tests/chat_greeting_test.py -q
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, fake_calls, login, post_json, set_fake_mode,
    setting, start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
GREET = "/api/ai_greet.php"
CHAT = "/api/ai_chat.php"

_SID = {}
_ANKETA = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid():
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE))


FIELDS = ("City", "Place_of_birth", "About_self", "Kashrut_level", "Shabbat_level")


def setup_module(module):
    start_fake_server()
    clear_ai_settings()
    # запоминаем анкету, чтобы вернуть её ровно как была
    row = dbq("SELECT %s FROM Message204 WHERE User_ID=%d LIMIT 1"
              % (", ".join("`%s`" % f for f in FIELDS), uid()))
    _ANKETA["saved"] = row.split("\t") if row else None
    wipe()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()
    if _ANKETA.get("saved"):
        sets = ", ".join("`%s`='%s'" % (f, v.replace("'", "''"))
                         for f, v in zip(FIELDS, _ANKETA["saved"]))
        dbq("UPDATE Message204 SET %s WHERE User_ID=%d" % (sets, uid()))


def wipe():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid())


def rows():
    out = dbq("SELECT id, role, content FROM `ai_chat_messages` WHERE user_id=%d ORDER BY id" % uid())
    return [line.split("\t", 2) for line in (out or "").splitlines() if line.strip()]


def greet():
    return post_json(GREET, sid_of(CANDIDATE), {})


# ------------------------------------------------------------ приветствие ---

def test_greeting_is_stored_as_message():
    """Приветствие — обычная реплика в истории: иначе модель его не видит и в
    следующем ответе здоровается второй раз."""
    wipe()
    code, js = greet()
    assert code == 200, js
    assert js.get("message"), js
    saved = rows()
    assert len(saved) == 1, "ожидалась одна строка, получено %d" % len(saved)
    assert saved[0][1] == "assistant", saved
    assert saved[0][2] == js["message"]["content"], "в базе другой текст"


def test_greeting_is_not_regenerated():
    """Повторное открытие страницы не должно плодить приветствия и — при живом
    ключе — тратить деньги на каждое открытие."""
    wipe()
    code, first = greet()
    code2, second = greet()
    assert code2 == 200, second
    assert second["message"]["id"] == first["message"]["id"], "создано второе приветствие"
    assert len(rows()) == 1, rows()


def test_greeting_not_added_to_started_conversation():
    """Если разговор уже идёт, здороваться поздно."""
    wipe()
    post_json(CHAT, sid_of(CANDIDATE), {"message": "здравствуйте 51413"})
    before = len(rows())
    greet()
    assert len(rows()) == before, "приветствие вклинилось в начатый разговор"


def test_greeting_invites_to_speak():
    """Приветствие должно звать к разговору, а не быть вывеской: заканчиваться
    вопросом и объяснять, зачем всё это."""
    wipe()
    code, js = greet()
    text = js["message"]["content"]
    assert text.rstrip().endswith("?"), "приветствие не заканчивается вопросом: " + text[-60:]
    assert "анкет" in text.lower(), "не сказано, зачем нужен разговор: " + text


def test_greeting_works_without_key():
    """Без ключа приветствие всё равно есть: это шаблон, а не вызов модели."""
    wipe()
    clear_ai_settings()
    before = fake_calls()          # счётчик накопительный: сравниваем сдвиг
    code, js = greet()
    assert code == 200, js
    assert js["message"]["content"], js
    assert fake_calls() == before, "заглушке не нужна модель, а вызов был"


def test_greeting_by_model_when_enabled():
    """С включённой настройкой приветствие пишет модель — но ровно один раз."""
    wipe()
    use_fake_provider()
    setting("greet_with_llm", "1")
    set_fake_mode("ok")
    before = fake_calls()
    code, js = greet()
    assert code == 200, js
    assert fake_calls() == before + 1, "ожидался один вызов модели"
    assert len(rows()) == 1, rows()
    setting("greet_with_llm", "0")
    clear_ai_settings()


def test_greeting_falls_back_when_model_fails():
    """Модель недоступна — человек всё равно должен увидеть приветствие."""
    wipe()
    use_fake_provider()
    setting("greet_with_llm", "1")
    set_fake_mode("always_500")
    code, js = greet()
    assert code == 200, "отказ модели не должен ломать приветствие: %s" % js
    assert js["message"]["content"], js
    assert len(rows()) == 1, rows()
    setting("greet_with_llm", "0")
    clear_ai_settings()


def test_greeting_requires_auth():
    code, js = post_json(GREET, None, {})
    assert code == 403, code


# --------------------------------------------------------- быстрые ответы ---

def set_fields(**kw):
    sets = ", ".join("`%s`='%s'" % (k, v) for k, v in kw.items())
    dbq("UPDATE Message204 SET %s WHERE User_ID=%d" % (sets, uid()))


def chips_of(js):
    return js.get("chips") or []


def test_chips_offered_with_greeting():
    wipe()
    set_fields(City="", About_self="")
    code, js = greet()
    chips = chips_of(js)
    assert chips, "нет быстрых ответов: %s" % js
    assert len(chips) <= 4, "слишком много подсказок: %s" % chips


def test_chips_point_at_empty_fields():
    """Подсказка ведёт туда, где в анкете пусто, — в этом весь смысл."""
    wipe()
    set_fields(City="")
    code, js = greet()
    joined = " ".join(chips_of(js)).lower()
    assert "живу" in joined or "город" in joined, "нет подсказки про город: %s" % chips_of(js)


def test_chips_skip_filled_fields():
    wipe()
    set_fields(City="Москва")
    code, js = greet()
    joined = " ".join(chips_of(js)).lower()
    assert "живу" not in joined and "город" not in joined, \
        "подсказка про уже заполненное поле: %s" % chips_of(js)


def test_chips_do_not_cost_a_model_call():
    """Подсказки считает сервер по фактам: они не должны стоить денег."""
    wipe()
    use_fake_provider()
    set_fake_mode("ok")
    before = fake_calls()
    greet()
    assert fake_calls() == before, "быстрые ответы обратились к модели"
    clear_ai_settings()


def test_chips_come_with_reply_too():
    """После ответа подсказки обновляются: часть полей уже заполнена."""
    wipe()
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "привет 27182"})
    assert code == 200, js
    assert "chips" in js, "ответ не принёс подсказок: %s" % list(js)


def test_chips_are_plain_text():
    """Подсказка подставляется в поле ввода и уходит в модель — в ней не должно
    быть ни разметки, ни переносов."""
    wipe()
    set_fields(City="")
    code, js = greet()
    for chip in chips_of(js):
        assert "<" not in chip and "\n" not in chip, "подсказка с разметкой: %r" % chip
        assert len(chip) <= 60, "слишком длинная подсказка: %r" % chip


# ------------------------------------------------- системный промпт по ТЗ ---

def test_default_prompt_follows_spec():
    """ТЗ (Э1.2) просило понятную терминологию традиции и живой диалог вместо
    анкетного опроса. Плюс два правила, которых не хватало на практике: не
    рапортовать о записи в анкету и спрашивать перед заменой заполненного."""
    wipe()
    use_fake_provider()
    set_fake_mode("echo_system")
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "какой промпт 33445"})
    assert code == 200, js
    prompt = js.get("reply", "")

    for word in ("Шаббат", "кашрут", "хупа", "мицва"):
        assert word in prompt, "нет терминологии традиции: " + word
    assert "списком" in prompt, "нет запрета на вопросы списком"
    assert "не докладывайте" in prompt, "модель будет рапортовать о записи в анкету"
    assert "поменять на" in prompt, "нет правила спрашивать перед заменой значения"
    # на эту подстроку опираются прежние проверки доставки промпта
    assert "консультант еврейского сервиса знакомств" in prompt, prompt[:200]
    clear_ai_settings()


def test_safety_preamble_survives_prompt_change():
    """Преамбула о деликатных данных не должна теряться при правке роли:
    это требование закона, а не настройка тона."""
    wipe()
    use_fake_provider()
    set_fake_mode("echo_system")
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "проверка преамбулы 55667"})
    prompt = js.get("reply", "")
    assert "здоровье" in prompt.lower() and "лекарств" in prompt.lower(), \
        "преамбула о деликатных данных пропала"
    clear_ai_settings()
