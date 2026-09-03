<?php
/*
 * Сохранение системного промпта (ROADMAP Э1.2-b).
 *
 * POST, только администратор. action:
 *   save    — content: новая версия промпта
 *   restore — version: откат к прошлой версии (записывается как новая версия,
 *             история не теряется)
 *
 * Экранирование строк — prepare() (№35), числа — (int). CSRF — заслон по
 * Origin/Referer до любых изменений.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();   // JSON-заголовки и обработчик фаталов — до загрузки ядра
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/prompt_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}


global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!shiduh_is_admin($db, $uid)) {
    shiduh_json(403, array('error' => 'Доступно только администратору'));
}

shiduh_csrf_check($uid);   // заслон CSRF: источник + токен (C3)

$action = (string)$nc_core->input->fetch_post('action');

switch ($action) {
    case 'save':
        $raw = $nc_core->input->fetch_post('content');
        if (!is_string($raw)) {
            // content[]=... иначе превратился бы в строку "Array" и стал бы
            // ролью консультанта
            shiduh_json(400, array('error' => 'Некорректный текст промпта'));
        }
        $content = trim($raw);
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $content)) {
            // колонка в utf8 (3 байта): эмодзи иначе дал бы невнятную 500
            shiduh_json(400, array('error' => 'Уберите эмодзи и другие 4-байтные символы'));
        }
        if ($content === '') {
            shiduh_json(400, array('error' => 'Текст промпта обязателен'));
        }
        if (mb_strlen($content) > PROMPT_MAX_LEN) {
            shiduh_json(400, array('error' => 'Промпт длиннее ' . PROMPT_MAX_LEN . ' символов'));
        }
        $version = prompt_store($db, $content, $uid);
        if (!$version) {
            shiduh_json(500, array('error' => 'Не удалось сохранить промпт'));
        }
        shiduh_json(200, array('ok' => true, 'version' => $version));
        break;

    case 'restore':
        $version = (int)$nc_core->input->fetch_post('version');
        $content = prompt_version($db, $version);
        if ($content === null || $content === false) {
            shiduh_json(404, array('error' => 'Версия не найдена'));
        }
        $new_version = prompt_store($db, $content, $uid);
        if (!$new_version) {
            shiduh_json(500, array('error' => 'Не удалось откатить промпт'));
        }
        shiduh_json(200, array('ok' => true, 'version' => $new_version, 'restored_from' => $version));
        break;

    default:
        shiduh_json(400, array('error' => 'Неизвестное действие'));
}
