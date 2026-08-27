<?php
/*
 * Подписки на ИИ-функции (ТЗ Э4-1).
 *
 *   user_id           — пользователь (PRIMARY: одна строка на человека)
 *   status            — free | trial | paid (справочник в subscription_lib)
 *   ai_messages_used  — сколько ИИ-сообщений израсходовано (пейволл Э4-2
 *                       блокирует free после лимита из настройки
 *                       ai.free_message_limit, по умолчанию 3)
 *   expires_at        — срок действия trial/paid; NULL = бессрочно. После
 *                       срока статус трактуется как free (ревью Э4: иначе
 *                       недельный триал давал бы безлимит навсегда)
 *
 * Нет строки = free с нулевым счётчиком: базу не заполняем за всех.
 * Таблица модуля: обычный SQL, MyISAM + utf8 как остальная база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `ai_subscriptions` (
            `user_id` int(11) NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'free',
            `ai_messages_used` int(11) NOT NULL DEFAULT 0,
            `expires_at` datetime DEFAULT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`user_id`),
            KEY `updated` (`updated_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `ai_subscriptions`");
    },
);
