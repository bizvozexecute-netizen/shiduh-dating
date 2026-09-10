<?php
/*
 * Настройки ИИ, капчи и безопасности — описание, чтение и проверка (C9).
 *
 * До этого ключ провайдера вводился только правкой таблицы Settings. Здесь
 * единый список полей с типами и границами: по нему и рисуется форма
 * admin-ai.php, и проверяется то, что она присылает. Секреты (ключ
 * провайдера, серверный секрет капчи) наружу не отдаются никогда — только
 * последние четыре символа, чтобы администратор понимал, какой ключ стоит.
 *
 * Хранение — Settings ядра (Module/Key, Catalogue_ID=0): те же строки, что
 * читают ai_config(), rag_config() и будущий captcha_lib.
 */

require_once dirname(__FILE__) . '/admin_lib.php';

/*
 * Поле: module, key, type, default, [min, max] / [enum], secret, hint.
 *   int/float — число в границах; bool — 0/1; url — http(s) без пути-мусора,
 *   пустая строка допустима, если 'empty' => true; text — строка ≤ 200;
 *   ids — список целых через запятую.
 */
function ai_settings_spec() {
    return array(
        // --- модель -------------------------------------------------------
        'provider' => array('module' => 'ai', 'type' => 'enum', 'enum' => array('stub', 'openrouter'),
            'default' => 'stub', 'title' => 'Провайдер',
            'hint' => 'stub — заглушка без обращения к модели; openrouter — живая модель по ключу'),
        'api_key' => array('module' => 'ai', 'type' => 'secret', 'default' => '', 'title' => 'Ключ API',
            'hint' => 'Пустое поле — оставить прежний ключ. Ключ никогда не показывается целиком'),
        'base_url' => array('module' => 'ai', 'type' => 'url', 'default' => 'https://openrouter.ai/api/v1',
            'title' => 'Адрес API', 'hint' => 'OpenAI-совместимый адрес, без /chat/completions на конце'),
        'model' => array('module' => 'ai', 'type' => 'text', 'default' => 'mistralai/mistral-small-2603', 'title' => 'Модель',
            'hint' => 'Идентификатор модели у провайдера. По живому прогону выбрана mistralai/mistral-small-2603; запасные: deepseek/deepseek-v4-flash (дешевле, но с задержками), qwen/qwen3.8-flash (живее, дороже). Модели с «рассуждением» (gpt-5-mini) не ставить'),
        'temperature' => array('module' => 'ai', 'type' => 'float', 'min' => 0, 'max' => 2, 'default' => 0.7,
            'title' => 'Температура', 'hint' => '0 — предсказуемо, 2 — очень свободно; для консультанта 0.5–0.8'),
        'max_tokens' => array('module' => 'ai', 'type' => 'int', 'min' => 50, 'max' => 4000, 'default' => 700,
            'title' => 'Потолок ответа, токенов', 'hint' => 'Длиннее — дороже; 700 хватает на 2–5 абзацев'),
        'timeout' => array('module' => 'ai', 'type' => 'int', 'min' => 3, 'max' => 120, 'default' => 15,
            'title' => 'Таймаут обычного вызова, с'),
        'stream_timeout' => array('module' => 'ai', 'type' => 'int', 'min' => 10, 'max' => 300, 'default' => 90,
            'title' => 'Таймаут потокового ответа, с'),
        'first_byte_timeout' => array('module' => 'ai', 'type' => 'int', 'min' => 3, 'max' => 60, 'default' => 20,
            'title' => 'Ожидание первого байта, с'),
        'max_retries' => array('module' => 'ai', 'type' => 'int', 'min' => 0, 'max' => 5, 'default' => 2,
            'title' => 'Повторов при сбое'),
        // --- пределы -------------------------------------------------------
        'rate_limit_per_hour' => array('module' => 'ai', 'type' => 'int', 'min' => 1, 'max' => 500, 'default' => 30,
            'title' => 'Сообщений в час на человека'),
        'burst_per_minute' => array('module' => 'ai', 'type' => 'int', 'min' => 1, 'max' => 60, 'default' => 6,
            'title' => 'Сообщений в минуту на человека'),
        'greet_with_llm' => array('module' => 'ai', 'type' => 'bool', 'default' => 0,
            'title' => 'Приветствие пишет модель', 'hint' => 'Иначе шаблон — бесплатно и предсказуемо'),
        // --- RAG -----------------------------------------------------------
        'rag_url' => array('module' => 'ai', 'type' => 'url', 'empty' => true, 'default' => '',
            'title' => 'Адрес RAG-сервиса', 'hint' => 'Пусто — фрагменты книг не подмешиваются'),
        'rag_top_k' => array('module' => 'ai', 'type' => 'int', 'min' => 1, 'max' => 10, 'default' => 3,
            'title' => 'Фрагментов на запрос'),
        'rag_timeout' => array('module' => 'ai', 'type' => 'int', 'min' => 1, 'max' => 30, 'default' => 3,
            'title' => 'Таймаут RAG, с'),
        // --- капча ---------------------------------------------------------
        'captcha_secret' => array('module' => 'captcha', 'key' => 'secret', 'type' => 'secret', 'default' => '',
            'title' => 'Серверный секрет SmartCaptcha',
            'hint' => 'Без него капча проверяется только в браузере — заслон декоративный'),
        'captcha_sitekey' => array('module' => 'captcha', 'key' => 'sitekey', 'type' => 'text', 'default' => '',
            'title' => 'Клиентский ключ SmartCaptcha'),
        'captcha_fail_open' => array('module' => 'captcha', 'key' => 'fail_open', 'type' => 'bool', 'default' => 1,
            'title' => 'Пропускать при недоступности сервиса капчи',
            'hint' => 'Иначе регистрация встанет вместе с Яндексом'),
        // --- безопасность --------------------------------------------------
        'superadmin_ids' => array('module' => 'security', 'type' => 'ids', 'default' => SHIDUH_SUPERADMIN_DEFAULT,
            'title' => 'Суперадминистраторы (ID через запятую)',
            'hint' => 'Менять список может только суперадминистратор; себя из списка убрать нельзя'),
    );
}

function ai_settings_key($name, $spec) {
    return isset($spec['key']) ? $spec['key'] : $name;
}

/*
 * Последние символы секрета — чтобы отличить один ключ от другого. У
 * короткого секрета хвост раскрыл бы заметную его часть, поэтому показываем
 * его только начиная с 12 символов; короче — просто «задан».
 */
function ai_settings_tail($value) {
    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value, 'UTF-8') < 12) {
        return '(задан)';
    }
    return '…' . mb_substr($value, -4, null, 'UTF-8');
}

/* http допустим только для loopback (dev-стенд, фейковый провайдер): по сети
   ключ должен идти только по TLS. */
function ai_settings_url_is_local($host) {
    $host = strtolower(trim((string)$host, '[]'));
    return $host === 'localhost' || $host === '::1' || preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
}

/*
 * Текущие значения для формы. Секреты подменяются хвостом и флагом
 * «задан» — в HTML и в JSON целиком они не попадают.
 */
function ai_settings_read() {
    $out = array();
    foreach (ai_settings_spec() as $name => $spec) {
        $raw = shiduh_setting($spec['module'], ai_settings_key($name, $spec), null);
        $value = ($raw === null || $raw === '') ? $spec['default'] : $raw;
        if ($spec['type'] === 'secret') {
            $out[$name] = array('set' => (string)$raw !== '', 'tail' => ai_settings_tail((string)$raw));
        } else {
            $out[$name] = $value;
        }
    }
    return $out;
}

/*
 * Проверка присланного. Возвращает [values, errors]: values — только
 * прошедшие проверку поля в хранимом виде; секрет с пустым значением
 * пропускается (значит «оставить как есть»). Неизвестные ключи игнорируются.
 */
function ai_settings_validate(array $input) {
    $values = array();
    $errors = array();
    foreach (ai_settings_spec() as $name => $spec) {
        if (!array_key_exists($name, $input)) {
            continue;
        }
        $v = $input[$name];
        if (!is_string($v) && !is_numeric($v)) {
            $errors[$name] = 'не строка';
            continue;
        }
        $v = trim((string)$v);
        switch ($spec['type']) {
            case 'secret':
                if ($v === '') {
                    continue 2;           // пустое — оставить прежний
                }
                if (preg_match('/\s/u', $v) || mb_strlen($v, 'UTF-8') > 200 || mb_strlen($v, 'UTF-8') < 8) {
                    $errors[$name] = 'ключ: от 8 до 200 символов, без пробелов';
                    continue 2;
                }
                $values[$name] = $v;
                break;
            case 'enum':
                if (!in_array($v, $spec['enum'], true)) {
                    $errors[$name] = 'допустимо: ' . implode(', ', $spec['enum']);
                    continue 2;
                }
                $values[$name] = $v;
                break;
            case 'int':
            case 'float':
                if (!is_numeric($v)) {
                    $errors[$name] = 'нужно число';
                    continue 2;
                }
                $n = $spec['type'] === 'int' ? (int)$v : (float)$v;
                if ($n < $spec['min'] || $n > $spec['max']) {
                    $errors[$name] = 'от ' . $spec['min'] . ' до ' . $spec['max'];
                    continue 2;
                }
                $values[$name] = (string)$n;
                break;
            case 'bool':
                $values[$name] = ($v === '1' || $v === 'on' || $v === 'true') ? '1' : '0';
                break;
            case 'url':
                if ($v === '') {
                    if (empty($spec['empty'])) {
                        $errors[$name] = 'адрес обязателен';
                        continue 2;
                    }
                    $values[$name] = '';
                    break;
                }
                // Только http(s), без учётных данных (ключ в URL — утечка в логах),
                // без query и якоря: к адресу дописывается путь вызова.
                $p = parse_url($v);
                if (!$p || empty($p['scheme']) || empty($p['host'])
                    || !in_array(strtolower($p['scheme']), array('http', 'https'), true)
                    || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query'])) {
                    $errors[$name] = 'нужен адрес вида https://хост/путь, без ?параметров';
                    continue 2;
                }
                // http — только на loopback (dev-стенд): по сети ключ идёт по TLS
                if (strtolower($p['scheme']) === 'http' && !ai_settings_url_is_local($p['host'])) {
                    $errors[$name] = 'вне localhost допустим только https';
                    continue 2;
                }
                if (mb_strlen($v, 'UTF-8') > 200) {
                    $errors[$name] = 'слишком длинный адрес';
                    continue 2;
                }
                $v = rtrim($v, '/');
                // подсказка просит адрес без /chat/completions; если вставили с ним — срежем
                $v = preg_replace('~/chat/completions$~', '', $v);
                $values[$name] = $v;
                break;
            case 'ids':
                $ids = array();
                foreach (explode(',', $v) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    if (!ctype_digit($part) || (int)$part <= 0) {
                        $errors[$name] = 'только целые ID через запятую';
                        continue 3;
                    }
                    $ids[] = (int)$part;
                }
                if (!$ids) {
                    $errors[$name] = 'нужен хотя бы один ID';
                    continue 2;
                }
                $values[$name] = implode(',', array_values(array_unique($ids)));
                break;
            default:   // text
                if (mb_strlen($v, 'UTF-8') > 200 || preg_match('/[\r\n]/', $v)) {
                    $errors[$name] = 'строка до 200 символов без переносов';
                    continue 2;
                }
                $values[$name] = $v;
        }
    }
    return array($values, $errors);
}

/*
 * Запись проверенных значений через ядро (обновляет и его кэш настроек).
 * Возвращает имена записанных полей или false при ошибке базы: set_settings
 * ошибок не возвращает, а молча «сохранённое» поле хуже честного отказа.
 */
function ai_settings_save(array $values) {
    $nc = nc_core::get_object();
    $spec_all = ai_settings_spec();
    $saved = array();
    foreach ($values as $name => $v) {
        if (!isset($spec_all[$name])) {
            continue;
        }
        $spec = $spec_all[$name];
        $nc->set_settings(ai_settings_key($name, $spec), $v, $spec['module'], 0);
        if ($nc->db->is_error) {
            shiduh_log('admin', 'не записана настройка ' . $name . ': ' . (string)$nc->db->last_error);
            return false;
        }
        $saved[] = $name;
    }
    return $saved;
}
