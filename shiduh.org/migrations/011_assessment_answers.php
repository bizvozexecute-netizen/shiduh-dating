<?php
/*
 * Ответы на кейсы ценностного опроса (ТЗ Э2.3).
 *
 * Роль LLM здесь узкая: классифицировать ОДИН ответ (Э2.3-a). Итоговый
 * профиль считает КОД по сохранённым баллам (Э2.3-b) — поэтому баллы
 * каждого ответа хранятся отдельно и навсегда: только так повтор с тем же
 * набором ответов даёт тот же профиль, а не «как модель сегодня решит».
 *
 *   case_id     — кейс из assessment_cases
 *   answer      — что ответил человек (своими словами)
 *   scores      — JSON {измерение: балл}, результат классификации
 *   confidence  — уверенность классификатора 0..1
 *   version     — версия профиля, в которую вошёл ответ (Э2.3-c: повтор
 *                 опроса — новая версия, прошлые ответы не переписываются)
 *
 * Таблица модуля: обычный SQL, MyISAM + utf8 как остальная база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `assessment_answers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `case_id` int(11) NOT NULL,
            `answer` text NOT NULL,
            `scores` text,
            `confidence` decimal(3,2) NOT NULL DEFAULT 0,
            `version` int(11) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_version` (`user_id`, `version`),
            KEY `user_case` (`user_id`, `case_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `assessment_answers`");
    },
);
