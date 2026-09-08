<?php
/*
 * Админ-экран «Сигналы внимания» (этап «До идеала», C10).
 *
 * Очередь сигналов, которые поднял ИИ-консультант по переписке кандидатов:
 * кто, какая категория, когда, кто ведёт анкету. Кнопка «Посмотрел» закрывает
 * сигнал через /api/moderation_resolve.php (там повторная проверка прав и
 * CSRF). Ниже — недавно закрытые. Никаких цитат из переписки здесь нет по
 * построению (risk_signal_lib.php): в заметке хранится только категория.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/moderation_lib.php';

list($nc_core, $db, $uid) = admin_bootstrap();

$nc_kinds = risk_kinds();
$nc_counts = moderation_counts($db);
$nc_open = moderation_open($db, 200);
$nc_done = moderation_resolved_recent($db, 30);

admin_page_start($uid, 'Сигналы внимания', 'moderation',
    'Сигналы поднимает ИИ-консультант по переписке кандидата. Это не диагноз и не оценка личности — повод живому человеку присмотреться. Закрыть сигнал может администратор или ведущий шадхан кандидата.');
?>

<div class="stat">
  <div class="panel"><div class="lbl">Открытых</div><div class="stat__n" id="statOpen"><?= (int)$nc_counts['open'] ?></div></div>
  <div class="panel"><div class="lbl">Закрыто за 7 дней</div><div class="stat__n"><?= (int)$nc_counts['resolved_7d'] ?></div></div>
  <div class="panel"><div class="lbl">Поднято за 30 дней</div><div class="stat__n"><?= (int)$nc_counts['total_30d'] ?></div></div>
</div>

<div class="note u-mt14"><?= admin_esc(risk_disclaimer()) ?></div>

<h2>Очередь</h2>
<?php if (!$nc_open): ?>
<div class="okbox" id="queueEmpty">Открытых сигналов нет.</div>
<?php else: ?>
<div class="table-wrap"><table class="table" id="queueTable">
  <thead><tr><th>Кандидат</th><th>Сигнал</th><th>Что делать</th><th>Ведут</th><th>Когда</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($nc_open as $n): ?>
  <tr data-note="<?= (int)$n['id'] ?>">
    <td>
      <?php if ((int)$n['anketa_id'] > 0): ?>
      <a href="/my/candidate/candidate_<?= (int)$n['anketa_id'] ?>.html"><?= admin_esc($n['user_name'] !== null && $n['user_name'] !== '' ? $n['user_name'] : 'ID ' . (int)$n['user_id']) ?></a>
      <?php else: ?>
      <?= admin_esc($n['user_name'] !== null && $n['user_name'] !== '' ? $n['user_name'] : 'ID ' . (int)$n['user_id']) ?>
      <?php endif; ?>
      <span class="mini">ID <?= (int)$n['user_id'] ?></span>
    </td>
    <td><?= admin_esc(isset($nc_kinds[$n['kind']]) ? $nc_kinds[$n['kind']] : $n['kind']) ?></td>
    <td class="mini"><?= admin_esc(risk_kind_hint($n['kind'])) ?></td>
    <td><?= admin_esc($n['shadhans'] !== null && $n['shadhans'] !== '' ? $n['shadhans'] : '—') ?></td>
    <td class="mini"><?= admin_esc((string)$n['created_at']) ?></td>
    <td class="u-right"><button class="btn btn--ghost js-resolve" type="button" data-id="<?= (int)$n['id'] ?>">Посмотрел</button></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<div class="msg" id="queueMsg"></div>

<h2>Недавно закрытые</h2>
<?php if (!$nc_done): ?>
<div class="mini">Пока ничего не закрывали.</div>
<?php else: ?>
<div class="table-wrap"><table class="table" id="doneTable">
  <thead><tr><th>Кандидат</th><th>Сигнал</th><th>Поднят</th><th>Закрыт</th><th>Кем</th></tr></thead>
  <tbody>
  <?php foreach ($nc_done as $n): ?>
  <tr>
    <td><?= admin_esc($n['user_name'] !== null && $n['user_name'] !== '' ? $n['user_name'] : 'ID ' . (int)$n['user_id']) ?></td>
    <td><?= admin_esc(isset($nc_kinds[$n['kind']]) ? $nc_kinds[$n['kind']] : $n['kind']) ?></td>
    <td class="mini"><?= admin_esc((string)$n['created_at']) ?></td>
    <td class="mini"><?= admin_esc((string)$n['resolved_at']) ?></td>
    <td><?= admin_esc($n['resolver_name'] !== null && $n['resolver_name'] !== '' ? $n['resolver_name'] : 'ID ' . (int)$n['resolved_by']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?= admin_script_open() ?>
(function () {
  var msg = document.getElementById('queueMsg');
  var counter = document.getElementById('statOpen');
  document.querySelectorAll('.js-resolve').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      shAdmin.post('/api/moderation_resolve.php', new URLSearchParams({ note_id: btn.getAttribute('data-id') })).then(function (r) {
        if (r.code !== 200) {
          btn.disabled = false;
          shAdmin.say(msg, (r.js && r.js.error) ? r.js.error : 'Не удалось закрыть сигнал', false);
          return;
        }
        var row = btn.closest('tr');
        if (row) { row.parentNode.removeChild(row); }
        if (counter) { counter.textContent = Math.max(0, parseInt(counter.textContent, 10) - 1); }
        shAdmin.say(msg, 'Сигнал закрыт', true);
        var table = document.getElementById('queueTable');
        if (table && !table.querySelector('tbody tr')) {
          var box = document.createElement('div');
          box.className = 'okbox';
          box.textContent = 'Открытых сигналов нет.';
          table.parentNode.parentNode.replaceChild(box, table.parentNode);
        }
      });
    });
  });
})();
</script>
<?php admin_page_end(); ?>
