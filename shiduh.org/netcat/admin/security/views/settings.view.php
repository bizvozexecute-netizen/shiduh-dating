<?php

if (!class_exists('nc_core')) { die; }

/** @var nc_ui $ui */

// COMMON
/** @var int $site_id */
/** @var string $default_settings_link */

// FILTER
/** @var bool $site_has_own_filter_settings */
/** @var array $filter_configuration_errors */

// CAPTCHA
/** @var bool $site_has_own_captcha_settings */
/** @var string $captcha_mode */
/** @var int $captcha_free_attempts */

$nc_core = nc_core::get_object();

$get_setting = function($setting) use ($site_id) {
    return nc_core::get_object()->get_settings($setting, 'system', false, $site_id);
};

$filter_radio_cell = function($input_name, $input_value) use ($get_setting) {
    $current_value = $get_setting($input_name);
    return '<td class="nc-text-center">' .
           '<input type="radio" name="filter_settings[' . $input_name . ']"' .
           ' value="' . $input_value .'"' .
           ($input_value == $current_value ? ' checked' : '') .
           ' class="nc--wide"></td>';
};

$filter_row = function($mode) use ($filter_radio_cell) {
    return $filter_radio_cell('SecurityInputFilterSQL', $mode) .
           $filter_radio_cell('SecurityInputFilterPHP', $mode) .
           $filter_radio_cell('SecurityInputFilterXSS', $mode);
};

?>

<?= $ui->controls->site_select($site_id, true) ?>

<? if ($saved): ?>
    <?= $ui->alert->success(NETCAT_SECURITY_SETTINGS_SAVED) ?>
<? endif; ?>

<form method="post">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="site_id" value="<?= $site_id ?>">

    <!-- INPUT FILTERS -->

    <h3><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER ?></h3>

    <? if ($site_id): ?>
        <div class="nc-margin-vertical-small">
            <label>
                <input type="checkbox" name="filters_use_default_settings" value="1"
                        <?= $site_has_own_filter_settings ? '' : ' checked' ?>
                        id="nc_security_filter_use_default">
                <?= sprintf(NETCAT_SECURITY_SETTINGS_USE_DEFAULT, $default_settings_link) ?>
            </label>
        </div>
    <? endif; ?>

    <div id="nc_security_filter_site_settings" <?= (!$site_id || $site_has_own_filter_settings ? '' : ' style="display: none"') ?>>

        <? if (!empty($filter_configuration_errors)): ?>
            <?= $ui->alert->error(implode('<br>', $filter_configuration_errors)) ?>
        <? endif; ?>

        <table class="nc-table">
            <tr>
                <th><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE ?></th>
                <th width="15%" class="nc-text-center">SQL</th>
                <th width="15%" class="nc-text-center">PHP</th>
                <th width="15%" class="nc-text-center">HTML (XSS)</th>
            </tr>
            <tr>
                <td><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE_DISABLED ?></td>
                <?= $filter_row(nc_security_filter::MODE_DISABLED) ?>
            </tr>
            <tr>
                <td><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE_LOG_ONLY ?></td>
                <?= $filter_row(nc_security_filter::MODE_LOG_ONLY) ?>
            </tr>
            <tr>
                <td><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE_RELOAD_ESCAPE_INPUT ?></td>
                <?= $filter_row(nc_security_filter::MODE_RELOAD_ESCAPE_INPUT) ?>
            </tr>
            <tr>
                <td><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE_RELOAD_REMOVE_INPUT ?></td>
                <?= $filter_row(nc_security_filter::MODE_RELOAD_REMOVE_INPUT) ?>
            </tr>
            <tr>
                <td><?= NETCAT_SECURITY_SETTINGS_INPUT_FILTER_MODE_EXCEPTION ?></td>
                <?= $filter_row(nc_security_filter::MODE_EXCEPTION) ?>
            </tr>
        </table>

        <div class="nc-form-checkbox-block nc-margin-top-medium">
            <input type="hidden" name="filter_settings[SecurityFilterEmailAlertEnabled]" value="0">
            <label>
                <input type="checkbox" name="filter_settings[SecurityFilterEmailAlertEnabled]"
                   <?= ($get_setting('SecurityFilterEmailAlertEnabled') ? 'checked' : '') ?>
                   value="1">
                <?= NETCAT_SECURITY_FILTER_EMAIL_ENABLED ?>
            </label>
            <div id="nc_security_email_alert">
                <input type="text" name="filter_settings[SecurityFilterEmailAlertAddress]"
                   value="<?= htmlspecialchars($get_setting('SecurityFilterEmailAlertAddress')) ?>"
                   placeholder="<?= htmlspecialchars(
                       $get_setting('SpamFromEmail') ?: NETCAT_SECURITY_FILTER_EMAIL_PLACEHOLDER
                   ) ?>"
                   size="50">
            </div>
        </div>

    </div>

    <!-- + <script> ниже! -->

    <!-- AUTH CAPTCHA -->

    <div class="nc-margin-top-medium"></div>
    <h3><?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA ?></h3>

    <? if ($site_id): ?>
        <div class="nc-margin-vertical-small">
            <label>
                <input type="checkbox" name="captcha_use_default_settings" value="1"
                        <?= $site_has_own_captcha_settings ? '' : ' checked' ?>
                        id="nc_security_captcha_use_default">
                <?= sprintf(NETCAT_SECURITY_SETTINGS_USE_DEFAULT, $default_settings_link) ?>
                <?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA_RECOMMEND_DEFAULT ?>
            </label>
        </div>
    <? endif; ?>

    <div id="nc_security_captcha_site_settings"<?= (!$site_id || $site_has_own_captcha_settings ? '' : ' style="display: none"') ?>>
        <?
        $captcha_radio = function($radio_value) use ($ui, $captcha_mode) {
            return $ui->html->input('radio', 'captcha_mode', $radio_value)->checked($radio_value === $captcha_mode);
        };
        ?>

        <div>
            <label><?= $captcha_radio('disabled') ?> <?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA_MODE_DISABLED ?></label>
        </div>
        <div>
            <label><?= $captcha_radio('always') ?> <?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA_MODE_ALWAYS ?></label>
        </div>
        <div class="nc-form-checkbox-block">
            <label><?= $captcha_radio('count') ?> <?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA_MODE_COUNT ?></label>
            <div id="nc_security_captcha_mode_count_attempts"<?= ($captcha_mode === 'count' ? '' : ' style="display: none"') ?>>
                <label>
                    <?= NETCAT_SECURITY_SETTINGS_AUTH_CAPTCHA_ATTEMPTS ?>:
                    <input type="number" min="1" name="captcha_free_attempts" class="nc--small"
                            value="<?= $captcha_free_attempts > 0 ? $captcha_free_attempts : 1 ?>">
                </label>
            </div>
        </div>
    </div>

    <script>
    (function() {
        function toggle(el, on) {
            on ? $nc(el).slideDown() : $nc(el).slideUp();
        }

        $nc('#nc_security_filter_use_default').change(function() {
            toggle('#nc_security_filter_site_settings', !$nc(this).prop('checked'));
        });

        $nc('#nc_security_captcha_use_default').change(function() {
            toggle('#nc_security_captcha_site_settings', !$nc(this).prop('checked'));
        });

        $nc(':radio[name=captcha_mode]').change(function() {
            toggle('#nc_security_captcha_mode_count_attempts', $nc(this).val() === 'count');
        });
    })();
    </script>

</form>