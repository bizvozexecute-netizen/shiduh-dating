<?php
/*
 * Новые поля анкеты кандидата Message204 (ТЗ Э0.1-a).
 *
 * Добавляются штатным API NetCat: $nc->component->add_field() пишет запись
 * в таблицу Field, делает ALTER TABLE Message204 ADD и обновляет кэш
 * универсальных шаблонов — иначе NetCat не покажет поле в формах/шаблонах.
 * Откат: удалить запись из Field и ALTER TABLE DROP COLUMN.
 *
 * Типы (TypeOfData_ID, см. nc_component::add_field_to_message_table):
 *   3  = TEXT  (LONGTEXT) — свободный текст и JSON
 *   4  = SELECT (INT)     — ссылка на справочник по Format
 *
 * НЕ дублируем существующее: relocation_ready = Are_you_ready_to_move,
 * relocation_cities = Country_of_relocation (AUDIT.md §9, ROADMAP Э0.1-b).
 */

$FIELDS = array(
    // Field_Name,               TypeOfData_ID, Format,          Description
    array('About_self',            3, '',              'О себе'),
    array('Kashrut_level',         4, 'Kashrut_level', 'Уровень кашрута'),
    array('Shabbat_level',         4, 'Shabbat_level', 'Уровень шаббата'),
    array('Family_values',         3, '',              'Семейные ценности (JSON)'),
    array('AI_summary',            3, '',              'Резюме от ИИ'),
    array('AI_summary_meta',       3, '',              'Метаданные резюме (JSON)'),
    array('Psychological_profile', 3, '',              'Психологический профиль (JSON)'),
    array('Profile_embedding',     3, '',              'Эмбеддинг анкеты (JSON)'),
    array('Embedding_meta',        3, '',              'Метаданные эмбеддинга (JSON)'),
);

return array(
    'up' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach ($FIELDS as $f) {
            list($name, $type, $format, $desc) = $f;
            // идемпотентность: пропускаем, если колонка уже есть
            if ($db->get_var("SHOW COLUMNS FROM `Message204` LIKE '" . $db->escape($name) . "'")) {
                continue;
            }
            $nc->component->add_field(204, array(
                'TypeOfData_ID' => $type,
                'Field_Name'    => $name,
                'Description'   => $desc,
                'Format'        => $format,
                'DefaultState'  => '',
                'NotNull'       => 0,
                'DoSearch'      => ($type === 4 ? 1 : 0),  // по спискам ищем, по тексту нет
            ));
        }
    },

    'down' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach (array_reverse($FIELDS) as $f) {
            $name = $f[0];
            $db->query("DELETE FROM `Field` WHERE `Class_ID`=204 AND `Field_Name`='" . $db->escape($name) . "'");
            if ($db->get_var("SHOW COLUMNS FROM `Message204` LIKE '" . $db->escape($name) . "'")) {
                $db->query("ALTER TABLE `Message204` DROP COLUMN `$name`");
            }
        }
        $nc->component->update_cache_for_multipurpose_templates();
    },
);
