# -*- coding: utf-8 -*-
"""
C10 этапа «До идеала»: очередь «сигналов внимания» — экран /admin-moderation.php,
закрытие сигнала через /api/moderation_resolve.php (администратор или ведущий
шадхан кандидата), кнопка «Посмотрел» в карточке кандидата.

Что было: risk_note_resolve() никто не вызывал — сигналы копились навсегда.

    python -m pytest tests/admin_moderation_test.py -q
"""
import os
import re
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, NoRedirect, backup_row, csrf_token, dbq, login, restore_row, web  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"        # User 1
SHADHAN = "u3f9bb8c20b01@example.local"      # User 57 — станет ведущим анкеты 42
OTHER_SHADHAN = "ue3fd6199f1a0@example.local"  # User 43 — чужой шадхан
CANDIDATE = "u091ff2b36c83@example.local"    # User 50, анкета 42
CANDIDATE_ID = 50
ANKETA = 42
KIND = "incoherence"

_SID = {}
STATE = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def get(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def resolve(sid, note_id, origin=BASE):
    body = urllib.parse.urlencode({"note_id": note_id}).encode()
    req = urllib.request.Request(BASE + "/api/moderation_resolve.php", data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
        req.add_header("X-CSRF-Token", csrf_token(sid))
    if origin:
        req.add_header("Origin", origin)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def php(code):
    boot = ("$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
            "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
            "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
            "require_once $r.'/api/risk_signal_lib.php'; $db=nc_core::get_object()->db; ")
    return (web("php", "-r", boot + code).stdout or "").strip()


def new_note():
    """Свежий открытый сигнал по кандидату 50 (дубли за сутки библиотека не создаёт)."""
    dbq("DELETE FROM `moderation_notes` WHERE user_id=%d AND kind='%s'" % (CANDIDATE_ID, KIND))
    assert php("var_export(risk_note_add($db, %d, '%s'));" % (CANDIDATE_ID, KIND)).endswith("true")
    return int(dbq("SELECT MAX(id) FROM `moderation_notes` WHERE user_id=%d AND kind='%s'" % (CANDIDATE_ID, KIND)))


def setup_module(module):
    STATE["row"] = backup_row("Message204", ANKETA, ("Shadhan_1", "Shadhan_2", "Shadhan_3", "LastUpdated"))
    dbq("UPDATE `Message204` SET Shadhan_1=57 WHERE Message_ID=%d" % ANKETA)


def teardown_module(module):
    dbq("DELETE FROM `moderation_notes` WHERE user_id=%d AND kind='%s'" % (CANDIDATE_ID, KIND))
    restore_row("Message204", ANKETA, STATE["row"])


# ------------------------------------------------------------- экран -----

def test_admin_page_lists_open_signal():
    note = new_note()
    code, html = get("/admin-moderation.php", sid_of(ADMIN))
    assert code == 200, code
    for mark in ("Fatal error", "Parse error", "Warning:"):
        assert mark not in html
    assert 'data-note="%d"' % note in html, "сигнал не в очереди"
    assert "candidate_%d.html" % ANKETA in html, "нет ссылки на анкету кандидата"
    assert "Бессвязность изложения" in html and "не диагноз" in html
    assert 'class="btn btn--ghost js-resolve"' in html
    assert "СЕНТИНЕЛ" not in html


def test_page_forbidden_for_others():
    for who in (SHADHAN, CANDIDATE, None):
        code, html = get("/admin-moderation.php", sid_of(who) if who else None)
        assert code == 403, "%s: %d" % (who, code)


# ------------------------------------------------------------- закрытие --

def test_candidate_and_foreign_shadhan_cannot_resolve():
    note = new_note()
    code, _ = resolve(sid_of(CANDIDATE), note)
    assert code == 403, code
    code, _ = resolve(sid_of(OTHER_SHADHAN), note)
    assert code == 403, code
    code, _ = resolve(None, note)
    assert code in (401, 403), code
    assert dbq("SELECT resolved_at IS NULL FROM `moderation_notes` WHERE id=%d" % note) == "1", "сигнал закрыт без прав"


def test_leading_shadhan_resolves_once():
    note = new_note()
    code, out = resolve(sid_of(SHADHAN), note)
    assert code == 200, out
    row = dbq("SELECT resolved_at IS NOT NULL, resolved_by FROM `moderation_notes` WHERE id=%d" % note)
    assert row == "1\t57", row
    code, _ = resolve(sid_of(SHADHAN), note)
    assert code == 409, "повторное закрытие выглядит успехом"
    code, _ = resolve(sid_of(ADMIN), 999999999)
    assert code == 404


def test_admin_resolves_and_queue_shrinks():
    note = new_note()
    _, html = get("/admin-moderation.php", sid_of(ADMIN))
    assert 'data-note="%d"' % note in html
    code, out = resolve(sid_of(ADMIN), note)
    assert code == 200, out
    _, html = get("/admin-moderation.php", sid_of(ADMIN))
    assert 'data-note="%d"' % note not in html, "закрытый сигнал остался в очереди"
    assert 'id="doneTable"' in html, "закрытый сигнал не виден в недавних"


def test_foreign_origin_rejected():
    note = new_note()
    code, _ = resolve(sid_of(ADMIN), note, origin="https://evil.example")
    assert code == 403
    assert dbq("SELECT resolved_at IS NULL FROM `moderation_notes` WHERE id=%d" % note) == "1"


# ------------------------------------------------------------- карточка --

def test_card_has_resolve_button_wired():
    note = new_note()
    code, html = get("/my/my-candidates/moi-kandidaty_%d.html" % ANKETA, sid_of(SHADHAN))
    assert code == 200, code
    assert "Сигналы внимания" in html
    assert 'data-sh-risks' in html, "нет корня блока для lk-card.js"
    assert 'js-risk-resolve" data-note="%d"' % note in html, "нет кнопки «Посмотрел» у сигнала"
    js = urllib.request.urlopen(BASE + "/js/lk-card.js", timeout=60).read().decode("utf-8", "replace")
    assert "js-risk-resolve" in js and "/api/moderation_resolve.php" in js


def test_admin_nav_has_new_screens():
    _, html = get("/admin-cases.php", sid_of(ADMIN))
    for href in ("/admin-moderation.php", "/admin-consent.php", "/admin-matches.php"):
        assert 'href="%s"' % href in html, href
    for rel in ("api/moderation_lib.php", "api/moderation_resolve.php", "admin-moderation.php"):
        r = web("php", "-l", "/var/www/html/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
