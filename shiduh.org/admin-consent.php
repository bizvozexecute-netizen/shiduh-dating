<?php
/*
 * Админ-экран «Согласия на сведения о здоровье» (этап «До идеала», C11).
 *
 * Сколько анкет с согласием, сколько — со сведениями без согласия (заполнены
 * до появления галочки X-5). Список таких анкет без самого поля здоровья,
 * выгрузка CSV и запрос повторного согласия письмом. Стирать данные экран
 * не умеет — по решению заказчика; сотрудник может сделать это только
 * вручную через анкету.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/consent_admin_lib.php';

list($nc_core, $db, $uid) = admin_bootstrap();

$nc_stats = consent_stats($db);
$nc_rows = consent_pending($db, 200, 0);
$nc_recipients = count(consent_pending_recipients($db));
$nc_last = $db->get_row(
    "SELECT `id`, `recipients_total`, `created_at` FROM `mailings` "
    . "WHERE `filters`='" . $db->prepare(json_encode(consent_mailing_filters(), JSON_UNESCAPED_UNICODE)) . "' "
    . "ORDER BY `id` DESC LIMIT 1",
    ARRAY_A
);

admin_page_start($uid, 'Согласия на сведения о здоровье', 'consent',
    'Поле «Состояние здоровья» — специальная категория персональных данных: хранить его можно только с отдельного согласия. Здесь видно, у кого сведения есть, а согласия нет, и можно попросить его повторно. Само поле экран не показывает.');
?>

<div class="stat">
  <div class="panel"><div class="lbl">Согласие дано</div><div class="stat__n" id="statWith"><?= (int)$nc_stats['with'] ?></div></div>
  <div class="panel"><div class="lbl">Сведения есть, согласия нет</div><div class="stat__n" id="statPending"><?= (int)$nc_stats['pending'] ?></div></div>
  <div class="panel"><div class="lbl">Поле пустое</div><div class="stat__n" id="statEmpty"><?= (int)$nc_stats['empty'] ?></div></div>
  <div class="panel"><div class="lbl">Анкет всего</div><div class="stat__n" id="statTotal"><?= (int)$nc_stats['total'] ?></div></div>
</div>

<div class="note u-mt14">Данные не стираются автоматически — таково решение заказчика. Человек сам либо даёт согласие в анкете, либо очищает поле.</div>

<div class="grid u-mt14">
  <div class="panel">
    <div class="lbl">Запросить согласие повторно</div>
    <p class="mini">Адресаты: владельцы таких анкет с подтверждённым e-mail, не отписавшиеся от рассылок — сейчас <b id="recipientsCount"><?= (int)$nc_recipients ?></b>.
      <?php if ($nc_last): ?>Последний запрос: №<?= (int)$nc_last['id'] ?>, <?= admin_esc((string)$nc_last['created_at']) ?>, писем <?= (int)$nc_last['recipients_total'] ?>.<?php endif; ?></p>
    <div class="fld"><label for="fSubject">Тема</label><input class="in" id="fSubject" maxlength="200" value="<?= admin_esc(consent_request_subject()) ?>"></div>
    <div class="fld"><label for="fBody">Текст письма</label><textarea class="txt" id="fBody" rows="12"><?= admin_esc(consent_request_text()) ?></textarea></div>
    <div class="row">
      <button class="btn" id="btnSend" type="button">Отправить запрос согласия</button>
      <a class="btn btn--ghost" href="/api/consent_export.php">Скачать список (CSV)</a>
    </div>
    <div class="msg" id="sendMsg"></div>
  </div>
</div>

<h2>Анкеты без согласия<?php if (count($nc_rows) < (int)$nc_stats['pending']): ?> <span class="mini">— показаны первые <?= count($nc_rows) ?> из <?= (int)$nc_stats['pending'] ?>, полный список в CSV</span><?php endif; ?></h2>
<?php if (!$nc_rows): ?>
<div class="okbox">Таких анкет нет.</div>
<?php else: ?>
<div class="table-wrap"><table class="table" id="pendingTable">
  <thead><tr><th>Анкета</th><th>Имя</th><th>E-mail</th><th>Подтверждён</th><th>Рассылки</th><th>Изменена</th></tr></thead>
  <tbody>
  <?php foreach ($nc_rows as $r): ?>
  <tr data-anketa="<?= (int)$r['Message_ID'] ?>">
    <td><a href="/my/candidate/candidate_<?= (int)$r['Message_ID'] ?>.html">№<?= (int)$r['Message_ID'] ?></a></td>
    <td><?= admin_esc($r['Name']) ?> <span class="mini">ID <?= (int)$r['User_ID'] ?></span></td>
    <td><?= admin_esc((string)$r['Email']) ?></td>
    <td><?= (int)$r['Confirmed'] ? 'да' : '<span class="mini">нет</span>' ?></td>
    <td><?= (int)$r['opted_out'] ? '<span class="mini">отписан</span>' : 'да' ?></td>
    <td class="mini"><?= admin_esc((string)$r['LastUpdated']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?= admin_script_open() ?>
(function () {
  var btn = document.getElementById('btnSend');
  var msg = document.getElementById('sendMsg');
  if (!btn) { return; }
  btn.addEventListener('click', function () {
    var n = parseInt(document.getElementById('recipientsCount').textContent, 10) || 0;
    if (!window.confirm('Отправить письмо ' + n + ' адресатам?')) { return; }
    btn.disabled = true;
    shAdmin.say(msg, 'Ставим в очередь…', true);
    shAdmin.post('/api/consent_request_mailing.php', new URLSearchParams({
      subject: document.getElementById('fSubject').value,
      body: document.getElementById('fBody').value
    })).then(function (r) {
      btn.disabled = false;
      if (r.code !== 200) {
        shAdmin.say(msg, (r.js && r.js.error) ? r.js.error : 'Не удалось отправить', false);
        return;
      }
      shAdmin.say(msg, 'В очереди писем: ' + r.js.queued + (r.js.mailing_id ? ' (рассылка №' + r.js.mailing_id + ')' : ''), true);
    });
  });
})();
</script>
<?php admin_page_end(); ?>
