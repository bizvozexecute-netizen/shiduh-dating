<?php
/*
 * «Мне интересно» из поиска кандидатов (приёмка 2026-10): кандидат одной
 * кнопкой сообщает шадхану анкеты о заинтересованности (api/interest_send.php).
 * Таблица — защита от повторов: одна пара «кто → какая анкета» уведомляет
 * шадхана один раз, сколько бы раз ни нажали кнопку.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `interest_requests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `anketa_id` int(11) NOT NULL,
            `shadhan_id` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_anketa` (`user_id`, `anketa_id`),
            KEY `user_time` (`user_id`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `interest_requests`");
    },
);
