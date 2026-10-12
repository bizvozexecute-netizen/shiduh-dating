<?php
/*
 * Заглушки аватаров для стенда (приёмка 2026-10): фото пользователей
 * (netcat_files/u/) на стенд не переносятся — это настоящие снимки, ПДн.
 * В базе же Photo_avatar заполнен, и кабинет показывал битые картинки.
 * Скрипт рисует нейтральный силуэт на мягком фоне (оттенок — по User_ID),
 * формат по расширению из Photo_avatar; существующие файлы не трогает.
 *
 * Запуск на стенде (из каталога проекта):
 *   docker compose -f docker-compose.stage.yml cp tools/stage/avatar_placeholders.php web:/tmp/
 *   docker compose -f docker-compose.stage.yml exec -u www-data web php /tmp/avatar_placeholders.php
 */
$r = '/var/www/html';
putenv("DOCUMENT_ROOT=$r"); putenv('HTTP_HOST=localhost');
$_SERVER['DOCUMENT_ROOT'] = $r; $_SERVER['HTTP_HOST'] = 'localhost';
include_once $r . '/vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
$db = nc_core::get_object()->db;

$rows = (array)$db->get_results(
    "SELECT `User_ID`, `Photo_avatar` FROM `User` WHERE `Photo_avatar` IS NOT NULL AND `Photo_avatar` <> ''", ARRAY_A);
$made = 0; $kept = 0;
foreach ($rows as $row) {
    $parts = explode(':', (string)$row['Photo_avatar']);
    if (count($parts) < 4 || strpos($parts[3], '..') !== false) { continue; }
    $path = $r . '/netcat_files/' . $parts[3];
    if (is_file($path)) { $kept++; continue; }
    @mkdir(dirname($path), 0775, true);

    $uid = (int)$row['User_ID'];
    $im = imagecreatetruecolor(400, 400);
    // мягкий пастельный фон: оттенок из User_ID, одна палитра на весь стенд
    $h = ($uid * 137) % 360; $c = 46; $m = 196;                  // пастель
    $f = function ($n) use ($h, $c, $m) {
        $k = fmod($n + $h / 30, 12);
        return (int)($m + $c * max(-1, min(1, min($k - 3, 9 - $k))));
    };
    $bg = imagecolorallocate($im, $f(0), $f(8), $f(4));
    $fg = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, 400, 400, $bg);
    // силуэт: голова + плечи
    imagefilledellipse($im, 200, 160, 130, 130, $fg);
    imagefilledarc($im, 200, 420, 340, 300, 180, 360, $fg, IMG_ARC_PIE);

    $ext = strtolower(pathinfo($parts[3], PATHINFO_EXTENSION));
    $ok = ($ext === 'png') ? imagepng($im, $path) : imagejpeg($im, $path, 88);
    imagedestroy($im);
    if ($ok) { @chmod($path, 0664); $made++; echo "made $uid {$parts[3]}\n"; }
    else { echo "FAIL $uid {$parts[3]}\n"; }
}
echo "итог: создано $made, уже были $kept\n";
