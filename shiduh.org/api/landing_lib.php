<?php
/*
 * Числа для лендинга (этап «До идеала», A2.2).
 *
 * На главной висели «1000+ анкет» и «100+ шадханов», набранные руками в
 * 2024-м. Числа на витрине должны быть настоящими: их печатает PHP из базы,
 * скрипт слоя (one.js, counters) только оживляет показ. Один запрос на
 * страницу — результат кэшируется в static на время запроса.
 *
 * Лента городов — из анкет, прошедших модерацию: город указывает сам
 * человек, поэтому значение экранируется при печати, а сентинелы тестов
 * («…-Тест») отфильтрованы — на dev-стенде они встречаются после
 * прерванного прогона.
 */

function landing_stats() {
    static $stats = null;
    if ($stats !== null) {
        return $stats;
    }
    $db = nc_core::get_object()->db;
    $stats = array(
        'anketas'   => (int)$db->get_var("SELECT COUNT(*) FROM `Message204` WHERE `Checked`=1"),
        // тот же предикат, что в карточках и списках шадханов: 1 — директор,
        // 119 — служебный аккаунт
        'shadhans'  => (int)$db->get_var("SELECT COUNT(*) FROM `User` WHERE `Type`=2 AND `Checked`=1 AND `User_ID` NOT IN (1,119)"),
        'countries' => (int)$db->get_var("SELECT COUNT(DISTINCT `Country`) FROM `Message204` WHERE `Checked`=1 AND `Country`>0"),
    );
    return $stats;
}

/* Города с тремя и более анкетами, по убыванию. */
function landing_cities($limit = 16) {
    static $cities = null;
    if ($cities !== null) {
        return $cities;
    }
    $db = nc_core::get_object()->db;
    $limit = max(1, (int)$limit);
    $rows = (array)$db->get_col("SELECT TRIM(`City`) AS c FROM `Message204` "
        . "WHERE `Checked`=1 AND TRIM(`City`)<>'' GROUP BY c HAVING COUNT(*)>=3 "
        . "ORDER BY COUNT(*) DESC, c LIMIT " . ($limit + 4));
    $cities = array();
    foreach ($rows as $c) {
        $c = trim((string)$c);
        if ($c === '' || mb_stripos($c, 'тест', 0, 'UTF-8') !== false || mb_strlen($c, 'UTF-8') > 40) {
            continue;
        }
        // первая буква прописная: «москва» и «Москва» — один город
        $cities[] = mb_strtoupper(mb_substr($c, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($c, 1, null, 'UTF-8');
        if (count($cities) >= $limit) {
            break;
        }
    }
    return $cities;
}

/* 1133 → «1 133» с неразрывным пробелом: тот же символ, что даёт
   toLocaleString('ru-RU') в счётчике, иначе при старте анимации строка
   перерисовывается на кадр. */
function landing_num($n) {
    return number_format((int)$n, 0, ',', "\xC2\xA0");
}

/* Склонение: landing_plural(3, 'анкета', 'анкеты', 'анкет') → «анкеты». */
function landing_plural($n, $one, $few, $many) {
    $n = abs((int)$n) % 100;
    $d = $n % 10;
    if ($n > 10 && $n < 20) {
        return $many;
    }
    if ($d === 1) {
        return $one;
    }
    if ($d >= 2 && $d <= 4) {
        return $few;
    }
    return $many;
}
