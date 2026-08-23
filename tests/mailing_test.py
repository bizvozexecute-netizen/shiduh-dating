# -*- coding: utf-8 -*-
"""
Тесты массовой рассылки по сегменту (ROADMAP Раздел IV, Р-1…Р-4).

Эндпоинты (только администратор NetCat — Permission.AdminType 6/7):
  POST /api/mailing_preview.php {gender?,age_min?,age_max?,city?}
       → {ok, total, confirmed, unconfirmed}
  POST /api/mailing_send.php    {фильтры..., subject, body}
       → {ok, mailing_id, queued}; письма ложатся в Mail_Queue (шлёт крон
       mailer по 20/тик — троттлинг Р-2), лог в mailing_log, в теле письма
       ссылка отписки с персональным токеном (Р-3)
  GET  /api/mailing_unsubscribe.php?t=<64hex> → отписка (без авторизации)

Сегмент: User.Type=1, не заготовки приглашений, не отписавшиеся.
Письма уходят только Confirmed=1; предпросмотр показывает и цифру
неподтверждённых (Р-4). Гоняется против Docker по HTTP.

    python -m pytest tests/mailing_test.py -q
"""
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
ADMIN = "u5760fc1c6357@example.local"      # User_ID 1, DIRECTOR
SHADHAN = "u3f9bb8c20b01@example.local"    # User_ID 57, Type=2, НЕ админ
CITY_MARKER = "Тестоград571113"
PLACEHOLDER_LIKE = "invite-%@invite.shiduh.org"
BASE_WHERE = ("u.Type=1 AND u.Email<>'' AND u.Email NOT LIKE '%s'"
              % PLACEHOLDER_LIKE)

STATE = {}


def dbq(sql):
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org", "-e", sql],
        cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=60,
    )
    return (r.stdout or "").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def login(email):
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0", "AUTH_USER": email, "AUTH_PW": PASSWORD,
    }).encode()
    req = urllib.request.Request(BASE + "/netcat/modules/auth/", data=body, method="POST")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        headers = opener.open(req, timeout=60).headers
    except urllib.error.HTTPError as e:
        headers = e.headers
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("не удалось авторизоваться: " + email)


def api_post(path, sid, fields, origin=None):
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if origin:
        req.add_header("Origin", origin)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        raw = r.read().decode("utf-8", "replace")
        code = r.status
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        code = e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, {"_raw": raw}


def http_get(path):
    req = urllib.request.Request(BASE + path)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def preview(sid, **filters):
    return api_post("/api/mailing_preview.php", sid, filters)


def send(sid, origin=None, **fields):
    return api_post("/api/mailing_send.php", sid, fields, origin=origin)


def queue_body(recipient):
    body = dbq("SELECT body FROM `Mail_Queue` WHERE recipient='%s' "
               "ORDER BY id DESC LIMIT 1" % recipient)
    # quoted-printable режет длинные строки мягкими переносами "=\n"
    return body.replace("=\r\n", "").replace("=\n", "")


def reset_mailing():
    for u in (STATE["confirmed_uid"], STATE["unconfirmed_uid"]):
        dbq("DELETE FROM `Mail_Queue` WHERE recipient="
            "(SELECT Email FROM `User` WHERE User_ID=%d)" % u)
        dbq("DELETE FROM `mailing_log` WHERE user_id=%d" % u)
        dbq("DELETE FROM `mailing_optout` WHERE user_id=%d" % u)
    dbq("DELETE FROM `mailings` WHERE subject LIKE 'Тест-рассылка%'")


def setup_module(module):
    # два кандидата со своей анкетой: подтверждённый и неподтверждённый email
    row = dbq("SELECT u.User_ID, m.Message_ID FROM `User` u "
              "JOIN `Message204` m ON m.User_ID=u.User_ID "
              "WHERE u.Type=1 AND u.Confirmed=1 AND u.Email<>'' LIMIT 1").split("\t")
    STATE["confirmed_uid"], STATE["confirmed_msg"] = int(row[0]), int(row[1])
    row = dbq("SELECT u.User_ID, m.Message_ID FROM `User` u "
              "JOIN `Message204` m ON m.User_ID=u.User_ID "
              "WHERE u.Type=1 AND u.Confirmed=0 AND u.Email<>'' "
              "AND u.User_ID<>%d LIMIT 1" % STATE["confirmed_uid"]).split("\t")
    STATE["unconfirmed_uid"], STATE["unconfirmed_msg"] = int(row[0]), int(row[1])
    for key in ("confirmed", "unconfirmed"):
        mid = STATE[key + "_msg"]
        STATE[key + "_old_city"] = dbq(
            "SELECT IFNULL(City,'') FROM `Message204` WHERE Message_ID=%d" % mid)
        dbq("UPDATE `Message204` SET City='%s' WHERE Message_ID=%d" % (CITY_MARKER, mid))
    reset_mailing()


def teardown_module(module):
    reset_mailing()
    for key in ("confirmed", "unconfirmed"):
        dbq("UPDATE `Message204` SET City='%s' WHERE Message_ID=%d"
            % (STATE[key + "_old_city"].replace("'", "''"), STATE[key + "_msg"]))


# --- доступ -----------------------------------------------------------------

def test_preview_requires_auth():
    code, data = preview(None, city=CITY_MARKER)
    assert code == 403, "гостя пустили в предпросмотр: %s" % data


def test_preview_forbidden_for_shadhan():
    sid = login(SHADHAN)
    code, data = preview(sid, city=CITY_MARKER)
    assert code == 403, "не-админа пустили в предпросмотр: %s" % data


def test_send_forbidden_for_shadhan_no_side_effects():
    reset_mailing()
    before = dbq("SELECT COUNT(*) FROM `Mail_Queue`")
    sid = login(SHADHAN)
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка", body="привет")
    assert code == 403, "не-админ разослал письма: %s" % data
    assert dbq("SELECT COUNT(*) FROM `Mail_Queue`") == before, "очередь выросла"


def test_send_foreign_origin_forbidden():
    reset_mailing()
    before = dbq("SELECT COUNT(*) FROM `Mail_Queue`")
    sid = login(ADMIN)
    code, data = send(sid, origin="https://evil.example.com",
                      city=CITY_MARKER, subject="Тест-рассылка", body="привет")
    assert code == 403, "запрос с чужого Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `Mail_Queue`") == before, "очередь выросла"


# --- предпросмотр (Р-1, Р-4) ------------------------------------------------

def test_preview_city_segment():
    reset_mailing()
    sid = login(ADMIN)
    code, data = preview(sid, city=CITY_MARKER)
    assert code == 200 and data.get("ok") is True, "предпросмотр упал: %s" % data
    assert data["total"] == 2, "сегмент по городу: %s" % data
    assert data["confirmed"] == 1 and data["unconfirmed"] == 1, \
        "разбивка подтверждённых (Р-4): %s" % data


def test_preview_no_filters_matches_sql():
    sid = login(ADMIN)
    code, data = preview(sid)
    expected = int(dbq("SELECT COUNT(*) FROM `User` u WHERE " + BASE_WHERE))
    assert code == 200 and data["total"] == expected, \
        "total без фильтров: %s ≠ %d" % (data, expected)


def test_preview_gender_filter_matches_sql():
    sid = login(ADMIN)
    code, data = preview(sid, gender=1)
    expected = int(dbq("SELECT COUNT(*) FROM `User` u WHERE %s AND u.Gender=1" % BASE_WHERE))
    assert code == 200 and data["total"] == expected, \
        "фильтр по полу: %s ≠ %d" % (data, expected)


def test_preview_age_filter_matches_sql():
    sid = login(ADMIN)
    code, data = preview(sid, age_min=30, age_max=40)
    expected = int(dbq(
        "SELECT COUNT(*) FROM `User` u WHERE %s "
        "AND TIMESTAMPDIFF(YEAR, u.Date_of_birth, CURDATE()) BETWEEN 30 AND 40"
        % BASE_WHERE))
    assert code == 200 and data["total"] == expected, \
        "фильтр по возрасту: %s ≠ %d" % (data, expected)


# --- отправка (Р-2, Р-3) ----------------------------------------------------

def test_send_queues_only_confirmed():
    reset_mailing()
    sid = login(ADMIN)
    code, data = send(sid, city=CITY_MARKER,
                      subject="Тест-рассылка 31415", body="Здравствуйте! Это тест.")
    assert code == 200 and data.get("ok") is True, "рассылка упала: %s" % data
    assert data["queued"] == 1, "в очередь должен уйти только подтверждённый: %s" % data
    email = dbq("SELECT Email FROM `User` WHERE User_ID=%d" % STATE["confirmed_uid"])
    assert dbq("SELECT COUNT(*) FROM `Mail_Queue` WHERE recipient='%s'" % email) == "1"
    # лог (Р-3): кому, когда, статус
    log = dbq("SELECT user_id, status FROM `mailing_log` WHERE mailing_id=%d"
              % data["mailing_id"])
    assert log == "%d\tqueued" % STATE["confirmed_uid"], "лог рассылки: " + log
    # шапка рассылки сохранена
    assert dbq("SELECT COUNT(*) FROM `mailings` WHERE id=%d AND subject='Тест-рассылка 31415'"
               % data["mailing_id"]) == "1"


def test_send_letter_contains_unsubscribe_link():
    reset_mailing()
    sid = login(ADMIN)
    code, data = send(sid, city=CITY_MARKER,
                      subject="Тест-рассылка отписка", body="Текст письма.")
    assert code == 200, "рассылка упала: %s" % data
    email = dbq("SELECT Email FROM `User` WHERE User_ID=%d" % STATE["confirmed_uid"])
    token = dbq("SELECT token FROM `mailing_optout` WHERE user_id=%d"
                % STATE["confirmed_uid"])
    assert re.fullmatch(r"[0-9a-f]{64}", token), "нет персонального токена отписки"
    body = queue_body(email)
    assert "/api/mailing_unsubscribe.php?t=" + token in body, \
        "в письме нет ссылки отписки с токеном"
    assert "Текст письма." in body, "в письме нет текста рассылки"


def test_send_validation():
    reset_mailing()
    sid = login(ADMIN)
    code, data = send(sid, city=CITY_MARKER, subject="", body="текст")
    assert code == 400, "пустая тема принята: %s" % data
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка", body="")
    assert code == 400, "пустое тело принято: %s" % data
    assert dbq("SELECT COUNT(*) FROM `mailings` WHERE subject LIKE 'Тест-рассылка%'") == "0"


def test_admin_check_ignores_zero_group():
    # личные права админов хранятся с PermissionGroup_ID=0 — осиротевшая
    # строка User_Group с группой 0 не должна дарить эти права не-админу
    dbq("INSERT INTO `User_Group` SET User_ID=57, PermissionGroup_ID=0")
    try:
        sid = login(SHADHAN)
        code, data = preview(sid, city=CITY_MARKER)
        assert code == 403, "нулевая группа дала шадхану права админа: %s" % data
    finally:
        dbq("DELETE FROM `User_Group` WHERE User_ID=57 AND PermissionGroup_ID=0")


def test_send_skips_recipient_with_broken_token():
    # если персональный токен отписки не создался — письмо НЕ уходит
    # (мёртвая ссылка отписки хуже пропущенного письма), в логе status=error
    reset_mailing()
    dbq("INSERT INTO `mailing_optout` SET user_id=%d, token='', opted_out=0, "
        "created_at=NOW()" % STATE["confirmed_uid"])
    sid = login(ADMIN)
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка токен", body="текст")
    assert code == 200, "рассылка упала: %s" % data
    assert data["queued"] == 0, "письмо с пустым токеном отписки ушло: %s" % data
    email = dbq("SELECT Email FROM `User` WHERE User_ID=%d" % STATE["confirmed_uid"])
    assert dbq("SELECT COUNT(*) FROM `Mail_Queue` WHERE recipient='%s'" % email) == "0"
    assert dbq("SELECT status FROM `mailing_log` WHERE user_id=%d"
               % STATE["confirmed_uid"]) == "error", "в логе нет status=error"


def test_duplicate_send_rejected():
    # повторный запуск той же рассылки (даблклик, ретрай после обрыва)
    # отклоняется, письма не дублируются
    reset_mailing()
    sid = login(ADMIN)
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка дубль", body="раз")
    assert code == 200 and data["queued"] == 1, "первая рассылка упала: %s" % data
    email = dbq("SELECT Email FROM `User` WHERE User_ID=%d" % STATE["confirmed_uid"])
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка дубль", body="раз")
    assert code == 409, "дубль рассылки не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `Mail_Queue` WHERE recipient='%s'" % email) == "1", \
        "письмо продублировалось"


# --- отписка (Р-3) ----------------------------------------------------------

def test_unsubscribe_excludes_from_next_mailing():
    reset_mailing()
    sid = login(ADMIN)
    send(sid, city=CITY_MARKER, subject="Тест-рассылка 1", body="раз")
    token = dbq("SELECT token FROM `mailing_optout` WHERE user_id=%d"
                % STATE["confirmed_uid"])
    code, html = http_get("/api/mailing_unsubscribe.php?t=" + token)
    assert code == 200 and "отписан" in html.lower(), "страница отписки: %d" % code
    assert dbq("SELECT opted_out FROM `mailing_optout` WHERE user_id=%d"
               % STATE["confirmed_uid"]) == "1"
    # предпросмотр больше не видит отписавшегося
    code, data = preview(sid, city=CITY_MARKER)
    assert data["total"] == 1, "отписавшийся остался в сегменте: %s" % data
    # повторная рассылка его не трогает
    code, data = send(sid, city=CITY_MARKER, subject="Тест-рассылка 2", body="два")
    assert code == 200 and data["queued"] == 0, \
        "отписавшемуся снова отправили: %s" % data
    # отписка идемпотентна
    code, _ = http_get("/api/mailing_unsubscribe.php?t=" + token)
    assert code == 200


def test_unsubscribe_bad_token():
    code, _ = http_get("/api/mailing_unsubscribe.php?t=" + "0" * 64)
    assert code == 404
    code, _ = http_get("/api/mailing_unsubscribe.php?t=abc")
    assert code == 404


# --- админ-экран (Р-1) -------------------------------------------------------

def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def test_admin_page_forbidden_for_non_admin():
    code, _ = get_page("/admin-mailing.php", None)
    assert code == 403, "экран рассылки открылся гостю"
    code, _ = get_page("/admin-mailing.php", login(SHADHAN))
    assert code == 403, "экран рассылки открылся шадхану"


def test_admin_page_renders_form():
    code, html = get_page("/admin-mailing.php", login(ADMIN))
    assert code == 200, "экран рассылки не открылся админу: %d" % code
    assert "Fatal error" not in html and "Parse error" not in html
    for marker in ("mailing_preview", "mailing_send", "Тема", "Текст письма",
                   "Отписаться от рассылки"):
        assert marker in html, "на экране нет: " + marker
