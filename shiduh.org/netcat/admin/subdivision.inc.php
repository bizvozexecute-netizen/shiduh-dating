<?php

function UpdateHiddenURL($parent_url, $parent_sub, $catalogue) {
    global $nc_core, $db;

    $parent_sub+= 0;
    $catalogue+= 0;

    $res = $db->get_results("SELECT `EnglishName`, `Subdivision_ID` FROM `Subdivision`
      WHERE `Parent_Sub_ID` = '".$parent_sub."' AND `Catalogue_ID` = '".$catalogue."'", ARRAY_N);
    $subCount = $db->num_rows;
    for ($i = 0; $i < $subCount; $i++)
        list($english_name[$i], $sub_id[$i]) = $res[$i];

    for ($i = 0; $i < $subCount; $i++) {
        $new_parent_url = $parent_url.$english_name[$i]."/";

        $res = $db->query("UPDATE `Subdivision` SET `Hidden_URL` = '".$new_parent_url."', `LastUpdated` = `LastUpdated` WHERE `Subdivision_ID` = '".$sub_id[$i]."'");
        UpdateHiddenURL($new_parent_url, $sub_id[$i], $catalogue);
    }
}

function GetHiddenURL($SubdivisionID) {
    $nc_core = nc_Core::get_object();
    return!$SubdivisionID ? "" : $nc_core->subdivision->get_by_id($SubdivisionID, "Hidden_URL");
}

function ChildrenNumber($SubdivisionID) {
    global $db, $perm;

    # часть sql-запроса, ограничивающая выборку только объектами, которые пользователь может видеть
    $security_limit = "";
    $access = false;
    $Array = array();
    $SubdivisionID = intval($SubdivisionID);

    if ($perm->isDirector() || $perm->isSupervisor()) {
        return $db->get_var("SELECT COUNT(Subdivision_ID) FROM `Subdivision` AS a WHERE Parent_Sub_ID = '".$SubdivisionID."'");
    }

    # id каталогов, которые пользователь может администрировать
    $catalogue_admin = $perm->listItems('catalogue');
    if (!empty($catalogue_admin)) $access = true;

    # id разделов, которые пользователь может администрировать
    $sub_admin = $perm->listItems('subdivision');
    if (!empty($sub_admin)) $access = true;

    # id шаблонов в разделе, которые пользователь может администрировать
    $cc_admin = $perm->listItems('subclass');
    if (!empty($cc_admin)) $access = true;

    if (!$access) return false;

    # id разделов, которые администрирует пользователь, на основе $sub_admin + $cc_admin
    $sub_and_cc_admin = $sub_admin;

    if ($cc_admin) {
        $res = $db->get_results("SELECT `Subdivision_ID`
			FROM `Sub_Class`
			WHERE `Sub_Class_ID` IN (".join(',', $cc_admin).")", ARRAY_A);
        foreach ($res AS $row) {
            $sub_and_cc_admin[] = $row['Subdivision_ID'];
        }
    }

    if (!empty($sub_and_cc_admin) || !empty($catalogue_admin)) {
        # получить родительские разделы для разделов, которые пользователь может
        # модерировать или администрировать
        $res = $db->get_results("SELECT parent.Subdivision_ID
			FROM `Subdivision` AS parent, `Subdivision` AS allowed
			WHERE (allowed.Subdivision_ID IN (".join(',', array_unique($sub_and_cc_admin)).")
			OR allowed.Catalogue_ID IN (".join(',', array_unique($catalogue_admin))."))
			AND allowed.Hidden_URL LIKE CONCAT(parent.Hidden_URL, '%')", ARRAY_A);

        # разделы, которые пользователь может видеть
        $allowed_subs = array();
        foreach ((array) $res AS $row) {
            $allowed_subs[] = $row['Subdivision_ID'];
        }


        # id разделов, которые являются дочерними для тех разделов, на которые явно указаны права на администрирование -- эти права наследуются (as of 3.0)

        $sub_child_administrator = array();
        # права наследуются для дочерних узлов
        $res = $db->get_results("SELECT child.Subdivision_ID, allowed.Subdivision_ID AS Allowed_Subdivision_ID
			FROM Subdivision AS child, Subdivision AS allowed
			WHERE allowed.Subdivision_ID IN (".join(',', array_unique($sub_admin)).")
			AND child.Hidden_URL LIKE CONCAT(allowed.Hidden_URL, '_%')", ARRAY_A);

        foreach ((array) $res as $row) {
            $allowed_subs[] = $row['Subdivision_ID'];
            $sub_child_administrator[$row['Subdivision_ID']] = $row['Allowed_Subdivision_ID'];
        }

        if ($allowed_subs) {
            $qry_where.= " AND a.Subdivision_ID IN (".join(',', $allowed_subs).") ";
        }
    }

    $Array = $db->get_var("SELECT COUNT(Subdivision_ID) FROM `Subdivision` AS a WHERE Parent_Sub_ID = '{$SubdivisionID}'$qry_where");

    return $Array;
}

function GetParentSubID($SubdivisionID) {
    $nc_core = nc_Core::get_object();
    return $nc_core->subdivision->get_by_id($SubdivisionID, "Parent_Sub_ID");
}

function GetSubClassCount($SubdivisionID) {
    global $db;
    return $db->get_var("SELECT COUNT(*) FROM Sub_Class WHERE Subdivision_ID='".intval($SubdivisionID)."'");
}

function DeleteFromSubClass($subdivisionId) {
    $nc_core = nc_Core::get_object();
    $db = $nc_core->db;

    $res = $nc_core->sub_class->get_by_subdivision_id($subdivisionId);

    $catalogueId = null;
    $subClassToDelete = array();
    $classIds = array();

    foreach((array)$res as $subClass) {
        if (!$catalogueId) {
            $catalogueId = $subClass['Catalogue_ID'];
        }
        $subClassToDelete[] = (int)$subClass['Sub_Class_ID'];
        $classIds[$subClass['Sub_Class_ID']] = (int)$subClass['Class_ID'];
    }

    if (count($subClassToDelete) > 0) {
        $nc_core->event->execute(nc_Event::BEFORE_INFOBLOCK_DELETED, $catalogueId, $subdivisionId, $subClassToDelete);

        foreach($subClassToDelete as $subClass) {
            DeleteSubClassFiles($subClass, $classIds[$subClass]);

            if (nc_module_check_by_keyword('comments')) {
                include_once nc_module_folder('comments') . 'function.inc.php';
                // delete comment rules
                nc_comments::dropRuleSubClass($db, $subClass);
                // delete comments
                nc_comments::dropComments($db, $subClass, 'Sub_Class');
            }

            $db->query("DELETE FROM `Sub_Class` WHERE `Sub_Class_ID` = {$subClass}");
        }

        $nc_core->event->execute(nc_Event::AFTER_INFOBLOCK_DELETED, $catalogueId, $subdivisionId, $subClassToDelete);
    }

    return;
}

function DeleteMessages($SubdivisionID) {
    $nc_core = nc_Core::get_object();
    $SubdivisionID = (int)$SubdivisionID;
    $classes = $nc_core->db->get_col(
        "SELECT DISTINCT `Class_ID` FROM `Sub_Class`
         WHERE `Subdivision_ID` = '{$SubdivisionID}'"
    );

    if (!empty($classes)) {
        foreach ($classes as $ClassID) {
            $messages = $nc_core->db->get_col(
                "SELECT Message_ID
                 FROM `Message{$ClassID}`
                 WHERE `Subdivision_ID` = '{$SubdivisionID}'"
            );
            $nc_core->message->delete_by_id($messages, $ClassID, $nc_core->get_settings('TrashUse'));
        }
    }
}

class SubdivisionLocation {

    public $CatalogueID, $ParentSubID, $SubdivisionID;

    function __construct() {
        global $CatalogueID, $ParentSubID, $SubdivisionID;
        global $db, $nc_core;

        if ($SubdivisionID) {
            $Array = $nc_core->subdivision->get_by_id($SubdivisionID);
            $this->SubdivisionID = $SubdivisionID;
            $this->ParentSubID = $Array['Parent_Sub_ID'];
            $this->CatalogueID = $Array['Catalogue_ID'];
        } else {
            $this->SubdivisionID = 0;

            $this->ParentSubID = (isset($ParentSubID) && $ParentSubID) ? $ParentSubID : 0;

            if ($this->ParentSubID) {
                $this->CatalogueID = $nc_core->subdivision->get_by_id($this->ParentSubID, 'Catalogue_ID');
            } else {
                $this->CatalogueID = (isset($CatalogueID) && $CatalogueID) ? $CatalogueID : 0;
            }
        }
    }

    function printVars() {
        print "SubdivisionLocation.CatalogueID=".$this->CatalogueID."<br>\n";
        print "SubdivisionLocation.ParentSubID=".$this->ParentSubID."<br>\n";
        print "SubdivisionLocation.SubdivisionID=".$this->SubdivisionID."<br>\n";
    }

}

function IsAllowedSubdivisionEnglishName($EnglishName, $ParentSubID, $SubdivisionID, $CatalogueID) {
    global $db;

    if (!$EnglishName) return 0;

    $EnglishName = $db->escape($EnglishName);
    $ParentSubID = intval($ParentSubID);
    $SubdivisionID = intval($SubdivisionID);
    $CatalogueID = intval($CatalogueID);

    $select = "SELECT EnglishName FROM Subdivision WHERE EnglishName='".$EnglishName."' AND Parent_Sub_ID='".$ParentSubID."'";
    $select .= " AND EnglishName<>'' AND Subdivision_ID<>'".$SubdivisionID."' AND Catalogue_ID='".$CatalogueID."'";

    $Result = $db->query($select);

    return ($db->num_rows == 0);
}

function CascadeDeleteSubdivision($SubdivisionID) {
    $nc_core = nc_Core::get_object();
    $db = $nc_core->db;
    $SubdivisionID = (int)$SubdivisionID;

    $CatalogueID = $db->get_var("SELECT `Catalogue_ID` FROM `Subdivision` WHERE `Subdivision_ID` = $SubdivisionID");

    // execute core action
    $nc_core->event->execute(nc_Event::BEFORE_SUBDIVISION_DELETED, $CatalogueID, $SubdivisionID);

    if (0 && nc_module_check_by_keyword('comments')) {
        include_once nc_module_folder('comments') . 'function.inc.php';
        // delete comment rules
        nc_comments::dropRule($db, array($CatalogueID, $SubdivisionID));
        // delete comments
        nc_comments::dropComments($db, $SubdivisionID, 'Subdivision');
    }

    DeleteMessages($SubdivisionID);
    DeleteFromSubClass($SubdivisionID);
    DeleteSubdivisionDir($SubdivisionID);

    $db->query("DELETE FROM `Subdivision` WHERE `Subdivision_ID` = $SubdivisionID");

    // Удаление сгенерированных изображений для раздела
    $sys_table_id = $nc_core->get_system_table_id_by_name('Subdivision');
    $sub_file_field_ids = $db->get_col(
        "SELECT `Field_ID` FROM `Field` WHERE `System_Table_ID`={$sys_table_id} AND `TypeOfData_ID`=" . NC_FIELDTYPE_FILE
    );
    if ($sub_file_field_ids) {
        foreach ($sub_file_field_ids as $sub_file_field_id) {
            nc_image_generator::remove_generated_images('subdivision', $sub_file_field_id, $SubdivisionID);
        }
    }

    // execute core action
    $nc_core->event->execute(nc_Event::AFTER_SUBDIVISION_DELETED, $CatalogueID, $SubdivisionID);

    nc_tpl_mixin_cache::delete_subdivision_files($CatalogueID, $SubdivisionID);

}
