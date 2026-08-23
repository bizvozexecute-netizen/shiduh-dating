<?php
/*
 * Общий код массовой рассылки (ROADMAP Раздел IV): JSON-ответ, проверка
 * прав администратора, сборка WHERE сегмента из фильтров запроса.
 *
 * Сегмент: кандидаты (User.Type=1) с email, кроме аккаунтов-заготовок
 * приглашений и отписавшихся (mailing_optout.opted_out=1). Письма уходят
 * только подтверждённым (Confirmed=1) — это решает вызывающий код.
 * Экранирование строк — только prepare() (находка №35), id — (int).
 */

require_once dirname(__FILE__) . '/admin_lib.php';

function mailing_json($status, $payload) {
    shiduh_json($status, $payload);
}

/* Проверка прав администратора живёт в admin_lib.php (общая с экраном
   кейсов опроса); здесь — тонкая обёртка ради читаемости вызовов. */
function mailing_is_admin($db, $uid) {
    return shiduh_is_admin($db, $uid);
}

/*
 * WHERE сегмента (для таблицы `User` u) из POST-фильтров.
 * Возвращает array($where, $filters): $filters — нормализованные значения
 * для сохранения в mailings.filters (JSON).
 */
function mailing_segment_where($db, $input) {
    $where = "u.`Type`=1 AND u.`Email`<>'' "
        . "AND u.`Email` NOT LIKE 'invite-%@invite.shiduh.org' "
        . "AND NOT EXISTS (SELECT 1 FROM `mailing_optout` o "
        . "WHERE o.`user_id`=u.`User_ID` AND o.`opted_out`=1)";
    $filters = array();

    $gender = (int)$input->fetch_post('gender');
    if ($gender === 1 || $gender === 2) {
        $where .= " AND u.`Gender`=$gender";
        $filters['gender'] = $gender;
    }

    $age_min = (int)$input->fetch_post('age_min');
    $age_max = (int)$input->fetch_post('age_max');
    if ($age_min > 0) {
        $where .= " AND TIMESTAMPDIFF(YEAR, u.`Date_of_birth`, CURDATE()) >= $age_min";
        $filters['age_min'] = $age_min;
    }
    if ($age_max > 0) {
        $where .= " AND TIMESTAMPDIFF(YEAR, u.`Date_of_birth`, CURDATE()) <= $age_max";
        $filters['age_max'] = $age_max;
    }

    // Город — по анкете кандидата (в User город пуст). Подстрока, без регистра.
    $city = trim((string)$input->fetch_post('city'));
    if ($city !== '') {
        $city = mb_substr($city, 0, 100);
        $like = $db->prepare(addcslashes($city, '%_'));
        $where .= " AND EXISTS (SELECT 1 FROM `Message204` m "
            . "WHERE m.`User_ID`=u.`User_ID` AND m.`City` LIKE '%$like%')";
        $filters['city'] = $city;
    }

    return array($where, $filters);
}
