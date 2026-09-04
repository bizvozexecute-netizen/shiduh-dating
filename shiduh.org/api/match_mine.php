<?php
/*
 * «Мои пары» для кандидата (этап «До идеала», B8; логика — Э3.4).
 *
 * Раньше пары считались при КАЖДОМ открытии страницы консультанта: жёсткие
 * фильтры по всей таблице анкет плюс ранжирование — ради блока, до которого
 * человек в чате часто не доходит. Теперь страница печатает только каркас,
 * а список запрашивается отсюда, когда блок попадает в поле зрения.
 *
 * Тот же движок match_list (Э3.4-b: отдельно ничего не считаем), но
 * ОБЕЗЛИЧЕННО: ни имени, ни id анкеты, ни ссылки — только возраст, город и
 * совпадение. Знакомство ведёт шадхан; пары, отклонённые ведущим шадханом
 * анкеты, кандидату тоже не показываются.
 *
 * GET и только для себя: анкета — первая проверенная модератором анкета
 * самого пользователя, никакие id извне не принимаются.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/match_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

global $current_user;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}

$db = $nc_core->db;

$anketa = $db->get_row(
    "SELECT `Message_ID`, `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3`, "
    . "`Gender`, `Date_of_birth`, `City`, `Are_you_ready_to_move`, "
    . "`Search_age_start`, `Search_age_end`, `Kashrut_level`, `Shabbat_level`, "
    . "`Psychological_profile` "
    . "FROM `Message204` WHERE `User_ID`=$uid AND `Checked`=1 "
    . "ORDER BY `Message_ID` LIMIT 1",
    ARRAY_A
);

if (!$anketa) {
    shiduh_json(200, array('ok' => true, 'has_anketa' => false, 'pairs' => array()));
}

$lead = (int)$anketa['Shadhan_1'];
if (!$lead) { $lead = (int)$anketa['Shadhan_2']; }
if (!$lead) { $lead = (int)$anketa['Shadhan_3']; }

$shadhan_name = '';
if ($lead) {
    $shadhan_name = (string)$db->get_var("SELECT `Name` FROM `User` WHERE `User_ID`=$lead");
}

$pairs = array();
foreach (match_list($db, $anketa, 5, $lead) as $i => $p) {
    $pct = (int)round((float)$p['score'] * 100);
    $pairs[] = array(
        'n' => $i + 1,
        'age' => (int)$p['age'],
        'city' => (string)$p['city'],
        // Нулевой балл — не «0%»: человек прочтёт это как «я никому не
        // подхожу», хотя это просто непройденный опрос.
        'score_pct' => $pct > 0 ? $pct : null,
    );
}

/*
 * Не кэшировать. Кэш браузера ключуется по адресу, а не по cookie: на общем
 * компьютере следующий вошедший получил бы пары предыдущего. Блок и так
 * грузится один раз на просмотр страницы — выигрыш от max-age нулевой.
 */
header('Cache-Control: private, no-store');
shiduh_json(200, array(
    'ok' => true,
    'has_anketa' => true,
    'shadhan_name' => $shadhan_name,
    'pairs' => $pairs,
));
