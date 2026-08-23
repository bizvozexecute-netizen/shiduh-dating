<?php
/*
 * Админ-экран кейсов ценностного опроса (ROADMAP Э2.2-a).
 *
 * Только администратор NetCat. Кейсы сгруппированы по измерениям; можно
 * добавить, отредактировать, включить/выключить и удалить. Правки — AJAX
 * на /api/cases_save.php (там повторная проверка прав и CSRF-заслон).
 * Standalone-страница, как api/* и admin-mailing.php (см. ROADMAP I-9).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_lib.php';
require_once dirname(__FILE__) . '/api/cases_lib.php';

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
        . '<p style="color:#756d65">Экран кейсов доступен только администратору.</p></body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');

$nc_groups = cases_groups();
$db->query("SELECT * FROM `assessment_cases` ORDER BY `dimension_group`, `sort_order`, `id`", ARRAY_A);
$nc_cases = (array)$db->last_result;

$nc_by_group = array();
foreach ($nc_cases as $c) {
    $nc_by_group[$c['dimension_group']][] = $c;
}
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Кейсы ценностного опроса</title>
<style>
:root{--ink:#3a2b2b;--mut:#756d65;--line:#e3ddd2;--bg:#f5f2ec;--card:#fff;--brand:#c8ae88;--brand-d:#a68556;--navy:#262839}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Georgia,serif}
.wrap{max-width:900px;margin:0 auto;padding:26px 16px 60px}
h1{font-size:22px;font-weight:500;margin:0 0 4px}
.sub{color:var(--mut);font-size:13.5px;margin:0 0 22px}
h2{font-size:17px;font-weight:500;margin:26px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.case{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:10px}
.case--off{opacity:.55}
.case__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.dim{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}
.badge{font-size:11.5px;border-radius:999px;padding:2px 9px;background:#e8f2e4;color:#3d6b2f;border:1px solid #bcd8b2}
.badge--off{background:#f0ece5;color:var(--mut);border-color:var(--line)}
.spacer{flex:1}
.txt{width:100%;border:1px solid var(--line);background:var(--bg);border-radius:10px;padding:10px 12px;font:inherit;font-size:14.5px;color:var(--ink);min-height:74px;resize:vertical}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:9px}
.btn{border:0;border-radius:10px;padding:8px 15px;font:inherit;font-size:14px;font-weight:500;background:var(--brand);color:var(--navy);cursor:pointer}
.btn:hover{background:var(--brand-d)}
.btn--ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.btn--ghost:hover{background:var(--bg)}
.btn--danger{background:transparent;border:1px solid #e3b6b6;color:#8a2a2a}
.btn--danger:hover{background:#f8e3e3}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.mini{font-size:12.5px;color:var(--mut)}
.add{background:var(--card);border:1px dashed var(--line);border-radius:12px;padding:16px;margin-top:14px}
.in,select.in{border:1px solid var(--line);background:var(--bg);border-radius:10px;padding:9px 11px;font:inherit;font-size:14px;color:var(--ink)}
.note{background:#faf3dc;border:1px solid #e8d9a0;color:#8a6d1a;border-radius:10px;padding:10px 13px;font-size:13.5px;margin-bottom:18px}
.msg{margin-top:8px;font-size:13.5px}
.msg--ok{color:#3d6b2f}
.msg--err{color:#8a2a2a}
</style>
</head>
<body>
<div class="wrap">
  <h1>Кейсы ценностного опроса</h1>
  <p class="sub">ИИ задаёт эти вопросы в диалоге по одному и по ответам собирает ценностный профиль. Выключенный кейс не попадает в опрос, но остаётся в истории.</p>
  <div class="note">Формулировки — черновые, согласуйте с заказчиком и раввином. Цель — 5–7 кейсов на каждое измерение.</div>

<?php foreach ($nc_groups as $gkey => $g): ?>
  <h2><?= esc($g['title']) ?></h2>
  <?php foreach ((array)($nc_by_group[$gkey] ?? array()) as $c): ?>
  <div class="case <?= $c['active'] ? '' : 'case--off' ?>" data-id="<?= (int)$c['id'] ?>">
    <div class="case__head">
      <span class="dim"><?= esc(cases_dimension_title($gkey, $c['dimension'])) ?></span>
      <span class="badge <?= $c['active'] ? '' : 'badge--off' ?>"><?= $c['active'] ? 'в опросе' : 'выключен' ?></span>
      <span class="spacer"></span>
      <span class="mini">#<?= (int)$c['id'] ?></span>
    </div>
    <textarea class="txt js-text"><?= esc($c['scenario_text']) ?></textarea>
    <div class="row">
      <button class="btn js-save">Сохранить</button>
      <button class="btn btn--ghost js-toggle"><?= $c['active'] ? 'Выключить' : 'Включить' ?></button>
      <span class="spacer"></span>
      <button class="btn btn--danger js-delete">Удалить</button>
    </div>
    <div class="msg"></div>
  </div>
  <?php endforeach; ?>

  <div class="add" data-group="<?= esc($gkey) ?>">
    <div class="row" style="margin-top:0">
      <select class="in js-dim">
        <?php foreach ($g['dimensions'] as $dk => $dt): ?>
        <option value="<?= esc($dk) ?>"><?= esc($dt) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="mini">новый кейс в этой группе</span>
    </div>
    <textarea class="txt js-new-text" style="margin-top:9px" placeholder="Текст вопроса, который ИИ задаст в диалоге"></textarea>
    <div class="row">
      <button class="btn js-create">Добавить кейс</button>
      <span class="msg"></span>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
(function(){
  function post(params) {
    return fetch('/api/cases_save.php', { method:'POST', body: params, credentials:'same-origin' })
      .then(function(r){ return r.json().catch(function(){ return {}; })
        .then(function(js){ return {code:r.status, js:js}; }); });
  }
  function say(el, text, ok) {
    el.textContent = text;
    el.className = 'msg ' + (ok ? 'msg--ok' : 'msg--err');
  }

  document.querySelectorAll('.case').forEach(function(card){
    var id = card.getAttribute('data-id');
    var msg = card.querySelector('.msg');

    card.querySelector('.js-save').addEventListener('click', function(){
      var btn = this; btn.disabled = true;
      var p = new URLSearchParams();
      p.append('action', 'update');
      p.append('id', id);
      p.append('scenario_text', card.querySelector('.js-text').value);
      post(p).then(function(res){
        btn.disabled = false;
        say(msg, res.js.ok ? 'Сохранено' : (res.js.error || 'Не удалось сохранить'), !!res.js.ok);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });

    card.querySelector('.js-toggle').addEventListener('click', function(){
      var btn = this; btn.disabled = true;
      var p = new URLSearchParams();
      p.append('action', 'toggle');
      p.append('id', id);
      post(p).then(function(res){
        btn.disabled = false;
        if (!res.js.ok) { say(msg, res.js.error || 'Не удалось', false); return; }
        var on = res.js.active === 1;
        card.classList.toggle('case--off', !on);
        btn.textContent = on ? 'Выключить' : 'Включить';
        var b = card.querySelector('.badge');
        b.textContent = on ? 'в опросе' : 'выключен';
        b.classList.toggle('badge--off', !on);
        say(msg, on ? 'Кейс снова в опросе' : 'Кейс выключен', true);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });

    card.querySelector('.js-delete').addEventListener('click', function(){
      if (!window.confirm('Удалить кейс безвозвратно? Обычно достаточно выключить.')) return;
      var btn = this; btn.disabled = true;
      var p = new URLSearchParams();
      p.append('action', 'delete');
      p.append('id', id);
      post(p).then(function(res){
        if (res.js.ok) { card.remove(); return; }
        btn.disabled = false;
        say(msg, res.js.error || 'Не удалось удалить', false);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });
  });

  document.querySelectorAll('.add').forEach(function(box){
    var msg = box.querySelector('.msg');
    box.querySelector('.js-create').addEventListener('click', function(){
      var text = box.querySelector('.js-new-text').value.trim();
      if (!text) { say(msg, 'Введите текст кейса', false); return; }
      var btn = this; btn.disabled = true;
      var p = new URLSearchParams();
      p.append('action', 'create');
      p.append('dimension_group', box.getAttribute('data-group'));
      p.append('dimension', box.querySelector('.js-dim').value);
      p.append('scenario_text', text);
      post(p).then(function(res){
        btn.disabled = false;
        if (res.js.ok) { window.location.reload(); return; }
        say(msg, res.js.error || 'Не удалось добавить', false);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });
  });
})();
</script>
</body>
</html>
