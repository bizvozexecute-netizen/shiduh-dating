# -*- coding: utf-8 -*-
"""
Тесты клиента LLM и подключения его к чату (ROADMAP Э1.1).

Провайдер выбирается настройкой (Settings, Module='ai'):
  stub       — без ключа, отвечает заглушкой (режим по умолчанию сейчас)
  openrouter — OpenAI-совместимый API; ключ и base_url из настроек, не из кода

Реальный HTTP-путь проверяем против локального фейкового эндпоинта: он
отдаёт ответ в формате OpenAI и умеет изображать 500/429/4xx — так
проверяются ретраи с бэкоффом и то, что на 4xx повтора нет.

    python -m pytest tests/ai_client_test.py -q
"""
import base64
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
CANDIDATE = "u091ff2b36c83@example.local"     # User 50
CHAT_URL = "/api/ai_chat.php"
# Фейк живёт ВНЕ веб-корня: отдаётся встроенным сервером PHP на порту 8099
# внутри контейнера. Так забытый после сбоя файл не может уехать на прод
# вместе с деревом сайта (замечание code-review).
FAKE_DIR = "/tmp/fakellm"
FAKE_PORT = 8099
FAKE_KEY = "sk-test-fake"
FAKE_BASE = "http://127.0.0.1:%d" % FAKE_PORT

FAKE_LLM = r"""<?php
/* Фейковый LLM для тестов: формат ответа как у OpenAI. Роутер встроенного
   сервера PHP — принимает любой путь (клиент зовёт /chat/completions).
   Режим и счётчик попыток — в файле, чтобы пережить отдельные запросы. */
header('Content-Type: application/json; charset=utf-8');
$state = sys_get_temp_dir() . '/fake_llm_state.json';
$s = @json_decode(@file_get_contents($state), true);
if (!is_array($s)) { $s = array('mode' => 'ok', 'calls' => 0, 'fail_first' => 0); }
$s['calls']++;
@file_put_contents($state, json_encode($s));

/* Ключ обязателен: без верного Bearer отвечаем 401 — так тест успешного
   пути доказывает, что ключ из настроек реально уходит в заголовке. */
$auth = '';
foreach ($_SERVER as $k => $v) {
    if ($k === 'HTTP_AUTHORIZATION' || $k === 'REDIRECT_HTTP_AUTHORIZATION') { $auth = $v; }
}
if ($auth !== 'Bearer __KEY__') {
    http_response_code(401);
    echo json_encode(array('error' => array('message' => 'no/bad api key: [' . $auth . ']')));
    exit;
}

$mode = $s['mode'];
if ($mode === 'fail_then_ok' && $s['calls'] <= (int)$s['fail_first']) {
    http_response_code(500);
    echo json_encode(array('error' => array('message' => 'temporary')));
    exit;
}
if ($mode === 'always_500') {
    http_response_code(500);
    echo json_encode(array('error' => array('message' => 'boom')));
    exit;
}
if ($mode === 'bad_request') {
    /* Провайдер может вернуть в тексте ошибки кусок ключа и внутренний URL —
       проверяем, что наружу это не уходит. */
    http_response_code(400);
    echo json_encode(array('error' => array(
        'message' => 'Incorrect API key provided: sk-Ab****cdEF. See https://internal.example/keys',
    )));
    exit;
}
if ($mode === 'no_total_tokens') {
    /* Часть шлюзов не отдаёт total_tokens — только слагаемые. */
    echo json_encode(array(
        'choices' => array(array('message' => array('role' => 'assistant', 'content' => 'ЧАСТИЧНЫЙ УЧЁТ'))),
        'usage' => array('prompt_tokens' => 5, 'completion_tokens' => 4),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = json_decode(file_get_contents('php://input'), true);
$last = '';
$system = '';
if (isset($raw['messages']) && is_array($raw['messages'])) {
    foreach ($raw['messages'] as $m) {
        if (isset($m['role']) && $m['role'] === 'user') { $last = $m['content']; }
        if (isset($m['role']) && $m['role'] === 'system') { $system = $m['content']; }
    }
}
if ($mode === 'echo_system') { $last = $system; }
echo json_encode(array(
    'choices' => array(array('message' => array(
        'role' => 'assistant',
        'content' => 'ФЕЙК-ОТВЕТ на: ' . $last,
    ))),
    'usage' => array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
), JSON_UNESCAPED_UNICODE);
"""


def dbq(sql):
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org", "-e", sql],
        cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=60,
    )
    return (r.stdout or "").strip()


def web(*args):
    return subprocess.run([DOCKER, "compose", "exec", "-T", "web"] + list(args),
                          cwd=ROOT, capture_output=True, text=True,
                          encoding="utf-8", errors="replace", timeout=60)


def set_fake_mode(mode, fail_first=0):
    # chmod 0666: файл пишет и CLI-PHP (root), и веб-сервер (www-data) —
    # без этого счётчик попыток не увеличивается и ретраи не проверить
    web("php", "-r",
        "$f=sys_get_temp_dir().'/fake_llm_state.json';"
        "file_put_contents($f, json_encode("
        "array('mode'=>'%s','calls'=>0,'fail_first'=>%d))); @chmod($f, 0666);"
        % (mode, fail_first))


def fake_calls():
    r = web("php", "-r",
            "$s=json_decode(@file_get_contents(sys_get_temp_dir().'/fake_llm_state.json'),true);"
            "echo (int)($s['calls'] ?? 0);")
    return int((r.stdout or "0").strip() or 0)


def setting(key, value):
    esc = str(value).replace("\\", "\\\\").replace("'", "''")
    dbq("DELETE FROM `Settings` WHERE `Key`='%s' AND `Module`='ai' AND Catalogue_ID=0; "
        "INSERT INTO `Settings` SET `Key`='%s', `Value`='%s', `Module`='ai', Catalogue_ID=0"
        % (key, key, esc))


def clear_ai_settings():
    dbq("DELETE FROM `Settings` WHERE `Module`='ai'")


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


def send(sid, text, origin=None):
    body = urllib.parse.urlencode({"message": text}).encode()
    req = urllib.request.Request(BASE + CHAT_URL, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if origin:
        req.add_header("Origin", origin)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=180)
        raw, code = r.read().decode("utf-8", "replace"), r.status
    except urllib.error.HTTPError as e:
        raw, code = e.read().decode("utf-8", "replace"), e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, {"_raw": raw}


UID = None


def history(marker):
    return dbq("SELECT role, tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
               "AND content LIKE '%%%s%%'" % (UID, marker))


def clear_chat():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)


def start_fake_server():
    """Кладёт роутер в /tmp контейнера и поднимает встроенный сервер PHP."""
    php = FAKE_LLM.replace("__KEY__", FAKE_KEY)
    b64 = base64.b64encode(php.encode("utf-8")).decode("ascii")
    web("php", "-r",
        "@mkdir('%s', 0777, true); file_put_contents('%s/router.php', base64_decode('%s'));"
        % (FAKE_DIR, FAKE_DIR, b64))
    subprocess.run(
        [DOCKER, "compose", "exec", "-d", "web", "php", "-S", "127.0.0.1:%d" % FAKE_PORT,
         "%s/router.php" % FAKE_DIR],
        cwd=ROOT, capture_output=True, text=True, timeout=60)
    # ждём готовности сервера
    for _ in range(40):
        r = web("php", "-r",
                "$c=@curl_init('%s/ping'); curl_setopt($c, CURLOPT_RETURNTRANSFER, true);"
                "curl_setopt($c, CURLOPT_TIMEOUT, 2); @curl_exec($c);"
                "echo (int)curl_getinfo($c, CURLINFO_HTTP_CODE);" % FAKE_BASE)
        if (r.stdout or "").strip() not in ("", "0"):
            return
        time.sleep(0.25)
    raise RuntimeError("фейковый LLM не поднялся")


def stop_fake_server():
    web("sh", "-c", "pkill -f 'php -S 127.0.0.1:%d' || true" % FAKE_PORT)
    web("sh", "-c", "rm -rf %s" % FAKE_DIR)


def setup_module(module):
    global UID
    UID = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    start_fake_server()
    clear_ai_settings()
    clear_chat()


def teardown_module(module):
    clear_ai_settings()
    clear_chat()
    stop_fake_server()


def use_fake_provider():
    setting("provider", "openrouter")
    setting("api_key", FAKE_KEY)
    setting("base_url", FAKE_BASE)
    setting("model", "test/model")
    setting("max_retries", 2)
    setting("rate_limit_per_hour", 1000)   # лимит проверяется отдельным тестом


# --- режим заглушки (без ключа) ---------------------------------------------

def test_stub_provider_by_default():
    clear_ai_settings()
    clear_chat()
    sid = login(CANDIDATE)
    code, data = send(sid, "тест заглушки 71828")
    assert code == 200, "чат не ответил: %s" % data
    assert "консультант" in data.get("reply", "").lower()
    rows = history("тест заглушки 71828")
    assert rows.startswith("user\t"), "сообщение пользователя не сохранено: " + rows


def test_stub_saves_assistant_reply():
    clear_ai_settings()
    clear_chat()
    sid = login(CANDIDATE)
    send(sid, "вопрос 31415")
    n = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='assistant'" % UID)
    assert n == "1", "ответ ассистента не сохранён"


# --- CSRF (Э1.1-e) -----------------------------------------------------------

def test_foreign_origin_forbidden():
    clear_chat()
    sid = login(CANDIDATE)
    code, data = send(sid, "csrf 27182", origin="https://evil.example.com")
    assert code == 403, "запрос с чужого Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d" % UID) == "0", \
        "межсайтовый запрос записал сообщение"


def test_own_origin_allowed():
    clear_chat()
    sid = login(CANDIDATE)
    code, _ = send(sid, "свой origin 99887", origin="http://localhost:8080")
    assert code == 200


# --- реальный HTTP-путь провайдера ------------------------------------------

def test_openrouter_path_returns_model_reply_and_tokens():
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    sid = login(CANDIDATE)
    code, data = send(sid, "живой вопрос 55555")
    assert code == 200, "провайдер не ответил: %s" % data
    assert "ФЕЙК-ОТВЕТ" in data.get("reply", ""), "ответ модели не доехал: %s" % data
    assert "живой вопрос 55555" in data["reply"], "модель не увидела сообщение пользователя"
    # учёт токенов (Э1.1-d): ответ ассистента несёт usage
    tok = dbq("SELECT tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
              "AND role='assistant' ORDER BY id DESC LIMIT 1" % UID)
    assert tok == "18", "токены не учтены: " + tok


def test_system_prompt_is_sent():
    # роль консультанта задаётся системным промптом (в Э1.2 — версионируемым)
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    sid = login(CANDIDATE)
    code, data = send(sid, "проверка промпта 88888")
    assert code == 200, "не ответил: %s" % data
    assert "консультант еврейского сервиса знакомств" in data.get("reply", ""), \
        "системный промпт не дошёл до модели: %s" % data


def test_history_is_sent_to_model():
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    sid = login(CANDIDATE)
    send(sid, "первое сообщение 111")
    code, data = send(sid, "второе сообщение 222")
    assert code == 200
    # модель отвечает на последнее сообщение пользователя из переданной истории
    assert "второе сообщение 222" in data["reply"]


def test_retries_on_temporary_error():
    clear_chat()
    use_fake_provider()
    set_fake_mode("fail_then_ok", fail_first=2)   # 2 отказа, затем успех
    sid = login(CANDIDATE)
    code, data = send(sid, "ретрай 33333")
    assert code == 200, "клиент не пережил временную ошибку: %s" % data
    assert "ФЕЙК-ОТВЕТ" in data.get("reply", "")
    assert fake_calls() == 3, "ожидали 3 попытки (2 отказа + успех), было %d" % fake_calls()


def test_gives_up_after_retries():
    clear_chat()
    use_fake_provider()
    set_fake_mode("always_500")
    sid = login(CANDIDATE)
    code, data = send(sid, "всегда 500 44444")
    assert code == 503, "постоянная ошибка не отражена в ответе: %s" % data
    assert data.get("error"), "нет понятного сообщения об ошибке"
    assert fake_calls() == 3, "ожидали 3 попытки (1 + 2 ретрая), было %d" % fake_calls()
    # сообщение пользователя не теряется
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='user'" % UID) == "1"
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='assistant'" % UID) == "0"


def test_no_retry_on_client_error():
    clear_chat()
    use_fake_provider()
    set_fake_mode("bad_request")
    sid = login(CANDIDATE)
    code, data = send(sid, "битый запрос 66666")
    assert code == 503, "ошибка 4xx не отражена: %s" % data
    assert fake_calls() == 1, "на 4xx повтор не нужен, попыток: %d" % fake_calls()


def test_tokens_fallback_when_total_missing():
    # шлюз отдал только слагаемые — учёт не должен обнуляться
    clear_chat()
    use_fake_provider()
    set_fake_mode("no_total_tokens")
    sid = login(CANDIDATE)
    code, data = send(sid, "частичный учёт 12321")
    assert code == 200, "не ответил: %s" % data
    tok = dbq("SELECT tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
              "AND role='assistant' ORDER BY id DESC LIMIT 1" % UID)
    assert tok == "9", "токены не досчитаны из слагаемых (5+4): " + tok


# --- защита расхода и приватность -------------------------------------------

def test_rate_limit_blocks_burst():
    # эндпоинт тратит деньги: превышение часового лимита отсекается
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    setting("rate_limit_per_hour", 3)
    sid = login(CANDIDATE)
    for i in range(3):
        code, data = send(sid, "в пределах лимита %d" % i)
        assert code == 200, "запрос %d отклонён раньше лимита: %s" % (i, data)
    calls_before = fake_calls()
    code, data = send(sid, "сверх лимита 99999")
    assert code == 429, "лимит не сработал: %s" % data
    assert data.get("error"), "нет понятного сообщения о лимите"
    assert fake_calls() == calls_before, "запрос сверх лимита ушёл к провайдеру (деньги)"
    # сообщение сверх лимита не сохраняется
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d "
               "AND content LIKE '%%сверх лимита 99999%%'" % UID) == "0"


def test_error_text_does_not_leak_provider_details():
    # текст ошибки провайдера может содержать кусок ключа и внутренний URL —
    # наружу отдаём обобщённое сообщение
    clear_chat()
    use_fake_provider()
    set_fake_mode("bad_request")
    sid = login(CANDIDATE)
    code, data = send(sid, "утечка 45454")
    assert code == 503, "ошибка не отражена: %s" % data
    err = data.get("error") or ""
    for leak in ("sk-Ab", "internal.example", "Incorrect API key"):
        assert leak not in err, "в ответ пользователю утёк фрагмент «%s»: %s" % (leak, err)


def test_error_text_does_not_leak_base_url():
    # сетевая ошибка curl содержит хост и порт провайдера — их тоже не отдаём
    clear_chat()
    use_fake_provider()
    setting("base_url", "http://127.0.0.1:9")   # закрытый порт → ошибка соединения
    setting("max_retries", 0)
    sid = login(CANDIDATE)
    code, data = send(sid, "сетевая ошибка 78787")
    assert code == 503, "сетевая ошибка не отражена: %s" % data
    err = data.get("error") or ""
    assert "127.0.0.1" not in err and ":9" not in err, "в ответ утёк адрес провайдера: " + err


def test_system_prompt_does_not_solicit_sensitive_data():
    # 152-ФЗ / политика X-2: здоровье, доход и медикаменты не собираем в чат,
    # который уходит во внешний LLM — для них есть поля анкеты
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    sid = login(CANDIDATE)
    code, data = send(sid, "политика промпта 65656")
    assert code == 200, "не ответил: %s" % data
    prompt = data.get("reply", "")
    assert "не спрашивайте" in prompt.lower() or "не расспрашивайте" in prompt.lower(), \
        "в промпте нет запрета расспрашивать о чувствительном: " + prompt
    for word in ("здоров", "доход"):
        assert word in prompt.lower(), "промпт не называет чувствительные темы: " + prompt


def test_provider_without_key_reports_clearly():
    clear_chat()
    clear_ai_settings()
    setting("provider", "openrouter")   # ключ не задан
    sid = login(CANDIDATE)
    code, data = send(sid, "нет ключа 77777")
    assert code == 503, "отсутствие ключа не отражено: %s" % data
    assert "ключ" in (data.get("error") or "").lower()
