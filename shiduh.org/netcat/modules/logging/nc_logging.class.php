<?php

class nc_logging {

    protected $db;
    protected $ADMIN_PATH;
    protected $MODULE_VARS;

    protected function __construct() {
        // system superior object
        $nc_core = nc_Core::get_object();

        $this->MODULE_VARS = $nc_core->modules->get_vars("logging");

        if ($this->MODULE_VARS['ACTIVITY']) {
            $this->db = $nc_core->db;
            $nc_core->event->set_logger($this);
        }
    }

    /**
     * Get or instance self object
     * @static
     * @access public
     *
     * @return nc_logging self object
     */
    public static function get_object() {
        // call as static
        static $storage;
        // check inited object
        if (!isset($storage)) {
            // init object
            $storage = new self();
        }
        // return object
        return is_object($storage) ? $storage : false;
    }

    /**
     *
     */
    public function logging_event($event, $args) {
        // DEPRECATED
        global $AUTH_USER_ID;

        $args_str = is_array($args) ? serialize($args) : '';

        $info = '';
        preg_match('/^(add|update|drop|check|uncheck|authorize|edit)(.+)$/is', $event, $matches);
        if (!empty($matches) && $matches[1] && $matches[2]) {
            $method = $matches[2] . 'Info';
            if (method_exists($this, $method)) {
                $info_data = $this->$method($matches[1], $args);

                if (!empty($info_data)) {
                    // process logging info
                    $info = call_user_func_array('sprintf', $info_data);
                }
            }
        }

        $this->db->query(
            "INSERT INTO `Logging`
                SET `Event` = '" . $this->db->escape($event) . "',
                    `User_ID` = " . (int)$AUTH_USER_ID . ",
                    `Args` = '" . $this->db->escape($args_str) . "',
                    `Info` = '" . $this->db->escape($info) . "'"
        );
    }

    /**
     *
     */
    public function CatalogueInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Catalogue_ID) = $args;

        if ($action != "drop" && !is_array($Catalogue_ID)) {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', `Catalogue_ID`),
          CONCAT(`Catalogue_ID`, '. ', `Catalogue_Name`)
        FROM `Catalogue`
        WHERE `Catalogue_ID` = '".intval($Catalogue_ID)."'", ARRAY_N);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."catalogue/index.php",
                    "#".(is_array($Catalogue_ID) ? join(",", $Catalogue_ID) : $Catalogue_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_CATALOGUE);

        return $result;
    }

    /**
     *
     */
    public function SubdivisionInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Catalogue_ID, $Subdivision_ID) = $args;

        if ($action != "drop" && !is_array($Subdivision_ID)) {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
          CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
          CONCAT('".$nc_core->ADMIN_PATH."subdivision/index.php?phase=4&SubdivisionID=', s.`Subdivision_ID`),
          CONCAT(s.`Subdivision_ID`, '. ', s.`Subdivision_Name`)
        FROM `Subdivision` AS s
        LEFT JOIN `Catalogue` AS c ON s.`Catalogue_ID` = c.`Catalogue_ID`
        WHERE s.`Subdivision_ID` = '".intval($Subdivision_ID)."'", ARRAY_N);
        } else {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', `Catalogue_ID`),
          CONCAT(`Catalogue_ID`, '. ', `Catalogue_Name`)
        FROM `Catalogue`
        WHERE `Catalogue_ID` = '".intval($Catalogue_ID)."'", ARRAY_N);
            // format
            $result[3] = $nc_core->ADMIN_PATH."subdivision/full.php?CatalogueID=".$Catalogue_ID;
            $result[4] = is_array($Subdivision_ID) ? "#".join(",", $Subdivision_ID) : "#".$Subdivision_ID;
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_SUBDIVISION);

        return $result;
    }

    /**
     *
     */
    public function SubClassInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Catalogue_ID, $Subdivision_ID, $Sub_Class_ID) = $args;

        if ($Subdivision_ID) {
            $constant = NETCAT_MODULE_LOGGING_SUBCLASS;
        } else {
            $constant = NETCAT_MODULE_LOGGING_AREA_INFOBLOCK;
        }

        $result = null;

        if ($action !== 'drop' && !is_array($Sub_Class_ID) && $Subdivision_ID) {
            $result = $this->db->get_row(
                "SELECT
                  CONCAT('{$nc_core->ADMIN_PATH}catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
                  CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
                  CONCAT('{$nc_core->ADMIN_PATH}subdivision/index.php?phase=4&SubdivisionID=', s.`Subdivision_ID`),
                  CONCAT(s.`Subdivision_ID`, '. ', s.`Subdivision_Name`),
                  CONCAT('{$nc_core->HTTP_ROOT_PATH}?inside_admin=1&cc=', sc.`Sub_Class_ID`),
                  CONCAT(sc.`Sub_Class_ID`, '. ', sc.`Sub_Class_Name`)
                FROM `Sub_Class` AS sc
                LEFT JOIN `Subdivision` AS s ON sc.`Subdivision_ID` = s.`Subdivision_ID`
                LEFT JOIN `Catalogue` AS c ON sc.`Catalogue_ID` = c.`Catalogue_ID`
                WHERE sc.`Sub_Class_ID` = '" . (int)$Sub_Class_ID . "'",
                ARRAY_N
            );
        }

        if ($action !== 'drop' && !is_array($Sub_Class_ID) && !$Subdivision_ID) {
            $result = $this->db->get_row(
                "SELECT
                  CONCAT('{$nc_core->ADMIN_PATH}catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
                  CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
                  CONCAT('{$nc_core->HTTP_ROOT_PATH}action.php?ctrl=admin.infoblock&action=show_area_infoblocks&site_id=', c.`Catalogue_ID`),
                  CONCAT(sc.`Sub_Class_ID`, '. ', sc.`Sub_Class_Name`)
                FROM `Sub_Class` AS sc
                LEFT JOIN `Catalogue` AS c ON sc.`Catalogue_ID` = c.`Catalogue_ID`
                WHERE sc.`Sub_Class_ID` = '" . (int)$Sub_Class_ID . "'",
                ARRAY_N
            );
        }

        if (($action === 'drop' || is_array($Sub_Class_ID)) && $Subdivision_ID) {
            $result = $this->db->get_row(
                "SELECT
                  CONCAT('{$nc_core->ADMIN_PATH}catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
                  CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
                  CONCAT('{$nc_core->ADMIN_PATH}subdivision/index.php?phase=4&SubdivisionID=', s.`Subdivision_ID`),
                  CONCAT(s.`Subdivision_ID`, '. ', s.`Subdivision_Name`)
                FROM `Subdivision` AS s
                LEFT JOIN `Catalogue` AS c ON s.`Catalogue_ID` = c.`Catalogue_ID`
                WHERE s.`Subdivision_ID` = '" . (int)$Subdivision_ID . "'",
                ARRAY_N
            );

            $result[5] = $nc_core->ADMIN_PATH. 'subdivision/SubClass.php?SubdivisionID=' .$Subdivision_ID;
            $result[6] = '#' . (is_array($Sub_Class_ID) ? implode(',', $Sub_Class_ID) : $Sub_Class_ID);
        }

        if (($action === 'drop' || is_array($Sub_Class_ID)) && !$Subdivision_ID) {
            $result = $this->db->get_row(
                "SELECT
                  CONCAT('{$nc_core->ADMIN_PATH}catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
                  CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
                  CONCAT('{$nc_core->HTTP_ROOT_PATH}action.php?ctrl=admin.infoblock&action=show_area_infoblocks&site_id=', c.`Catalogue_ID`)
                FROM `Catalogue` AS c
                WHERE c.`Catalogue_ID` = '" . (int)$Catalogue_ID . "'",
                ARRAY_N
            );
            $result[3] = '#' . (is_array($Sub_Class_ID) ? implode(',', $Sub_Class_ID) : $Sub_Class_ID);
        }

        // no result
        if (!is_array($result)) {
            return false;
        }

        // constant
        array_unshift($result, $constant);

        return $result;
    }

    /**
     *
     */
    public function ClassInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Class_ID) = $args;

        if ($action != "drop" && !is_array($Class_ID)) {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."admin/class/index.php?phase=4&ClassID=', `Class_ID`),
          CONCAT(`Class_ID`, '. ', `Class_Name`)
        FROM `Class`
        WHERE `Class_ID` = '".intval($Class_ID)."'", ARRAY_N);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."class/index.php",
                    "#".(is_array($Class_ID) ? join(",", $Class_ID) : $Class_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_CLASS);

        return $result;
    }

    /**
     *
     */
    public function ClassTemplateInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Class_ID, $Class_Template_ID) = $args;

        if ($action != "drop" && !is_array($Class_Template_ID)) {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."admin/class/index.php?phase=4&ClassID=', c.`Class_ID`),
          CONCAT(c.`Class_ID`, '. ', c.`Class_Name`),
          CONCAT('".$nc_core->ADMIN_PATH."class/index.php?phase=16&ClassID=', ct.`Class_ID`),
          CONCAT(ct.`Class_ID`, '. ', ct.`Class_Name`)
        FROM `Class` AS ct
        LEFT JOIN `Class` AS c ON ct.`ClassTemplate` = c.`Class_ID`
        WHERE ct.`Class_ID` = '".intval($Class_Template_ID)."'", ARRAY_N);
        } else {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."admin/class/index.php?phase=4&ClassID=', `Class_ID`),
          CONCAT(`Class_ID`, '. ', `Class_Name`)
        FROM `Class`
        WHERE `Class_ID` = '".intval($Class_ID)."'", ARRAY_N);
            // format
            $result[3] = $nc_core->ADMIN_PATH."class/index.php?phase=20&ClassID=".$Class_ID;
            $result[4] = is_array($Class_Template_ID) ? "#".join(",", $Class_Template_ID) : "#".$Class_Template_ID;
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_CLASSTEMPLATE);

        return $result;
    }

    /**
     *
     */
    public function MessageInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Catalogue_ID, $Subdivision_ID, $Sub_Class_ID, $Class_ID, $Message_ID) = $args;

        $result = $this->db->get_row("SELECT
        CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
        CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
        CONCAT('".$nc_core->ADMIN_PATH."subdivision/index.php?phase=4&SubdivisionID=', s.`Subdivision_ID`),
        CONCAT(s.`Subdivision_ID`, '. ', s.`Subdivision_Name`),
        CONCAT('".$nc_core->HTTP_ROOT_PATH."?inside_admin=1&cc=', sc.`Sub_Class_ID`),
        CONCAT(sc.`Sub_Class_ID`, '. ', sc.`Sub_Class_Name`),
        CONCAT('".$nc_core->HTTP_ROOT_PATH."admin/class/index.php?phase=4&ClassID=', cl.`Class_ID`),
        CONCAT(cl.`Class_ID`, '. ', cl.`Class_Name`)
      FROM `Sub_Class` AS sc
      LEFT JOIN `Subdivision` AS s ON sc.`Subdivision_ID` = s.`Subdivision_ID`
      LEFT JOIN `Catalogue` AS c ON s.`Catalogue_ID` = c.`Catalogue_ID`
      LEFT JOIN `Class` AS cl ON sc.`Class_ID` = cl.`Class_ID`
      WHERE `Sub_Class_ID` = '".intval($Sub_Class_ID)."'", ARRAY_N);

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_MESSAGE);

        // format
        if (is_array($Message_ID)) {
            $result[9] = $result[5];
            $result[10] = "#".join(",", $Message_ID);
        } else {
            $result[9] = $action != "drop" ? $nc_core->HTTP_ROOT_PATH."full.php?inside_admin=1&catalogue=".$Catalogue_ID."&sub=".$Subdivision_ID."&cc=".$Sub_Class_ID."&message=".$Message_ID : $result[5];
            $result[10] = "#".$Message_ID;
        }

        return $result;
    }

    /**
     *
     */
    public function TemplateInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Template_ID) = $args;

        if ($action != "drop" && !is_array($Template_ID)) {
            $result = $this->db->get_row("SELECT CONCAT('".$nc_core->ADMIN_PATH."template/index.php?phase=4&TemplateID=', `Template_ID`), CONCAT(`Template_ID`, '. ', `Description`)
        FROM `Template`
        WHERE `Template_ID` = '".intval($Template_ID)."'", ARRAY_N);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."template/index.php",
                    "#".(is_array($Template_ID) ? join(",", $Template_ID) : $Template_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_TEMPLATE);

        return $result;
    }

    /**
     *
     */
    public function SystemTableInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($System_Table_ID, $Essence_ID) = $args;

        if ($action != "drop" && !is_array($System_Table_ID)) {
            $result = array($nc_core->ADMIN_PATH."field/index.php?isSys=1&SystemTableID=".$System_Table_ID, "#".$System_Table_ID);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."field/system.php",
                    "#".(is_array($System_Table_ID) ? join(",", $System_Table_ID) : $System_Table_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_SYSTEMTABLE);

        return $result;
    }

    /**
     *
     */
    public function UserInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($User_ID) = $args;

        if ($action != "drop" && !is_array($User_ID)) {
            $result = $this->db->get_row("SELECT CONCAT('".$nc_core->ADMIN_PATH."user/index.php?phase=4&UserID=', `User_ID`),
                                           CONCAT(`User_ID`, '. ', `".$nc_core->AUTHORIZE_BY."`)
        FROM `User`
        WHERE `User_ID` = '".intval($User_ID)."'", ARRAY_N);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."user/index.php",
                    "#".(is_array($User_ID) ? join(",", $User_ID) : $User_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_USER);

        return $result;
    }

    public function ModuleInfo($action, $args) {
        $nc_core = nc_Core::get_object();
        list($keyword, $catalogue_id) = $args;

        $info = $nc_core->db->get_row("SELECT `Module_Name`, `Checked` FROM `Module` WHERE `Keyword` = '".$nc_core->db->escape($keyword)."'", ARRAY_A);

        if ($info['Checked'] && file_exists(nc_module_folder($keyword) . "admin.php")) {
            $url = nc_module_path($keyword) . 'admin.php';
        } else {
            $url = $nc_core->ADMIN_PATH . 'modules/index.php?phase=2&amp;module_name=' . $keyword;
        }
        $result[0] = $url;
        $result[1] = constant($info['Module_Name']);

        if ($catalogue_id) {
            $catalogue = $this->db->get_row("SELECT
                CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', `Catalogue_ID`),
                CONCAT(`Catalogue_ID`, '. ', `Catalogue_Name`)
              FROM `Catalogue`
              WHERE `Catalogue_ID` = '".intval($catalogue_id)."'", ARRAY_N);
        } else {
            $catalogue = array(
                    $nc_core->ADMIN_PATH."catalogue/index.php",
                    CONTROL_USER_SELECTSITEALL
            );
        }
        $result[2] = $catalogue[0];
        $result[3] = $catalogue[1];
        array_unshift($result, NETCAT_MODULE_LOGGING_MODULE);
        return $result;
    }

    public function CommentInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Catalogue_ID, $Subdivision_ID, $Sub_Class_ID, $Class_ID, $Message_ID, $Comment_ID) = $args;

        $result = $this->db->get_row("SELECT
        CONCAT('".$nc_core->ADMIN_PATH."catalogue/index.php?phase=2&type=2&CatalogueID=', c.`Catalogue_ID`),
        CONCAT(c.`Catalogue_ID`, '. ', c.`Catalogue_Name`),
        CONCAT('".$nc_core->ADMIN_PATH."subdivision/index.php?phase=4&SubdivisionID=', s.`Subdivision_ID`),
        CONCAT(s.`Subdivision_ID`, '. ', s.`Subdivision_Name`),
        CONCAT('".$nc_core->HTTP_ROOT_PATH."?inside_admin=1&cc=', sc.`Sub_Class_ID`),
        CONCAT(sc.`Sub_Class_ID`, '. ', sc.`Sub_Class_Name`),
        CONCAT('".$nc_core->HTTP_ROOT_PATH."admin/class/index.php?phase=4&ClassID=', cl.`Class_ID`),
        CONCAT(cl.`Class_ID`, '. ', cl.`Class_Name`)
      FROM `Sub_Class` AS sc
      LEFT JOIN `Subdivision` AS s ON sc.`Subdivision_ID` = s.`Subdivision_ID`
      LEFT JOIN `Catalogue` AS c ON s.`Catalogue_ID` = c.`Catalogue_ID`
      LEFT JOIN `Class` AS cl ON sc.`Class_ID` = cl.`Class_ID`
      WHERE `Sub_Class_ID` = '".intval($Sub_Class_ID)."'", ARRAY_N);

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_COMMENT);

        // format
        $result[9] = $nc_core->HTTP_ROOT_PATH."full.php?inside_admin=1&catalogue=".$Catalogue_ID."&sub=".$Subdivision_ID."&cc=".$Sub_Class_ID."&message=".$Message_ID;
        $result[10] = "#".$Message_ID;

        $result[11] = nc_message_link($Message_ID, $Class_ID).( $action != 'drop' ? "#nc_commentID".$Sub_Class_ID."_".$Message_ID."_".$Comment_ID : "");
        $result[12] = "#".$Comment_ID;



        return $result;
    }

    /**
     *
     */
    public function WidgetClassInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Widget_Class_ID) = $args;

        if ($action != "drop" && !is_array($Widget_Class_ID)) {
            $result = $this->db->get_row("SELECT CONCAT('".$nc_core->ADMIN_PATH."widget/index.php?phase=30&widgetclass_id=', `Widget_Class_ID`),
                                           CONCAT(`Widget_Class_ID`, '. ', `Name`)
                                    FROM `Widget_Class`
                                    WHERE `Widget_Class_ID` = '".intval($Widget_Class_ID)."'", ARRAY_N);
        } else {
            $result = array(
                    $nc_core->ADMIN_PATH."widgets8/index.php",
                    "#".(is_array($Widget_Class_ID) ? join(",", $Widget_Class_ID) : $Widget_Class_ID)
            );
        }

        // no result
        if (!is_array($result)) return false;

        // constant
        array_unshift($result, NETCAT_MODULE_LOGGING_WIDGETCLASS);

        return $result;
    }

    /**
     *
     */
    public function WidgetInfo($action, $args) {
        // system superior object
        $nc_core = nc_Core::get_object();

        list($Widget_Class_ID, $Widget_ID) = $args;
        if ($action != "drop" && !is_array($Widget_ID)) {
            $result = $this->db->get_row("SELECT
          CONCAT('".$nc_core->ADMIN_PATH."widget/admin.php?phase=30&widget_id=', `Widget_ID`),
          CONCAT(`Widget_ID`, '. ', `Name`),
          CONCAT('".$nc_core->ADMIN_PATH."widget/index.php?phase=30&widgetclass_id=', `Widget_Class_ID`),
          CONCAT(`Widget_Class_ID`, '. ', `Name`)
        FROM `Widget`
        WHERE `Widget_ID` = '".intval($Widget_ID)."'", ARRAY_N);
            if (!is_array($result)) return false;
            array_unshift($result, NETCAT_MODULE_LOGGING_WIDGET);
        }
        else {
            $result = array($Widget_ID, $Widget_Class_ID);
            if (!is_array($result)) return false;
            array_unshift($result, NETCAT_MODULE_LOGGING_WIDGET_DROP);
        }

        return $result;
    }

}
