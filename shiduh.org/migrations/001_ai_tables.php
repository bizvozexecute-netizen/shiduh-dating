<?php
/*
 * Служебные таблицы модуля ИИ (ТЗ Э0.1):
 *   ai_chat_messages — история диалога кандидата с консультантом
 *   ai_prompts       — системные промпты с версиями (редактируются из админки)
 *   ai_field_writes  — журнал автозаполнения полей анкеты
 *
 * Это НЕ компоненты NetCat, а таблицы модуля — обычный SQL, без Field/Class.
 * MyISAM + utf8 как остальная база (см. AUDIT.md §6).
 */
return array(
    'up' => function ($nc) {
        $db = $nc->db;

        $db->query("CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `role` enum('user','assistant','system') NOT NULL DEFAULT 'user',
            `content` longtext,
            `tokens_used` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_created` (`user_id`,`created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $db->query("CREATE TABLE IF NOT EXISTS `ai_prompts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `key` varchar(64) NOT NULL,
            `content` longtext,
            `version` int(11) NOT NULL DEFAULT 1,
            `updated_by` int(11) DEFAULT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `key_version` (`key`,`version`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $db->query("CREATE TABLE IF NOT EXISTS `ai_field_writes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `field` varchar(64) NOT NULL,
            `old_value` text,
            `new_value` text,
            `source` enum('ai','user','admin') NOT NULL DEFAULT 'ai',
            `chat_message_id` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_field` (`user_id`,`field`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $db = $nc->db;
        $db->query("DROP TABLE IF EXISTS `ai_field_writes`");
        $db->query("DROP TABLE IF EXISTS `ai_prompts`");
        $db->query("DROP TABLE IF EXISTS `ai_chat_messages`");
    },
);
