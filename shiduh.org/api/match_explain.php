<?php
/*
 * Обоснование пары для шадхана (ТЗ Э3.3-a).
 *
 * POST {anketa_id, target_id}, только шадхан, ведущий anketa_id. Пара обязана
 * проходить жёсткие фильтры подбора — обоснование строится только по
 * реальным данным («Готовность Э3»).
 *
 * Деньги: ответ LLM кэшируется по хэшу фактов (match_explanations) — пара
 * объясняется моделью один раз, пока профили не изменились. Без ключа
 * (провайдер stub) отдаётся детерминированный текст из посчитанных
 * компонентов — честно и бесплатно, в кэш не пишется.
 *
 * В модель уходят ТОЛЬКО белый список фактов и посчитанные компоненты —
 * без имён, идентификаторов и чувствительных полей (Раздел X, 152-ФЗ).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/match_lib.php';
require_once dirname(__FILE__) . '/ai_client.php';

/* Потолок генераций на шадхана в час: кэш-попадания бесплатны и не считаются. */
define('EXPLAIN_MAX_PER_HOUR', 30);

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
$target_id = (int)$nc_core->input->fetch_post('target_id');
if ($anketa_id <= 0 || $target_id <= 0 || $anketa_id === $target_id) {
    shiduh_json(400, array('error' => 'Некорректная пара'));
}

// имена НЕ выбираются: их нет в фактах по построению
$fields = "`Message_ID`, `User_ID`, `Gender`, `Date_of_birth`, `City`, "
    . "`Are_you_ready_to_move`, `Search_age_start`, `Search_age_end`, "
    . "`Kashrut_level`, `Shabbat_level`, `Psychological_profile`, "
    . "TIMESTAMPDIFF(YEAR, `Date_of_birth`, CURDATE()) AS age";

$anketa = $db->get_row(
    "SELECT $fields, `Shadhan_1`, `Shadhan_2`, `Shadhan_3` "
    . "FROM `Message204` WHERE `Message_ID`=$anketa_id AND `Checked`=1",
    ARRAY_A
);
if (!$anketa) {
    shiduh_json(404, array('error' => 'Анкета не найдена'));
}
$leads = in_array($uid, array(
    (int)$anketa['User_ID'], (int)$anketa['Shadhan_1'],
    (int)$anketa['Shadhan_2'], (int)$anketa['Shadhan_3'],
), true);
if (!$leads) {
    shiduh_json(403, array('error' => 'Эта анкета не в вашем ведении'));
}

$target = $db->get_row(
    "SELECT $fields FROM `Message204` WHERE `Message_ID`=$target_id AND `Checked`=1",
    ARRAY_A
);
if (!$target) {
    shiduh_json(404, array('error' => 'Вторая анкета пары не найдена'));
}

// пара обязана проходить жёсткие фильтры: обоснование только по реальным
// данным, а не по любой произвольной паре. Точечный запрос ($only_id), а не
// проход по всей базе: этот эндпоинт дёргается по кнопке на каждую пару
if (!match_hard_filter($db, $anketa, 1, $target_id)) {
    shiduh_json(400, array('error' => 'Пара не проходит жёсткие фильтры подбора'));
}

// отклонённую пару не обосновываем: шадхан сам убрал её из выдачи,
// тратить генерацию на неё незачем
$pair_key = min($anketa_id, $target_id) . '-' . max($anketa_id, $target_id);
$rejected = match_rejected_pairs($db, $uid);
if (isset($rejected[$pair_key])) {
    shiduh_json(400, array('error' => 'Эта пара отклонена вами — верните её в выдачу, чтобы получить обоснование'));
}

// Страховка Раздела X: чувствительные поля из реестра вырезаются из строк
// анкеты ДО сборки фактов — даже если кто-то допишет их в $fields выше, во
// внешнюю модель они не уедут. Города скрабим как свободный текст (человек
// мог вписать туда телефон) — тем же правилом, что и резюме Э1.4.
require_once dirname(__FILE__) . '/summary_lib.php';
foreach ((array)include dirname(__FILE__) . '/ai_sensitive_fields.php' as $nc_sf) {
    unset($anketa[$nc_sf], $target[$nc_sf]);
}
$anketa['City'] = summary_scrub($anketa['City']);
$target['City'] = summary_scrub($target['City']);

$scored = match_score(
    match_parse_profile($anketa['Psychological_profile']),
    match_parse_profile($target['Psychological_profile']),
    match_weights($db, $uid)
);
$cfg = ai_config();
// факты нормализованы по паре (не по направлению запроса), модель — в соли:
// её смена в настройках инвалидирует кэш
$facts = match_explain_facts($anketa, $target, $scored);
$hash = match_explain_hash($facts, $cfg['model']);
$hash_esc = $db->prepare($hash);

$a = min($anketa_id, $target_id);
$b = max($anketa_id, $target_id);

// кэш: пара уже объяснена по этим же фактам — модель не дёргаем.
// Пустая explanation — чья-то живая резервация (см. ниже).
$row = $db->get_row(
    "SELECT `explanation`, TIMESTAMPDIFF(SECOND, `created_at`, NOW()) AS age "
    . "FROM `match_explanations` "
    . "WHERE `anketa_a`=$a AND `anketa_b`=$b AND `source_hash`='$hash_esc'",
    ARRAY_A
);
if ($db->is_error) {
    // fail-closed: сломанное хранилище означало бы «без кэша и без лимита» —
    // молча жечь деньги на каждый клик нельзя
    shiduh_log('match', 'ОШИБКА чтения кэша обоснований: ' . $db->last_error);
    shiduh_json(500, array('error' => 'Хранилище обоснований недоступно'));
}
if ($row && (string)$row['explanation'] !== '') {
    shiduh_log('match', 'шадхан ' . $uid . ' получил обоснование пары ' . $a . '-' . $b . ' из кэша');
    shiduh_json(200, array(
        'ok' => true, 'explanation' => (string)$row['explanation'], 'cached' => true,
        'provider' => 'cache', 'score' => $scored['score'],
        'breakdown' => $scored['breakdown'],
    ));
}
if ($row) {
    if ((int)$row['age'] < 120) {
        // параллельный запрос уже генерирует эту пару — не платим второй раз
        shiduh_json(409, array('error' => 'Обоснование уже готовится, попробуйте через минуту'));
    }
    // мёртвая резервация (генерация упала, не дочистив за собой) — забираем
    $db->query("DELETE FROM `match_explanations` WHERE `anketa_a`=$a AND `anketa_b`=$b "
        . "AND `source_hash`='$hash_esc' AND `explanation`=''");
}

if ($cfg['provider'] !== 'openrouter' || $cfg['api_key'] === '') {
    // без ключа (в т.ч. провайдер выбран, а ключ ещё не задан): честный
    // детерминированный текст из компонентов — бесплатно и без кэша
    shiduh_json(200, array(
        'ok' => true, 'explanation' => match_explain_stub_text($facts),
        'cached' => false, 'provider' => 'stub',
        'score' => $scored['score'], 'breakdown' => $scored['breakdown'],
    ));
}

/*
 * Резервация слота ДО вызова модели (Б3 ревью): вставляем строку-заготовку
 * с пустой explanation. Она одновременно (1) не даёт параллельному запросу
 * той же пары заплатить второй раз (UNIQUE + проверка выше) и (2) сразу
 * входит в часовой счётчик — сто параллельных запросов не проскочат лимит,
 * прочитав ноль до первой вставки. MyISAM без транзакций, но каждый
 * INSERT/DELETE атомарен — этого достаточно.
 */
$db->query(
    "INSERT IGNORE INTO `match_explanations` SET "
    . "`anketa_a`=$a, `anketa_b`=$b, `shadhan_id`=$uid, "
    . "`source_hash`='$hash_esc', `explanation`='', `created_at`=NOW()"
);
if ($db->is_error) {
    shiduh_log('match', 'ОШИБКА резервации кэша обоснований: ' . $db->last_error);
    shiduh_json(500, array('error' => 'Хранилище обоснований недоступно'));
}
if (!$db->rows_affected) {
    // кто-то занял пару между нашей проверкой и вставкой
    shiduh_json(409, array('error' => 'Обоснование уже готовится, попробуйте через минуту'));
}
$drop_reservation = function () use ($db, $a, $b, $hash_esc) {
    $db->query("DELETE FROM `match_explanations` WHERE `anketa_a`=$a AND `anketa_b`=$b "
        . "AND `source_hash`='$hash_esc' AND `explanation`=''");
};

// потолок генераций: каждая стоит денег; кэш-попадания сюда не доходят.
// Своя резервация уже в счётчике, поэтому строго БОЛЬШЕ лимита.
$recent = (int)$db->get_var(
    "SELECT COUNT(*) FROM `match_explanations` WHERE `shadhan_id`=$uid "
    . "AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
);
if ($recent > EXPLAIN_MAX_PER_HOUR) {
    $drop_reservation();
    shiduh_json(429, array(
        'error' => 'За последний час вы запросили ' . ($recent - 1)
                 . ' обоснований. Продолжите чуть позже.',
    ));
}

$system = AI_SAFETY_PREAMBLE . "\n\n"
    . 'Вы помогаете шадхану еврейского сервиса знакомств оценить пару. '
    . 'По приведённым фактам объясните в 2–4 предложениях, чем пара может '
    . 'подойти друг другу и на что обратить внимание при знакомстве. '
    . 'Опирайтесь ТОЛЬКО на факты, ничего не выдумывайте. '
    . 'Имён не называйте — пишите «он» и «она».';

$res = ai_chat(
    array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => match_explain_prompt_text($facts)),
    ),
    array('temperature' => 0.3)
);
$text = $res['ok'] ? match_explain_sanitize($res['content']) : '';
if ($text === '') {
    $drop_reservation();    // неудача не занимает слот и не поселяется в кэше
    shiduh_json(502, array(
        'error' => $res['error'] ? $res['error'] : 'Сервис ИИ не ответил',
    ));
}

$db->query(
    "UPDATE `match_explanations` SET "
    . "`explanation`='" . $db->prepare($text) . "', "
    . "`model`='" . $db->prepare($cfg['model']) . "' "
    . "WHERE `anketa_a`=$a AND `anketa_b`=$b AND `source_hash`='$hash_esc'"
);
if ($db->is_error) {
    // деньги уже потрачены — текст отдаём, но о сломанном кэше кричим в лог
    shiduh_log('match', 'ОШИБКА записи кэша обоснований: ' . $db->last_error);
}

shiduh_log('match', 'шадхан ' . $uid . ' сгенерировал обоснование пары ' . $a . '-' . $b);

shiduh_json(200, array(
    'ok' => true, 'explanation' => $text, 'cached' => false,
    'provider' => $res['provider'], 'score' => $scored['score'],
    'breakdown' => $scored['breakdown'],
));
