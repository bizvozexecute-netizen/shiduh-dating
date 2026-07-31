<?php

/* $Id: message_edit.php 8390 2012-11-09 14:03:32Z vadim $ */

$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -2)).( strstr(__FILE__, "/") ? "/" : "\\" );
@include_once ($NETCAT_FOLDER."vars.inc.php");

if (!class_exists("nc_System")) die("Unable to load file.");
if (!isset($inside_admin)) {
    $inside_admin = null;
}
if ($inside_admin && $UI_CONFIG) {
    if ($action == "add") {
        $UI_CONFIG->locationHash = "object.add(".$cc.")";
    } else {
        $UI_CONFIG->locationHash = "object.edit(".$classID.",".$message.")";
    }
}

// если редактируем системные таблицы, функции нужны глобальные значения
if ($systemTableID) {
    $GLOBALS['fldCount'] = $fldCount;
    $GLOBALS['fldID'] = $fldID;
    $GLOBALS['fld'] = $fld;
    $GLOBALS['fldName'] = $fldName;
    $GLOBALS['fldValue'] = $fldValue;
    $GLOBALS['fldType'] = $fldType;
    $GLOBALS['fldFmt'] = $fldFmt;
    $GLOBALS['fldNotNull'] = $fldNotNull;
    $GLOBALS['fldInheritance'] = $fldInheritance;
    $GLOBALS['fldDefault'] = $fldDefault;
    $GLOBALS['fldTypeOfEdit'] = $fldTypeOfEdit;
    $GLOBALS['fldDoSearch'] = $fldDoSearch;
}

// получаем код формы
$result = nc_fields_form($action);

if ($result) {
    if (!$nc_notmodal && (!$systemTableID || $systemTableID == 3)) {
		$result = nc_prepare_message_form(  $result, $action, $admin_mode, $systemTableID, $systemTableID, $current_cc,
                                            $f_Checked, $f_Priority, $f_Keyword,
                                            $f_ncTitle, $f_ncKeywords, $f_ncDescription, 1, 0,
                                            $f_ncSMO_Title, $f_ncSMO_Description, $f_ncSMO_Image, $f_ncDemoContent);
	}
    if (!isset($classID)) {
        $classID = null;
    }
    eval(nc_check_eval("echo \"$result\";"));
}
