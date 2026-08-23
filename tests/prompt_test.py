# -*- coding: utf-8 -*-
"""
Тесты системного промпта из админки (ROADMAP Э1.2).

  GET  /admin-ai-prompt.php   — экран редактирования (только администратор)
  POST /api/prompt_save.php   — action=save|restore

Каждое сохранение — НОВАЯ версия (история только дописывается, ничего не
затирается). Активен промпт с наибольшей версией. Откат к прошлой версии —
тоже новая версия с её содержимым, поэтому журнал остаётся полным.
Пока промпт не задан, работает встроенный текст по умолчанию.

    python -m pytest tests/prompt_test.py -q
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
SHADHAN = "u3f9bb8c20b01@example.local"     # User_ID 57, не админ
SAVE_URL = "/api/prompt_save.php"
PAGE_URL = "/admin-ai-prompt.php"
KEY = "consultant_system"


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
    # список пар — чтобы можно было отправить content[]=a&content[]=b (массив в PHP)
    body = urllib.parse.urlencode(fields, doseq=False).encode()
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


def clear_prompts():
    dbq("DELETE FROM `ai_prompts` WHERE `key`='%s'" % KEY)


def teardown_module(module):
    clear_prompts()


def save(sid, text, origin=None):
    return api(sid, {"action": "save", "content": text}, origin=origin)


def versions():
    out = dbq("SELECT version FROM `ai_prompts` WHERE `key`='%s' ORDER BY version" % KEY)
    return [int(v) for v in out.split() if v]


def active_content():
    return dbq("SELECT content FROM `ai_prompts` WHERE `key`='%s' "
               "ORDER BY version DESC LIMIT 1" % KEY)


# --- доступ ------------------------------------------------------------------

def test_page_forbidden_for_guest_and_shadhan():
    code, _ = get_page(PAGE_URL, None)
    assert code == 403, "экран промпта открылся гостю"
    code, _ = get_page(PAGE_URL, login(SHADHAN))
    assert code == 403, "экран промпта открылся шадхану"


def test_api_forbidden_for_non_admin():
    clear_prompts()
    code, data = save(login(SHADHAN), "взлом промпта")
    assert code == 403, "не-админ сохранил промпт: %s" % data
    assert versions() == [], "запись всё-таки появилась"


def test_api_foreign_origin_forbidden():
    clear_prompts()
    code, data = save(login(ADMIN), "csrf промпт", origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data
    assert versions() == []


def test_own_origin_accepted():
    # заслон должен пропускать свой Origin — иначе «зелёные» тесты просто
    # никогда не доходят до сравнения хостов
    clear_prompts()
    code, data = save(login(ADMIN), "свой origin 121212", origin=BASE)
    assert code == 200 and data.get("ok") is True, "свой Origin отклонён: %s" % data


def test_restore_guards():
    # откат меняет то, что говорится модели — гейты нужны и здесь, не только у save
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "версия для откатов 343434")
    code, _ = api(login(SHADHAN), {"action": "restore", "version": 1})
    assert code == 403, "не-админ откатил промпт"
    code, _ = api(sid, {"action": "restore", "version": 1},
                  origin="https://evil.example.com")
    assert code == 403, "откат прошёл с чужого Origin"
    assert versions() == [1], "гейты пропустили запись: %s" % versions()


def test_get_not_allowed():
    code, _ = get_page(SAVE_URL, login(ADMIN))
    assert code == 405, "GET на эндпоинт правок не отклонён: %d" % code


# --- экран -------------------------------------------------------------------

def test_page_shows_default_when_empty():
    clear_prompts()
    code, html = get_page(PAGE_URL, login(ADMIN))
    assert code == 200, "экран не открылся админу: %d" % code
    assert "Fatal error" not in html and "Parse error" not in html
    # пока промпт не задан — показываем встроенный по умолчанию
    assert "консультант еврейского сервиса знакомств" in html, "нет текста по умолчанию"
    assert "prompt_save" in html, "на экране нет обращения к эндпоинту"


def test_page_escapes_author_name():
    # имя автора берётся из User.Name — оно задаётся пользователем
    clear_prompts()
    sid = login(ADMIN)
    old = dbq("SELECT Name FROM `User` WHERE User_ID=1")
    try:
        dbq("UPDATE `User` SET Name='<script>alert(1)</script>' WHERE User_ID=1")
        save(sid, "промпт для проверки автора 565656")
        _, html = get_page(PAGE_URL, sid)
        assert "<script>alert(1)</script>" not in html, "XSS: имя автора не экранировано"
        assert "&lt;script&gt;" in html
    finally:
        esc = old.replace("\\", "\\\\").replace("'", "''")
        dbq("UPDATE `User` SET Name='%s' WHERE User_ID=1" % esc)


def test_page_escapes_prompt_text():
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "<script>alert(1)</script> промпт-маркер")
    _, html = get_page(PAGE_URL, sid)
    assert "<script>alert(1)</script>" not in html, "XSS: текст промпта не экранирован"
    assert "&lt;script&gt;" in html


# --- версионирование ---------------------------------------------------------

def test_save_creates_versions():
    clear_prompts()
    sid = login(ADMIN)
    code, data = save(sid, "первая версия промпта 111")
    assert code == 200 and data.get("ok") is True, "не сохранилось: %s" % data
    assert data.get("version") == 1, "первая версия должна быть 1: %s" % data
    code, data = save(sid, "вторая версия промпта 222")
    assert code == 200 and data.get("version") == 2, "версия не выросла: %s" % data
    assert versions() == [1, 2], "история версий: %s" % versions()
    assert "вторая версия промпта 222" in active_content(), "активна не последняя версия"


def test_history_is_append_only():
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "старый текст 333")
    save(sid, "новый текст 444")
    old = dbq("SELECT content FROM `ai_prompts` WHERE `key`='%s' AND version=1" % KEY)
    assert "старый текст 333" in old, "прошлая версия затёрта: " + old


def test_save_records_author():
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "кто сохранил 555")
    who = dbq("SELECT updated_by FROM `ai_prompts` WHERE `key`='%s' "
              "ORDER BY version DESC LIMIT 1" % KEY)
    assert who == "1", "автор правки не записан: " + who


def test_restore_previous_version():
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "версия А 666")
    save(sid, "версия Б 777")
    code, data = api(sid, {"action": "restore", "version": 1})
    assert code == 200 and data.get("ok") is True, "откат не прошёл: %s" % data
    assert data.get("version") == 3, "откат должен создавать новую версию: %s" % data
    assert "версия А 666" in active_content(), "содержимое не откатилось"
    assert versions() == [1, 2, 3], "история потерялась: %s" % versions()


def test_restore_unknown_version():
    clear_prompts()
    sid = login(ADMIN)
    save(sid, "единственная 888")
    code, _ = api(sid, {"action": "restore", "version": 99})
    assert code == 404, "откат к несуществующей версии не отклонён"


# --- валидация ---------------------------------------------------------------

def test_validation():
    clear_prompts()
    sid = login(ADMIN)
    code, _ = save(sid, "   ")
    assert code == 400, "пустой промпт принят"
    code, _ = save(sid, "я" * 6001)
    assert code == 400, "слишком длинный промпт принят"
    code, _ = api(sid, [("action", "save"), ("content[]", "массив"),
                        ("content[]", "вместо строки")])
    assert code == 400, "массив вместо текста принят"
    code, _ = api(sid, {"action": "bogus"})
    assert code == 400, "неизвестное действие принято"
    assert versions() == [], "мусорные записи всё-таки появились"


def test_boundary_length_accepted():
    clear_prompts()
    code, data = save(login(ADMIN), "я" * 6000)
    assert code == 200 and data.get("ok") is True, "ровно предельная длина отклонена: %s" % data


def test_sql_injection_in_prompt_stored_literally():
    clear_prompts()
    sid = login(ADMIN)
    payload = "'; DROP TABLE ai_prompts; -- маркер999"
    code, data = save(sid, payload)
    assert code == 200, "промпт с кавычками не сохранился: %s" % data
    assert dbq("SHOW TABLES LIKE 'ai_prompts'") == "ai_prompts", "таблица пропала"
    assert "DROP TABLE" in active_content(), "текст исказился"
