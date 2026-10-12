# -*- coding: utf-8 -*-
"""
Тесты блока «Резюме от ИИ» в карточке кандидата у шадхана (Э0.3).

Блок показывает поле AI_summary. Пусто — заглушка. Шадхан видит только
резюме, НЕ переписку кандидата с ИИ. Гоняется против Docker по HTTP.

    python -m pytest tests/ai_summary_test.py -q
"""
import os
import shutil
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
# Docker Desktop на Windows или docker из PATH (Ubuntu, tests/run_ubuntu.sh)
DOCKER = os.environ.get("SHIDUH_DOCKER") or shutil.which("docker") or r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
SHADHAN = "u3f9bb8c20b01@example.local"     # User_ID 57
CARD_ANKETA = 2033                          # Shadhan_1=57, Checked=1
CARD_URL = "/my/my-candidates/moi-kandidaty_%d.html" % CARD_ANKETA


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


def get_card(sid):
    req = urllib.request.Request(BASE + CARD_URL)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def set_summary(value):
    if value is None:
        dbq("UPDATE Message204 SET AI_summary=NULL WHERE Message_ID=%d" % CARD_ANKETA)
    else:
        esc = value.replace("\\", "\\\\").replace("'", "''")
        dbq("UPDATE Message204 SET AI_summary='%s' WHERE Message_ID=%d"
            % (esc, CARD_ANKETA))


def teardown_module(module):
    set_summary(None)


def test_card_has_summary_block():
    set_summary(None)
    sid = login(SHADHAN)
    code, html = get_card(sid)
    assert code == 200
    assert "Резюме от ИИ" in html, "нет заголовка блока резюме"
    assert "Fatal error" not in html and "Parse error" not in html


def test_empty_summary_shows_placeholder():
    set_summary(None)
    sid = login(SHADHAN)
    _, html = get_card(sid)
    assert "Резюме ещё не сформировано" in html


def test_filled_summary_is_shown():
    marker = "Спокойная и вдумчивая, ценит семью выше карьеры 71828"
    set_summary(marker)
    sid = login(SHADHAN)
    _, html = get_card(sid)
    assert marker in html, "текст резюме не отрисован"
    assert "Резюме ещё не сформировано" not in html


def test_summary_is_html_escaped():
    # резюме приходит из внешнего LLM — HTML-спецсимволы должны экранироваться,
    # а не исполняться в карточке шадхана
    set_summary("<script>alert(1)</script> маркер92653")
    sid = login(SHADHAN)
    _, html = get_card(sid)
    assert "<script>alert(1)</script>" not in html, "XSS: payload отдан без экранирования"
    assert "&lt;script&gt;" in html, "нет экранированного вывода резюме"
    assert "&amp;lt;script&amp;gt;" not in html, "двойное экранирование резюме"
    assert "маркер92653" in html


def test_shadhan_does_not_see_candidate_chat():
    # переписка кандидата с ИИ не должна попадать в карточку шадхана
    owner = dbq("SELECT User_ID FROM Message204 WHERE Message_ID=%d" % CARD_ANKETA)
    secret = "ТАЙНАЯ переписка кандидата с ИИ 31415"
    dbq("INSERT INTO ai_chat_messages SET user_id=%s, role='user', content='%s', created_at=NOW()"
        % (owner, secret))
    set_summary("Обычное резюме")
    sid = login(SHADHAN)
    _, html = get_card(sid)
    dbq("DELETE FROM ai_chat_messages WHERE content='%s'" % secret)
    assert secret not in html, "переписка кандидата видна шадхану — утечка приватности"
