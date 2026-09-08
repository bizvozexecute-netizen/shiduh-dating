<?php
/*
 * Запуск рассылки по сегменту (ROADMAP Р-1…Р-3).
 *
 * POST, только администратор. Фильтры как в mailing_preview.php + subject,
 * body. Письма уходят только адресатам с подтверждённым email (Confirmed=1):
 * кладутся в Mail_Queue (nc_mail2queue), реально шлёт штатный крон mailer
 * порциями по 20 — троттлинг Р-2. В каждом письме — ссылка отписки с
 * персональным токеном (Р-3); лог — mailings + mailing_log. Сама очередь —
 * mailing_enqueue() в mailing_lib.php (её же зовёт запрос согласия, C11).
 * CSRF — заслон по Origin/Referer, как в api/invite_create.php.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $NETCAT_FOLDER . 'netcat/admin/mail.inc.php';
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

$subject = trim((string)$nc_core->input->fetch_post('subject'));
$body    = trim((string)$nc_core->input->fetch_post('body'));
if ($subject === '' || mb_strlen($subject) > 200) {
    shiduh_json(400, array('error' => 'Тема обязательна (до 200 символов)'));
}
if ($body === '' || mb_strlen($body) > 20000) {
    shiduh_json(400, array('error' => 'Текст обязателен (до 20000 символов)'));
}

// Защита от даблклика и ретрая после обрыва: идентичная рассылка,
// созданная только что, не запускается повторно.
$dup = $db->get_var(
    "SELECT `id` FROM `mailings` WHERE "
    . "`subject`='" . $db->prepare($subject) . "' "
    . "AND `body`='" . $db->prepare($body) . "' "
    . "AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1"
);
if ($dup) {
    shiduh_json(409, array(
        'error' => 'Такая рассылка уже запускалась только что (№' . (int)$dup . '). '
                 . 'Если это не даблклик — измените тему или текст.',
    ));
}

list($where, $filters) = mailing_segment_where($db, $nc_core->input);

// Получатели: сегмент + подтверждённый email.
$recipients = (array)$db->get_results(
    "SELECT u.`User_ID`, u.`Email` FROM `User` u "
    . "WHERE $where AND u.`Confirmed`=1 ORDER BY u.`User_ID`",
    ARRAY_A
);

$result = mailing_enqueue($db, $uid, $subject, $body, $filters, $recipients);
if (!$result['mailing_id']) {
    shiduh_json(500, array('error' => 'Не удалось создать рассылку'));
}

shiduh_json(200, array('ok' => true, 'mailing_id' => $result['mailing_id'], 'queued' => $result['queued']));
