<?php
/*
 * Схема под чат v2 (этап «До идеала», B1).
 *
 * 1. ai_chat_messages: три колонки.
 *    - thread    — номер диалога. «Новый диалог» = MAX(thread)+1, а не
 *                  архивный флаг: флаг требовал бы UPDATE по всем строкам
 *                  пользователя (MyISAM, блокировка таблицы) и сливал бы
 *                  прошлые диалоги в одну кучу. Значение по умолчанию 1,
 *                  поэтому вся существующая переписка сразу оказывается
 *                  в первом треде, а не вне тредов.
 *    - rating    — оценка ответа консультанта (1 / -1 / NULL). Нужна, чтобы
 *                  видеть, где модель отвечает мимо, не читая переписку.
 *    - stopped   — ответ был прерван пользователем на середине. Такой текст
 *                  сохраняем (человек его уже видел), но помечаем: иначе
 *                  обрывок выглядит как полноценный ответ.
 *    Индекс (user_id, thread, id) — под выборку истории треда постранично.
 *
 * 2. ai_usage_log — по строке на КАЖДЫЙ вызов модели, включая заглушку и
 *    неудачные попытки. До этого расход был виден только как tokens_used в
 *    строке ответа чата: сколько ушло на резюме, обоснования пар и разбор
 *    ответов опроса — узнать было нельзя. Отдельная таблица, а не колонки в
 *    ai_chat_messages, потому что вызовы бывают и вне переписки.
 *
 * Обратимость: down снимает колонки и удаляет журнал. Данные переписки не
 * трогаются ни в ту, ни в другую сторону.
 */

$col_exists = function ($db, $table, $col) {
    return (bool)$db->get_var("SHOW COLUMNS FROM `$table` LIKE '" . $db->prepare($col) . "'");
};
$key_exists = function ($db, $table, $key) {
    return (bool)$db->get_var("SHOW INDEX FROM `$table` WHERE `Key_name`='" . $db->prepare($key) . "'");
};

return array(
    'up' => function ($nc) use ($col_exists, $key_exists) {
        $db = $nc->db;

        $add = array(
            'thread'  => "ADD COLUMN `thread` int(11) NOT NULL DEFAULT 1",
            'rating'  => "ADD COLUMN `rating` tinyint(4) DEFAULT NULL",
            'stopped' => "ADD COLUMN `stopped` tinyint(1) NOT NULL DEFAULT 0",
        );
        foreach ($add as $col => $sql) {
            if (!$col_exists($db, 'ai_chat_messages', $col)) {
                $db->query("ALTER TABLE `ai_chat_messages` $sql");
                echo "ai_chat_messages: колонка $col добавлена\n";
            }
        }
        if (!$key_exists($db, 'ai_chat_messages', 'user_thread_id')) {
            $db->query("ALTER TABLE `ai_chat_messages` ADD KEY `user_thread_id` (`user_id`, `thread`, `id`)");
            echo "ai_chat_messages: индекс user_thread_id добавлен\n";
        }

        $db->query(
            "CREATE TABLE IF NOT EXISTS `ai_usage_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL DEFAULT 0,
                `kind` enum('chat','greet','summary','explain','classify','psychotype','other')
                       NOT NULL DEFAULT 'other',
                `provider` varchar(16) NOT NULL DEFAULT '',
                `model` varchar(64) NOT NULL DEFAULT '',
                `prompt_tokens` int(11) NOT NULL DEFAULT 0,
                `completion_tokens` int(11) NOT NULL DEFAULT 0,
                `total_tokens` int(11) NOT NULL DEFAULT 0,
                `latency_ms` int(11) NOT NULL DEFAULT 0,
                `attempts` tinyint(4) NOT NULL DEFAULT 1,
                `streamed` tinyint(1) NOT NULL DEFAULT 0,
                `ok` tinyint(1) NOT NULL DEFAULT 1,
                `error` varchar(200) NOT NULL DEFAULT '',
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `created` (`created_at`),
                KEY `user_created` (`user_id`, `created_at`),
                KEY `kind_created` (`kind`, `created_at`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
        );
        echo "ai_usage_log готов\n";
    },

    'down' => function ($nc) use ($col_exists, $key_exists) {
        $db = $nc->db;

        if ($key_exists($db, 'ai_chat_messages', 'user_thread_id')) {
            $db->query("ALTER TABLE `ai_chat_messages` DROP KEY `user_thread_id`");
        }
        foreach (array('stopped', 'rating', 'thread') as $col) {
            if ($col_exists($db, 'ai_chat_messages', $col)) {
                $db->query("ALTER TABLE `ai_chat_messages` DROP COLUMN `$col`");
            }
        }
        $db->query("DROP TABLE IF EXISTS `ai_usage_log`");
        echo "колонки чата v2 сняты, журнал расхода удалён\n";
    },
);
