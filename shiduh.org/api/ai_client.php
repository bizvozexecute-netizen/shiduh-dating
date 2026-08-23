<?php
/*
 * Клиент LLM (ТЗ Э1.1). Единая точка обращения к модели: провайдер выбирается
 * настройкой, вызывающий код от него не зависит.
 *
 *   ai_chat(array $messages, array $options = array()) -> array(
 *       'ok'         => bool,
 *       'content'    => string,          текст ответа
 *       'tool_calls' => array,           вызовы инструментов (Э1.3)
 *       'usage'      => array(prompt_tokens, completion_tokens, total_tokens),
 *       'provider'   => string,
 *       'error'      => string|null,     понятная причина, если ok=false
 *   )
 *
 * Провайдеры:
 *   stub       — работает без ключа: отвечает заглушкой. Значение по умолчанию,
 *                чтобы сайт был исправен до подключения тарифа.
 *   openrouter — OpenAI-совместимый API (Э1.1-b). Ключ и base_url берутся из
 *                настроек NetCat (модуль 'ai'), не из кода.
 *
 * Настройки (Settings, Module='ai'): provider, api_key, base_url, model,
 * timeout, max_retries, temperature. Ключ в коде не хранится (Э1.1-c).
 */

define('AI_STUB_REPLY', 'Спасибо! Я сохранил ваше сообщение. Живой ИИ-консультант '
    . 'подключится на следующем этапе и поможет дозаполнить анкету в диалоге.');

/*
 * Системный промпт по умолчанию. В Э1.2 его заменит версионируемый промпт
 * `consultant_system` из ai_prompts (редактируется в админке); здесь —
 * разумная основа, чтобы с приходом ключа консультант сразу вёл себя верно,
 * а не как безличный ассистент.
 */
/*
 * НЕСМЕНЯЕМАЯ часть системного промпта: приписывается к любому промпту, в том
 * числе заданному администратором (Э1.2). Здесь только то, что защищает
 * персональные данные: здоровье, лекарства и доход — особая категория (152-ФЗ,
 * Раздел X), собирать их в переписке, уходящей во внешнюю модель, нельзя.
 * Если бы запрет жил только в редактируемом тексте, он исчез бы при первой же
 * правке промпта «под тон» — незаметно для администратора.
 */
define('AI_SAFETY_PREAMBLE',
    'Не спрашивайте у собеседника о здоровье, диагнозах, лекарствах, доходе и '
    . 'имуществе: эти сведения он указывает сам в закрытых полях анкеты. '
    . 'Если он заговорит об этом сам — не расспрашивайте и не уточняйте '
    . 'подробности, мягко предложите внести их в анкету. Не давайте '
    . 'медицинских, юридических и галахических заключений: по вопросам Закона '
    . 'предлагайте обратиться к раввину.');

/* Роль и тон по умолчанию — эту часть администратор может переписать. */
define('AI_DEFAULT_SYSTEM_PROMPT',
    'Вы — доброжелательный консультант еврейского сервиса знакомств. '
    . 'Ваша задача — в спокойной беседе помочь человеку рассказать о себе и '
    . 'дозаполнить анкету. Говорите уважительно и тепло, на «вы». '
    . 'Задавайте по одному вопросу за раз и дожидайтесь ответа. '
    . 'Уважайте религиозные чувства и традицию. '
    . 'Не выдумывайте факты о человеке.');

/*
 * Системный промпт для запроса: активная версия из ai_prompts (редактируется
 * в админке, Э1.2). Пока промпт не задан — встроенный текст по умолчанию,
 * чтобы консультант работал сразу «из коробки».
 */
function ai_system_prompt() {
    require_once dirname(__FILE__) . '/prompt_lib.php';
    $row = prompt_active(nc_core::get_object()->db);
    $editable = ($row && trim((string)$row['content']) !== '')
        ? (string)$row['content']
        : AI_DEFAULT_SYSTEM_PROMPT;
    // Преамбула безопасности добавляется всегда и не может быть снята правкой
    // промпта в админке — это требование закона, а не настройка.
    return AI_SAFETY_PREAMBLE . "

" . $editable;
}

function ai_config() {
    $nc = nc_core::get_object();
    // catalogue_id=0 — настройки общие для сайта, не зависят от текущего каталога
    $get = function ($key, $default) use ($nc) {
        $v = $nc->get_settings($key, 'ai', false, 0);
        return ($v === false || $v === '' || $v === null) ? $default : $v;
    };
    return array(
        'provider'    => (string)$get('provider', 'stub'),
        'api_key'     => (string)$get('api_key', ''),
        'base_url'    => rtrim((string)$get('base_url', 'https://openrouter.ai/api/v1'), '/'),
        'model'       => (string)$get('model', 'openai/gpt-4o-mini'),
        // Таймаут с запасом под шлюз: худший случай 3 попытки × 15с + 1.5с
        // пауз ≈ 46с — укладывается в типовые 60с фронтового прокси.
        'timeout'     => (int)$get('timeout', 15),
        'max_retries' => (int)$get('max_retries', 2),
        'temperature' => (float)$get('temperature', 0.7),
        // Сколько сообщений в час разрешено одному пользователю (Э4 добавит
        // тарифы; здесь — защита баланса от перебора и от угнанной сессии).
        'rate_limit_per_hour' => (int)$get('rate_limit_per_hour', 30),
    );
}

/*
 * Подробности ошибки нужны администратору, но не пользователю: текст от
 * провайдера может содержать фрагмент ключа и внутренние адреса, а ошибка
 * curl — хост и порт. Пишем детали в лог, наружу отдаём общее сообщение.
 */
function ai_log_error($detail) {
    error_log('[ai] ' . $detail);
}

function ai_result($ok, $content, $usage = null, $provider = 'stub', $error = null, $tool_calls = array()) {
    return array(
        'ok' => (bool)$ok,
        'content' => (string)$content,
        'tool_calls' => (array)$tool_calls,
        'usage' => $usage ? $usage : array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
        'provider' => (string)$provider,
        'error' => $error,
    );
}

/*
 * Один HTTP-вызов к OpenAI-совместимому API.
 * Возвращает array(http_code, body, curl_error).
 */
function ai_http_post($url, $payload, $api_key, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        // Редиректы не следуем: иначе заголовок с ключом можно увести на
        // чужой хост. Протоколы ограничиваем явно.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array($code, (string)$body, $err);
}

/*
 * Основной вызов. $messages — [['role'=>'user'|'assistant'|'system','content'=>...], ...]
 * $options: model, temperature, tools, timeout — перекрывают настройки.
 */
function ai_chat(array $messages, array $options = array()) {
    $cfg = ai_config();
    $provider = isset($options['provider']) ? $options['provider'] : $cfg['provider'];

    if ($provider !== 'openrouter') {
        // Заглушка: сайт работает и без ключа (Э0.2 → Э1 переходный режим).
        return ai_result(true, AI_STUB_REPLY, null, 'stub');
    }
    if ($cfg['api_key'] === '') {
        // Провайдер выбран, но ключ не задан — честно говорим, а не молчим.
        return ai_result(false, '', null, 'openrouter', 'Не задан ключ доступа к ИИ');
    }

    $payload = array(
        'model' => isset($options['model']) ? $options['model'] : $cfg['model'],
        'messages' => array_values($messages),
        'temperature' => isset($options['temperature']) ? $options['temperature'] : $cfg['temperature'],
    );
    if (!empty($options['tools'])) {
        $payload['tools'] = $options['tools'];
    }

    $url = $cfg['base_url'] . '/chat/completions';
    $timeout = isset($options['timeout']) ? (int)$options['timeout'] : $cfg['timeout'];
    $attempts = max(1, $cfg['max_retries'] + 1);
    $last_error = 'Сервис ИИ недоступен';

    for ($i = 0; $i < $attempts; $i++) {
        if ($i > 0) {
            // Бэкофф: 0.5s, 1s — не долбим провайдер и не держим пользователя долго
            usleep((int)(500000 * pow(2, $i - 1)));
        }
        list($code, $body, $curl_err) = ai_http_post($url, $payload, $cfg['api_key'], $timeout);

        if ($curl_err !== '') {
            // в тексте curl есть хост и порт провайдера — только в лог
            ai_log_error('curl: ' . $curl_err);
            $last_error = 'Сервис ИИ временно недоступен';
            continue;                       // сетевая ошибка — пробуем ещё раз
        }
        if ($code === 429 || $code >= 500) {
            ai_log_error('http ' . $code . ': ' . substr($body, 0, 500));
            $last_error = 'Сервис ИИ перегружен, попробуйте позже';
            continue;                       // временная ошибка — повтор
        }
        if ($code >= 400) {
            // 4xx — наша ошибка (ключ, модель, формат): повтор не поможет.
            // Текст провайдера может содержать фрагмент ключа — не показываем.
            ai_log_error('http ' . $code . ': ' . substr($body, 0, 500));
            return ai_result(false, '', null, 'openrouter',
                'Запрос к ИИ отклонён. Обратитесь к администратору сайта');
        }

        $js = json_decode($body, true);
        if (!is_array($js) || !isset($js['choices'][0]['message'])) {
            ai_log_error('unexpected body: ' . substr($body, 0, 500));
            $last_error = 'Неожиданный ответ сервиса ИИ';
            continue;
        }
        $msg = $js['choices'][0]['message'];
        $usage = isset($js['usage']) ? $js['usage'] : array();
        $prompt_tokens = (int)(isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0);
        $completion_tokens = (int)(isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0);
        $total_tokens = (int)(isset($usage['total_tokens']) ? $usage['total_tokens'] : 0);
        if (!$total_tokens) {
            // часть шлюзов отдаёт только слагаемые — иначе учёт был бы нулевым
            $total_tokens = $prompt_tokens + $completion_tokens;
        }
        return ai_result(
            true,
            isset($msg['content']) ? $msg['content'] : '',
            array(
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'total_tokens' => $total_tokens,
            ),
            'openrouter',
            null,
            isset($msg['tool_calls']) ? $msg['tool_calls'] : array()
        );
    }

    return ai_result(false, '', null, 'openrouter', $last_error);
}
