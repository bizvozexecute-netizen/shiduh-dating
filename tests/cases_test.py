# -*- coding: utf-8 -*-
"""
Тесты кейсов ценностного опроса (ROADMAP Э2.2-a): хранилище + админка.

  GET  /admin-cases.php          — экран редактирования (только администратор)
  POST /api/cases_save.php       — action=create|update|delete|toggle

Кейсы задаёт ИИ в диалоге (Э2.2-c), ответы классифицирует LLM (Э2.3).
Здесь проверяем только хранилище и редактирование: доступ, CRUD, валидацию,
CSRF-заслон и то, что выключенный кейс не попадает в активную выборку.

    python -m pytest tests/cases_test.py -q
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
ADMIN = "u5760fc1c6357@example.local"       # User_ID 1, DIRECTOR
SHADHAN = "u3f9bb8c20b01@example.local"     # User_ID 57, Type=2, НЕ админ
SAVE_URL = "/api/cases_save.php"
PAGE_URL = "/admin-cases.php"
MARK = "ТЕСТКЕЙС"


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


def api(sid, fields, origin=None):
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + SAVE_URL, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if origin:
        req.add_header("Origin", origin)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        raw, code = r.read().decode("utf-8", "replace"), r.status
    except urllib.error.HTTPError as e:
        raw, code = e.read().decode("utf-8", "replace"), e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, {"_raw": raw}


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


def cleanup():
    dbq("DELETE FROM `assessment_cases` WHERE `scenario_text` LIKE '%s%%'" % MARK)


def teardown_module(module):
    cleanup()


def create_case(sid, text=None, group="family_value", dim="budget", origin=None):
    return api(sid, {
        "action": "create", "dimension_group": group, "dimension": dim,
        "scenario_text": text if text is not None else (MARK + " базовый вопрос"),
    }, origin=origin)


# --- стартовый набор (миграция 010) -----------------------------------------

def test_seed_present():
    n = int(dbq("SELECT COUNT(*) FROM `assessment_cases`") or 0)
    assert n >= 12, "стартовых кейсов меньше ожидаемого: %d" % n
    love = int(dbq("SELECT COUNT(*) FROM `assessment_cases` "
                   "WHERE dimension_group='love_language'") or 0)
    fam = int(dbq("SELECT COUNT(*) FROM `assessment_cases` "
                  "WHERE dimension_group='family_value'") or 0)
    assert love >= 5, "языков любви должно быть 5: %d" % love
    assert fam >= 7, "ценностных измерений должно быть 7: %d" % fam


# --- доступ ------------------------------------------------------------------

def test_page_forbidden_for_guest_and_shadhan():
    code, _ = get_page(PAGE_URL, None)
    assert code == 403, "экран кейсов открылся гостю"
    code, _ = get_page(PAGE_URL, login(SHADHAN))
    assert code == 403, "экран кейсов открылся шадхану"


def test_api_forbidden_for_shadhan_no_side_effects():
    cleanup()
    before = dbq("SELECT COUNT(*) FROM `assessment_cases`")
    code, data = create_case(login(SHADHAN))
    assert code == 403, "не-админ создал кейс: %s" % data
    assert dbq("SELECT COUNT(*) FROM `assessment_cases`") == before


def test_api_forbidden_for_guest():
    cleanup()
    before = dbq("SELECT COUNT(*) FROM `assessment_cases`")
    code, data = create_case(None)
    assert code == 403, "гость создал кейс: %s" % data
    assert dbq("SELECT COUNT(*) FROM `assessment_cases`") == before


def test_all_mutations_forbidden_for_shadhan():
    # гейт один на все действия — проверяем каждое, не только create
    cleanup()
    _, data = create_case(login(ADMIN), MARK + " чужой кейс")
    cid = data["id"]
    sid = login(SHADHAN)
    for action in ("update", "toggle", "delete"):
        code, res = api(sid, {"action": action, "id": cid,
                              "scenario_text": MARK + " взлом"})
        assert code == 403, "шадхан выполнил %s: %s" % (action, res)
    row = dbq("SELECT active, scenario_text FROM `assessment_cases` WHERE id=%d" % cid)
    assert row.startswith("1\t") and "взлом" not in row, "кейс изменён не-админом: " + row


def test_api_foreign_origin_forbidden():
    cleanup()
    before = dbq("SELECT COUNT(*) FROM `assessment_cases`")
    code, data = create_case(login(ADMIN), origin="https://evil.example.com")
    assert code == 403, "запрос с чужого Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `assessment_cases`") == before


def test_api_foreign_referer_forbidden():
    # Referer — отдельная ветка заслона (когда браузер не прислал Origin)
    cleanup()
    before = dbq("SELECT COUNT(*) FROM `assessment_cases`")
    body = urllib.parse.urlencode({
        "action": "create", "dimension_group": "family_value",
        "dimension": "budget", "scenario_text": MARK + " через referer",
    }).encode()
    req = urllib.request.Request(BASE + SAVE_URL, data=body, method="POST")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % login(ADMIN))
    req.add_header("Referer", "https://evil.example.com/page")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        code = opener.open(req, timeout=60).status
    except urllib.error.HTTPError as e:
        code = e.code
    assert code == 403, "запрос с чужого Referer не отклонён: %d" % code
    assert dbq("SELECT COUNT(*) FROM `assessment_cases`") == before


def test_get_not_allowed():
    code, _ = get_page(SAVE_URL, login(ADMIN))
    assert code == 405, "GET на эндпоинт правок не отклонён: %d" % code


# --- экран -------------------------------------------------------------------

def test_admin_page_renders():
    code, html = get_page(PAGE_URL, login(ADMIN))
    assert code == 200, "экран не открылся админу: %d" % code
    assert "Fatal error" not in html and "Parse error" not in html
    assert "cases_save" in html, "на экране нет обращения к эндпоинту"
    # видны существующие кейсы и группы
    assert "Языки любви" in html and "Ценност" in html


def test_admin_page_escapes_case_text():
    cleanup()
    sid = login(ADMIN)
    create_case(sid, MARK + " <script>alert(1)</script> экран")
    _, html = get_page(PAGE_URL, sid)
    assert "<script>alert(1)</script>" not in html, "XSS: текст кейса не экранирован"
    assert "&lt;script&gt;" in html


# --- CRUD --------------------------------------------------------------------

def test_create_case():
    cleanup()
    sid = login(ADMIN)
    code, data = create_case(sid, MARK + " новый кейс 71828")
    assert code == 200 and data.get("ok") is True, "кейс не создан: %s" % data
    cid = data["id"]
    row = dbq("SELECT dimension_group, dimension, active FROM `assessment_cases` "
              "WHERE id=%d" % cid)
    assert row == "family_value\tbudget\t1", "неверно сохранён кейс: " + row


def test_update_case():
    cleanup()
    sid = login(ADMIN)
    _, data = create_case(sid, MARK + " до правки")
    cid = data["id"]
    code, res = api(sid, {"action": "update", "id": cid,
                          "scenario_text": MARK + " после правки 31415"})
    assert code == 200 and res.get("ok") is True, "правка не прошла: %s" % res
    text = dbq("SELECT scenario_text FROM `assessment_cases` WHERE id=%d" % cid)
    assert "после правки 31415" in text
    assert dbq("SELECT updated_at IS NOT NULL FROM `assessment_cases` WHERE id=%d" % cid) == "1"


def test_toggle_active_excludes_from_survey():
    cleanup()
    sid = login(ADMIN)
    _, data = create_case(sid, MARK + " для выключения")
    cid = data["id"]
    code, res = api(sid, {"action": "toggle", "id": cid})
    assert code == 200 and res.get("active") == 0, "кейс не выключился: %s" % res
    assert dbq("SELECT active FROM `assessment_cases` WHERE id=%d" % cid) == "0"
    # выключенный не попадает в активную выборку опроса
    n = dbq("SELECT COUNT(*) FROM `assessment_cases` WHERE active=1 AND id=%d" % cid)
    assert n == "0"
    # обратное включение
    _, res2 = api(sid, {"action": "toggle", "id": cid})
    assert res2.get("active") == 1


def test_delete_case():
    cleanup()
    sid = login(ADMIN)
    _, data = create_case(sid, MARK + " на удаление")
    cid = data["id"]
    code, res = api(sid, {"action": "delete", "id": cid})
    assert code == 200 and res.get("ok") is True, "кейс не удалён: %s" % res
    assert dbq("SELECT COUNT(*) FROM `assessment_cases` WHERE id=%d" % cid) == "0"


# --- валидация ---------------------------------------------------------------

def test_validation():
    cleanup()
    sid = login(ADMIN)
    code, _ = create_case(sid, "   ")
    assert code == 400, "пустой текст кейса принят"
    code, _ = api(sid, {"action": "create", "dimension_group": "wrong_group",
                        "dimension": "budget", "scenario_text": MARK + " x"})
    assert code == 400, "неизвестная группа измерений принята"
    code, _ = api(sid, {"action": "create", "dimension_group": "family_value",
                        "dimension": "", "scenario_text": MARK + " x"})
    assert code == 400, "пустое измерение принято"
    code, _ = api(sid, {"action": "update", "id": 999999,
                        "scenario_text": MARK + " нет такого"})
    assert code == 404, "правка несуществующего кейса не отклонена"
    code, _ = api(sid, {"action": "bogus", "id": 1})
    assert code == 400, "неизвестное действие принято"
    code, _ = create_case(sid, MARK + " " + ("я" * 2100))
    assert code == 400, "текст длиннее 2000 символов принят"
    assert dbq("SELECT COUNT(*) FROM `assessment_cases` WHERE scenario_text LIKE '%s%%'"
               % MARK) == "0"


def test_sql_injection_in_text_is_stored_literally():
    cleanup()
    sid = login(ADMIN)
    payload = MARK + " '; DROP TABLE assessment_cases; -- "
    code, data = create_case(sid, payload)
    assert code == 200, "кейс с кавычками не сохранился: %s" % data
    assert dbq("SHOW TABLES LIKE 'assessment_cases'") == "assessment_cases", \
        "таблица пропала — инъекция сработала"
    text = dbq("SELECT scenario_text FROM `assessment_cases` WHERE id=%d" % data["id"])
    assert "DROP TABLE" in text, "текст исказился при сохранении: " + text
