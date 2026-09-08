# -*- coding: utf-8 -*-
"""
C8 этапа «До идеала»: каркас админ-консоли.

Админ-экраны — автономные страницы в корне сайта, чтобы не попадать в
ловушку двойного хранения шаблонов NetCat. Раньше каждая рисовала свой
<head>, свою палитру и свою проверку прав, а открыть экраны можно было
только зная адрес наизусть. Теперь общее — в api/admin_layout.php:
бутстрап и права, заголовки безопасности, навигация, подвал.

Что защищаем:
  - не администратору — 403 на каждой странице, а не пустой экран;
  - строгая CSP: инлайновый скрипт без nonce не выполнится — это защита от
    XSS на экранах, где администратор видит чужой ввод;
  - у каждого экрана есть токен CSRF, слой и навигация со всеми экранами;
  - вход в консоль из кабинета виден только администратору.

    python -m pytest tests/admin_console_test.py -q
"""
import os
import re
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import get_text, login  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"       # User 1, AdminType=7
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "uad322c9ddbba@example.local"   # User 44
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "shiduh.org")

PAGES = ["/admin-cases.php", "/admin-ai-prompt.php", "/admin-mailing.php",
         "/admin-moderation.php", "/admin-consent.php", "/admin-matches.php"]   # C10–C12
if os.path.exists(os.path.join(ROOT, "admin-ai.php")):
    PAGES.insert(0, "/admin-ai.php")

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def page(path, email):
    return get_text(path, sid_of(email) if email else None)


# ---------------------------------------------------------------- права ---

@pytest.mark.parametrize("path", PAGES)
def test_admin_opens_page(path):
    code, html = page(path, ADMIN)
    assert code == 200, "%s: %d" % (path, code)
    for mark in ("Fatal error", "Parse error", "Warning:"):
        assert mark not in html, path + ": след ошибки PHP"


@pytest.mark.parametrize("path", PAGES)
@pytest.mark.parametrize("who", [SHADHAN, CANDIDATE, None])
def test_others_get_403(path, who):
    code, html = page(path, who)
    assert code == 403, "%s для %s: %d" % (path, who or "гостя", code)
    assert "Доступ запрещён" in html, "403 без объяснения"
    assert "csrf-token" not in html, "чужому отдан токен CSRF"


# ------------------------------------------------------------- каркас ---

@pytest.mark.parametrize("path", PAGES)
def test_page_has_shell(path):
    code, html = page(path, ADMIN)
    assert re.search(r'name="csrf-token"[^>]*content="[0-9a-f]{64}"', html), path + ": нет токена CSRF"
    assert "/js/csrf.js" in html and "/js/admin.js" in html, path + ": не подключены общие скрипты"
    # инлайновые скрипты страниц выполняются при разборе — раньше отложенных;
    # admin.js с defer оставлял бы shAdmin неопределённым (поймано в браузере)
    tag = re.search(r"<script[^>]*/js/admin\.js[^>]*>", html).group(0)
    assert "defer" not in tag and "async" not in tag, path + ": admin.js отложен: " + tag
    assert "/css/one.css" in html and "/css/one-admin.css" in html, path + ": не подключён слой"
    assert "<style" not in html, path + ": в странице остался свой <style>"
    assert 'class="nav"' in html, path + ": нет навигации"
    for other in PAGES:
        assert 'href="%s"' % other in html, "%s: в навигации нет %s" % (path, other)
    assert 'aria-current="page"' in html, path + ": текущий экран не отмечен"
    assert 'href="/my/"' in html, path + ": нет дороги назад в кабинет"


@pytest.mark.parametrize("path", PAGES)
def test_strict_csp_with_nonce(path):
    """Инлайновый скрипт выполняется только с nonce из заголовка — иначе CSP
    его отбрасывает, и это защита от XSS на экранах с чужим вводом."""
    import urllib.request
    from fake_llm import BASE
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid_of(ADMIN))
    with urllib.request.urlopen(req, timeout=120) as r:
        csp = r.headers.get("Content-Security-Policy", "")
        html = r.read().decode("utf-8", "replace")
        assert r.headers.get("X-Frame-Options") == "DENY", path + ": нет X-Frame-Options"
        assert r.headers.get("X-Content-Type-Options") == "nosniff", path + ": нет nosniff"
        assert "no-store" in r.headers.get("Cache-Control", ""), path + ": экран кэшируется"
        assert r.headers.get("Referrer-Policy"), path + ": нет Referrer-Policy"
    m = re.search(r"script-src 'self' 'nonce-([0-9a-f]+)'", csp)
    assert m, path + ": CSP без nonce: " + csp
    nonce = m.group(1)
    assert "'unsafe-inline'" not in csp, path + ": в CSP разрешён unsafe-inline: " + csp
    assert 'style="' not in html, path + ": инлайновый стиль — CSP его не пустит"
    scripts = re.findall(r"<script\b([^>]*)>", html)
    inline = [a for a in scripts if "src=" not in a]
    # экран только для чтения (admin-matches.php) обходится без инлайновых скриптов
    # nonce нужен только инлайновым: внешние со своего origin пускает 'self'
    for attrs in inline:
        assert 'nonce="%s"' % nonce in attrs, "%s: инлайновый скрипт без nonce: <script%s>" % (path, attrs)


def test_403_page_is_protected_too():
    """Страница отказа защищена так же, как рабочая: не кэшируется, не
    встраивается, без чужих скриптов — и без токена."""
    import urllib.request, urllib.error
    from fake_llm import BASE
    req = urllib.request.Request(BASE + PAGES[-1])
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid_of(CANDIDATE))
    try:
        urllib.request.urlopen(req, timeout=120)
        assert False, "кандидат получил не 403"
    except urllib.error.HTTPError as e:
        assert e.code == 403
        assert e.headers.get("X-Frame-Options") == "DENY"
        assert "no-store" in e.headers.get("Cache-Control", "")
        assert "script-src 'self' 'nonce-" in e.headers.get("Content-Security-Policy", "")


def test_nonce_changes_per_request():
    a = page(PAGES[-1], ADMIN)[1]
    b = page(PAGES[-1], ADMIN)[1]
    na = re.search(r'nonce="([0-9a-f]+)"', a).group(1)
    nb = re.search(r'nonce="([0-9a-f]+)"', b).group(1)
    assert na != nb, "nonce одинаков в двух запросах — его можно угадать"


# ------------------------------------------------------- вход из кабинета ---

def test_sidebar_link_only_for_admin():
    code, html = get_text("/my/ai-consultant/", sid_of(ADMIN))
    assert code == 200
    assert "Администрирование" in html, "администратор не видит входа в консоль"
    m = re.search(r'class="lk-aside__write lk-aside__admin" href="([^"]+)"', html)
    assert m, "нет ссылки на консоль"
    code, _ = get_text(m.group(1), sid_of(ADMIN))
    assert code == 200, "ссылка из кабинета ведёт на %s → %d" % (m.group(1), code)

    for who in (SHADHAN, CANDIDATE):
        code, html = get_text("/my/ai-consultant/", sid_of(who))
        assert code == 200
        assert "lk-aside__admin" not in html, "не администратор видит вход в консоль"


# -------------------------------------------------------- страницы целы ---

def test_page_bodies_kept_their_hooks():
    """Тела экранов не менялись — на их id и тексты опираются cases_test,
    prompt_test, mailing_test и сами AJAX-эндпоинты."""
    _, cases = page("/admin-cases.php", ADMIN)
    assert "cases_save" in cases and "js-create" in cases and "Языки любви" in cases
    _, prompt = page("/admin-ai-prompt.php", ADMIN)
    assert 'id="promptText"' in prompt and 'id="btnSave"' in prompt and "prompt_save" in prompt
    _, mailing = page("/admin-mailing.php", ADMIN)
    assert 'id="btnPreview"' in mailing and 'id="btnSend"' in mailing and "mailing_send" in mailing


def test_provider_shown_as_stub_when_no_settings():
    """Ядро отвечает array(), а не false, когда у модуля настроек нет ни одной
    строки — и на экране появлялось «Провайдер сейчас: Array». Так выглядит
    свежая установка до первого сохранения настроек ИИ."""
    from fake_llm import clear_ai_settings
    clear_ai_settings()
    _, html = page("/admin-ai-prompt.php", ADMIN)
    assert "Array" not in html, "настройки без строк прочитаны как массив"
    assert "Провайдер сейчас: <b>stub</b>" in html, "без настроек провайдер должен быть stub"
