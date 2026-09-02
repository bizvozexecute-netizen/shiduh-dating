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

/*
 * Единая точка логирования. Канал — короткое слово в квадратных скобках
 * (ai|match|rag|scoring|admin|csrf|captcha|auth|fatal|cron|upload), по нему
 * удобно grep-ать лог веб-сервера. Пишет в error_log — на проде это ErrorLog
 * Apache (см. DEPLOY.prod.md §12), на стенде — docker compose logs web.
 */
function shiduh_log($channel, $msg) {
    error_log('[' . $channel . '] ' . $msg);
}

/*
 * Фатал внутри JSON-эндпоинта раньше отдавал пустой ответ 200: ядро NetCat
 * включает ob_start(), буфер сбрасывался как есть, а фронт получал «ничего».
 * Обработчик регистрируется shiduh_bootstrap_api() ДО загрузки ядра, поэтому
 * ловит и фаталы внутри include. Наружу — только общее сообщение: текст
 * ошибки может содержать пути, SQL и фрагменты ключей; подробности — в лог.
 */
function shiduh_fatal_handler() {
    $e = error_get_last();
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR);
    if (!$e || !in_array($e['type'], $fatal, true)) {
        return;
    }
    shiduh_log('fatal', $e['message'] . ' в ' . $e['file'] . ':' . $e['line']);
    if (headers_sent()) {
        return;   // ответ уже пошёл (например, поток SSE) — дописывать нечего
    }
    while (ob_get_level() > 0) {
        ob_end_clean();   // выбросить частичный вывод и текст ошибки, если display_errors включён
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Внутренняя ошибка сервера'), JSON_UNESCAPED_UNICODE);
}

/* Общий пролог JSON-эндпоинта: заголовки + обработчик фаталов. Вызывать до connect_io.php. */
function shiduh_bootstrap_api() {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    register_shutdown_function('shiduh_fatal_handler');
}
