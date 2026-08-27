<?php
/*
 * Фидбек шадхана по паре (ТЗ Э3.3-b).
 *
 * POST {anketa_id, target_id, action, reason?}, только шадхан, ведущий
 * anketa_id. action: proposed | rejected. Пара хранится нормализованно
 * (меньший id первым), повторное решение перезаписывает прошлое.
 *
 * Отклонённая пара исключается из выдачи подбора этого шадхана; причина
 * отклонения понижает вес соответствующего компонента (эвристика в
 * match_weights()).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/match_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

shiduh_check_origin();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}
if ((int)$db->get_var("SELECT `Type` FROM `User` WHERE `User_ID`=$uid") !== 2) {
    shiduh_json(403, array('error' => 'Доступно только шадхану'));
}

$anketa_id = (int)$nc_core->input->fetch_post('anketa_id');
$target_id = (int)$nc_core->input->fetch_post('target_id');
$action = (string)$nc_core->input->fetch_post('action');
$reason = trim((string)$nc_core->input->fetch_post('reason'));

if (!in_array($action, array('proposed', 'rejected'), true)) {
    shiduh_json(400, array('error' => 'Неизвестное действие'));
}
// причина — только из фиксированного списка (по ней работает эвристика весов)
$allowed_reasons = array('', 'values', 'love_language', 'geography', 'age', 'other');
if (!in_array($reason, $allowed_reasons, true)) {
    shiduh_json(400, array('error' => 'Причина вне списка'));
}
if ($anketa_id <= 0 || $target_id <= 0 || $anketa_id === $target_id) {
    shiduh_json(400, array('error' => 'Некорректная пара'));
}

$anketa = $db->get_row(
    "SELECT `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3` "
    . "FROM `Message204` WHERE `Message_ID`=$anketa_id AND `Checked`=1",
    ARRAY_A
);
if (!$anketa) {
    shiduh_json(404, array('error' => 'Анкета не найдена'));
}
$leads = in_array($uid, array(
    (int)$anketa['User_ID'], (int)$anketa['Shadhan_1'],
    (int)$anketa['Shadhan_2'], (int)$anketa['Shadhan_3'],
), true);
if (!$leads) {
    shiduh_json(403, array('error' => 'Эта анкета не в вашем ведении'));
}
if (!$db->get_var("SELECT `Message_ID` FROM `Message204` WHERE `Message_ID`=$target_id AND `Checked`=1")) {
    shiduh_json(404, array('error' => 'Вторая анкета пары не найдена'));
}

// пара нормализуется: (X,Y) и (Y,X) — одно решение
$a = min($anketa_id, $target_id);
$b = max($anketa_id, $target_id);

// повторное решение перезаписывает прошлое (UNIQUE pair_shadhan)
$db->query(
    "INSERT INTO `match_feedback` SET "
    . "`anketa_a`=$a, `anketa_b`=$b, `shadhan_id`=$uid, "
    . "`action`='" . $db->prepare($action) . "', "
    . "`reason`='" . $db->prepare($reason) . "', "
    . "`created_at`=NOW() "
    . "ON DUPLICATE KEY UPDATE "
    . "`action`=VALUES(`action`), `reason`=VALUES(`reason`), `created_at`=NOW()"
);
if ($db->is_error) {
    shiduh_json(500, array('error' => 'Не удалось сохранить решение'));
}

shiduh_json(200, array('ok' => true, 'action' => $action));
