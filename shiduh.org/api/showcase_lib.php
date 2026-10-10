<?php
/*
 * Витрина кандидатов и «Мои кандидаты» (этап «До идеала», A4).
 *
 * Что было: список тянул ВСЕ анкеты без LIMIT (~1200 строк), возраст
 * отсеивался в PHP после выборки, «анкета проверена и владелец проверен»
 * проверялись по get_by_id на каждой строке (до семи запросов на строку).
 * Постраничность ядра (SQL_CALC_FOUND_ROWS … LIMIT) при этом была
 * невозможна: страница из 24 строк после PHP-фильтра становилась «дырявой».
 *
 * Здесь: возраст и владелец уходят в SQL, ядро листает страницами, а
 * пейджер печатается из переменных ядра ($totRows/$begRow/$endRow/
 * $prevLink/$nextLink).
 */

require_once dirname(__FILE__) . '/lk_lib.php';
require_once dirname(__FILE__) . '/auth_rules_lib.php';

if (!defined('MATCH_MIN_AGE')) {
    // тот же порог, что в подборе (api/match_lib.php) и при регистрации
    define('MATCH_MIN_AGE', AUTH_MIN_AGE);
}
define('SHOWCASE_PER_PAGE', 24);
define('SHOWCASE_MAX_AGE', 120);

/*
 * Границы возраста из $_GET['age'] = [от, до]. Нижняя — не меньше
 * совершеннолетия: анкеты с датой «по умолчанию» (2024) на витрину не
 * попадают. $required=false — без параметра фильтр не применяется вовсе
 * (для «Моих кандидатов»: шадхан должен видеть и анкеты с битой датой,
 * чтобы их починить).
 */
function showcase_age_bounds($age, $required = true) {
    $from = isset($age[0]) && $age[0] !== '' ? (int)$age[0] : null;
    $to   = isset($age[1]) && $age[1] !== '' ? (int)$age[1] : null;
    if (!$required && $from === null && $to === null) {
        return null;
    }
    $from = max(MATCH_MIN_AGE, (int)$from);
    $to   = ($to === null || $to <= 0) ? SHOWCASE_MAX_AGE : min(SHOWCASE_MAX_AGE, $to);
    if ($to < $from) {
        // просили диапазон целиком ниже порога — пусто, а не «MATCH_MIN_AGE»
        $to = $from - 1;
    }
    return array($from, $to);
}

/* Условие возраста для WHERE; $alias — алиас таблицы анкет в запросе ядра. */
function showcase_age_sql($alias, $from, $to) {
    $from = (int)$from;
    $to = (int)$to;
    return "($alias.`Date_of_birth` IS NOT NULL AND $alias.`Date_of_birth` NOT LIKE '0000%' "
        . "AND TIMESTAMPDIFF(YEAR, $alias.`Date_of_birth`, CURDATE()) BETWEEN $from AND $to)";
}

/* Владелец анкеты проверен — вместо get_by_id(...,'Checked') на каждой строке. */
function showcase_owner_join() {
    return 'INNER JOIN `User` AS u ON u.`User_ID` = a.`User_ID` AND u.`Checked` = 1';
}

/*
 * Пейджер. Ссылки ядра ($prevLink/$nextLink) уже с &amp; — печатаем как
 * есть. Показывается только когда страниц больше одной.
 */
function showcase_pager($totRows, $begRow, $endRow, $recNum, $prevLink, $nextLink) {
    $totRows = (int)$totRows;
    $recNum = (int)$recNum;
    if ($recNum <= 0 || $totRows <= $recNum) {
        return '';
    }
    $prev = (string)$prevLink;
    $next = (string)$nextLink;
    // curPos за концом списка: не печатать «10000–1200 из 1200»
    $begRow = min((int)$begRow, $totRows);
    $endRow = min((int)$endRow, $totRows);
    $h = '<nav class="sh-pager" aria-label="Страницы списка">';
    $h .= $prev !== ''
        ? '<a class="button button--sm button--secondary sh-pager__link" rel="prev" href="' . $prev . '">Назад</a>'
        : '<span class="button button--sm button--secondary sh-pager__link is-disabled" aria-disabled="true">Назад</span>';
    $h .= '<span class="sh-pager__state">' . (int)$begRow . '–' . (int)$endRow . ' из ' . $totRows . '</span>';
    $h .= $next !== ''
        ? '<a class="button button--sm button--secondary sh-pager__link" rel="next" href="' . $next . '">Дальше</a>'
        : '<span class="button button--sm button--secondary sh-pager__link is-disabled" aria-disabled="true">Дальше</span>';
    $h .= '</nav>';
    return $h;
}

/* Сколько дней анкете — для метки «новая». */
function showcase_days_since($y, $m, $d) {
    $ts = mktime(0, 0, 0, (int)$m, (int)$d, (int)$y);
    return $ts ? (int)floor((time() - $ts) / 86400) : 9999;
}

/* Возраст для печати: пусто, если дата битая. */
function showcase_age_text($dob) {
    $dob = (string)$dob;
    if ($dob === '' || strpos($dob, '0000') === 0) {
        return '';
    }
    $ts = strtotime($dob);
    if (!$ts) {
        return '';
    }
    $age = (int)date('Y') - (int)date('Y', $ts);
    if ((int)date('md') < (int)date('md', $ts)) {
        $age--;
    }
    return $age >= 0 && $age <= SHOWCASE_MAX_AGE ? (string)$age : '';
}

/*
 * Приёмка 2026-10: «Поиск кандидатов» у кандидата открывается сразу списком
 * тех, кто подходит по его анкете — по формуле подбора (жёсткие фильтры +
 * совпадение профилей, api/match_lib.php), от большего процента к меньшему.
 * Возвращает array('ids' => [порядок], 'pct' => [id => процент или null])
 * или null, если своей проверенной анкеты нет (тогда обычная витрина).
 * Процент null — профиль ещё не посчитан у одной из сторон (опрос не пройден):
 * «0%» читался бы как «вы никому не подходите». Настоящий 0% показывается.
 */
function showcase_match_order($db, $uid) {
    require_once dirname(__FILE__) . '/match_lib.php';
    $own = match_own_anketa($db, $uid);
    if (!$own) {
        return null;
    }
    list($anketa, $lead) = $own;
    $ids = array();
    $pct = array();
    foreach (match_list($db, $anketa, 500, $lead) as $r) {
        $id = (int)$r['anketa_id'];
        $ids[] = $id;
        $pct[$id] = $r['profiled'] ? (int)round((float)$r['score'] * 100) : null;
    }
    return array('ids' => $ids, 'pct' => $pct);
}
