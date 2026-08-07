<?php
/*
 * Справочники NetCat для новых полей анкеты (ТЗ Э0.1-d, Э0.1-g):
 *   Kashrut_level   — уровень соблюдения кашрута
 *   Shabbat_level   — уровень соблюдения шаббата
 *   Value_dimension — измерения ценностного профиля (расширенный состав,
 *                     протокол встречи 2026-08-07; список предложен на
 *                     утверждение заказчику/раввину). Используется на уровне
 *                     модуля ИИ (кейсы Э2.2, скоринг Э2.3) — НЕ путать с
 *                     полем анкеты Family_values (JSON-хранилище ответов).
 *
 * Структура справочника NetCat: таблица Classificator_<Name> с колонками
 * <Name>_ID, <Name>_Name, <Name>_Priority, Value, Checked, CustomSettings,
 * плюс запись в реестре Classificator. Повторяет Classificator_Sex.
 *
 * Уровни кашрута/шаббата смешаны в старом Degree_of_compliance (AUDIT.md §9);
 * из него уровень не выводится, поэтому заводим отдельные шкалы. Итоговые
 * формулировки шкал — согласовать с раввином (ROADMAP Э0.1-d).
 */

$DICTS = array(
    'Kashrut_level' => array(
        'title' => 'Уровень кашрута',
        'items' => array('Не соблюдаю', 'Базовый', 'Традиционный', 'Строгий'),
    ),
    'Shabbat_level' => array(
        'title' => 'Уровень шаббата',
        'items' => array('Не соблюдаю', 'Частично', 'Традиционный', 'Строгий'),
    ),
    'Value_dimension' => array(
        'title' => 'Ценностные измерения',
        'items' => array(
            'Бюджет семьи',
            'Роли в доме',
            'Воспитание детей',
            'Отношения с родителями',
            'Отношение к религии',
            'Карьера и дом',
            'Готовность к переезду',
        ),
    ),
);

return array(
    'up' => function ($nc) use ($DICTS) {
        $db = $nc->db;
        foreach ($DICTS as $name => $spec) {
            // сама справочная таблица
            $db->query("CREATE TABLE IF NOT EXISTS `Classificator_$name` (
                `{$name}_ID` int(11) NOT NULL AUTO_INCREMENT,
                `{$name}_Name` char(255) NOT NULL DEFAULT '',
                `{$name}_Priority` int(11) DEFAULT NULL,
                `Value` text,
                `Checked` int(1) DEFAULT '1',
                `CustomSettings` text,
                PRIMARY KEY (`{$name}_ID`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

            // значения — идемпотентно ПОКА. Проверяем каждый элемент отдельно:
            // если up() упадёт посреди цикла, повторный запуск дозаполнит
            // недостающее, а не пропустит остаток (замечание код-ревью).
            $priority = 0;
            foreach ($spec['items'] as $item) {
                $priority++;
                $exists = (int)$db->get_var(
                    "SELECT COUNT(*) FROM `Classificator_$name` "
                    . "WHERE `{$name}_Name`='" . $db->escape($item) . "'"
                );
                if ($exists === 0) {
                    $db->query("INSERT INTO `Classificator_$name` SET "
                        . "`{$name}_Name`='" . $db->escape($item) . "', "
                        . "`{$name}_Priority`=" . $priority . ", `Checked`=1");
                }
            }

            // регистрация в реестре справочников
            if ((int)$db->get_var("SELECT COUNT(*) FROM `Classificator` WHERE `Table_Name`='" . $db->escape($name) . "'") === 0) {
                $db->query("INSERT INTO `Classificator` SET "
                    . "`Classificator_Name`='" . $db->escape($spec['title']) . "', "
                    . "`Table_Name`='" . $db->escape($name) . "', "
                    . "`System`=0, `Sort_Type`=0, `Sort_Direction`=0");
            }
        }
    },

    'down' => function ($nc) use ($DICTS) {
        $db = $nc->db;
        foreach ($DICTS as $name => $spec) {
            $db->query("DELETE FROM `Classificator` WHERE `Table_Name`='" . $db->escape($name) . "'");
            $db->query("DROP TABLE IF EXISTS `Classificator_$name`");
        }
    },
);
