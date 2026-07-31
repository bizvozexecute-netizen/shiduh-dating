<?php
/* Cron API
 *
 * This file should be included in script which is intended to run via cron.
 * It does two things:
 * 1. Initializes system variables and loads nessecary files
 * 2. Performs an authorization via SecretKey ($_GET['cron_key'])
 *
 */

// Initialize system variables and load settings
$NETCAT_FOLDER = realpath(dirname(__FILE__)."/../../");
require_once ($NETCAT_FOLDER."/vars.inc.php");

// Initialize database connection
require_once ($ROOT_FOLDER."connect_io.php");

// Get secret key from system
if (!isset($nc_core)) $nc_core = nc_Core::get_object();
$key = $nc_core->get_settings('SecretKey');


// Forbid run if key is empty (for security reasons)
if ($key==='') die('Access denied');

// To be able to run, key should be provided via GET query with 'key' parameter
if (!isset($_GET['cron_key']) || $_GET['cron_key']!==$key) die('Access denied');


