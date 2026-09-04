<?php
/*
 * Сохранение настроек ИИ / капчи / безопасности (C9).
 *
 * POST, только администратор, CSRF-заслон. Принимает только поля из
 * ai_settings_spec() и только в допустимых границах; при любой ошибке не
 * пишет ничего (частичное сохранение оставило бы форму в состоянии, которое
 * администратор не видел). Секреты в ответ не возвращаются — только хвост.
 */
$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once dirname(__FILE__) . '/admin_lib.php';
shiduh_bootstrap_api();
require_once $ROOT_FOLDER . 'connect_io.php';
require_once dirname(__FILE__) . '/ai_settings_lib.php';

$nc_core = nc_core::get_object();
$nc_core->user->attempt_to_authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shiduh_json(405, array('error' => 'Метод не поддерживается'));
}

global $current_user;
$db  = $nc_core->db;
$uid = (int)(isset($current_user['User_ID']) ? $current_user['User_ID'] : 0);
if (!$uid) {
    shiduh_json(403, array('error' => 'Требуется авторизация'));
}
shiduh_csrf_check($uid);
shiduh_require_admin($db, $uid);

$input = array();
foreach (array_keys(ai_settings_spec()) as $name) {
    $v = $nc_core->input->fetch_post($name);
    if ($v !== null && $v !== false) {
        // ядро эмулирует magic quotes для глобалов, но fetch_post отдаёт
        // сырые данные — экранирование делает prepare() при записи
        $input[$name] = $v;
    }
}
if (!$input) {
    shiduh_json(400, array('error' => 'Нечего сохранять', 'code' => 'empty'));
}

list($values, $errors) = ai_settings_validate($input);

$before = ai_settings_read();
$current_key = (string)shiduh_setting('ai', 'api_key', '');
$current_base = (string)shiduh_setting('ai', 'base_url', 'https://openrouter.ai/api/v1');

/*
 * Суперадминистраторов меняет только суперадминистратор — иначе любой
 * супервизор вписал бы себя. И себя из списка убрать нельзя: иначе один
 * неверный клик оставляет сайт без суперадминистраторов.
 */
if (isset($values['superadmin_ids'])) {
    if (!shiduh_is_superadmin($uid)) {
        shiduh_json(403, array('error' => 'Список суперадминистраторов меняет только суперадминистратор',
            'code' => 'not_superadmin'));
    }
    if (!in_array((string)$uid, explode(',', $values['superadmin_ids']), true)) {
        $errors['superadmin_ids'] = 'нельзя убрать себя из списка';
    }
}

/*
 * Провайдер openrouter без ключа — заведомо нерабочая настройка: консультант
 * начнёт отвечать ошибками. Ключ должен быть либо уже сохранён, либо
 * прислан сейчас.
 */
$provider_after = isset($values['provider']) ? $values['provider'] : $before['provider'];
$key_after = isset($values['api_key']) ? $values['api_key'] : $current_key;
if ($provider_after === 'openrouter' && $key_after === '' && !isset($errors['api_key'])) {
    $errors['api_key'] = 'для провайдера openrouter нужен ключ';
}

/*
 * Смена ХОСТА адреса API обнуляет ключ, если новый ключ не прислан вместе с
 * адресом. Иначе тот, кто может править адрес, уводил бы ключ заказчика на
 * свой хост, не зная его: следующий же запрос ушёл бы туда с Bearer.
 * Тот, кто меняет адрес, обязан знать ключ.
 */
$key_reset = false;
if (isset($values['base_url']) && !isset($values['api_key'])) {
    $old_host = strtolower((string)parse_url($current_base, PHP_URL_HOST));
    $new_host = strtolower((string)parse_url($values['base_url'], PHP_URL_HOST));
    if ($old_host !== $new_host && $current_key !== '') {
        $values['api_key'] = '';
        $key_reset = true;
        if ($provider_after === 'openrouter') {
            // без ключа openrouter не работает — переводим на заглушку явно
            $values['provider'] = 'stub';
        }
    }
}

if ($errors) {
    shiduh_json(400, array('error' => 'Проверьте поля', 'code' => 'invalid', 'fields' => $errors));
}
if (!$values) {
    shiduh_json(400, array('error' => 'Нечего сохранять', 'code' => 'empty'));
}

$saved = ai_settings_save($values);
if ($saved === false) {
    shiduh_json(500, array('error' => 'Не удалось записать настройки', 'code' => 'db'));
}

// В журнал — имена полей, а для адресов и провайдера ещё и «было → стало»:
// это не секреты, а без них увод ключа нечем расследовать.
$trail = array();
foreach (array('provider', 'base_url', 'rag_url') as $name) {
    if (isset($values[$name]) && (string)$before[$name] !== (string)$values[$name]) {
        $trail[] = $name . ': ' . $before[$name] . ' → ' . $values[$name];
    }
}
shiduh_log('admin', 'настройки ИИ изменены пользователем ' . $uid . ': ' . implode(', ', $saved)
    . ($trail ? ' [' . implode('; ', $trail) . ']' : '') . ($key_reset ? ' [ключ сброшен из-за смены хоста]' : ''));

$now = ai_settings_read();
shiduh_json(200, array(
    'ok' => true,
    'saved' => $saved,
    'key_reset' => $key_reset,
    'note' => $key_reset ? 'Адрес API изменён — ключ сброшен, введите его заново.' : '',
    'api_key' => $now['api_key'],
    'captcha_secret' => $now['captcha_secret'],
));
