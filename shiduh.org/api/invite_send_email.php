<?php
/*
 * Отправка ссылки-приглашения на email кандидата (ROADMAP XI-5b).
 *
 * POST, только шадхан. Берёт активный токен уже созданного приглашения для
 * этой анкеты и кладёт письмо со ссылкой в очередь (nc_mail2queue → Mail_Queue,
 * реальную отправку делает штатный крон mailer). Требует, чтобы ссылка уже
 * была создана (api/invite_create.php) — иначе просить сначала создать её.
 *
 * Первичный канал у шадхана — «Скопировать»; email опционален. Экранирование
 * строк — prepare() (№35). CSRF — заслон по Origin, как в invite_create.php.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function send_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, array('error' => 'Метод не поддерживается'));
}

$req_host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($src !== '' && strcasecmp((string)parse_url($src, PHP_URL_HOST), $req_host) !== 0) {
    send_json(403, array('error' => 'Недопустимый источник запроса'));
}

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    send_json(403, array('error' => 'Требуется авторизация'));
}
if ((int)$db->get_var("SELECT `Type` FROM `User` WHERE `User_ID`=$uid") !== 2) {
    send_json(403, array('error' => 'Доступно только шадхану'));
}

$anketa_id = (int)$nc_core->input->fetch_post('anketa_id');
$email     = trim((string)$nc_core->input->fetch_post('email'));
if ($anketa_id <= 0) {
    send_json(400, array('error' => 'Не указана анкета'));
}
if ($email === '' || !nc_check_email($email)) {
    send_json(400, array('error' => 'Некорректный email'));
}

$anketa = $db->get_row(
    "SELECT `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3`, `Name` "
    . "FROM `Message204` WHERE `Message_ID`=$anketa_id",
    ARRAY_A
);
if (!$anketa) {
    send_json(404, array('error' => 'Анкета не найдена'));
}
$leads = in_array($uid, array(
    (int)$anketa['User_ID'], (int)$anketa['Shadhan_1'],
    (int)$anketa['Shadhan_2'], (int)$anketa['Shadhan_3'],
), true);
if (!$leads) {
    send_json(403, array('error' => 'Эта анкета не в вашем ведении'));
}

// Активный токен приглашения для владельца анкеты (ссылка уже создана).
$owner_id = (int)$anketa['User_ID'];
$token = $db->get_var(
    "SELECT `token` FROM `invite_tokens` "
    . "WHERE `candidate_user_id`=$owner_id AND `claimed_at` IS NULL "
    . "AND `expires_at` > NOW() ORDER BY `id` DESC LIMIT 1"
);
if (!$token) {
    send_json(409, array('error' => 'Сначала создайте ссылку-приглашение'));
}

$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . $_SERVER['HTTP_HOST'];
$link = $base . '/api/invite.php?t=' . $token;

$from = $nc_core->get_settings('FromEmail', 'subscriber')
     ?: ($nc_core->get_settings('SpamFromEmail', 'system') ?: ('noreply@' . $req_host));
$site = $nc_core->get_settings('SiteName') ?: 'сайт знакомств';
$subject = 'Приглашение заполнить анкету';
$message = "Здравствуйте!\n\n"
    . "Вас приглашают заполнить анкету на сайте «{$site}». "
    . "Перейдите по ссылке — анкету поможет заполнить ИИ-консультант, "
    . "регистрация не нужна:\n\n{$link}\n\n"
    . "Ссылка действует 30 дней.";

nc_mail2queue($email, $from, $subject, $message);

send_json(200, array('ok' => true));
