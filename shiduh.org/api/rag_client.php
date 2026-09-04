<?php
/*
 * Клиент RAG-ретривера (ТЗ Э2.1-d). Отдельный Python-сервис (rag/service)
 * ищет релевантные фрагменты книг; здесь — только HTTP-вызов.
 *
 * Настройки (Settings, Module='ai'):
 *   rag_url     — адрес сервиса ('' = RAG выключен, консультант работает
 *                 без фрагментов; сайт не зависит от живости ретривера)
 *   rag_top_k   — сколько фрагментов брать (по умолчанию 3)
 *   rag_timeout — таймаут запроса, сек (по умолчанию 3: ретривер локальный,
 *                 ждать дольше — задерживать ответ консультанта).
 *                 ВНИМАНИЕ: при переключении сервиса на реальные эмбеддинги
 *                 (RAG_EMBEDDER=openrouter, Э2.1-b) каждый /retrieve делает
 *                 сетевой вызов к провайдеру — rag_timeout нужно поднять,
 *                 иначе RAG молча перестанет работать целиком
 *
 * Любой сбой = null: лог для админа, пользователю — обычный ответ без
 * фрагментов. Ключей и персональных данных здесь нет — уходит только текст
 * сообщения-запроса, и только на НАШ сервис.
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

function rag_config() {
    $nc = nc_core::get_object();
    $get = function ($key, $default) use ($nc) {
        $v = $nc->get_settings($key, 'ai', false, 0);
        // без строк у модуля ядро отвечает array(), а не false — см. ai_config()
        return (!is_scalar($v) || $v === false || $v === '') ? $default : $v;
    };
    return array(
        'url' => rtrim((string)$get('rag_url', ''), '/'),
        'top_k' => max(1, min(10, (int)$get('rag_top_k', 3))),
        'timeout' => max(1, (int)$get('rag_timeout', 3)),
    );
}

/*
 * top-k фрагментов по запросу: array строк-результатов (book, content, ...)
 * или null (выключено/сбой).
 */
function rag_retrieve($query) {
    $cfg = rag_config();
    if ($cfg['url'] === '') {
        return null;
    }
    $query = trim((string)$query);
    if ($query === '') {
        return null;
    }

    $ch = curl_init($cfg['url'] . '/retrieve');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => $cfg['timeout'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        // INVALID_UTF8_SUBSTITUTE: битый UTF-8 в сообщении не должен давать
        // false вместо тела запроса (тогда FastAPI ответил бы 422)
        CURLOPT_POSTFIELDS => json_encode(
            array('query' => mb_substr($query, 0, 2000), 'k' => $cfg['top_k']),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code !== 200) {
        // адрес сервиса — только в лог: пользователю сбой ретривера не виден
        shiduh_log('rag', 'ретривер недоступен: http ' . $code
            . ($err !== '' ? ', curl: ' . $err : ''));
        return null;
    }
    $js = json_decode((string)$body, true);
    if (!is_array($js) || !isset($js['results']) || !is_array($js['results'])) {
        shiduh_log('rag', 'неожиданный ответ ретривера: ' . substr((string)$body, 0, 300));
        return null;
    }
    return $js['results'];
}
