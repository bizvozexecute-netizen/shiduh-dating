<?php
/*
 * Эндпоинт ИИ-чата (Э0.2 + Э1.1): приём сообщения кандидата, вызов модели
 * через клиент LLM (api/ai_client.php) и сохранение диалога в ai_chat_messages.
 *
 * Сам конвейер живёт в api/chat_lib.php: здесь только проверки на входе и
 * форма ответа. Так тот же конвейер сможет отдавать ответ потоком (B3), не
 * дублируя логику.
 *
 * Провайдер выбирается настройкой (Settings, Module='ai'): пока ключа нет —
 * 'stub', и ответ прежний. С ключом переключается на 'openrouter' без правки
 * этого файла.
 *
 * Доступ — только авторизованному; пишет строго под своим user_id.
 * Экранирование через prepare() (не escape — тот делает stripslashes,
 * AUDIT №35). CSRF — общий заслон shiduh_csrf_check: запрос тратит деньги.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/chat_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

global $current_user;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}

shiduh_csrf_check($uid);   // заслон CSRF: источник + токен (C3)

$db = $nc_core->db;
$input = $nc_core->input;

// Проверки по возрастанию цены: формат → лимиты → замок → модель.
$check = chat_validate_message($input->fetch_post('message'));
if (!$check['ok']) {
    shiduh_json($check['http'], array('error' => $check['message'], 'code' => $check['code']));
}

$limits = chat_limits_check($db, $uid, ai_config());
if (!$limits['ok']) {
    shiduh_json($limits['http'], array('error' => $limits['message'], 'code' => $limits['code']));
}

// Один незавершённый ответ на пользователя: две вкладки иначе жгут два
// платных вызова разом и путают порядок реплик.
if (!chat_lock_acquire($db, $uid)) {
    shiduh_json(409, array(
        'error' => 'Дождитесь ответа на предыдущее сообщение.',
        'code' => 'busy',
    ));
}
// замок снимается и при фатале: иначе пользователь остался бы заблокирован
register_shutdown_function(function () use ($db, $uid) {
    chat_lock_release($db, $uid);
});

$thread = (int)$input->fetch_post('thread');
if ($thread <= 0) {
    $thread = chat_current_thread($db, $uid);
}

/*
 * Потоковый режим включается заголовком Accept или полем stream=1. Всё, что
 * может отказать дёшево (метод, права, длина, лимиты, замок), проверено выше и
 * отвечает обычным JSON с настоящим кодом: клиенту не придётся разбирать
 * события ради «слишком длинно». Поток открывается только когда работа
 * действительно начинается.
 */
$accept = isset($_SERVER['HTTP_ACCEPT']) ? (string)$_SERVER['HTTP_ACCEPT'] : '';
$want_stream = (stripos($accept, 'text/event-stream') !== false)
            || ((string)$input->fetch_post('stream') === '1');

$emit = $want_stream ? chat_emitter_sse() : chat_emitter_json();
if ($want_stream) {
    chat_stream_begin();
}

$result = chat_run($db, $uid, array(
    'text' => $check['text'],
    'thread' => $thread,
    'retry_of' => (int)$input->fetch_post('retry_of'),
), $emit);

chat_lock_release($db, $uid);

if ($want_stream) {
    if (empty($result['ok'])) {
        chat_emit($emit, 'error', array(
            'code' => $result['code'],
            'message' => $result['message'],
            'retryable' => !empty($result['retryable']),
            'partial' => !empty($result['partial']),
            'user_message_id' => isset($result['user_message_id']) ? $result['user_message_id'] : 0,
        ));
        exit;
    }
    $collected = call_user_func($emit['collected']);
    chat_emit($emit, 'done', array(
        'reply' => $result['reply'],
        'tokens' => $result['tokens'],
        'message_id' => $result['message_id'],
        'user_message_id' => $result['user_message_id'],
        'created_at' => $result['created_at'],
        'thread' => $result['thread'],
        'stopped' => !empty($result['stopped']),
        'tool_events' => $collected['tool_events'],
    ));
    exit;
}

if (empty($result['ok'])) {
    shiduh_json($result['http'], array(
        'error' => $result['message'],
        'code' => $result['code'],
        'retryable' => !empty($result['retryable']),
        // сообщение пользователя уже сохранено — клиент повторит по этому id
        // и не создаст дубль
        'user_message_id' => isset($result['user_message_id']) ? $result['user_message_id'] : 0,
    ));
}

$collected = call_user_func($emit['collected']);
shiduh_json(200, array(
    'ok' => true,
    // reply и tokens сохраняем: на них опираются прежние тесты и клиент
    'reply' => $result['reply'],
    'tokens' => $result['tokens'],
    'message_id' => $result['message_id'],
    'user_message_id' => $result['user_message_id'],
    'created_at' => $result['created_at'],
    'thread' => $result['thread'],
    'tool_events' => $collected['tool_events'],
));
