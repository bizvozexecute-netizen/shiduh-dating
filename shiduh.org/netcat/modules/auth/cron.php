<?php

/* $Id: cron.php 4430 2011-03-31 11:18:17Z denis $ */

$param = isset($_GET['param']) ? $_GET['param'] : '';

// Укажите значение параметра, заданного в 'Управление задачами'
$check = "test";

if ($check != $param) {
    echo "Non-authorized access!";
    exit;
}

$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -4)).( strstr(__FILE__, "/") ? "/" : "\\" );
include_once ($NETCAT_FOLDER."vars.inc.php");
require ($ROOT_FOLDER."connect_io.php");

$MODULE_VARS = $nc_core->modules->get_module_vars();

// удаление пользователей
if (($confirm_time = intval($nc_core->get_settings('confirm_time', 'auth')))) {
    $users_to_drop = $db->get_col("SELECT `User_ID` FROM `User`
    WHERE `Confirmed` = 0
    AND `Checked` = 0
    AND `RegistrationCode` <> ''
    AND (UNIX_TIMESTAMP(`Created`) + ".$confirm_time."*3600) < UNIX_TIMESTAMP(NOW()) ");
    if ($users_to_drop) {
        $nc_core->user->delete_by_id($users_to_drop);
    }
}



print "Done.";