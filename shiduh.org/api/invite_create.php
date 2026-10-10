<?php
/*
 * Эндпоинт «Пригласить кандидата по ссылке» (ROADMAP XI-2).
 *
 * Только для шадхана. Работает ПОВЕРХ уже заведённой анкеты (у Message204
 * много NOT NULL-полей без дефолта — «пустую» строку под strict-mode не
 * вставить; шадхан и так создаёт анкету штатно). Действие:
 *   1) создаёт кандидату аккаунт-заготовку (nc_user::add, группа 2, Type=1,
 *      Checked=1, синтетический email-логин, случайный пароль);
 *   2) передаёт заготовке владение анкетой (Message204.User_ID), сохраняя
 *      Shadhan_1 = шадхан (видимость у шадхана ведётся по Shadhan_N);
 *   3) выпускает токен invite_tokens (bin2hex(random_bytes(32)), 30 дней).
 * Возвращает ссылку — по ней кандидат логинится (api/invite.php, XI-3).
 *
 * Идемпотентность: если у текущего владельца анкеты уже есть активный
 * (не присвоенный, не истёкший) токен — возвращаем его, не плодя аккаунты.
 *
 * Экранирование строк — через $db->prepare() (не escape; AUDIT.md №35).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/invite_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

// Защита от CSRF: действие меняет состояние (создаёт аккаунт, передаёт
// владение анкетой). Если браузер прислал Origin/Referer — их хост обязан
// совпасть с хостом сайта. Полноценный nc_token добавится с формой шадхана
// (XI-5); здесь — заслон межсайтовому запросу.

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}

shiduh_csrf_check($uid);   // заслон CSRF: источник + токен (C3)

// Приглашать может только шадхан (Type=2). Читаем сырое числовое значение
// колонки User.Type — get_by_id() резолвит select-поле в текстовую метку.
shiduh_require_shadhan($db, $uid);

$anketa_id = (int)$nc_core->input->fetch_post('anketa_id');
if ($anketa_id <= 0) {
    shiduh_json(400, array('error' => 'Не указана анкета'));
}

$anketa = $db->get_row(
    "SELECT `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3`, "
    . "`Name`, `Gender`, `Date_of_birth`, `Phone` "
    . "FROM `Message204` WHERE `Message_ID`=$anketa_id",
    ARRAY_A
);
if (!$anketa) {
    shiduh_json(404, array('error' => 'Анкета не найдена'));
}

// Шадхан должен вести эту анкету: быть её владельцем или одним из Shadhan_N.
$leads = in_array($uid, array(
    (int)$anketa['User_ID'], (int)$anketa['Shadhan_1'],
    (int)$anketa['Shadhan_2'], (int)$anketa['Shadhan_3'],
), true);
if (!$leads) {
    shiduh_json(403, array('error' => 'Эта анкета не в вашем ведении'));
}

$owner_id = (int)$anketa['User_ID'];

// Приглашение ПЕРЕДАЁТ владение анкетой: кому его можно передать — в
// api/invite_lib.php (то же правило решает, показывать ли кнопку в карточке).
$owner_state = invite_owner_state($db, $anketa, $uid);
if (!$owner_state['ok']) {
    shiduh_json(409, array('error' => $owner_state['message'], 'reason' => $owner_state['reason']));
}

$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . $_SERVER['HTTP_HOST'];

// Идемпотентность: активный токен для нынешнего владельца анкеты — вернуть его.
$existing = $db->get_var(
    "SELECT `token` FROM `invite_tokens` "
    . "WHERE `candidate_user_id`=$owner_id AND `claimed_at` IS NULL "
    . "AND `expires_at` > NOW() ORDER BY `id` DESC LIMIT 1"
);
if ($existing) {
    shiduh_json(200, array(
        'ok' => true, 'reused' => true, 'token' => $existing,
        'link' => $base . '/api/invite.php?t=' . $existing,
    ));
}

// Создаём аккаунт-заготовку кандидата. Обязательные (NOT NULL без дефолта)
// поля User берём из анкеты — заготовка согласована с анкетой.
$token    = bin2hex(random_bytes(32));
$email    = 'invite-' . substr($token, 0, 32) . '@invite.shiduh.org';
$password = bin2hex(random_bytes(16));

try {
    $candidate_uid = (int)$nc_core->user->add(
        array(
            'Email'         => $email,
            'Name'          => (string)$anketa['Name'],
            'Gender'        => $anketa['Gender'],
            'Date_of_birth' => $anketa['Date_of_birth'],
            'Phone'         => (string)$anketa['Phone'],
            'Type'          => 1,   // кандидат
        ),
        2,          // группа регистрации кандидата
        $password,
        array('Checked' => 1)
    );
} catch (Exception $e) {
    shiduh_json(500, array('error' => 'Не удалось создать аккаунт-заготовку'));
}
if ($candidate_uid <= 0) {
    shiduh_json(500, array('error' => 'Не удалось создать аккаунт-заготовку'));
}

// Токен пишем ДО передачи владения: если INSERT не удастся, анкета останется
// у шадхана (не «повиснет» на заготовке с мёртвой ссылкой). MyISAM без
// транзакций — потому проверяем ошибку явно и передаём владение только после.
$db->query(
    "INSERT INTO `invite_tokens` SET "
    . "`token`='" . $db->prepare($token) . "', "
    . "`candidate_user_id`=$candidate_uid, "
    . "`created_by`=$uid, "
    . "`created_at`=NOW(), "
    . "`expires_at`=DATE_ADD(NOW(), INTERVAL 30 DAY)"
);
if ($db->is_error || !$db->insert_id) {
    shiduh_json(500, array('error' => 'Не удалось выпустить приглашение'));
}

// Передаём заготовке владение анкетой; сохраняем ведущего шадхана.
$db->query("UPDATE `Message204` SET `User_ID`=$candidate_uid WHERE `Message_ID`=$anketa_id");
// Анкету завёл другой шадхан — он остаётся при ней в свободном слоте.
if ($owner_state['keep_slot'] !== '') {
    $db->query("UPDATE `Message204` SET `" . $owner_state['keep_slot'] . "`=$owner_id "
        . "WHERE `Message_ID`=$anketa_id");
    $anketa[$owner_state['keep_slot']] = $owner_id;
}
if (!(int)$anketa['Shadhan_1']) {
    $db->query("UPDATE `Message204` SET `Shadhan_1`=$uid WHERE `Message_ID`=$anketa_id");
}

shiduh_json(200, array(
    'ok' => true, 'reused' => false, 'token' => $token,
    'candidate_user_id' => $candidate_uid,
    'link' => $base . '/api/invite.php?t=' . $token,
));
