<?php
/*
 * Анкета шадхана (приёмка 2026-10): кандидаты выбирают шадхана, а о нём
 * было известно лишь имя, страна и возраст. Добавляем в профиль (таблица
 * User, поля системной таблицы 3):
 *
 *   Shadhan_about           — о себе (TEXT)
 *   Shadhan_origin          — происхождение (справочник Origin, как у анкет)
 *   Shadhan_specialization  — на чём специализируется (TEXT)
 *   Shadhan_focus           — какими кандидатами занимается больше всего (TEXT)
 *
 * Город уже есть (City, поле 586) — его просто начинаем показывать.
 *
 * nc_component::add_field() здесь не годится: он жёстко пишет
 * System_Table_ID=0 и меняет Message-таблицу компонента. Поэтому, как и
 * 003/006 по смыслу, но руками: колонка + строка в Field + сброс кэша
 * шаблонов. Откат — строго обратный.
 */

$FIELDS = array(
    // Field_Name,              TypeOfData_ID, Format,   SQL-тип,  Description
    array('Shadhan_about',          3, '',       'TEXT',    'О себе (шадхан)'),
    array('Shadhan_origin',         4, 'Origin', 'INT(11)', 'Происхождение (шадхан)'),
    array('Shadhan_specialization', 3, '',       'TEXT',    'Специализация (шадхан)'),
    array('Shadhan_focus',          3, '',       'TEXT',    'С какими кандидатами работает больше всего'),
);

return array(
    'up' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        $priority = 40;
        foreach ($FIELDS as $f) {
            list($name, $type, $format, $sql, $desc) = $f;
            $priority++;
            if (!$db->get_var("SHOW COLUMNS FROM `User` LIKE '" . $db->prepare($name) . "'")) {
                $db->query("ALTER TABLE `User` ADD `$name` $sql NULL DEFAULT NULL");
            }
            if (!$db->get_var("SELECT `Field_ID` FROM `Field` WHERE `System_Table_ID`=3 AND `Field_Name`='" . $db->prepare($name) . "'")) {
                $db->query(
                    "INSERT INTO `Field` SET `Class_ID`=0, `Field_Name`='" . $db->prepare($name) . "', "
                    . "`Description`='" . $db->prepare($desc) . "', `TypeOfData_ID`=" . (int)$type . ", "
                    . "`Format`='" . $db->prepare($format) . "', `Extension`='', `NotNull`=0, "
                    . "`Priority`=$priority, `DoSearch`=0, `DefaultState`='', `Inheritance`=0, "
                    . "`System_Table_ID`=3, `Widget_Class_ID`=0, `TypeOfEdit_ID`=1, `Checked`=1, `InTableView`=0"
                );
            }
        }
        $nc->component->update_cache_for_multipurpose_templates();
    },

    'down' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach ($FIELDS as $f) {
            $name = $f[0];
            $db->query("DELETE FROM `Field` WHERE `System_Table_ID`=3 AND `Field_Name`='" . $db->prepare($name) . "'");
            if ($db->get_var("SHOW COLUMNS FROM `User` LIKE '" . $db->prepare($name) . "'")) {
                $db->query("ALTER TABLE `User` DROP COLUMN `$name`");
            }
        }
        $nc->component->update_cache_for_multipurpose_templates();
    },
);
