<?php
/*
 * Предпросмотр сегмента рассылки (ROADMAP Р-1, Р-4).
 *
 * POST, только администратор. Фильтры: gender (1/2), age_min, age_max,
 * city (подстрока по анкете). Отвечает количеством адресатов и разбивкой
 * по подтверждённости email — письма уйдут только Confirmed=1 (Р-4).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/mailing_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}


global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!shiduh_is_admin($db, $uid)) {
    shiduh_json(403, array('error' => 'Доступно только администратору'));
}

shiduh_csrf_check($uid);   // заслон CSRF: источник + токен (C3)

list($where, $filters) = mailing_segment_where($db, $nc_core->input);

$row = $db->get_row(
    "SELECT COUNT(*) AS total, COALESCE(SUM(u.`Confirmed`=1), 0) AS confirmed "
    . "FROM `User` u WHERE $where",
    ARRAY_A
);
$total = (int)$row['total'];
$confirmed = (int)$row['confirmed'];

shiduh_json(200, array(
    'ok' => true,
    'total' => $total,
    'confirmed' => $confirmed,
    'unconfirmed' => $total - $confirmed,
    'filters' => $filters,
));
