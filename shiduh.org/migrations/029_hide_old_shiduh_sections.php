<?php
/*
 * Выключить разделы «Поиск шидуха» (81, /my/poisk-shiduha/) и «Создание
 * шидуха» (76, /my/sozdanie-shiduha/) — приёмка 2026-10: они повторяли
 * подбор пар с ИИ и путали шадханов. Их заменил раздел «Подбор пар с ИИ»
 * (миграция 028); старые адреса перенаправляет .htaccess.
 *
 * Данные не трогаем: созданные раньше шидухи живут в Message280 и видны в
 * «Истории шидухов». down включает разделы обратно — только те, что
 * выключила эта миграция (прежнее значение Checked запоминается).
 */
$SECTIONS = array(81, 76);

return array(
    'up' => function ($nc) use ($SECTIONS) {
        $db = $nc->db;
        $db->query("CREATE TABLE IF NOT EXISTS `_hidden_sections_029` (
            `Subdivision_ID` int(11) NOT NULL PRIMARY KEY,
            `Checked_before` tinyint(4) NOT NULL
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
        foreach ($SECTIONS as $id) {
            $was = $db->get_var("SELECT `Checked` FROM `Subdivision` WHERE `Subdivision_ID`=$id");
            if ($was === null) {
                continue;
            }
            if (!$db->get_var("SELECT 1 FROM `_hidden_sections_029` WHERE `Subdivision_ID`=$id")) {
                $db->query("INSERT INTO `_hidden_sections_029` SET `Subdivision_ID`=$id, `Checked_before`=" . (int)$was);
            }
            $db->query("UPDATE `Subdivision` SET `Checked`=0 WHERE `Subdivision_ID`=$id");
        }
    },

    'down' => function ($nc) {
        $db = $nc->db;
        if (!$db->get_var("SHOW TABLES LIKE '_hidden_sections_029'")) {
            return;
        }
        foreach ((array)$db->get_results("SELECT * FROM `_hidden_sections_029`", ARRAY_A) as $r) {
            $db->query("UPDATE `Subdivision` SET `Checked`=" . (int)$r['Checked_before']
                . " WHERE `Subdivision_ID`=" . (int)$r['Subdivision_ID']);
        }
        $db->query("DROP TABLE `_hidden_sections_029`");
    },
);
