<?php
/*
 * Согласия на сведения о здоровье — сводка для администратора (этап
 * «До идеала», C11).
 *
 * После X-5 новые сохранения анкеты без галочки стирают поле здоровья, но
 * в базе остались сотни анкет, заполненных до появления согласия: сведения
 * есть, согласия нет. Решение заказчика — не стирать, а запросить согласие
 * повторно. Здесь: счётчики, список таких анкет БЕЗ содержимого поля
 * (экран и CSV показывают только кто и когда), адресаты и текст письма.
 */

require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/consent_lib.php';

/* Условие «сведения есть, согласия нет» — одно на все выборки. */
function consent_pending_where($alias = 'm') {
    return "IFNULL($alias.`Consent_sensitive`, 0) = 0 AND IFNULL($alias.`Health_status`, '') <> ''";
}

function consent_stats($db) {
    return array(
        'with'    => (int)$db->get_var("SELECT COUNT(*) FROM `Message204` m WHERE m.`Consent_sensitive` = 1"),
        'pending' => (int)$db->get_var("SELECT COUNT(*) FROM `Message204` m WHERE " . consent_pending_where('m')),
        'empty'   => (int)$db->get_var("SELECT COUNT(*) FROM `Message204` m WHERE IFNULL(m.`Health_status`, '') = ''"),
        'total'   => (int)$db->get_var("SELECT COUNT(*) FROM `Message204`"),
    );
}

/*
 * Анкеты без согласия, где поле здоровья заполнено. Само поле НЕ выбирается:
 * администратору для решения оно не нужно, а лишняя копия — лишний риск.
 */
function consent_pending($db, $limit = 200, $offset = 0) {
    $limit = max(1, (int)$limit);
    $offset = max(0, (int)$offset);
    $db->query(
        "SELECT m.`Message_ID`, m.`Name`, m.`User_ID`, m.`LastUpdated`, m.`Created`, "
        . "u.`Email`, u.`Confirmed`, "
        . "(o.`opted_out` = 1) AS `opted_out` "
        . "FROM `Message204` m LEFT JOIN `User` u ON u.`User_ID` = m.`User_ID` "
        . "LEFT JOIN `mailing_optout` o ON o.`user_id` = m.`User_ID` "
        . "WHERE " . consent_pending_where('m') . " "
        . "ORDER BY m.`LastUpdated` DESC, m.`Message_ID` DESC LIMIT $offset, $limit",
        ARRAY_A
    );
    return (array)$db->last_result;
}

/*
 * Кому писать: владельцы таких анкет с подтверждённым e-mail, не отписавшиеся
 * от рассылок и не заготовки приглашений. По одному письму на человека.
 */
function consent_pending_recipients($db) {
    $db->query(
        "SELECT DISTINCT u.`User_ID`, u.`Email` FROM `User` u "
        . "JOIN `Message204` m ON m.`User_ID` = u.`User_ID` "
        . "WHERE " . consent_pending_where('m') . " "
        . "AND u.`Confirmed` = 1 AND u.`Email` <> '' "
        . "AND u.`Email` NOT LIKE 'invite-%@invite.shiduh.org' "
        . "AND NOT EXISTS (SELECT 1 FROM `mailing_optout` o WHERE o.`user_id` = u.`User_ID` AND o.`opted_out` = 1) "
        . "ORDER BY u.`User_ID`",
        ARRAY_A
    );
    return (array)$db->last_result;
}

/* Тема и текст письма — из настроек (Module=consent), иначе по умолчанию. */
function consent_request_subject() {
    $s = trim((string)shiduh_setting('consent', 'request_subject', ''));
    return $s !== '' ? $s : 'One Soul: нужно ваше согласие на сведения о здоровье';
}

function consent_request_text() {
    $t = trim((string)shiduh_setting('consent', 'request_text', ''));
    if ($t !== '') {
        return $t;
    }
    return "Здравствуйте!\n\n"
        . "В вашей анкете на One Soul заполнено поле «Состояние здоровья». По закону о персональных данных "
        . "такие сведения относятся к специальной категории, и хранить их мы можем только с вашего отдельного согласия.\n\n"
        . "Пожалуйста, зайдите в анкету и выберите один из вариантов:\n"
        . "— поставьте галочку согласия рядом с полем «Состояние здоровья» и сохраните анкету;\n"
        . "— или очистите это поле, если не хотите, чтобы оно хранилось.\n\n"
        . "Анкета: https://shiduh.org/my/modify/\n\n"
        . "Эти сведения видят только ваши шадханы и администратор; они не передаются во внешние сервисы. "
        . "Отозвать согласие можно в любой момент, сняв галочку.\n\n"
        . "С уважением, команда One Soul";
}

/* Маркер в фильтрах рассылки — по нему узнаём повторный запуск. */
function consent_mailing_filters() {
    return array('consent_request' => 1);
}

/*
 * CSV для выгрузки: BOM и «;» — чтобы Excel в русской локали открыл файл без
 * мастера импорта. Медицинских данных в файле нет.
 */
function consent_csv(array $rows) {
    $out = chr(0xEF) . chr(0xBB) . chr(0xBF);
    $out .= "Анкета;Имя;Пользователь;E-mail;E-mail подтверждён;Отписан от рассылок;Создана;Изменена\r\n";
    foreach ($rows as $r) {
        $cells = array(
            (int)$r['Message_ID'],
            (string)$r['Name'],
            (int)$r['User_ID'],
            (string)$r['Email'],
            (int)$r['Confirmed'] ? 'да' : 'нет',
            (int)$r['opted_out'] ? 'да' : 'нет',
            (string)$r['Created'],
            (string)$r['LastUpdated'],
        );
        $out .= implode(';', array_map('consent_csv_cell', $cells)) . "\r\n";
    }
    return $out;
}

function consent_csv_cell($v) {
    $v = (string)$v;
    // формулы Excel начинаются с =,+,-,@ — экранируем, чтобы файл не исполнял чужой ввод
    if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
        $v = "'" . $v;
    }
    if (strpbrk($v, ";\"\r\n") !== false) {
        $v = '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}
