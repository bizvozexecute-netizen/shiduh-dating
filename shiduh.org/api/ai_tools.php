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
 */
function ai_tool_definitions($with_case = false) {
    $tools = ai_tool_profile_definitions();
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
 */
function ai_tool_apply($db, $user_id, $args) {
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
        $current = (string)$db->get_var(
            "SELECT `$column` FROM `Message204` WHERE `Message_ID`=$anketa_id"
        );
        if (trim($current) !== '' && trim($current) !== '0') {
            // человек (или прошлый диалог) уже заполнил — не перетираем
            $report['skipped'][$key] = 'уже заполнено';
            continue;
        }

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
            . "`source`='ai', `created_at`=NOW()"
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
