<?php
/*
 * Эндпоинт ИИ-чата (Э0.2 + Э1.1): приём сообщения кандидата, вызов модели
 * через клиент LLM (api/ai_client.php) и сохранение диалога в ai_chat_messages.
 *
 * Провайдер выбирается настройкой (Settings, Module='ai'): пока ключа нет —
 * 'stub', и ответ прежний. С ключом переключается на 'openrouter' без правок
 * этого файла.
 *
 * Доступ — только авторизованному пользователю; пишет строго под своим
 * user_id. Экранирование через nc_db()->prepare() (не escape — тот делает
 * stripslashes; AUDIT.md находка №35). CSRF — заслон по Origin/Referer
 * (Э1.1-e): запрос тратит токены и деньги, межсайтовый вызов недопустим.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/ai_client.php';
require_once dirname(__FILE__) . '/ai_tools.php';
require_once dirname(__FILE__) . '/subscription_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ai_chat_json($status, $payload) {
    shiduh_json($status, $payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_chat_json(405, array('error' => 'Метод не поддерживается'));
}

shiduh_check_origin();

global $current_user;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    ai_chat_json(403, array('error' => 'Требуется авторизация'));
}

$db = $nc_core->db;
$message = $nc_core->input->fetch_post('message');
if (!is_string($message)) {
    ai_chat_json(400, array('error' => 'Некорректное сообщение'));
}
$message = trim($message);
if ($message === '') {
    ai_chat_json(400, array('error' => 'Пустое сообщение'));
}
// Разумный предел — чтобы пользователь не раздувал свою историю и не слал
// огромные промпты в LLM (Э1). Мягко обрезаем, а не отклоняем.
$max_len = 4000;
if (mb_strlen($message, 'UTF-8') > $max_len) {
    $message = mb_substr($message, 0, $max_len, 'UTF-8');
}

// Лимит расхода: запрос к модели стоит денег, поэтому ограничиваем частоту
// ДО записи сообщения и до обращения к провайдеру. Защищает баланс и от
// перебора, и от угнанной сессии. Тарифы и платный доступ — Э4.
$cfg = ai_config();
$limit = (int)$cfg['rate_limit_per_hour'];
if ($limit > 0) {
    $sent = (int)$db->get_var(
        "SELECT COUNT(*) FROM `ai_chat_messages` WHERE `user_id`=$uid "
        . "AND `role`='user' AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    if ($sent >= $limit) {
        ai_chat_json(429, array(
            'error' => 'Вы отправили слишком много сообщений за час. '
                     . 'Пожалуйста, продолжите беседу немного позже.',
        ));
    }
}

// Пейволл (Э4-2): у free после N сообщений — блок с окном подписки;
// trial/paid без лимита; заглушка (нет реального ИИ) бесплатна и счётчик
// не копит. Решение принимает ОДИН атомарный UPDATE (subscription_take_slot):
// залп параллельных запросов не проскочит мимо лимита. ДО записи сообщения:
// заблокированное в историю не попадает.
$gate = subscription_gate_chat($db, $uid, $cfg);
if ($gate['billable'] && !subscription_take_slot($db, $uid, $gate['limit'])) {
    ai_chat_json(402, array(
        'error' => 'Бесплатный лимит из ' . (int)$gate['limit'] . ' сообщений исчерпан. '
                 . 'Оформите подписку, чтобы продолжить общение с консультантом.',
        'paywall' => true,
    ));
}

// Сообщение пользователя сохраняем сразу: даже если модель не ответит,
// написанное не пропадёт. (Слот при этом уже занят: упавшая модель не повод
// не считать — попытка потратила запрос к провайдеру.)
$db->query(
    "INSERT INTO `ai_chat_messages` SET "
    . "`user_id`=$uid, `role`='user', "
    . "`content`='" . $db->prepare($message) . "', "
    . "`tokens_used`=0, `created_at`=NOW()"
);

// История диалога для модели: последние сообщения этого пользователя.
// Ограничиваем, чтобы промпт не рос бесконечно (и не дорожал).
$history_limit = 20;
$db->query(
    "SELECT `role`, `content` FROM `ai_chat_messages` WHERE `user_id`=$uid "
    . "ORDER BY `id` DESC LIMIT $history_limit",
    ARRAY_A
);
$rows = array_reverse((array)$db->last_result);
// Системный промпт задаёт роль консультанта (в Э1.2 — версионируемый из админки)
$messages = array(array('role' => 'system', 'content' => ai_system_prompt()));
foreach ($rows as $r) {
    $messages[] = array('role' => $r['role'], 'content' => $r['content']);
}

// Инструмент автозаполнения анкеты (Э1.3): модель может попросить записать
// то, что человек рассказал о себе.
$res = ai_chat($messages, array('tools' => ai_tool_definitions()));

if (!$res['ok']) {
    // Ответа нет — честно говорим об этом, сообщение пользователя уже сохранено.
    ai_chat_json(503, array('error' => $res['error'] ?: 'Сервис ИИ временно недоступен'));
}

$tokens = (int)$res['usage']['total_tokens'];

// Второй шаг конвейера: применяем запрошенные записи и возвращаем модели
// отчёт, чтобы она продолжила диалог естественно, а не оборвалась.
if (!empty($res['tool_calls'])) {
    $messages[] = array(
        'role' => 'assistant',
        'content' => (string)$res['content'],
        'tool_calls' => $res['tool_calls'],
    );
    foreach ($res['tool_calls'] as $call) {
        $name = isset($call['function']['name']) ? $call['function']['name'] : '';
        $args = array();
        if (isset($call['function']['arguments'])) {
            $decoded = json_decode((string)$call['function']['arguments'], true);
            if (is_array($decoded)) {
                $args = $decoded;
            }
        }
        $report = ($name === 'update_profile_fields')
            ? ai_tool_apply($db, $uid, $args)
            : array('applied' => array(), 'skipped' => array('_' => 'неизвестный инструмент'));

        $messages[] = array(
            'role' => 'tool',
            'tool_call_id' => isset($call['id']) ? $call['id'] : '',
            'name' => $name,
            'content' => json_encode($report, JSON_UNESCAPED_UNICODE),
        );
    }

    // Второй вызов — последний: инструменты передаём, но повторный tool_call
    // уже не исполняем, иначе диалог мог бы уйти в цикл вызовов за деньги.
    $res = ai_chat($messages, array('tools' => ai_tool_definitions()));
    if (!$res['ok']) {
        // Поля уже записаны — сообщаем о сбое, но сделанное не откатываем.
        ai_chat_json(503, array('error' => $res['error'] ?: 'Сервис ИИ временно недоступен'));
    }
    $tokens += (int)$res['usage']['total_tokens'];
}

// Модель может вернуть пустой текст (например, снова попросив инструмент) —
// не показываем пользователю пустой пузырь.
if (trim((string)$res['content']) === '') {
    $res['content'] = 'Записал, спасибо. Расскажите ещё что-нибудь о себе.';
}

$db->query(
    "INSERT INTO `ai_chat_messages` SET "
    . "`user_id`=$uid, `role`='assistant', "
    . "`content`='" . $db->prepare($res['content']) . "', "
    . "`tokens_used`=$tokens, `created_at`=NOW()"
);

ai_chat_json(200, array('reply' => $res['content'], 'tokens' => $tokens));
