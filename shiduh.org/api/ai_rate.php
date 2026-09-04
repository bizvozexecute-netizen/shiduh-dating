<?php
/*
 * Оценка ответа консультанта (этап «До идеала», B7).
 *
 * Понять, где модель отвечает мимо, до сих пор можно было единственным
 * способом — читать чужие переписки целиком. Это и долго, и неприлично: в
 * них человек рассказывает о себе живому шадхану, а не аудитории. Палец
 * вверх/вниз даёт тот же сигнал, не открывая содержания: администратору
 * видно, сколько ответов помечены плохими, и заглядывать глубже есть смысл
 * только по ним.
 *
 * Чужое сообщение и собственная реплика получают ОДИНАКОВЫЙ отказ 404.
 * Разные коды позволили бы перебором узнать, какие id вообще существуют и
 * кому принадлежат, — а id здесь сквозные по всем пользователям.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';

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
$input = $nc_core->input;

$message_id = (int)$input->fetch_post('message_id');
if ($message_id <= 0) {
    shiduh_json(400, array('error' => 'Не указан ответ', 'code' => 'no_message'));
}

/*
 * Три допустимых значения перечислены явно, а не приведены к (int): «5» и
 * «хорошо» иначе стали бы 5 и 0, то есть мусором и случайным снятием оценки.
 */
$raw = (string)$input->fetch_post('rating');
$allowed = array('1' => 1, '-1' => -1, '0' => 0);
if (!isset($allowed[$raw])) {
    shiduh_json(400, array(
        'error' => 'Недопустимая оценка',
        'code' => 'bad_rating',
    ));
}
$rating = $allowed[$raw];

// Оценивается ответ КОНСУЛЬТАНТА и только свой. Проверяем выборкой, а не по
// числу изменённых строк: повторная та же оценка не меняет ни одной строки,
// и её приняли бы за «сообщения нет».
$own = (int)$db->get_var(
    "SELECT COUNT(*) FROM `ai_chat_messages` "
    . "WHERE `id`=$message_id AND `user_id`=$uid AND `role`='assistant'"
);
if (!$own) {
    shiduh_json(404, array('error' => 'Ответ не найден', 'code' => 'not_found'));
}

$db->query(
    "UPDATE `ai_chat_messages` SET `rating`=" . ($rating === 0 ? 'NULL' : $rating)
    . " WHERE `id`=$message_id AND `user_id`=$uid"
);
if ($db->is_error) {
    shiduh_log('ai', 'не сохранена оценка ответа ' . $message_id . ', пользователь ' . $uid);
    shiduh_json(500, array('error' => 'Не удалось сохранить оценку', 'code' => 'db'));
}

shiduh_json(200, array(
    'ok' => true,
    'message_id' => $message_id,
    'rating' => $rating === 0 ? null : $rating,
));
