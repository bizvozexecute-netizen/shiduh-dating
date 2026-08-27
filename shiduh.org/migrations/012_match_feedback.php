<?php
/*
 * Фидбек шадхана по подобранным парам (ТЗ Э3.3-b).
 *
 *   anketa_a / anketa_b — пара анкет, ХРАНИТСЯ НОРМАЛИЗОВАННО (a < b),
 *                         чтобы (X,Y) и (Y,X) были одной парой
 *   shadhan_id          — кто решил
 *   action              — proposed (предложить шидух) | rejected (отклонить)
 *   reason              — код причины отклонения из фиксированного списка
 *                         (values | love_language | geography | age | other);
 *                         причина понижает вес компонента в подборе ЭТОГО
 *                         шадхана — простая эвристика Э3.3-b
 *
 * Отклонённая пара исключается из выдачи подбора. Повторное решение по той
 * же паре перезаписывает прошлое (UNIQUE по паре+шадхану): шадхан вправе
 * передумать.
 *
 * Таблица модуля: обычный SQL, MyISAM + utf8 как остальная база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `match_feedback` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `anketa_a` int(11) NOT NULL,
            `anketa_b` int(11) NOT NULL,
            `shadhan_id` int(11) NOT NULL,
            `action` varchar(16) NOT NULL,
            `reason` varchar(32) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `pair_shadhan` (`anketa_a`, `anketa_b`, `shadhan_id`),
            KEY `pair` (`anketa_a`, `anketa_b`),
            KEY `shadhan` (`shadhan_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `match_feedback`");
    },
);
