<?php
/*
 * Отписка от рассылки по персональному токену из письма (ROADMAP Р-3).
 *
 * GET, без авторизации (ссылка открывается из почты). Токен неугадываем
 * (bin2hex(random_bytes(32))); действие идемпотентно и безвредно, поэтому
 * one-click GET допустим. Битый/неизвестный токен — общий 404.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$db = $nc_core->db;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function unsubscribe_page($status, $title, $text) {
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="font-family:Georgia,serif;background:#f5f2ec;color:#3a2b2b;'
        . 'display:grid;place-items:center;min-height:90vh;margin:0">'
        . '<div style="background:#fff;border:1px solid #e3ddd2;border-radius:14px;'
        . 'padding:36px 44px;text-align:center;max-width:420px">'
        . '<h1 style="font-size:20px;margin:0 0 10px">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:0;color:#756d65">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></body></html>';
    exit;
}

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    unsubscribe_page(404, 'Ссылка не найдена', 'Проверьте адрес из письма.');
}

$token_esc = $db->prepare($token);
$user_id = (int)$db->get_var(
    "SELECT `user_id` FROM `mailing_optout` WHERE `token`='$token_esc'"
);
if (!$user_id) {
    unsubscribe_page(404, 'Ссылка не найдена', 'Проверьте адрес из письма.');
}

$db->query(
    "UPDATE `mailing_optout` SET `opted_out`=1, "
    . "`opted_out_at`=IFNULL(`opted_out_at`, NOW()) "
    . "WHERE `token`='$token_esc'"
);

unsubscribe_page(200, 'Вы отписаны от рассылки',
    'Письма с новостями сайта вам больше приходить не будут.');
