<?php
/*
 * Подбор пар для анкеты (ТЗ Э3.1 + Э3.2-a).
 *
 * POST, только шадхан, ведущий анкету. Возвращает пары, прошедшие жёсткие
 * фильтры, по убыванию балла с разбивкой по компонентам.
 *
 * Денег не тратит: и фильтры, и ранжирование считает код (обоснование от
 * LLM появится в Э3.3 и будет кэшироваться).
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
if ($anketa_id <= 0) {
    shiduh_json(400, array('error' => 'Не указана анкета'));
}

$anketa = $db->get_row(
    "SELECT `Message_ID`, `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3`, "
    . "`Gender`, `Date_of_birth`, `City`, `Are_you_ready_to_move`, "
    . "`Search_age_start`, `Search_age_end`, `Kashrut_level`, `Shabbat_level`, "
    . "`Psychological_profile` "
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

// limit опционален (по умолчанию 50, не больше 500): шадхану — листать
// глубже, тестам — видеть полную выборку, а не хвост топ-50.
$limit = (int)$nc_core->input->fetch_post('limit');
if ($limit <= 0) {
    $limit = 50;
}
$limit = min(500, $limit);

shiduh_json(200, array(
    'ok' => true,
    'anketa_id' => $anketa_id,
    'matches' => match_list($db, $anketa, $limit),
));
