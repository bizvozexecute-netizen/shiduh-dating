<?php
/*
 * Общее для админских эндпоинтов: JSON-ответ, проверка прав администратора,
 * заслон от межсайтовых запросов.
 *
 * Выделено из api/mailing_lib.php, когда появился второй админ-экран
 * (кейсы опроса, Э2.2-a) — чтобы проверка прав жила в одном месте.
 */

function shiduh_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * Администратор NetCat: запись в Permission с AdminType директор (7) или
 * супервизор (6) — лично или через группу (User_Group). Зеркалит логику
 * конструктора admin/permission.class.php, включая окно действия прав.
 *
 * В подзапросе групп JOIN PermissionGroup: личные права хранятся с
 * PermissionGroup_ID=0, и осиротевшая строка User_Group с группой 0 иначе
 * подарила бы их постороннему (найдено на security-ревью рассылки).
 */
function shiduh_is_admin($db, $uid) {
    $uid = (int)$uid;
    if ($uid <= 0) {
        return false;
    }
    return (bool)$db->get_var(
        "SELECT 1 FROM `Permission` WHERE `AdminType` IN (6,7) "
        . "AND (`User_ID`=$uid OR `PermissionGroup_ID` IN "
        . "(SELECT ug.`PermissionGroup_ID` FROM `User_Group` ug "
        . "JOIN `PermissionGroup` g ON g.`PermissionGroup_ID`=ug.`PermissionGroup_ID` "
        . "WHERE ug.`User_ID`=$uid)) "
        . "AND (`PermissionBegin` IS NULL OR `PermissionBegin`<=NOW()) "
        . "AND (`PermissionEnd` IS NULL OR `PermissionEnd`>=NOW()) LIMIT 1"
    );
}

/*
 * Заслон от CSRF: если браузер прислал Origin/Referer — их хост обязан
 * совпасть с хостом сайта. Полноценный nc_token непригоден, пока
 * SecretKey=md5('') общеизвестен (находка B-3).
 * Вызывать ДО любых изменений состояния.
 */
function shiduh_check_origin($on_reject = null) {
    $req_host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($src !== '' && strcasecmp((string)parse_url($src, PHP_URL_HOST), $req_host) !== 0) {
        if (is_callable($on_reject)) {
            $on_reject();
        }
        shiduh_json(403, array('error' => 'Недопустимый источник запроса'));
    }
}
