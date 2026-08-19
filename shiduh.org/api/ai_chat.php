<?php
/*
 * Эндпоинт ИИ-чата (Э0.2): приём сообщения кандидата и сохранение диалога
 * в ai_chat_messages. На этой фазе ассистент отвечает заглушкой — живой
 * диалог подключается в Э1 (здесь же появится вызов LLMClient вместо stub).
 *
 * Доступ — только авторизованному пользователю; пишет строго под своим
 * user_id. Экранирование через nc_db()->prepare() (не escape — тот делает
 * stripslashes; AUDIT.md находка №35).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ai_chat_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_chat_json(405, array('error' => 'Метод не поддерживается'));
}

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

// Сообщение пользователя
$db->query(
    "INSERT INTO `ai_chat_messages` SET "
    . "`user_id`=$uid, `role`='user', "
    . "`content`='" . $db->prepare($message) . "', "
    . "`tokens_used`=0, `created_at`=NOW()"
);

// Ответ-заглушка. В Э1 заменяется вызовом LLM по истории диалога.
$stub = 'Спасибо! Я сохранил ваше сообщение. Живой ИИ-консультант подключится '
      . 'на следующем этапе и поможет дозаполнить анкету в диалоге.';
$db->query(
    "INSERT INTO `ai_chat_messages` SET "
    . "`user_id`=$uid, `role`='assistant', "
    . "`content`='" . $db->prepare($stub) . "', "
    . "`tokens_used`=0, `created_at`=NOW()"
);

ai_chat_json(200, array('reply' => $stub));
