<?
$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -3)).( strstr(__FILE__, "/") ? "/" : "\\" );
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $INCLUDE_FOLDER. 'lib/Mail/Queue.php';
$nc_core = nc_core::get_object();
$nc_core->modules->load_env();

// проверка cron_key
$cron_key = $nc_core->get_settings('SecretKey');
if ($nc_core->input->fetch_get('cron_key') !== $cron_key) {
    die('Invalid cron_key');
}

$number = filter_input(INPUT_GET, 'number', FILTER_SANITIZE_NUMBER_INT) ?: 20;

$db_options = array('type' => 'ezsql', 'mail_table' => 'Mail_Queue');
$mail_options = array('driver' => 'mail');

$mail_queue = new Mail_Queue($db_options, $mail_options);
$mail_queue->sendMailsInQueue($number);
?>