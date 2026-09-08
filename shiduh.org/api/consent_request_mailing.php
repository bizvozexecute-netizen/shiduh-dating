<?php
/*
 * Запросить повторное согласие на сведения о здоровье (C11).
 *
 * POST, только администратор. Адресаты — владельцы анкет, где поле здоровья
 * заполнено, а согласия нет (consent_pending_recipients): подтверждённый
 * e-mail, не отписавшиеся. Письмо уходит штатной очередью рассылок
 * (mailing_enqueue → Mail_Queue, крон шлёт порциями), с персональной
 * ссылкой отписки. Повтор в течение 10 минут — 409: даблклик или ретрай
 * после обрыва не должны слать второе письмо. CSRF — источник + токен.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $NETCAT_FOLDER . 'netcat/admin/mail.inc.php';
require_once dirname(__FILE__) . '/mailing_lib.php';
require_once dirname(__FILE__) . '/consent_admin_lib.php';

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
shiduh_csrf_check($uid);

$subject = trim((string)$nc_core->input->fetch_post('subject'));
$body    = trim((string)$nc_core->input->fetch_post('body'));
if ($subject === '') {
    $subject = consent_request_subject();
}
if ($body === '') {
    $body = consent_request_text();
}
if (mb_strlen($subject) > 200) {
    shiduh_json(400, array('error' => 'Тема — до 200 символов'));
}
if (mb_strlen($body) > 20000) {
    shiduh_json(400, array('error' => 'Текст — до 20000 символов'));
}

$filters = consent_mailing_filters();
$dup = $db->get_var(
    "SELECT `id` FROM `mailings` WHERE `filters`='" . $db->prepare(json_encode($filters, JSON_UNESCAPED_UNICODE)) . "' "
    . "AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1"
);
if ($dup) {
    shiduh_json(409, array('error' => 'Запрос согласия уже отправлялся только что (рассылка №' . (int)$dup . '). Подождите 10 минут.'));
}

$recipients = consent_pending_recipients($db);
if (!$recipients) {
    shiduh_json(200, array('ok' => true, 'mailing_id' => 0, 'queued' => 0, 'note' => 'Адресатов нет'));
}

$result = mailing_enqueue($db, $uid, $subject, $body, $filters, $recipients);
if (!$result['mailing_id']) {
    shiduh_json(500, array('error' => 'Не удалось создать рассылку'));
}
shiduh_log('admin', 'consent request mailing ' . $result['mailing_id'] . ' queued ' . $result['queued'] . ' by ' . $uid);
shiduh_json(200, array('ok' => true, 'mailing_id' => $result['mailing_id'], 'queued' => $result['queued'], 'skipped' => $result['skipped']));
