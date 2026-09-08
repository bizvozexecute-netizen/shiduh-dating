<?php
/*
 * Очередь «сигналов внимания» (этап «До идеала», C10).
 *
 * Что было: ИИ-консультант поднимал сигнал (risk_signal_lib.php), шадхан видел
 * его в карточке, но отметить «посмотрел» было негде — risk_note_resolve()
 * никто не вызывал, и сигналы копились навсегда. Здесь: выборки для
 * админ-экрана и правило, кто вправе закрыть сигнал — администратор или
 * ведущий шадхан кандидата (Shadhan_1..3 его анкеты).
 *
 * В заметке нет ничего из переписки по построению (см. risk_signal_lib), но
 * экран всё равно показывает только категорию, подсказку и даты.
 */

require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/risk_signal_lib.php';

/* Заметка по id или null. */
function moderation_note($db, $note_id) {
    $id = (int)$note_id;
    if ($id <= 0) {
        return null;
    }
    $row = $db->get_row("SELECT * FROM `moderation_notes` WHERE `id`=$id", ARRAY_A);
    return is_array($row) ? $row : null;
}

/* Ведёт ли шадхан $uid хоть одну анкету пользователя $candidate_uid. */
function moderation_is_leading_shadhan($db, $uid, $candidate_uid) {
    $uid = (int)$uid;
    $cand = (int)$candidate_uid;
    if ($uid <= 0 || $cand <= 0) {
        return false;
    }
    return (bool)$db->get_var(
        "SELECT 1 FROM `Message204` WHERE `User_ID`=$cand "
        . "AND (`Shadhan_1`=$uid OR `Shadhan_2`=$uid OR `Shadhan_3`=$uid) LIMIT 1"
    );
}

/* Кто вправе закрыть сигнал: администратор или ведущий шадхан кандидата. */
function moderation_can_resolve($db, $uid, array $note) {
    if (shiduh_is_admin($db, $uid)) {
        return true;
    }
    return moderation_is_leading_shadhan($db, $uid, isset($note['user_id']) ? $note['user_id'] : 0);
}

/*
 * Открытые сигналы для экрана: кандидат (имя, id), первая анкета для
 * ссылки, категория, когда. Самые свежие сверху.
 */
function moderation_open($db, $limit = 200) {
    $limit = max(1, (int)$limit);
    $db->query(
        "SELECT n.`id`, n.`user_id`, n.`kind`, n.`created_at`, u.`Name` AS `user_name`, "
        . "(SELECT MIN(m.`Message_ID`) FROM `Message204` m WHERE m.`User_ID`=n.`user_id`) AS `anketa_id`, "
        . "(SELECT GROUP_CONCAT(DISTINCT s.`Name` ORDER BY s.`Name` SEPARATOR ', ') FROM `Message204` m2 "
        . " JOIN `User` s ON s.`User_ID` IN (m2.`Shadhan_1`, m2.`Shadhan_2`, m2.`Shadhan_3`) "
        . " WHERE m2.`User_ID`=n.`user_id`) AS `shadhans` "
        . "FROM `moderation_notes` n LEFT JOIN `User` u ON u.`User_ID`=n.`user_id` "
        . "WHERE n.`resolved_at` IS NULL ORDER BY n.`created_at` DESC, n.`id` DESC LIMIT $limit",
        ARRAY_A
    );
    return (array)$db->last_result;
}

/* Недавно закрытые — чтобы видеть, что очередь живёт. */
function moderation_resolved_recent($db, $limit = 30) {
    $limit = max(1, (int)$limit);
    $db->query(
        "SELECT n.`id`, n.`user_id`, n.`kind`, n.`created_at`, n.`resolved_at`, "
        . "u.`Name` AS `user_name`, r.`Name` AS `resolver_name`, n.`resolved_by` "
        . "FROM `moderation_notes` n LEFT JOIN `User` u ON u.`User_ID`=n.`user_id` "
        . "LEFT JOIN `User` r ON r.`User_ID`=n.`resolved_by` "
        . "WHERE n.`resolved_at` IS NOT NULL ORDER BY n.`resolved_at` DESC LIMIT $limit",
        ARRAY_A
    );
    return (array)$db->last_result;
}

/* Счётчики для шапки экрана. */
function moderation_counts($db) {
    return array(
        'open'        => (int)$db->get_var("SELECT COUNT(*) FROM `moderation_notes` WHERE `resolved_at` IS NULL"),
        'resolved_7d' => (int)$db->get_var("SELECT COUNT(*) FROM `moderation_notes` WHERE `resolved_at` > DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'total_30d'   => (int)$db->get_var("SELECT COUNT(*) FROM `moderation_notes` WHERE `created_at` > DATE_SUB(NOW(), INTERVAL 30 DAY)"),
    );
}
