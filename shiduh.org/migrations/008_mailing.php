<?php
/*
 * Массовая рассылка по сегменту (ROADMAP Раздел IV).
 *
 *   mailings       — шапка рассылки: кто создал, фильтры сегмента (JSON),
 *                    тема/текст, сколько получателей поставлено в очередь
 *   mailing_log    — Р-3: кому, когда, статус (queued; фактическую отправку
 *                    делает штатный крон mailer из Mail_Queue по 20/тик)
 *   mailing_optout — отписка: персональный токен в каждом письме;
 *                    opted_out=1 исключает адресата из всех рассылок
 *
 * Таблицы модуля (не компоненты NetCat): обычный SQL, MyISAM + utf8 как база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `mailings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `created_by` int(11) NOT NULL,
            `filters` text NOT NULL,
            `subject` varchar(200) NOT NULL,
            `body` text NOT NULL,
            `recipients_total` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $nc->db->query("CREATE TABLE IF NOT EXISTS `mailing_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mailing_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `email` varchar(255) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'queued',
            `queued_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `mailing` (`mailing_id`),
            KEY `user` (`user_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $nc->db->query("CREATE TABLE IF NOT EXISTS `mailing_optout` (
            `user_id` int(11) NOT NULL,
            `token` char(64) NOT NULL,
            `opted_out` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            `opted_out_at` datetime DEFAULT NULL,
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `token` (`token`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `mailings`");
        $nc->db->query("DROP TABLE IF EXISTS `mailing_log`");
        $nc->db->query("DROP TABLE IF EXISTS `mailing_optout`");
    },
);
