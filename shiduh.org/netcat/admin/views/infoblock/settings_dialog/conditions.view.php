<?php

if (!class_exists('nc_core')) {
    die;
}
$condition_json = $infoblock_data['Condition'] ?: '{}';
$condition_groups = nc_array_json(array('GROUP_OBJECTS'));
$site_id = $infoblock_data['Catalogue_ID'];

?>
<link rel='stylesheet' href='<?= nc_add_revision_to_url($ADMIN_PATH . 'skins/default/css/condition_modal.css') ?>' />

<div id='nc_condition_editor'></div>

<div class="nc-margin-top-medium">
    <div><?= CONTROL_CONTENT_SUBCLASS_CONDITION_OFFSET ?>:</div>
    <div>
        <input type="number" name="data[ConditionOffset]" class="nc--small" min="0"
               value="<?= htmlspecialchars($infoblock_data['ConditionOffset']) ?>">
    </div>
</div>

<div>
    <div><?= CONTROL_CONTENT_SUBCLASS_CONDITION_LIMIT ?>:</div>
    <div>
        <input type="number" name="data[ConditionLimit]" class="nc--small" min="0"
               value="<?= htmlspecialchars($infoblock_data['ConditionLimit']) ?>">
    </div>
</div>

<script>

    (function() {
        var dialog = nc.ui.modal_dialog.get_current_dialog();
        dialog.set_on_tab_change('conditions', function() {
            var container = $nc('#nc_condition_editor');

            if (container.data('initialized')) {
                return;
            }

            $nc.when(
                nc.load_script('<?= nc_add_revision_to_url($ADMIN_PATH . 'js/chosen.jquery.min.js') ?>'),
                nc.load_script('<?= nc_add_revision_to_url($ADMIN_PATH . 'condition/js/editor_strings.php')?>'),
                nc.load_script('<?= nc_add_revision_to_url($ADMIN_PATH . 'condition/js/editor.min.js')?>'),
                $nc.Deferred(function (deferred) {
                    $nc(deferred.resolve);
                })
            ).done(function () {
                var condition_editor = new nc_condition_editor({
                    container: container,
                    input_name: 'data[Condition]',
                    conditions: <?= $condition_json ?>,
                    site_id: <?= $site_id ?>,
                    sub_class_id: <?= $infoblock_data['Sub_Class_ID']?> ,
                    groups_to_show: <?= $condition_groups ?>
                });

                container.closest('.ncf_value').removeClass('ncf_value');
                container.closest('form').get(0).onsubmit = function () {
                    return condition_editor.onFormSubmit();
                };
                container.data('initialized', true);
            });
        });
    })();

</script>
