<?php
/*
 * Подбор пар (ТЗ Э3.1 + Э3.2-a).
 *
 * Слой 1 — жёсткие фильтры, и они ОБЯЗАТЕЛЬНЫ: пара вне фильтров не должна
 * попадать в выдачу никогда («Готовность Э3»). Фильтры считает SQL по
 * индексу idx_match(Gender, Country, Date_of_birth) из миграции 004:
 *   - противоположный пол;
 *   - возраст АСИММЕТРИЧНО: Б в диапазоне А И А в диапазоне Б; незаданный
 *     диапазон не ограничивает (диапазон задали ~40% анкет — трактовать
 *     пустое как запрет значило бы выкинуть больше половины базы);
 *   - география: один город (без регистра) ИЛИ у любой из сторон готовность
 *     к переезду. Пустой город НЕ считается совпадением;
 *   - обе анкеты Checked=1, сам с собой не матчится.
 *
 * Религиозная совместимость — по МАТРИЦЕ в PHP (Э3.1-b): дефолт до ответа
 * раввина — соседние уровни совместимы (|a-b| <= 1), крайние
 * («не соблюдаю» × «строгий») нет. Уровень 0/пусто = «не указано» и
 * совместим со всем: новые поля кашрута/шаббата пока не заполнены ни у
 * одной анкеты, строгая трактовка опустошила бы выдачу. Когда раввин даст
 * настоящие матрицы, меняется ТОЛЬКО match_religion_compatible().
 *
 * Слой 2 — ранжирование (Э3.2-a):
 *   score = 0.4*values_match + 0.3*love_language_match + 0.3*semantic
 * Совпадение профилей — 1 - (L1-расстояние процентов / 200): у двух
 * распределений по 100% максимальное расхождение равно 200. semantic пока 0
 * (эмбеддинги ждут ключа); веса НЕ перенормируются, чтобы баллы не скакнули
 * при подключении эмбеддингов.
 */

require_once dirname(__FILE__) . '/profile_view_lib.php';

/* Веса компонентов (Э3.2-a). В конфиг выносить рано: менять их будет Э3.3-b. */
function match_weights() {
    return array('values' => 0.4, 'love' => 0.3, 'semantic' => 0.3);
}

/*
 * Совместимость уровней соблюдения по матрице-дефолту.
 * 0 = «не указано» — совместимо со всем (см. шапку файла).
 */
function match_religion_compatible($a, $b) {
    $a = (int)$a;
    $b = (int)$b;
    if ($a === 0 || $b === 0) {
        return true;
    }
    return abs($a - $b) <= 1;
}

/*
 * Жёсткие фильтры: SQL по индексу + религиозная матрица.
 * Возвращает массив строк-кандидатов (анкета + владелец + шадхан + профиль).
 */
function match_hard_filter($db, $anketa, $limit = 600) {
    $id = (int)$anketa['Message_ID'];
    $gender = (int)$anketa['Gender'];
    if ($gender !== 1 && $gender !== 2) {
        return array();
    }
    $want = ($gender === 1) ? 2 : 1;
    $limit = (int)$limit;

    $city = trim((string)$anketa['City']);
    $city_esc = $db->prepare(mb_strtolower($city));
    $i_move = ((int)$anketa['Are_you_ready_to_move'] === 1) ? 1 : 0;

    // география: общий город ИЛИ чья-то релокация; пустой город не совпадает
    $geo = $i_move
        ? "1=1"
        : "((" . ($city !== '' ? "LOWER(m.`City`)='$city_esc'" : "0") . ") OR m.`Are_you_ready_to_move`=1)";

    // возраст обеих сторон
    $my_age = "TIMESTAMPDIFF(YEAR, '" . $db->prepare($anketa['Date_of_birth']) . "', CURDATE())";
    $their_age = "TIMESTAMPDIFF(YEAR, m.`Date_of_birth`, CURDATE())";

    $my_start = (int)$anketa['Search_age_start'];
    $my_end = (int)$anketa['Search_age_end'];
    $age_mine = "1=1";
    if ($my_start > 0) {
        $age_mine .= " AND $their_age >= $my_start";
    }
    if ($my_end > 0) {
        $age_mine .= " AND $their_age <= $my_end";
    }
    // Их диапазон: незаданный не ограничивает. IFNULL обязателен: у 674 из
    // 1133 анкет поле NULL, а CAST(NULL AS UNSIGNED)=NULL превращает всё
    // условие в NULL — строка молча выпадала бы из выдачи (ловил тест).
    $age_theirs = "(CAST(IFNULL(m.`Search_age_start`,'') AS UNSIGNED) = 0"
        . " OR $my_age >= CAST(m.`Search_age_start` AS UNSIGNED))"
        . " AND (CAST(IFNULL(m.`Search_age_end`,'') AS UNSIGNED) = 0"
        . " OR $my_age <= CAST(m.`Search_age_end` AS UNSIGNED))";

    $db->query(
        "SELECT m.`Message_ID`, m.`User_ID`, m.`Name`, m.`City`, m.`Gender`, "
        . "m.`Date_of_birth`, m.`Kashrut_level`, m.`Shabbat_level`, "
        . "m.`Shadhan_1`, m.`Psychological_profile`, "
        . "$their_age AS age "
        . "FROM `Message204` m "
        . "WHERE m.`Checked`=1 AND m.`Gender`=$want AND m.`Message_ID`<>$id "
        . "AND m.`Date_of_birth` IS NOT NULL AND m.`Date_of_birth` NOT LIKE '0000%' "
        . "AND $geo AND $age_mine AND ($age_theirs) "
        . "ORDER BY m.`Message_ID` LIMIT $limit",
        ARRAY_A
    );
    $rows = (array)$db->last_result;

    // религиозная матрица — в PHP, чтобы жила в одном месте
    $out = array();
    foreach ($rows as $r) {
        if (!match_religion_compatible($anketa['Kashrut_level'], $r['Kashrut_level'])) {
            continue;
        }
        if (!match_religion_compatible($anketa['Shabbat_level'], $r['Shabbat_level'])) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/*
 * Совпадение двух распределений процентов: 1 - L1/200 в [0..1].
 * Если у кого-то профиля нет — 0 (совпадение неизвестно, а не «идеально»).
 */
function match_distribution_similarity($a, $b) {
    $a = is_array($a) ? $a : array();
    $b = is_array($b) ? $b : array();
    if (!$a || !$b) {
        return 0.0;
    }
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $distance = 0;
    foreach ($keys as $k) {
        $distance += abs((int)(isset($a[$k]) ? $a[$k] : 0) - (int)(isset($b[$k]) ? $b[$k] : 0));
    }
    return max(0.0, 1.0 - $distance / 200.0);
}

/*
 * Балл пары (Э3.2-a) с разбивкой. Профили передаются уже разобранными
 * (или null). semantic_similarity — честный 0 до появления эмбеддингов,
 * а не выдуманное число.
 */
function match_score($profile_a, $profile_b) {
    $w = match_weights();
    $values = match_distribution_similarity(
        $profile_a ? (array)$profile_a['family_values_scores'] : null,
        $profile_b ? (array)$profile_b['family_values_scores'] : null
    );
    $love = match_distribution_similarity(
        $profile_a ? (array)$profile_a['love_languages'] : null,
        $profile_b ? (array)$profile_b['love_languages'] : null
    );
    $semantic = 0.0;

    return array(
        'score' => round($w['values'] * $values + $w['love'] * $love
                       + $w['semantic'] * $semantic, 4),
        'breakdown' => array(
            'values_match' => round($values, 4),
            'love_language_match' => round($love, 4),
            'semantic_similarity' => $semantic,
        ),
    );
}

/* Разбор профиля из строки анкеты (сырой JSON из БД, может быть пуст/бит). */
function match_parse_profile($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/*
 * Полный подбор для анкеты: фильтры + ранжирование.
 * Сортировка по убыванию балла, при равенстве по Message_ID — чтобы
 * порядок был воспроизводим («Готовность Э3»).
 */
function match_list($db, $anketa, $limit = 50) {
    $candidates = match_hard_filter($db, $anketa);
    $profile_a = match_parse_profile($anketa['Psychological_profile']);

    $out = array();
    foreach ($candidates as $r) {
        $scored = match_score($profile_a, match_parse_profile($r['Psychological_profile']));
        $shadhan_id = (int)$r['Shadhan_1'];
        $out[] = array(
            'anketa_id' => (int)$r['Message_ID'],
            'name' => (string)$r['Name'],
            'age' => (int)$r['age'],
            'city' => (string)$r['City'],
            'score' => $scored['score'],
            'breakdown' => $scored['breakdown'],
            // Э3.3-c: кто ведёт кандидата
            'shadhan' => $shadhan_id
                ? (string)$db->get_var("SELECT `Name` FROM `User` WHERE `User_ID`=$shadhan_id")
                : '',
        );
    }

    usort($out, function ($x, $y) {
        if ($x['score'] !== $y['score']) {
            return ($x['score'] < $y['score']) ? 1 : -1;
        }
        return ($x['anketa_id'] < $y['anketa_id']) ? -1 : 1;
    });

    return array_slice($out, 0, max(1, (int)$limit));
}
