# -*- coding: utf-8 -*-
"""
Тесты автозаполнения анкеты через tool calling (ROADMAP Э1.3).

Конвейер строго по порядку:
  запрос модели с описанием инструмента → модель просит записать поля
  → бэкенд валидирует (невалидное отбрасывается, словарные значения
  приводятся к ID) → пишет ТОЛЬКО пустые поля (заполненное не перетирает)
  → журналирует в ai_field_writes → второй вызов модели с результатом
  инструмента, чтобы ИИ естественно продолжил диалог.

Чувствительные поля (доход/здоровье/медикаменты) инструментом НЕ пишутся
никогда, даже если модель их запросит — они собираются только самим
человеком в закрытых полях анкеты (152-ФЗ, Раздел X).

    python -m pytest tests/ai_tools_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (clear_ai_settings, dbq, fake_calls, login, post_json,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider)

CANDIDATE = "u091ff2b36c83@example.local"     # User 50, анкета 42
CHAT_URL = "/api/ai_chat.php"

# поля, которые инструмент вправе заполнять (свободный текст и словарные)
TEXT_FIELDS = ["City", "Place_of_birth", "About_self", "Family_values",
               "Languages_spoken", "Sport_attitude", "Housing_after_marriage",
               "Diaspora_openness", "Observance_growth", "Introvert_extrovert"]
DICT_FIELDS = ["Kashrut_level", "Shabbat_level", "Are_you_ready_to_move"]
SENSITIVE = ["Income_property", "Health_conditions", "Medications"]

UID = None
ANKETA = None
BACKUP = {}


def send(sid, text):
    return post_json(CHAT_URL, sid, {"message": text})


def field(name):
    return dbq("SELECT IFNULL(`%s`,'') FROM `Message204` WHERE Message_ID=%d" % (name, ANKETA))


def set_field(name, value):
    esc = str(value).replace("\\", "\\\\").replace("'", "''")
    dbq("UPDATE `Message204` SET `%s`='%s' WHERE Message_ID=%d" % (name, esc, ANKETA))


def clear_fields():
    cols = TEXT_FIELDS + DICT_FIELDS + SENSITIVE
    dbq("UPDATE `Message204` SET " + ", ".join("`%s`=NULL" % c for c in cols)
        + " WHERE Message_ID=%d" % ANKETA)


def clear_chat():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)
    dbq("DELETE FROM `ai_field_writes` WHERE user_id=%d" % UID)


def writes_log():
    return dbq("SELECT field, old_value, new_value, source FROM `ai_field_writes` "
               "WHERE user_id=%d ORDER BY id" % UID)


def setup_module(module):
    global UID, ANKETA
    UID = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    ANKETA = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID=%d LIMIT 1" % UID))
    for c in TEXT_FIELDS + DICT_FIELDS + SENSITIVE:
        BACKUP[c] = dbq("SELECT IFNULL(`%s`,'') FROM `Message204` WHERE Message_ID=%d"
                        % (c, ANKETA))
    start_fake_server()
    clear_ai_settings()
    clear_chat()


def teardown_module(module):
    for c, v in BACKUP.items():
        set_field(c, v)
    clear_ai_settings()
    clear_chat()
    stop_fake_server()


def run_tool(args, message="расскажи обо мне"):
    """Прогоняет один обмен, в котором модель просит записать поля args."""
    clear_chat()
    use_fake_provider()
    set_fake_mode("tool_call", tool_args=args)
    sid = login(CANDIDATE)
    return send(sid, message)


# --- описание инструмента доходит до модели -----------------------------------

def test_tool_definition_reaches_model():
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_tools")
    sid = login(CANDIDATE)
    code, data = send(sid, "какие у тебя инструменты 24680")
    assert code == 200, "не ответил: %s" % data
    reply = data.get("reply", "")
    assert "update_profile_fields" in reply, "инструмент не передан модели: " + reply
    # поля инструмента описаны
    for key in ("city", "kashrut_level", "about_self"):
        assert key in reply, "в описании инструмента нет поля %s: %s" % (key, reply)
    # чувствительных полей в описании нет — модель о них даже не знает
    for key in ("income", "health", "medication"):
        assert key not in reply.lower(), "чувствительное поле попало в описание: " + reply


# --- запись полей ------------------------------------------------------------

def test_writes_empty_text_field():
    clear_fields()
    code, data = run_tool({"city": "Ашдод"})
    assert code == 200, "конвейер не отработал: %s" % data
    assert field("City") == "Ашдод", "город не записан: " + field("City")


def test_writes_several_fields_at_once():
    clear_fields()
    code, data = run_tool({
        "city": "Хайфа",
        "origin": "Одесса",
        "languages": "русский, иврит",
        "about_self": "Спокойный, люблю учиться",
    })
    assert code == 200, "конвейер не отработал: %s" % data
    assert field("City") == "Хайфа"
    assert field("Place_of_birth") == "Одесса"
    assert field("Languages_spoken") == "русский, иврит"
    assert "Спокойный" in field("About_self")


def test_does_not_overwrite_filled_field():
    clear_fields()
    set_field("City", "Москва")
    code, data = run_tool({"city": "Ашдод"})
    assert code == 200
    assert field("City") == "Москва", "заполненное поле перетёрто: " + field("City")


def test_dictionary_value_maps_to_id():
    clear_fields()
    code, data = run_tool({"kashrut_level": "Строгий", "shabbat_level": "Традиционный"})
    assert code == 200, "конвейер не отработал: %s" % data
    # словарные поля хранят ID значения справочника
    assert field("Kashrut_level") == "4", "кашрут не приведён к ID: " + field("Kashrut_level")
    assert field("Shabbat_level") == "3", "шаббат не приведён к ID: " + field("Shabbat_level")


def test_invalid_dictionary_value_discarded():
    clear_fields()
    code, data = run_tool({"kashrut_level": "Сверхстрогий которого нет"})
    assert code == 200, "конвейер должен продолжить работу: %s" % data
    assert field("Kashrut_level") == "", "невалидное словарное значение записано"


def test_yes_no_dictionary():
    clear_fields()
    code, _ = run_tool({"relocation_ready": "Да"})
    assert code == 200
    assert field("Are_you_ready_to_move") == "1", \
        "готовность к переезду не приведена к ID: " + field("Are_you_ready_to_move")


# --- границы допустимого ------------------------------------------------------

def test_sensitive_fields_never_written():
    clear_fields()
    code, data = run_tool({
        "income_property": "зарплата 5000",
        "health_conditions": "депрессия",
        "medications": "антидепрессанты",
        "Income_property": "ещё попытка",
        "city": "Тверия",
    })
    assert code == 200, "конвейер не отработал: %s" % data
    for f in SENSITIVE:
        assert field(f) == "", "чувствительное поле записано инструментом: " + f
    # при этом разрешённое поле из того же вызова записалось
    assert field("City") == "Тверия", "допустимое поле не записано"


def test_unknown_fields_ignored():
    clear_fields()
    code, data = run_tool({"Checked": "0", "User_ID": "1", "nonexistent": "x",
                           "city": "Цфат"})
    assert code == 200, "конвейер не отработал: %s" % data
    assert field("City") == "Цфат"
    # служебные поля анкеты не тронуты
    assert dbq("SELECT Checked FROM `Message204` WHERE Message_ID=%d" % ANKETA) == "1"
    assert dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % ANKETA) == str(UID)


def test_numeric_and_odd_keys_ignored():
    # модель может прислать числовые ключи или список — они не должны
    # случайно совпасть с полем карты (в PHP "5" становится int-ключом)
    clear_fields()
    code, data = run_tool({"0": "ноль", "5": "пять", "": "пусто", "city": "Модиин"})
    assert code == 200, "конвейер не отработал: %s" % data
    assert field("City") == "Модиин", "допустимое поле не записано"
    # ничего лишнего не записалось
    log = writes_log()
    assert len(log.splitlines()) == 1 and "City" in log, "записано лишнее: " + log


def test_empty_values_ignored():
    clear_fields()
    code, _ = run_tool({"city": "   ", "about_self": ""})
    assert code == 200
    assert field("City") == "" and field("About_self") == ""


# --- журнал и продолжение диалога --------------------------------------------

def test_writes_are_logged():
    clear_fields()
    set_field("City", "Бейт-Шемеш")
    code, _ = run_tool({"city": "Ашкелон", "languages": "иврит"})
    assert code == 200
    log = writes_log()
    # записано только пустое поле; перетирания нет и в журнале
    assert "Languages_spoken\t\tиврит\tai" in log, "нет записи в журнале: " + log
    assert "City" not in log, "в журнал попало поле, которое не меняли: " + log


def test_ai_continues_dialogue_after_tool():
    clear_fields()
    code, data = run_tool({"city": "Тель-Авив"})
    assert code == 200, "конвейер не отработал: %s" % data
    # второй вызов модели: она видит результат инструмента и отвечает текстом
    assert "ЗАПИСАНО" in data.get("reply", ""), "ИИ не продолжил диалог: %s" % data
    assert fake_calls() == 2, "ожидали два вызова модели, было %d" % fake_calls()
    # текст ответа сохранён как сообщение ассистента
    saved = dbq("SELECT content FROM `ai_chat_messages` WHERE user_id=%d "
                "AND role='assistant' ORDER BY id DESC LIMIT 1" % UID)
    assert "ЗАПИСАНО" in saved, "ответ ассистента не сохранён: " + saved


def test_no_tool_call_loop():
    # если модель просит инструмент повторно, конвейер останавливается:
    # ровно два вызова и осмысленный ответ вместо пустого пузыря
    clear_fields()
    clear_chat()
    use_fake_provider()
    set_fake_mode("tool_call_always", tool_args={"city": "Беэр-Шева"})
    sid = login(CANDIDATE)
    code, data = send(sid, "проверка цикла 13131")
    assert code == 200, "конвейер не отработал: %s" % data
    assert fake_calls() == 2, "ожидали ровно два вызова модели, было %d" % fake_calls()
    assert data.get("reply", "").strip(), "пользователю ушёл пустой ответ"
    # поле при этом записано один раз
    assert field("City") == "Беэр-Шева"
    assert writes_log().count("City") == 1, "поле записано повторно: " + writes_log()


def test_tool_result_reports_applied_fields():
    clear_fields()
    code, data = run_tool({"city": "Нетания", "kashrut_level": "Базовый"})
    assert code == 200
    # модель получила отчёт инструмента о том, что реально записано
    reply = data.get("reply", "")
    assert "City" in reply or "city" in reply, "в отчёте инструмента нет поля: " + reply
