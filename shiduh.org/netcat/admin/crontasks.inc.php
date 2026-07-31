<?php

function CrontasksList() {
    global $db, $UI_CONFIG, $ADMIN_TEMPLATE, $nc_core;

    $Result = $db->get_results("SELECT * FROM `CronTasks` ORDER BY `Cron_ID` ", ARRAY_A);

    if ($db->is_error) {
        throw new nc_Exception_DB_Error($db->last_query, $db->last_error);
    }

    $crontab = nc_get_file($nc_core->ADMIN_FOLDER.'crontab.php');
    $pattern = "/HTTP_HOST[\s]*?=[\s]*?['\"]([0-9a-zA-Zа-яёА-ЯЁ.-]+?)['\"]/";
    if (nc_preg_match($pattern, $crontab, $matches)) {
        $crontab_host = nc_strtolower($matches[1]);
        $host = nc_strtolower($_SERVER['HTTP_HOST']);
        if ($crontab_host != $host) {
            nc_print_status(sprintf(TOOLS_CRON_WRONG_DOMAIN, $crontab_host, $host), ($crontab_host == 'localhost' ? 'error' : 'info'));
        }
    }

    if (($countClassif = $db->num_rows)) {
        echo "
    <form method='post' action='crontasks.php'>

    <table class='nc-table nc--striped' width=100%>
      <tr>
        <th width='3%'>ID</th>
        <th width='14%' class='align-center'>".TOOLS_CRON_INTERVAL."</th>
        <th width='16%'>".TOOLS_CRON_LAUNCHED."</th>
        <th width='45%'>".TOOLS_CRON_SCRIPTURL."</th>
        <th class='nc-text-center'>".TOOLS_CRON_CHANGE."</th>
        <th class='nc-text-center'><i class='nc-icon nc--remove' title='".CONTROL_CONTENT_CATALOUGE_FUNCS_SHOWCATALOGUELIST_DELETE."'></i></th>
      </tr>";



        foreach ($Result as $Array) {

            print "<tr>";
            print "<td >".$Array['Cron_ID']."</td>\n";
            print "<td class='nc-text-center'>{$Array['Cron_Minutes']}:{$Array['Cron_Hours']}:{$Array['Cron_Days']}</a></td>";
            if ($Array['Cron_Launch']) {
                print "<td>".date("H:i d.m.Y", $Array['Cron_Launch'])."</td>";
            } else {
                print "<td>".CONTROL_CONTENT_SUBDIVISION_FUNCS_OBJ_NO."</td>";
            }
            $url = htmlspecialchars($Array['Cron_Script_URL'], ENT_QUOTES);
            print "<td><a href='$url' target=_blank>$url</a></font></td>";
            print "<td class='nc-text-center'><a href=crontasks.php?phase=4&CronID=".$Array['Cron_ID']."><div class='icons icon_settings' title='".TOOLS_REDIRECT_CHANGEINFO."'></div></a></td>";
            print "<td class='nc-text-center'>".nc_admin_checkbox_simple("Delete".$Array['Cron_ID'], $Array['Cron_ID'])."</td>";
            print "</tr>";
        }

        echo "</table></td></tr></table><br/>";



        $UI_CONFIG->actionButtons[] = array(
            "id" => "submit",
            "caption" => NETCAT_ADMIN_DELETE_SELECTED,
            "action" => "mainView.submitIframeForm()",
            "align" => "right",
            "red_border" => true,
        );
    } else {
        nc_print_status(TOOLS_CRON_NOTASKS, 'info');
    }

    $UI_CONFIG->actionButtons[] = array(
            "id" => "add",
            "caption" => TOOLS_CRON_ADDLINK,
            "location" => "cron.add",
            "align" => "left");

    if ($countClassif) {
        echo "<input type='hidden' name='phase' value='3'>";
        echo "<input type='submit' class='hidden'>";
        echo $nc_core->token->get_input();
        echo"</form>";
    }
}

###############################################################################

function CronForm($CronID) {
    global $db, $Cron_Minutes, $Cron_Hours, $Cron_Days, $Cron_Script_URL;
    global $UI_CONFIG, $nc_core;

    if ($CronID) {
        $rs = $db->get_row("SELECT * FROM CronTasks WHERE Cron_ID='" . intval($CronID) . "' LIMIT 1", ARRAY_A);
    }
    else {
        $rs = array();
    }

    ?>
    <form method="post" action="crontasks.php">

    <font color="gray">
    <?= TOOLS_CRON_MINUTES ?>:
    &nbsp;<?= nc_admin_input_simple('Cron_Minutes', nc_array_value($rs, 'Cron_Minutes'), 3, '', '') ?>&nbsp;&nbsp;
    <?= TOOLS_CRON_HOURS ?>:
    &nbsp;<?= nc_admin_input_simple('Cron_Hours', nc_array_value($rs, 'Cron_Hours'), 3, '', '') ?>&nbsp;&nbsp;
    <?= TOOLS_CRON_DAYS ?>:
    &nbsp;<?= nc_admin_input_simple('Cron_Days', nc_array_value($rs, 'Cron_Days'), 3, '', '') ?>
    <br><br>
    <?= TOOLS_CRON_SCRIPTURL ?>:
    <br><?= nc_admin_input_simple('Cron_Script_URL', $rs['Cron_Script_URL'], 70, '', "maxlength='255'") ?>

    <?
    if (!$CronID) {
        print '<input type="hidden" name="phase" value="2">';
        $UI_CONFIG->actionButtons[] = array("id" => "submit",
            "caption" => TOOLS_REDIRECT_ADDONLY,
            "action" => "mainView.submitIframeForm()"
        );
    }
    else {
        ?>
            <input type="hidden" name="CronID" value="<?= $CronID ?>">
            <input type="hidden" name="phase" value="5">
            <input type="submit" class="hidden">
        <?
        $UI_CONFIG->actionButtons[] = array("id" => "submit",
            "caption" => CONTROL_CONTENT_CATALOUGE_FUNCS_CATALOGUEFORM_SAVE,
            "action" => "mainView.submitIframeForm()"
        );
    }
    print $nc_core->token->get_input();
    print "</form>";
}

###############################################################################

function IsCrontasksExist($OldURL) {
    global $db;
    $OldURL = $db->escape($OldURL);
    $Select = "select Cron_ID from CronTasks where Cron_Script_URL='".$OldURL."'";

    $Result = $db->get_results($Select);
    if ($db->num_rows > 0) return 1; else return 0;
}

###############################################################################

function CronCompleted($CronID, $Cron_Minutes, $Cron_Hours, $Cron_Days, $Cron_Script_URL) {
    global $db;

    $Cron_Minutes = intval($Cron_Minutes);
    $Cron_Hours = intval($Cron_Hours);
    $Cron_Days = intval($Cron_Days);
    $Cron_Script_URL = $db->escape($Cron_Script_URL);

    if (($Cron_Minutes == "" and $Cron_Hours == "" and $Cron_Days == "") or ($Cron_Script_URL == "")) {
        nc_print_status(TOOLS_REDIRECT_CANTBEEMPTY, 'error');
        CronForm($CronID);
    } elseif (!$CronID) {
        $Insert = "insert into CronTasks (Cron_Minutes, Cron_Hours, Cron_Days, Cron_Script_URL) values ('$Cron_Minutes', '$Cron_Hours', '$Cron_Days', '$Cron_Script_URL')";
        $Result = $db->query($Insert);
    } else {
        $Insert = "update CronTasks set Cron_Minutes='".$Cron_Minutes."', Cron_Hours='".$Cron_Hours."', Cron_Days='".$Cron_Days."', Cron_Script_URL='".$Cron_Script_URL."' WHERE Cron_ID='".$CronID."'";
        $Result = $db->query($Insert);
    }

    return ($Result);
}

###############################################################################

function DeleteCron($CronID) {
    global $db;
    return $db->query("delete from CronTasks where Cron_ID='".intval($CronID)."'");
}
