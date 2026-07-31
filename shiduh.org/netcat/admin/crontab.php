#!/usr/local/bin/php
<?php

///$DOCUMENT_ROOT="/usr/local/etc/httpd/htdocs/netcat"; # Физический путь до папки содержащей netcat
// Определим путь до DOCUMENT_ROOT (на 2 уровня выше текущей директории):
$DOCUMENT_ROOT = join('/', array_slice(explode(DIRECTORY_SEPARATOR, __FILE__), 0, -3));

# Домен, на который cron стучится по HTTP.
#
# ВНИМАНИЕ. Раньше здесь был захардкожен боевой домен 'shid.o-d.one'. Это
# означало, что cron, запущенный в ЛЮБОМ окружении (в том числе локальном),
# дёргает задачи на ПРОДЕ: рассылку писем (admin/mailer.php), уведомления
# и подписки. Теперь домен берётся из окружения — см. NC_HTTP_HOST
# в .env / docker-compose.yml.
$HTTP_HOST = getenv('NC_HTTP_HOST') ?: 'shid.o-d.one';

$_SERVER['HTTP_HOST'] = $HTTP_HOST;

putenv("DOCUMENT_ROOT=$DOCUMENT_ROOT");
putenv("HTTP_HOST=${_SERVER['HTTP_HOST']}");

$NETCAT_FOLDER = join(strstr(__FILE__, '/') ? '/' : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -3)) . (strstr(__FILE__, '/') ? '/' : "\\");
include_once $NETCAT_FOLDER . 'vars.inc.php';

ignore_user_abort(true);

require_once $ROOT_FOLDER . 'connect_io.php';


$res = $db->get_results('SELECT * FROM CronTasks', ARRAY_A);

if (!function_exists("nc_task_fetch_url")) {

    function nc_task_fetch_url($url) {
        switch (true) {
            case function_exists('curl_version') :
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_exec($ch);
                if (curl_errno($ch) > 0) {
                    curl_close($ch);
                    return false;
                }
                curl_close($ch);
                break;
            case (function_exists('stream_get_contents')):
                #   -- for the future using, example for http authorization while search engine processing
                #
                #   $headers_array = array(
                #      'Connection: close',
                #      'Accept: text/xml,application/xml,application/xhtml+xml,text/html,*/*'
                #   );
                #  $headers = implode("\r\n",$headers_array)."\n";
                #
                $context_params = array(
                    'http' => array(
                        'method' => 'GET'
                        # , 'header' = > $headers
                    )
                );
                $context = stream_context_create($context_params);
                $fp = fopen($url, 'r', false, $context);
                if ($fp) {
                    $buff = stream_get_contents($fp);
                    fclose($fp);
                }
                break;
            case (strpos(ini_get('disable_functions'), "passthru") !== false) :
                passthru('wget -O - -q  "$url"', $ret_var);
                if ($ret_var > 0) {
                    return false;
                }
                break;
        }
        return true;
    }

}

// Get secret key from system. Should be passed to scripts via $_GET['cron_key']
if (!isset($nc_core)) {
    $nc_core = nc_Core::get_object();
}
$cron_key = $nc_core->get_settings('SecretKey');

function addCronKey($url) {
    global $cron_key;
    $dl = '?';
    if (strpos($url, '?') !== false) {
        $dl = '&';
    }

    return $url . $dl . 'cron_key=' . $cron_key;
}

foreach ($res as $rs) {

    $time = $rs['Cron_Launch'];
    if ($rs['Cron_Minutes'] > 0) {
        $time += $rs['Cron_Minutes'] * 60;
    }
    if ($rs['Cron_Hours'] > 0) {
        $time += $rs['Cron_Hours'] * 3600;
    }
    if ($rs['Cron_Days'] > 0) {
        $time += $rs['Cron_Days'] * 86400;
    }

    if ($rs['Cron_Minutes'] || $rs['Cron_Hours'] || $rs['Cron_Days']) {
        if ($time <= time()) {
            if (getenv('debug')) {
                echo 'Fetching URL ' . addCronKey($rs['Cron_Script_URL']) . "\n";
            }
            $no_err = true;
            if (substr($rs['Cron_Script_URL'], 0, 1) === "/") {
                $scheme = $nc_core->catalogue->get_scheme_by_host_name($HTTP_HOST);
                $no_err = nc_task_fetch_url($scheme . '://' . $HTTP_HOST . addCronKey($rs['Cron_Script_URL']));
                //passthru('wget -O - -q "http://'.$HTTP_HOST.$rs['Cron_Script_URL'].'"');
            } else {
                $no_err = nc_task_fetch_url(addCronKey($rs['Cron_Script_URL']));
                //passthru('wget -O - -q "'.$rs['Cron_Script_URL'].'"');
            }
            if ($no_err) {
                $db->query("UPDATE CronTasks SET Cron_Launch='" . time() . "' WHERE Cron_ID='" . $rs['Cron_ID'] . "' LIMIT 1");
            }
        }//end if now time
    }
}

unset($rs);
exit();
?>
