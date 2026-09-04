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
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/mailing_lib.php';

// права, заголовки безопасности, CSP — в каркасе; не администратору — 403
list($nc_core, $db, $uid) = admin_bootstrap();

admin_page_start($uid, 'Рассылка по сегменту', 'mailing',
    'Отберите пользователей, проверьте количество и отправьте письмо. Шлём только кандидатам с подтверждённым email; отправка — очередью, порциями (без залпа).');
?>

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
        <button class="btn btn--ghost u-w100" id="btnPreview">Обновить предпросмотр</button>
      </div>
    </div>

    <div class="stack">
      <div class="count" id="countBox">
        <div>
          <div class="lbl">Получат письмо</div>
          <div class="count__n" id="cConfirmed">—</div>
        </div>
        <span class="spacer"></span>
        <div class="u-right">
          <div class="mini"><span id="cTotal">—</span> в сегменте</div>
          <div class="u-mt6"><span class="pill" id="cUnconfirmed">— не подтвердили email</span></div>
          <div class="mini u-mt6" id="cEta"></div>
        </div>
      </div>

      <div class="panel">
        <div class="fld u-mt0"><label for="mSubject">Тема</label>
          <input class="in" id="mSubject" type="text" maxlength="200">
        </div>
        <div class="fld"><label for="mBody">Текст письма</label>
          <textarea class="in" id="mBody" maxlength="20000"></textarea>
        </div>
        <div class="warnbox">В конец письма автоматически добавляется ссылка «Отписаться от рассылки» — убрать её нельзя. Отписавшиеся исключаются из всех рассылок.</div>
        <div class="row u-mt14">
          <button class="btn" id="btnSend" disabled>Отправить</button>
          <span class="mini" id="sendHint">Сначала обновите предпросмотр</span>
        </div>
        <div id="resultBox"></div>
      </div>
    </div>
  </div>

<?= admin_script_open() ?>
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

  function post(url, params) { return shAdmin.post(url, params); }

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
<?php admin_page_end(); ?>
