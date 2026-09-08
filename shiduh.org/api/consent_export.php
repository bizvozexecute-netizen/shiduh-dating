<?php
/*
 * Выгрузка списка анкет без согласия на сведения о здоровье (C11).
 *
 * GET, только администратор. В файле — кто и когда, без самого поля
 * здоровья (см. consent_admin_lib.php). Не кэшируется.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/consent_admin_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!shiduh_is_admin($db, $uid)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Доступно только администратору';
    exit;
}

$rows = consent_pending($db, 10000, 0);
$csv = consent_csv($rows);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="consent-pending-' . date('Ymd') . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $csv;
