# -*- coding: utf-8 -*-
"""
B8 этапа «До идеала»: опрос и чат знают друг о друге.

Ответить на сценарий можно двумя путями — в блоке опроса и словами в
диалоге. Раньше блоки не синхронизировались: после ответа в чате опрос
показывал уже отвеченный вопрос и «Отвечено 2 из 8» при фактических трёх;
после ответа в опросе чат предлагал «Ответить на вопрос консультанта»,
которого больше нет.

Что защищаем:
  - ответ чата несёт состояние опроса ТОЛЬКО когда в этом обмене записан
    ответ на сценарий — иначе лишний запрос к базе на каждую реплику;
  - ответ опроса несёт подсказки чата, посчитанные уже после записи;
  - устаревшая вкладка (сценарий уже отвечен в другом окне или в чате) не
    заводит тихо новую версию опроса — шадхан увидел бы вырожденный профиль
    из одного ответа. Новую версию заводит только осознанный повтор
    (restart=1, кнопка «Пройти заново»).

    python -m pytest tests/chat_survey_sync_test.py -q
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, get_text, login, post_json, set_fake_mode,
    start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "u091ff2b36c83@example.local"   # User 50 — у него есть кейсы опроса
UID = 50
CHAT = "/api/ai_chat.php"
ANSWER = "/api/assessment_answer.php"
PAGE = "/my/ai-consultant/"

_SID = {}
_SAVED = {}
SURVEY_CHIP = "Ответить на вопрос консультанта"


def sid():
    if "s" not in _SID:
        _SID["s"] = login(CANDIDATE)
    return _SID["s"]


def active_cases():
    return [int(x) for x in dbq(
        "SELECT id FROM `assessment_cases` WHERE active=1 ORDER BY sort_order, id").split()]


def next_case_id():
    return int(dbq(
        "SELECT c.id FROM `assessment_cases` c WHERE c.active=1 AND c.id NOT IN ("
        "  SELECT a.case_id FROM `assessment_answers` a WHERE a.user_id=%d "
        "  AND a.version=(SELECT IFNULL(MAX(version),1) FROM `assessment_answers` "
        "                 WHERE user_id=%d)"
        ") ORDER BY c.sort_order, c.id LIMIT 1" % (UID, UID)))


def versions():
    return dbq("SELECT IFNULL(MAX(version),0), COUNT(*) FROM `assessment_answers` WHERE user_id=%d" % UID)


def clear_answers():
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % UID)
    dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE User_ID=%d" % UID)


def answer(case_id, text="Проводить время вместе", restart=False):
    set_fake_mode("classify", classify={"scores": {"time": 3}, "confidence": 0.9})
    fields = {"case_id": case_id, "answer": text}
    if restart:
        fields["restart"] = "1"
    return post_json(ANSWER, sid(), fields)


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    # профиль — живая колонка анкеты: запоминаем, чтобы вернуть как было
    _SAVED["profile"] = dbq("SELECT IFNULL(Psychological_profile, '\\0') FROM `Message204` "
                            "WHERE User_ID=%d ORDER BY Message_ID LIMIT 1" % UID)
    clear_answers()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    clear_answers()
    saved = _SAVED.get("profile")
    if saved is not None and saved != "\0":
        dbq("UPDATE `Message204` SET Psychological_profile='%s' WHERE User_ID=%d"
            % (saved.replace("\\", "\\\\").replace("'", "''"), UID))
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)


# ------------------------------------------------------- чат → блок опроса ---

def test_chat_reply_carries_survey_state_after_dialog_answer():
    clear_answers()
    first = next_case_id()
    set_fake_mode("case_flow",
                  tool_args={"answer": "Мне важнее всего проводить время вместе"},
                  classify={"scores": {"time": 3}, "confidence": 0.9})
    code, js = post_json(CHAT, sid(), {"message": "отвечаю на ваш сценарий 31415"})
    assert code == 200, js
    survey = js.get("survey")
    assert survey, "ответ чата не принёс состояние опроса: %s" % list(js)
    assert survey["answered"] == 1, survey
    assert survey["total"] >= 1, survey
    assert survey["next"] and survey["next"]["id"] != first, \
        "следующий сценарий тот же, на который только что ответили: %s" % survey


def test_chat_reply_has_no_survey_when_nothing_recorded():
    """Без записи ответа — ни состояния, ни лишнего запроса."""
    set_fake_mode("ok")
    code, js = post_json(CHAT, sid(), {"message": "просто реплика 27182"})
    assert code == 200, js
    assert js.get("survey") is None, "состояние опроса отдано без записи ответа: %s" % js.get("survey")


# ------------------------------------------------------- опрос → подсказки ---

def test_survey_answer_returns_chips_and_last_answer_removes_survey_chip():
    """Подсказка «Ответить на вопрос консультанта» должна исчезнуть ровно
    тогда, когда вопросов не осталось, — и не раньше."""
    clear_answers()
    cases = active_cases()
    assert len(cases) >= 2, "для проверки нужно хотя бы два сценария"
    code, js = answer(cases[0])
    assert code == 200, js
    assert "chips" in js, "ответ опроса не принёс подсказок чата: %s" % list(js)
    assert SURVEY_CHIP in js["chips"], "после первого ответа подсказка про опрос пропала: %s" % js["chips"]
    for cid in cases[1:]:
        code, js = answer(cid)
        assert code == 200, js
    assert js["next"] is None, "опрос не завершён: %s" % js
    assert SURVEY_CHIP not in js["chips"], "опрос пройден, а подсказка осталась: %s" % js["chips"]


# ------------------------------------------------- устаревшая вкладка / повтор ---

def test_stale_tab_does_not_start_new_version():
    """Сценарий уже отвечен (в чате или в другой вкладке) — повторный ответ
    без restart не заводит версию 2 из одного ответа."""
    clear_answers()
    cid = next_case_id()
    code, js = answer(cid)
    assert code == 200, js
    code, js = answer(cid, "отвечаю из старой вкладки")
    assert code == 409, "устаревшая вкладка завела новую версию: %s %s" % (code, js)
    assert js.get("code") == "stale_case", js
    assert js.get("next") and js["next"]["id"] != cid, "не отдано актуальное состояние: %s" % js
    assert "chips" in js, "устаревшей вкладке не отданы подсказки"
    assert versions() == "1\t1", "появилась лишняя версия/ответ: " + versions()


def test_explicit_restart_starts_new_version():
    """«Пройти заново» — осознанно: с restart=1 новая версия заводится."""
    clear_answers()
    cid = next_case_id()
    answer(cid)
    code, js = answer(cid, "прохожу заново", restart=True)
    assert code == 200, js
    assert js["version"] == 2, "повтор не завёл новую версию: %s" % js
    assert versions() == "2\t2", versions()


# ---------------------------------------------------------------- страница ---

def test_page_wires_survey_and_pairs_script():
    clear_answers()
    code, html = get_text(PAGE, sid())
    assert code == 200, code
    assert "/js/consultant.js" in html, "общий скрипт опроса и пар не подключён"
    # опрос больше не носит инлайновых стилей и скрипта: они в слое и в файле
    assert ".ai-survey{" not in html, "инлайновые стили опроса остались в шаблоне"
    assert "document.getElementById('aiSurvey')" not in html, "скрипт опроса остался инлайновым"
    assert 'data-restart="1"' not in html, "обычная страница помечена как повтор опроса"
    code, html = get_text(PAGE, sid(), "retake=1")
    assert 'data-restart="1"' in html, "страница повтора не помечена — клиент не пошлёт restart=1"
