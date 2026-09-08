# -*- coding: utf-8 -*-
"""
C11 этапа «До идеала»: экран согласий на сведения о здоровье — счётчики,
список анкет без согласия (без самого поля), выгрузка CSV и запрос
повторного согласия письмом через очередь рассылок.

Решение заказчика: данные не стирать. Экран этого и не умеет.

Только dev-стенд: тест шлёт настоящую рассылку всем владельцам анкет без
согласия (в очередь Mail_Queue, крон локально выключен) и потом чистит
mailings/mailing_log/Mail_Queue по маркеру consent_request.

    python -m pytest tests/admin_consent_test.py -q
"""
import os
import re
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, NoRedirect, backup_row, csrf_token, dbq, login, restore_row, web  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"       # User 1
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50 (Confirmed=1), анкета 42
ANKETA = 42
CANDIDATE_ID = 50
SENTINEL = "аллергия-СЕНТИНЕЛ-C11"
ROW_FIELDS = ("Health_status", "Consent_sensitive", "Consent_sensitive_at", "Consent_sensitive_version", "LastUpdated")
SUBJECT = "Тест-запрос согласия C11"

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
        return r.status, r.read().decode("utf-8", "replace"), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)


def post(path, sid, fields, origin=BASE):
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
        req.add_header("X-CSRF-Token", csrf_token(sid))
    if origin:
        req.add_header("Origin", origin)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=300)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def cleanup_mailing():
    ids = dbq("SELECT GROUP_CONCAT(id) FROM `mailings` WHERE filters LIKE '%%consent_request%%'")
    for mid in [x for x in (ids or "").split(",") if x]:
        dbq("DELETE q FROM `Mail_Queue` q JOIN `mailing_log` l ON l.email=q.recipient WHERE l.mailing_id=%s AND q.create_time>=DATE_SUB(NOW(), INTERVAL 1 DAY)" % mid)
        dbq("DELETE FROM `mailing_log` WHERE mailing_id=%s" % mid)
    dbq("DELETE FROM `mailings` WHERE filters LIKE '%%consent_request%%'")


def setup_module(module):
    STATE["row"] = backup_row("Message204", ANKETA, ROW_FIELDS)
    # анкета 42: сведения есть, согласия нет — попадает в список и в адресаты
    dbq("UPDATE `Message204` SET Health_status='%s', Consent_sensitive=0, Consent_sensitive_at=NULL, "
        "Consent_sensitive_version=NULL WHERE Message_ID=%d" % (SENTINEL, ANKETA))
    cleanup_mailing()
    dbq("DELETE FROM `mailing_optout` WHERE user_id=%d AND opted_out=0 AND created_at>=DATE_SUB(NOW(), INTERVAL 1 DAY)" % CANDIDATE_ID)


def teardown_module(module):
    cleanup_mailing()
    restore_row("Message204", ANKETA, STATE["row"])


def sql_stats():
    return {
        "with": dbq("SELECT COUNT(*) FROM `Message204` WHERE Consent_sensitive=1"),
        "pending": dbq("SELECT COUNT(*) FROM `Message204` WHERE IFNULL(Consent_sensitive,0)=0 AND IFNULL(Health_status,'')<>''"),
        "total": dbq("SELECT COUNT(*) FROM `Message204`"),
    }


# ------------------------------------------------------------- экран -----

def test_page_counters_match_sql_and_hide_health():
    code, html, _ = get("/admin-consent.php", sid_of(ADMIN))
    assert code == 200, code
    for mark in ("Fatal error", "Parse error", "Warning:"):
        assert mark not in html
    s = sql_stats()
    assert re.search(r'id="statWith">%s<' % s["with"], html), "счётчик согласий не совпал"
    assert re.search(r'id="statPending">%s<' % s["pending"], html), "счётчик «без согласия» не совпал"
    assert re.search(r'id="statTotal">%s<' % s["total"], html)
    assert 'data-anketa="%d"' % ANKETA in html, "анкета без согласия не в списке"
    assert SENTINEL not in html, "экран показывает содержимое поля здоровья"
    assert "не стираются" in html.lower() or "не стирать" in html.lower()


def test_page_forbidden_for_others():
    for who in (SHADHAN, CANDIDATE, None):
        code, _, _ = get("/admin-consent.php", sid_of(who) if who else None)
        assert code == 403, "%s: %d" % (who, code)


# ------------------------------------------------------------- CSV -------

def test_csv_export_lists_anketa_without_health():
    code, body, headers = get("/api/consent_export.php", sid_of(ADMIN))
    assert code == 200, code
    assert headers.get("Content-Type", "").startswith("text/csv"), headers.get("Content-Type")
    assert "attachment" in headers.get("Content-Disposition", "")
    assert body.startswith("\ufeff"), "нет BOM — Excel покажет кракозябры"
    lines = body.lstrip("\ufeff").split("\r\n")
    assert lines[0].startswith("Анкета;Имя;Пользователь;E-mail;")
    assert any(l.startswith("%d;" % ANKETA) for l in lines), "анкета 42 не выгружена"
    assert SENTINEL not in body and "Здоровье" not in lines[0] and "Health" not in lines[0]
    code, _, _ = get("/api/consent_export.php", sid_of(SHADHAN))
    assert code == 403
    code, _, _ = get("/api/consent_export.php", None)
    assert code == 403


# ------------------------------------------------------------- письмо ----

def test_request_mailing_queues_only_pending_owners_and_dedupes():
    # владелец анкеты с согласием — не адресат
    with_consent = dbq("SELECT u.User_ID, u.Email FROM `Message204` m JOIN `User` u ON u.User_ID=m.User_ID "
                       "WHERE m.Consent_sensitive=1 AND u.Confirmed=1 AND u.Email<>'' "
                       "AND NOT EXISTS (SELECT 1 FROM `Message204` p WHERE p.User_ID=u.User_ID AND IFNULL(p.Consent_sensitive,0)=0 AND IFNULL(p.Health_status,'')<>'') LIMIT 1")
    code, out = post("/api/consent_request_mailing.php", sid_of(ADMIN), {"subject": SUBJECT, "body": "Тестовое письмо: подтвердите согласие."})
    assert code == 200, out
    mid = int(re.search(r'"mailing_id":(\d+)', out).group(1))
    expected = int(dbq("SELECT COUNT(DISTINCT u.User_ID) FROM `User` u JOIN `Message204` m ON m.User_ID=u.User_ID "
                       "WHERE IFNULL(m.Consent_sensitive,0)=0 AND IFNULL(m.Health_status,'')<>'' AND u.Confirmed=1 AND u.Email<>'' "
                       "AND u.Email NOT LIKE 'invite-%%@invite.shiduh.org' "
                       "AND NOT EXISTS (SELECT 1 FROM `mailing_optout` o WHERE o.user_id=u.User_ID AND o.opted_out=1)"))
    queued = int(dbq("SELECT COUNT(*) FROM `mailing_log` WHERE mailing_id=%d AND status IN ('queued','error')" % mid))
    assert queued == expected, "адресатов %d, ожидалось %d" % (queued, expected)
    assert dbq("SELECT COUNT(*) FROM `mailing_log` WHERE mailing_id=%d AND user_id=%d" % (mid, CANDIDATE_ID)) == "1", "владелец анкеты без согласия не получил письмо"
    if with_consent:
        uid_c = with_consent.split("\t")[0]
        assert dbq("SELECT COUNT(*) FROM `mailing_log` WHERE mailing_id=%d AND user_id=%s" % (mid, uid_c)) == "0", "письмо ушло тому, у кого согласие есть"
    body = dbq("SELECT body FROM `Mail_Queue` WHERE recipient='%s' ORDER BY id DESC LIMIT 1" % CANDIDATE)
    assert "mailing_unsubscribe.php?t=" in body.replace("=\r\n", "").replace("=\n", ""), "в письме нет ссылки отписки"
    assert dbq("SELECT subject FROM `mailings` WHERE id=%d" % mid) == SUBJECT
    # повтор через минуту — 409, второй рассылки нет
    code, out = post("/api/consent_request_mailing.php", sid_of(ADMIN), {"subject": SUBJECT, "body": "ещё раз"})
    assert code == 409, out
    assert dbq("SELECT COUNT(*) FROM `mailings` WHERE filters LIKE '%%consent_request%%'") == "1"


def test_request_mailing_forbidden_for_others_no_side_effects():
    before = dbq("SELECT COUNT(*) FROM `mailings`")
    code, _ = post("/api/consent_request_mailing.php", sid_of(SHADHAN), {})
    assert code == 403
    code, _ = post("/api/consent_request_mailing.php", sid_of(ADMIN), {}, origin="https://evil.example")
    assert code == 403
    assert dbq("SELECT COUNT(*) FROM `mailings`") == before


def test_files_compile():
    for rel in ("api/consent_admin_lib.php", "api/consent_export.php", "api/consent_request_mailing.php",
                "api/mailing_lib.php", "api/mailing_send.php", "admin-consent.php"):
        r = web("php", "-l", "/var/www/html/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
