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

/* Описание инструмента для модели (OpenAI function calling). */
function ai_tool_definitions() {
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
            error_log('[ai] не записан журнал автозаполнения: поле ' . $column
                . ', пользователь ' . $user_id);
        }
        $report['applied'][$column] = $stored;
    }

    return $report;
}
