# -*- coding: utf-8 -*-
"""
B6 этапа «До идеала»: подтверждение перед заменой значения в анкете.

ТЗ (Э1.3) прямо требует: «Если поле уже заполнено и LLM предлагает другое
значение — НЕ перезаписывать; ИИ в диалоге уточняет у пользователя ("У тебя
указано X, поменять на Y?"), перезапись только после явного подтверждения».

Была реализована ровно половина: заполненное поле не перезаписывалось, но и
пути изменить его через разговор не существовало. Человек, переехавший в
другой город, не мог сообщить об этом консультанту — приходилось идти в форму.

Устройство защиты от «модель сама себе разрешила»:
  - у инструмента подтверждения НЕТ параметра значения. Он подтверждает
    только то, о чём сервер сам ранее записал вопрос, — подменить значение
    в момент подтверждения нечем;
  - подтверждение засчитывается, только если вопрос был задан РАНЬШЕ текущей
    реплики человека: предложение и подтверждение в одном ходу — это модель
    договорилась сама с собой;
  - если значение в анкете успело измениться (человек правил форму), старое
    подтверждение сгорает.

    python -m pytest tests/chat_overwrite_test.py -q
"""
import os
import re
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, login, post_json, set_fake_mode, setting,
    start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
CHAT = "/api/ai_chat.php"
GREET = "/api/ai_greet.php"

_SID = {}
_SAVED = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid():
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE))


def anketa_id():
    return int(dbq("SELECT Message_ID FROM Message204 WHERE User_ID=%d ORDER BY Message_ID LIMIT 1" % uid()))


FIELDS = ("City", "Kashrut_level", "About_self", "Place_of_birth")


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    # тесты правят живые анкеты dev-БД: запоминаем всё, что трогаем, чтобы
    # прерванный прогон не оставил в ней сентинелы
    row = dbq("SELECT %s FROM Message204 WHERE Message_ID=%d"
              % (", ".join("`%s`" % f for f in FIELDS), anketa_id()))
    _SAVED["row"] = row.split("\t") if row else None
    wipe()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()
    if _SAVED.get("row"):
        sets = ", ".join("`%s`='%s'" % (f, v.replace("'", "''"))
                         for f, v in zip(FIELDS, _SAVED["row"]))
        dbq("UPDATE Message204 SET %s WHERE Message_ID=%d" % (sets, anketa_id()))


def wipe():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid())
    dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d" % uid())
    dbq("DELETE FROM `ai_field_writes` WHERE user_id=%d" % uid())


def city():
    return dbq("SELECT City FROM Message204 WHERE Message_ID=%d" % anketa_id())


def set_city(value):
    dbq("UPDATE Message204 SET City='%s' WHERE Message_ID=%d" % (value, anketa_id()))


def pending():
    out = dbq("SELECT field, current_value, proposed_value, proposed_label "
              "FROM `ai_pending_overwrites` WHERE user_id=%d" % uid())
    return [line.split("\t") for line in (out or "").splitlines() if line.strip()]


def say(text, mode="ok", **kw):
    set_fake_mode(mode, **kw)
    return post_json(CHAT, sid_of(CANDIDATE), {"message": text})


def propose(value="Ашдод", text="теперь я живу в другом городе"):
    """Модель просит записать новый город в уже заполненное поле."""
    return say(text, mode="tool_call", tool_args={"city": value})


def confirm(text="да, поменяйте", fields=None):
    """Модель вызывает подтверждение. Значения в вызове НЕТ — только поле."""
    return say(text, mode="confirm_call", tool_args={"fields": fields or ["city"]})


# ------------------------------------------------------- вопрос вместо замены ---

def test_filled_field_is_not_overwritten_silently():
    wipe()
    set_city("Москва")
    code, js = propose("Ашдод")
    assert code == 200, js
    assert city() == "Москва", "значение заменено без спроса"


def test_proposal_is_remembered_as_question():
    """Чтобы подтверждение можно было проверить, сервер запоминает, о чём
    именно спросил: иначе «да» нечем сопоставить."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    rows = pending()
    assert len(rows) == 1, "вопрос не записан: %s" % rows
    field, current, proposed, label = rows[0]
    assert field == "City", rows
    assert current == "Москва" and proposed == "Ашдод", rows


def test_model_is_told_to_ask():
    """Модель должна узнать, что поле занято и что надо спросить, — иначе она
    просто промолчит о неудавшейся записи."""
    wipe()
    set_city("Москва")
    code, js = propose("Ашдод")
    reply = js.get("reply", "")
    assert "already_filled" in reply or "уже заполнено" in reply, \
        "модели не сообщили, что поле занято: " + reply[:200]


def test_pending_question_reaches_prompt():
    """В следующем запросе модель видит заданный вопрос — с человеческими
    значениями, а не идентификаторами."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    code, js = say("а что вы спрашивали?", mode="echo_system")
    prompt = js.get("reply", "")
    assert "Москва" in prompt and "Ашдод" in prompt, "вопрос не дошёл до модели"
    assert "confirm_profile_update" in prompt, "модель не знает, чем подтверждать"


# ------------------------------------------------------------ подтверждение ---

def test_confirmation_applies_exactly_the_asked_value():
    wipe()
    set_city("Москва")
    propose("Ашдод")
    code, js = confirm()
    assert code == 200, js
    assert city() == "Ашдод", "подтверждённая замена не применена"
    assert pending() == [], "вопрос остался висеть после ответа"


def test_confirmation_is_journaled_with_old_value():
    """Замена — не то же, что заполнение пустого: в журнале должно остаться,
    что именно было до неё."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    confirm()
    row = dbq("SELECT old_value, new_value FROM `ai_field_writes` WHERE user_id=%d "
              "AND field='City' ORDER BY id DESC LIMIT 1" % uid())
    assert row, "замена не попала в журнал"
    old, new = row.split("\t")
    assert old == "Москва" and new == "Ашдод", row


def test_confirmation_without_question_is_rejected():
    """Модель не может подтвердить то, о чём никто не спрашивал."""
    wipe()
    set_city("Москва")
    code, js = confirm()
    assert city() == "Москва", "замена без заданного вопроса"
    # без этой проверки тест прошёл бы и при вовсе не реализованном инструменте
    assert "не задавали" in js.get("reply", ""),         "модели не объяснили отказ: " + js.get("reply", "")[:200]


def test_same_turn_proposal_and_confirmation_rejected():
    """Предложение и подтверждение в одной реплике — это модель договорилась
    сама с собой, а не согласие человека."""
    wipe()
    set_city("Москва")
    code, js = say("живу в Ашдоде", mode="propose_then_confirm",
                   tool_args={"city": "Ашдод"})
    assert city() == "Москва", "замена засчитана без хода человека"
    assert len(pending()) == 1, "вопрос должен остаться заданным: %s" % pending()
    # иначе тест зелен и когда подтверждение просто не доехало до диспетчера
    assert "ещё не ответил" in js.get("reply", ""),         "отклонено не как «согласия ещё не было»: " + js.get("reply", "")[:200]


def test_value_changed_meanwhile_invalidates_question():
    """Человек успел поправить анкету сам — старое согласие уже не о том."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    set_city("Хайфа")                      # правка через форму
    code, js = confirm()
    assert city() == "Хайфа", "подтверждение перезаписало более свежее значение"
    assert pending() == [], "устаревший вопрос не снят"


def test_stale_question_expires():
    wipe()
    set_city("Москва")
    propose("Ашдод")
    dbq("UPDATE `ai_pending_overwrites` SET asked_at = DATE_SUB(NOW(), INTERVAL 2 DAY) "
        "WHERE user_id=%d" % uid())
    code, js = confirm()
    assert city() == "Москва", "подтверждено согласие двухдневной давности"
    assert "не задавали" in js.get("reply", ""), \
        "отклонено не как «вопрос протух»: " + js.get("reply", "")[:200]
    assert pending() == [], "просроченный вопрос не убран из таблицы"


def test_new_proposal_replaces_previous_question():
    """Передумал по ходу разговора — подтверждать надо последнее."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    propose("Хайфа")
    rows = pending()
    assert len(rows) == 1 and rows[0][2] == "Хайфа", rows
    confirm()
    assert city() == "Хайфа", "применено не последнее предложение"


# --------------------------------------------------------------- инструмент ---

def test_confirm_tool_offered_only_when_there_is_a_question():
    """Лишний инструмент в списке — приглашение им воспользоваться не к месту."""
    wipe()
    set_city("Москва")
    code, js = say("просто разговор", mode="echo_tools")
    assert "confirm_profile_update" not in js.get("reply", ""), \
        "инструмент подтверждения предложен без заданного вопроса"

    propose("Ашдод")
    code, js = say("и ещё вопрос", mode="echo_tools")
    assert "confirm_profile_update" in js.get("reply", ""), \
        "инструмент подтверждения не предложен, хотя вопрос задан"


def test_confirm_tool_has_no_value_parameter():
    """У подтверждения не должно быть параметра значения: иначе модель могла бы
    подставить своё, и вся проверка теряет смысл."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    code, js = say("покажи инструменты", mode="echo_tools")
    reply = js.get("reply", "")
    found = re.search(r"confirm_profile_update\(([^)]*)\)", reply)
    assert found, "инструмент не дошёл до модели: " + reply[:250]
    params = [p for p in found.group(1).split("|") if p]
    assert params == ["fields"], \
        "у подтверждения есть лишние параметры: %s" % params


def test_chips_offer_yes_and_no():
    """Ответ «да/нет» должен быть в один клик — вопрос задан консультантом."""
    wipe()
    set_city("Москва")
    code, js = propose("Ашдод")
    chips = js.get("chips") or []
    joined = " ".join(chips).lower()
    assert "ашдод" in joined, "нет подсказки согласиться: %s" % chips
    assert "москв" in joined, "нет подсказки отказаться: %s" % chips

# --------------------------------------------------- окно согласия — один ход ---

def test_refusal_closes_the_question():
    """Главное, ради чего вопрос вообще хранится отдельно.

    Проверки «вопрос задан раньше текущей реплики» мало: она доказывает лишь,
    что человек после вопроса что-то написал. Сказал он «нет» — и если вопрос
    остаётся висеть, то «да» на совсем другой вопрос консультанта тремя
    репликами позже применит замену, о которой человек уже отказался.
    """
    wipe()
    set_city("Москва")
    propose("Ашдод")                      # ход 1: вопрос задан
    say("нет, оставьте как есть")         # ход 2: отказ, подтверждения нет
    assert pending() == [], "отказ не снял вопрос: %s" % pending()

    code, js = confirm()                  # ход 3: «да» уже про другое
    assert city() == "Москва", "замена применена после отказа"


def test_silence_also_closes_the_question():
    """Молчание — тоже не согласие: ответил человек о другом, вопрос закрыт."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    say("а расскажите лучше про опрос")
    assert pending() == [], "вопрос пережил ход, которому был адресован"


def test_repeated_proposal_does_not_cancel_consent():
    """Модель часто присылает в одном ответе и повтор предложения, и
    подтверждение. Если повтор сдвигает номер реплики, явное согласие человека
    превращается в «он ещё не ответил» — кнопка «Да, поменять» не работает."""
    wipe()
    set_city("Москва")
    propose("Ашдод")                      # ход 1
    code, js = say("да, поменяйте", mode="propose_then_confirm",
                   tool_args={"city": "Ашдод"})
    assert city() == "Ашдод", "согласие потеряно из-за повтора предложения"


def test_question_is_bound_to_its_dialogue():
    """«Да» в новом разговоре не подтверждает вопрос из старого. Номер диалога
    приходит от клиента, поэтому очистки при приветствии недостаточно."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    set_fake_mode("confirm_call", tool_args={"fields": ["city"]})
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "да", "thread": 2})
    assert code == 200, js
    assert city() == "Москва", "подтверждено из другого диалога"
    assert len(pending()) == 1, "чужой ход съел окно согласия: %s" % pending()


def test_new_dialogue_drops_open_questions():
    wipe()
    set_city("Москва")
    propose("Ашдод")
    assert len(pending()) == 1
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid())   # разговор начат заново
    code, js = post_json(GREET, sid_of(CANDIDATE), {})
    assert code == 200, js
    assert pending() == [], "вопрос прошлого разговора пережил новый"


# ------------------------------------------------------------ словарное поле ---

def kashrut():
    return dbq("SELECT Kashrut_level FROM Message204 WHERE Message_ID=%d" % anketa_id())


def set_kashrut(value):
    dbq("UPDATE Message204 SET Kashrut_level='%s' WHERE Message_ID=%d" % (value, anketa_id()))


def propose_kashrut(name="Строгий"):
    return say("я стал соблюдать строже", mode="tool_call",
               tool_args={"kashrut_level": name})


def test_dictionary_question_speaks_names_not_ids():
    """Справочник хранит идентификаторы. Согласиться на «поменять на 4»
    невозможно — и в вопрос, и в подсказки должны идти имена."""
    wipe()
    set_kashrut("2")                       # Базовый
    propose_kashrut("Строгий")             # ID 4
    rows = pending()
    assert len(rows) == 1, rows
    field, current, proposed, label = rows[0]
    assert field == "Kashrut_level", rows
    assert current == "2" and proposed == "4", "снимок и предложение не в хранимом виде: %s" % rows
    assert label == "Строгий", "метка не человеческая: %s" % rows

    code, js = say("что вы спрашивали?", mode="echo_system")
    prompt = js.get("reply", "")
    assert "Базовый" in prompt and "Строгий" in prompt, "в промпт ушли идентификаторы"


def test_dictionary_replacement_applies_the_id():
    wipe()
    set_kashrut("2")
    propose_kashrut("Строгий")
    confirm(fields=["kashrut_level"])
    assert kashrut() == "4", "подтверждённый уровень не записан: " + kashrut()


def test_dictionary_chips_offer_names():
    wipe()
    set_kashrut("2")
    code, js = propose_kashrut("Строгий")
    joined = " ".join(js.get("chips") or [])
    assert "Строгий" in joined and "Базовый" in joined, "в подсказках идентификаторы: %s" % js.get("chips")


# --------------------------------------------------------- границы инструмента ---

def test_confirm_rejects_fields_outside_the_map():
    """Подтверждение не должно быть лазейкой к полям, которых у инструмента
    записи нет вовсе (здоровье, доход, лекарства)."""
    wipe()
    set_city("Москва")
    propose("Ашдод")
    code, js = confirm(fields=["health_status", "income_property", "Health_conditions"])
    assert code == 200, js
    assert city() == "Москва", "подтверждение записало что-то помимо спрошенного"
    assert "не задавали" in js.get("reply", ""), \
        "модели не сказали, что таких вопросов не было: " + js.get("reply", "")[:200]


def test_chips_still_point_at_empty_fields_while_question_hangs():
    """Две кнопки «да/нет» не должны вытеснять все остальные подсказки: иначе
    человек с висящим вопросом остаётся без единственной навигации по анкете."""
    wipe()
    set_city("Москва")
    dbq("UPDATE Message204 SET About_self='', Place_of_birth='' WHERE Message_ID=%d" % anketa_id())
    code, js = propose("Ашдод")
    chips = js.get("chips") or []
    assert len(chips) > 2, "кроме да/нет подсказок не осталось: %s" % chips
    assert len(chips) <= 4, "слишком много подсказок: %s" % chips
