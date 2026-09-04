<?php
/*
 * Первая реплика консультанта (этап «До идеала», B5).
 *
 * Человек открывал чат и видел чистый лист с приглашением «напишите
 * сообщение». О чём писать — непонятно, и разговор не начинался.
 *
 * Почему отдельный эндпоинт, а не рендер страницы: страница отвечает на GET и
 * не должна ничего записывать и тем более обращаться к модели — иначе простое
 * обновление вкладки создавало бы записи и (с живым ключом) тратило деньги.
 * Клиент зовёт этот эндпоинт один раз, когда видит пустой тред.
 *
 * Идемпотентно: если в треде уже есть сообщения, возвращает первое и ничего
 * не создаёт.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
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

shiduh_csrf_check($uid);

$db = $nc_core->db;
$thread = (int)$nc_core->input->fetch_post('thread');
if ($thread <= 0) {
    $thread = chat_current_thread($db, $uid);
}

// Замок общий с обычной отправкой: два одновременных открытия страницы иначе
// создали бы два приветствия.
if (!chat_lock_acquire($db, $uid)) {
    shiduh_json(409, array('error' => 'Подождите, идёт обработка', 'code' => 'busy'));
}
register_shutdown_function(function () use ($db, $uid) {
    chat_lock_release($db, $uid);
});

$result = chat_greet($db, $uid, $thread);
chat_lock_release($db, $uid);

shiduh_json(200, array(
    'ok' => true,
    'created' => $result['created'],
    'message' => $result['message'],
    'chips' => chat_chips($db, $uid, array('thread' => $thread)),
    'thread' => $thread,
));
