<?php
/*
 * Резюме кандидата для шадхана (ТЗ Э1.4).
 *
 * Собирает выжимку анкеты, отправляет её модели и складывает результат в
 * AI_summary + AI_summary_meta.
 *
 * ГЛАВНОЕ ПРАВИЛО (Э1.4-b, Раздел X, 152-ФЗ): во внешнюю модель уходит
 * только то, что перечислено в БЕЛОМ списке. Белый список, а не чёрный —
 * потому что в анкете 120+ полей, и любое новое поле по умолчанию НЕ
 * уезжает никуда, пока его осознанно не добавят сюда. Прямых
 * идентификаторов (ФИО, телефон, email, контакты раввина, документы) и
 * чувствительных полей (здоровье, доход, медикаменты) в списке нет.
 *
 * Дата рождения тоже не уходит — вместо неё вычисляется возраст: для
 * подбора он и нужен, а точная дата приближает к идентификации человека.
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

require_once dirname(__FILE__) . '/ai_client.php';

/*
 * Белый список полей анкеты, допустимых к отправке в модель.
 * Ключ — колонка Message204, значение — человекочитаемая подпись.
 */
function summary_whitelist() {
    return array(
        // кто и откуда (без имён и точной даты рождения)
        'Gender'                 => 'Пол',
        'City'                   => 'Город проживания',
        'Country'                => 'Страна',
        'Place_of_birth'         => 'Место рождения',
        'Origin'                 => 'Происхождение',
        // занятие
        'Education'              => 'Образование',
        'Specialization'         => 'Специальность',
        'What_does'              => 'Чем занимается',
        'Hobby'                  => 'Увлечения',
        'Hobby_description'      => 'Об увлечениях',
        // семейное положение
        'Marital_status'         => 'Семейное положение',
        'Was_previously_married' => 'Был(а) в браке',
        'Divorced'               => 'Разведён(а)',
        'Number_of_children'     => 'Детей',
        'Height'                 => 'Рост',
        // еврейство и соблюдение
        'Jewishness'             => 'Еврейство',
        'Belonging_denomination' => 'Направление',
        'Degree_of_compliance'   => 'Степень соблюдения',
        'Course_of_compliance'   => 'Как пришёл к соблюдению',
        'Kashrut_level'          => 'Кашрут',
        'Shabbat_level'          => 'Шаббат',
        'Observance_growth'      => 'Готовность расти в соблюдении',
        // привычки и характер
        'Bad_habits'             => 'Вредные привычки',
        'Introvert_extrovert'    => 'Интроверт/экстраверт',
        'Sport_attitude'         => 'Спорт и отдых',
        'Languages_spoken'       => 'Языки',
        // планы
        'Are_you_ready_to_move'  => 'Готовность к переезду',
        'Country_of_relocation'  => 'Куда готов(а) переехать',
        'Housing_after_marriage' => 'Жильё после свадьбы',
        'Diaspora_openness'      => 'Открытость к диаспорам',
        // своими словами
        'About_self'             => 'О себе',
        'Family_values'          => 'Ценности в семье',
        // что ищет
        'Search_age_start'       => 'Ищет: возраст от',
        'Search_age_end'         => 'Ищет: возраст до',
        'Search_country'         => 'Ищет: страна',
        'Search_education'       => 'Ищет: образование',
        'Search_belonging_denomination' => 'Ищет: направление',
        'Search_bad_habits'      => 'Ищет: отношение к привычкам',
        'Search_number_of_children' => 'Ищет: детей',
        'Search_are_you_ready_to_move' => 'Ищет: готовность к переезду',
    );
}

/*
 * Проверка инварианта: в белом списке не должно быть ни чувствительных
 * полей, ни явных идентификаторов. Вызывается перед сборкой — дешевле
 * поймать ошибку правки списка здесь, чем узнать об утечке потом.
 */
function summary_forbidden_fields() {
    $sensitive = (array)include dirname(__FILE__) . '/ai_sensitive_fields.php';
    $identifiers = array(
        'Name', 'Jewish_name', 'EN_Jewish_name', 'Phone', 'Email',
        'Photo_avatar', 'Photo_questionnaire', 'Date_of_birth',
        'Your_ravin', 'Your_ravin_contact', 'Your_sinagoga',
        'Who_can_give_recommendation', 'Birthcertificates',
        'Another_document_Jewishness', 'Success_docs', 'Rabbinate_registration',
        'Father', 'Mother', 'Brother', 'Sister', 'Search_for_shadhan',
    );
    return array_merge($sensitive, $identifiers);
}

/*
 * Страховка для свободного текста: человек мог сам написать телефон или
 * email в поле «о себе» или в переписке. Для резюме такие данные не нужны,
 * а во внешнюю модель им уходить незачем. Сегодня в базе таких значений нет
 * (проверено), но поля свободные и переписка ничем не ограничена.
 */
function summary_scrub($text) {
    $text = (string)$text;
    // email
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', '[контакт удалён]', $text);
    // телефоны и любые длинные цифровые цепочки (в т.ч. с разделителями)
    $text = preg_replace('/\+?\d[\d\s()\-]{6,}\d/u', '[контакт удалён]', $text);
    return $text;
}

/* Имя справочника поля: у Gender это Sex, поэтому берём из Format. */
function summary_dict_name($format) {
    $name = strtok((string)$format, ':');
    return $name ? $name : '';
}

/* Значение справочника по ID. */
function summary_dict_value($db, $dict, $id) {
    $id = (int)$id;
    // Имя справочника приходит из Field.Format (данные админки, не запроса),
    // но оно попадает в позицию ИДЕНТИФИКАТОРА, где экранирование значений не
    // работает — поэтому чистим один раз и используем только очищенное.
    $dict = preg_replace('/[^A-Za-z0-9_]/', '', (string)$dict);
    if ($dict === '' || !$id) {
        return '';
    }
    $table = 'Classificator_' . $dict;
    return (string)$db->get_var(
        "SELECT `{$dict}_Name` FROM `$table` WHERE `{$dict}_ID`=$id LIMIT 1"
    );
}

/*
 * Выжимка анкеты для модели: только белый список, словарные значения
 * раскрыты в текст, пустые поля пропущены. Возвращает array(строки, возраст).
 */
function summary_profile_digest($db, $anketa_id) {
    $anketa_id = (int)$anketa_id;
    $white = summary_whitelist();
    $forbidden = summary_forbidden_fields();

    // инвариант: запрещённое не могло попасть в белый список
    foreach ($forbidden as $f) {
        if (isset($white[$f])) {
            shiduh_log('ai', 'в белый список резюме попало запрещённое поле: ' . $f);
            unset($white[$f]);
        }
    }

    $row = $db->get_row(
        "SELECT * FROM `Message204` WHERE `Message_ID`=$anketa_id", ARRAY_A
    );
    if (!$row) {
        return array();
    }

    // типы и справочники нужных полей
    $names = array();
    foreach (array_keys($white) as $f) {
        $names[] = "'" . $db->prepare($f) . "'";
    }
    $db->query(
        "SELECT `Field_Name`, `TypeOfData_ID`, `Format` FROM `Field` "
        . "WHERE `Class_ID`=204 AND `Field_Name` IN (" . implode(',', $names) . ")",
        ARRAY_A
    );
    $meta = array();
    foreach ((array)$db->last_result as $f) {
        $meta[$f['Field_Name']] = $f;
    }

    $lines = array();

    // возраст вместо даты рождения
    if (!empty($row['Date_of_birth']) && substr($row['Date_of_birth'], 0, 4) !== '0000') {
        $age = (int)$db->get_var(
            "SELECT TIMESTAMPDIFF(YEAR, '" . $db->prepare($row['Date_of_birth']) . "', CURDATE())"
        );
        if ($age > 0 && $age < 120) {
            $lines[] = 'Возраст: ' . $age;
        }
    }

    foreach ($white as $field => $label) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $raw = (string)$row[$field];
        if (trim($raw) === '' || trim($raw) === '0' || trim($raw) === ',,') {
            continue;
        }
        $type = isset($meta[$field]) ? (int)$meta[$field]['TypeOfData_ID'] : 1;
        $dict = isset($meta[$field]) ? summary_dict_name($meta[$field]['Format']) : '';

        if ($type === 4) {                       // одиночный справочник
            $value = summary_dict_value($db, $dict, $raw);
        } elseif ($type === 10) {                // многозначный: ,id,id,
            $ids = array_filter(explode(',', $raw), 'strlen');
            $parts = array();
            foreach ($ids as $id) {
                $v = summary_dict_value($db, $dict, $id);
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $value = implode(', ', $parts);
        } else {
            $value = summary_scrub(trim($raw));
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
        if ($value === '') {
            continue;
        }
        $lines[] = $label . ': ' . mb_substr($value, 0, 500);
    }

    return $lines;
}

/* Последние сообщения кандидата из диалога — своими словами о себе. */
function summary_chat_excerpt($db, $user_id, $limit = 10) {
    $user_id = (int)$user_id;
    $limit = (int)$limit;
    $db->query(
        "SELECT `role`, `content` FROM `ai_chat_messages` WHERE `user_id`=$user_id "
        . "AND `role`='user' ORDER BY `id` DESC LIMIT $limit",
        ARRAY_A
    );
    $rows = array_reverse((array)$db->last_result);
    $out = array();
    foreach ($rows as $r) {
        $out[] = mb_substr(summary_scrub(trim((string)$r['content'])), 0, 500);
    }
    return $out;
}

/* Промпт генерации резюме. */
function summary_build_messages($profile_lines, $chat_lines) {
    $profile = $profile_lines ? implode("\n", $profile_lines) : '(анкета почти не заполнена)';
    $chat = $chat_lines ? implode("\n- ", $chat_lines) : '';

    $user = "Данные анкеты:\n" . $profile;
    if ($chat !== '') {
        $user .= "\n\nЧто человек рассказал в беседе:\n- " . $chat;
    }
    $user .= "\n\nНапишите резюме для шадхана.";

    return array(
        array('role' => 'system', 'content' =>
            AI_SAFETY_PREAMBLE . "\n\n"
            . 'Вы помогаете шадхану быстро понять кандидата. По данным анкеты и '
            . 'беседы напишите связное резюме из 3–6 предложений: кто этот '
            . 'человек, чем занимается, каков уклад соблюдения, что для него '
            . 'важно в семье и кого он ищет. Пишите только то, что есть в '
            . 'данных, — не додумывайте и не делайте выводов о здоровье или '
            . 'психическом состоянии. Без обращения к читателю, без списков, '
            . 'нейтральным тоном.'),
        array('role' => 'user', 'content' => $user),
    );
}

/* Сохранение резюме и его метаданных. */
function summary_store($db, $anketa_id, $text, $meta) {
    $anketa_id = (int)$anketa_id;
    // Резюме — 3–6 предложений; если модель вернула простыню, не тащим её в
    // карточку целиком.
    $text = mb_substr((string)$text, 0, 4000);
    $db->query(
        "UPDATE `Message204` SET "
        . "`AI_summary`='" . $db->prepare($text) . "', "
        . "`AI_summary_meta`='" . $db->prepare(json_encode($meta, JSON_UNESCAPED_UNICODE)) . "' "
        . "WHERE `Message_ID`=$anketa_id"
    );
    return !$db->is_error;
}
