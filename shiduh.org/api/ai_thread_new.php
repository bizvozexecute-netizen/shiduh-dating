<?php
/*
 * «Новый диалог» (этап «До идеала», B7).
 *
 * Переписка с консультантом росла одной бесконечной лентой. Человек,
 * вернувшийся через месяц с другой темой, тащил в промпт двадцать реплик
 * прошлого разговора: модель отвечала «по мотивам» старого, а начать с
 * чистого листа было нельзя.
 *
 * Новый диалог — следующий номер треда, а не флаг «в архиве»: флаг требовал
 * бы UPDATE по всем строкам пользователя (MyISAM блокирует таблицу целиком)
 * и слил бы прошлые разговоры в одну кучу. Номер оставляет их раздельно
 * читаемыми.
 *
 * Повторное нажатие не создаёт второй пустой тред: пока в текущем нет ни
 * одной реплики человека, «новый диалог» — это он и есть.
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

// Тот же замок, что и у переписки: две вкладки иначе создадут два треда и
// два приветствия.
if (!chat_lock_acquire($db, $uid)) {
    shiduh_json(409, array('error' => 'Подождите, идёт обработка', 'code' => 'busy'));
}
register_shutdown_function(function () use ($db, $uid) {
    chat_lock_release($db, $uid);
});

$current = chat_current_thread($db, $uid);
$has_own_words = (int)$db->get_var(
    "SELECT COUNT(*) FROM `ai_chat_messages` WHERE `user_id`=$uid "
    . "AND `thread`=" . (int)$current . " AND `role`='user'"
);

if ($has_own_words) {
    /*
     * Предел на новые разговоры в сутки. Каждый начинается приветствием, а с
     * живым ключом это платный вызов; без предела кнопка превращается в
     * способ жечь бюджет в один клик.
     */
    if (chat_threads_started_since($db, $uid, 24) >= CHAT_THREADS_PER_DAY) {
        chat_lock_release($db, $uid);
        shiduh_json(429, array(
            'error' => 'Вы начали слишком много новых диалогов за сутки. '
                     . 'Продолжите текущий или вернитесь позже.',
            'code' => 'thread_limit',
        ));
    }
    $current = $current + 1;
}

$result = chat_greet($db, $uid, $current);
chat_lock_release($db, $uid);

shiduh_json(200, array(
    'ok' => true,
    'thread' => $current,
    'created' => $result['created'],
    'message' => $result['message'],
    'chips' => chat_chips($db, $uid, array('thread' => $current)),
));
