<?php
/*
 * Редактирование кейсов ценностного опроса (ROADMAP Э2.2-a).
 *
 * POST, только администратор. action:
 *   create  — dimension_group, dimension, scenario_text [, sort_order]
 *   update  — id, scenario_text [, dimension_group, dimension, sort_order]
 *   toggle  — id: включить/выключить кейс (мягкое удаление: выключенный
 *             не попадает в опрос, но история ответов остаётся осмысленной)
 *   delete  — id
 *
 * Экранирование строк — только prepare() (находка №35), id — (int).
 * CSRF — заслон по Origin/Referer до любых изменений.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/admin_lib.php';
require_once dirname(__FILE__) . '/cases_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

shiduh_check_origin();

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!shiduh_is_admin($db, $uid)) {
    shiduh_json(403, array('error' => 'Доступно только администратору'));
}

$action = (string)$nc_core->input->fetch_post('action');
$id     = (int)$nc_core->input->fetch_post('id');

/* Текст кейса: непустой, разумной длины. */
function cases_text($nc_core) {
    $text = trim((string)$nc_core->input->fetch_post('scenario_text'));
    if ($text === '') {
        shiduh_json(400, array('error' => 'Текст кейса обязателен'));
    }
    if (mb_strlen($text) > 2000) {
        shiduh_json(400, array('error' => 'Текст кейса длиннее 2000 символов'));
    }
    return $text;
}

/* Группа и измерение: группа — из известных, измерение — непустой ключ. */
function cases_dimension($nc_core) {
    $group = trim((string)$nc_core->input->fetch_post('dimension_group'));
    $dim   = trim((string)$nc_core->input->fetch_post('dimension'));
    if (!isset(cases_groups()[$group])) {
        shiduh_json(400, array('error' => 'Неизвестная группа измерений'));
    }
    if ($dim === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $dim)) {
        shiduh_json(400, array('error' => 'Измерение обязательно (латиница, цифры, _)'));
    }
    return array($group, $dim);
}

function cases_require_existing($db, $id) {
    if ($id <= 0 || !$db->get_var("SELECT `id` FROM `assessment_cases` WHERE `id`=$id")) {
        shiduh_json(404, array('error' => 'Кейс не найден'));
    }
}

switch ($action) {
    case 'create':
        list($group, $dim) = cases_dimension($nc_core);
        $text = cases_text($nc_core);
        $order = (int)$nc_core->input->fetch_post('sort_order');
        if ($order <= 0) {
            $order = (int)$db->get_var("SELECT IFNULL(MAX(`sort_order`),0)+1 FROM `assessment_cases`");
        }
        $db->query(
            "INSERT INTO `assessment_cases` SET "
            . "`dimension_group`='" . $db->prepare($group) . "', "
            . "`dimension`='" . $db->prepare($dim) . "', "
            . "`scenario_text`='" . $db->prepare($text) . "', "
            . "`active`=1, `sort_order`=$order, `created_at`=NOW()"
        );
        if ($db->is_error || !$db->insert_id) {
            shiduh_json(500, array('error' => 'Не удалось сохранить кейс'));
        }
        shiduh_json(200, array('ok' => true, 'id' => (int)$db->insert_id));
        break;

    case 'update':
        cases_require_existing($db, $id);
        $text = cases_text($nc_core);
        $set = "`scenario_text`='" . $db->prepare($text) . "', `updated_at`=NOW()";
        // группу/измерение меняем только если переданы обе части
        if (trim((string)$nc_core->input->fetch_post('dimension_group')) !== '') {
            list($group, $dim) = cases_dimension($nc_core);
            $set .= ", `dimension_group`='" . $db->prepare($group) . "'"
                  . ", `dimension`='" . $db->prepare($dim) . "'";
        }
        $order = (int)$nc_core->input->fetch_post('sort_order');
        if ($order > 0) {
            $set .= ", `sort_order`=$order";
        }
        $db->query("UPDATE `assessment_cases` SET $set WHERE `id`=$id");
        // Не рапортуем «сохранено», если запрос не прошёл: иначе админ уверен,
        // что правка на месте, а её нет (как в ветке create).
        if ($db->is_error) {
            shiduh_json(500, array('error' => 'Не удалось сохранить кейс'));
        }
        shiduh_json(200, array('ok' => true));
        break;

    case 'toggle':
        cases_require_existing($db, $id);
        $db->query("UPDATE `assessment_cases` SET `active`=1-`active`, `updated_at`=NOW() WHERE `id`=$id");
        if ($db->is_error) {
            shiduh_json(500, array('error' => 'Не удалось переключить кейс'));
        }
        $active = (int)$db->get_var("SELECT `active` FROM `assessment_cases` WHERE `id`=$id");
        shiduh_json(200, array('ok' => true, 'active' => $active));
        break;

    case 'delete':
        cases_require_existing($db, $id);
        $db->query("DELETE FROM `assessment_cases` WHERE `id`=$id");
        if ($db->is_error) {
            shiduh_json(500, array('error' => 'Не удалось удалить кейс'));
        }
        shiduh_json(200, array('ok' => true));
        break;

    default:
        shiduh_json(400, array('error' => 'Неизвестное действие'));
}
