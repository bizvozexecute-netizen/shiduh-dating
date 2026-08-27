# -*- coding: utf-8 -*-
"""
Сквозная проверка живости сайта (страховочная сеть).

Пофичные наборы проверяют свою логику, но не ловят поломку СОСЕДНЕЙ
страницы — а шаблоны NetCat ломаются именно так: правка одного файла
роняет другой раздел. Здесь мы обходим ключевые страницы каждой роли и
требуем: ожидаемый код ответа и НИ ОДНОГО следа PHP-ошибки в выводе.

Отдельно: все наши PHP-файлы должны компилироваться (php -l), а
API-эндпоинты — отвечать осмысленным кодом, а не 500.

    python -m pytest tests/smoke_test.py -q
"""
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, DOCKER, ROOT, NoRedirect, dbq, login

GUEST = None
CANDIDATE = "u091ff2b36c83@example.local"   # User 50, своя анкета
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
ADMIN = "u5760fc1c6357@example.local"       # User 1, директор

# Следы поломок PHP в HTML. Ищем именно их, а не любое слово «error»:
# на страницах есть легитимные тексты про ошибки формы.
PHP_ERROR_MARKS = [
    "Fatal error", "Parse error", "Uncaught",
    "Warning: ", "Notice: ", "Deprecated: ",
    "Call to undefined", "syntax error, unexpected",
]


def fetch(path, sid=None, method="GET", data=None):
    body = urllib.parse.urlencode(data).encode() if data else None
    req = urllib.request.Request(BASE + path, data=body, method=method)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except urllib.error.URLError as e:
        raise AssertionError("страница %s недоступна: %s" % (path, e))


def assert_no_php_errors(path, html):
    for mark in PHP_ERROR_MARKS:
        assert mark not in html, "на странице %s след ошибки PHP: «%s»" % (path, mark)


SESSIONS = {}


def setup_module(module):
    SESSIONS["candidate"] = login(CANDIDATE)
    SESSIONS["shadhan"] = login(SHADHAN)
    SESSIONS["admin"] = login(ADMIN)
    # карточка кандидата, которую ведёт шадхан 57
    SESSIONS["card"] = "/my/my-candidates/moi-kandidaty_%s.html" % dbq(
        "SELECT Message_ID FROM `Message204` WHERE Shadhan_1=57 AND Checked=1 "
        "ORDER BY Message_ID LIMIT 1")


# --- публичные страницы -------------------------------------------------------

def test_public_pages_alive():
    for path in ("/", "/news/", "/about/"):
        code, html = fetch(path)
        assert code in (200, 301, 302, 404), "%s ответил %d" % (path, code)
        if code == 200:
            assert_no_php_errors(path, html)


def test_login_page_alive():
    code, html = fetch("/login/")
    assert code in (200, 301, 302, 404)
    if code == 200:
        assert_no_php_errors("/login/", html)


# --- кабинет кандидата --------------------------------------------------------

def test_candidate_pages_alive():
    sid = SESSIONS["candidate"]
    for path in ("/my/", "/my/modify/", "/my/message/", "/my/ai-consultant/"):
        code, html = fetch(path, sid)
        assert code in (200, 302), "%s ответил %d кандидату" % (path, code)
        assert_no_php_errors(path, html)


def test_candidate_ai_consultant_renders_chat():
    code, html = fetch("/my/ai-consultant/", SESSIONS["candidate"])
    assert code == 200, "консультант не открылся: %d" % code
    assert "ai-chat" in html or "консультант" in html.lower(), "нет интерфейса чата"
    assert_no_php_errors("/my/ai-consultant/", html)


def test_candidate_cannot_open_admin_screens():
    sid = SESSIONS["candidate"]
    for path in ("/admin-mailing.php", "/admin-cases.php", "/admin-ai-prompt.php"):
        code, _ = fetch(path, sid)
        assert code == 403, "%s открылся кандидату (код %d)" % (path, code)


# --- кабинет шадхана ----------------------------------------------------------

def test_shadhan_pages_alive():
    sid = SESSIONS["shadhan"]
    for path in ("/my/", "/my/my-candidates/", "/my/message/", SESSIONS["card"]):
        code, html = fetch(path, sid)
        assert code in (200, 302), "%s ответил %d шадхану" % (path, code)
        assert_no_php_errors(path, html)


def test_shadhan_card_has_ai_blocks():
    code, html = fetch(SESSIONS["card"], SESSIONS["shadhan"])
    assert code == 200
    assert "Резюме от ИИ" in html, "в карточке нет блока резюме"
    assert "js-ai-summary-refresh" in html, "в карточке нет кнопки обновления резюме"
    assert "js-invite-create" in html, "в карточке нет кнопки приглашения"
    assert "Психологический портрет" in html, "в карточке нет блока психопрофиля"
    assert "js-match-load" in html, "в карточке нет кнопки подбора пар"
    assert_no_php_errors(SESSIONS["card"], html)


# --- админ-экраны -------------------------------------------------------------

def test_admin_screens_alive():
    sid = SESSIONS["admin"]
    for path in ("/admin-mailing.php", "/admin-cases.php", "/admin-ai-prompt.php"):
        code, html = fetch(path, sid)
        assert code == 200, "%s не открылся админу (код %d)" % (path, code)
        assert_no_php_errors(path, html)
        assert "X-Frame" not in html  # заголовок, а не текст на странице


def test_admin_screens_deny_guest():
    for path in ("/admin-mailing.php", "/admin-cases.php", "/admin-ai-prompt.php"):
        code, _ = fetch(path, GUEST)
        assert code == 403, "%s открылся гостю (код %d)" % (path, code)


# --- API: осмысленные коды вместо 500 ----------------------------------------

API_ENDPOINTS = [
    "/api/ai_chat.php",
    "/api/invite_create.php",
    "/api/invite_claim.php",
    "/api/invite_send_email.php",
    "/api/mailing_preview.php",
    "/api/mailing_send.php",
    "/api/cases_save.php",
    "/api/prompt_save.php",
    "/api/summary_generate.php",
    "/api/assessment_answer.php",
    "/api/match_list.php",
    "/api/match_feedback.php",
    "/api/match_explain.php",
]


def test_api_get_is_rejected_not_crashed():
    for path in API_ENDPOINTS:
        code, body = fetch(path, GUEST)
        assert code in (403, 405), "%s на GET ответил %d (ожидали 403/405)" % (path, code)
        assert_no_php_errors(path, body)


def test_api_post_without_auth_is_rejected():
    for path in API_ENDPOINTS:
        code, body = fetch(path, GUEST, method="POST", data={"x": "1"})
        assert code in (400, 403, 404), \
            "%s без авторизации ответил %d" % (path, code)
        assert_no_php_errors(path, body)


def test_api_returns_json():
    code, body = fetch("/api/ai_chat.php", GUEST, method="POST", data={"x": "1"})
    assert body.strip().startswith("{"), "эндпоинт вернул не JSON: " + body[:120]


# --- компилируемость наших PHP-файлов ----------------------------------------

def test_our_php_files_compile():
    """php -l по всем файлам, которые мы добавили или правили."""
    targets = []
    api_dir = os.path.join(ROOT, "shiduh.org", "api")
    for name in sorted(os.listdir(api_dir)):
        if name.endswith(".php") and not name.startswith("_"):
            targets.append("/var/www/html/api/" + name)
    for name in ("admin-mailing.php", "admin-cases.php", "admin-ai-prompt.php"):
        targets.append("/var/www/html/" + name)
    mig_dir = os.path.join(ROOT, "shiduh.org", "migrations")
    for name in sorted(os.listdir(mig_dir)):
        if name.endswith(".php"):
            targets.append("/var/www/html/migrations/" + name)

    assert len(targets) > 15, "подозрительно мало файлов для проверки: %d" % len(targets)
    bad = []
    for path in targets:
        r = subprocess.run(
            [DOCKER, "compose", "exec", "-T", "-e", "MSYS_NO_PATHCONV=1", "web",
             "php", "-l", path],
            cwd=ROOT, capture_output=True, text=True,
            encoding="utf-8", errors="replace", timeout=60)
        out = (r.stdout or "") + (r.stderr or "")
        if "No syntax errors" not in out:
            bad.append(path + ": " + out.strip()[:200])
    assert not bad, "файлы не компилируются:\n" + "\n".join(bad)


# --- целостность данных после всех прогонов ----------------------------------

def test_core_data_intact():
    """Наши тесты много пишут в базу — убеждаемся, что ничего не снесли."""
    assert dbq("SELECT COUNT(*) FROM `Message204`") == "1189", "изменилось число анкет"
    assert dbq("SELECT COUNT(*) FROM `User`") != "0", "пропали пользователи"
    # схема на месте
    for table in ("ai_chat_messages", "ai_prompts", "ai_field_writes",
                  "invite_tokens", "mailings", "assessment_cases"):
        assert dbq("SHOW TABLES LIKE '%s'" % table) == table, "нет таблицы " + table


def test_no_test_leftovers_in_production_tables():
    """Тестовые артефакты не должны копиться в боевых таблицах."""
    leftovers = dbq("SELECT COUNT(*) FROM `User` WHERE Email LIKE 'invite-%@invite.shiduh.org'")
    assert leftovers == "0", "остались аккаунты-заготовки от тестов: " + leftovers
    fake = dbq("SELECT COUNT(*) FROM `Settings` WHERE `Module`='ai' AND `Value` LIKE '%127.0.0.1%'")
    assert fake == "0", "в настройках остался адрес фейкового LLM"
