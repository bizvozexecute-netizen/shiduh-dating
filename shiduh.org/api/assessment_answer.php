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
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/scoring_lib.php';
require_once dirname(__FILE__) . '/cases_lib.php';
require_once dirname(__FILE__) . '/chat_lib.php';   // chat_chips(): подсказки чата после ответа

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

// Каждый ответ — обращение к модели (а на смене версии ещё одно, за текстом
// психотипа). Потолок за сутки — общий с путём диалога (Э2.2-c).
if (scoring_daily_cap_reached($db, $uid)) {
    shiduh_json(429, array(
        'error' => 'Слишком много ответов за сутки. Продолжите завтра.',
    ));
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

/*
 * Уже отвеченный в текущей версии кейс — либо осознанный повтор («Пройти
 * заново», клиент шлёт restart=1), либо УСТАРЕВШАЯ ВКЛАДКА: человек ответил
 * на этот сценарий в чате в другом окне, а здесь всё ещё висит старый
 * вопрос. Без различения второе тихо заводило новую версию из одного
 * ответа, и шадхан видел вырожденный профиль. Устаревшей вкладке отдаём
 * актуальное состояние — она покажет правильный вопрос.
 */
$restart = (string)$nc_core->input->fetch_post('restart') === '1';
$current_version = scoring_current_version($db, $uid);
if (!$restart && $current_version > 0) {
    $already = (int)$db->get_var(
        "SELECT COUNT(*) FROM `assessment_answers` WHERE `user_id`=$uid "
        . "AND `case_id`=$case_id AND `version`=$current_version"
    );
    if ($already) {
        $state = cases_survey_state($db, $uid);
        shiduh_json(409, array(
            'error' => 'На этот сценарий вы уже ответили — вопрос обновлён.',
            'code' => 'stale_case',
            'next' => $state['next'],
            'answered' => $state['answered'],
            'total' => $state['total'],
            'chips' => chat_chips($db, $uid, array(
                'survey' => $state, 'thread' => chat_current_thread($db, $uid),
            )),
        ));
    }
}

// Общий конвейер с путём диалога (Э2.2-c): классификация -> баллы ->
// профиль КОДОМ -> психотип раз на версию -> фиксация в анкете.
$result = scoring_record_answer($db, $uid, $case_row, $answer);
if (!$result['answer_id']) {
    shiduh_json(500, array('error' => 'Не удалось сохранить ответ'));
}
$classification_ok = $result['classified'];
$version = $result['version'];
$profile = $result['profile'];

// Следующий вопрос и прогресс отдаём в этом же ответе — отдельный эндпоинт
// ради этого заводить незачем (Э2.4).
$state = cases_survey_state($db, $uid);

// Текст психотипа написан ДЛЯ ШАДХАНА и обращён к нему — самому человеку он не
// показывается. В HTML мы его и не печатаем, но и в JSON отдавать нельзя:
// страница кандидата получает этот ответ, и текст был бы виден в отладчике.
$public_profile = $profile;
if (is_array($public_profile)) {
    unset($public_profile['psychotype_text']);
}

shiduh_json(200, array(
    'ok' => true,
    'classified' => $classification_ok,
    'version' => $version,
    'profile' => $public_profile,
    'next' => $state['next'],
    'answered' => $state['answered'],
    'total' => $state['total'],
    // Подсказки чата зависят от опроса («Ответить на вопрос консультанта»):
    // отдаём их здесь же, чтобы блок чата обновился без второго запроса.
    // Состояние опроса уже посчитано — передаём, а не считаем второй раз;
    // диалог — текущий, иначе в подсказки попал бы вопрос из архивного.
    'chips' => chat_chips($db, $uid, array(
        'survey' => $state, 'thread' => chat_current_thread($db, $uid),
    )),
));
