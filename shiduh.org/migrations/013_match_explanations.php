<?php
/*
 * Кэш обоснований пар от LLM (ТЗ Э3.3-a).
 *
 *   anketa_a / anketa_b — пара, хранится нормализованно (a < b)
 *   shadhan_id          — кто сгенерировал первым (аудит + часовой лимит)
 *   source_hash         — md5 фактов, из которых собран промпт: изменение
 *                         профиля/анкеты меняет хэш и инвалидирует кэш
 *   explanation         — текст обоснования
 *   model               — какая модель отвечала
 *
 * Кэш общий для всех шадханов: обоснование строится из компонентов балла
 * (совпадение ценностей/языков любви), а они от шадхана не зависят —
 * персональны только ВЕСА, но они в промпт не входят.
 *
 * Таблица модуля: обычный SQL, MyISAM + utf8 как остальная база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `match_explanations` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `anketa_a` int(11) NOT NULL,
            `anketa_b` int(11) NOT NULL,
            `shadhan_id` int(11) NOT NULL,
            `source_hash` varchar(32) NOT NULL,
            `explanation` text NOT NULL,
            `model` varchar(64) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `pair_hash` (`anketa_a`, `anketa_b`, `source_hash`),
            KEY `shadhan_time` (`shadhan_id`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `match_explanations`");
    },
);
