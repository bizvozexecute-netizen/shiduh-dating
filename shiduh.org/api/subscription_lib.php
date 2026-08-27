<?php
/*
 * Тарификация ИИ-чата (ТЗ Э4-2): единая точка для эндпоинтов, которые
 * тратят деньги от имени КАНДИДАТА.
 *
 * Правила:
 *   - статус free: после N ИИ-сообщений (Settings ai.free_message_limit,
 *     по умолчанию 3; отрицательное значение = лимит отключён) — блок с
 *     окном «оформите подписку»;
 *   - trial / paid — без лимита, пока не истёк expires_at (NULL = бессрочно;
 *     истёкший срок = free);
 *   - ЗАГЛУШКА БЕСПЛАТНА: пока провайдер stub или ключ пуст, реального ИИ
 *     нет — блокировать нечего, и такие сообщения не копят счётчик.
 *
 * Осознанно ВНЕ тарификации: ценностный опрос Э2.4 (assessment_answer.php) —
 * сбор профиля выгоден сервису, у него свой потолок 300/сутки; и шадханские
 * инструменты (резюме, обоснование пар) — у них свои часовые лимиты.
 *
 * Гонки: решение «пустить или нет» принимает ОДИН атомарный UPDATE
 * (subscription_take_slot), а не SELECT с последующей записью — залп
 * параллельных запросов не проскакивает мимо лимита (MyISAM без
 * транзакций, но одиночный statement атомарен).
 *
 * Нет строки в ai_subscriptions = free с нулевым счётчиком.
 */

require_once dirname(__FILE__) . '/ai_client.php';

function subscription_statuses() {
    return array('free', 'trial', 'paid');
}

/* SQL-условие «строка даёт безлимит»: trial/paid и срок не истёк. */
function subscription_unlimited_sql() {
    return "(`status` IN ('trial','paid') AND (`expires_at` IS NULL OR `expires_at` > NOW()))";
}

/*
 * Лимит бесплатных ИИ-сообщений. Отрицательный = лимита нет; 0 — валидный
 * «ни одного бесплатного» (интуитивное прочтение нуля для денежной
 * настройки; «выключить» — это явное -1, а не 0).
 */
function subscription_free_limit() {
    $v = nc_core::get_object()->get_settings('free_message_limit', 'ai', false, 0);
    if ($v === false || $v === '' || $v === null) {
        return 3;
    }
    return (int)$v;
}

/* Статус и счётчик пользователя; истёкший trial/paid отдаётся как free. */
function subscription_get($db, $uid) {
    $uid = (int)$uid;
    $row = $db->get_row(
        "SELECT `status`, `ai_messages_used`, " . subscription_unlimited_sql() . " AS unlimited "
        . "FROM `ai_subscriptions` WHERE `user_id`=$uid",
        ARRAY_A
    );
    if (!$row) {
        return array('status' => 'free', 'used' => 0);
    }
    $status = in_array($row['status'], subscription_statuses(), true) ? $row['status'] : 'free';
    if ($status !== 'free' && !(int)$row['unlimited']) {
        $status = 'free';   // срок вышел
    }
    return array('status' => $status, 'used' => (int)$row['ai_messages_used']);
}

/*
 * Информация для гейта: биллабельно ли сообщение и какой лимит.
 * $cfg — уже загруженный ai_config(), чтобы не ходить в Settings дважды.
 * Само решение «пустить или нет» принимает subscription_take_slot().
 */
function subscription_gate_chat($db, $uid, $cfg = null) {
    $sub = subscription_get($db, $uid);
    $limit = subscription_free_limit();
    if ($cfg === null) {
        $cfg = ai_config();
    }
    // billable: заглушка бесплатна — иначе при подключении ключа демо-переписка
    // задним числом съела бы лимит free
    $real_ai = ($cfg['provider'] === 'openrouter' && $cfg['api_key'] !== '');
    return array(
        'billable' => $real_ai,
        'status' => $sub['status'], 'used' => $sub['used'], 'limit' => $limit,
    );
}

/*
 * Атомарно занять слот ИИ-сообщения. true = можно, счётчик уже увеличен;
 * false = бесплатный лимит исчерпан (пейволл).
 *
 * При ошибке БД — fail-open с громким логом: чат — основной сценарий сайта,
 * ронять его из-за сломанной таблицы тарифов хуже, чем недосчитать
 * сообщения (в отличие от кэша обоснований, где fail-closed бережёт деньги).
 */
function subscription_take_slot($db, $uid, $limit) {
    $uid = (int)$uid;
    $limit = (int)$limit;
    $db->query(
        "INSERT IGNORE INTO `ai_subscriptions` SET "
        . "`user_id`=$uid, `status`='free', `ai_messages_used`=0, `updated_at`=NOW()"
    );
    $free_ok = ($limit >= 0) ? "`ai_messages_used` < $limit" : "1=1";
    $db->query(
        "UPDATE `ai_subscriptions` SET "
        . "`ai_messages_used`=`ai_messages_used`+1, `updated_at`=NOW() "
        . "WHERE `user_id`=$uid AND (" . subscription_unlimited_sql() . " OR $free_ok)"
    );
    if ($db->is_error) {
        error_log('[subscription] учёт сообщения не сработал для uid ' . $uid
            . ': ' . $db->last_error);
        return true;
    }
    return (int)$db->rows_affected === 1;
}

/*
 * Ручное переключение статуса (Э4-4, права проверяет вызывающий).
 * Сбрасывает счётчик: новый тариф — новый отсчёт. $expires_at — 'Y-m-d H:i:s'
 * или null (бессрочно).
 */
function subscription_set($db, $uid, $status, $expires_at = null) {
    if (!in_array($status, subscription_statuses(), true)) {
        return false;
    }
    $uid = (int)$uid;
    $status_esc = $db->prepare($status);
    $exp_sql = ($expires_at === null || $expires_at === '')
        ? "NULL"
        : "'" . $db->prepare($expires_at) . "'";
    $db->query(
        "INSERT INTO `ai_subscriptions` SET "
        . "`user_id`=$uid, `status`='$status_esc', `ai_messages_used`=0, "
        . "`expires_at`=$exp_sql, `updated_at`=NOW() "
        . "ON DUPLICATE KEY UPDATE `status`='$status_esc', "
        . "`ai_messages_used`=0, `expires_at`=$exp_sql, `updated_at`=NOW()"
    );
    return !$db->is_error;
}
