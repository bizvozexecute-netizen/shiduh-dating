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
 * Заслон от CSRF, шаг 1: источник запроса.
 *
 * ФЕЙЛ-КЛОУЗД: раньше запрос без Origin И без Referer проходил насквозь
 * («если браузер ничего не прислал — пропускаем»). Это нарушение принципа
 * «запрещено по умолчанию»: заголовки может срезать Referrer-Policy или
 * промежуточный прокси, и заслон исчезал. Теперь отсутствие обоих — отказ;
 * настоящий браузер на fetch/XHR всегда шлёт Origin.
 */
function shiduh_check_origin($on_reject = null) {
    $req_host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $ok = ($src !== '' && $req_host !== ''
           && strcasecmp((string)parse_url($src, PHP_URL_HOST), $req_host) === 0);
    if (!$ok) {
        if (is_callable($on_reject)) {
            $on_reject();
        }
        shiduh_log('csrf', 'отказ по источнику: ' . ($src === '' ? 'заголовков нет' : $src));
        shiduh_json(403, array('error' => 'Недопустимый источник запроса', 'code' => 'csrf'));
    }
}

/*
 * Заслон от CSRF, шаг 2: токен.
 *
 * nc_token = md5(user_id + SecretKey) — ровно тот, что ядро печатает в свои
 * формы. Пока SecretKey был равен md5(''), токен вычислялся кем угодно, и мы
 * ограничивались проверкой источника (находка B-3). После ротации ключа
 * (миграция 019) он снова непредсказуем, поэтому проверяем и его.
 *
 * Токен берётся из заголовка X-CSRF-Token (его проставляет /js/csrf.js) или
 * из поля формы csrf_token / nc_token — чтобы работали и обычные формы.
 */
function shiduh_csrf_token($uid) {
    // ВАЖНО: не переиспользуем nc_token. Ядро печатает его в адресную строку
    // (netcat/admin/**: token->get_url()), поэтому он оседает в логах доступа
    // и в истории браузера — секрет, живущий в URL, защитой быть не может.
    // Свой токен от того же ключа, но с разделением назначения.
    $key = (string)nc_core::get_object()->get_settings('SecretKey');
    return hash_hmac('sha256', 'api-csrf|' . (int)$uid, $key);
}

function shiduh_csrf_check($uid, $on_reject = null) {
    shiduh_check_origin($on_reject);

    $uid = (int)$uid;
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '') {
        $input = nc_core::get_object()->input;
        $sent = (string)$input->fetch_post('csrf_token');
    }

    // hash_equals: сравнение за постоянное время, чтобы токен нельзя было
    // подобрать посимвольно по времени ответа
    if ($uid <= 0 || $sent === '' || !hash_equals(shiduh_csrf_token($uid), $sent)) {
        if (is_callable($on_reject)) {
            $on_reject();
        }
        shiduh_log('csrf', 'отказ по токену, пользователь ' . $uid);
        shiduh_json(403, array(
            'error' => 'Сессия устарела. Обновите страницу и повторите',
            'code' => 'csrf',
        ));
    }
}

/* Требует авторизации; возвращает id пользователя.
 * Вызывать ПОСЛЕ $nc_core->user->attempt_to_authorize(): именно она заполняет
 * глобальный $current_user, из которого ядро везде и берёт текущего. */
function shiduh_require_auth() {
    global $current_user;
    $uid = (int)($current_user['User_ID'] ?? 0);
    if ($uid <= 0) {
        shiduh_json(403, array('error' => 'Требуется авторизация'));
    }
    return $uid;
}

/*
 * Гейт роли шадхана. Раньше эта проверка была скопирована в семь эндпоинтов
 * (AUDIT №36: единой точки проверки прав нет) — одна правка политики означала
 * семь правок кода.
 */
function shiduh_require_shadhan($db, $uid) {
    if ((int)$db->get_var("SELECT `Type` FROM `User` WHERE `User_ID`=" . (int)$uid) !== 2) {
        shiduh_json(403, array('error' => 'Доступно только шадхану'));
    }
}

function shiduh_require_admin($db, $uid) {
    if (!shiduh_is_admin($db, $uid)) {
        shiduh_json(403, array('error' => 'Доступно только администратору'));
    }
}

/*
 * Супер-администраторы. В унаследованных шаблонах их id зашиты числами в 79
 * местах (AUDIT №8); здесь — одно место, значения правятся настройкой.
 */
function shiduh_is_superadmin($uid) {
    $ids = (string)shiduh_setting('security', 'superadmin_ids', '1,119,43');
    return in_array((int)$uid, array_map('intval', explode(',', $ids)), true);
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
