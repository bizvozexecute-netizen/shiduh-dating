<?php
/*
 * UNIQUE на ответ опроса (ревью Э2.2-c, находка о гонке).
 *
 * Ответы приходят двумя путями (форма опроса + инструмент диалога), и без
 * ограничения гонка дублировала кейс внутри версии — профиль суммировал его
 * дважды и молча перекашивал проценты. Сериализацию делает GET_LOCK в
 * scoring_record_answer; UNIQUE — страховка на уровне схемы.
 *
 * Перед добавлением индекса возможные исторические дубли схлопываются
 * (остаётся самый ранний ответ — тот, что реально сформировал профиль).
 */
return array(
    'up' => function ($nc) {
        // дедуп: для каждой (user_id, case_id, version) оставляем MIN(id)
        $nc->db->query(
            "DELETE a FROM `assessment_answers` a JOIN `assessment_answers` b "
            . "ON a.`user_id`=b.`user_id` AND a.`case_id`=b.`case_id` "
            . "AND a.`version`=b.`version` AND a.`id`>b.`id`"
        );
        $nc->db->query(
            "ALTER TABLE `assessment_answers` "
            . "ADD UNIQUE KEY `user_case_version` (`user_id`, `case_id`, `version`)"
        );
    },

    'down' => function ($nc) {
        $nc->db->query(
            "ALTER TABLE `assessment_answers` DROP KEY `user_case_version`"
        );
    },
);
