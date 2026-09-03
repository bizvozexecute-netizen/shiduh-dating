<?php
/*
 * Админ-экран системного промпта ИИ-консультанта (ROADMAP Э1.2).
 * Дизайн — по прототипу презентации (экран «Настройка ИИ»).
 *
 * Только администратор NetCat. Сохранение создаёт новую версию; ниже —
 * журнал версий с возможностью вернуться к любой. Правки — AJAX на
 * /api/prompt_save.php (там повторная проверка прав и CSRF-заслон).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_lib.php';
require_once dirname(__FILE__) . '/api/ai_client.php';
require_once dirname(__FILE__) . '/api/prompt_lib.php';

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
        . '<p style="color:#756d65">Экран настройки ИИ доступен только администратору.</p></body></html>';
    exit;
}
header('X-Frame-Options: DENY');
header('Content-Type: text/html; charset=utf-8');

$nc_active  = prompt_active($db);
$nc_history = prompt_history($db);
$nc_current = ($nc_active && trim((string)$nc_active['content']) !== '')
    ? $nc_active['content'] : AI_DEFAULT_SYSTEM_PROMPT;
$nc_is_default = !$nc_active;
$nc_cfg = ai_config();

function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function author_name($db, $id) {
    $id = (int)$id;
    if (!$id) {
        return '—';
    }
    $name = $db->get_var("SELECT `Name` FROM `User` WHERE `User_ID`=$id");
    return $name ? $name : ('ID ' . $id);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="csrf-token" content="<?= htmlspecialchars(shiduh_csrf_token($uid), ENT_QUOTES) ?>">
<script src="/js/csrf.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Настройка ИИ-консультанта</title>
<style>
:root{--ink:#3a2b2b;--mut:#756d65;--line:#e3ddd2;--bg:#f5f2ec;--card:#fff;--brand:#c8ae88;--brand-d:#a68556;--navy:#262839;--warn:#8a6d1a;--warn-bg:#faf3dc;--warn-line:#e8d9a0}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Georgia,serif}
.wrap{max-width:900px;margin:0 auto;padding:26px 16px 60px}
h1{font-size:22px;font-weight:500;margin:0 0 4px}
.sub{color:var(--mut);font-size:13.5px;margin:0 0 20px}
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px}
.lbl{font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--mut)}
.txt{width:100%;border:1px solid var(--line);background:var(--bg);border-radius:10px;padding:12px 13px;font:inherit;font-size:14.5px;color:var(--ink);min-height:260px;resize:vertical;margin-top:10px}
.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.btn{border:0;border-radius:11px;padding:10px 18px;font:inherit;font-size:14.5px;font-weight:500;background:var(--brand);color:var(--navy);cursor:pointer}
.btn:hover{background:var(--brand-d)}
.btn--ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.btn--ghost:hover{background:var(--bg)}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.mini{font-size:12.5px;color:var(--mut)}
.spacer{flex:1}
.note{background:var(--warn-bg);border:1px solid var(--warn-line);color:var(--warn);border-radius:10px;padding:10px 13px;font-size:13.5px;margin-bottom:16px}
h2{font-size:17px;font-weight:500;margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.ver{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:9px}
.ver__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.badge{font-size:11.5px;border-radius:999px;padding:2px 9px;background:#e8f2e4;color:#3d6b2f;border:1px solid #bcd8b2}
.ver__text{margin-top:7px;font-size:13.5px;color:var(--mut);white-space:pre-wrap;max-height:4.6em;overflow:hidden}
.msg{margin-top:8px;font-size:13.5px}
.msg--ok{color:#3d6b2f}
.msg--err{color:#8a2a2a}
.state{display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;background:#f0ece5;color:var(--mut);border:1px solid var(--line)}
</style>
</head>
<body>
<div class="wrap">
  <h1>Настройка ИИ-консультанта</h1>
  <p class="sub">Системный промпт задаёт, как консультант ведёт беседу с кандидатом. Каждое сохранение создаёт новую версию — к любой можно вернуться.</p>

  <div class="note">
    Провайдер сейчас: <b><?= esc($nc_cfg['provider']) ?></b><?php if ($nc_cfg['provider'] === 'stub'): ?>
    — модель ещё не подключена, консультант отвечает заглушкой. Промпт можно готовить заранее: он вступит в силу сразу после подключения ключа.<?php endif; ?>
    <br>Не просите в промпте сведения о здоровье, лекарствах и доходе: эти данные кандидат вносит в закрытые поля анкеты и во внешнюю модель не передаются.
  </div>

  <div class="panel">
    <div class="lbl">Системный промпт
      <?php if ($nc_is_default): ?><span class="state">по умолчанию, ещё не сохранялся</span>
      <?php else: ?><span class="state">версия <?= (int)$nc_active['version'] ?> от <?= esc($nc_active['updated_at']) ?></span><?php endif; ?>
    </div>
    <textarea class="txt" id="promptText" maxlength="<?= (int)PROMPT_MAX_LEN ?>"><?= esc($nc_current) ?></textarea>
    <div class="row">
      <button class="btn" id="btnSave">Сохранить новую версию</button>
      <span class="spacer"></span>
      <span class="mini" id="counter"></span>
    </div>
    <div class="msg" id="msg"></div>
  </div>

  <h2>История версий</h2>
  <?php if (!$nc_history): ?>
    <p class="mini">Промпт ещё ни разу не сохраняли — работает встроенный текст по умолчанию.</p>
  <?php endif; ?>
  <?php foreach ($nc_history as $i => $h): ?>
  <div class="ver">
    <div class="ver__head">
      <b>Версия <?= (int)$h['version'] ?></b>
      <?php if ($i === 0): ?><span class="badge">действует</span><?php endif; ?>
      <span class="mini"><?= esc($h['updated_at']) ?> · <?= esc(author_name($db, $h['updated_by'])) ?></span>
      <span class="spacer"></span>
      <?php if ($i !== 0): ?>
      <button class="btn btn--ghost js-restore" data-version="<?= (int)$h['version'] ?>">Вернуть эту версию</button>
      <?php endif; ?>
    </div>
    <div class="ver__text"><?= esc($h['content']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  var PROMPT_MAX = <?= (int)PROMPT_MAX_LEN ?>;
  var text = document.getElementById('promptText');
  var msg = document.getElementById('msg');
  var counter = document.getElementById('counter');

  function say(el, t, ok) {
    el.textContent = t;
    el.className = 'msg ' + (ok ? 'msg--ok' : 'msg--err');
  }
  function updateCounter() {
    counter.textContent = text.value.length + ' из ' + PROMPT_MAX + ' символов';
  }
  text.addEventListener('input', updateCounter);
  updateCounter();

  function post(params) {
    return fetch('/api/prompt_save.php', { method:'POST', body: params, credentials:'same-origin' })
      .then(function(r){ return r.json().catch(function(){ return {}; })
        .then(function(js){ return {code:r.status, js:js}; }); });
  }

  document.getElementById('btnSave').addEventListener('click', function(){
    var value = text.value.trim();
    if (!value) { say(msg, 'Введите текст промпта', false); return; }
    var btn = this; btn.disabled = true;
    var p = new URLSearchParams();
    p.append('action', 'save');
    p.append('content', value);
    post(p).then(function(res){
      btn.disabled = false;
      if (res.js.ok) { window.location.reload(); return; }
      say(msg, res.js.error || 'Не удалось сохранить', false);
    }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
  });

  document.querySelectorAll('.js-restore').forEach(function(btn){
    btn.addEventListener('click', function(){
      var v = btn.getAttribute('data-version');
      if (!window.confirm('Вернуть версию ' + v + '? Она будет записана как новая версия.')) return;
      btn.disabled = true;
      var p = new URLSearchParams();
      p.append('action', 'restore');
      p.append('version', v);
      post(p).then(function(res){
        if (res.js.ok) { window.location.reload(); return; }
        btn.disabled = false;
        say(msg, res.js.error || 'Не удалось вернуть версию', false);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });
  });
})();
</script>
</body>
</html>
