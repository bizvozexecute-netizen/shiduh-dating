<?php
/*
 * «Присвоить аккаунт» — приглашённый кандидат закрепляет за собой заготовку
 * (ROADMAP XI-4). Задаёт свой email + пароль: заготовка (синтетический
 * email, вход только по ссылке) превращается в обычный аккаунт, а токен
 * приглашения гасится (claimed_at) — вход по ссылке больше не работает.
 *
 * POST, авторизован как заготовка. Меняет только СВОЙ аккаунт (по сессии,
 * не по входному id — подделать нельзя). Экранирование — prepare() (№35).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function claim_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    claim_json(405, array('error' => 'Метод не поддерживается'));
}

// CSRF: тот же заслон, что и в invite_create.php.
$req_host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($src !== '' && strcasecmp((string)parse_url($src, PHP_URL_HOST), $req_host) !== 0) {
    claim_json(403, array('error' => 'Недопустимый источник запроса'));
}

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    claim_json(403, array('error' => 'Требуется авторизация'));
}

// Присваивать можно только аккаунт-заготовку (у неё синтетический email).
$is_placeholder = (bool)$db->get_var(
    "SELECT 1 FROM `User` WHERE `User_ID`=$uid "
    . "AND `Email` LIKE 'invite-%@invite.shiduh.org' LIMIT 1"
);
if (!$is_placeholder) {
    claim_json(409, array('error' => 'Аккаунт уже закреплён'));
}

$email    = trim((string)$nc_core->input->fetch_post('email'));
$password = (string)$nc_core->input->fetch_post('password');

// Формат email проверяем явно: check_login сверяет Format === 'email', а у
// поля он 'email:' — т.е. штатной проверки формата не происходит.
if ($email === '' || !nc_check_email($email)) {
    claim_json(400, array('error' => 'Некорректный email'));
}
switch ($nc_core->user->check_login($email, $uid)) {
    case NC_AUTH_LOGIN_INCORRECT:
        claim_json(400, array('error' => 'Некорректный email'));
    case NC_AUTH_LOGIN_EXISTS:
        claim_json(409, array('error' => 'Такой email уже используется'));
}
if (mb_strlen($password, 'UTF-8') < 6) {
    claim_json(400, array('error' => 'Пароль слишком короткий (минимум 6 символов)'));
}

// Ставим настоящий email и пароль; гасим токен(ы) этой заготовки.
$db->query("UPDATE `User` SET `Email`='" . $db->prepare($email) . "' WHERE `User_ID`=$uid");
$nc_core->user->change_password($uid, $password);
$db->query("UPDATE `invite_tokens` SET `claimed_at`=NOW() "
    . "WHERE `candidate_user_id`=$uid AND `claimed_at` IS NULL");

claim_json(200, array('ok' => true));
