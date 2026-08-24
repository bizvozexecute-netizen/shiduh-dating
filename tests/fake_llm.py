# -*- coding: utf-8 -*-
"""
Общий стенд для тестов ИИ: фейковый LLM в формате OpenAI + помощники.

Фейк живёт ВНЕ веб-корня — его отдаёт встроенный сервер PHP на порту 8099
внутри контейнера. Так забытый после сбоя файл не может уехать на прод
вместе с деревом сайта.

Режимы (set_fake_mode):
  ok              — обычный ответ, эхом последнего сообщения пользователя
  echo_system     — эхом системного промпта (проверка, что он доехал)
  echo_tools      — эхом списка полученных инструментов и их полей
  tool_call_always— просит инструмент на каждом вызове (проверка отсутствия цикла)
  fail_then_ok    — первые N вызовов отдают 500, потом успех (ретраи)
  always_500      — всегда 500 (отказ после ретраев)
  bad_request     — 400 с «утечкой» ключа и внутреннего URL в тексте
  no_total_tokens — usage без total_tokens (только слагаемые)
  tool_call       — первый вызов возвращает tool_calls (из tool_args),
                    последующие — обычный текст (двухфазный конвейер Э1.3)

Ключ обязателен: без верного Bearer фейк отвечает 401, поэтому «зелёный»
успешный путь доказывает, что ключ из настроек реально уходит в заголовке.
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

DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FAKE_DIR = "/tmp/fakellm"
FAKE_PORT = 8099
FAKE_KEY = "sk-test-fake"
FAKE_BASE = "http://127.0.0.1:%d" % FAKE_PORT

FAKE_LLM = r"""<?php
/* Фейковый LLM: роутер встроенного сервера PHP, принимает любой путь. */
header('Content-Type: application/json; charset=utf-8');
$state = sys_get_temp_dir() . '/fake_llm_state.json';
$s = @json_decode(@file_get_contents($state), true);
if (!is_array($s)) { $s = array('mode' => 'ok', 'calls' => 0, 'fail_first' => 0); }
$s['calls']++;
@file_put_contents($state, json_encode($s));

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
    echo json_encode(array(
        'choices' => array(array('message' => array('role' => 'assistant', 'content' => 'ЧАСТИЧНЫЙ УЧЁТ'))),
        'usage' => array('prompt_tokens' => 5, 'completion_tokens' => 4),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$last = '';
$system = '';
$tool_seen = '';
if (isset($raw['messages']) && is_array($raw['messages'])) {
    foreach ($raw['messages'] as $m) {
        if (!isset($m['role'])) { continue; }
        if ($m['role'] === 'user') { $last = $m['content']; }
        if ($m['role'] === 'system') { $system = $m['content']; }
        if ($m['role'] === 'tool') { $tool_seen = $m['content']; }
    }
}

/* Двухфазный конвейер: сначала модель просит записать поля, затем — по
   результату инструмента — продолжает диалог обычным текстом. */
if ($mode === 'tool_call') {
    if ($s['calls'] === 1) {
        echo json_encode(array(
            'choices' => array(array('message' => array(
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array(array(
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => array(
                        'name' => 'update_profile_fields',
                        'arguments' => json_encode(
                            isset($s['tool_args']) ? $s['tool_args'] : array(),
                            JSON_UNESCAPED_UNICODE
                        ),
                    ),
                )),
            ))),
            'usage' => array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => 'ЗАПИСАНО. Результат инструмента: ' . $tool_seen,
        ))),
        'usage' => array('prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'tool_call_always') {
    /* Модель просит инструмент на каждом вызове: проверяем, что конвейер
       не уходит в цикл и пользователь не видит пустой ответ. */
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => array(array(
                'id' => 'call_' . $s['calls'],
                'type' => 'function',
                'function' => array(
                    'name' => 'update_profile_fields',
                    'arguments' => json_encode(
                        isset($s['tool_args']) ? $s['tool_args'] : array(),
                        JSON_UNESCAPED_UNICODE
                    ),
                ),
            )),
        ))),
        'usage' => array('prompt_tokens' => 4, 'completion_tokens' => 4, 'total_tokens' => 8),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'echo_tools') {
    /* Отдаём, какие инструменты пришли в запросе — так тест доказывает, что
       описание инструмента реально доезжает до модели. */
    $names = array();
    if (isset($raw['tools']) && is_array($raw['tools'])) {
        foreach ($raw['tools'] as $t) {
            if (isset($t['function']['name'])) { $names[] = $t['function']['name']; }
        }
    }
    $props = array();
    if (isset($raw['tools'][0]['function']['parameters']['properties'])) {
        $props = array_keys($raw['tools'][0]['function']['parameters']['properties']);
    }
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => 'ИНСТРУМЕНТЫ: ' . implode(',', $names) . ' ПОЛЯ: ' . implode(',', $props),
        ))),
        'usage' => array('prompt_tokens' => 3, 'completion_tokens' => 3, 'total_tokens' => 6),
    ), JSON_UNESCAPED_UNICODE);
    exit;
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


def set_fake_mode(mode, fail_first=0, tool_args=None):
    # chmod 0666: файл пишет и CLI-PHP (root), и веб-сервер (www-data) —
    # без этого счётчик попыток не увеличивается и ретраи не проверить
    state = {"mode": mode, "calls": 0, "fail_first": fail_first}
    if tool_args is not None:
        state["tool_args"] = tool_args
    payload = base64.b64encode(
        json.dumps(state, ensure_ascii=False).encode("utf-8")).decode("ascii")
    web("php", "-r",
        "$f=sys_get_temp_dir().'/fake_llm_state.json';"
        "file_put_contents($f, base64_decode('%s')); @chmod($f, 0666);" % payload)


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


def use_fake_provider(rate_limit=1000):
    setting("provider", "openrouter")
    setting("api_key", FAKE_KEY)
    setting("base_url", FAKE_BASE)
    setting("model", "test/model")
    setting("max_retries", 2)
    setting("rate_limit_per_hour", rate_limit)


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


# --- общие HTTP-помощники ----------------------------------------------------

BASE = "http://localhost:8080"
PASSWORD = "shiduh"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def login(email, password=PASSWORD):
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
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("не удалось авторизоваться: " + email)


def post_json(path, sid, fields, origin=None, timeout=180):
    """POST формы → (код, разобранный JSON). Список пар допустим (массивы в PHP)."""
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if origin:
        req.add_header("Origin", origin)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=timeout)
        raw, code = r.read().decode("utf-8", "replace"), r.status
    except urllib.error.HTTPError as e:
        raw, code = e.read().decode("utf-8", "replace"), e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, {"_raw": raw}
