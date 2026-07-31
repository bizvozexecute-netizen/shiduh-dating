<?php

/* $Id: cron.php 7302 2012-06-25 21:12:35Z alive $ */

$param = $_GET['param'];

// Укажите значение параметра, заданного в 'Управление задачами'
$check = "test";

if ($check != $param) {
    echo "Non-authorized access!";
    exit;
}

$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -4)).( strstr(__FILE__, "/") ? "/" : "\\" );
include_once ($NETCAT_FOLDER."vars.inc.php");
require ($ROOT_FOLDER."connect_io.php");

$MODULE_VARS = $nc_core->modules->load_env();


try {
    $s = nc_subscriber_send::get_object();

    if ($s->is_blocked()) {
        die("Blocked");
    }

    $s->block();
    $nc_subscriber = nc_subscriber::get_object();

    $s->formation_service();

    $s->send();
    $s->send_periodical();
    $s->send_serial();
    $s->send_prepared();


    $s->update_stats();

    if (rand(0, 20) == 5) {
        $nc_subscriber->delete_expire();
    }
} catch (Exception $e) {
    echo $e->getMessage();
}

$s->unlock();
print "Done.";