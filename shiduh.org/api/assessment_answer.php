<?php
/*
 * Ответ на кейс ценностного опроса (ТЗ Э2.3).
 *
 * POST, авторизованный пользователь отвечает ЗА СЕБЯ: user_id берётся из
 * сессии, а не из запроса. Ответ классифицируется моделью (один ответ —
 * один разбор), баллы сохраняются, профиль пересчитывается КОДОМ и
 * фиксируется в анкете.
 *
 * Негодный разбор не роняет конвейер: ответ сохраняется без баллов —
 * выдумывать оценку из мусора хуже, чем не иметь её.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/scoring_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

shiduh_check_origin();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}

$case_id = (int)$nc_core->input->fetch_post('case_id');
$raw = $nc_core->input->fetch_post('answer');
if (!is_string($raw)) {
    shiduh_json(400, array('error' => 'Некорректный ответ'));
}
$answer = trim($raw);
if ($answer === '') {
    shiduh_json(400, array('error' => 'Ответ обязателен'));
}
if (mb_strlen($answer) > 4000) {
    $answer = mb_substr($answer, 0, 4000);
}

$case_row = $db->get_row(
    "SELECT `id`, `dimension_group`, `dimension`, `scenario_text` "
    . "FROM `assessment_cases` WHERE `id`=$case_id AND `active`=1",
    ARRAY_A
);
if (!$case_row) {
    shiduh_json(404, array('error' => 'Кейс не найден'));
}

// Один ответ — один разбор моделью (Э2.3-a). Ошибка модели не теряет ответ.
$classification = scoring_classify($case_row, $answer);

$version = scoring_version_for_answer($db, $uid, $case_id);
$answer_id = scoring_store_answer($db, $uid, $case_id, $answer, $classification, $version);
if (!$answer_id) {
    shiduh_json(500, array('error' => 'Не удалось сохранить ответ'));
}

// Профиль считает КОД по сохранённым баллам (Э2.3-b), а не модель.
$profile = scoring_compute_profile($db, $uid);
if ($profile) {
    // Текст психотипа — один раз на версию (Э2.3-c). Если он уже посчитан
    // для этой версии, переносим как есть: при просмотрах и следующих
    // ответах модель не тревожим.
    $stored = json_decode((string)$db->get_var(
        "SELECT `Psychological_profile` FROM `Message204` WHERE `User_ID`=$uid "
        . "ORDER BY `Message_ID` LIMIT 1"
    ), true);
    $cached = (is_array($stored)
        && (int)(isset($stored['version']) ? $stored['version'] : 0) === (int)$profile['version']
        && !empty($stored['psychotype_text']))
        ? $stored['psychotype_text'] : null;

    $profile['psychotype_text'] = $cached !== null
        ? $cached
        : scoring_psychotype_text($profile);

    scoring_save_profile($db, $uid, $profile);
}

shiduh_json(200, array(
    'ok' => true,
    'classified' => $classification !== null,
    'version' => $version,
    'profile' => $profile,
));
