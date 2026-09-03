<?php
/*
 * Удаление мёртвых компонентов чата 292/293/251 (AUDIT №14; этап «До идеала», C2).
 *
 * Компоненты «Чат», «Сообщения чата» (с подшаблонами 295–298) и «Темы чатов»
 * не привязаны ни к одному разделу (Sub_Class пуст), таблицы Message292/293/251
 * пусты, а в 293/CheckActionTemplate.html сидел open redirect через
 * $_SERVER['HTTP_REFERER']. Файлы шаблонов удалены из репозитория; эта миграция
 * убирает строки Class/Field, чтобы компоненты не всплывали в админке и не
 * ссылались на несуществующие файлы (File_Mode=1).
 *
 * Обратимо: строки Class/Field копируются в _dead_chat_Class/_dead_chat_Field,
 * таблицы Message* переименовываются с префиксом _dead_chat_. down возвращает
 * всё как было (файлы шаблонов — из git).
 *
 * Страховка: если у компонентов вдруг появились разделы — не удаляем, бросаем
 * исключение (раннер покажет текст и не отметит миграцию применённой).
 */
$dead_ids = '292,293,251,295,296,297,298';
$dead_tables = array('Message292', 'Message293', 'Message251');

$has_table = function ($db, $name) {
    return (bool)$db->get_var("SHOW TABLES LIKE '" . $db->prepare($name) . "'");
};

return array(
    'up' => function ($nc) use ($dead_ids, $dead_tables, $has_table) {
        $db = $nc->db;

        $refs = (int)$db->get_var(
            "SELECT COUNT(*) FROM `Sub_Class` WHERE `Class_ID` IN ($dead_ids) OR `Class_Template_ID` IN ($dead_ids)"
        );
        if ($refs > 0) {
            throw new Exception("компоненты чата привязаны к разделам ($refs строк Sub_Class) — не удаляю");
        }

        foreach (array('Class', 'Field') as $t) {
            if (!$has_table($db, '_dead_chat_' . $t)) {
                $db->query("CREATE TABLE `_dead_chat_$t` AS SELECT * FROM `$t` WHERE `Class_ID` IN ($dead_ids)");
                echo "архив _dead_chat_$t: " . (int)$db->get_var("SELECT COUNT(*) FROM `_dead_chat_$t`") . " строк\n";
            }
        }
        foreach ($dead_tables as $t) {
            if ($has_table($db, $t) && !$has_table($db, '_dead_chat_' . $t)) {
                $db->query("RENAME TABLE `$t` TO `_dead_chat_$t`");
            }
        }
        $db->query("DELETE FROM `Field` WHERE `Class_ID` IN ($dead_ids)");
        $db->query("DELETE FROM `Class` WHERE `Class_ID` IN ($dead_ids)");
        echo "компоненты чата 292/293/251 (+295–298) убраны из Class/Field\n";
    },

    'down' => function ($nc) use ($dead_ids, $dead_tables, $has_table) {
        $db = $nc->db;
        foreach (array('Class', 'Field') as $t) {
            if ($has_table($db, '_dead_chat_' . $t)) {
                // IGNORE: если строка уже есть (частичный откат) — не дублировать
                $db->query("INSERT IGNORE INTO `$t` SELECT * FROM `_dead_chat_$t`");
                $db->query("DROP TABLE `_dead_chat_$t`");
            }
        }
        foreach ($dead_tables as $t) {
            if ($has_table($db, '_dead_chat_' . $t) && !$has_table($db, $t)) {
                $db->query("RENAME TABLE `_dead_chat_$t` TO `$t`");
            }
        }
        echo "компоненты чата возвращены в Class/Field (файлы шаблонов — из git)\n";
    },
);
