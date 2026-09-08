<?php
/*
 * Каркас админ-консоли (этап «До идеала», C8).
 *
 * Админ-экраны — автономные страницы в корне сайта (admin-*.php), а не
 * компоненты NetCat: так они не попадают в ловушку двойного хранения
 * шаблонов (ROADMAP I-9). Плата за автономность — каждая страница рисовала
 * собственный <head>, свою палитру и свою проверку прав, а открыть экраны
 * можно было только зная адрес наизусть. Здесь общее: бутстрап и права,
 * заголовки безопасности, шапка с навигацией, подвал.
 *
 * Content-Security-Policy строгая: скрипты только свои и только с nonce,
 * стили только из своих файлов. Inline-скрипт без nonce не выполнится —
 * это и есть защита от XSS на экранах, где администратор видит чужой ввод
 * (тексты кейсов, промпт). Атрибуты style= политика тоже не пускает:
 * вместо них утилитарные классы в one-admin.css.
 */

require_once dirname(__FILE__) . '/admin_lib.php';

/*
 * Бутстрап страницы: ядро, авторизация, права. Не администратору — 403 и
 * страница «Доступ запрещён» (не редирект: человек должен понять, что
 * адрес верный, а прав нет). Возвращает [$nc_core, $db, $uid].
 */
function admin_bootstrap() {
    $nc_core = nc_core::get_object();
    $nc_core->user->attempt_to_authorize();

    global $current_user;
    $db  = $nc_core->db;
    $uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);

    // Заголовки безопасности — ДО проверки прав: страница отказа защищена
    // так же, как и рабочая (не кэшируется, не встраивается, без чужих
    // скриптов и стилей).
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . admin_nonce() . "'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    if (!shiduh_is_admin($db, $uid)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Доступ запрещён</title>'
            . '<link rel="stylesheet" href="/css/one.css"><link rel="stylesheet" href="/css/one-admin.css">'
            . '</head><body><div class="wrap"><div class="denied">'
            . '<h1>Доступ запрещён</h1>'
            . '<p>Экраны администрирования доступны только администратору сайта.</p>'
            . '<p><a href="/my/">Вернуться в кабинет</a></p>'
            . '</div></div></body></html>';
        exit;
    }

    return array($nc_core, $db, $uid);
}

/* Одноразовый nonce на запрос — для инлайновых <script> страниц. */
function admin_nonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = bin2hex(random_bytes(12));
    }
    return $nonce;
}

function admin_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Имя пользователя для журналов версий; без имени — «ID N». */
function admin_author_name($db, $id) {
    $id = (int)$id;
    if (!$id) {
        return '—';
    }
    $name = $db->get_var("SELECT `Name` FROM `User` WHERE `User_ID`=$id");
    return $name ? $name : ('ID ' . $id);
}

/*
 * Пункты навигации. Порядок — по частоте использования: то, что трогают
 * каждый день, слева. Экраны следующих инкрементов добавляются сюда же.
 */
function admin_nav_items() {
    return array(
        'ai'      => array('href' => '/admin-ai.php',        'title' => 'ИИ и подключение'),
        'prompt'  => array('href' => '/admin-ai-prompt.php', 'title' => 'Промпт консультанта'),
        'cases'   => array('href' => '/admin-cases.php',     'title' => 'Кейсы опроса'),
        'mailing' => array('href' => '/admin-mailing.php',   'title' => 'Рассылка'),
        'moderation' => array('href' => '/admin-moderation.php', 'title' => 'Сигналы внимания'),
        'consent' => array('href' => '/admin-consent.php',   'title' => 'Согласия'),
        'matches' => array('href' => '/admin-matches.php',   'title' => 'Подбор'),
    );
}

/* Адрес входа в консоль — первый существующий экран из навигации. */
function admin_home_url() {
    foreach (admin_nav_items() as $item) {
        if (file_exists(dirname(__FILE__) . '/..' . $item['href'])) {
            return $item['href'];
        }
    }
    return '/my/';
}

/*
 * Шапка страницы: <head> с токеном CSRF и слоем, навигация, заголовок.
 * $active — ключ из admin_nav_items(); $sub — подзаголовок под h1.
 */
function admin_page_start($uid, $title, $active, $sub = '') {
    $nonce = admin_nonce();
    echo '<!DOCTYPE html>' . "\n" . '<html lang="ru">' . "\n" . '<head>' . "\n"
        . '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<meta name="robots" content="noindex, nofollow">' . "\n"
        . '<meta name="csrf-token" content="' . admin_esc(shiduh_csrf_token($uid)) . '">' . "\n"
        . '<title>' . admin_esc($title) . ' — администрирование</title>' . "\n"
        . '<link rel="stylesheet" href="/css/one.css?v=' . (int)@filemtime(dirname(__FILE__) . '/../css/one.css') . '">' . "\n"
        . '<link rel="stylesheet" href="/css/one-admin.css?v=' . (int)@filemtime(dirname(__FILE__) . '/../css/one-admin.css') . '">' . "\n"
        . '<script src="/js/csrf.js" nonce="' . $nonce . '"></script>' . "\n"
        // без defer: инлайновые скрипты страниц выполняются при разборе, раньше
        // отложенных, и shAdmin к их запуску обязан уже существовать
        . '<script src="/js/admin.js?v=' . (int)@filemtime(dirname(__FILE__) . '/../js/admin.js') . '" nonce="' . $nonce . '"></script>' . "\n"
        . '</head>' . "\n" . '<body>' . "\n"
        . '<div class="wrap">' . "\n"
        . '<nav class="nav" aria-label="Разделы администрирования">' . "\n";
    foreach (admin_nav_items() as $key => $item) {
        // существующие экраны показываем всегда; отсутствующий файл — не ссылка
        if (!file_exists(dirname(__FILE__) . '/..' . $item['href'])) {
            continue;
        }
        $is = $key === $active;
        echo '  <a href="' . admin_esc($item['href']) . '"'
            . ($is ? ' class="is-current" aria-current="page"' : '') . '>'
            . admin_esc($item['title']) . '</a>' . "\n";
    }
    echo '  <a class="nav__back" href="/my/">← В кабинет</a>' . "\n"
        . '</nav>' . "\n"
        . '<h1>' . admin_esc($title) . '</h1>' . "\n";
    if ($sub !== '') {
        echo '<p class="sub">' . admin_esc($sub) . '</p>' . "\n";
    }
}

function admin_page_end() {
    echo '</div>' . "\n" . '</body>' . "\n" . '</html>' . "\n";
}

/* Открывающий тег инлайнового скрипта с nonce — иначе CSP его не пустит. */
function admin_script_open() {
    return '<script nonce="' . admin_nonce() . '">';
}
