# -*- coding: utf-8 -*-
"""
Тесты рамки психологического портрета (ROADMAP X-4).

Шадханы просили «психотипы по Нэнси Маквильямс». Решение: клиническую
типологию ярлыками НЕ внедряем — опрос из 12 бытовых кейсов не валиден
для психиатрической типизации, а ярлык о живом человеке в руках свата
приносит вред, а не пользу. Вместо этого:

  * показываем ценностный профиль (что человеку важно), а не тип личности;
  * везде, где портрет виден, стоит оговорка: это самоопрос, ориентир для
    разговора, а не диагноз и не оценка личности;
  * промпт прямо запрещает модели ставить диагнозы, давать ярлыки и
    употреблять клиническую терминологию.

    python -m pytest tests/psychotype_framing_test.py -q
"""
import json
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, NoRedirect, clear_ai_settings, dbq, login,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider, web)

SHADHAN = "u3f9bb8c20b01@example.local"     # ведёт анкету 2033
CANDIDATE = "u091ff2b36c83@example.local"
ANKETA = 2033
CARD = "/my/my-candidates/moi-kandidaty_%d.html" % ANKETA
CONSULTANT = "/my/ai-consultant/"

PROFILE = {
    "love_languages": {"time": 40, "words": 30, "service": 15, "touch": 10, "gifts": 5},
    "family_values_scores": {"budget": 55, "children": 30, "religion": 15},
    "cases_answered": 7,
    "completed_at": "2026-08-24 08:13:38",
    "version": 1,
    "psychotype_text": "Для неё важно время вместе.",
}

OWNER_UID = None


def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=180) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def set_profile(uid, profile):
    if profile is None:
        dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE User_ID=%d" % uid)
        return
    raw = json.dumps(profile, ensure_ascii=False).replace("\\", "\\\\").replace("'", "''")
    dbq("UPDATE `Message204` SET Psychological_profile='%s' WHERE Message_ID="
        "(SELECT m FROM (SELECT MIN(Message_ID) m FROM `Message204` WHERE User_ID=%d) t)"
        % (raw, uid))


def setup_module(module):
    global OWNER_UID
    OWNER_UID = int(dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % ANKETA))
    set_profile(OWNER_UID, PROFILE)


def teardown_module(module):
    set_profile(OWNER_UID, None)
    clear_ai_settings()


# --- оговорка в интерфейсе ----------------------------------------------------

def test_card_shows_disclaimer_next_to_portrait():
    """Шадхан видит портрет — рядом должна быть оговорка, а не только цифры."""
    code, html = get_page(CARD, login(SHADHAN))
    assert code == 200, "карточка не открылась: %d" % code
    assert "Психологический портрет" in html, "нет блока портрета"
    low = html.lower()
    assert "не диагноз" in low or "не является диагнозом" in low, \
        "нет оговорки, что это не диагноз"
    assert "самоопрос" in low or "со слов" in low or "сам о себе" in low, \
        "не сказано, что данные получены самоопросом"
    assert "разговор" in low, "не сказано, что это ориентир для разговора"


def test_candidate_sees_disclaimer_too():
    """Кандидат видит свои проценты — оговорка нужна и ему.

    Блок процентов рисуется только когда опрос пройден, поэтому на время
    проверки выдаём кандидату профиль и отвечаем за него на все кейсы."""
    cand_uid = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    set_profile(cand_uid, PROFILE)
    # «опрос пройден» = отвечены все активные кейсы текущей версии
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % cand_uid)
    dbq("INSERT INTO `assessment_answers` (user_id, case_id, answer, scores, "
        "confidence, version, created_at) "
        "SELECT %d, id, 'ответ', '', 0.5, 1, NOW() FROM `assessment_cases` WHERE active=1"
        % cand_uid)
    try:
        code, html = get_page(CONSULTANT, login(CANDIDATE))
        assert code == 200
        assert "Опрос пройден" in html, "блок результатов опроса не отрисовался"
        low = html.lower()
        assert "не диагноз" in low or "не является диагнозом" in low, \
            "кандидату не показана оговорка"
    finally:
        dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % cand_uid)
        set_profile(cand_uid, None)


# --- запреты в промпте --------------------------------------------------------

def test_prompt_forbids_diagnoses_and_labels():
    """Промпт психотипа должен явно запрещать диагнозы, ярлыки и клинику."""
    start_fake_server()
    try:
        use_fake_provider()
        set_fake_mode("echo_system")
        out = web("php", "-r",
                  "$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
                  "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
                  "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
                  "require_once $r.'/api/scoring_lib.php'; "
                  "echo scoring_psychotype_text(array('cases_answered'=>7,"
                  "'love_languages'=>array('time'=>60,'words'=>40),"
                  "'family_values_scores'=>array('budget'=>100)));").stdout or ""
        low = out.lower()
        assert "диагноз" in low, "промпт не запрещает диагнозы"
        assert "ярлык" in low or "тип личности" in low, \
            "промпт не запрещает ярлыки и типы личности"
        assert "маквильямс" not in low, \
            "в промпте всплыла клиническая типология Маквильямс"
    finally:
        stop_fake_server()
        clear_ai_settings()


def test_no_clinical_typology_in_code():
    """Клинические ярлыки не должны появиться в коде как справочник типов."""
    out = web("sh", "-c",
              "grep -ril 'маквильямс\\|нарцисс\\|пограничн\\|шизоид\\|психопат' "
              "/var/www/html/api /var/www/html/netcat_template/class/204/213 "
              "/var/www/html/netcat_template/class/323 2>/dev/null || true").stdout or ""
    assert out.strip() == "", "в коде появилась клиническая типология: " + out.strip()
