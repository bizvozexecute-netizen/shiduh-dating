# -*- coding: utf-8 -*-
"""
Тесты жёсткости сессий (ROADMAP B-4, находка AUDIT №5).

Было: сессия живёт 365 дней и не привязана ни к чему — украденная кука
работает год с любого устройства и из любой сети.

Стало (движок NetCat это умеет, надо было включить):
  * срок жизни авторизации ≤ 30 суток бездействия (скользящее окно:
    активный пользователь не разлогинивается);
  * привязка сессии к сети клиента (`ip_check_level` ≥ 1) — кука, унесённая
    в другую сеть, не работает.

    python -m pytest tests/session_hardening_test.py -q
"""
import os
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login, web

CANDIDATE = "u091ff2b36c83@example.local"

MAX_AUTHTIME = 30 * 24 * 3600      # верхняя граница разумного срока


def authed_page(sid, path="/my/"):
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=120) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def is_authorized(sid):
    """Кабинет отдаётся только авторизованному: гостя макет уводит редиректом."""
    code, html = authed_page(sid)
    return code == 200 and "Выйти" in html


# --- срок жизни --------------------------------------------------------------

def test_authtime_is_not_a_year():
    """В конфиге и в примере конфига срок не должен быть «год»."""
    out = (web("php", "-r",
               "$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
               "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
               "include_once $r.'/vars.inc.php'; echo (int)$ADMIN_AUTHTIME;").stdout or "0").strip()
    assert out.isdigit(), "не удалось прочитать ADMIN_AUTHTIME: " + repr(out)
    assert 0 < int(out) <= MAX_AUTHTIME, \
        "срок жизни авторизации %s c — больше 30 суток" % out


def test_example_config_documents_safe_value():
    """Пример конфига едет в git и на прод — там тоже не должно быть года."""
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    path = os.path.join(root, "shiduh.org", "vars.inc.php.example")
    with open(path, encoding="utf-8") as f:
        text = f.read()
    line = [l for l in text.split("\n") if l.strip().startswith("$ADMIN_AUTHTIME")]
    assert line, "в примере конфига нет ADMIN_AUTHTIME"
    # только присваивание, до «;» — в комментарии рядом стоит прежнее значение
    value = line[0].split("=", 1)[1].split(";", 1)[0]
    digits = "".join(c for c in value if c.isdigit())
    assert digits, "не удалось разобрать значение: " + line[0].strip()
    assert 0 < int(digits) <= MAX_AUTHTIME, \
        "в примере конфига срок больше 30 суток: " + line[0].strip()


def test_expired_session_is_rejected():
    """Просроченная сессия не пускает в кабинет."""
    sid = login(CANDIDATE)
    assert is_authorized(sid), "свежая сессия не авторизует"
    dbq("UPDATE `Session` SET SessionTime=%d WHERE Session_ID='%s'"
        % (int(time.time()) - 60, sid))
    assert not is_authorized(sid), "просроченная сессия всё ещё пускает в кабинет"


# --- привязка к сети ---------------------------------------------------------

def test_ip_check_enabled_for_site():
    level = (web("php", "-r",
                 "$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
                 "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
                 "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
                 "echo (int)nc_core::get_object()->get_settings('ip_check_level','auth');").stdout or "0").strip()
    assert level.isdigit() and int(level) >= 1, \
        "привязка сессии к сети выключена (ip_check_level=%s)" % level


def test_session_stolen_to_another_network_is_rejected():
    """Кука, унесённая в другую сеть, не работает."""
    sid = login(CANDIDATE)
    assert is_authorized(sid), "свежая сессия не авторизует"
    # подменяем сохранённый IP сессии на заведомо чужой (203.0.113.7 — TEST-NET-3)
    dbq("UPDATE `Session` SET UserIP=%d WHERE Session_ID='%s'"
        % (3405803783, sid))
    assert not is_authorized(sid), "сессия с чужим IP всё ещё пускает в кабинет"
