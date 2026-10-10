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

/* Минимальный возраст в подборе — тот же порог, что при регистрации
   (AUTH_MIN_AGE, см. match_hard_filter). */
require_once dirname(__FILE__) . '/auth_rules_lib.php';
if (!defined('MATCH_MIN_AGE')) {
    define('MATCH_MIN_AGE', AUTH_MIN_AGE);
}

/*
 * Веса компонентов (Э3.2-a) с эвристикой Э3.3-b: причина отклонения пары
 * понижает вес компонента, который дал ложный сигнал, — В ПОДБОРЕ ЭТОГО
 * шадхана. Отклонил три пары «не совпали ценности» → компонент values ему
 * очевидно мало помогает, приглушаем.
 *
 * Эвристика намеренно простая и ограниченная: −0.03 за отклонение, не ниже
 * пола 0.15 (компонент никогда не выключается совсем). Причины geography и
 * age на веса не влияют — это жёсткие фильтры, а не компоненты балла.
 */
function match_weights($db = null, $shadhan_id = 0) {
    $w = array('values' => 0.4, 'love' => 0.3, 'semantic' => 0.3);
    $shadhan_id = (int)$shadhan_id;
    if (!$db || !$shadhan_id) {
        return $w;
    }
    $db->query(
        "SELECT `reason`, COUNT(*) AS n FROM `match_feedback` "
        . "WHERE `shadhan_id`=$shadhan_id AND `action`='rejected' "
        . "AND `reason` IN ('values','love_language') GROUP BY `reason`",
        ARRAY_A
    );
    foreach ((array)$db->last_result as $r) {
        $key = ($r['reason'] === 'values') ? 'values' : 'love';
        $w[$key] = max(0.15, $w[$key] - 0.03 * (int)$r['n']);
    }
    return $w;
}

/* Пары, отклонённые этим шадханом: set ключей "a-b" (a < b). */
function match_rejected_pairs($db, $shadhan_id) {
    $shadhan_id = (int)$shadhan_id;
    if (!$shadhan_id) {
        return array();
    }
    $db->query(
        "SELECT `anketa_a`, `anketa_b` FROM `match_feedback` "
        . "WHERE `shadhan_id`=$shadhan_id AND `action`='rejected'",
        ARRAY_A
    );
    $out = array();
    foreach ((array)$db->last_result as $r) {
        $out[$r['anketa_a'] . '-' . $r['anketa_b']] = true;
    }
    return $out;
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
function match_hard_filter($db, $anketa, $limit = 2000, $only_id = 0) {
    $id = (int)$anketa['Message_ID'];
    $gender = (int)$anketa['Gender'];
    if ($gender !== 1 && $gender !== 2) {
        return array();
    }
    $want = ($gender === 1) ? 2 : 1;
    $limit = (int)$limit;
    // $only_id: проверить ОДНУ конкретную пару (Э3.3-a) — те же фильтры,
    // но индексированный точечный запрос вместо прохода по всей базе
    $only = ((int)$only_id > 0) ? " AND m.`Message_ID`=" . (int)$only_id : "";

    $city = trim((string)$anketa['City']);
    // без LOWER(): коллация utf8_general_ci и так без регистра, а функция
    // на колонке лишила бы City индекса
    $city_esc = $db->prepare($city);
    $i_move = ((int)$anketa['Are_you_ready_to_move'] === 1) ? 1 : 0;

    // география: общий город ИЛИ чья-то релокация; пустой город не совпадает
    $geo = $i_move
        ? "1=1"
        : "((" . ($city !== '' ? "m.`City`='$city_esc'" : "0") . ") OR m.`Are_you_ready_to_move`=1)";

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
    // Их диапазон: незаданный не ограничивает. NULL проверяется ЯВНО: у 674
    // из 1133 анкет поле NULL, а CAST(NULL...)=NULL превращал всё условие в
    // NULL — строка молча выпадала из выдачи (82% допустимых пар; ловил тест).
    // Колонка double, поэтому без CAST-трюков: они по-разному округляют и
    // по-разному видят отрицательные значения в двух половинах одного OR.
    $age_theirs = "(m.`Search_age_start` IS NULL OR m.`Search_age_start` <= 0"
        . " OR $my_age >= m.`Search_age_start`)"
        . " AND (m.`Search_age_end` IS NULL OR m.`Search_age_end` <= 0"
        . " OR $my_age <= m.`Search_age_end`)";

    $db->query(
        "SELECT m.`Message_ID`, m.`User_ID`, m.`Name`, m.`City`, m.`Gender`, m.`Open`, "
        . "m.`Date_of_birth`, m.`Kashrut_level`, m.`Shabbat_level`, "
        // ведущий — первый заполненный из трёх слотов, не только Shadhan_1
        . "COALESCE(NULLIF(m.`Shadhan_1`,0), NULLIF(m.`Shadhan_2`,0), NULLIF(m.`Shadhan_3`,0)) AS lead_shadhan, "
        . "m.`Psychological_profile`, "
        . "$their_age AS age "
        . "FROM `Message204` m "
        . "WHERE m.`Checked`=1 AND m.`Gender`=$want AND m.`Message_ID`<>$id "
        . "AND m.`Date_of_birth` IS NOT NULL AND m.`Date_of_birth` NOT LIKE '0000%' "
        // Минимальный возраст (MATCH_MIN_AGE) — жёсткий предел, а не предпочтение: сервис ведёт
        // к браку, пары младше порога недопустимы. Тот же порог
        // отсекает мусорные даты рождения: у 224 анкет вместо даты рождения
        // стоит дата из 2020–2025 («возраст» 0–6) — очевидно, дата
        // регистрации. Без этого шадхану и кандидату показывались «пары»
        // 0–2 лет (найдено при осмотре интерфейса).
        . "AND $their_age >= " . MATCH_MIN_AGE . " "
        // Симметрично: анкете младше порога (или с мусорной датой) подбор
        // не выдаётся вовсе. Выражение константное — при таком запрашивающем
        // запрос честно вернёт ноль строк.
        . "AND $my_age >= " . MATCH_MIN_AGE . " "
        . "AND $geo AND $age_mine AND ($age_theirs)$only "
        . "ORDER BY m.`Message_ID` LIMIT $limit",   // предохранитель, а не пагинация:
                                                     // база в разы меньше
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
function match_score($profile_a, $profile_b, $weights = null) {
    $w = $weights ? $weights : match_weights();
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

/* --- обоснование пары (Э3.3-a) ---------------------------------------------
 * Факты для промпта собираются ТОЛЬКО из посчитанных компонентов и белого
 * списка: возраст, город, переезд, уровни соблюдения, ведущие измерения
 * профиля. Имён, идентификаторов и чувствительных полей (здоровье, доход)
 * здесь нет по построению — во внешнюю модель они не уходят (Раздел X).
 */

/* Топ-2 измерения группы с человекочитаемыми названиями: «Время вдвоём 60%». */
function match_explain_top($profile, $key, $group) {
    require_once dirname(__FILE__) . '/cases_lib.php';
    $arr = ($profile && isset($profile[$key])) ? (array)$profile[$key] : array();
    arsort($arr);
    $out = array();
    foreach (array_slice($arr, 0, 2, true) as $dim => $pc) {
        $out[] = cases_dimension_title($group, $dim) . ' ' . (int)$pc . '%';
    }
    return implode(', ', $out);
}

/* Одна сторона пары: белый список фактов. $row обязан содержать age. */
function match_explain_side($row) {
    $profile = match_parse_profile($row['Psychological_profile']);
    $side = array(
        'пол' => ((int)$row['Gender'] === 1) ? 'мужчина' : 'женщина',
        'возраст' => (int)$row['age'],
        'город' => (string)$row['City'],
        'готовность к переезду' => ((int)$row['Are_you_ready_to_move'] === 1) ? 'да' : 'нет',
    );
    if ((int)$row['Kashrut_level'] > 0) {
        $side['кашрут (уровень 1-4)'] = (int)$row['Kashrut_level'];
    }
    if ((int)$row['Shabbat_level'] > 0) {
        $side['шаббат (уровень 1-4)'] = (int)$row['Shabbat_level'];
    }
    if ($profile) {
        $love = match_explain_top($profile, 'love_languages', 'love_language');
        $values = match_explain_top($profile, 'family_values_scores', 'family_value');
        if ($love !== '') {
            $side['ведущие языки любви'] = $love;
        }
        if ($values !== '') {
            $side['ведущие семейные ценности'] = $values;
        }
    }
    return $side;
}

/*
 * Все факты пары: две стороны + посчитанные компоненты + география словами.
 * Стороны НОРМАЛИЗУЮТСЯ по Message_ID (меньший — «кандидат А»): кэш общий
 * для обоих направлений, иначе шадханы двух половин пары платили бы за одно
 * и то же обоснование дважды.
 */
function match_explain_facts($anketa, $target, $scored) {
    if ((int)$target['Message_ID'] < (int)$anketa['Message_ID']) {
        list($anketa, $target) = array($target, $anketa);
    }
    $city_a = trim((string)$anketa['City']);
    $city_b = trim((string)$target['City']);
    $same_city = ($city_a !== '')
        && (function_exists('mb_strtolower')
            ? mb_strtolower($city_a, 'UTF-8') === mb_strtolower($city_b, 'UTF-8')
            : strcasecmp($city_a, $city_b) === 0);
    // «города разные» утверждаем только когда оба города известны: при пустом
    // городе пара в пуле лишь благодаря чьей-то релокации, но сами города
    // могут и совпадать — модели такое выдавать нельзя («только факты»)
    if ($same_city) {
        $geo = 'живут в одном городе';
    } elseif ($city_a !== '' && $city_b !== '') {
        $geo = 'города разные, у одной из сторон есть готовность к переезду';
    } else {
        $geo = 'город указан не у обеих сторон, есть готовность к переезду';
    }
    return array(
        'кандидат А' => match_explain_side($anketa),
        'кандидат Б' => match_explain_side($target),
        'совпадение семейных ценностей'
            => (int)round($scored['breakdown']['values_match'] * 100) . '%',
        'совпадение языков любви'
            => (int)round($scored['breakdown']['love_language_match'] * 100) . '%',
        'география' => $geo,
    );
}

/*
 * Хэш фактов — ключ кэша. Версия v1 в начале: изменение СТРУКТУРЫ фактов или
 * ТЕКСТА системного промпта должно инвалидировать кэш — поднимайте версию.
 * $salt — внешние параметры генерации (модель): их смена тоже меняет ключ.
 */
function match_explain_hash($facts, $salt = '') {
    $json = json_encode($facts, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // битый UTF-8 в свободном тексте анкеты: иначе хэш стал бы константой
        // и изменение профиля перестало бы инвалидировать кэш
        $json = serialize($facts);
    }
    return md5('v1|' . $salt . '|' . $json);
}

/*
 * Санитайз текста от модели перед кэшем: соединение и база в utf8 (3 байта),
 * эмодзи и прочие 4-байтовые символы MySQL молча ОБРЕЗАЛ бы вместе со всем
 * хвостом текста (STRICT_TRANS_TABLES не строг к MyISAM) — а пустой/битый
 * кэш означает вечный промах и безлимитные платные генерации одной пары.
 */
function match_explain_sanitize($text) {
    $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string)$text);
    // preg_replace вернёт null на битом UTF-8 — тогда режем жёстче, до ASCII+кириллицы
    if ($clean === null) {
        $clean = preg_replace('/[^\x20-\x7E\x{0400}-\x{04FF}\s]/u', '', (string)$text);
    }
    return trim((string)$clean);
}

/* Факты в текст промпта: по строке на пункт, стороны — одной строкой. */
function match_explain_prompt_text($facts) {
    $lines = array('Факты о паре:');
    foreach ($facts as $label => $value) {
        if (is_array($value)) {
            $pairs = array();
            foreach ($value as $k => $v) {
                $pairs[] = $k . ': ' . $v;
            }
            $lines[] = $label . ' — ' . implode('; ', $pairs);
        } else {
            $lines[] = $label . ': ' . $value;
        }
    }
    return implode("\n", $lines);
}

/*
 * Детерминированное обоснование без LLM (провайдер stub): честный текст из
 * посчитанных компонентов. Бесплатно, поэтому в кэш не пишется.
 */
function match_explain_stub_text($facts) {
    $parts = array(
        'Совпадение семейных ценностей — ' . $facts['совпадение семейных ценностей'] . '.',
        'Совпадение языков любви — ' . $facts['совпадение языков любви'] . '.',
        ($facts['география'] === 'живут в одном городе')
            ? 'Живут в одном городе.'
            : 'Города разные, но есть готовность к переезду.',
    );
    return implode(' ', $parts);
}

/*
 * Полный подбор для анкеты: фильтры + ранжирование.
 * Сортировка по убыванию балла, при равенстве по Message_ID — чтобы
 * порядок был воспроизводим («Готовность Э3»).
 */
/*
 * Своя анкета кандидата для подбора — проверенная, первая по номеру (то же
 * правило, что у «Рекомендованных пар» в консультанте). Вместе с ней —
 * ведущий шадхан: его отклонения и веса действуют и в подборе кандидата.
 * Возвращает array(анкета, id ведущего) или null.
 */
function match_own_anketa($db, $uid) {
    $uid = (int)$uid;
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
        return null;
    }
    $lead = (int)$anketa['Shadhan_1'];
    if (!$lead) { $lead = (int)$anketa['Shadhan_2']; }
    if (!$lead) { $lead = (int)$anketa['Shadhan_3']; }
    return array($anketa, $lead);
}

function match_list($db, $anketa, $limit = 50, $shadhan_id = 0) {
    $candidates = match_hard_filter($db, $anketa);
    $profile_a = match_parse_profile($anketa['Psychological_profile']);
    $weights = match_weights($db, $shadhan_id);
    $rejected = match_rejected_pairs($db, $shadhan_id);
    $my_id = (int)$anketa['Message_ID'];

    $out = array();
    foreach ($candidates as $r) {
        // отклонённая этим шадханом пара исключается из его выдачи
        $key = min($my_id, (int)$r['Message_ID']) . '-' . max($my_id, (int)$r['Message_ID']);
        if (isset($rejected[$key])) {
            continue;
        }
        $profile_b = match_parse_profile($r['Psychological_profile']);
        $scored = match_score($profile_a, $profile_b, $weights);
        $out[] = array(
            'anketa_id' => (int)$r['Message_ID'],
            'name' => (string)$r['Name'],
            'age' => (int)$r['age'],
            'city' => (string)$r['City'],
            // открыта ли анкета: закрытая — связь только через её шадхана
            'open' => isset($r['Open']) && (int)$r['Open'] === 1,
            'score' => $scored['score'],
            'breakdown' => $scored['breakdown'],
            // профиль есть у обеих сторон — иначе процент не о чем считать
            'profiled' => $profile_a && $profile_b,
            'shadhan_id' => (int)$r['lead_shadhan'],
        );
    }

    usort($out, function ($x, $y) {
        if ($x['score'] !== $y['score']) {
            return ($x['score'] < $y['score']) ? 1 : -1;
        }
        return ($x['anketa_id'] < $y['anketa_id']) ? -1 : 1;
    });
    $out = array_slice($out, 0, max(1, (int)$limit));

    // Имена ведущих шадханов (Э3.3-c) — ПОСЛЕ среза и одним запросом:
    // до среза это было бы N+1 на сотни строк ради топ-50.
    $ids = array();
    foreach ($out as $row) {
        if ($row['shadhan_id']) {
            $ids[$row['shadhan_id']] = true;
        }
    }
    $names = array();
    if ($ids) {
        $db->query(
            "SELECT `User_ID`, `Name` FROM `User` WHERE `User_ID` IN ("
            . implode(',', array_map('intval', array_keys($ids))) . ")",
            ARRAY_A
        );
        foreach ((array)$db->last_result as $u) {
            $names[(int)$u['User_ID']] = (string)$u['Name'];
        }
    }
    foreach ($out as &$row) {
        $row['shadhan'] = isset($names[$row['shadhan_id']]) ? $names[$row['shadhan_id']] : '';
        unset($row['shadhan_id']);
    }
    unset($row);

    return $out;
}
