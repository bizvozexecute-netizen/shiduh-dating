<?php
/*
 * Раздел личного кабинета «Ваш ИИ-консультант» (ТЗ Э0.2-a).
 *
 *   URL /my/ai-consultant/  →  Subdivision 89  →  Sub_Class 99  →  Class 323
 *   Шаблоны компонента — netcat_template/class/323/ (File_Mode=1).
 *
 * Компонент рендерит чат из таблицы ai_chat_messages (создана миграцией 001),
 * а не из своей Message-таблицы. Но NetCat при выводе компонента всё равно
 * делает SELECT из Message323 — поэтому заводим её пустой (только системные
 * колонки, как у Message221), иначе nc_objects_list упадёт.
 *
 * Строки Class/Subdivision/Sub_Class копируются с рабочих образцов
 * (221 «Заметки», раздел 29, Sub_Class 30) с заменой ключевых полей —
 * см. mig_copy_row в _bootstrap.php.
 *
 * ID зафиксированы (не AUTO): папка шаблонов class/323/ должна совпадать с
 * File_Path независимо от повторных up/down.
 */

$CLASS_ID = 323;
$SUB_ID   = 89;
$SUBCLASS_ID = 99;

return array(
    'up' => function ($nc) use ($CLASS_ID, $SUB_ID, $SUBCLASS_ID) {
        $db = $nc->db;

        // Фиктивная Message-таблица компонента (системные колонки как у 221).
        if (!$db->get_var("SHOW TABLES LIKE 'Message$CLASS_ID'")) {
            $db->query("CREATE TABLE `Message$CLASS_ID` LIKE `Message221`");
            foreach (array('Date', 'text', 'Candidate') as $c) {
                if ($db->get_var("SHOW COLUMNS FROM `Message$CLASS_ID` LIKE '$c'")) {
                    $db->query("ALTER TABLE `Message$CLASS_ID` DROP COLUMN `$c`");
                }
            }
        }

        // Компонент (Class) — копия 221 с новым ID и путём шаблонов.
        if (!$db->get_var("SELECT Class_ID FROM `Class` WHERE Class_ID=$CLASS_ID")) {
            mig_copy_row($db, 'Class', 'Class_ID', 221, array(
                'Class_ID'    => $CLASS_ID,
                'Class_Name'  => 'ИИ-консультант',
                'Class_Group' => 'One Soul',
                'Keyword'     => '',
                'File_Mode'   => 1,
                'File_Path'   => "/$CLASS_ID/",
                'File_Hash'   => '',
                'SortBy'      => 'a.`Created` ASC, a.`Message_ID` ASC',
            ));
        }

        // Раздел (Subdivision) — копия 29 с URL /my/ai-consultant/.
        if (!$db->get_var("SELECT Subdivision_ID FROM `Subdivision` WHERE Subdivision_ID=$SUB_ID")) {
            mig_copy_row($db, 'Subdivision', 'Subdivision_ID', 29, array(
                'Subdivision_ID'   => $SUB_ID,
                'Parent_Sub_ID'    => 1,
                'Subdivision_Name' => 'ИИ-консультант',
                'Template_ID'      => 17,
                'EnglishName'      => 'ai-consultant',
                'Hidden_URL'       => '/my/ai-consultant/',
                'Priority'         => 34,
                'Checked'          => 1,
            ));
        }

        // Привязка компонента к разделу (Sub_Class) — копия 30.
        if (!$db->get_var("SELECT Sub_Class_ID FROM `Sub_Class` WHERE Sub_Class_ID=$SUBCLASS_ID")) {
            mig_copy_row($db, 'Sub_Class', 'Sub_Class_ID', 30, array(
                'Sub_Class_ID'   => $SUBCLASS_ID,
                'Subdivision_ID' => $SUB_ID,
                'Class_ID'       => $CLASS_ID,
                'Sub_Class_Name' => 'ИИ-консультант',
                'EnglishName'    => 'ai-consultant',
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
