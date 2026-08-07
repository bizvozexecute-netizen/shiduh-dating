<?php
/*
 * Раннер обратимых миграций схемы (ТЗ Э0.1).
 *
 *   php migrations/migrate.php status     — что применено, что ждёт
 *   php migrations/migrate.php up         — применить все неприменённые
 *   php migrations/migrate.php down       — откатить последнюю применённую
 *   php migrations/migrate.php down-all   — откатить все, в обратном порядке
 *
 * Каждый файл 00N_*.php возвращает ['up' => fn($nc_core), 'down' => fn($nc_core)].
 * Учёт — в таблице Migrations. up идемпотентен: применённое пропускается.
 */
require __DIR__ . '/_bootstrap.php';

$cmd = isset($argv[1]) ? $argv[1] : 'status';
$files = mig_files();

if ($cmd === 'status') {
    foreach ($files as $f) {
        $n = mig_name($f);
        echo (mig_applied($db, $n) ? '[x] ' : '[ ] ') . $n
            . (mig_applied($db, $n) ? '  — применена' : '  — ожидает') . "\n";
    }
    exit(0);
}

if ($cmd === 'up') {
    foreach ($files as $f) {
        $n = mig_name($f);
        if (mig_applied($db, $n)) {
            continue;
        }
        $m = require $f;
        echo "up:   $n ... ";
        $m['up']($nc_core);
        mig_mark($db, $n);
        echo "ok\n";
    }
    echo "Готово.\n";
    exit(0);
}

if ($cmd === 'down') {
    foreach (array_reverse($files) as $f) {
        $n = mig_name($f);
        if (!mig_applied($db, $n)) {
            continue;
        }
        $m = require $f;
        echo "down: $n ... ";
        $m['down']($nc_core);
        mig_unmark($db, $n);
        echo "ok\n";
        exit(0);
    }
    echo "Нечего откатывать.\n";
    exit(0);
}

if ($cmd === 'down-all') {
    foreach (array_reverse($files) as $f) {
        $n = mig_name($f);
        if (!mig_applied($db, $n)) {
            continue;
        }
        $m = require $f;
        echo "down: $n ... ";
        $m['down']($nc_core);
        mig_unmark($db, $n);
        echo "ok\n";
    }
    echo "Готово.\n";
    exit(0);
}

echo "Использование: migrate.php up|down|down-all|status\n";
exit(1);
