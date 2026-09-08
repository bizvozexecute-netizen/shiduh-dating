<?php
/*
 * Закрыть «сигнал внимания» (этап «До идеала», C10).
 *
 * POST note_id. Право — у администратора и у ведущего шадхана кандидата
 * (Shadhan_1..3 его анкеты); остальным 403. Уже закрытый — 409, чтобы
 * повторный клик не выглядел успехом. Вызывается с админ-экрана и из
 * карточки кандидата (js/lk-card.js). CSRF — источник + токен (C3).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/moderation_lib.php';

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
if ($uid <= 0) {
    shiduh_json(401, array('error' => 'Нужно войти'));
}
shiduh_csrf_check($uid);

$note = moderation_note($db, (int)$nc_core->input->fetch_post('note_id'));
if (!$note) {
    shiduh_json(404, array('error' => 'Сигнал не найден'));
}
if (!moderation_can_resolve($db, $uid, $note)) {
    shiduh_json(403, array('error' => 'Закрыть сигнал может администратор или ведущий шадхан кандидата'));
}
if (!empty($note['resolved_at'])) {
    shiduh_json(409, array('error' => 'Сигнал уже отмечен как просмотренный'));
}
if (!risk_note_resolve($db, (int)$note['id'], $uid)) {
    shiduh_json(500, array('error' => 'Не удалось сохранить отметку'));
}
shiduh_log('admin', 'moderation note ' . (int)$note['id'] . ' resolved by ' . $uid);
shiduh_json(200, array('ok' => true, 'note_id' => (int)$note['id']));
