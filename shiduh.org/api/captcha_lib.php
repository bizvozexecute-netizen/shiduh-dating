<?php
/*
 * Серверная проверка SmartCaptcha (этап «До идеала», C7).
 *
 * В формах уже стоит виджет Яндекса (`.form__captcha`, бандл рендерит его и
 * кладёт в форму скрытое поле `smart-token`), но проверялся он ТОЛЬКО в
 * браузере: кто шлёт POST напрямую (регистрация ботами, спам в обратную
 * связь), капчу просто не замечал. Здесь — проверка токена на сервере через
 * API Яндекса; без серверного секрета заслон декоративен.
 *
 * Ключи в Settings (Module='captcha'): secret, sitekey, fail_open,
 * validate_url — задаются на admin-ai.php (C9). Пока секрет пуст, проверка
 * пропускает всех (ok=true, code=skipped): регистрация не должна вставать
 * из-за ненастроенной капчи. Предупреждение в лог — не чаще раза в сутки.
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_setting(), shiduh_log()

define('CAPTCHA_VALIDATE_URL', 'https://smartcaptcha.yandexcloud.net/validate');

/*
 * Адрес API берётся из Settings, но только если это домен Яндекса или
 * loopback (dev-фейк): туда уходит секрет POST-ом, и подменив адрес на свой
 * сервер, тот, кто пишет в Settings, увёл бы секрет и вернул бы «status:ok»
 * на любой токен — полный обход. Чужой хост → принудительно домен Яндекса.
 */
/* Разрешён ли хост адреса проверки: домен Яндекса или loopback (dev-фейк). */
function captcha_host_allowed($url) {
    $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
    return $host === 'smartcaptcha.yandexcloud.net'
        || (bool)preg_match('/(^|\.)yandexcloud\.net$/', $host)   // домен Яндекса и поддомены
        || $host === 'localhost' || $host === '::1'
        || (bool)preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
}

function captcha_validate_url() {
    $url = (string)shiduh_setting('captcha', 'validate_url', CAPTCHA_VALIDATE_URL);
    return captcha_host_allowed($url) ? $url : CAPTCHA_VALIDATE_URL;
}

function captcha_config() {
    return array(
        'secret'    => (string)shiduh_setting('captcha', 'secret', ''),
        'validate_url' => captcha_validate_url(),
        // короткий таймаут: каждый прямой спам-POST держит воркер до ответа,
        // 2 c — компромисс между DoS-амплификацией и медленной сетью
        'timeout'   => 2,
        // fail_open: недоступен сервис Яндекса — пропускать (иначе регистрация
        // встанет вместе с ним). По умолчанию да; на admin-ai.php переключается.
        'fail_open' => (int)shiduh_setting('captcha', 'fail_open', 1) === 1,
    );
}

/* POST form-encoded → [http_code, body, curl_error]. */
function captcha_http_post($url, array $fields, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        // явный '&': NetCat ставит arg_separator.output='&amp;', и без этого
        // тело запроса склеилось бы через &amp; — приёмник прочитал бы ключ
        // «amp;token» вместо «token», и токен потерялся бы
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array($code, (string)$body, $err);
}

/* Предупреждение «капча не настроена» — не чаще раза в сутки на процесс/лог. */
function captcha_warn_unconfigured() {
    static $warned = false;
    if ($warned) {
        return;
    }
    $warned = true;
    $stamp = sys_get_temp_dir() . '/shiduh_captcha_unconfigured.stamp';
    $last = @filemtime($stamp);
    if ($last && (time() - $last) < 86400) {
        return;
    }
    @touch($stamp);
    shiduh_log('captcha', 'серверный секрет SmartCaptcha не задан — капча проверяется только в браузере');
}

/*
 * Проверка токена. Возвращает array('ok'=>bool, 'code'=>string).
 *   skipped     — секрет не задан, пропускаем (и предупреждаем в лог);
 *   no_token    — секрет есть, а токена нет: не человек прошёл виджет;
 *   ok          — Яндекс подтвердил;
 *   failed      — Яндекс отклонил;
 *   fail_open   — сервис недоступен, но настроено пропускать;
 *   unreachable — сервис недоступен и пропускать нельзя.
 */
function captcha_verify($token, $ip, $cfg = null) {
    $cfg = $cfg ?: captcha_config();
    if ($cfg['secret'] === '') {
        captcha_warn_unconfigured();
        return array('ok' => true, 'code' => 'skipped');
    }
    $token = (string)$token;
    if ($token === '') {
        return array('ok' => false, 'code' => 'no_token');
    }
    list($code, $body, $err) = captcha_http_post($cfg['validate_url'], array(
        'secret' => $cfg['secret'],
        'token'  => $token,
        'ip'     => (string)$ip,
    ), $cfg['timeout']);

    if ($code === 200) {
        $js = json_decode($body, true);
        if (is_array($js) && isset($js['status'])) {
            return array('ok' => $js['status'] === 'ok',
                'code' => $js['status'] === 'ok' ? 'ok' : 'failed');
        }
    }
    // недоступен или ответил мусором: код в лог. Текст curl_error НЕ пишем —
    // при DNS/connect-ошибке он содержит хост из validate_url
    shiduh_log('captcha', 'проверка не удалась: http ' . $code);
    return $cfg['fail_open']
        ? array('ok' => true, 'code' => 'fail_open')
        : array('ok' => false, 'code' => 'unreachable');
}

/*
 * Заслон для AddCond формы: если проверка не прошла — отменяет запись
 * ($posting=0) и пишет $warnText. Токен и IP берёт из запроса сам.
 * Ничего не делает для админа/уже вошедшего: капча — от ботов на публичных
 * формах, а не от авторизованных.
 */
function captcha_guard_addcond(&$warnText, &$posting) {
    global $current_user;
    if (!empty($current_user['User_ID'])) {
        return;
    }
    // $_POST напрямую: имя поля с дефисом (smart-token) не проходит через
    // fetch_post/эмуляцию register_globals. В SQL значение не идёт — только
    // form-urlencoded в запрос к Яндексу, там оно экранируется.
    $token = isset($_POST['smart-token']) ? (string)$_POST['smart-token'] : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $res = captcha_verify($token, $ip);
    if (!$res['ok']) {
        $posting = 0;
        $warnText = $res['code'] === 'no_token'
            ? 'Пожалуйста, подтвердите, что вы не робот, и отправьте форму ещё раз.'
            : 'Проверка «я не робот» не пройдена. Обновите страницу и попробуйте снова.';
    }
}
