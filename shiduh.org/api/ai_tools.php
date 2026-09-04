<?php
/*
 * Автозаполнение анкеты в диалоге через tool calling (ТЗ Э1.3).
 *
 * Модель не пишет в базу сама: она лишь ПРОСИТ записать поля, а бэкенд
 * проверяет и решает. Порядок жёсткий (Э1.3-b):
 *   1. валидация: неизвестные поля и пустые значения отбрасываются,
 *      словарные значения приводятся к ID справочника (невалидное — мимо);
 *   2. запись ТОЛЬКО пустых полей — заполненное человеком не перетирается;
 *   3. журнал в ai_field_writes (что, из чего, кем);
 *   4. отчёт возвращается модели, чтобы она естественно продолжила диалог.
 *
 * Чувствительные поля (доход/здоровье/медикаменты) недоступны инструменту
 * НИКОГДА: их заполняет только сам человек в закрытых полях анкеты
 * (152-ФЗ, Раздел X). Проверка не «на честность модели» — просто таких
 * ключей нет в карте разрешённых.
 *
 * Экранирование строк — prepare() (№35), числа — (int).
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

// В шапке, а не внутри функций: include в теле функции делает top-level
// переменные подключаемого файла локальными для будущих точек входа
require_once dirname(__FILE__) . '/cases_lib.php';
require_once dirname(__FILE__) . '/scoring_lib.php';
require_once dirname(__FILE__) . '/risk_signal_lib.php';

/*
 * Карта: ключ инструмента → колонка Message204 + тип.
 *   text — свободный текст/строка
 *   dict — справочник NetCat: значение приводится к ID по имени
 */
function ai_tool_field_map() {
    return array(
        // где живёт и откуда родом
        'city'                  => array('column' => 'City',                  'type' => 'text'),
        'origin'                => array('column' => 'Place_of_birth',        'type' => 'text'),
        // соблюдение
        'kashrut_level'         => array('column' => 'Kashrut_level',         'type' => 'dict'),
        'shabbat_level'         => array('column' => 'Shabbat_level',         'type' => 'dict'),
        'observance_growth'     => array('column' => 'Observance_growth',     'type' => 'text'),
        // семья и ценности
        'family_values'         => array('column' => 'Family_values',         'type' => 'text'),
        'housing_after_marriage'=> array('column' => 'Housing_after_marriage','type' => 'text'),
        // переезд
        'relocation_ready'      => array('column' => 'Are_you_ready_to_move', 'type' => 'dict'),
        'diaspora'              => array('column' => 'Diaspora_openness',     'type' => 'text'),
        // прочее о себе
        'about_self'            => array('column' => 'About_self',            'type' => 'text'),
        'languages'             => array('column' => 'Languages_spoken',      'type' => 'text'),
        'sport'                 => array('column' => 'Sport_attitude',        'type' => 'text'),
        'introvert_extrovert'   => array('column' => 'Introvert_extrovert',   'type' => 'text'),
    );
}

/*
 * Описание инструментов для модели (OpenAI function calling).
 * $with_case — у пользователя есть неотвеченный кейс опроса (Э2.2-c):
 * добавляется инструмент записи ответа на кейс. КАКОЙ кейс — определяет
 * сервер (следующий неотвеченный), модель передаёт только сам ответ:
 * доверять ей выбор кейса нельзя.
 *
 * $pending — заданные ранее вопросы «поменять X на Y?». Пока их нет,
 * инструмента подтверждения в списке тоже нет: инструмент — это приглашение
 * им воспользоваться, а подтверждать нечего.
 */
function ai_tool_definitions($with_case = false, array $pending = array()) {
    $tools = ai_tool_profile_definitions();
    if ($pending) {
        $tools[] = ai_tool_confirm_definition($pending);
    }
    if ($with_case) {
        $tools[] = array(
            'type' => 'function',
            'function' => array(
                'name' => 'record_case_answer',
                'description' => 'Записать ответ собеседника на предложенный вами '
                    . 'сценарий ценностного опроса. Вызывайте только когда он '
                    . 'содержательно ответил именно на сценарий; передавайте его '
                    . 'слова, не пересказ.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'answer' => array(
                            'type' => 'string',
                            'description' => 'Ответ собеседника на сценарий, близко к его словам',
                        ),
                    ),
                    'required' => array('answer'),
                    'additionalProperties' => false,
                ),
            ),
        );
    }
    /*
     * X-3. «Сигнал внимания» шадхану. Инструмент, а не отдельный платный
     * вызов: модель и так прочитала сообщение. Рамка в описании — чтобы
     * модель не превращала его в психиатрическую оценку: только наблюдаемое
     * поведение, без цитат и без диагнозов.
     */
    $tools[] = array(
        'type' => 'function',
        'function' => array(
            'name' => 'report_safety_concern',
            'description' => 'Отметить для живого шадхана, что в переписке есть повод '
                . 'обратить внимание. Вызывайте РЕДКО и только по наблюдаемому в тексте '
                . 'поведению: прямая агрессия и угрозы, полная бессвязность речи, '
                . 'признаки тяжёлого состояния человека. НЕ ставьте диагнозов, не '
                . 'оценивайте личность, не делайте выводов о психическом здоровье. '
                . 'Не вызывайте из-за грусти, усталости, резкого слова или несогласия '
                . 'с вами. Собеседнику ничего не сообщайте и разговор не меняйте.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'kind' => array(
                        'type' => 'string',
                        'enum' => array_keys(risk_kinds()),
                        'description' => 'aggression — угрозы или агрессия; '
                            . 'incoherence — бессвязность; distress — признаки тяжёлого состояния',
                    ),
                ),
                'required' => array('kind'),
                // только категория: свободный текст не принимаем вовсе,
                // чтобы фрагменты переписки не попадали даже в логи
                'additionalProperties' => false,
            ),
        ),
    );

    return $tools;
}

/* Инструмент автозаполнения анкеты (Э1.3). */
function ai_tool_profile_definitions() {
    $props = array();
    $descr = array(
        'city' => 'Город, где человек живёт сейчас',
        'origin' => 'Откуда родом (город/страна рождения)',
        'kashrut_level' => 'Уровень соблюдения кашрута: Не соблюдаю, Базовый, Традиционный, Строгий',
        'shabbat_level' => 'Уровень соблюдения шаббата: Не соблюдаю, Частично, Традиционный, Строгий',
        'observance_growth' => 'Готовность расти в соблюдении заповедей и границы',
        'family_values' => 'Что важно в семье своими словами',
        'housing_after_marriage' => 'Жильё после свадьбы: с семьёй, отдельно, не решили',
        'relocation_ready' => 'Готовность к переезду: Да или Нет',
        'diaspora' => 'Открытость к другим общинам и диаспорам',
        'about_self' => 'Короткий рассказ о себе своими словами',
        'languages' => 'Языки, на которых говорит',
        'sport' => 'Отношение к спорту и активному отдыху',
        'introvert_extrovert' => 'Скорее интроверт или экстраверт',
    );
    foreach (ai_tool_field_map() as $key => $spec) {
        $props[$key] = array(
            'type' => 'string',
            'description' => isset($descr[$key]) ? $descr[$key] : $key,
        );
    }
    return array(array(
        'type' => 'function',
        'function' => array(
            'name' => 'update_profile_fields',
            'description' => 'Сохранить в анкету то, что человек рассказал о себе. '
                . 'Передавайте только те поля, о которых он сказал прямо. '
                . 'Не передавайте догадки. Заполненные ранее поля не изменятся.',
            'parameters' => array(
                'type' => 'object',
                'properties' => $props,
                'additionalProperties' => false,
            ),
        ),
    ));
}

/* Значение справочника → ID (по имени, без учёта регистра). 0, если нет. */
function ai_tool_dict_id($db, $column, $value) {
    $table = 'Classificator_' . $column;
    // имя таблицы — из нашей карты, не из запроса; значение экранируется
    $id = (int)$db->get_var(
        "SELECT `{$column}_ID` FROM `$table` "
        . "WHERE LOWER(`{$column}_Name`)=LOWER('" . $db->prepare($value) . "') "
        . "AND `Checked`=1 LIMIT 1"
    );
    return $id;
}

/*
 * Применяет запрос модели к анкете пользователя.
 * Возвращает отчёт: array('applied'=>[поле=>значение], 'skipped'=>[поле=>причина]).
 *
 * $chat_message_id — реплика человека, в ответ на которую пришёл вызов. Нужна
 * для вопроса о замене: подтверждение будет засчитано только со следующей
 * реплики (см. ai_tool_confirm).
 */
function ai_tool_apply($db, $user_id, $args, $chat_message_id = 0, $thread = 1) {
    $user_id = (int)$user_id;
    $report = array('applied' => array(), 'skipped' => array());
    if (!is_array($args) || !$user_id) {
        return $report;
    }

    $anketa_id = (int)$db->get_var(
        "SELECT `Message_ID` FROM `Message204` WHERE `User_ID`=$user_id "
        . "ORDER BY `Message_ID` LIMIT 1"
    );
    if (!$anketa_id) {
        $report['skipped']['_'] = 'анкета не найдена';
        return $report;
    }

    $map = ai_tool_field_map();
    foreach ($args as $key => $value) {
        if (!isset($map[$key])) {
            // неизвестный ключ — в том числе попытка тронуть чувствительное
            // или служебное поле: таких ключей в карте нет
            $report['skipped'][(string)$key] = 'поле недоступно';
            continue;
        }
        if (!is_string($value) && !is_numeric($value)) {
            $report['skipped'][$key] = 'значение не текст';
            continue;
        }
        $value = trim((string)$value);
        if ($value === '') {
            $report['skipped'][$key] = 'пустое значение';
            continue;
        }

        $column = $map[$key]['column'];

        // Приведение к хранимому виду ДО проверки «занято»: иначе про
        // несуществующее значение справочника пришлось бы задавать вопрос
        // о замене, а записать его всё равно было бы нечем.
        if ($map[$key]['type'] === 'dict') {
            $id = ai_tool_dict_id($db, $column, $value);
            if (!$id) {
                $report['skipped'][$key] = 'значение вне справочника';
                continue;
            }
            $stored = (string)$id;
        } else {
            $stored = mb_substr($value, 0, 2000);
        }

        $current = (string)$db->get_var(
            "SELECT `$column` FROM `Message204` WHERE `Message_ID`=$anketa_id"
        );
        if (trim($current) !== '' && trim($current) !== '0') {
            // Поле занято. Молча перетирать нельзя (ТЗ Э1.3), но и молча
            // ронять предложение — значит запереть человека: переехал в
            // другой город, а сказать об этом консультанту некому.
            // Записываем вопрос и просим модель задать его словами.
            if (trim($current) === trim($stored)) {
                ai_pending_drop($db, $user_id, $column);
                $report['skipped'][$key] = 'там уже это же значение';
                continue;
            }
            $report['skipped'][$key] = 'уже заполнено';
            $report['pending'][$key] = ai_pending_ask(
                $db, $user_id, $key, $column, $map[$key]['type'],
                $current, $stored, $chat_message_id, $thread
            );
            continue;
        }

        // поле пустое — прежний вопрос о нём (если был) уже не о чем
        ai_pending_drop($db, $user_id, $column);

        $db->query(
            "UPDATE `Message204` SET `$column`='" . $db->prepare($stored) . "' "
            . "WHERE `Message_ID`=$anketa_id"
        );
        if ($db->is_error) {
            $report['skipped'][$key] = 'ошибка записи';
            continue;
        }

        $db->query(
            "INSERT INTO `ai_field_writes` SET "
            . "`user_id`=$user_id, `field`='" . $db->prepare($column) . "', "
            . "`old_value`='" . $db->prepare($current) . "', "
            . "`new_value`='" . $db->prepare($stored) . "', "
            . "`source`='ai', `chat_message_id`=" . (int)$chat_message_id . ", "
            . "`created_at`=NOW()"
        );
        if ($db->is_error) {
            // Транзакций нет (MyISAM): поле уже записано, а журнал — нет.
            // Откатывать запись хуже, чем потерять строку журнала, но молчать
            // нельзя: иначе изменение анкеты остаётся необъяснимым.
            shiduh_log('ai', 'не записан журнал автозаполнения: поле ' . $column
                . ', пользователь ' . $user_id);
        }
        $report['applied'][$column] = $stored;
    }

    return $report;
}

/*
 * Запись ответа на кейс из диалога (Э2.2-c). Кейс определяет СЕРВЕР —
 * следующий неотвеченный по cases_survey_state, тем же правилом, что и
 * явный опрос: модель не выбирает, на какой кейс «зачесть» ответ.
 * Конвейер записи общий (scoring_record_answer) — путь ответа не должен
 * влиять на профиль.
 */
function ai_tool_record_case($db, $user_id, $args) {
    $user_id = (int)$user_id;

    $answer = isset($args['answer']) && (is_string($args['answer']) || is_numeric($args['answer']))
        ? trim((string)$args['answer']) : '';
    if ($answer === '') {
        return array('recorded' => false, 'reason' => 'пустой ответ');
    }
    if (mb_strlen($answer) > 4000) {
        $answer = mb_substr($answer, 0, 4000);
    }

    if (scoring_daily_cap_reached($db, $user_id)) {
        return array('recorded' => false, 'reason' => 'дневной лимит ответов исчерпан');
    }

    $state = cases_survey_state($db, $user_id);
    if (empty($state['next'])) {
        return array('recorded' => false, 'reason' => 'опрос уже пройден');
    }
    $case_row = $db->get_row(
        "SELECT `id`, `dimension_group`, `dimension`, `scenario_text` "
        . "FROM `assessment_cases` WHERE `id`=" . (int)$state['next']['id'] . " AND `active`=1",
        ARRAY_A
    );
    if (!$case_row) {
        return array('recorded' => false, 'reason' => 'кейс недоступен');
    }

    $result = scoring_record_answer($db, $user_id, $case_row, $answer);
    if (!$result['answer_id']) {
        return array('recorded' => false, 'reason' => 'не удалось сохранить');
    }

    $after = cases_survey_state($db, $user_id);
    return array(
        'recorded' => true,
        'classified' => $result['classified'],
        'answered' => (int)$after['answered'],
        'total' => (int)$after['total'],
        // подсказка модели: есть ли следующий сценарий (сам текст придёт в
        // системном промпте следующего сообщения — не раздуваем ответ)
        'has_next' => !empty($after['next']),
    );
}

/*
 * Обработка сигнала от модели (X-3). Возвращает отчёт МОДЕЛИ — намеренно
 * скупой: модель не должна обсуждать сигнал с собеседником.
 */
function ai_tool_report_concern($db, $user_id, $args) {
    $kind = isset($args['kind']) ? (string)$args['kind'] : '';
    $note = isset($args['note']) ? (string)$args['note'] : '';
    $ok = risk_note_add($db, $user_id, $kind, $note);
    // 'noted' и когда заметка не создана (дубль за сутки): модели знать
    // о дедупликации незачем, иначе она начнёт «пробовать ещё раз»
    return array('noted' => true, 'stored' => (bool)$ok);
}

/* ------------------------------- вопрос о замене значения (ТЗ Э1.3) ------ */

/*
 * Сколько живёт заданный вопрос. Сутки — потому что «да» через неделю
 * относится уже к другому разговору: человек не помнит, о чём его спросили,
 * а модель охотно подтвердит что угодно, лишь бы согласиться с собеседником.
 */
define('AI_PENDING_TTL_HOURS', 24);

/* ID справочника → имя. Пустая строка, если такого ID нет. */
function ai_tool_dict_name($db, $column, $id) {
    $id = (int)$id;
    if (!$id) {
        return '';
    }
    $table = 'Classificator_' . $column;      // имя из карты полей, не из запроса
    return (string)$db->get_var(
        "SELECT `{$column}_Name` FROM `$table` WHERE `{$column}_ID`=$id LIMIT 1"
    );
}

/*
 * Хранимое значение → то, что можно показать человеку. Для справочников это
 * имя: в вопросе «поменять на 4?» согласиться невозможно.
 */
function ai_tool_value_label($db, $column, $type, $stored) {
    if ($type === 'dict') {
        $name = ai_tool_dict_name($db, $column, $stored);
        $text = $name !== '' ? $name : (string)$stored;
    } else {
        $text = (string)$stored;
    }

    /*
     * Метка уходит в СИСТЕМНЫЙ промпт и в описание инструмента, а берётся из
     * поля анкеты, которое человек заполняет сам. Без очистки строка, где
     * после кавычки идёт новая строка с указанием модели, выходит за границу
     * блока данных и читается как инструкция. Соседние блоки (фрагменты книг,
     * текст сценария) чистятся ровно так же — этот не исключение.
     */
    $text = str_replace(array("\r", "\n", "\t", "\xC2\xAB", "\xC2\xBB"),
                        array(' ', ' ', ' ', '"', '"'), $text);
    $clean = preg_replace('/\s+/u', ' ', $text);
    if ($clean === null) {           // битый UTF-8: иначе метка стала бы пустой
        $clean = preg_replace('/\s+/', ' ', $text);
    }
    return mb_substr(trim((string)$clean), 0, 200);
}

/* Снять вопрос по одному полю (ответили, протух или стал бессмысленным). */
function ai_pending_drop($db, $user_id, $column) {
    $db->query(
        "DELETE FROM `ai_pending_overwrites` WHERE `user_id`=" . (int)$user_id
        . " AND `field`='" . $db->prepare($column) . "'"
    );
}

/*
 * Окно согласия — ровно одна следующая реплика человека.
 *
 * Проверки «вопрос задан раньше текущей реплики» мало: она доказывает лишь,
 * что человек после вопроса что-то написал. Скажи он «нет» — вопрос всё
 * равно висел бы, и «да» на совсем другой вопрос консультанта тремя
 * репликами позже модель зачла бы за согласие на замену. Поэтому вопрос,
 * доживший до конца отвечающего хода неподтверждённым, снимается: отдельно
 * фиксировать отказ не нужно, молчание — тоже не согласие.
 *
 * Вызывается в конце обмена: подтверждение к этому моменту свою строку уже
 * удалило, а заново предложенное в этом же ходу имеет номер текущей реплики
 * и под условие не попадает.
 */
function ai_pending_expire_answered($db, $user_id, $current_message_id, $thread = null) {
    $current_message_id = (int)$current_message_id;
    if ($current_message_id <= 0) {
        return;
    }
    // Реплика в другом диалоге чужой вопрос не отвечает — и не должна
    // съедать его окно: у каждого разговора свой ход.
    $where = $thread === null ? '' : " AND `thread`=" . (int)$thread;
    $db->query(
        "DELETE FROM `ai_pending_overwrites` WHERE `user_id`=" . (int)$user_id
        . " AND `asked_message_id` < $current_message_id" . $where
    );
}

/* Снять все вопросы пользователя (новый диалог: прежние уже не о чём). */
function ai_pending_clear($db, $user_id) {
    $db->query("DELETE FROM `ai_pending_overwrites` WHERE `user_id`=" . (int)$user_id);
}

/*
 * Запомнить вопрос «поменять X на Y?» и вернуть его же для отчёта модели.
 * REPLACE, а не INSERT: по полю висит ровно один вопрос — последний. Иначе
 * передумавшая по ходу разговора модель оставила бы два предложения, и «да»
 * относилось бы неизвестно к какому.
 */
function ai_pending_ask($db, $user_id, $key, $column, $type, $current, $proposed, $chat_message_id, $thread = 1) {
    $current_label  = ai_tool_value_label($db, $column, $type, $current);
    $proposed_label = ai_tool_value_label($db, $column, $type, $proposed);

    /*
     * По полю висит ровно один вопрос — последний: передумавшая по ходу
     * разговора модель иначе оставила бы два предложения, и «да» относилось
     * бы неизвестно к какому.
     *
     * Но при повторе ТОГО ЖЕ предложения номер реплики не сдвигаем. Модель
     * часто присылает в одном ответе и повтор предложения, и подтверждение;
     * сдвинутый номер превратил бы явное согласие человека в «он ещё не
     * ответил», и кнопка «Да, поменять» переставала бы работать.
     */
    $db->query(
        "INSERT INTO `ai_pending_overwrites` SET "
        . "`user_id`=" . (int)$user_id . ", "
        . "`field`='" . $db->prepare($column) . "', "
        . "`tool_key`='" . $db->prepare($key) . "', "
        . "`thread`=" . (int)$thread . ", "
        . "`current_value`='" . $db->prepare($current) . "', "
        . "`current_label`='" . $db->prepare($current_label) . "', "
        . "`proposed_value`='" . $db->prepare($proposed) . "', "
        . "`proposed_label`='" . $db->prepare($proposed_label) . "', "
        . "`asked_message_id`=" . (int)$chat_message_id . ", "
        . "`asked_at`=NOW() "
        . "ON DUPLICATE KEY UPDATE "
        . "`tool_key`=VALUES(`tool_key`), `thread`=VALUES(`thread`), "
        . "`current_value`=VALUES(`current_value`), `current_label`=VALUES(`current_label`), "
        . "`proposed_label`=VALUES(`proposed_label`), "
        . "`asked_message_id`=IF(`proposed_value`=VALUES(`proposed_value`), "
        . "`asked_message_id`, VALUES(`asked_message_id`)), "
        . "`proposed_value`=VALUES(`proposed_value`), `asked_at`=NOW()"
    );
    if ($db->is_error) {
        shiduh_log('ai', 'не сохранён вопрос о замене поля ' . $column
            . ', пользователь ' . (int)$user_id);
    }

    return array(
        'сейчас' => $current_label,
        'предложено' => $proposed_label,
        'что_делать' => 'спросите собеседника, менять ли; после явного согласия '
            . 'вызовите confirm_profile_update с этим полем',
    );
}

/*
 * Живые вопросы пользователя. Заодно чистит те, что потеряли смысл:
 *   - старше суток;
 *   - значение в анкете успело измениться (человек правил её через форму) —
 *     тогда согласие относилось бы к тому, чего человек уже не видит.
 * Проверка здесь, а не только при подтверждении, потому что протухший вопрос
 * не должен ни попадать в промпт, ни висеть подсказкой «да/нет».
 */
function ai_pending_list($db, $user_id, $thread = null) {
    $user_id = (int)$user_id;
    $ttl = (int)AI_PENDING_TTL_HOURS;
    // Забираем все строки пользователя разом (в обычной жизни их 0-1) и
    // разбираем в PHP: отдельный DELETE на каждый вызов брал бы табличную
    // блокировку MyISAM даже там, где чистить нечего.
    $rows = (array)$db->get_results(
        "SELECT *, (`asked_at` < DATE_SUB(NOW(), INTERVAL $ttl HOUR)) AS `expired` "
        . "FROM `ai_pending_overwrites` WHERE `user_id`=$user_id ORDER BY `id`",
        ARRAY_A
    );
    if (!$rows) {
        return array();
    }

    $fresh = array();
    foreach ($rows as $r) {
        if (!empty($r['expired'])) {
            ai_pending_drop($db, $user_id, (string)$r['field']);
            continue;
        }
        // Вопрос из другого диалога: «да» в новом разговоре не должно
        // подтверждать то, о чём спрашивали в старом. Номер диалога приходит
        // от клиента, поэтому очистки при приветствии недостаточно.
        if ($thread !== null && (int)$r['thread'] !== (int)$thread) {
            continue;
        }
        $fresh[] = $r;
    }
    $rows = $fresh;
    if (!$rows) {
        return array();
    }

    $anketa_id = (int)$db->get_var(
        "SELECT `Message_ID` FROM `Message204` WHERE `User_ID`=$user_id "
        . "ORDER BY `Message_ID` LIMIT 1"
    );
    if (!$anketa_id) {
        return array();
    }

    $map = ai_tool_field_map();
    $live = array();
    foreach ($rows as $r) {
        // колонку берём из нашей карты, а не из строки таблицы: в SQL не
        // должно попадать ничего, чего мы не объявляли сами
        $key = (string)$r['tool_key'];
        if (!isset($map[$key]) || $map[$key]['column'] !== $r['field']) {
            ai_pending_drop($db, $user_id, (string)$r['field']);
            continue;
        }
        $column = $map[$key]['column'];
        $now = (string)$db->get_var(
            "SELECT `$column` FROM `Message204` WHERE `Message_ID`=$anketa_id"
        );
        if (trim($now) !== trim((string)$r['current_value'])) {
            ai_pending_drop($db, $user_id, $column);
            continue;
        }
        $r['column'] = $column;
        $r['type'] = $map[$key]['type'];
        $live[] = $r;
    }
    return $live;
}

/*
 * Описание инструмента подтверждения. Появляется только когда вопрос задан:
 * инструмент в списке — это приглашение им воспользоваться, а приглашать
 * подтверждать нечего не нужно.
 *
 * Параметра значения НЕТ намеренно. Что записать, сервер знает сам из строки
 * вопроса; будь у модели возможность передать значение — она могла бы
 * подставить не то, о чём спрашивала, и вся проверка потеряла бы смысл.
 */
function ai_tool_confirm_definition(array $pending) {
    $keys = array();
    $lines = array();
    foreach ($pending as $p) {
        $keys[] = (string)$p['tool_key'];
        $lines[] = $p['tool_key'] . ': "' . $p['current_label'] . '" -> "' . $p['proposed_label'] . '"';
    }
    return array(
        'type' => 'function',
        'function' => array(
            'name' => 'confirm_profile_update',
            'description' => 'Заменить в анкете ранее заполненное значение на предложенное. '
                . 'Вызывайте ТОЛЬКО после того, как собеседник прямо согласился на замену '
                . 'в ответ на ваш вопрос. Ожидают подтверждения: ' . implode('; ', $lines)
                . '. Если он отказался — не вызывайте, оставьте как есть.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'fields' => array(
                        'type' => 'array',
                        'description' => 'Поля, замену которых собеседник подтвердил',
                        'items' => array('type' => 'string', 'enum' => $keys),
                    ),
                ),
                'required' => array('fields'),
                'additionalProperties' => false,
            ),
        ),
    );
}

/*
 * Применение подтверждённой замены.
 *
 * Согласие засчитывается, только если вопрос был задан РАНЬШЕ текущей реплики
 * человека. Предложение и подтверждение в одном ходу модель может выдать сама,
 * ни о чём не спросив; по её же словам этого не отличить, а по номеру реплики
 * видно однозначно.
 */
function ai_tool_confirm($db, $user_id, $args, $chat_message_id = 0, $thread = null) {
    $user_id = (int)$user_id;
    $chat_message_id = (int)$chat_message_id;
    $report = array('applied' => array(), 'skipped' => array());

    $fields = array();
    if (is_array($args) && isset($args['fields'])) {
        $fields = is_array($args['fields']) ? $args['fields'] : array($args['fields']);
    }
    if (!$fields || !$user_id) {
        $report['skipped']['_'] = 'не указано, что подтверждать';
        return $report;
    }

    $anketa_id = (int)$db->get_var(
        "SELECT `Message_ID` FROM `Message204` WHERE `User_ID`=$user_id "
        . "ORDER BY `Message_ID` LIMIT 1"
    );
    if (!$anketa_id) {
        $report['skipped']['_'] = 'анкета не найдена';
        return $report;
    }

    $pending = array();
    foreach (ai_pending_list($db, $user_id, $thread) as $p) {
        $pending[(string)$p['tool_key']] = $p;
    }

    foreach ($fields as $key) {
        $key = is_string($key) ? trim($key) : '';
        if ($key === '' || !isset($pending[$key])) {
            // спросили не об этом — или вопрос уже снят как потерявший смысл
            $report['skipped'][(string)$key] = 'подтверждать нечего: такого вопроса не задавали';
            continue;
        }
        $row = $pending[$key];
        if ((int)$row['asked_message_id'] >= $chat_message_id) {
            // вопрос задан в этом же ходу: собеседник ещё не отвечал
            $report['skipped'][$key] = 'собеседник ещё не ответил на ваш вопрос';
            continue;
        }

        $column = (string)$row['column'];
        $stored = (string)$row['proposed_value'];
        // Условие по прежнему значению — вместо транзакции (MyISAM их не
        // умеет): между сверкой снимка выше и этой записью человек мог
        // сохранить форму анкеты, и его правку затёрло бы молча.
        $db->query(
            "UPDATE `Message204` SET `$column`='" . $db->prepare($stored) . "' "
            . "WHERE `Message_ID`=$anketa_id "
            . "AND `$column`='" . $db->prepare((string)$row['current_value']) . "'"
        );
        if ($db->is_error) {
            $report['skipped'][$key] = 'ошибка записи';
            continue;
        }
        if ((int)$db->rows_affected === 0) {
            ai_pending_drop($db, $user_id, $column);
            $report['skipped'][$key] = 'значение в анкете уже другое, спросите заново';
            continue;
        }

        $db->query(
            "INSERT INTO `ai_field_writes` SET "
            . "`user_id`=$user_id, `field`='" . $db->prepare($column) . "', "
            . "`old_value`='" . $db->prepare((string)$row['current_value']) . "', "
            . "`new_value`='" . $db->prepare($stored) . "', "
            . "`source`='ai', `chat_message_id`=" . $chat_message_id . ", "
            . "`created_at`=NOW()"
        );
        if ($db->is_error) {
            // Транзакций нет (MyISAM). Замену не откатываем, но замена без
            // следа — худший вид изменения анкеты: пишем в лог.
            shiduh_log('ai', 'не записан журнал подтверждённой замены: поле ' . $column
                . ', пользователь ' . $user_id);
        }

        ai_pending_drop($db, $user_id, $column);
        $report['applied'][$column] = $stored;
    }

    return $report;
}
