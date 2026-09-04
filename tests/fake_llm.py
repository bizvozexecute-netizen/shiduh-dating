# -*- coding: utf-8 -*-
"""
Общий стенд для тестов ИИ: фейковый LLM в формате OpenAI + помощники.

Фейк живёт ВНЕ веб-корня — его отдаёт встроенный сервер PHP на порту 8099
внутри контейнера. Так забытый после сбоя файл не может уехать на прод
вместе с деревом сайта.

Режимы (set_fake_mode):
  ok              — обычный ответ, эхом последнего сообщения пользователя
  emoji           — ответ с эмодзи (4-байтовый UTF-8): проверка санитайза
  risk_tool       — сигнал внимания (X-3): tool_call report_safety_concern
  case_flow       — вплетение кейса (Э2.2-c): tool_call ответа на кейс ->
                    классификация -> финальная реплика
  echo_system     — эхом системного промпта (проверка, что он доехал)
  echo_tools      — эхом списка полученных инструментов и их полей
  echo_prompt     — эхом всего полученного запроса (проверка приватности)
  echo_payload    — эхо параметров: max_tokens, stream, temperature
  classify        — строгий JSON разбора ответа (скоринг Э2.3)
  tool_call_always— просит инструмент на каждом вызове (проверка отсутствия цикла)
  fail_then_ok    — первые N вызовов отдают 500, потом успех (ретраи)
  always_500      — всегда 500 (отказ после ретраев)
  bad_request     — 400 с «утечкой» ключа и внутреннего URL в тексте
  no_total_tokens — usage без total_tokens (только слагаемые)
  stream_error_mid— поток обрывается ошибкой на середине (break_after)
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

/*
 * Единый выход роутера. Обычный запрос получает JSON как раньше; запрос с
 * stream:true — поток SSE в формате OpenAI, чтобы клиент разбирал настоящий,
 * а не упрощённый ответ.
 */
function fake_emit($content, $usage, $tool_calls, $raw, $s) {
    if (empty($raw['stream'])) {
        $msg = array('role' => 'assistant', 'content' => $content);
        if ($tool_calls) { $msg['tool_calls'] = $tool_calls; }
        echo json_encode(array(
            'choices' => array(array('message' => $msg)),
            'usage' => $usage,
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { ob_end_clean(); }

    $delay = isset($s['chunk_delay_ms']) ? ((int)$s['chunk_delay_ms']) * 1000 : 20000;
    $put = function ($obj) {
        echo 'data: ' . json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };

    // первый чанк — только роль, как это делает настоящий шлюз
    $put(array('choices' => array(array('index' => 0,
        'delta' => array('role' => 'assistant', 'content' => ''), 'finish_reason' => null))));

    if ($tool_calls) {
        foreach ($tool_calls as $i => $call) {
            $put(array('choices' => array(array('index' => 0, 'delta' => array(
                'tool_calls' => array(array(
                    'index' => $i,
                    'id' => isset($call['id']) ? $call['id'] : ('call_' . $i),
                    'type' => 'function',
                    'function' => array('name' => $call['function']['name'], 'arguments' => ''),
                )),
            ), 'finish_reason' => null))));
            // аргументы приходят кусками — парсер обязан их склеивать
            $args = (string)$call['function']['arguments'];
            foreach (str_split($args, max(1, (int)ceil(strlen($args) / 2))) as $piece) {
                usleep($delay);
                $put(array('choices' => array(array('index' => 0, 'delta' => array(
                    'tool_calls' => array(array('index' => $i, 'function' => array('arguments' => $piece))),
                ), 'finish_reason' => null))));
            }
        }
        $put(array('choices' => array(array('index' => 0, 'delta' => new stdClass(),
            'finish_reason' => 'tool_calls'))));
    } else {
        $words = preg_split('/(?<=\s)/u', $content);
        $chunk = '';
        $out = array();
        foreach ($words as $w) {
            $chunk .= $w;
            if (mb_strlen($chunk, 'UTF-8') >= 12) { $out[] = $chunk; $chunk = ''; }
        }
        if ($chunk !== '') { $out[] = $chunk; }
        if (!$out) { $out = array($content); }

        foreach ($out as $n => $piece) {
            usleep($delay);
            if (!empty($s['break_after']) && $n >= (int)$s['break_after']) {
                // обрыв посреди выдачи: ошибка приходит в самом потоке
                $put(array('error' => array('message' => 'boom mid-stream')));
                exit;
            }
            $put(array('choices' => array(array('index' => 0,
                'delta' => array('content' => $piece), 'finish_reason' => null))));
        }
        $put(array('choices' => array(array('index' => 0, 'delta' => new stdClass(),
            'finish_reason' => 'stop'))));
    }

    $put(array('choices' => array(), 'usage' => $usage));
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

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
if (!empty($s['delay_ms'])) {
    usleep(((int)$s['delay_ms']) * 1000);   // имитация медленной модели
}

/* C9. GET /models — проверка подключения из admin-ai.php: список моделей,
   как у OpenAI-совместимых провайдеров. Режим always_500 действует и здесь. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos((string)$_SERVER['REQUEST_URI'], '/auth/key') !== false) {
    /* как у OpenRouter: ключ уже проверен заслоном выше, отдаём его описание */
    echo json_encode(array('data' => array('label' => 'test key', 'usage' => 0)));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos((string)$_SERVER['REQUEST_URI'], '/models') !== false) {
    if ($mode === 'always_500') {
        http_response_code(500);
        echo json_encode(array('error' => array('message' => 'boom, key sk-test-fake leaked?')));
        exit;
    }
    if ($mode === 'models_garbage') {
        /* 200, но не список моделей: страница прокси или капчи */
        header('Content-Type: text/html');
        echo '<html><body>Access denied by proxy. key=sk-test-fake</body></html>';
        exit;
    }
    echo json_encode(array('object' => 'list', 'data' => array(
        array('id' => 'test/model', 'name' => 'Test Model'),
        array('id' => 'test/other', 'name' => 'Other Model'),
    )));
    exit;
}
if ($mode === 'fail_then_ok' && $s['calls'] <= (int)$s['fail_first']) {
    http_response_code(500);
    echo json_encode(array('error' => array('message' => 'temporary')));
    exit;
}
if ($mode === 'emoji') {
    /* Модель вернула эмодзи (4-байтовый UTF-8): база в utf8 (3 байта) молча
       обрезала бы текст — проверяем санитайз перед кэшем (ревью Э3.3-a, Б2). */
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => "\u{1F48D} Отличная пара \u{1F48D} по ценностям",
        ))),
        'usage' => array('prompt_tokens' => 3, 'completion_tokens' => 3, 'total_tokens' => 6),
    ), JSON_UNESCAPED_UNICODE);
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
if (!empty($s['pre_delay_ms']) && !empty($raw['stream'])) {
    // долгое молчание до первого куска: так проверяются служебные
    // строки, которыми поток удерживает соединение
    usleep(((int)$s['pre_delay_ms']) * 1000);
}
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
        fake_emit('', array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
            array(array(
                'id' => 'call_1',
                'type' => 'function',
                'function' => array(
                    'name' => 'update_profile_fields',
                    'arguments' => json_encode(
                        isset($s['tool_args']) ? $s['tool_args'] : array(),
                        JSON_UNESCAPED_UNICODE
                    ),
                ),
            )), $raw, $s);
    }
    fake_emit('ЗАПИСАНО. Результат инструмента: ' . $tool_seen,
        array('prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20),
        array(), $raw, $s);
}

/* B6. Человек согласился на замену — модель подтверждает её инструментом.
   Значения в вызове нет: инструмент принимает только имена полей. */
if ($mode === 'confirm_call') {
    if ($s['calls'] === 1) {
        fake_emit('', array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
            array(array(
                'id' => 'conf_1',
                'type' => 'function',
                'function' => array(
                    'name' => 'confirm_profile_update',
                    'arguments' => json_encode(
                        isset($s['tool_args']) ? $s['tool_args'] : array('fields' => array()),
                        JSON_UNESCAPED_UNICODE
                    ),
                ),
            )), $raw, $s);
    }
    fake_emit('ПОМЕНЯНО. Результат инструмента: ' . $tool_seen,
        array('prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20),
        array(), $raw, $s);
}

/* B6. Модель предлагает замену и тут же сама её подтверждает, ни о чём не
   спросив: проверка, что согласие в одном ходу с предложением не считается. */
if ($mode === 'propose_then_confirm') {
    if ($s['calls'] === 1) {
        $args = isset($s['tool_args']) ? $s['tool_args'] : array();
        fake_emit('', array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
            array(
                array(
                    'id' => 'prop_1',
                    'type' => 'function',
                    'function' => array(
                        'name' => 'update_profile_fields',
                        'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE),
                    ),
                ),
                array(
                    'id' => 'conf_1',
                    'type' => 'function',
                    'function' => array(
                        'name' => 'confirm_profile_update',
                        'arguments' => json_encode(
                            array('fields' => array_keys($args)), JSON_UNESCAPED_UNICODE
                        ),
                    ),
                ),
            ), $raw, $s);
    }
    fake_emit('ГОТОВО. Результат инструмента: ' . $tool_seen,
        array('prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20),
        array(), $raw, $s);
}

if ($mode === 'case_flow') {
    /* Вплетение кейса в диалог (Э2.2-c): 1-й вызов — модель просит записать
       ответ на кейс; 2-й — классификатор (scoring_classify) получает строгий
       JSON; 3-й — финальная реплика по результату инструмента. */
    if ($s['calls'] === 1) {
        /* multi > 1 — parallel tool calls: модель просит записать ответ
           несколько раз за один ход (проверка «не больше одного за запрос») */
        $n = isset($s['multi']) ? max(1, (int)$s['multi']) : 1;
        $calls_arr = array();
        for ($i = 0; $i < $n; $i++) {
            $calls_arr[] = array(
                'id' => 'case_' . ($i + 1),
                'type' => 'function',
                'function' => array(
                    'name' => 'record_case_answer',
                    'arguments' => json_encode(
                        isset($s['tool_args']) ? $s['tool_args'] : array(),
                        JSON_UNESCAPED_UNICODE
                    ),
                ),
            );
        }
        echo json_encode(array(
            'choices' => array(array('message' => array(
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $calls_arr,
            ))),
            'usage' => array('prompt_tokens' => 9, 'completion_tokens' => 5, 'total_tokens' => 14),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($s['calls'] === 2) {
        echo json_encode(array(
            'choices' => array(array('message' => array(
                'role' => 'assistant',
                'content' => json_encode(
                    isset($s['classify']) ? $s['classify'] : array(),
                    JSON_UNESCAPED_UNICODE
                ),
            ))),
            'usage' => array('prompt_tokens' => 6, 'completion_tokens' => 6, 'total_tokens' => 12),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => 'ВПЛЕТЕНО. Результат инструмента: ' . $tool_seen,
        ))),
        'usage' => array('prompt_tokens' => 7, 'completion_tokens' => 7, 'total_tokens' => 14),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'risk_tool') {
    /* X-3: модель поднимает сигнал внимания инструментом, потом договаривает. */
    if ($s['calls'] === 1) {
        fake_emit('', array('prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12),
            array(array(
                'id' => 'risk_1',
                'type' => 'function',
                'function' => array(
                    'name' => 'report_safety_concern',
                    'arguments' => json_encode(
                        isset($s['tool_args']) ? $s['tool_args'] : array('kind' => 'distress'),
                        JSON_UNESCAPED_UNICODE
                    ),
                ),
            )), $raw, $s);
    }
    fake_emit('Понимаю вас. Расскажите, что для вас сейчас важнее всего.',
        array('prompt_tokens' => 6, 'completion_tokens' => 6, 'total_tokens' => 12),
        array(), $raw, $s);
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

if ($mode === 'classify') {
    /* Классификатор одного ответа (Э2.3-a): отдаёт строгий JSON в поле
       content, как это делает модель со structured output. */
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => json_encode(
                isset($s['tool_args']) ? $s['tool_args'] : array(),
                JSON_UNESCAPED_UNICODE
            ),
        ))),
        'usage' => array('prompt_tokens' => 6, 'completion_tokens' => 6, 'total_tokens' => 12),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'echo_prompt') {
    /* Возвращает ВЕСЬ полученный запрос: так тест видит, что реально ушло
       в модель, и может доказать отсутствие идентификаторов. */
    $all = array();
    if (isset($raw['messages']) && is_array($raw['messages'])) {
        foreach ($raw['messages'] as $m) {
            $all[] = (isset($m['role']) ? $m['role'] : '?') . ': '
                   . (isset($m['content']) ? $m['content'] : '');
        }
    }
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => implode(" | ", $all),
        ))),
        'usage' => array('prompt_tokens' => 9, 'completion_tokens' => 9, 'total_tokens' => 18),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'echo_payload') {
    /* Эхо параметров запроса (не сообщений): так тест видит, что реально
       уходит потолок ответа и признак потоковой выдачи. */
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => 'MAXTOK:' . (isset($raw['max_tokens']) ? (int)$raw['max_tokens'] : 0)
                       . ' STREAM:' . (empty($raw['stream']) ? '0' : '1')
                       . ' TEMP:' . (isset($raw['temperature']) ? $raw['temperature'] : '?'),
        ))),
        'usage' => array('prompt_tokens' => 9, 'completion_tokens' => 9, 'total_tokens' => 18),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'echo_tools') {
    /* Отдаём, какие инструменты пришли в запросе — так тест доказывает, что
       описание инструмента реально доезжает до модели. У каждого в скобках
       его параметры: без этого нельзя проверить, чего у инструмента НЕТ. */
    $names = array();
    if (isset($raw['tools']) && is_array($raw['tools'])) {
        foreach ($raw['tools'] as $t) {
            if (!isset($t['function']['name'])) { continue; }
            $p = array();
            if (isset($t['function']['parameters']['properties'])) {
                $p = array_keys($t['function']['parameters']['properties']);
            }
            $names[] = $t['function']['name'] . '(' . implode('|', $p) . ')';
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

if ($mode === 'echo_dialog') {
    /* Весь диалог, который реально ушёл модели: так тест видит, что попало
       в промпт, а что осталось за его границей (например, прошлый тред). */
    $parts = array();
    if (isset($raw['messages']) && is_array($raw['messages'])) {
        foreach ($raw['messages'] as $m) {
            $parts[] = (isset($m['role']) ? $m['role'] : '?') . ': '
                . (isset($m['content']) ? (string)$m['content'] : '');
        }
    }
    echo json_encode(array(
        'choices' => array(array('message' => array(
            'role' => 'assistant',
            'content' => implode(' | ', $parts),
        ))),
        'usage' => array('prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'echo_system') { $last = $system; }
fake_emit('ФЕЙК-ОТВЕТ на: ' . $last,
    array('prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18),
    array(), $raw, $s);
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


def set_fake_mode(mode, fail_first=0, tool_args=None, classify=None, multi=None,
                  delay_ms=0, chunk_delay_ms=0, pre_delay_ms=0, break_after=0):
    # chmod 0666: файл пишет и CLI-PHP (root), и веб-сервер (www-data) —
    # без этого счётчик попыток не увеличивается и ретраи не проверить
    state = {"mode": mode, "calls": 0, "fail_first": fail_first}
    if tool_args is not None:
        state["tool_args"] = tool_args
    if classify is not None:
        state["classify"] = classify
    if multi is not None:
        state["multi"] = multi
    if delay_ms:
        # Пауза перед ответом: так тест видит поведение, пока запрос ещё
        # в работе (замок на параллельную отправку, индикатор ожидания).
        state["delay_ms"] = int(delay_ms)
    if chunk_delay_ms:
        state["chunk_delay_ms"] = int(chunk_delay_ms)   # пауза между кусками потока
    if pre_delay_ms:
        state["pre_delay_ms"] = int(pre_delay_ms)       # молчание до первого куска
    if break_after:
        state["break_after"] = int(break_after)         # обрыв потока ошибкой
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


def use_fake_provider(rate_limit=1000, burst=1000):
    setting("provider", "openrouter")
    setting("api_key", FAKE_KEY)
    setting("base_url", FAKE_BASE)
    setting("model", "test/model")
    setting("max_retries", 2)
    setting("rate_limit_per_hour", rate_limit)
    # Наборы шлют сообщения подряд, без пауз: поминутный лимит (по умолчанию
    # шесть) отсекал бы их с середины. Сам лимит проверяется отдельно, в
    # chat_history_test, где он выставляется намеренно.
    setting("burst_per_minute", burst)



def sse_post(path, sid, fields, timeout=120, abort_after=None, origin=None, csrf=True):
    """POST с Accept: text/event-stream. Читает ответ ПО МЕРЕ ПРИХОДА и
    возвращает (код, тип содержимого, события).

    События — список (время_от_старта, имя, данные). Служебные строки
    (комментарии SSE, которыми поток удерживает соединение) приходят как
    имя 'comment'. abort_after=N — закрыть соединение после N кусков текста,
    так проверяется поведение при нажатии «Остановить».
    """
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    req.add_header("Accept", "text/event-stream")
    browser_headers(req, sid, origin, csrf)

    started = time.time()
    events = []
    try:
        r = urllib.request.urlopen(req, timeout=timeout)
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.code, e.headers.get("Content-Type", ""), [(0.0, "json", json.loads(raw))]
        except ValueError:
            return e.code, e.headers.get("Content-Type", ""), [(0.0, "raw", raw)]

    ctype = r.headers.get("Content-Type", "")
    if "text/event-stream" not in ctype:
        raw = r.read().decode("utf-8", "replace")
        try:
            return r.status, ctype, [(0.0, "json", json.loads(raw))]
        except ValueError:
            return r.status, ctype, [(0.0, "raw", raw)]

    name, data, deltas = None, [], 0
    try:
        for line in r:
            line = line.decode("utf-8", "replace").rstrip("\r\n")
            if line.startswith(":"):
                events.append((time.time() - started, "comment", line[1:].strip()))
                continue
            if line.startswith("event:"):
                name = line[6:].strip()
                continue
            if line.startswith("data:"):
                data.append(line[5:].strip())
                continue
            if line == "" and data:
                payload = "\n".join(data)
                try:
                    payload = json.loads(payload)
                except ValueError:
                    pass
                events.append((time.time() - started, name or "message", payload))
                if name == "delta":
                    deltas += 1
                    if abort_after and deltas >= abort_after:
                        r.close()
                        break
                name, data = None, []
    except Exception:
        pass          # обрыв соединения — штатная часть проверок
    return r.status, ctype, events

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


_CSRF = {}


def csrf_token(sid):
    """Токен CSRF из разметки страницы — так же, как его берёт браузер.

    Метатег стоит в <head> макета, поэтому годится любая страница; берём
    публичную главную с кукой сессии, чтобы работало для всех ролей.
    """
    if sid not in _CSRF:
        req = urllib.request.Request(BASE + "/")
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
        html = urllib.request.urlopen(req, timeout=60).read().decode("utf-8", "replace")
        m = re.search(r'name="csrf-token"[^>]*content="([^"]*)"', html)
        _CSRF[sid] = m.group(1) if m else ""
    return _CSRF[sid]


def browser_headers(req, sid, origin=None, csrf=True):
    """Заголовки, которые настоящий браузер ставит сам: Origin своего сайта и
    X-CSRF-Token из метатега. csrf=False — намеренно без токена (проверка
    заслона), csrf='<строка>' — подсунуть чужой токен."""
    if origin is None:
        origin = BASE
    if origin:
        req.add_header("Origin", origin)
    if csrf and sid:
        token = csrf_token(sid) if csrf is True else csrf
        if token:
            req.add_header("X-CSRF-Token", token)


def get_text(path, sid=None, params="", timeout=120):
    """GET страницы → (код, тело как текст). Для проверок разметки."""
    url = BASE + path + (("?" + params) if params else "")
    req = urllib.request.Request(url)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.urlopen(req, timeout=timeout)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def get_json(path, sid=None, params="", timeout=120):
    """GET → (код, разобранный JSON). Для эндпоинтов, которые только читают."""
    url = BASE + path + (("?" + params) if params else "")
    req = urllib.request.Request(url)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.urlopen(req, timeout=timeout)
        raw, code = r.read().decode("utf-8", "replace"), r.status
    except urllib.error.HTTPError as e:
        raw, code = e.read().decode("utf-8", "replace"), e.code
    try:
        return code, json.loads(raw)
    except ValueError:
        return code, {"_raw": raw[:300]}


def post_json(path, sid, fields, origin=None, timeout=180, csrf=True):
    """POST формы → (код, разобранный JSON). Список пар допустим (массивы в PHP).

    По умолчанию шлёт Origin своего сайта и CSRF-токен — как браузер.
    origin=False (не None!) — совсем без заголовка Origin.
    """
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    browser_headers(req, sid, origin, csrf)
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
