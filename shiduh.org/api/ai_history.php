<?php
/*
 * История переписки с консультантом постранично (этап «До идеала», B2).
 *
 * Раньше страница читала ВСЮ переписку без LIMIT и печатала её целиком: у
 * активного пользователя разметка растёт без предела, а «показать ранее»
 * было неоткуда взять. Теперь страница печатает хвост, а остальное клиент
 * догружает отсюда.
 *
 * Только чтение и только своё: тред выбирается в пределах своего user_id,
 * поэтому подобрать чужую переписку номером треда нельзя. Метод GET,
 * состояние не меняется — токен CSRF не требуется.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/chat_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

global $current_user;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}

$db = $nc_core->db;
$in = $nc_core->input;

$thread = (int)$in->fetch_get('thread');
if ($thread <= 0) {
    $thread = chat_current_thread($db, $uid);
}

// Список тредов: для переключателя «прошлые диалоги»
if ((string)$in->fetch_get('threads') === '1') {
    $db->query(
        "SELECT `thread`, COUNT(*) AS `count`, MIN(`created_at`) AS `first_at`, "
        . "MAX(`created_at`) AS `last_at` FROM `ai_chat_messages` "
        . "WHERE `user_id`=$uid GROUP BY `thread` ORDER BY `thread` DESC",
        ARRAY_A
    );
    $threads = array();
    foreach ((array)$db->last_result as $r) {
        $threads[] = array(
            'thread' => (int)$r['thread'],
            'count' => (int)$r['count'],
            'first_at' => (string)$r['first_at'],
            'last_at' => (string)$r['last_at'],
        );
    }
    shiduh_json(200, array('ok' => true, 'current' => chat_current_thread($db, $uid), 'threads' => $threads));
}

$page = chat_history_page(
    $db, $uid, $thread,
    (int)($in->fetch_get('limit') ?: CHAT_PAGE_SIZE),
    (int)$in->fetch_get('before_id'),
    (int)$in->fetch_get('after_id')
);

shiduh_json(200, array(
    'ok' => true,
    'thread' => $thread,
    'messages' => $page['messages'],
    'has_more' => $page['has_more'],
));
