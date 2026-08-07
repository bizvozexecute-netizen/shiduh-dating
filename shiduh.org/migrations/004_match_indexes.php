<?php
/*
 * Составной индекс под жёсткие фильтры подбора пар (ТЗ Э0.1-e, Э3.1).
 *
 * В Message204 есть одиночные индексы на Gender, Country, City и др.
 * (AUDIT.md §5.1), но составного под реальный запрос подбора нет.
 * Порядок колонок — от самого селективного равенства к диапазону:
 * пол (равенство) → страна (равенство) → дата рождения (диапазон возраста).
 *
 * Индексы на Kashrut_level / Shabbat_level создаёт миграция 003 (add_field
 * с DoSearch=1) — здесь не дублируем.
 */
return array(
    'up' => function ($nc) {
        $db = $nc->db;
        if (!$db->get_var("SHOW INDEX FROM `Message204` WHERE `Key_name`='idx_match'")) {
            $db->query("ALTER TABLE `Message204` ADD INDEX `idx_match` (`Gender`,`Country`,`Date_of_birth`)");
        }
    },

    'down' => function ($nc) {
        $db = $nc->db;
        if ($db->get_var("SHOW INDEX FROM `Message204` WHERE `Key_name`='idx_match'")) {
            $db->query("ALTER TABLE `Message204` DROP INDEX `idx_match`");
        }
    },
);
