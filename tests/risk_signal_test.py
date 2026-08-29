# -*- coding: utf-8 -*-
"""
Тесты «сигнала риска» (ROADMAP X-3).

Шадханы просили «улавливать адекватность из переписки». Это близко к
псевдопсихиатрии, поэтому рамка жёсткая:

  * сигнал — НЕ диагноз и не оценка личности, а повод живому шадхану
    обратить внимание; решение всегда за человеком;
  * категории поведенческие и наблюдаемые: агрессия/угрозы, бессвязность,
    признаки острого неблагополучия. Никаких «типов личности»;
  * в заметку НЕ попадают цитаты из переписки — только категория и
    нейтральная формулировка: сигнал не должен превращаться в пересказ
    личной переписки третьему лицу;
  * хранится как заметка модерации (`moderation_notes`), а не как поле
    анкеты — это не характеристика человека;
  * видит только ведущий шадхан анкеты и администратор;
  * кандидату это раскрыто в интерфейсе консультанта;
  * отдельного платного вызова модели нет: сигнал поднимает инструмент у
    самого консультанта.

    python -m pytest tests/risk_signal_test.py -q
"""
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, NoRedirect, clear_ai_settings, dbq, login, post_json,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider, web)

CANDIDATE = "u091ff2b36c83@example.local"   # User 50
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету 2033
OTHER_SHADHAN = None
CAND_UID = 50
ANKETA = 2033
CARD = "/my/my-candidates/moi-kandidaty_%d.html" % ANKETA
CONSULTANT = "/my/ai-consultant/"


def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=180) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def notes(uid=CAND_UID):
    return dbq("SELECT kind, note FROM `moderation_notes` WHERE user_id=%d" % uid)


def clear_notes():
    dbq("DELETE FROM `moderation_notes`")


def owner_of_anketa():
    return int(dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % ANKETA))


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    clear_notes()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    clear_notes()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % CAND_UID)


# --- схема -------------------------------------------------------------------

def test_notes_table_exists_and_is_not_anketa_field():
    assert dbq("SHOW TABLES LIKE 'moderation_notes'") != "", "нет таблицы moderation_notes"
    # это НЕ характеристика человека — в анкете такого поля быть не должно
    for bad in ("Risk_signal", "Risk_level", "Adequacy"):
        assert dbq("SHOW COLUMNS FROM `Message204` LIKE '%s'" % bad) == "", \
            "сигнал риска просочился в поля анкеты: " + bad


# --- категории и рамка --------------------------------------------------------

def test_only_behavioural_kinds_allowed():
    out = (web("php", "-r",
               "require '/var/www/html/api/risk_signal_lib.php'; "
               "echo implode(',', array_keys(risk_kinds()));").stdout or "").strip()
    kinds = set(out.split(","))
    assert kinds == {"aggression", "incoherence", "distress"}, \
        "неожиданный список категорий: " + out


def test_kind_titles_are_not_diagnoses():
    out = (web("php", "-r",
               "require '/var/www/html/api/risk_signal_lib.php'; "
               "echo implode(' | ', risk_kinds());").stdout or "").strip().lower()
    for bad in ("диагноз", "расстройство", "психопат", "шизо", "маниакальн", "биполярн"):
        assert bad not in out, "клиническая формулировка в названии категории: " + bad


def test_note_stores_no_verbatim_quotes():
    """В заметку не должен попадать текст переписки."""
    clear_notes()
    secret = "СЕКРЕТНАЯ-ФРАЗА-ИЗ-ПЕРЕПИСКИ-9182"
    web("php", "-r",
        "$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
        "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
        "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
        "require_once $r.'/api/risk_signal_lib.php'; "
        "risk_note_add(nc_core::get_object()->db, %d, 'aggression', '%s');" % (CAND_UID, secret))
    stored = notes()
    assert stored != "", "заметка не сохранилась"
    assert secret not in stored, "в заметку попала цитата из переписки: " + stored


def test_duplicate_signals_do_not_pile_up():
    """Один и тот же сигнал за день не должен множиться в список."""
    clear_notes()
    php = ("$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
           "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
           "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
           "require_once $r.'/api/risk_signal_lib.php'; $db=nc_core::get_object()->db; "
           "risk_note_add($db, %d, 'aggression', 'резкий тон'); "
           "risk_note_add($db, %d, 'aggression', 'резкий тон снова');" % (CAND_UID, CAND_UID))
    web("php", "-r", php)
    n = dbq("SELECT COUNT(*) FROM `moderation_notes` WHERE user_id=%d" % CAND_UID)
    assert n == "1", "сигналы дублируются: %s строк" % n


# --- доступ ------------------------------------------------------------------

def test_leading_shadhan_sees_signal_with_framing():
    clear_notes()
    uid = owner_of_anketa()
    dbq("INSERT INTO `moderation_notes` (user_id, kind, note, source, created_at) "
        "VALUES (%d, 'aggression', 'Резкий тон в переписке', 'ai', NOW())" % uid)
    code, html = get_page(CARD, login(SHADHAN))
    assert code == 200, "карточка не открылась: %d" % code
    assert "Сигналы внимания" in html, "нет блока сигналов у ведущего шадхана"
    low = html.lower()
    assert "не диагноз" in low, "нет оговорки, что сигнал не диагноз"
    assert "решение" in low and "за вами" in low, "не сказано, что решение за шадханом"


def test_candidate_does_not_see_signals():
    clear_notes()
    uid = owner_of_anketa()
    dbq("INSERT INTO `moderation_notes` (user_id, kind, note, source, created_at) "
        "VALUES (%d, 'aggression', 'Резкий тон в переписке', 'ai', NOW())" % uid)
    own = int(dbq("SELECT MIN(Message_ID) FROM `Message204` WHERE User_ID=%d" % CAND_UID))
    code, html = get_page("/my/my-candidates/moi-kandidaty_%d.html" % own, login(CANDIDATE))
    assert code == 200
    assert "Сигналы внимания" not in html, "кандидат видит блок сигналов"


# --- прозрачность для кандидата ----------------------------------------------

def test_consultant_discloses_signal_to_candidate():
    code, html = get_page(CONSULTANT, login(CANDIDATE))
    assert code == 200
    low = html.lower()
    assert "шадхан" in low and ("обратить внимание" in low or "сигнал" in low), \
        "кандидату не раскрыто, что консультант может передать сигнал шадхану"


# --- сигнал поднимается инструментом, без отдельного платного вызова ----------

def test_signal_raised_via_tool_without_extra_call():
    clear_notes()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % CAND_UID)
    set_fake_mode("risk_tool", tool_args={"kind": "aggression",
                                          "note": "Резкие формулировки в адрес собеседника"})
    code, data = post_json("/api/ai_chat.php", login(CANDIDATE), {"message": "любое сообщение"})
    assert code == 200, "чат не ответил: %s" % data
    stored = notes()
    assert "aggression" in stored, "сигнал не сохранён: " + repr(stored)
