<?php
/*
 * Проверка подключения (C9): «дотягиваемся ли мы до провайдера с этим
 * ключом» и «жив ли RAG-сервис». Без неё администратор узнавал об ошибке в
 * ключе только из жалоб кандидатов на заглушку.
 *
 * POST и CSRF-заслон, хотя состояние не меняется: вызов ходит наружу с
 * ключом, и запускать его чужой страницей нельзя. Подробности отказа —
 * только в лог: ответ провайдера может содержать фрагмент ключа или
 * внутренние адреса.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/ai_client.php';
require_once dirname(__FILE__) . '/rag_client.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}
shiduh_csrf_check($uid);
shiduh_require_admin($db, $uid);

$target = (string)$nc_core->input->fetch_post('target');

if ($target === 'provider') {
    $cfg = ai_config();
    if ($cfg['provider'] !== 'openrouter') {
        shiduh_json(200, array('ok' => false, 'code' => 'stub',
            'error' => 'Провайдер — заглушка: подключения нет, консультант отвечает шаблоном.'));
    }
    if ($cfg['api_key'] === '') {
        shiduh_json(200, array('ok' => false, 'code' => 'no_key', 'error' => 'Ключ API не задан.'));
    }
    $secrets = array($cfg['api_key']);
    $t0 = microtime(true);

    /*
     * У OpenRouter список моделей публичный — GET /models ответит 200 и с
     * битым ключом, и кнопка врала бы «связь есть». Там ключ проверяет
     * /auth/key (401 при плохом, бесплатно). У остальных OpenAI-совместимых
     * /models сам требует ключ.
     */
    $host = strtolower((string)parse_url($cfg['base_url'], PHP_URL_HOST));
    if ($host === 'openrouter.ai' || substr($host, -13) === '.openrouter.ai') {
        list($kcode, $kbody, $kerr) = ai_http_get($cfg['base_url'] . '/auth/key', $cfg['api_key'], 5);
        if ($kcode !== 200) {
            $ms = (int)round((microtime(true) - $t0) * 1000);
            shiduh_log('admin', 'проверка ключа OpenRouter не прошла: http ' . $kcode . ' '
                . ai_usage_scrub((string)$kerr, $secrets) . ' ' . ai_usage_scrub((string)$kbody, $secrets));
            shiduh_json(200, array('ok' => false, 'latency_ms' => $ms, 'http' => $kcode,
                'code' => $kcode === 401 ? 'unauthorized' : ($kcode === 0 ? 'unreachable' : 'http_error'),
                'error' => $kcode === 401 ? 'Провайдер отверг ключ (401).'
                    : ($kcode === 0 ? 'Провайдер недоступен: не удалось соединиться.' : 'Провайдер ответил ошибкой ' . $kcode . '.')));
        }
    }

    list($code, $body, $err) = ai_http_get($cfg['base_url'] . '/models', $cfg['api_key'], 5);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $js = json_decode((string)$body, true);
    // 200 без списка моделей (страница прокси, {"error":…}) — это не связь, а подмена
    if ($code === 200 && is_array($js) && isset($js['data']) && is_array($js['data'])) {
        $count = count($js['data']);
        shiduh_json(200, array('ok' => true, 'latency_ms' => $ms, 'models_count' => $count,
            'model_known' => $count > 0 ? ai_ping_model_listed($js['data'], $cfg['model']) : null));
    }
    shiduh_log('admin', 'проверка провайдера не прошла: http ' . $code . ' ' . ai_usage_scrub((string)$err, $secrets)
        . ' ' . ai_usage_scrub((string)$body, $secrets));
    if ($code === 200) {
        shiduh_json(200, array('ok' => false, 'latency_ms' => $ms, 'http' => 200, 'code' => 'bad_body',
            'error' => 'Адрес отвечает, но это не OpenAI-совместимый API: списка моделей в ответе нет.'));
    }
    shiduh_json(200, array('ok' => false, 'latency_ms' => $ms, 'http' => $code,
        'code' => $code === 401 ? 'unauthorized' : ($code === 0 ? 'unreachable' : 'http_error'),
        'error' => $code === 401
            ? 'Провайдер отверг ключ (401).'
            : ($code === 0 ? 'Провайдер недоступен: не удалось соединиться.' : 'Провайдер ответил ошибкой ' . $code . '.')));
}

if ($target === 'rag') {
    $rag = rag_config();
    if ($rag['url'] === '') {
        shiduh_json(200, array('ok' => false, 'code' => 'off', 'error' => 'RAG выключен: адрес не задан.'));
    }
    $t0 = microtime(true);
    list($code, $body, $err) = ai_http_get($rag['url'] . '/health', '', min(5, max(1, (int)$rag['timeout'])));
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $js = json_decode((string)$body, true);
    if ($code === 200 && is_array($js)) {
        shiduh_json(200, array('ok' => true, 'latency_ms' => $ms,
            'chunks' => isset($js['chunks']) ? (int)$js['chunks'] : null,
            'embedder' => isset($js['embedder']) ? (string)$js['embedder'] : null));
    }
    shiduh_log('admin', 'проверка RAG не прошла: http ' . $code . ' ' . ai_usage_scrub((string)$err));
    shiduh_json(200, array('ok' => false, 'latency_ms' => $ms, 'http' => $code,
        'code' => $code === 0 ? 'unreachable' : 'http_error',
        'error' => $code === 0 ? 'RAG-сервис недоступен.' : 'RAG-сервис ответил ошибкой ' . $code . '.'));
}

shiduh_json(400, array('error' => 'Неизвестная цель проверки', 'code' => 'bad_target'));

/* Есть ли настроенная модель в списке провайдера. */
function ai_ping_model_listed(array $data, $model) {
    foreach ($data as $m) {
        if (isset($m['id']) && (string)$m['id'] === (string)$model) {
            return true;
        }
    }
    return false;
}
