# -*- coding: utf-8 -*-
"""
Тесты «Пригласить кандидата по ссылке», создание приглашения (XI-2).

Эндпоинт api/invite_create.php: только шадхан, поверх уже заведённой анкеты
создаёт кандидату аккаунт-заготовку, передаёт ей владение анкетой, выпускает
токен. Мутационные тесты — состояние сбрасывается перед каждым.

    python -m pytest tests/invite_test.py -q
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
SHADHAN = "u3f9bb8c20b01@example.local"          # User_ID 57 (Type=2)
CANDIDATE = "uad322c9ddbba@example.local"        # User_ID 44 (Type=1)
PLACEHOLDER_LIKE = "invite-%@invite.shiduh.org"
CREATE_URL = BASE + "/api/invite_create.php"

# Заполняется в setup_module
STATE = {"anketa": None, "orig_shadhan1": None, "other_anketa": None}


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


def post_invite(sid, anketa_id, origin=None):
    body = urllib.parse.urlencode({"anketa_id": anketa_id}).encode()
    req = urllib.request.Request(CREATE_URL, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if origin:
        req.add_header("Origin", origin)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        code, raw = r.status, r.read()
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read()
    try:
        data = json.loads(raw.decode("utf-8", "replace"))
    except ValueError:
        data = None
    return code, data


def _delete_placeholders():
    ids = dbq("SELECT User_ID FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE).split()
    for uid in ids:
        uid = int(uid)
        dbq("DELETE FROM `User` WHERE User_ID=%d" % uid)
        dbq("DELETE FROM `User_Group` WHERE User_ID=%d" % uid)
        dbq("DELETE FROM `invite_tokens` WHERE candidate_user_id=%d" % uid)
        dbq("DELETE FROM `Session` WHERE User_ID=%d" % uid)
    dbq("DELETE FROM `invite_tokens` WHERE created_by=57")
    # осиротевшие сессии заготовок из прошлых прогонов (User уже удалён)
    dbq("DELETE s FROM `Session` s LEFT JOIN `User` u ON u.User_ID=s.User_ID "
        "WHERE u.User_ID IS NULL")


def reset_state():
    """Чистый лист: анкета снова принадлежит шадхану 57, заготовки/токены удалены."""
    _delete_placeholders()
    a = STATE["anketa"]
    dbq("UPDATE `Message204` SET `User_ID`=57, `Shadhan_1`=%s WHERE `Message_ID`=%d"
        % (STATE["orig_shadhan1"], a))


def setup_module(module):
    # анкета, которой шадхан 57 владеет сам (не трогаем личность реального кандидата)
    a = dbq("SELECT Message_ID FROM `Message204` WHERE User_ID=57 AND Checked=1 "
            "ORDER BY Message_ID LIMIT 1")
    assert a, "нет анкеты, принадлежащей шадхану 57"
    STATE["anketa"] = int(a)
    STATE["orig_shadhan1"] = dbq("SELECT `Shadhan_1` FROM `Message204` WHERE Message_ID=%d" % int(a)) or "0"
    # чужая анкета: шадхан 57 её не ведёт
    STATE["other_anketa"] = int(dbq(
        "SELECT Message_ID FROM `Message204` WHERE User_ID<>57 AND (Shadhan_1 IS NULL OR Shadhan_1<>57) "
        "AND (Shadhan_2 IS NULL OR Shadhan_2<>57) AND (Shadhan_3 IS NULL OR Shadhan_3<>57) "
        "ORDER BY Message_ID LIMIT 1"))
    # анкета реального кандидата, которую шадхан 57 ведёт (Shadhan_1=57), но НЕ владеет
    STATE["real_anketa"] = int(dbq(
        "SELECT m.Message_ID FROM `Message204` m JOIN `User` u ON u.User_ID=m.User_ID "
        "WHERE m.Shadhan_1=57 AND m.User_ID<>57 AND u.Email NOT LIKE '%s' "
        "ORDER BY m.Message_ID LIMIT 1" % PLACEHOLDER_LIKE))
    reset_state()


def teardown_module(module):
    reset_state()


def test_shadhan_creates_invite():
    reset_state()
    a = STATE["anketa"]
    sid = login(SHADHAN)
    code, data = post_invite(sid, a)
    assert code == 200, "ответ не 200: %s" % data
    assert data and data.get("ok") is True and data.get("reused") is False
    token = data.get("token", "")
    assert re.match(r"^[0-9a-f]{64}$", token), "токен не 64-hex: %r" % token
    assert data.get("link", "").endswith("/api/invite.php?t=" + token)

    cand = int(data["candidate_user_id"])
    # аккаунт-заготовка: кандидат (Type=1), подтверждён, в группе 2, синтетический email
    assert dbq("SELECT Type FROM `User` WHERE User_ID=%d" % cand) == "1"
    assert dbq("SELECT Checked FROM `User` WHERE User_ID=%d" % cand) == "1"
    assert dbq("SELECT COUNT(*) FROM `User_Group` WHERE User_ID=%d AND PermissionGroup_ID=2" % cand) == "1"
    assert dbq("SELECT Email FROM `User` WHERE User_ID=%d" % cand).endswith("@invite.shiduh.org")
    # владение анкетой передано заготовке, ведущий шадхан сохранён
    assert dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % a) == str(cand)
    assert dbq("SELECT Shadhan_1 FROM `Message204` WHERE Message_ID=%d" % a) == "57"
    # токен записан и привязан к заготовке
    assert dbq("SELECT candidate_user_id FROM `invite_tokens` WHERE token='%s'" % token) == str(cand)
    assert dbq("SELECT created_by FROM `invite_tokens` WHERE token='%s'" % token) == "57"


def test_invite_is_idempotent():
    reset_state()
    a = STATE["anketa"]
    sid = login(SHADHAN)
    code1, d1 = post_invite(sid, a)
    assert code1 == 200 and d1.get("reused") is False
    code2, d2 = post_invite(sid, a)
    assert code2 == 200, "повтор не 200: %s" % d2
    assert d2.get("reused") is True, "повтор создал новый аккаунт вместо переиспользования"
    assert d2.get("token") == d1.get("token"), "переиспользован другой токен"
    # ровно одна заготовка на анкету
    cnt = dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE)
    assert cnt == "1", "создано несколько заготовок: " + cnt


def test_non_shadhan_forbidden():
    reset_state()
    sid = login(CANDIDATE)
    code, data = post_invite(sid, STATE["anketa"])
    assert code == 403, "кандидату разрешили приглашать: %s" % data
    assert dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE) == "0"


def test_anonymous_forbidden():
    reset_state()
    code, data = post_invite(None, STATE["anketa"])
    assert code == 403, "аноним получил доступ: %s" % data


def test_uncontrolled_anketa_forbidden():
    reset_state()
    sid = login(SHADHAN)
    code, data = post_invite(sid, STATE["other_anketa"])
    assert code == 403, "шадхан пригласил на чужую анкету: %s" % data
    assert dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE) == "0"


def test_real_candidate_anketa_not_stolen():
    # анкету реального зарегистрированного кандидата нельзя отобрать, даже если
    # шадхан её ведёт (Shadhan_1). Владение не меняется, заготовка не создаётся.
    reset_state()
    a = STATE["real_anketa"]
    orig_owner = dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % a)
    sid = login(SHADHAN)
    code, data = post_invite(sid, a)
    assert code == 409, "анкету реального кандидата отдали заготовке: %s" % data
    assert dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % a) == orig_owner, \
        "владение анкетой реального кандидата изменилось"
    assert dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE) == "0"


def test_foreign_origin_forbidden():
    # межсайтовый POST (чужой Origin) отклоняется до любых побочных эффектов
    reset_state()
    sid = login(SHADHAN)
    code, data = post_invite(sid, STATE["anketa"], origin="https://evil.example.com")
    assert code == 403, "запрос с чужого Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE '%s'" % PLACEHOLDER_LIKE) == "0"
    # свой Origin — проходит
    code2, data2 = post_invite(sid, STATE["anketa"], origin="http://localhost:8080")
    assert code2 == 200, "запрос со своего Origin отклонён: %s" % data2


def test_card_shows_invite_button():
    # в карточке кандидата у шадхана есть кнопка приглашения с id анкеты
    reset_state()
    a = STATE["anketa"]
    sid = login(SHADHAN)
    code, body = get_with_sid("/my/my-candidates/moi-kandidaty_%d.html" % a, sid)
    assert code == 200, "карточка не открылась: %s" % code
    assert "js-invite-create" in body, "нет кнопки приглашения"
    assert ('data-anketa="%d"' % a) in body, "кнопка без id анкеты"
    assert "Fatal error" not in body and "Parse error" not in body


def test_get_method_not_allowed():
    req = urllib.request.Request(CREATE_URL, method="GET")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        code = opener.open(req, timeout=30).status
    except urllib.error.HTTPError as e:
        code = e.code
    assert code == 405


# --- Вход по токену (XI-3): api/invite.php ---

REDEEM_URL = BASE + "/api/invite.php"


def make_invite():
    """Создаёт приглашение и возвращает (token, candidate_user_id)."""
    sid = login(SHADHAN)
    code, data = post_invite(sid, STATE["anketa"])
    assert code == 200 and data.get("ok"), "не удалось создать приглашение: %s" % data
    return data["token"], int(data["candidate_user_id"])


def redeem(token, sid=None):
    """GET /api/invite.php?t=... без следования редиректу. → (code, headers, body)."""
    req = urllib.request.Request(REDEEM_URL + "?t=" + urllib.parse.quote(token))
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.headers, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode("utf-8", "replace")


def sid_from_headers(headers):
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    return None


def get_with_sid(path, sid):
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def test_redeem_logs_in_candidate():
    reset_state()
    token, cand = make_invite()
    code, headers, _ = redeem(token)
    assert code in (301, 302), "нет редиректа после входа: %s" % code
    assert headers.get("Location") == "/my/ai-consultant/", \
        "редирект не на консультанта: %s" % headers.get("Location")
    new_sid = sid_from_headers(headers)
    assert new_sid, "не выдана cookie сессии PHP_AUTH_SID"
    # новая сессия авторизована как кандидат: консультант открывается (200)
    scode, body = get_with_sid("/my/ai-consultant/", new_sid)
    assert scode == 200, "сессия кандидата не авторизована: %s" % scode
    assert ("ai-chat" in body) or ("консультант" in body.lower())
    # used_at проставлен
    assert dbq("SELECT used_at IS NOT NULL FROM invite_tokens WHERE token='%s'" % token) == "1"


def test_redeem_invalid_token():
    reset_state()
    code, headers, _ = redeem("deadbeef" * 8)  # 64 hex, но нет в базе
    assert code == 404
    assert headers.get("Location") is None, "битый токен не должен авторизовывать"


def test_redeem_malformed_token():
    reset_state()
    code, headers, _ = redeem("not-a-real-token")
    assert code == 404
    assert headers.get("Location") is None


def test_redeem_expired_token():
    reset_state()
    token, _ = make_invite()
    dbq("UPDATE invite_tokens SET expires_at=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE token='%s'" % token)
    code, headers, _ = redeem(token)
    assert code == 404, "истёкший токен не отклонён: %s" % code
    assert headers.get("Location") is None


def test_redeem_claimed_token():
    reset_state()
    token, _ = make_invite()
    dbq("UPDATE invite_tokens SET claimed_at=NOW() WHERE token='%s'" % token)
    code, headers, _ = redeem(token)
    assert code == 410, "присвоенный токен не отклонён: %s" % code
    assert headers.get("Location") is None
