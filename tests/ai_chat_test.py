# -*- coding: utf-8 -*-
"""
Тесты эндпоинта ИИ-чата (Э0.2): приём сообщения и хранение в ai_chat_messages.

На этой фазе ассистент отвечает заглушкой (LLM подключается в Э1) — но
хранение и приватность работают полностью. Гоняются против Docker по HTTP.

Запуск (окружение поднято):
    python -m pytest tests/ai_chat_test.py -q
"""
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
ENDPOINT = BASE + "/api/ai_chat.php"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
CAND_A = "ua9c6c028bf40@example.local"   # User_ID 32
CAND_A_ID = 32
CAND_B = "uad322c9ddbba@example.local"   # User_ID 44


def dbq(sql):
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org", "-e", sql],
        cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=60,
    )
    return (r.stdout or "").strip()


def truncate_chat():
    dbq("TRUNCATE ai_chat_messages;")


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
        resp = opener.open(req, timeout=60)
        headers = resp.headers
    except urllib.error.HTTPError as e:
        headers = e.headers
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("не удалось авторизоваться: " + email)


def post_message(sid, message):
    """POST сообщения в эндпоинт. Возвращает (код, распарсенный JSON или None)."""
    data = urllib.parse.urlencode({"message": message}).encode()
    req = urllib.request.Request(ENDPOINT, data=data, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        raw = r.read().decode("utf-8", "replace")
        code = r.status
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        code = e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, None


def test_anonymous_is_rejected():
    truncate_chat()
    code, _ = post_message(None, "привет")
    assert code in (401, 403), "аноним должен получать отказ, получил %s" % code
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages") == "0", "аноним не должен писать в чат"


def test_message_stored_and_stub_reply():
    truncate_chat()
    sid = login(CAND_A)
    code, js = post_message(sid, "Здравствуйте, помогите заполнить анкету")
    assert code == 200, "код %s" % code
    assert js is not None, "ответ не JSON"
    # в базе ровно две строки: сообщение пользователя и ответ-заглушка
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d" % CAND_A_ID) == "2"
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='user'" % CAND_A_ID) == "1"
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d AND role='assistant'" % CAND_A_ID) == "1"
    # заглушка ассистента вернулась в ответе
    assert "reply" in js, "нет поля reply в ответе"
    assert js["reply"].strip() != "", "пустой ответ ассистента"


def test_user_message_content_preserved():
    truncate_chat()
    sid = login(CAND_A)
    text = "Вырос в Махачкале, живу в Москве"
    post_message(sid, text)
    stored = dbq("SELECT content FROM ai_chat_messages WHERE user_id=%d AND role='user' ORDER BY id DESC LIMIT 1" % CAND_A_ID)
    assert stored == text, "текст сообщения искажён: %r" % stored


def test_empty_message_rejected():
    truncate_chat()
    sid = login(CAND_A)
    code, _ = post_message(sid, "   ")
    assert code == 400, "пустое сообщение должно отклоняться, код %s" % code
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages") == "0"


def test_injection_in_message_is_safe():
    truncate_chat()
    sid = login(CAND_A)
    payload = "'; DROP TABLE ai_chat_messages; --"
    code, _ = post_message(sid, payload)
    assert code == 200
    # таблица цела и сообщение записано дословно
    assert dbq("SHOW TABLES LIKE 'ai_chat_messages'") == "ai_chat_messages"
    stored = dbq("SELECT content FROM ai_chat_messages WHERE role='user' ORDER BY id DESC LIMIT 1")
    assert stored == payload


def test_messages_are_private_per_user():
    truncate_chat()
    sid_a = login(CAND_A)
    sid_b = login(CAND_B)
    post_message(sid_a, "сообщение кандидата А")
    post_message(sid_b, "сообщение кандидата Б")
    # у каждого только свои строки (user+assistant = 2)
    assert dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id=%d" % CAND_A_ID) == "2"
    # сообщение А не приписано Б
    leak = dbq("SELECT COUNT(*) FROM ai_chat_messages WHERE user_id!=%d AND content='сообщение кандидата А'" % CAND_A_ID)
    assert leak == "0", "сообщение утекло к другому пользователю"


# --- раздел /my/ai-consultant/ (UI компонента-чата) ---

def get_section(sid):
    req = urllib.request.Request(BASE + "/my/ai-consultant/")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def test_section_opens_for_candidate():
    sid = login(CAND_A)
    code, html = get_section(sid)
    assert code == 200, "раздел не открылся, код %s" % code
    assert "ai-chat__log" in html, "нет UI чата в разделе"
    assert "lk-aside__nav" in html, "нет сайдбара ЛК (макет 17 не подтянулся)"
    assert "Fatal error" not in html and "Parse error" not in html


def test_section_renders_own_history():
    truncate_chat()
    sid = login(CAND_A)
    marker = "Мой уникальный вопрос про кашрут 12345"
    post_message(sid, marker)
    code, html = get_section(sid)
    assert code == 200
    assert marker in html, "сообщение пользователя не отрисовано в истории раздела"


def test_section_history_not_leaking_between_users():
    truncate_chat()
    sid_a = login(CAND_A)
    sid_b = login(CAND_B)
    secret_a = "Секрет кандидата А про переезд 98765"
    post_message(sid_a, secret_a)
    code, html_b = get_section(sid_b)
    assert code == 200
    assert secret_a not in html_b, "чужое сообщение видно в разделе другого пользователя"
