<?php
/*
 * Вход кандидата по ссылке-приглашению (ROADMAP XI-3; межстраница — C4).
 *
 * Раньше GET ?t=<токен> сразу логинил и редиректил. Это значит, что любой,
 * кто заставит браузер открыть адрес (превью ссылки в мессенджере, картинка
 * в письме, чужая страница с <img src>), входил в аккаунт-заготовку без
 * единого действия человека; прокси и антивирусы, «проверяющие» ссылки,
 * тоже отмечали приглашение использованным.
 *
 * Теперь GET только проверяет токен и показывает страницу с кнопкой «Войти»;
 * cookie не ставится, used_at не пишется. Вход — POST с той же ссылки, с
 * заслоном по источнику (fail-closed) и повторной проверкой токена. Токен
 * 64-hex и неугадываем: он и есть секрет, поэтому CSRF-токена авторизованной
 * сессии здесь нет и быть не может.
 *
 * Токен живёт в адресе, значит адрес не должен уходить в Referer чужим
 * сайтам и в кэш: Referrer-Policy same-origin, no-store, noindex.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$db = $nc_core->db;

function invite_headers() {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    // same-origin, а не no-referrer: токен в адресе не должен уходить чужим
    // сайтам, но собственный POST с кнопки обязан нести Referer — иначе
    // браузер без заголовка Origin (старые версии) упирался бы в заслон
    // источника на честном входе
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; "
        . "img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; form-action 'self'");
}

/* Страница без скриптов: разметка на слое, ничего инлайнового. */
function invite_page($status, $title, $text, $form_token = null, $show_login = false) {
    http_response_code($status);
    invite_headers();
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $v = (int)@filemtime(dirname(__FILE__) . '/../css/one.css');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>' . $t . '</title>'
       . '<link rel="stylesheet" href="/css/fonts.css">'      // фирменные шрифты без 300-КБ бандла
       . '<link rel="stylesheet" href="/css/one.css?v=' . $v . '"></head>'
       . '<body class="sh-standalone"><main class="sh-standalone__card sh-card" role="main">'
       . '<h1 class="sh-standalone__title">' . $t . '</h1>'
       . '<p class="sh-standalone__text">' . $b . '</p>';
    if ($form_token !== null) {
        echo '<form method="post" action="/api/invite.php" class="sh-standalone__form">'
           . '<input type="hidden" name="t" value="' . htmlspecialchars($form_token, ENT_QUOTES, 'UTF-8') . '">'
           . '<button type="submit" class="button">Войти как приглашённый кандидат</button>'
           . '</form>'
           . '<p class="sh-standalone__note">Ссылка работает, пока вы не закрепите аккаунт за собой; '
           . 'никому её не пересылайте.</p>';
    }
    if ($show_login) {
        echo '<p><a class="sh-standalone__link" href="/my/">Войти на сайт</a></p>';
    }
    echo '</main></body></html>';
    exit;
}

/* Проверка токена: null — недействителен, 'claimed' — уже присвоен, иначе строка. */
function invite_lookup($db, $token) {
    if (!preg_match('/^[0-9a-f]{64}$/', (string)$token)) {
        return null;
    }
    $row = $db->get_row(
        "SELECT `id`, `candidate_user_id`, `claimed_at`, "
        . "(`expires_at` < NOW()) AS `expired` "
        . "FROM `invite_tokens` WHERE `token`='" . $db->prepare($token) . "'",
        ARRAY_A
    );
    if (!$row || (int)$row['expired'] === 1) {
        return null;
    }
    return $row;
}

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($is_post) {
    // Заслон по источнику — ДО любой работы с токеном (fail-closed): чужая
    // страница не нажмёт кнопку за человека. Отказ — человеческой страницей:
    // это навигация с кнопки, а не XHR, и голый JSON человек не поймёт.
    shiduh_check_origin(function () {
        invite_page(403, 'Не удалось подтвердить источник',
            'Браузер не сообщил, откуда пришёл запрос. Откройте ссылку из письма ещё раз и нажмите кнопку.');
    });
}

$token = (string)($is_post ? $nc_core->input->fetch_post('t') : $nc_core->input->fetch_get('t'));

$row = invite_lookup($db, $token);
if ($row === null) {
    // единый нейтральный текст: не раскрываем, чего именно нет
    invite_page(404, 'Ссылка недействительна',
        'Похоже, ссылка неполная или устарела. Попросите шадхана прислать новую.');
}
if ($row['claimed_at'] !== null) {
    invite_page(410, 'Приглашение уже использовано',
        'Этот аккаунт уже активирован. Войдите обычным способом.', null, true);
}

if (!$is_post) {
    // GET: только показать, что ссылка настоящая, и дать кнопку. Если в
    // браузере уже чужая сессия (шадхан «проверяет» ссылку кандидата),
    // предупреждаем: вход по кнопке заменит её — раньше это происходило молча.
    $nc_core->user->attempt_to_authorize();
    global $current_user;
    $who = '';
    if (!empty($current_user['User_ID']) && (int)$current_user['User_ID'] !== (int)$row['candidate_user_id']) {
        $who = (string)(isset($current_user['Email']) ? $current_user['Email'] : ('ID ' . (int)$current_user['User_ID']));
    }
    invite_page(200, 'Приглашение от шадхана',
        'Шадхан завёл для вас анкету и приглашает дозаполнить её в диалоге с консультантом. '
        . 'Нажмите кнопку, чтобы войти.'
        . ($who !== '' ? ' Сейчас вы вошли на сайт как ' . $who . ' — нажатие кнопки выйдет из этого аккаунта.' : ''),
        $token);
}

$candidate_uid = (int)$row['candidate_user_id'];

// логиним кандидата в его заготовку (ставит cookie сессии)
$ok = $nc_core->user->authorize_by_id($candidate_uid);
if (!$ok) {
    invite_page(500, 'Не удалось войти',
        'Техническая ошибка при входе. Попросите шадхана прислать новую ссылку.');
}

// первый вход по ссылке — для аналитики; после успешного входа, чтобы
// упавший логин не помечал приглашение использованным
$db->query("UPDATE `invite_tokens` SET `used_at`=NOW() "
    . "WHERE `id`=" . (int)$row['id'] . " AND `used_at` IS NULL");

invite_headers();
header('Location: /my/ai-consultant/', true, 303);
exit;
