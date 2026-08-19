<?php
/*
 * Bootstrap для системы миграций: поднимает ядро NetCat и ведёт учёт
 * применённых миграций в таблице Migrations.
 *
 * Только CLI. По HTTP закрыто (.htaccess), плюс страховка ниже.
 */
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die('Migrations run from CLI only.');
}

// В CLI веб-сервер не задаёт DOCUMENT_ROOT/HTTP_HOST, а vars.inc.php строит
// из них все пути. Задаём сами (как netcat/admin/crontab.php), иначе
// connect_io ищется по '/netcat/...' от корня ФС.
$doc_root = realpath(__DIR__ . '/..');
putenv("DOCUMENT_ROOT=$doc_root");
putenv("HTTP_HOST=" . (getenv('NC_HTTP_HOST') ?: 'localhost'));
$_SERVER['DOCUMENT_ROOT'] = $doc_root;
$_SERVER['HTTP_HOST'] = getenv('NC_HTTP_HOST') ?: 'localhost';

include_once $doc_root . '/vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

$nc_core = nc_core::get_object();
$nc_core->modules->load_env();
$db = $nc_core->db;

// Таблица учёта применённых миграций.
$db->query("CREATE TABLE IF NOT EXISTS `Migrations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `applied_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

function mig_applied($db, $name) {
    return (int)$db->get_var(
        "SELECT COUNT(*) FROM `Migrations` WHERE `name`='" . $db->escape($name) . "'"
    ) > 0;
}

function mig_mark($db, $name) {
    $db->query("INSERT INTO `Migrations` SET `name`='" . $db->escape($name) . "', `applied_at`=NOW()");
}

function mig_unmark($db, $name) {
    $db->query("DELETE FROM `Migrations` WHERE `name`='" . $db->escape($name) . "'");
}

function mig_files() {
    $files = glob(__DIR__ . '/[0-9]*.php');
    sort($files);
    return $files;
}

function mig_name($file) {
    return basename($file, '.php');
}

/*
 * Копирует строку таблицы-образца с заменой части полей и вставляет её.
 * Нужно для создания раздела/компонента: таблицы Class, Subdivision,
 * Sub_Class имеют десятки колонок, перечислять их вручную хрупко —
 * берём готовую рабочую строку и точечно меняем ключевые поля.
 */
function mig_copy_row($db, $table, $id_col, $src_id, $overrides) {
    $row = $db->get_row("SELECT * FROM `$table` WHERE `$id_col`=" . (int)$src_id, ARRAY_A);
    if (!$row) {
        throw new Exception("mig_copy_row: нет строки-образца $table.$id_col=$src_id");
    }
    foreach ($overrides as $k => $v) {
        $row[$k] = $v;
    }
    $sets = array();
    foreach ($row as $k => $v) {
        $sets[] = $v === null ? "`$k`=NULL" : "`$k`='" . $db->prepare($v) . "'";
    }
    $db->query("INSERT INTO `$table` SET " . implode(',', $sets));
}
