<?php
/*
 * A7: форма правки анкеты (своя — /my/modify/, кандидата шадханом —
 * /my/edit-anket/) мастером из семи шагов. Поля общие с формой добавления
 * (anketa_form.inc.php), проверка — class/204/EditCond.html; partial=1 —
 * ядро пишет только присланные поля.
 *
 * Подключается из class/204/206/RecordTemplate.html (страница кабинета:
 * инфоблоки /my/modify/ и /my/edit-anket/ — зеркала инфоблока 22, поэтому
 * форма шлёт cc=22) и из EditTemplate.html шаблона 206 и корня 204: после
 * отказа EditCond ядро рисует именно EditTemplate — того шаблона, что
 * назван в nc_ctpl, иначе корневого. Ожидает $nc_af_ctpl_id (0 — не слать
 * nc_ctpl). data-sh-native — см. 209/AddTemplate.
 */
$nc_af_ctpl_id = isset($nc_af_ctpl_id) ? (int)$nc_af_ctpl_id : 0;
$checkedLink = isset($checkedLink) ? $checkedLink : '';
$warnText = isset($warnText) ? $warnText : '';
require_once dirname(__FILE__) . '/anketa_validate_lib.php';
require_once dirname(__FILE__) . '/lk_lib.php';
$nc_af_mode = 'edit';
$nc_sh_open_step = isset($nc_sh_open_step) ? (int)$nc_sh_open_step : 0;
// после отказа сервера ядро не перечитывает строку — id берём из запроса
$nc_af_msg = !empty($f_Message_ID) ? (int)$f_Message_ID : (int)$message;
// анкета, заведённая при регистрации, почти пуста — подсказываем, чего не хватает
$nc_af_missing = array();
foreach (anketa_required_fields() as $nc_af_n => $nc_af_l) {
    if ($nc_af_n === 'Date_of_birth') {
        $nc_af_v = (int)$f_Date_of_birth_year > 0 ? 'x' : '';
    } else {
        $nc_af_v = isset(${'f_' . $nc_af_n}) ? ${'f_' . $nc_af_n} : '';
    }
    if (is_array($nc_af_v) ? !count($nc_af_v) : (trim((string)$nc_af_v) === '' || (string)$nc_af_v === '0')) {
        $nc_af_missing[] = $nc_af_l;
    }
}
?>
<div class="tpl-block-change-form tpl-component-netcat-module-auth-user" id="nc-block-c28cd920d139d1087582717014a2931e">
  <?php if ($nc_af_missing && !$warnText): ?>
  <div class="sh-alert sh-alert--warn" role="status">Чтобы анкету видели шадханы, заполните: <?= anketa_v(implode(', ', $nc_af_missing)) ?>.</div>
  <?php endif; ?>
  <?= $warnText ? "<div class='warnText' role='alert'>$warnText</div>" : '' ?>
  <form class="form form--lk-profile-candidate tpl-module-auth-registration-form sh-wizard" name="adminForm" id="adminForm" data-sh-wizard data-sh-native data-sh-draft="sh:draft:edit:<?= $nc_af_msg ?>:<?= (int)$current_user['User_ID'] ?>" data-sh-draft-skip="<?= ANKETA_DRAFT_SKIP ?>" data-sh-open-step="<?= $nc_sh_open_step ?>" enctype="multipart/form-data" method="post" action='<?= $SUB_FOLDER ?><?= $HTTP_ROOT_PATH ?>message.php'>
    <div id='nc_moderate_form'>
    <div class='nc_clear'></div>
    <input name='admin_mode' type='hidden' value='<?= (int)$admin_mode ?>' />
    <?= $nc_core->token->get_input() ?>
    <input name='catalogue' type='hidden' value='<?= (int)$catalogue ?>' />
    <input name='cc' type='hidden' value='<?= (int)$cc ?>' />
    <input name='sub' type='hidden' value='<?= (int)$sub ?>' /><input name='message' type='hidden' value='<?= $nc_af_msg ?>' />
    <input name='posting' type='hidden' value='1' />
    <input name='curPos' type='hidden' value='<?= (int)$curPos ?>' />
    <input name='f_Parent_Message_ID' type='hidden' value='<?= (int)$f_Parent_Message_ID ?>' />
    <?= nc_form_moderate('change', $admin_mode, 0, $systemTableID, $current_cc, (isset($f_Checked) ? $f_Checked  : null), $f_Priority , $f_Keyword, $f_ncTitle, $f_ncKeywords, $f_ncDescription ) ?>
    <input type="hidden" name="partial" value="1" />
    <?php if ($nc_af_ctpl_id > 0): ?><input type="hidden" name="nc_ctpl" value="<?= $nc_af_ctpl_id ?>" /><?php endif; ?>
    </div>
    <?php require $nc_core->DOCUMENT_ROOT . '/api/anketa_form.inc.php'; ?>
    <div class="form__footer sh-wizard__footer">
      <button class="form__submit button button--fill" type="submit">Сохранить изменения</button>
      <?php if ($f_User_ID === $current_user['User_ID'] || $f_Shadhan_1 === $current_user['User_ID'] || $f_Shadhan_2 === $current_user['User_ID'] || $f_Shadhan_3 === $current_user['User_ID']): ?>
      <a class="form__reset lk-questionnaire__goback-link" href="<?= $checkedLink ?>"><?= $f_Checked == 1 ? 'выключить' : 'включить' ?></a>
      <?php endif; ?>
    </div>
  </form>
  <?php
  // Шадханы анкеты (и сам шадхан, если правит чужую) — через lk_user_brief:
  // раньше по три get_by_id на каждого.
  $nc_af_sh = array();
  if ($current_user['Type_id'] === 2 && (int)$f_User_ID) { $nc_af_sh[] = (int)$f_User_ID; }
  foreach (array($f_Shadhan_1, $f_Shadhan_2, $f_Shadhan_3) as $nc_af_id) {
      if ((int)$nc_af_id && !in_array((int)$nc_af_id, $nc_af_sh, true)) { $nc_af_sh[] = (int)$nc_af_id; }
  }
  ?>
  <?php if ($nc_af_sh): ?>
  <section class="form__body-section" id="profile_shadhans">
    <div class="form__body-container">
      <h2 class="form__body-title">Шадханы</h2>
      <ul class="shadhans lk-questionnaire__shadhans">
        <?php foreach ($nc_af_sh as $nc_af_id): $nc_c_sh = lk_user_brief($nc_af_id); if (!$nc_c_sh) { continue; } ?>
        <li><a class="shadhans__item" href="/my/shadhany/shadhan_<?= $nc_af_id ?>.html">
          <div class="shadhans__item-avatar"><img class="shadhans__item-avatar-img" src="<?= lk_esc($nc_c_sh['avatar']) ?>" alt="" width="48" height="48" loading="lazy"></div>
          <div class="shadhans__item-body"><div class="shadhans__item-name"><?= lk_esc($nc_c_sh['name']) ?></div></div>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <?php endif; ?>
  <?php $f_delete_true = nc_object_path(204, $nc_af_msg, 'drop', 'html', false, array('nc_token' => $nc_core->token->get())); ?>
  <?= nc_objects_list( 28, 29, "&nc_ctpl=254&IDCandidate=$nc_af_msg&FIOCandidate=" . rawurlencode(html_entity_decode((string)$f_Name, ENT_QUOTES, 'UTF-8')) . "&dropLink=" . $f_delete_true ); ?>
</div>
<script src="/js/anketa-wizard.js?v=<?= filemtime($nc_core->DOCUMENT_ROOT . '/js/anketa-wizard.js') ?>" defer></script>
