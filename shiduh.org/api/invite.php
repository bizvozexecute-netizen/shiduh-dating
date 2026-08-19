<?php
/*
 * Вход кандидата по ссылке-приглашению (ROADMAP XI-3).
 *
 * GET ?t=<токен>. Валидный токен (существует, не истёк, не присвоен) логинит
 * кандидата в его аккаунт-заготовку через nc_user::authorize_by_id() — это
 * ставит cookie сессии — и редиректит в ИИ-консультанта, где он дозаполняет
 * анкету. Токен многоразовый до присвоения (claimed_at), живёт 30 дней.
 *
 * Токен 64-hex и неугадываем, поэтому для несуществующего/истёкшего показываем
 * единый нейтральный текст (не раскрываем, что именно не так).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$db = $nc_core->db;

function invite_page($status, $title, $text, $show_login = false) {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $login = $show_login
        ? '<p><a href="/my/" style="color:#a68556">Войти на сайт</a></p>' : '';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $t . '</title></head>'
       . '<body style="font:16px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;'
       . 'background:#f5f2ec;color:#3a2b2b;display:grid;place-items:center;min-height:100vh;margin:0">'
       . '<div style="max-width:420px;padding:28px;background:#fff;border:1px solid #e3ddd2;'
       . 'border-radius:14px;text-align:center">'
       . '<h1 style="font-size:19px;margin:0 0 10px">' . $t . '</h1>'
       . '<p style="color:#756d65;margin:0 0 12px">' . $b . '</p>' . $login
       . '</div></body></html>';
    exit;
}

$token = (string)$nc_core->input->fetch_get('t');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    invite_page(404, 'Ссылка недействительна',
        'Похоже, ссылка неполная или устарела. Попросите шадхана прислать новую.');
}

$row = $db->get_row(
    "SELECT `id`, `candidate_user_id`, `claimed_at`, "
    . "(`expires_at` < NOW()) AS `expired` "
    . "FROM `invite_tokens` WHERE `token`='" . $db->prepare($token) . "'",
    ARRAY_A
);
if (!$row || (int)$row['expired'] === 1) {
    invite_page(404, 'Ссылка недействительна',
        'Похоже, ссылка неполная или устарела. Попросите шадхана прислать новую.');
}
if ($row['claimed_at'] !== null) {
    invite_page(410, 'Приглашение уже использовано',
        'Этот аккаунт уже активирован. Войдите обычным способом.', true);
}

$candidate_uid = (int)$row['candidate_user_id'];

// первый вход по ссылке — для аналитики (ссылка остаётся рабочей)
$db->query("UPDATE `invite_tokens` SET `used_at`=NOW() "
    . "WHERE `id`=" . (int)$row['id'] . " AND `used_at` IS NULL");

// логиним кандидата в его заготовку (ставит cookie сессии)
$ok = $nc_core->user->authorize_by_id($candidate_uid);
if (!$ok) {
    invite_page(500, 'Не удалось войти',
        'Техническая ошибка при входе. Попросите шадхана прислать новую ссылку.');
}

header('Location: /my/ai-consultant/');
exit;
