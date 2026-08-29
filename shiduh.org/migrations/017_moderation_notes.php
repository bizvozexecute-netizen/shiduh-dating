<?php
/*
 * Заметки модерации («сигналы внимания», ROADMAP X-3).
 *
 * ПОЧЕМУ ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ ПОЛЕ АНКЕТЫ. Сигнал — не характеристика
 * человека и не часть его профиля: это разовое наблюдение за поведением в
 * переписке, адресованное живому шадхану. Поле в Message204 сделало бы из
 * него ярлык, который поедет в подбор, резюме и выгрузки. Заметка живёт
 * отдельно, её можно закрыть, и она не влияет ни на один алгоритм.
 *
 *   user_id     — о ком сигнал
 *   kind        — категория: aggression | incoherence | distress
 *                 (поведенческие и наблюдаемые, без клиники — risk_kinds())
 *   note        — нейтральная формулировка БЕЗ цитат из переписки
 *   source      — кто поднял: 'ai' (инструмент консультанта) или 'human'
 *   created_at  — когда
 *   resolved_at / resolved_by — шадхан отметил, что посмотрел
 *
 * Таблица модуля: обычный SQL, MyISAM + utf8 как остальная база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `moderation_notes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `kind` varchar(32) NOT NULL,
            `note` varchar(500) NOT NULL DEFAULT '',
            `source` varchar(16) NOT NULL DEFAULT 'ai',
            `created_at` datetime NOT NULL,
            `resolved_at` datetime DEFAULT NULL,
            `resolved_by` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_open` (`user_id`, `resolved_at`),
            KEY `user_kind_day` (`user_id`, `kind`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `moderation_notes`");
    },
);
