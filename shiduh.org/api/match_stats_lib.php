<?php
/*
 * Статистика подбора пар для администратора (этап «До идеала», C12).
 *
 * Что смотреть: как шадханы реагируют на рекомендации (match_feedback —
 * предложил / отклонил и по какой причине) и сколько обоснований пар
 * запрашивалось у модели (match_explanations). Все цифры — прямые COUNT по
 * таблицам, чтобы тест мог сверить их с SQL.
 */

require_once dirname(__FILE__) . '/admin_lib.php';

/* Подписи причин отклонения — те же ключи, что принимает match_feedback.php. */
function match_stats_reason_titles() {
    return array(
        'values'        => 'Не сходятся ценности',
        'love_language' => 'Разные языки любви',
        'geography'     => 'География',
        'age'           => 'Возраст',
        'other'         => 'Другое',
        ''              => 'Без причины',
    );
}

function match_stats_action_titles() {
    return array(
        'proposed' => 'Предложено',
        'rejected'  => 'Отклонено',
    );
}

function match_stats($db) {
    $s = array();
    $s['feedback_total'] = (int)$db->get_var("SELECT COUNT(*) FROM `match_feedback`");
    $s['feedback_30d']   = (int)$db->get_var("SELECT COUNT(*) FROM `match_feedback` WHERE `created_at` > DATE_SUB(NOW(), INTERVAL 30 DAY)");

    $db->query("SELECT `action`, COUNT(*) AS `n` FROM `match_feedback` GROUP BY `action` ORDER BY `n` DESC", ARRAY_A);
    $s['by_action'] = (array)$db->last_result;

    $db->query("SELECT `reason`, COUNT(*) AS `n` FROM `match_feedback` WHERE `action`='rejected' GROUP BY `reason` ORDER BY `n` DESC", ARRAY_A);
    $s['by_reason'] = (array)$db->last_result;

    $db->query(
        "SELECT f.`shadhan_id`, u.`Name`, COUNT(*) AS `n`, "
        . "SUM(f.`action`='proposed') AS `proposed`, SUM(f.`action`='rejected') AS `rejected` "
        . "FROM `match_feedback` f LEFT JOIN `User` u ON u.`User_ID`=f.`shadhan_id` "
        . "GROUP BY f.`shadhan_id`, u.`Name` ORDER BY `n` DESC LIMIT 10",
        ARRAY_A
    );
    $s['by_shadhan'] = (array)$db->last_result;

    $s['explanations_total'] = (int)$db->get_var("SELECT COUNT(*) FROM `match_explanations`");
    $s['explanations_7d']    = (int)$db->get_var("SELECT COUNT(*) FROM `match_explanations` WHERE `created_at` > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $s['explanations_pairs'] = (int)$db->get_var(
        "SELECT COUNT(DISTINCT CONCAT(LEAST(`anketa_a`,`anketa_b`), '-', GREATEST(`anketa_a`,`anketa_b`))) FROM `match_explanations`"
    );
    $db->query("SELECT `model`, COUNT(*) AS `n` FROM `match_explanations` GROUP BY `model` ORDER BY `n` DESC LIMIT 10", ARRAY_A);
    $s['explanations_models'] = (array)$db->last_result;

    // сколько уникальных пар предложено — грубая мера пользы подбора
    $s['pairs_proposed'] = (int)$db->get_var(
        "SELECT COUNT(DISTINCT CONCAT(LEAST(`anketa_a`,`anketa_b`), '-', GREATEST(`anketa_a`,`anketa_b`))) "
        . "FROM `match_feedback` WHERE `action`='proposed'"
    );
    return $s;
}
