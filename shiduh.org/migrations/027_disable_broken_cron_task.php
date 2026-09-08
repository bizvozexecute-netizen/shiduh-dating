<?php
/*
 * Убрать сломанную задачу планировщика NetCat (этап «До идеала», C13; ROADMAP B-5).
 *
 * В CronTasks стояла строка «каждую минуту» на /netcat/modules/
 * check_birthday_candidates/index.php (с хвостовым табом) — по этому адресу
 * файла нет (он в /check_birthday_candidates/), а если бы был, старый скрипт
 * слал бы поздравление 1440 раз в день: дедупликации в нём не было.
 * Поздравления теперь шлёт переписанный скрипт из системного cron раз в
 * день (DEPLOY, §Cron), задача ядра не нужна. down возвращает строку с тем
 * же (несуществующим) адресом и тем же расписанием — откат ничего не
 * оживляет, но и не теряет.
 */
return array(
    'up' => function ($nc) {
        $nc->db->query("DELETE FROM `CronTasks` WHERE `Cron_Script_URL` LIKE '%check_birthday_candidates%'");
    },

    'down' => function ($nc) {
        $db = $nc->db;
        if (!$db->get_var("SELECT 1 FROM `CronTasks` WHERE `Cron_Script_URL` LIKE '%check_birthday_candidates%' LIMIT 1")) {
            $db->query("INSERT INTO `CronTasks` SET `Cron_Minutes`=1, `Cron_Hours`=0, `Cron_Days`=0, `Cron_Months`=0, "
                . "`Cron_Weekdays`=0, `Cron_Script_URL`='/netcat/modules/check_birthday_candidates/index.php', `Cron_Launch`=NULL");
        }
    },
);
