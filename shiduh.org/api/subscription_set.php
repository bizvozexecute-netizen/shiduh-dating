<?php
/*
 * Ручное переключение статуса подписки (ТЗ Э4-4).
 *
 * POST {user_id, status}, только администратор NetCat. Вызывается с
 * админ-экрана /admin-subscriptions.php. Смена статуса сбрасывает счётчик
 * сообщений (subscription_set): новый тариф — новый отсчёт.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/ai_client.php';
require_once dirname(__FILE__) . '/subscription_lib.php';

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
if (!shiduh_is_admin($db, $uid)) {
    shiduh_json(403, array('error' => 'Доступно только администратору'));
}

$target_raw = $nc_core->input->fetch_post('user_id');
$status_raw = $nc_core->input->fetch_post('status');
$days_raw = $nc_core->input->fetch_post('days');
// user_id[]=x дал бы (int)массива = 1 — целились бы в директора
if (!is_scalar($target_raw) || !is_scalar($status_raw)
    || ($days_raw !== null && !is_scalar($days_raw))) {
    shiduh_json(400, array('error' => 'Некорректные параметры'));
}
$target = (int)$target_raw;
$status = (string)$status_raw;
// срок в днях: пусто = бессрочно; действует только для trial/paid
$days = (int)$days_raw;

if (!in_array($status, subscription_statuses(), true)) {
    shiduh_json(400, array('error' => 'Неизвестный статус'));
}
if ($target <= 0 || !$db->get_var("SELECT `User_ID` FROM `User` WHERE `User_ID`=$target")) {
    shiduh_json(404, array('error' => 'Пользователь не найден'));
}

$expires = null;
if ($status !== 'free' && $days > 0) {
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);
}

if (!subscription_set($db, $target, $status, $expires)) {
    shiduh_json(500, array('error' => 'Не удалось сохранить статус'));
}

// аудит: смена тарифа — денежное действие, должно быть видно, кто и кому
error_log('[subscription] админ ' . $uid . ' установил пользователю ' . $target
    . ' статус ' . $status . ($expires ? ' до ' . $expires : ''));

shiduh_json(200, array('ok' => true, 'user_id' => $target, 'status' => $status,
    'expires_at' => $expires));
