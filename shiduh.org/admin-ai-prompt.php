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
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/ai_client.php';
require_once dirname(__FILE__) . '/api/prompt_lib.php';

// права, заголовки безопасности, CSP — в каркасе; не администратору — 403
list($nc_core, $db, $uid) = admin_bootstrap();

$nc_active  = prompt_active($db);
$nc_history = prompt_history($db);
$nc_current = ($nc_active && trim((string)$nc_active['content']) !== '')
    ? $nc_active['content'] : AI_DEFAULT_SYSTEM_PROMPT;
$nc_is_default = !$nc_active;
$nc_cfg = ai_config();

admin_page_start($uid, 'Промпт консультанта', 'prompt',
    'Системный промпт задаёт, как консультант ведёт беседу с кандидатом. Каждое сохранение создаёт новую версию — к любой можно вернуться.');
?>

<div class="note">
    Провайдер сейчас: <b><?= admin_esc($nc_cfg['provider']) ?></b><?php if ($nc_cfg['provider'] === 'stub'): ?>
    — модель ещё не подключена, консультант отвечает заглушкой. Промпт можно готовить заранее: он вступит в силу сразу после подключения ключа.<?php endif; ?>
    <br>Не просите в промпте сведения о здоровье, лекарствах и доходе: эти данные кандидат вносит в закрытые поля анкеты и во внешнюю модель не передаются.
  </div>

  <div class="panel">
    <div class="lbl">Системный промпт
      <?php if ($nc_is_default): ?><span class="state">по умолчанию, ещё не сохранялся</span>
      <?php else: ?><span class="state">версия <?= (int)$nc_active['version'] ?> от <?= admin_esc($nc_active['updated_at']) ?></span><?php endif; ?>
    </div>
    <textarea class="txt" id="promptText" maxlength="<?= (int)PROMPT_MAX_LEN ?>"><?= admin_esc($nc_current) ?></textarea>
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
      <span class="mini"><?= admin_esc($h['updated_at']) ?> · <?= admin_esc(admin_author_name($db, $h['updated_by'])) ?></span>
      <span class="spacer"></span>
      <?php if ($i !== 0): ?>
      <button class="btn btn--ghost js-restore" data-version="<?= (int)$h['version'] ?>">Вернуть эту версию</button>
      <?php endif; ?>
    </div>
    <div class="ver__text"><?= admin_esc($h['content']) ?></div>
  </div>
  <?php endforeach; ?>

<?= admin_script_open() ?>
(function(){
  var PROMPT_MAX = <?= (int)PROMPT_MAX_LEN ?>;
  var text = document.getElementById('promptText');
  var msg = document.getElementById('msg');
  var counter = document.getElementById('counter');

  var say = shAdmin.say;
  function updateCounter() {
    counter.textContent = text.value.length + ' из ' + PROMPT_MAX + ' символов';
  }
  text.addEventListener('input', updateCounter);
  updateCounter();

  function post(params) { return shAdmin.post('/api/prompt_save.php', params); }

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
<?php admin_page_end(); ?>
