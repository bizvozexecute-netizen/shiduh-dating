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
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/cases_lib.php';

// права, заголовки безопасности, CSP — в каркасе; не администратору — 403
list($nc_core, $db, $uid) = admin_bootstrap();

$nc_groups = cases_groups();
$db->query("SELECT * FROM `assessment_cases` ORDER BY `dimension_group`, `sort_order`, `id`", ARRAY_A);
$nc_cases = (array)$db->last_result;

$nc_by_group = array();
foreach ($nc_cases as $c) {
    $nc_by_group[$c['dimension_group']][] = $c;
}

admin_page_start($uid, 'Кейсы ценностного опроса', 'cases',
    'ИИ задаёт эти вопросы в диалоге по одному и по ответам собирает ценностный профиль. Выключенный кейс не попадает в опрос, но остаётся в истории.');
?>

<div class="note">Формулировки — черновые, согласуйте с заказчиком и раввином. Цель — 5–7 кейсов на каждое измерение.</div>

<?php foreach ($nc_groups as $gkey => $g): ?>
  <h2><?= admin_esc($g['title']) ?></h2>
  <?php foreach ((array)($nc_by_group[$gkey] ?? array()) as $c): ?>
  <div class="case <?= $c['active'] ? '' : 'case--off' ?>" data-id="<?= (int)$c['id'] ?>">
    <div class="case__head">
      <span class="dim"><?= admin_esc(cases_dimension_title($gkey, $c['dimension'])) ?></span>
      <span class="badge <?= $c['active'] ? '' : 'badge--off' ?>"><?= $c['active'] ? 'в опросе' : 'выключен' ?></span>
      <span class="spacer"></span>
      <span class="mini">#<?= (int)$c['id'] ?></span>
    </div>
    <textarea class="txt js-text"><?= admin_esc($c['scenario_text']) ?></textarea>
    <div class="row">
      <button class="btn js-save">Сохранить</button>
      <button class="btn btn--ghost js-toggle"><?= $c['active'] ? 'Выключить' : 'Включить' ?></button>
      <span class="spacer"></span>
      <button class="btn btn--danger js-delete">Удалить</button>
    </div>
    <div class="msg"></div>
  </div>
  <?php endforeach; ?>

  <div class="add" data-group="<?= admin_esc($gkey) ?>">
    <div class="row u-mt0">
      <select class="in js-dim">
        <?php foreach ($g['dimensions'] as $dk => $dt): ?>
        <option value="<?= admin_esc($dk) ?>"><?= admin_esc($dt) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="mini">новый кейс в этой группе</span>
    </div>
    <textarea class="txt js-new-text u-mt9" placeholder="Текст вопроса, который ИИ задаст в диалоге"></textarea>
    <div class="row">
      <button class="btn js-create">Добавить кейс</button>
      <span class="msg"></span>
    </div>
  </div>
<?php endforeach; ?>

<?= admin_script_open() ?>
(function(){
  function post(params) { return shAdmin.post('/api/cases_save.php', params); }
  var say = shAdmin.say;

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
<?php admin_page_end(); ?>
