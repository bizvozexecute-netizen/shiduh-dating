<?php
/*
 * «Мне интересно» (приёмка 2026-10). Кандидат в «Поиске кандидатов» одной
 * кнопкой сообщает шадхану чужой анкеты, что хотел бы познакомиться.
 *
 * POST {anketa_id}, только кандидат (Type=1), не на свою анкету.
 * Получатель — ведущий шадхан анкеты (Shadhan_1, иначе 2, 3, иначе
 * владелец-шадхан). Сообщение в кабинет (Message6) от имени кандидата —
 * шадхан ответит ему прямо в переписке — и письмо. Повтор по той же анкете
 * не шлёт второе сообщение (interest_requests, миграция 031); не больше
 * 10 новых заявок в сутки — кнопка не должна стать рассылкой по базе.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/shiduh_lib.php';

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
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}
shiduh_csrf_check($uid);   // заслон CSRF: источник + токен (C3)

if (shiduh_user_type($db, $uid) !== 1) {
    shiduh_json(403, array('error' => 'Кнопка — для кандидатов. Шадхану: «Подбор пар с ИИ».'));
}

$anketa_id = (int)$nc_core->input->fetch_post('anketa_id');
if ($anketa_id <= 0) {
    shiduh_json(400, array('error' => 'Не указана анкета'));
}
$anketa = $db->get_row(
    "SELECT m.`Message_ID`, m.`User_ID`, m.`Shadhan_1`, m.`Shadhan_2`, m.`Shadhan_3`, u.`Type` AS `owner_type` "
    . "FROM `Message204` m LEFT JOIN `User` u ON u.`User_ID`=m.`User_ID` "
    . "WHERE m.`Message_ID`=$anketa_id AND m.`Checked`=1",
    ARRAY_A
);
if (!$anketa) {
    shiduh_json(404, array('error' => 'Анкета не найдена'));
}
if ((int)$anketa['User_ID'] === $uid) {
    shiduh_json(400, array('error' => 'Это ваша анкета'));
}

$shadhan = 0;
foreach (array('Shadhan_1', 'Shadhan_2', 'Shadhan_3') as $col) {
    if (!$shadhan && (int)$anketa[$col] > 0) {
        $shadhan = (int)$anketa[$col];
    }
}
if (!$shadhan && (int)$anketa['owner_type'] === 2) {
    $shadhan = (int)$anketa['User_ID'];
}
if (!$shadhan) {
    shiduh_json(409, array('error' => 'У этой анкеты пока нет шадхана — сообщить некому.'));
}
$shadhan_name = shiduh_user_name($db, $shadhan);

if ($db->get_var("SELECT 1 FROM `interest_requests` WHERE `user_id`=$uid AND `anketa_id`=$anketa_id")) {
    shiduh_json(200, array('ok' => true, 'repeat' => true,
        'message' => 'Вы уже сообщали шадхану ' . $shadhan_name . ' о своём интересе — ждите ответа в «Сообщениях».'));
}
if ((int)$db->get_var("SELECT COUNT(*) FROM `interest_requests` WHERE `user_id`=$uid "
        . "AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 DAY)") >= 10) {
    shiduh_json(429, array('error' => 'Не больше 10 заявок в сутки. Продолжите завтра.'));
}

$db->query("INSERT INTO `interest_requests` SET `user_id`=$uid, `anketa_id`=$anketa_id, "
    . "`shadhan_id`=$shadhan, `created_at`=NOW()");
if ($db->is_error) {
    shiduh_json(500, array('error' => 'Не удалось сохранить заявку'));
}

// Своя анкета кандидата — шадхану, чтобы было с чем сравнить.
$own_id = (int)$db->get_var(
    "SELECT `Message_ID` FROM `Message204` WHERE `User_ID`=$uid ORDER BY `Checked` DESC, `Message_ID` LIMIT 1"
);
$base = shiduh_base_url();
$me = shiduh_user_name($db, $uid);
$html = 'Здравствуйте!<br>Кандидат '
    . ($own_id ? '<a href="' . $base . '/my/candidate/candidate_' . $own_id . '.html">' . shiduh_esc($me) . '</a>' : shiduh_esc($me))
    . ' заинтересовался анкетой вашего кандидата '
    . '<a href="' . $base . '/my/candidate/candidate_' . $anketa_id . '.html">№ ' . $anketa_id . '</a> '
    . 'и просит рассказать о возможном знакомстве. Ответить можно прямо на это сообщение.'
    . '<br>С уважением,<br>Команда One Soul';
shiduh_notify($db, $uid, $shadhan, 'Кандидат заинтересовался анкетой', $html);

shiduh_json(200, array('ok' => true, 'repeat' => false,
    'message' => 'Шадхан ' . $shadhan_name . ' получил ваше сообщение. Ответ придёт в «Сообщения».'));
