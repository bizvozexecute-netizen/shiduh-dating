<?php
/*
 * Новые поля анкеты Message204 по итогам встречи с шадханами 2026-08-18
 * (ТЗ ROADMAP Э0.1-h). Все поля НЕОБЯЗАТЕЛЬНЫЕ — заполняются в диалоге с
 * ИИ-консультантом, поэтому свободный текст (TypeOfData_ID=3), NotNull=0.
 *
 * Механизм и откат — как в 003_profile_fields.php: add_field() пишет в Field,
 * делает ALTER TABLE ADD и обновляет кэш шаблонов; down удаляет запись Field
 * и колонку.
 *
 * Чувствительные поля (доход/имущество, здоровье, медикаменты) создаются
 * такими же колонками, но перечислены в едином реестре
 * api/ai_sensitive_fields.php: они НИКОГДА не уходят в зарубежный LLM в сыром
 * виде (152-ФЗ, Раздел X ROADMAP). Сама «чувствительность» — политика слоя
 * LLM (Э1), а не свойство колонки, поэтому в БД отдельного флага нет.
 */

$FIELDS = array(
    // Field_Name,               Description
    // --- обычные (могут уходить в LLM) ---
    array('Sport_attitude',          'Спорт и активный отдых'),
    array('Housing_after_marriage',  'Жильё после брака: с семьёй или отдельно'),
    array('Languages_spoken',        'Языки общения'),
    array('Diaspora_openness',       'Какую диаспору рассмотрит (своя / любая)'),
    array('Observance_growth',       'Готовность расти в соблюдении заповедей и границы'),
    array('Rabbinate_registration',  'Регистрация в раввинате, документы о еврействе'),
    array('Introvert_extrovert',     'Интроверт или экстраверт'),
    // --- чувствительные (см. api/ai_sensitive_fields.php, в LLM не уходят) ---
    array('Income_property',         'Доход, квартира, имущество (чувствительное)'),
    array('Health_conditions',       'Заболевания, в т.ч. врождённые, депрессия (чувствительное)'),
    array('Medications',             'Приём медикаментов (чувствительное)'),
);

return array(
    'up' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach ($FIELDS as $f) {
            list($name, $desc) = $f;
            // идемпотентность: пропускаем, если колонка уже есть
            if ($db->get_var("SHOW COLUMNS FROM `Message204` LIKE '" . $db->escape($name) . "'")) {
                continue;
            }
            $nc->component->add_field(204, array(
                'TypeOfData_ID' => 3,    // свободный текст (LONGTEXT)
                'Field_Name'    => $name,
                'Description'   => $desc,
                'Format'        => '',
                'DefaultState'  => '',
                'NotNull'       => 0,
                'DoSearch'      => 0,
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
