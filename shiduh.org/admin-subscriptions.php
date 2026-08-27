<?php
/*
 * Админ-экран подписок (ROADMAP Э4-4).
 *
 * Только администратор NetCat. Поиск пользователя по email/ID, ручное
 * переключение статуса free|trial|paid — AJAX на /api/subscription_set.php
 * (там повторная проверка прав и CSRF-заслон). Смена статуса сбрасывает
 * счётчик сообщений. Standalone-страница, как остальные admin-*.php (I-9).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_lib.php';
require_once dirname(__FILE__) . '/api/ai_client.php';
require_once dirname(__FILE__) . '/api/subscription_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!shiduh_is_admin($db, $uid)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Доступ запрещён</title></head>'
        . '<body style="font-family:Georgia,serif;text-align:center;padding:80px 20px;color:#3a2b2b">'
        . '<h1 style="font-size:20px">Доступ запрещён</h1>'
        . '<p style="color:#756d65">Экран подписок доступен только администратору.</p></body></html>';
    exit;
}
header('X-Frame-Options: DENY');
header('Content-Type: text/html; charset=utf-8');

function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$nc_q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
if ($nc_q !== '') {
    // % и _ — не поисковые операторы для админа: «%» не должен выгружать базу
    $q_esc = $db->prepare(addcslashes($nc_q, '%_'));
    // числовой запрос ищет и по ID, и по тексту: email бывает «007@…»
    $where = "(u.`Email` LIKE '%$q_esc%' OR u.`Name` LIKE '%$q_esc%'"
        . (ctype_digit($nc_q) ? " OR u.`User_ID`=" . (int)$nc_q : "") . ")";
    $db->query(
        "SELECT u.`User_ID`, u.`Email`, u.`Name`, s.`status`, s.`ai_messages_used`, s.`expires_at` "
        . "FROM `User` u LEFT JOIN `ai_subscriptions` s ON s.`user_id`=u.`User_ID` "
        . "WHERE $where ORDER BY u.`User_ID` LIMIT 30",
        ARRAY_A
    );
} else {
    // без запроса — у кого статус уже менялся (последние движения сверху)
    $db->query(
        "SELECT u.`User_ID`, u.`Email`, u.`Name`, s.`status`, s.`ai_messages_used`, s.`expires_at` "
        . "FROM `ai_subscriptions` s JOIN `User` u ON u.`User_ID`=s.`user_id` "
        . "ORDER BY s.`updated_at` DESC LIMIT 30",
        ARRAY_A
    );
}
$nc_rows = (array)$db->last_result;
$nc_limit = subscription_free_limit();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Подписки на ИИ</title>
<style>
:root{--ink:#3a2b2b;--mut:#756d65;--line:#e3ddd2;--bg:#f5f2ec;--card:#fff;--brand:#c8ae88;--brand-d:#a68556}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Georgia,serif}
.wrap{max-width:820px;margin:0 auto;padding:26px 16px 60px}
h1{font-size:22px;font-weight:500;margin:0 0 4px}
.sub{color:var(--mut);font-size:13.5px;margin:0 0 18px}
.search{display:flex;gap:8px;margin-bottom:18px}
.search input{flex:1;border:1px solid var(--line);background:var(--card);border-radius:10px;padding:9px 12px;font:inherit;font-size:14px;color:var(--ink)}
.search button{border:0;border-radius:10px;padding:9px 16px;font:inherit;font-size:14px;background:var(--brand);cursor:pointer}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
th,td{padding:9px 12px;text-align:left;font-size:13.5px;border-bottom:1px solid var(--line)}
th{background:#efe9dd;font-weight:500;color:var(--mut)}
select{border:1px solid var(--line);border-radius:8px;padding:6px 8px;font:inherit;font-size:13px}
.save{border:0;border-radius:8px;padding:6px 12px;font:inherit;font-size:13px;background:var(--brand);cursor:pointer}
.save:hover{background:var(--brand-d)}
.msg{font-size:12.5px;margin-left:8px}
.empty{color:var(--mut);padding:22px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
  <h1>Подписки на ИИ</h1>
  <p class="sub"><?= $nc_limit >= 0
      ? 'Статус free блокирует ИИ-чат после ' . (int)$nc_limit . ' сообщений (настройка ai.free_message_limit)'
      : 'Лимит free-сообщений отключён (ai.free_message_limit &lt; 0)' ?>;
    trial и paid — без лимита до истечения срока. Смена статуса сбрасывает счётчик.
    Пока провайдер ИИ не настроен, заглушка бесплатна для всех.</p>

  <form class="search" method="get" action="">
    <input type="text" name="q" value="<?= esc($nc_q) ?>" placeholder="Email, имя или ID пользователя">
    <button type="submit">Найти</button>
  </form>

  <?php if (!$nc_rows): ?>
    <div class="empty"><?= $nc_q !== '' ? 'Никого не нашлось.' : 'Подписки ещё никому не переключали. Найдите пользователя поиском.' ?></div>
  <?php else: ?>
  <table>
    <tr><th>ID</th><th>Пользователь</th><th>Статус</th><th>Сообщений</th><th></th></tr>
    <?php foreach ($nc_rows as $r): ?>
    <tr data-user="<?= (int)$r['User_ID'] ?>">
      <td><?= (int)$r['User_ID'] ?></td>
      <td><?= esc($r['Email']) ?><?= trim((string)$r['Name']) !== '' ? '<br><span style="color:var(--mut)">' . esc($r['Name']) . '</span>' : '' ?></td>
      <td>
        <select class="js-status">
          <?php $cur = in_array($r['status'], subscription_statuses(), true) ? $r['status'] : 'free'; ?>
          <?php foreach (subscription_statuses() as $s): ?>
          <option value="<?= esc($s) ?>"<?= $s === $cur ? ' selected' : '' ?>><?= esc($s) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><?= (int)$r['ai_messages_used'] ?><?php
        if (!empty($r['expires_at'])) {
            echo '<br><span style="color:var(--mut);font-size:12px">до ' . esc($r['expires_at']) . '</span>';
        } ?></td>
      <td><input type="number" class="js-days" min="1" placeholder="дней"
                 title="Срок действия trial/paid в днях; пусто — бессрочно"
                 style="width:64px;border:1px solid var(--line);border-radius:8px;padding:6px 8px;font:inherit;font-size:13px">
          <button type="button" class="save js-save">Сохранить</button><span class="msg js-msg"></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<script>
document.addEventListener('click', function(e){
  if (!e.target.classList.contains('js-save')) return;
  var tr = e.target.closest('tr');
  var msg = tr.querySelector('.js-msg');
  msg.style.color = '#756d65'; msg.textContent = '…';
  var body = new URLSearchParams();
  body.append('user_id', tr.getAttribute('data-user'));
  body.append('status', tr.querySelector('.js-status').value);
  var days = tr.querySelector('.js-days').value.trim();
  if (days) body.append('days', days);
  fetch('/api/subscription_set.php', { method:'POST', body: body, credentials:'same-origin' })
    .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, js:j }; }); })
    .then(function(res){
      if (res.ok && res.js && res.js.ok) { msg.style.color = '#2c6e49'; msg.textContent = 'Сохранено'; }
      else { msg.style.color = '#c0392b'; msg.textContent = (res.js && res.js.error) ? res.js.error : 'Ошибка'; }
    })
    .catch(function(){ msg.style.color = '#c0392b'; msg.textContent = 'Сервис недоступен'; });
});
</script>
</body>
</html>
