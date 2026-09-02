<?php
/*
 * Генерация резюме кандидата по кнопке шадхана (ТЗ Э1.4-a).
 *
 * POST, только шадхан, ведущий эту анкету. Собирает выжимку анкеты по
 * БЕЛОМУ списку (без идентификаторов и чувствительных полей — Э1.4-b),
 * добавляет последние сообщения кандидата, просит модель написать резюме
 * 3–6 предложений и складывает результат в AI_summary + AI_summary_meta.
 *
 * Расход денег ограничен: не чаще одного пересчёта в час на анкету —
 * иначе кнопку можно жать бесконечно.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/ai_client.php';
require_once dirname(__FILE__) . '/summary_lib.php';

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
if ((int)$db->get_var("SELECT `Type` FROM `User` WHERE `User_ID`=$uid") !== 2) {
    shiduh_json(403, array('error' => 'Доступно только шадхану'));
}

$anketa_id = (int)$nc_core->input->fetch_post('anketa_id');
if ($anketa_id <= 0) {
    shiduh_json(400, array('error' => 'Не указана анкета'));
}

$anketa = $db->get_row(
    "SELECT `Message_ID`, `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3`, `AI_summary_meta` "
    . "FROM `Message204` WHERE `Message_ID`=$anketa_id",
    ARRAY_A
);
if (!$anketa) {
    shiduh_json(404, array('error' => 'Анкета не найдена'));
}
// шадхан должен вести эту анкету
$leads = in_array($uid, array(
    (int)$anketa['User_ID'], (int)$anketa['Shadhan_1'],
    (int)$anketa['Shadhan_2'], (int)$anketa['Shadhan_3'],
), true);
if (!$leads) {
    shiduh_json(403, array('error' => 'Эта анкета не в вашем ведении'));
}

/*
 * Время берём ТОЛЬКО из БД. Часы PHP и MySQL в этой среде расходятся на 3
 * часа (PHP — Europe/Moscow, MySQL — UTC), поэтому сравнивать метку одного
 * источника со временем другого нельзя: разница уходит в минус и лимит
 * молча перестаёт работать. В метаданные пишем и unix-метку — по ней
 * сравнение однозначно, без разбора строк и часовых поясов.
 */
$db_now = (int)$db->get_var("SELECT UNIX_TIMESTAMP()");
$db_now_str = (string)$db->get_var("SELECT NOW()");

/* Возраст записи по метаданным; для старых записей — по строке времени. */
function summary_meta_age($meta, $db_now) {
    if (!is_array($meta)) {
        return null;
    }
    if (!empty($meta['generated_at_ts'])) {
        return $db_now - (int)$meta['generated_at_ts'];
    }
    if (!empty($meta['generated_at'])) {
        $ts = strtotime((string)$meta['generated_at']);
        return $ts ? ($db_now - $ts) : null;
    }
    return null;
}

// Не чаще одного пересчёта в час на анкету: каждый вызов стоит денег.
$prev = json_decode((string)$anketa['AI_summary_meta'], true);
$age = summary_meta_age($prev, $db_now);
if ($age !== null) {
    // отрицательный возраст (метка «из будущего») считаем свежим: лучше
    // отказать, чем молча пропустить лимит
    if ($age < 3600) {
        $age = max($age, 0);
        shiduh_json(429, array(
            'error' => 'Резюме обновлялось недавно. Следующее обновление можно '
                     . 'запросить через ' . (int)ceil((3600 - $age) / 60) . ' мин.',
        ));
    }
}

// Потолок на шадхана: лимит «раз в час на анкету» сам по себе не спасает —
// у одного шадхана бывает больше 240 анкет, и он мог бы вызвать модель
// столько же раз. Считаем по его же анкетам, сколько резюме он пересчитал
// за последний час.
define('SUMMARY_MAX_PER_HOUR', 20);
$db->query(
    "SELECT `AI_summary_meta` FROM `Message204` WHERE "
    . "(`User_ID`=$uid OR `Shadhan_1`=$uid OR `Shadhan_2`=$uid OR `Shadhan_3`=$uid) "
    . "AND `AI_summary_meta` IS NOT NULL AND `AI_summary_meta`<>''",
    ARRAY_A
);
$recent = 0;
foreach ((array)$db->last_result as $r) {
    $m = json_decode((string)$r['AI_summary_meta'], true);
    if (!is_array($m)) {
        continue;
    }
    if ((int)(isset($m['generated_by']) ? $m['generated_by'] : 0) !== $uid) {
        continue;
    }
    $delta = summary_meta_age($m, $db_now);
    if ($delta !== null && $delta < 3600) {
        $recent++;
    }
}
if ($recent >= SUMMARY_MAX_PER_HOUR) {
    shiduh_json(429, array(
        'error' => 'За последний час вы обновили ' . $recent . ' резюме. '
                 . 'Продолжите чуть позже.',
    ));
}

$profile_lines = summary_profile_digest($db, $anketa_id);
$chat_lines = summary_chat_excerpt($db, (int)$anketa['User_ID']);
if (!$profile_lines && !$chat_lines) {
    shiduh_json(409, array('error' => 'Анкета пока пустая — резюме составить не из чего'));
}

$res = ai_chat(summary_build_messages($profile_lines, $chat_lines));
if (!$res['ok']) {
    shiduh_json(503, array('error' => $res['error'] ?: 'Сервис ИИ временно недоступен'));
}
$text = trim((string)$res['content']);
if ($text === '') {
    shiduh_json(503, array('error' => 'Модель вернула пустое резюме, попробуйте ещё раз'));
}

$meta = array(
    'generated_at' => $db_now_str,      // время БД, а не PHP
    'generated_at_ts' => $db_now,       // для сравнений — без часовых поясов
    'generated_by' => $uid,
    'provider' => $res['provider'],
    'model' => ai_config()['model'],
    'tokens' => (int)$res['usage']['total_tokens'],
    'profile_fields' => count($profile_lines),
    'chat_messages' => count($chat_lines),
);
$previous = (string)$db->get_var(
    "SELECT `AI_summary` FROM `Message204` WHERE `Message_ID`=$anketa_id"
);
if (!summary_store($db, $anketa_id, $text, $meta)) {
    shiduh_json(500, array('error' => 'Не удалось сохранить резюме'));
}

// Аудит: кто и когда пересчитал резюме. Значения обрезаем — цель журнала
// объяснить изменение, а не хранить вторую копию текста.
$db->query(
    "INSERT INTO `ai_field_writes` SET "
    . "`user_id`=" . (int)$anketa['User_ID'] . ", `field`='AI_summary', "
    . "`old_value`='" . $db->prepare(mb_substr($previous, 0, 200)) . "', "
    . "`new_value`='" . $db->prepare(mb_substr($text, 0, 200)) . "', "
    . "`source`='ai', `created_at`=NOW()"
);
if ($db->is_error) {
    shiduh_log('ai', 'не записан журнал пересчёта резюме: анкета ' . $anketa_id);
}

shiduh_json(200, array('ok' => true, 'summary' => $text, 'meta' => $meta));
