<?php
/*
 * Админ-экран массовой рассылки (ROADMAP Р-1). Дизайн — по прототипу
 * презентации (экран «Рассылка»).
 *
 * Только администратор NetCat (Permission.AdminType 6/7). Сегмент задаётся
 * фильтрами, предпросмотр и отправка — AJAX на /api/mailing_preview.php и
 * /api/mailing_send.php (там же повторная проверка прав и CSRF-заслон).
 * Standalone-страница, как api/*: выкатывается копированием файла, минуя
 * двойное хранение шаблонов NetCat (см. ROADMAP I-9).
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/mailing_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!mailing_is_admin($db, $uid)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Доступ запрещён</title></head>'
        . '<body style="font-family:Georgia,serif;text-align:center;padding:80px 20px;color:#3a2b2b">'
        . '<h1 style="font-size:20px">Доступ запрещён</h1>'
        . '<p style="color:#756d65">Экран рассылки доступен только администратору.</p></body></html>';
    exit;
}
header('X-Frame-Options: DENY');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Рассылка по сегменту</title>
<style>
:root{--ink:#3a2b2b;--mut:#756d65;--line:#e3ddd2;--bg:#f5f2ec;--card:#fff;--brand:#c8ae88;--brand-d:#a68556;--navy:#262839;--warn:#8a6d1a;--warn-bg:#faf3dc;--warn-line:#e8d9a0}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Georgia,serif}
.wrap{max-width:980px;margin:0 auto;padding:26px 16px 60px}
h1{font-size:22px;font-weight:500;margin:0 0 4px}
.sub{color:var(--mut);font-size:13.5px;margin:0 0 22px}
.grid{display:grid;grid-template-columns:280px 1fr;gap:16px}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px}
.lbl{font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--mut)}
.fld{margin-top:12px}
.fld label{display:block;font-size:13px;color:var(--mut);margin-bottom:5px}
.in{width:100%;border:1px solid var(--line);background:var(--bg);border-radius:10px;padding:9px 11px;font:inherit;font-size:14px;color:var(--ink)}
.two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.btn{border:0;border-radius:11px;padding:11px 18px;font:inherit;font-size:14.5px;font-weight:500;background:var(--brand);color:var(--navy);cursor:pointer}
.btn:hover{background:var(--brand-d)}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.btn--ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.btn--ghost:hover{background:var(--bg)}
.count{background:var(--navy);color:#fff;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.count__n{font-size:30px;line-height:1.1}
.count .lbl{color:#c3beb7}
.mini{font-size:12.5px;color:var(--mut)}
.count .mini{color:#c3beb7}
.pill{display:inline-block;border-radius:999px;padding:3px 10px;font-size:12px;background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn-line)}
.warnbox{background:var(--warn-bg);border:1px solid var(--warn-line);color:var(--warn);border-radius:10px;padding:10px 13px;font-size:13.5px;margin-top:12px}
.okbox{background:#e8f2e4;border:1px solid #bcd8b2;color:#3d6b2f;border-radius:10px;padding:10px 13px;font-size:13.5px;margin-top:12px}
.errbox{background:#f8e3e3;border:1px solid #e3b6b6;color:#8a2a2a;border-radius:10px;padding:10px 13px;font-size:13.5px;margin-top:12px}
textarea.in{min-height:140px;resize:vertical}
.spacer{flex:1}
.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
</style>
</head>
<body>
<div class="wrap">
  <h1>Рассылка по сегменту</h1>
  <p class="sub">Отберите пользователей, проверьте количество и отправьте письмо. Шлём только кандидатам с подтверждённым email; отправка — очередью, порциями (без залпа).</p>

  <div class="grid">
    <div class="panel">
      <div class="lbl">Сегмент</div>
      <div class="fld"><label for="fGender">Пол</label>
        <select class="in" id="fGender">
          <option value="">Любой</option>
          <option value="1">Мужчины</option>
          <option value="2">Женщины</option>
        </select>
      </div>
      <div class="fld"><label>Возраст</label>
        <div class="two">
          <input class="in" id="fAgeMin" type="number" min="16" max="120" placeholder="от">
          <input class="in" id="fAgeMax" type="number" min="16" max="120" placeholder="до">
        </div>
      </div>
      <div class="fld"><label for="fCity">Город (по анкете, подстрока)</label>
        <input class="in" id="fCity" type="text" placeholder="например, Москва">
      </div>
      <div class="fld">
        <button class="btn btn--ghost" id="btnPreview" style="width:100%">Обновить предпросмотр</button>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:14px">
      <div class="count" id="countBox">
        <div>
          <div class="lbl">Получат письмо</div>
          <div class="count__n" id="cConfirmed">—</div>
        </div>
        <span class="spacer"></span>
        <div style="text-align:right">
          <div class="mini"><span id="cTotal">—</span> в сегменте</div>
          <div style="margin-top:6px"><span class="pill" id="cUnconfirmed">— не подтвердили email</span></div>
          <div class="mini" id="cEta" style="margin-top:6px"></div>
        </div>
      </div>

      <div class="panel">
        <div class="fld" style="margin-top:0"><label for="mSubject">Тема</label>
          <input class="in" id="mSubject" type="text" maxlength="200">
        </div>
        <div class="fld"><label for="mBody">Текст письма</label>
          <textarea class="in" id="mBody" maxlength="20000"></textarea>
        </div>
        <div class="warnbox">В конец письма автоматически добавляется ссылка «Отписаться от рассылки» — убрать её нельзя. Отписавшиеся исключаются из всех рассылок.</div>
        <div class="row" style="margin-top:14px">
          <button class="btn" id="btnSend" disabled>Отправить</button>
          <span class="mini" id="sendHint">Сначала обновите предпросмотр</span>
        </div>
        <div id="resultBox"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var confirmed = null;

  function filters() {
    var p = new URLSearchParams();
    var g = document.getElementById('fGender').value;
    var a1 = document.getElementById('fAgeMin').value;
    var a2 = document.getElementById('fAgeMax').value;
    var c = document.getElementById('fCity').value.trim();
    if (g) p.append('gender', g);
    if (a1) p.append('age_min', a1);
    if (a2) p.append('age_max', a2);
    if (c) p.append('city', c);
    return p;
  }

  function post(url, params) {
    return fetch(url, { method:'POST', body: params, credentials:'same-origin' })
      .then(function(r){ return r.json().then(function(js){ return {code:r.status, js:js}; }); });
  }

  function showResult(cls, text) {
    document.getElementById('resultBox').innerHTML =
      '<div class="' + cls + '"></div>';
    document.getElementById('resultBox').firstChild.textContent = text;
  }

  document.getElementById('btnPreview').addEventListener('click', function(){
    var btn = this; btn.disabled = true;
    post('/api/mailing_preview.php', filters()).then(function(res){
      btn.disabled = false;
      if (!res.js.ok) { showResult('errbox', res.js.error || 'Ошибка предпросмотра'); return; }
      confirmed = res.js.confirmed;
      document.getElementById('cConfirmed').textContent = res.js.confirmed;
      document.getElementById('cTotal').textContent = res.js.total;
      document.getElementById('cUnconfirmed').textContent = res.js.unconfirmed + ' не подтвердили email';
      document.getElementById('cEta').textContent =
        res.js.confirmed > 0 ? '≈ ' + Math.ceil(res.js.confirmed / 20) + ' мин, по 20 писем за тик' : '';
      var send = document.getElementById('btnSend');
      send.disabled = res.js.confirmed === 0;
      send.textContent = 'Отправить ' + res.js.confirmed + ' писем';
      document.getElementById('sendHint').textContent = '';
      document.getElementById('resultBox').innerHTML = '';
    }).catch(function(){ btn.disabled = false; showResult('errbox', 'Сервис недоступен'); });
  });

  document.getElementById('btnSend').addEventListener('click', function(){
    var subject = document.getElementById('mSubject').value.trim();
    var body = document.getElementById('mBody').value.trim();
    if (!subject) { showResult('errbox', 'Заполните тему письма'); return; }
    if (!body) { showResult('errbox', 'Заполните текст письма'); return; }
    if (!window.confirm('Отправить письмо ' + confirmed + ' получателям?')) return;
    var btn = this; btn.disabled = true;
    var p = filters();
    p.append('subject', subject);
    p.append('body', body);
    post('/api/mailing_send.php', p).then(function(res){
      btn.disabled = false;
      if (res.js.ok) {
        showResult('okbox', 'Поставлено в очередь: ' + res.js.queued
          + ' писем (рассылка №' + res.js.mailing_id + '). Отправит крон порциями.');
      } else {
        showResult('errbox', res.js.error || 'Не удалось отправить');
      }
    }).catch(function(){ btn.disabled = false; showResult('errbox', 'Сервис недоступен'); });
  });
})();
</script>
</body>
</html>
