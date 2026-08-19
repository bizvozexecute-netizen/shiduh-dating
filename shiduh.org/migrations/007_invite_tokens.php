<?php
/*
 * Таблица токенов-приглашений (ТЗ ROADMAP Раздел XI).
 *
 * Шадхан заводит кандидату аккаунт-заготовку и получает персональную ссылку
 * с одноразово сгенерированным токеном. По ссылке кандидат логинится в свой
 * аккаунт (authorize_by_id) и дозаполняет анкету через ИИ-консультанта.
 *
 *   token             — bin2hex(random_bytes(32)) = 64 hex-символа, неугадываем
 *   candidate_user_id — аккаунт-заготовка кандидата (владелец анкеты)
 *   created_by        — шадхан, создавший приглашение
 *   expires_at        — срок жизни ссылки (30 дней от создания)
 *   used_at           — первый вход по ссылке (аналитика; ссылка многоразова)
 *   claimed_at        — кандидат присвоил аккаунт (задал свой email+пароль)
 *
 * Таблица модуля (не компонент NetCat): обычный SQL, MyISAM + utf8 как база.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("CREATE TABLE IF NOT EXISTS `invite_tokens` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `token` char(64) NOT NULL,
            `candidate_user_id` int(11) NOT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `claimed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`),
            KEY `candidate` (`candidate_user_id`),
            KEY `created_by` (`created_by`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    },

    'down' => function ($nc) {
        $nc->db->query("DROP TABLE IF EXISTS `invite_tokens`");
    },
);
