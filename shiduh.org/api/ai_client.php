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

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

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

/*
 * Роль и тон по умолчанию — эту часть администратор может переписать.
 *
 * Текст приведён к тому, что просило ТЗ (Э1.2): понятная человеку
 * терминология традиции, живой диалог вместо анкетного опроса, вопрос по
 * одному. Добавлены два правила, которых не хватало на практике: не
 * рапортовать о сохранении в анкету (это техническая подробность, в беседе
 * она лишняя) и спрашивать разрешения, прежде чем менять уже заполненное
 * поле. Подстрока «консультант еврейского сервиса знакомств» сохранена
 * намеренно: на неё опираются проверки того, что промпт дошёл до модели.
 */
define('AI_DEFAULT_SYSTEM_PROMPT',
    'Вы — мудрый, доброжелательный консультант еврейского сервиса знакомств. '
    . 'Ваша задача — в живой беседе помочь человеку рассказать о себе и '
    . 'дозаполнить анкету, чтобы шадхану было проще подобрать пару. '
    . 'Говорите уважительно и тепло, на «вы». Используйте понятную человеку '
    . 'терминологию традиции — Шаббат, кашрут, хупа, мицва — и при '
    . 'необходимости коротко поясняйте её. Не задавайте вопросы списком: '
    . 'один вопрос за раз, дожидайтесь ответа и отталкивайтесь от сказанного. '
    . 'Когда собеседник рассказал что-то, что относится к анкете, сохраните '
    . 'это инструментом, но не докладывайте о сохранении — просто продолжайте '
    . 'разговор. Если поле уже заполнено другим значением, спросите: '
    . '«У вас указано X, поменять на Y?» — и меняйте только после явного '
    . 'согласия. Уважайте религиозные чувства и традицию. '
    . 'Не выдумывайте факты о человеке. '
    . 'Отвечайте кратко, обычно 2–5 предложений.');

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
        // Ядро отвечает array() (а не false), когда у модуля нет ни одной
        // строки настроек: без этой проверки каждый ключ становился массивом,
        // и на экране появлялось «Провайдер сейчас: Array».
        return (!is_scalar($v) || $v === false || $v === '') ? $default : $v;
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
        // Часовой лимит не спасает от залпа: тридцать сообщений можно
        // отправить за секунду. Поминутный ограничивает именно темп.
        'burst_per_minute' => (int)$get('burst_per_minute', 6),
        // Поток живёт дольше обычного вызова: модель пишет ответ постепенно.
        'stream_timeout' => (int)$get('stream_timeout', 90),
        // Молчание дольше этого — обрыв: покрывает и «нет первого байта»,
        // и «поток завис на середине».
        'first_byte_timeout' => (int)$get('first_byte_timeout', 20),
        // Потолок ответа. Без него длинная генерация упирается в таймаут, и
        // теряется весь вызов вместе с уже оплаченными токенами. 700 хватает
        // на живой ответ в 2–5 предложений с запасом.
        'max_tokens'  => (int)$get('max_tokens', 700),
        // Приветствие по умолчанию шаблонное: оно предсказуемо и бесплатно.
        // Настройкой можно доверить его модели.
        'greet_with_llm' => (int)$get('greet_with_llm', 0),
    );
}

/*
 * Журнал обращений к модели.
 *
 * Расход считался только как tokens_used в строке ответа чата: сколько ушло
 * на резюме, обоснования пар и разбор ответов опроса — узнать было нельзя.
 * Теперь строка пишется на КАЖДЫЙ вызов, включая заглушку и неудачные
 * попытки: иначе по журналу не отличить «не обращались» от «обращались и
 * получили отказ».
 *
 * Пишем из одного места (ai_chat), чтобы ни один вызывающий не мог «забыть»
 * учёт. Текст ошибки обрезается и очищается от фрагментов ключа: журнал
 * показывается администратору в веб-интерфейсе.
 */
/*
 * Чистка 4-байтных символов (эмодзи). Колонки переписки в utf8 (3 байта):
 * MySQL молча обрезает строку по первому такому символу, теряя весь хвост
 * сообщения. Чистим до записи и до отправки в модель, чтобы сохранённое и
 * показанное совпадали.
 */
function ai_strip_4byte($text) {
    $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string)$text);
    if ($clean === null) {
        // битый UTF-8 — режем жёстче, до печатаемых и кириллицы
        $clean = preg_replace('/[^\x20-\x7E\x{0400}-\x{04FF}\s]/u', '', (string)$text);
    }
    return (string)$clean;
}

function ai_usage_scrub($text) {
    $text = preg_replace('/sk-[A-Za-z0-9_\-*]{4,}/', 'sk-***', (string)$text);
    return mb_substr(trim((string)$text), 0, 200);
}

function ai_usage_log_write(array $row) {
    $db = nc_core::get_object()->db;
    if (!$db) {
        return;
    }
    $kinds = array('chat', 'greet', 'summary', 'explain', 'classify', 'psychotype', 'other');
    $kind = (isset($row['kind']) && in_array($row['kind'], $kinds, true)) ? $row['kind'] : 'other';
    $num = function ($k) use ($row) {
        return (int)(isset($row[$k]) ? $row[$k] : 0);
    };

    $db->query(
        "INSERT INTO `ai_usage_log` SET "
        . "`user_id`=" . $num('user_id') . ", "
        . "`kind`='" . $db->prepare($kind) . "', "
        . "`provider`='" . $db->prepare(mb_substr((string)$row['provider'], 0, 16)) . "', "
        . "`model`='" . $db->prepare(mb_substr((string)$row['model'], 0, 64)) . "', "
        . "`prompt_tokens`=" . $num('prompt_tokens') . ", "
        . "`completion_tokens`=" . $num('completion_tokens') . ", "
        . "`total_tokens`=" . $num('total_tokens') . ", "
        . "`latency_ms`=" . $num('latency_ms') . ", "
        . "`attempts`=" . max(1, $num('attempts')) . ", "
        . "`streamed`=" . (int)(!empty($row['streamed'])) . ", "
        . "`ok`=" . (int)(!empty($row['ok'])) . ", "
        . "`error`='" . $db->prepare(ai_usage_scrub(isset($row['error']) ? $row['error'] : '')) . "', "
        . "`created_at`=NOW()"
    );
    if ($db->is_error) {
        // Учёт не должен ронять ответ пользователю
        shiduh_log('ai', 'журнал расхода не записан: ' . $db->last_error);
    }
}

/*
 * Подробности ошибки нужны администратору, но не пользователю: текст от
 * провайдера может содержать фрагмент ключа и внутренние адреса, а ошибка
 * curl — хост и порт. Пишем детали в лог, наружу отдаём общее сообщение.
 */
function ai_log_error($detail) {
    shiduh_log('ai', $detail);
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
    $model = isset($options['model']) ? $options['model'] : $cfg['model'];

    // Учёт расхода: замыкание вокруг ЛЮБОГО выхода из функции, чтобы ни одна
    // ветка (заглушка, отсутствие ключа, отказ после ретраев) не осталась
    // неучтённой. $attempts_done обновляется в цикле ниже.
    $t0 = microtime(true);
    $attempts_done = 0;
    $finish = function ($result) use (&$attempts_done, $t0, $options, $provider, $model) {
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : array();
        ai_usage_log_write(array(
            'user_id' => isset($options['user_id']) ? $options['user_id'] : 0,
            'kind' => isset($options['kind']) ? $options['kind'] : 'other',
            'provider' => isset($result['provider']) ? $result['provider'] : $provider,
            'model' => $provider === 'openrouter' ? $model : '',
            'prompt_tokens' => isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0,
            'completion_tokens' => isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0,
            'total_tokens' => isset($usage['total_tokens']) ? $usage['total_tokens'] : 0,
            'latency_ms' => (int)round((microtime(true) - $t0) * 1000),
            'attempts' => max(1, $attempts_done),
            'streamed' => !empty($options['stream']),
            'ok' => !empty($result['ok']),
            'error' => isset($result['error']) ? $result['error'] : '',
        ));
        return $result;
    };

    if ($provider !== 'openrouter') {
        // Заглушка: сайт работает и без ключа (Э0.2 → Э1 переходный режим).
        return $finish(ai_result(true, AI_STUB_REPLY, null, 'stub'));
    }
    if ($cfg['api_key'] === '') {
        // Провайдер выбран, но ключ не задан — честно говорим, а не молчим.
        return $finish(ai_result(false, '', null, 'openrouter', 'Не задан ключ доступа к ИИ'));
    }

    // Тело собирает общая функция: иначе обычный и потоковый режимы со
    // временем разъедутся по параметрам.
    $payload = ai_payload_for($messages, $options, $cfg, $model, false);

    $url = $cfg['base_url'] . '/chat/completions';
    $timeout = isset($options['timeout']) ? (int)$options['timeout'] : $cfg['timeout'];
    $attempts = max(1, $cfg['max_retries'] + 1);
    $last_error = 'Сервис ИИ недоступен';

    for ($i = 0; $i < $attempts; $i++) {
        $attempts_done = $i + 1;
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
            return $finish(ai_result(false, '', null, 'openrouter',
                'Запрос к ИИ отклонён. Обратитесь к администратору сайта'));
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
        return $finish(ai_result(
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
        ));
    }

    return $finish(ai_result(false, '', null, 'openrouter', $last_error));
}


/* =========================== потоковая выдача ===========================
   ТЗ (Э0.2) просит показывать ответ по мере генерации. Провайдер отдаёт его
   потоком SSE: строки «data: {…}» с кусочками текста. Разбор вынесен в
   отдельные функции без сети — так его можно проверить на записанных данных,
   не поднимая ни модель, ни сервер.

   Результат намеренно той же формы, что у ai_chat(): вызывающему всё равно,
   пришёл ответ целиком или частями.                                       */

function ai_stream_state_new() {
    return array(
        'buf' => '',          // хвост незавершённой строки
        'content' => '',      // накопленный текст ответа
        'tools' => array(),   // вызовы инструментов по индексу
        'usage' => null,
        'finish' => null,
        'done' => false,      // пришло «[DONE]»
        'error' => null,      // провайдер прислал ошибку прямо в потоке
        'emitted' => false,   // хоть один кусок текста уже отдан наружу
        'aborted' => false,   // получатель попросил прекратить
    );
}

/*
 * Скармливает очередную порцию байтов. Возвращает false, если получатель
 * попросил остановиться. Границы кусков произвольны: строка может прийти
 * разрезанной пополам, поэтому хвост копится в буфере.
 */
function ai_stream_feed(&$st, $bytes, $on_delta = null) {
    $st['buf'] .= (string)$bytes;

    while (($pos = strpos($st['buf'], "\n")) !== false) {
        $line = rtrim(substr($st['buf'], 0, $pos), "\r");
        $st['buf'] = substr($st['buf'], $pos + 1);

        if ($line === '' || $line[0] === ':') {
            continue;                       // пустая строка или комментарий
        }
        if (strncmp($line, 'data:', 5) !== 0) {
            continue;                       // event:/id: нам не нужны
        }
        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            $st['done'] = true;
            continue;
        }
        $js = json_decode($data, true);
        if (!is_array($js)) {
            ai_log_error('stream: не разобран кусок ' . substr($data, 0, 120));
            continue;
        }
        if (isset($js['error'])) {
            $st['error'] = isset($js['error']['message'])
                ? (string)$js['error']['message'] : 'ошибка в потоке';
            continue;
        }
        if (isset($js['usage']) && is_array($js['usage'])) {
            $st['usage'] = $js['usage'];
        }

        $choice = isset($js['choices'][0]) ? $js['choices'][0] : null;
        if (!is_array($choice)) {
            continue;
        }
        if (!empty($choice['finish_reason'])) {
            $st['finish'] = (string)$choice['finish_reason'];
        }
        $delta = isset($choice['delta']) && is_array($choice['delta']) ? $choice['delta'] : array();

        if (isset($delta['content']) && $delta['content'] !== '') {
            // чистим эмодзи сразу: показанное и сохранённое должны совпадать
            $piece = ai_strip_4byte($delta['content']);
            if ($piece !== '') {
                $st['content'] .= $piece;
                $st['emitted'] = true;
                if ($on_delta && call_user_func($on_delta, $piece) === false) {
                    $st['aborted'] = true;
                    return false;
                }
            }
        }

        // Вызовы инструментов приходят по частям: имя один раз, аргументы
        // кусками. Склеиваем по индексу, иначе JSON аргументов не соберётся.
        if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $i = isset($tc['index']) ? (int)$tc['index'] : 0;
                if (!isset($st['tools'][$i])) {
                    $st['tools'][$i] = array('id' => '', 'type' => 'function',
                        'function' => array('name' => '', 'arguments' => ''));
                }
                if (!empty($tc['id'])) {
                    $st['tools'][$i]['id'] = (string)$tc['id'];
                }
                if (!empty($tc['function']['name'])) {
                    $st['tools'][$i]['function']['name'] = (string)$tc['function']['name'];
                }
                if (isset($tc['function']['arguments'])) {
                    $st['tools'][$i]['function']['arguments'] .= (string)$tc['function']['arguments'];
                }
            }
        }
    }
    return true;
}

/* Собранные вызовы инструментов — в том же виде, что у обычного ответа. */
function ai_stream_tool_calls($st) {
    $out = array();
    foreach ($st['tools'] as $call) {
        if ($call['function']['name'] !== '') {
            $out[] = $call;
        }
    }
    return $out;
}

/*
 * Потоковый вызов модели. $on_delta получает каждый кусочек текста и может
 * вернуть false — тогда соединение с провайдером обрывается (пользователь
 * нажал «Остановить» или ушёл со страницы: платить за досказывание незачем).
 */
function ai_chat_stream(array $messages, array $options, $on_delta) {
    $cfg = ai_config();
    $provider = isset($options['provider']) ? $options['provider'] : $cfg['provider'];
    $model = isset($options['model']) ? $options['model'] : $cfg['model'];
    $options['stream'] = true;

    $t0 = microtime(true);
    $attempts_done = 0;
    $finish = function ($result) use (&$attempts_done, $t0, $options, $provider, $model) {
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : array();
        ai_usage_log_write(array(
            'user_id' => isset($options['user_id']) ? $options['user_id'] : 0,
            'kind' => isset($options['kind']) ? $options['kind'] : 'other',
            'provider' => isset($result['provider']) ? $result['provider'] : $provider,
            'model' => $provider === 'openrouter' ? $model : '',
            'prompt_tokens' => isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0,
            'completion_tokens' => isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0,
            'total_tokens' => isset($usage['total_tokens']) ? $usage['total_tokens'] : 0,
            'latency_ms' => (int)round((microtime(true) - $t0) * 1000),
            'attempts' => max(1, $attempts_done),
            'streamed' => true,
            'ok' => !empty($result['ok']),
            'error' => isset($result['error']) ? $result['error'] : '',
        ));
        return $result;
    };

    // Заглушка тоже «стримит»: интерфейс должен работать и выглядеть
    // одинаково до подключения ключа.
    if ($provider !== 'openrouter') {
        $words = preg_split('/(?<=\s)/u', AI_STUB_REPLY);
        $chunk = '';
        foreach ($words as $w) {
            $chunk .= $w;
            if (mb_strlen($chunk, 'UTF-8') >= 12) {
                if (call_user_func($on_delta, $chunk) === false) {
                    break;
                }
                $chunk = '';
            }
        }
        if ($chunk !== '') {
            call_user_func($on_delta, $chunk);
        }
        return $finish(ai_result(true, AI_STUB_REPLY, null, 'stub'));
    }
    if ($cfg['api_key'] === '') {
        return $finish(ai_result(false, '', null, 'openrouter', 'Не задан ключ доступа к ИИ'));
    }

    $payload = ai_payload_for($messages, $options, $cfg, $model, true);
    $url = $cfg['base_url'] . '/chat/completions';
    $attempts = max(1, $cfg['max_retries'] + 1);
    $last_error = 'Сервис ИИ недоступен';

    for ($i = 0; $i < $attempts; $i++) {
        $attempts_done = $i + 1;
        if ($i > 0) {
            usleep((int)(500000 * pow(2, $i - 1)));
        }

        $st = ai_stream_state_new();
        $http_code = 0;
        $err_body = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer ' . $cfg['api_key'],
            ),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, (int)$cfg['timeout']),
            CURLOPT_TIMEOUT => (int)$cfg['stream_timeout'],
            // обрыв, если провайдер замолчал: покрывает и «нет первого байта»,
            // и «поток завис на середине»
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => (int)$cfg['first_byte_timeout'],
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$st, &$http_code, &$err_body, $on_delta) {
                if (!$http_code) {
                    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                if ($http_code >= 400) {
                    $err_body .= $data;      // это не поток, а текст ошибки
                    return strlen($data);
                }
                if (!ai_stream_feed($st, $data, $on_delta)) {
                    return 0;                // получатель попросил остановиться
                }
                return strlen($data);
            },
        ));
        curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_err = curl_error($ch);
        if (!$http_code) {
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        curl_close($ch);

        // Прерывание получателем — не ошибка: отдаём то, что уже показано.
        if (!empty($st['aborted'])) {
            $res = ai_result(true, $st['content'], ai_usage_normalize($st['usage']),
                'openrouter', null, ai_stream_tool_calls($st));
            $res['aborted'] = true;
            return $finish($res);
        }

        if ($http_code >= 400) {
            ai_log_error('stream http ' . $http_code . ': ' . substr($err_body, 0, 500));
            if ($http_code === 429 || $http_code >= 500) {
                $last_error = 'Сервис ИИ перегружен, попробуйте позже';
                continue;                    // временная — повтор
            }
            return $finish(ai_result(false, '', null, 'openrouter',
                'Запрос к ИИ отклонён. Обратитесь к администратору сайта'));
        }

        if ($st['error'] !== null) {
            // Ошибка пришла ПОСРЕДИ выдачи. Повторять нельзя: часть ответа
            // уже показана человеку, и второй прогон дал бы другой текст.
            ai_log_error('stream error: ' . $st['error']);
            $res = ai_result(false, $st['content'], ai_usage_normalize($st['usage']),
                'openrouter', 'Ответ оборвался. Попробуйте ещё раз');
            $res['partial'] = true;
            return $finish($res);
        }

        if ($curl_errno) {
            ai_log_error('stream curl: ' . $curl_err);
            if ($st['emitted']) {
                // часть текста уже у пользователя — повтор дал бы другой ответ
                $res = ai_result(false, $st['content'], ai_usage_normalize($st['usage']),
                    'openrouter', 'Связь с сервисом ИИ прервалась');
                $res['partial'] = true;
                return $finish($res);
            }
            $last_error = 'Сервис ИИ временно недоступен';
            continue;
        }

        return $finish(ai_result(true, $st['content'], ai_usage_normalize($st['usage']),
            'openrouter', null, ai_stream_tool_calls($st)));
    }

    return $finish(ai_result(false, '', null, 'openrouter', $last_error));
}

/* Тело запроса — одно для обоих режимов, чтобы они не разъезжались. */
function ai_payload_for(array $messages, array $options, array $cfg, $model, $stream = false) {
    $payload = array(
        'model' => $model,
        'messages' => array_values($messages),
        'temperature' => isset($options['temperature']) ? $options['temperature'] : $cfg['temperature'],
        'max_tokens' => isset($options['max_tokens']) ? (int)$options['max_tokens'] : $cfg['max_tokens'],
    );
    if (!empty($options['tools'])) {
        $payload['tools'] = $options['tools'];
    }
    if ($stream) {
        $payload['stream'] = true;
        // просим шлюз прислать расход в последнем кусочке: иначе учёт по
        // потоковым вызовам был бы нулевым
        $payload['stream_options'] = array('include_usage' => true);
    }
    return $payload;
}

/* Расход: часть шлюзов отдаёт только слагаемые. */
function ai_usage_normalize($usage) {
    if (!is_array($usage)) {
        return null;
    }
    $p = (int)(isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0);
    $c = (int)(isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0);
    $t = (int)(isset($usage['total_tokens']) ? $usage['total_tokens'] : 0);
    return array(
        'prompt_tokens' => $p,
        'completion_tokens' => $c,
        'total_tokens' => $t ?: ($p + $c),
    );
}
