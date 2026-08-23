<?php
/*
 * Запуск рассылки по сегменту (ROADMAP Р-1…Р-3).
 *
 * POST, только администратор. Фильтры как в mailing_preview.php + subject,
 * body. Письма уходят только адресатам с подтверждённым email (Confirmed=1):
 * кладутся в Mail_Queue (nc_mail2queue), реально шлёт штатный крон mailer
 * порциями по 20 — троттлинг Р-2. В каждом письме — ссылка отписки с
 * персональным токеном (Р-3); лог — mailings + mailing_log.
 * CSRF — заслон по Origin/Referer, как в api/invite_create.php.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $NETCAT_FOLDER . 'netcat/admin/mail.inc.php';
require_once dirname(__FILE__) . '/mailing_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mailing_json(405, array('error' => 'Метод не поддерживается'));
}

$req_host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($src !== '' && strcasecmp((string)parse_url($src, PHP_URL_HOST), $req_host) !== 0) {
    mailing_json(403, array('error' => 'Недопустимый источник запроса'));
}

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!mailing_is_admin($db, $uid)) {
    mailing_json(403, array('error' => 'Доступно только администратору'));
}

$subject = trim((string)$nc_core->input->fetch_post('subject'));
$body    = trim((string)$nc_core->input->fetch_post('body'));
if ($subject === '' || mb_strlen($subject) > 200) {
    mailing_json(400, array('error' => 'Тема обязательна (до 200 символов)'));
}
if ($body === '' || mb_strlen($body) > 20000) {
    mailing_json(400, array('error' => 'Текст обязателен (до 20000 символов)'));
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
    mailing_json(409, array(
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

$db->query(
    "INSERT INTO `mailings` SET "
    . "`created_by`=$uid, "
    . "`filters`='" . $db->prepare(json_encode($filters, JSON_UNESCAPED_UNICODE)) . "', "
    . "`subject`='" . $db->prepare($subject) . "', "
    . "`body`='" . $db->prepare($body) . "', "
    . "`recipients_total`=0, "
    . "`created_at`=NOW()"
);
if ($db->is_error || !$db->insert_id) {
    mailing_json(500, array('error' => 'Не удалось создать рассылку'));
}
$mailing_id = (int)$db->insert_id;

$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . (string)($_SERVER['HTTP_HOST'] ?? '');
$from = $nc_core->get_settings('FromEmail', 'subscriber')
     ?: ($nc_core->get_settings('SpamFromEmail', 'system') ?: ('noreply@' . $req_host));

// Сегмент может быть большим (тысячи адресатов): не даём PHP умереть по
// таймауту посреди цикла — как штатный bulk-отправитель admin/user/MessageToAll.php.
@set_time_limit(0);
@ignore_user_abort(true);

$queued = 0;
foreach ($recipients as $r) {
    $r_uid = (int)$r['User_ID'];
    $email = (string)$r['Email'];

    // Персональный токен отписки: заводим при первом касании, дальше переиспользуем.
    $token = $db->get_var("SELECT `token` FROM `mailing_optout` WHERE `user_id`=$r_uid");
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        $db->query(
            "INSERT IGNORE INTO `mailing_optout` SET "
            . "`user_id`=$r_uid, `token`='" . $db->prepare($token) . "', "
            . "`opted_out`=0, `created_at`=NOW()"
        );
        // гонка двух запросов: перечитываем фактический токен
        $token = $db->get_var("SELECT `token` FROM `mailing_optout` WHERE `user_id`=$r_uid");
    }

    if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        // Без рабочего токена ссылка отписки в письме была бы мёртвой —
        // такого адресата пропускаем и честно пишем в лог ошибку.
        $db->query(
            "INSERT INTO `mailing_log` SET "
            . "`mailing_id`=$mailing_id, `user_id`=$r_uid, "
            . "`email`='" . $db->prepare($email) . "', "
            . "`status`='error', `queued_at`=NOW()"
        );
        continue;
    }

    $message = $body . "\n\n---\n"
        . "Отписаться от рассылки: $base/api/mailing_unsubscribe.php?t=$token";

    nc_mail2queue($email, $from, $subject, $message);

    $db->query(
        "INSERT INTO `mailing_log` SET "
        . "`mailing_id`=$mailing_id, `user_id`=$r_uid, "
        . "`email`='" . $db->prepare($email) . "', "
        . "`status`='queued', `queued_at`=NOW()"
    );
    // счётчик копим инкрементально: при обрыве соединения шапка не останется
    // с нулём при реально ушедших письмах
    $db->query("UPDATE `mailings` SET `recipients_total`=`recipients_total`+1 WHERE `id`=$mailing_id");
    $queued++;
}

mailing_json(200, array('ok' => true, 'mailing_id' => $mailing_id, 'queued' => $queued));
