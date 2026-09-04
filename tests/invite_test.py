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
import sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import browser_headers  # Origin и X-CSRF-Token, как их шлёт браузер

BASE = "http://localhost:8080"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
SHADHAN = "u3f9bb8c20b01@example.local"          # User_ID 57 (Type=2)
CANDIDATE = "uad322c9ddbba@example.local"        # User_ID 44 (Type=1)
PLACEHOLDER_LIKE = "invite-%@invite.shiduh.org"
CLAIM_LIKE = "%@claimtest.local"          # присвоенные заготовки в тестах
CREATE_URL = BASE + "/api/invite_create.php"
CLAIM_URL = BASE + "/api/invite_claim.php"
SEND_URL = BASE + "/api/invite_send_email.php"

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
    browser_headers(req, sid, origin)
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
    ids = dbq("SELECT User_ID FROM `User` WHERE Email LIKE '%s' OR Email LIKE '%s'"
              % (PLACEHOLDER_LIKE, CLAIM_LIKE)).split()
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
    # тестовые письма из очереди
    dbq("DELETE FROM `Mail_Queue` WHERE recipient LIKE '%s'" % CLAIM_LIKE)


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
    """GET /api/invite.php?t=... без следования редиректу. → (code, headers, body).
    С C4 GET только показывает межстраницу: cookie не ставит, used_at не пишет."""
    req = urllib.request.Request(REDEEM_URL + "?t=" + urllib.parse.quote(token))
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.headers, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode("utf-8", "replace")


def redeem_post(token, origin=None, referer=None, sid=None):
    """POST с кнопки межстраницы: вход и редирект.
    origin=None — свой Origin, False — без Origin, строка — как есть (в т.ч. "null");
    referer — заголовок Referer как есть; sid — уже существующая сессия в браузере."""
    body = urllib.parse.urlencode({"t": token}).encode()
    req = urllib.request.Request(REDEEM_URL, data=body, method="POST")
    if origin is None:
        req.add_header("Origin", BASE)
    elif origin:
        req.add_header("Origin", origin)
    if referer:
        req.add_header("Referer", referer)
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


def test_redeem_page_does_not_log_in():
    """GET — только межстраница: превью ссылки в мессенджере или сканер
    антивируса не должны входить в аккаунт и помечать приглашение."""
    reset_state()
    token, cand = make_invite()
    code, headers, body = redeem(token)
    assert code == 200, "GET по ссылке должен показывать страницу: %s" % code
    assert headers.get("Location") is None, "GET редиректит — значит логинит"
    assert sid_from_headers(headers) is None, "GET поставил cookie сессии"
    assert 'name="t" value="%s"' % token in body, "форма несёт не тот токен"
    assert "Войти как приглашённый кандидат" in body, "нет кнопки входа"
    assert "<script" not in body, "на межстранице не должно быть скриптов"
    assert dbq("SELECT used_at IS NULL FROM invite_tokens WHERE token='%s'" % token) == "1", \
        "GET пометил приглашение использованным"
    # токен живёт в адресе: он не должен уходить в Referer и оседать в кэше
    # same-origin: чужим сайтам адрес не уходит, а собственному POST Referer нужен
    assert headers.get("Referrer-Policy") == "same-origin", headers.get("Referrer-Policy")
    assert "no-store" in headers.get("Cache-Control", ""), headers.get("Cache-Control")
    assert "noindex" in body


def test_redeem_logs_in_candidate():
    reset_state()
    token, cand = make_invite()
    code, headers, _ = redeem_post(token)
    assert code == 303, "нет редиректа после входа: %s" % code
    assert headers.get("Location") == "/my/ai-consultant/", \
        "редирект не на консультанта: %s" % headers.get("Location")
    new_sid = sid_from_headers(headers)
    assert new_sid, "нет cookie сессии после входа"
    # сессия действительно принадлежит заготовке
    code, html = get_with_sid("/my/ai-consultant/", new_sid)
    assert code == 200, "консультант не открылся по новой сессии: %d" % code
    # used_at проставлен
    assert dbq("SELECT used_at IS NOT NULL FROM invite_tokens WHERE token='%s'" % token) == "1"


def test_redeem_post_requires_own_origin():
    """Кнопку не нажмёт чужая страница: без Origin/Referer или с чужим — отказ."""
    reset_state()
    token, _ = make_invite()
    for origin in (False, "https://evil.example"):
        code, headers, _ = redeem_post(token, origin=origin)
        assert code == 403, "вход с источником %r: %s" % (origin, code)
        assert sid_from_headers(headers) is None, "cookie поставлена при отказе"
    assert dbq("SELECT used_at IS NULL FROM invite_tokens WHERE token='%s'" % token) == "1"


def test_redeem_post_claimed_token():
    reset_state()
    token, _ = make_invite()
    dbq("UPDATE invite_tokens SET claimed_at=NOW() WHERE token='%s'" % token)
    code, headers, _ = redeem_post(token)
    assert code == 410 and sid_from_headers(headers) is None


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


# --- Присвоение аккаунта (XI-4): api/invite_claim.php ---

def login_with(email, password):
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0", "AUTH_USER": email, "AUTH_PW": password,
    }).encode()
    req = urllib.request.Request(BASE + "/netcat/modules/auth/", data=body, method="POST")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        headers = opener.open(req, timeout=60).headers
    except urllib.error.HTTPError as e:
        headers = e.headers
    return sid_from_headers(headers)


def post_claim(sid, email, password, origin=None):
    body = urllib.parse.urlencode({"email": email, "password": password}).encode()
    req = urllib.request.Request(CLAIM_URL, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    browser_headers(req, sid, origin)
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


def redeem_to_sid():
    """Создать приглашение, войти по ссылке, вернуть (sid заготовки, candidate_uid, token)."""
    token, cand = make_invite()
    _, headers, _ = redeem_post(token)
    return sid_from_headers(headers), cand, token


def test_claim_converts_placeholder():
    reset_state()
    sid, cand, token = redeem_to_sid()
    assert sid, "не получена сессия заготовки"
    email = "cand71828@claimtest.local"
    code, data = post_claim(sid, email, "secret123")
    assert code == 200 and data.get("ok") is True, "присвоение не удалось: %s" % data
    assert dbq("SELECT Email FROM `User` WHERE User_ID=%d" % cand) == email
    assert dbq("SELECT claimed_at IS NOT NULL FROM invite_tokens WHERE token='%s'" % token) == "1"
    # можно войти по новым email+паролю
    assert login_with(email, "secret123"), "новый логин/пароль не работают"


def test_claim_disables_token():
    reset_state()
    sid, _, token = redeem_to_sid()
    code, data = post_claim(sid, "cand31415@claimtest.local", "secret123")
    assert code == 200, "присвоение не удалось: %s" % data
    # ссылка после присвоения больше не пускает
    rcode, rheaders, _ = redeem(token)
    assert rcode == 410 and rheaders.get("Location") is None


def test_claim_requires_placeholder():
    reset_state()
    sid = login(CANDIDATE)  # обычный кандидат, не заготовка
    code, data = post_claim(sid, "x27182@claimtest.local", "secret123")
    assert code == 409, "обычному аккаунту разрешили присвоение: %s" % data


def test_claim_rejects_taken_email():
    reset_state()
    sid, _, _ = redeem_to_sid()
    code, data = post_claim(sid, SHADHAN, "secret123")  # email уже занят
    assert code == 409, "занятый email не отклонён: %s" % data


def test_claim_rejects_bad_email():
    reset_state()
    sid, _, _ = redeem_to_sid()
    code, data = post_claim(sid, "not-an-email", "secret123")
    assert code == 400, "некорректный email не отклонён: %s" % data


def test_claim_rejects_short_password():
    reset_state()
    sid, _, _ = redeem_to_sid()
    code, data = post_claim(sid, "cand99999@claimtest.local", "123")
    assert code == 400, "короткий пароль не отклонён: %s" % data


def test_claim_foreign_origin_forbidden():
    reset_state()
    sid, cand, _ = redeem_to_sid()
    code, data = post_claim(sid, "evil@claimtest.local", "secret123", origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data
    assert dbq("SELECT Email FROM `User` WHERE User_ID=%d" % cand).endswith("@invite.shiduh.org"), \
        "email заготовки изменился при CSRF"


def post_send(sid, anketa_id, email, origin=None):
    body = urllib.parse.urlencode({"anketa_id": anketa_id, "email": email}).encode()
    req = urllib.request.Request(SEND_URL, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    browser_headers(req, sid, origin)
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


def test_send_email_queues_message():
    reset_state()
    make_invite()  # анкета переходит заготовке, появляется активный токен
    a = STATE["anketa"]
    rcpt = "invite-mail@claimtest.local"
    sid = login(SHADHAN)
    code, data = post_send(sid, a, rcpt)
    assert code == 200 and data.get("ok") is True, "письмо не поставлено в очередь: %s" % data
    n = dbq("SELECT COUNT(*) FROM `Mail_Queue` WHERE recipient='%s'" % rcpt)
    assert n == "1", "нет письма в Mail_Queue: " + n
    body = dbq("SELECT body FROM `Mail_Queue` WHERE recipient='%s' ORDER BY id DESC LIMIT 1" % rcpt)
    assert "/api/invite.php?t=" in body, "в письме нет ссылки-приглашения"


def test_send_email_requires_existing_link():
    reset_state()  # владелец — шадхан, активного токена нет
    sid = login(SHADHAN)
    code, data = post_send(sid, STATE["anketa"], "invite-mail@claimtest.local")
    assert code == 409, "отправка без созданной ссылки не отклонена: %s" % data


def test_send_email_bad_recipient():
    reset_state()
    make_invite()
    sid = login(SHADHAN)
    code, data = post_send(sid, STATE["anketa"], "not-an-email")
    assert code == 400, "некорректный email получателя не отклонён: %s" % data


def test_send_email_non_shadhan_forbidden():
    reset_state()
    sid = login(CANDIDATE)
    code, data = post_send(sid, STATE["anketa"], "invite-mail@claimtest.local")
    assert code == 403, "кандидату разрешили отправку: %s" % data


def test_send_email_foreign_origin_forbidden():
    reset_state()
    sid = login(SHADHAN)
    code, data = post_send(sid, STATE["anketa"], "invite-mail@claimtest.local",
                           origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data


def test_consultant_shows_claim_form_only_for_placeholder():
    reset_state()
    # заготовка видит форму закрепления
    sid, _, _ = redeem_to_sid()
    code, body = get_with_sid("/my/ai-consultant/", sid)
    assert code == 200
    assert "aiClaimForm" in body, "заготовка не видит форму закрепления"
    # обычный кандидат — не видит
    csid = login(CANDIDATE)
    ccode, cbody = get_with_sid("/my/ai-consultant/", csid)
    assert ccode == 200
    assert "aiClaimForm" not in cbody, "обычный кандидат видит форму закрепления"


def test_redeem_post_origin_null_rejected():
    """«Origin: null» — это sandboxed-iframe, кросс-origin 307 или no-referrer:
    не наш пользователь. Именно так браузер слал бы запрос при
    Referrer-Policy: no-referrer — поэтому на межстранице same-origin."""
    reset_state()
    token, _ = make_invite()
    code, headers, body = redeem_post(token, origin="null")
    assert code == 403, code
    assert sid_from_headers(headers) is None
    assert "Не удалось подтвердить источник" in body, "отказ не человеческой страницей"


def test_redeem_post_referer_fallback():
    """Старый браузер без Origin: свой Referer пускает, чужой — нет."""
    reset_state()
    token, _ = make_invite()
    code, headers, _ = redeem_post(token, origin=False, referer="https://evil.example/")
    assert code == 403 and sid_from_headers(headers) is None
    code, headers, _ = redeem_post(token, origin=False, referer=BASE + "/api/invite.php?t=" + token)
    assert code == 303, "свой Referer без Origin не пустил: %s" % code
    assert sid_from_headers(headers)


def test_redeem_post_bad_token_changes_nothing():
    reset_state()
    token, _ = make_invite()
    for bad in ("not-a-token", "deadbeef" * 8):
        code, headers, _ = redeem_post(bad)
        assert code == 404, "%s → %s" % (bad, code)
        assert sid_from_headers(headers) is None
    assert dbq("SELECT used_at IS NULL FROM invite_tokens WHERE token='%s'" % token) == "1"


def test_link_still_opens_after_login_until_claim():
    """Ссылка многоразовая до закрепления: повторный GET после входа —
    снова страница с кнопкой, а не отказ."""
    reset_state()
    token, _ = make_invite()
    redeem_post(token)
    code, headers, body = redeem(token)
    assert code == 200 and 'name="t"' in body


def test_error_pages_carry_same_headers():
    reset_state()
    token, _ = make_invite()
    dbq("UPDATE invite_tokens SET claimed_at=NOW() WHERE token='%s'" % token)
    for tok, expected in (("deadbeef" * 8, 404), (token, 410)):
        code, headers, body = redeem(tok)
        assert code == expected
        assert "no-store" in headers.get("Cache-Control", ""), tok
        assert headers.get("X-Frame-Options") == "DENY", tok
        assert "script-src 'none'" in headers.get("Content-Security-Policy", ""), tok
        assert tok not in body, "токен напечатан на странице ошибки"


def test_get_warns_about_foreign_session():
    """Шадхан открыл ссылку кандидата «проверить»: нажатие кнопки выйдет из
    его аккаунта — страница обязана сказать об этом заранее."""
    reset_state()
    token, _ = make_invite()
    code, headers, body = redeem(token, sid=login(SHADHAN))
    assert code == 200
    assert "нажатие кнопки выйдет из этого аккаунта" in body, "нет предупреждения о чужой сессии"
    assert sid_from_headers(headers) is None, "GET с чужой сессией поставил cookie"
    # без чужой сессии предупреждения нет
    code, headers, body = redeem(token)
    assert "выйдет из этого аккаунта" not in body


def test_invite_email_mentions_button():
    src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "shiduh.org", "api",
                            "invite_send_email.php"), encoding="utf-8").read()
    assert "Войти как приглашённый кандидат" in src, "письмо не говорит про кнопку на межстранице"
