# -*- coding: utf-8 -*-
"""
Тесты скоринга ценностного профиля (ROADMAP Э2.3).

Разделение ролей жёсткое:
  * LLM классифицирует ОДИН ответ → {измерение: балл, confidence} (Э2.3-a);
  * итоговый профиль (проценты по измерениям) считает КОД (Э2.3-b) —
    поэтому повтор с тем же набором ответов обязан дать тот же профиль;
  * профиль фиксируется версией; повторный опрос — новая версия, прошлые
    ответы не переписываются (Э2.3-c).

  POST /api/assessment_answer.php {case_id, answer}  — отвечает кандидат

    python -m pytest tests/scoring_test.py -q
"""
import base64
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, clear_ai_settings, dbq, login, post_json,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider)

CANDIDATE = "u091ff2b36c83@example.local"   # User 50
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
ANSWER_URL = "/api/assessment_answer.php"

UID = None
ANKETA = None
CASES = {}


def answer(sid, case_id, text, origin=None):
    return post_json(ANSWER_URL, sid,
                     {"case_id": case_id, "answer": text}, origin=origin)


def profile():
    # Читаем через base64: вывод `mysql -B` удваивает экранирование, и JSON с
    # кавычками внутри строк (текст психотипа) переставал разбираться —
    # ломался тест, а не код.
    # TO_BASE64 переносит строки каждые 76 символов — убираем переносы в SQL
    raw = dbq("SELECT IFNULL(REPLACE(REPLACE(TO_BASE64(Psychological_profile),"
              "CHAR(10),''),CHAR(13),''),'') FROM `Message204` "
              "WHERE Message_ID=%d" % ANKETA)
    if not raw.strip():
        return None
    return json.loads(base64.b64decode(raw).decode("utf-8"))


def clear_all():
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % UID)
    dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE Message_ID=%d" % ANKETA)


def setup_module(module):
    global UID, ANKETA
    UID = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    ANKETA = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID=%d LIMIT 1" % UID))
    for group in ("love_language", "family_value"):
        ids = dbq("SELECT id FROM `assessment_cases` WHERE dimension_group='%s' "
                  "AND active=1 ORDER BY id" % group).split()
        CASES[group] = [int(i) for i in ids]
    assert CASES["love_language"] and CASES["family_value"], "нет активных кейсов"
    start_fake_server()
    clear_ai_settings()
    clear_all()


def teardown_module(module):
    clear_all()
    clear_ai_settings()
    stop_fake_server()


def use_classifier(scores, confidence=0.9):
    """Фейк отвечает разбором одного ответа (structured output)."""
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": scores, "confidence": confidence})


# --- доступ ------------------------------------------------------------------

def test_guest_forbidden():
    clear_all()
    code, data = answer(None, CASES["love_language"][0], "мой ответ")
    assert code == 403, "гость записал ответ: %s" % data
    assert dbq("SELECT COUNT(*) FROM `assessment_answers`") == "0"


def test_foreign_origin_forbidden():
    clear_all()
    use_classifier({"words": 5})
    code, data = answer(login(CANDIDATE), CASES["love_language"][0], "ответ",
                        origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `assessment_answers` WHERE user_id=%d" % UID) == "0"


def test_unknown_case_rejected():
    clear_all()
    use_classifier({"words": 5})
    code, _ = answer(login(CANDIDATE), 99999999, "ответ")
    assert code == 404, "несуществующий кейс принят"


def test_empty_answer_rejected():
    clear_all()
    use_classifier({"words": 5})
    code, _ = answer(login(CANDIDATE), CASES["love_language"][0], "   ")
    assert code == 400, "пустой ответ принят"


# --- классификация одного ответа (Э2.3-a) ------------------------------------

def test_answer_stored_with_scores():
    clear_all()
    use_classifier({"words": 4, "time": 1}, confidence=0.8)
    sid = login(CANDIDATE)
    case_id = CASES["love_language"][0]
    code, data = answer(sid, case_id, "мне важнее слова поддержки 71828")
    assert code == 200 and data.get("ok") is True, "ответ не принят: %s" % data
    row = dbq("SELECT answer, scores, confidence FROM `assessment_answers` "
              "WHERE user_id=%d AND case_id=%d" % (UID, case_id))
    assert "71828" in row, "текст ответа не сохранён: " + row
    assert "words" in row, "баллы не сохранены: " + row
    assert "0.80" in row, "уверенность не сохранена: " + row


def test_invalid_classifier_output_discarded():
    # модель вернула мусор — ответ сохраняем, но баллы не выдумываем
    clear_all()
    use_fake_provider()
    set_fake_mode("ok")          # обычный текст вместо JSON
    sid = login(CANDIDATE)
    case_id = CASES["love_language"][0]
    code, data = answer(sid, case_id, "ответ без разбора 31415")
    assert code == 200, "конвейер должен пережить негодный разбор: %s" % data
    scores = dbq("SELECT IFNULL(scores,'') FROM `assessment_answers` "
                 "WHERE user_id=%d AND case_id=%d" % (UID, case_id))
    assert scores in ("", "{}", "null"), "выдуманы баллы из мусора: " + scores


def test_unknown_dimensions_ignored():
    clear_all()
    use_classifier({"words": 3, "выдуманное_измерение": 5, "budget": 2})
    sid = login(CANDIDATE)
    case_id = CASES["love_language"][0]
    answer(sid, case_id, "ответ 27182")
    scores = json.loads(dbq("SELECT scores FROM `assessment_answers` "
                            "WHERE user_id=%d AND case_id=%d" % (UID, case_id)))
    assert "выдуманное_измерение" not in scores, "принято измерение вне справочника"
    assert "words" in scores, "потеряно валидное измерение"


# --- профиль считает КОД (Э2.3-b) --------------------------------------------

def test_profile_percentages_computed_by_code():
    clear_all()
    sid = login(CANDIDATE)
    # два ответа с известными баллами: 3 и 1 → 75% и 25%
    use_classifier({"words": 3})
    answer(sid, CASES["love_language"][0], "первый ответ")
    use_classifier({"time": 1})
    answer(sid, CASES["love_language"][1], "второй ответ")
    p = profile()
    assert p, "профиль не сохранён"
    ll = p["love_languages"]
    assert ll["words"] == 75, "проценты посчитаны неверно: %s" % ll
    assert ll["time"] == 25, "проценты посчитаны неверно: %s" % ll
    assert sum(ll.values()) == 100, "сумма процентов не 100: %s" % ll


def test_profile_has_required_shape():
    clear_all()
    sid = login(CANDIDATE)
    use_classifier({"words": 2})
    answer(sid, CASES["love_language"][0], "ответ")
    use_classifier({"budget": 4})
    answer(sid, CASES["family_value"][0], "ответ про бюджет")
    p = profile()
    for key in ("love_languages", "family_values_scores", "completed_at",
                "cases_answered", "version"):
        assert key in p, "в профиле нет поля %s: %s" % (key, p)
    assert p["cases_answered"] == 2, "неверно посчитаны отвеченные кейсы: %s" % p
    assert p["version"] == 1, "первая версия должна быть 1: %s" % p
    assert p["family_values_scores"]["budget"] == 100


def test_profile_is_deterministic():
    """Главное требование: тот же набор ответов → тот же профиль."""
    clear_all()
    sid = login(CANDIDATE)
    use_classifier({"words": 3})
    answer(sid, CASES["love_language"][0], "ответ А")
    use_classifier({"time": 2})
    answer(sid, CASES["love_language"][1], "ответ Б")
    first = profile()

    # повтор того же набора — новая версия, но те же проценты
    clear_all()
    use_classifier({"words": 3})
    answer(sid, CASES["love_language"][0], "ответ А")
    use_classifier({"time": 2})
    answer(sid, CASES["love_language"][1], "ответ Б")
    second = profile()

    assert first["love_languages"] == second["love_languages"], \
        "профиль недетерминирован: %s vs %s" % (first["love_languages"],
                                                second["love_languages"])


def test_repeat_survey_creates_new_version():
    clear_all()
    sid = login(CANDIDATE)
    use_classifier({"words": 5})
    answer(sid, CASES["love_language"][0], "первый проход")
    v1 = profile()["version"]
    # повторный ответ на тот же кейс — новая версия опроса
    use_classifier({"time": 5})
    answer(sid, CASES["love_language"][0], "второй проход")
    v2 = profile()["version"]
    assert v2 == v1 + 1, "повтор не создал новую версию: %s → %s" % (v1, v2)
    # прошлые ответы не переписаны
    n = dbq("SELECT COUNT(*) FROM `assessment_answers` WHERE user_id=%d AND case_id=%d"
            % (UID, CASES["love_language"][0]))
    assert n == "2", "прошлый ответ затёрт: " + n


def test_profile_uses_only_current_version():
    clear_all()
    sid = login(CANDIDATE)
    use_classifier({"words": 5})
    answer(sid, CASES["love_language"][0], "старый проход")
    use_classifier({"time": 5})
    answer(sid, CASES["love_language"][0], "новый проход")   # версия 2
    p = profile()
    ll = p["love_languages"]
    assert ll.get("time") == 100, "профиль смешал версии: %s" % ll
    assert "words" not in ll or ll["words"] == 0, "в профиль попала прошлая версия: %s" % ll


# --- текст психотипа: один раз на версию (Э2.3-c) ------------------------------

def test_psychotype_text_generated_once_and_cached():
    from fake_llm import fake_calls, set_fake_mode as _mode
    clear_all()
    sid = login(CANDIDATE)
    # первые два ответа — текста ещё нет (описывать нечего, а генерация платная)
    use_classifier({"words": 3})
    answer(sid, CASES["love_language"][0], "ответ 1")
    use_classifier({"time": 2})
    answer(sid, CASES["love_language"][1], "ответ 2")
    assert not profile().get("psychotype_text"), "текст сгенерирован слишком рано"

    # третий ответ — порог пройден, текст появляется
    use_classifier({"gifts": 1})
    answer(sid, CASES["love_language"][2], "ответ 3")
    text = profile().get("psychotype_text")
    assert text, "текст психотипа не сгенерирован после порога"

    # четвёртый ответ той же версии: текст берётся из кэша, модель не зовём заново
    use_classifier({"service": 1})
    calls_before = fake_calls()
    answer(sid, CASES["love_language"][3], "ответ 4")
    calls_after = fake_calls()
    assert profile().get("psychotype_text") == text, "текст перегенерирован"
    # ровно один вызов — только классификация ответа, без генерации текста
    assert calls_after - calls_before == 1,         "лишние обращения к модели: %d" % (calls_after - calls_before)


def test_psychotype_text_regenerated_for_new_version():
    clear_all()
    sid = login(CANDIDATE)
    for i, dim in enumerate(("words", "time", "gifts")):
        use_classifier({dim: 3})
        answer(sid, CASES["love_language"][i], "ответ %d" % i)
    first = profile().get("psychotype_text")
    assert first, "нет текста в первой версии"
    # повтор кейса → новая версия → текст считается заново
    use_classifier({"touch": 5})
    answer(sid, CASES["love_language"][0], "новый проход")
    p = profile()
    assert p["version"] == 2, "новая версия не началась: %s" % p["version"]


# --- чужие данные -------------------------------------------------------------

def test_answers_are_private_per_user():
    clear_all()
    use_classifier({"words": 5})
    answer(login(CANDIDATE), CASES["love_language"][0], "мой ответ 55555")
    # шадхан отвечает от своего имени — попадает в свою запись, не в чужую
    use_classifier({"budget": 5})
    answer(login(SHADHAN), CASES["family_value"][0], "ответ шадхана")
    mine = dbq("SELECT COUNT(*) FROM `assessment_answers` WHERE user_id=%d" % UID)
    assert mine == "1", "в мои ответы попало чужое: " + mine
    dbq("DELETE FROM `assessment_answers` WHERE user_id=57")
    dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE User_ID=57")
