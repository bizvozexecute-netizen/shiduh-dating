<?php
/*
 * Админ-экран «Подбор пар» (этап «До идеала», C12).
 *
 * Как шадханы реагируют на рекомендации ИИ (предложил / отклонил и почему)
 * и сколько обоснований пар запрашивалось у модели. Только чтение: цифры
 * считает api/match_stats_lib.php, имён кандидатов на экране нет.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/match_stats_lib.php';

list($nc_core, $db, $uid) = admin_bootstrap();

$nc_s = match_stats($db);
$nc_actions = match_stats_action_titles();
$nc_reasons = match_stats_reason_titles();

admin_page_start($uid, 'Подбор пар', 'matches',
    'Реакции шадханов на рекомендации ИИ и расход обоснований. Если отклонений заметно больше предложений — стоит посмотреть причины и веса подбора.');
?>

<div class="stat">
  <div class="panel"><div class="lbl">Решений по парам</div><div class="stat__n" id="statFeedback"><?= (int)$nc_s['feedback_total'] ?></div></div>
  <div class="panel"><div class="lbl">За 30 дней</div><div class="stat__n" id="statFeedback30"><?= (int)$nc_s['feedback_30d'] ?></div></div>
  <div class="panel"><div class="lbl">Уникальных пар предложено</div><div class="stat__n" id="statPairs"><?= (int)$nc_s['pairs_proposed'] ?></div></div>
  <div class="panel"><div class="lbl">Обоснований всего</div><div class="stat__n" id="statExplain"><?= (int)$nc_s['explanations_total'] ?></div></div>
  <div class="panel"><div class="lbl">Обоснований за 7 дней</div><div class="stat__n" id="statExplain7"><?= (int)$nc_s['explanations_7d'] ?></div></div>
  <div class="panel"><div class="lbl">Пар с обоснованием</div><div class="stat__n" id="statExplainPairs"><?= (int)$nc_s['explanations_pairs'] ?></div></div>
</div>

<div class="grid u-mt14">
  <div class="panel">
    <div class="lbl">Решения</div>
    <div class="table-wrap"><table class="table u-mt9" id="actionsTable">
      <thead><tr><th>Действие</th><th class="u-right">Сколько</th></tr></thead>
      <tbody>
      <?php if (!$nc_s['by_action']): ?><tr><td colspan="2" class="mini">Решений ещё не было.</td></tr><?php endif; ?>
      <?php foreach ($nc_s['by_action'] as $r): ?>
      <tr data-action="<?= admin_esc($r['action']) ?>"><td><?= admin_esc(isset($nc_actions[$r['action']]) ? $nc_actions[$r['action']] : $r['action']) ?></td><td class="u-right"><?= (int)$r['n'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel">
    <div class="lbl">Причины отклонений</div>
    <div class="table-wrap"><table class="table u-mt9" id="reasonsTable">
      <thead><tr><th>Причина</th><th class="u-right">Сколько</th></tr></thead>
      <tbody>
      <?php if (!$nc_s['by_reason']): ?><tr><td colspan="2" class="mini">Отклонений ещё не было.</td></tr><?php endif; ?>
      <?php foreach ($nc_s['by_reason'] as $r): ?>
      <tr data-reason="<?= admin_esc($r['reason']) ?>"><td><?= admin_esc(isset($nc_reasons[$r['reason']]) ? $nc_reasons[$r['reason']] : $r['reason']) ?></td><td class="u-right"><?= (int)$r['n'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="grid u-mt14">
  <div class="panel">
    <div class="lbl">Самые активные шадханы</div>
    <div class="table-wrap"><table class="table u-mt9" id="shadhansTable">
      <thead><tr><th>Шадхан</th><th class="u-right">Предложил</th><th class="u-right">Отклонил</th><th class="u-right">Всего</th></tr></thead>
      <tbody>
      <?php if (!$nc_s['by_shadhan']): ?><tr><td colspan="4" class="mini">Пока пусто.</td></tr><?php endif; ?>
      <?php foreach ($nc_s['by_shadhan'] as $r): ?>
      <tr data-shadhan="<?= (int)$r['shadhan_id'] ?>"><td><?= admin_esc($r['Name'] !== null && $r['Name'] !== '' ? $r['Name'] : 'ID ' . (int)$r['shadhan_id']) ?></td><td class="u-right"><?= (int)$r['proposed'] ?></td><td class="u-right"><?= (int)$r['rejected'] ?></td><td class="u-right"><?= (int)$r['n'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel">
    <div class="lbl">Модели обоснований</div>
    <div class="table-wrap"><table class="table u-mt9" id="modelsTable">
      <thead><tr><th>Модель</th><th class="u-right">Обоснований</th></tr></thead>
      <tbody>
      <?php if (!$nc_s['explanations_models']): ?><tr><td colspan="2" class="mini">Обоснований ещё не было.</td></tr><?php endif; ?>
      <?php foreach ($nc_s['explanations_models'] as $r): ?>
      <tr><td><?= admin_esc((string)$r['model'] !== '' ? $r['model'] : '—') ?></td><td class="u-right"><?= (int)$r['n'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php admin_page_end(); ?>
