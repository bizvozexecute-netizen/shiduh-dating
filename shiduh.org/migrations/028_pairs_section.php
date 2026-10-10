<?php
/*
 * Раздел «Подбор пар с ИИ» (приёмка 2026-10).
 *
 *   URL /my/podbor-par/  →  Subdivision 90  →  Sub_Class 100  →  Class 324
 *   Шаблоны компонента — netcat_template/class/324/ (File_Mode=1).
 *
 * Заменяет «Поиск шидуха» (81) и «Создание шидуха» (76) — их выключает
 * миграция 029. Устроен как раздел ИИ-консультанта (005): компонент
 * рендерит всё в FormPrefix, своей Message-таблицы не читает, но ядро при
 * выводе делает SELECT из Message324 — заводим её пустой.
 *
 * Строки копируются с раздела 89/Sub_Class 99/Class 323 (005), макет — как
 * у разделов шидуха (Template_ID 20).
 */

$CLASS_ID = 324;
$SUB_ID   = 90;
$SUBCLASS_ID = 100;

return array(
    'up' => function ($nc) use ($CLASS_ID, $SUB_ID, $SUBCLASS_ID) {
        $db = $nc->db;

        if (!$db->get_var("SHOW TABLES LIKE 'Message$CLASS_ID'")) {
            $db->query("CREATE TABLE `Message$CLASS_ID` LIKE `Message323`");
        }

        if (!$db->get_var("SELECT Class_ID FROM `Class` WHERE Class_ID=$CLASS_ID")) {
            mig_copy_row($db, 'Class', 'Class_ID', 323, array(
                'Class_ID'    => $CLASS_ID,
                'Class_Name'  => 'Подбор пар с ИИ',
                'Class_Group' => 'One Soul',
                'Keyword'     => '',
                'File_Mode'   => 1,
                'File_Path'   => "/$CLASS_ID/",
                'File_Hash'   => '',
            ));
        }

        if (!$db->get_var("SELECT Subdivision_ID FROM `Subdivision` WHERE Subdivision_ID=$SUB_ID")) {
            mig_copy_row($db, 'Subdivision', 'Subdivision_ID', 89, array(
                'Subdivision_ID'   => $SUB_ID,
                'Parent_Sub_ID'    => 1,
                'Subdivision_Name' => 'Подбор пар с ИИ',
                'Template_ID'      => 20,
                'EnglishName'      => 'podbor-par',
                'Hidden_URL'       => '/my/podbor-par/',
                'Priority'         => 35,
                'Checked'          => 1,
            ));
        }

        if (!$db->get_var("SELECT Sub_Class_ID FROM `Sub_Class` WHERE Sub_Class_ID=$SUBCLASS_ID")) {
            mig_copy_row($db, 'Sub_Class', 'Sub_Class_ID', 99, array(
                'Sub_Class_ID'   => $SUBCLASS_ID,
                'Subdivision_ID' => $SUB_ID,
                'Class_ID'       => $CLASS_ID,
                'Sub_Class_Name' => 'Подбор пар с ИИ',
                'EnglishName'    => 'podbor-par',
                'Checked'        => 1,
            ));
        }
    },

    'down' => function ($nc) use ($CLASS_ID, $SUB_ID, $SUBCLASS_ID) {
        $db = $nc->db;
        $db->query("DELETE FROM `Sub_Class` WHERE Sub_Class_ID=$SUBCLASS_ID");
        $db->query("DELETE FROM `Subdivision` WHERE Subdivision_ID=$SUB_ID");
        $db->query("DELETE FROM `Class` WHERE Class_ID=$CLASS_ID");
        $db->query("DROP TABLE IF EXISTS `Message$CLASS_ID`");
    },
);
