<?php
/*
 * Админ-экран «ИИ и подключение» (этап «До идеала», C9).
 *
 * Ключ провайдера, модель, пределы, RAG, капча, суперадминистраторы — всё,
 * что раньше вводилось правкой таблицы Settings. Плюс проверка подключения
 * (дотягиваемся ли до провайдера с этим ключом, жив ли RAG) и расход по
 * ai_usage_log — иначе о цене диалогов узнавали из счёта провайдера.
 *
 * Секреты не печатаются никогда: только хвост из четырёх символов.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__)) . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/api/admin_layout.php';
require_once dirname(__FILE__) . '/api/ai_settings_lib.php';

list($nc_core, $db, $uid) = admin_bootstrap();

$nc_spec = ai_settings_spec();
$nc_val = ai_settings_read();

// --- расход: по дням за 30 дней, по видам, ошибки, самые активные ------------
$nc_has_log = (bool)$db->get_var("SHOW TABLES LIKE 'ai_usage_log'");
$nc_days = array();
$nc_kinds = array();
$nc_top = array();
$nc_totals = array('calls_7' => 0, 'tokens_7' => 0, 'calls_30' => 0, 'tokens_30' => 0, 'errors_30' => 0);
if ($nc_has_log) {
    $rows = (array)$db->get_results(
        "SELECT DATE(`created_at`) AS d, COUNT(*) AS n, SUM(`total_tokens`) AS t, SUM(`ok`=0) AS e "
        . "FROM `ai_usage_log` WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) "
        . "GROUP BY DATE(`created_at`)", ARRAY_A);
    $by_day = array();
    foreach ($rows as $r) {
        $by_day[$r['d']] = $r;
    }
    // «сегодня» берём у MySQL: у PHP и базы могут быть разные часовые пояса,
    // и последний день иначе выпадал бы из столбиков
    $today = (string)$db->get_var("SELECT CURDATE()");
    $today_ts = strtotime($today . ' 12:00:00');
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', $today_ts - $i * 86400);
        $r = isset($by_day[$d]) ? $by_day[$d] : array('n' => 0, 't' => 0, 'e' => 0);
        $nc_days[] = array('d' => $d, 'n' => (int)$r['n'], 't' => (int)$r['t'], 'e' => (int)$r['e']);
        $nc_totals['calls_30'] += (int)$r['n'];
        $nc_totals['tokens_30'] += (int)$r['t'];
        $nc_totals['errors_30'] += (int)$r['e'];
        if ($i <= 6) {
            $nc_totals['calls_7'] += (int)$r['n'];
            $nc_totals['tokens_7'] += (int)$r['t'];
        }
    }
    $nc_kinds = (array)$db->get_results(
        "SELECT `kind`, COUNT(*) AS n, SUM(`total_tokens`) AS t, SUM(`ok`=0) AS e, ROUND(AVG(`latency_ms`)) AS ms "
        . "FROM `ai_usage_log` WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) "
        . "GROUP BY `kind` ORDER BY t DESC", ARRAY_A);
    // только ID: расход не повод открывать, кто о чём говорил
    $nc_top = (array)$db->get_results(
        "SELECT `user_id`, COUNT(*) AS n, SUM(`total_tokens`) AS t FROM `ai_usage_log` "
        . "WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND `user_id` > 0 "
        . "GROUP BY `user_id` ORDER BY t DESC LIMIT 10", ARRAY_A);
}
$nc_max_tokens_day = 1;
foreach ($nc_days as $d) {
    $nc_max_tokens_day = max($nc_max_tokens_day, $d['t']);
}

$nc_kind_titles = array('chat' => 'диалог', 'greet' => 'приветствие', 'summary' => 'резюме',
    'explain' => 'обоснование пары', 'classify' => 'разбор ответа опроса', 'psychotype' => 'психотип', 'other' => 'прочее');

/* Поле формы по спецификации. */
function nc_field($name, $spec, $val) {
    $id = 'f_' . $name;
    echo '<div class="fld"><label for="' . $id . '">' . admin_esc($spec['title']) . '</label>';
    switch ($spec['type']) {
        case 'secret':
            $tail = $val['set'] ? 'сейчас: ' . admin_esc($val['tail']) : 'не задан';
            echo '<input class="in" type="password" id="' . $id . '" name="' . $name . '" autocomplete="new-password" '
                . 'placeholder="' . ($val['set'] ? 'оставить как есть' : 'вставьте ключ') . '">'
                . '<span class="hint" id="' . $id . '_tail">' . $tail . '</span>';
            break;
        case 'enum':
            echo '<select class="in" id="' . $id . '" name="' . $name . '">';
            foreach ($spec['enum'] as $opt) {
                echo '<option value="' . admin_esc($opt) . '"' . ($opt === (string)$val ? ' selected' : '') . '>' . admin_esc($opt) . '</option>';
            }
            echo '</select>';
            break;
        case 'bool':
            echo '<select class="in" id="' . $id . '" name="' . $name . '">'
                . '<option value="0"' . ((string)$val === '0' || $val === 0 ? ' selected' : '') . '>нет</option>'
                . '<option value="1"' . ((string)$val === '1' || $val === 1 ? ' selected' : '') . '>да</option></select>';
            break;
        case 'int':
        case 'float':
            // step="any": иначе браузер сам отвергнет 0.65 ещё до отправки
            echo '<input class="in" type="number" id="' . $id . '" name="' . $name . '" value="' . admin_esc($val) . '" '
                . 'min="' . $spec['min'] . '" max="' . $spec['max'] . '"' . ($spec['type'] === 'float' ? ' step="any"' : '') . '>';
            break;
        default:
            echo '<input class="in" type="text" id="' . $id . '" name="' . $name . '" value="' . admin_esc($val) . '">';
    }
    if (!empty($spec['hint'])) {
        echo '<span class="hint">' . admin_esc($spec['hint']) . '</span>';
    }
    echo '<span class="hint msg" id="' . $id . '_err"></span></div>' . "\n";
}

admin_page_start($uid, 'ИИ и подключение', 'ai',
    'Ключ провайдера, модель, пределы и RAG. Проверка подключения — здесь же, расход — ниже.');
?>

<?php if (!$nc_val['captcha_secret']['set']): ?>
<div class="note">Серверный секрет SmartCaptcha не задан: капча сейчас проверяется только в браузере, и обойти её можно одним запросом. Секрет выдаётся в консоли Яндекс Облака.</div>
<?php endif; ?>
<?php if ($nc_val['provider'] === 'stub'): ?>
<div class="note">Провайдер — заглушка: консультант отвечает шаблоном и денег не тратит. Чтобы подключить модель: выберите openrouter, вставьте ключ, нажмите «Сохранить настройки» — и затем «Проверить подключение» (проверяется сохранённый ключ).</div>
<?php endif; ?>

<form id="aiForm" autocomplete="off">
  <div class="panel">
    <div class="lbl">Модель</div>
    <div class="form-grid u-mt9">
      <?php foreach (array('provider', 'api_key', 'base_url', 'model', 'temperature', 'max_tokens') as $n) { nc_field($n, $nc_spec[$n], $nc_val[$n]); } ?>
    </div>
    <div class="row u-mt14">
      <button type="button" class="btn btn--ghost" id="btnPingProvider">Проверить подключение</button>
      <span class="msg" id="pingProviderMsg" role="status"></span>
    </div>
  </div>

  <div class="panel u-mt14">
    <div class="lbl">Таймауты и пределы</div>
    <div class="form-grid u-mt9">
      <?php foreach (array('timeout', 'stream_timeout', 'first_byte_timeout', 'max_retries', 'rate_limit_per_hour', 'burst_per_minute', 'greet_with_llm') as $n) { nc_field($n, $nc_spec[$n], $nc_val[$n]); } ?>
    </div>
  </div>

  <div class="panel u-mt14">
    <div class="lbl">Книги (RAG)</div>
    <div class="form-grid u-mt9">
      <?php foreach (array('rag_url', 'rag_top_k', 'rag_timeout') as $n) { nc_field($n, $nc_spec[$n], $nc_val[$n]); } ?>
    </div>
    <div class="row u-mt14">
      <button type="button" class="btn btn--ghost" id="btnPingRag">Проверить RAG</button>
      <span class="msg" id="pingRagMsg" role="status"></span>
    </div>
  </div>

  <div class="panel u-mt14">
    <div class="lbl">Капча и безопасность</div>
    <div class="form-grid u-mt9">
      <?php foreach (array('captcha_secret', 'captcha_sitekey', 'captcha_fail_open', 'superadmin_ids') as $n) { nc_field($n, $nc_spec[$n], $nc_val[$n]); } ?>
    </div>
  </div>

  <div class="row u-mt14">
    <button type="submit" class="btn" id="btnSave">Сохранить настройки</button>
    <span class="msg" id="saveMsg" role="status"></span>
  </div>
</form>

<h2>Расход за 30 дней</h2>
<?php if (!$nc_has_log): ?>
<p class="mini">Журнал расхода ещё не создан — примените миграции.</p>
<?php else: ?>
<div class="stat">
  <div class="panel"><div class="lbl">Вызовов за 7 дней</div><div class="stat__n" id="statCalls7"><?= (int)$nc_totals['calls_7'] ?></div></div>
  <div class="panel"><div class="lbl">Токенов за 7 дней</div><div class="stat__n" id="statTokens7"><?= number_format((int)$nc_totals['tokens_7'], 0, '', ' ') ?></div></div>
  <div class="panel"><div class="lbl">Вызовов за 30 дней</div><div class="stat__n" id="statCalls30"><?= (int)$nc_totals['calls_30'] ?></div></div>
  <div class="panel"><div class="lbl">Токенов за 30 дней</div><div class="stat__n" id="statTokens30"><?= number_format((int)$nc_totals['tokens_30'], 0, '', ' ') ?></div></div>
  <div class="panel"><div class="lbl">Неудачных вызовов</div><div class="stat__n" id="statErrors30"><?= (int)$nc_totals['errors_30'] ?></div></div>
</div>

<div class="panel u-mt14">
  <div class="lbl">Токены по дням</div>
  <div class="bars u-mt9" role="img" aria-label="Расход токенов по дням за 30 дней">
    <?php /* высота — классом с шагом 10%: CSP не пускает style=, а десяти
       ступеней для графика расхода достаточно */ ?>
    <?php foreach ($nc_days as $d): $h = $d['t'] > 0 ? max(10, (int)ceil($d['t'] * 10 / $nc_max_tokens_day) * 10) : 0; ?>
    <i class="<?= $d['t'] > 0 ? 'h' . $h : 'is-empty' ?>" title="<?= admin_esc($d['d']) ?>: <?= (int)$d['n'] ?> выз., <?= (int)$d['t'] ?> ток."></i>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid u-mt14">
  <div class="panel">
    <div class="lbl">По видам вызовов</div>
    <div class="table-wrap"><table class="table u-mt9" id="kindsTable">
      <tr><th>Вид</th><th>Вызовов</th><th>Токенов</th><th>Ошибок</th><th>Среднее, мс</th></tr>
      <?php foreach ($nc_kinds as $k): ?>
      <tr><td><?= admin_esc(isset($nc_kind_titles[$k['kind']]) ? $nc_kind_titles[$k['kind']] : $k['kind']) ?></td>
          <td><?= (int)$k['n'] ?></td><td><?= (int)$k['t'] ?></td><td><?= (int)$k['e'] ?></td><td><?= (int)$k['ms'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$nc_kinds): ?><tr><td colspan="5" class="mini">за 30 дней вызовов не было</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="panel">
    <div class="lbl">Самые активные (только ID)</div>
    <div class="table-wrap"><table class="table u-mt9" id="topTable">
      <tr><th>Пользователь</th><th>Вызовов</th><th>Токенов</th></tr>
      <?php foreach ($nc_top as $t): ?>
      <tr><td>ID <?= (int)$t['user_id'] ?></td><td><?= (int)$t['n'] ?></td><td><?= (int)$t['t'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$nc_top): ?><tr><td colspan="3" class="mini">пока пусто</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
<?php endif; ?>

<?= admin_script_open() ?>
(function(){
  var form = document.getElementById('aiForm');
  var saveMsg = document.getElementById('saveMsg');
  var say = shAdmin.say;

  function clearErrors() {
    form.querySelectorAll('.msg[id$="_err"]').forEach(function(el){ el.textContent = ''; });
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var btn = document.getElementById('btnSave');
    btn.disabled = true;
    clearErrors();
    say(saveMsg, 'Сохраняем…', true);
    shAdmin.post('/api/ai_settings_save.php', new URLSearchParams(new FormData(form))).then(function(res){
      btn.disabled = false;
      if (res.js.ok) {
        // смена хоста адреса API сбрасывает ключ — об этом надо сказать прямо
        say(saveMsg, 'Сохранено: ' + res.js.saved.length + ' полей' + (res.js.note ? '. ' + res.js.note : ''), !res.js.key_reset);
        // ключи в форме не держим: очищаем и показываем хвосты
        ['api_key', 'captcha_secret'].forEach(function(n){
          var inp = document.getElementById('f_' + n);
          var tail = document.getElementById('f_' + n + '_tail');
          if (inp) { inp.value = ''; }
          if (tail && res.js[n]) {
            tail.textContent = res.js[n].set ? 'сейчас: ' + res.js[n].tail : 'не задан';
            inp.placeholder = res.js[n].set ? 'оставить как есть' : 'вставьте ключ';
          }
        });
        return;
      }
      say(saveMsg, res.js.error || 'Не удалось сохранить', false);
      if (res.js.fields) {
        Object.keys(res.js.fields).forEach(function(n){
          var el = document.getElementById('f_' + n + '_err');
          if (el) { el.textContent = res.js.fields[n]; el.className = 'hint msg msg--err'; }
        });
      }
    }).catch(function(){ btn.disabled = false; say(saveMsg, 'Сервис недоступен', false); });
  });

  function ping(target, btnId, msgId, render) {
    var btn = document.getElementById(btnId), msg = document.getElementById(msgId);
    btn.addEventListener('click', function(){
      btn.disabled = true;
      say(msg, 'Проверяем…', true);
      var p = new URLSearchParams(); p.append('target', target);
      shAdmin.post('/api/ai_ping.php', p).then(function(res){
        btn.disabled = false;
        if (res.js.ok) { say(msg, render(res.js), true); return; }
        say(msg, res.js.error || 'Проверка не прошла', false);
      }).catch(function(){ btn.disabled = false; say(msg, 'Сервис недоступен', false); });
    });
  }
  ping('provider', 'btnPingProvider', 'pingProviderMsg', function(js){
    return 'Связь есть: ' + js.latency_ms + ' мс, моделей в списке: ' + js.models_count
      + (js.model_known === false ? '. Настроенной модели в списке нет — проверьте её имя' : '');
  });
  ping('rag', 'btnPingRag', 'pingRagMsg', function(js){
    return 'RAG жив: ' + js.latency_ms + ' мс' + (js.chunks !== null ? ', фрагментов: ' + js.chunks : '');
  });
})();
</script>
<?php admin_page_end(); ?>
