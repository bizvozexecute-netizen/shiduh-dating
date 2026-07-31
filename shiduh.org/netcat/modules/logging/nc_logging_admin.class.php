<?php

/* $Id: nc_logging_admin.class.php 7681 2012-07-16 15:09:22Z ewind $ */

class nc_logging_admin {

    protected $db;
    protected $MODULE_VARS;
    protected $UI_CONFIG;

    public function __construct() {
        // system superior object
        $nc_core = nc_Core::get_object();

        // global variables
        global $UI_CONFIG;

        // global variables to internal
        $this->UI_CONFIG = $UI_CONFIG;

        // system db object
        if (is_object($nc_core->db)) $this->db = $nc_core->db;

        $this->MODULE_VARS = &$nc_core->modules->get_vars("logging");
    }

    /**
     *
     */
    public function show_logging_data($curPos = 0, $show_filter=true) {
        // system superior object
        $nc_core = nc_Core::get_object();

        // validate
        $curPos+= 0;
        $sort = $nc_core->input->fetch_get('sort');
        $sortDir = $nc_core->input->fetch_get('sortDir');
        $sortViewType = $nc_core->input->fetch_get('sortViewType');
        $sortViewEssence = $nc_core->input->fetch_get('sortViewEssence');

        $current_url = $nc_core->url->get_parsed_url("path");

        $maxRows = $this->MODULE_VARS['ROWS_PER_PAGE'] ? $this->MODULE_VARS['ROWS_PER_PAGE'] : 25;
        $range = $this->MODULE_VARS['PAGES_NUMBER'] ? $this->MODULE_VARS['PAGES_NUMBER'] : 10;

        $allowedActions = array("add", "update", "drop", "check", "uncheck", "authorize");
        $allowedEssences = array("Catalogue", "Subdivision", "SubClass", "Class", "ClassTemplate", "Message", "SystemTable", "Template", "User", "Module", "Comment");

        if ($show_filter)
        {
            echo "<form method='get' id='SortData' action='".$current_url."' style='padding:0; margin:0;'>\n".
            "<input type='hidden' name='phase' value='1'>\n".
            "<br /><fieldset>\n".
            "<legend>\n".
            "".NETCAT_MODULE_LOGGING_SHOW_PARAMETERS."\n".
            "</legend>\n".
            "<div style='margin:10px 0; _padding:0;'>\n";

            echo "<table class='nc-table nc--small' width='100%'>".
            "<col style='width:30%' /><col style='width:70%' />".
            "<tr>".
            "<td nowrap>".NETCAT_MODULE_LOGGING_SHOW_SORT_BY."</td>".
            "<td>".
            "<select name='sort' style='width:100%'>
            <option value='date'".($sort == "date" || !$sort ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_SORT_BY_DATE."</option>
            <option value='event'".($sort == "event" ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_SORT_BY_EVENT."</option>
            <option value='user'".($sort == "user" ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_SORT_BY_USER."</option>
            </select>".
            "</td>".
            "</tr>".
            "<tr>".
            "<td nowrap>".NETCAT_MODULE_LOGGING_SHOW_SORT_DIR."</td>".
            "<td >".
            "<select name='sortDir' style='width:100%'>
            <option value='desc'".($sortDir == "desc" || !$sortDir ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_SORT_DIR_DESC."</option>
            <option value='asc'".($sortDir == "asc" ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_SORT_DIR_ASC."</option>
            </select>".
            "</td>".
            "</tr>".
            "<tr>".
            "<td nowrap>".NETCAT_MODULE_LOGGING_SHOW_ONLY_ACTION."</td>".
            "<td>".
            "<select name='sortViewType' style='width:100%'>
            <option value='0'".(!$sortViewType ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_ONLY_ACTION_NONE."</option>";
            foreach ($allowedActions as $row) {
                echo "<option value='".$row."'".($sortViewType == $row ? " selected" : "").">".constant("NETCAT_MODULE_LOGGING_EVENT_TYPE_".strtoupper($row))."</option>";
            }
            echo "</select>".
            "</td>".
            "</tr>".
            "<tr>".
            "<td nowrap>".NETCAT_MODULE_LOGGING_SHOW_ONLY_ESSENCE."</td>".
            "<td>".
            "<select name='sortViewEssence' style='width:100%'>
            <option value='0'".(!$sortViewEssence ? " selected" : "").">".NETCAT_MODULE_LOGGING_SHOW_ONLY_ESSENCE_NONE."</option>";
            foreach ($allowedEssences as $row) {
                echo "<option value='".$row."'".($sortViewEssence == $row ? " selected" : "").">".constant("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_".strtoupper($row))."</option>";
            }
            echo "</select>".
            "</td>".
            "</tr>".
            "</table>";

            echo "</div>\n".
            "</fieldset>\n".
            "</form>\n";
        }


        switch ($sort) {
            case "event":
                $sort_field = "l.`Event`";
                break;
            case "user":
                $sort_field = "u.`Email`";
                break;
            default:
                $sort_field = "l.`Date`";
        }

        switch ($sortDir) {
            case "asc":
                $sort_dir_field = "ASC";
                break;
            default:
                $sort_dir_field = "DESC";
        }

        $query_where_arr = array();

        if ($sortViewType && in_array($sortViewType, $allowedActions)) {
            $query_where_arr[] = "l.`Event` REGEXP '^".$sortViewType."'";
        }

        if ($sortViewEssence && in_array($sortViewEssence, $allowedEssences)) {
            $query_where_arr[] = "l.`Event` REGEXP '^(add|update|drop|check|uncheck|authorize)".$sortViewEssence."$'";
        }

        $data = $this->db->get_results("SELECT SQL_CALC_FOUND_ROWS l.*, u.`".$nc_core->AUTHORIZE_BY."` AS Login
      FROM `Logging` AS l
      LEFT JOIN `User` AS u ON l.`User_ID` = u.`User_ID`
      ".(!empty($query_where_arr) ? "WHERE ".join(" AND ", $query_where_arr) : "")."
      ORDER BY ".$sort_field." ".$sort_dir_field.", l.`ID` ".$sort_dir_field."
      LIMIT ".intval($curPos).", ".intval($maxRows)."", ARRAY_A);

        if (!empty($data)) {

            echo "<table class='nc-table'>
      <col width='3%'/><col/><col width='60%'/><col width='8%'/><col width='8%'/>
        <tr>
          <th nowrap class='nc-text-center'>".NETCAT_MODULE_LOGGING_DATA_ID."</th>
          <th nowrap>".NETCAT_MODULE_LOGGING_DATA_EVENT."</th>
          <th nowrap>".NETCAT_MODULE_LOGGING_DATA_INFO."</th>
          <th nowrap>".NETCAT_MODULE_LOGGING_DATA_DATE."</th>
          <th nowrap class='nc-text-center'>".NETCAT_MODULE_LOGGING_DATA_USER."</th>
        </tr>";

            $totRows = $this->db->get_var("SELECT FOUND_ROWS()");

            foreach ($data as $row):
                // determine event name
                preg_match("/^(add|update|drop|check|uncheck|authorize)/is", $row['Event'], $matches);

                // default color
                $color = "#FFFFFF";

                // set colors
                if (!empty($matches) && isset($matches[1])) {
                    switch ($matches[1]) {
                        case "add":
                            $color = "nc--green";
                            $tcolor = "nc-text-green";
                            break;
                        case "update":
                        case "check":
                        case "uncheck":
                            $color = "nc--yellow";
                            $tcolor = "nc-text-yellow";
                            break;
                        case "drop":
                            $color = "nc--red";
                            $tcolor = "nc-text-red";
                            break;
                        case "authorize":
                            $color = "nc--blue";
                            $tcolor = "nc-text-blue";
                            break;
                    }
                }

                // row element
                echo "<tr>".
                "<td class='nc-text-center ".$color."'>".$row['ID']."</td>".
                "<td nowrap>".$nc_core->event->event_name($row['Event'])."</td>".
                "<td >".$row['Info']."</td>".
                "<td nowrap>".$row['Date']."</td>".
                "<td class='nc-text-center'>".($row['User_ID'] ? "<a href='".$nc_core->ADMIN_PATH."user/index.php?phase=4&UserID=".$row['User_ID']."'>".$row['Login']."</a>" : NETCAT_MODULE_LOGGING_DATA_SYSTEM)."</td>".
                "</tr>";

            endforeach;

            echo "</table>";
        }



        $env['maxRows'] = $maxRows;
        $env['totRows'] = $totRows;
        $env['curPos'] = $curPos;
        $env['LocalQuery'] = $current_url;

        // DEPRECATED
        global $browse_msg;

        $browse_msg['prefix'] = "";
        $browse_msg['suffix'] = "";
        $browse_msg['active'] = "<b>%PAGE</b>";
        $browse_msg['unactive'] = "<a href=%URL>%PAGE</a>";
        $browse_msg['divider'] = " | ";

        $last_pos = ( ceil($totRows / $maxRows) * $maxRows - $maxRows );

        require_once($nc_core->INCLUDE_FOLDER."s_class.inc.php");
        // pagination data
        $pagination = browse_messages($env, $range);

        if ($pagination) {
            // pagination prefix
            echo "<div style='margin:10px 0'>";

            // first link
            if ($curPos) {
                echo "<a href='".$env['LocalQuery'].(strstr($env['LocalQuery'], "?") ? "&" : "?")."curPos=0'>&laquo;&laquo;</a>&nbsp;&nbsp;";
            }

            // pagination
            echo $pagination;

            // last link
            if ($curPos != $last_pos && ( ($range + 1) * $maxRows ) < $totRows) {
                echo "&nbsp;&nbsp;<a href='".$env['LocalQuery'].(strstr($env['LocalQuery'], "?") ? "&" : "?")."curPos=".$last_pos."'>&raquo;&raquo;</a>";
            }

            // pagination suffix
            echo "</div>";
        }

        // clear form
        echo "<form method='post' id='ClearData' action='".$current_url."' style='padding:0; margin:0;'>\n".
        "<input type='hidden' name='phase' value='2'>\n".
        "</form>\n";

        // admin buttons
        $this->UI_CONFIG->actionButtons[] = array(
            "id" => "submit",
            "align" => "left",
            "caption" => NETCAT_MODULE_LOGGING_BUTTON_CLEAR,
            "action" => "mainView.submitIframeForm('ClearData')",
            "red_border" => true,
        );

        // admin buttons
        $this->UI_CONFIG->actionButtons[] = array(
                "id" => "submit",
                "caption" => NETCAT_MODULE_LOGGING_BUTTON_SORT,
                "action" => "mainView.submitIframeForm('SortData')"
        );
    }

    /**
     *
     */
    public function clear_logging_dialog() {
        // system superior object
        $nc_core = nc_Core::get_object();

        $current_url = $nc_core->url->get_parsed_url("path");

        // clear notice
        nc_print_status(NETCAT_MODULE_LOGGING_NOTICE_CLEAR, "info");

        // clear form
        echo "<form method='post' id='CancelForm' action='".$current_url."' style='padding:0; margin:0;'>\n".
        "<input type='hidden' name='phase' value='1'>\n".
        "</form>\n";

        // clear form
        echo "<form method='post' id='ClearForm' action='".$current_url."' style='padding:0; margin:0;'>\n".
        "<input type='hidden' name='phase' value='3'>\n".
        "</form>\n";

        // admin buttons
        $this->UI_CONFIG->actionButtons[] = array(
            "id" => "submit",
            "align" => "left",
            "caption" => NETCAT_MODULE_LOGGING_BUTTON_CANCEL,
            "action" => "mainView.submitIframeForm('CancelForm')",
        );

        // admin buttons
        $this->UI_CONFIG->actionButtons[] = array(
            "id" => "submit",
            "caption" => NETCAT_MODULE_LOGGING_BUTTON_CLEAR,
            "action" => "mainView.submitIframeForm('ClearForm')",
            "red_border" => true,
        );
    }

    /**
     *
     */
    public function clear_logging_data() {
        // clear logging data
        $this->db->query("TRUNCATE TABLE `Logging`");
    }

}
?>
