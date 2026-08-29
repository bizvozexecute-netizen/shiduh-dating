<?php
/*
 * Привязка сессии к сети клиента (ROADMAP B-4, находка AUDIT №5).
 *
 * Было: `ip_check_level` = 0 для рабочего каталога — сессия не привязана
 * ни к чему, украденная кука работала с любого устройства и из любой сети
 * (а жила она год — второй половиной находки занимается $ADMIN_AUTHTIME в
 * vars.inc.php, там теперь 30 суток).
 *
 * Механизм НЕ наш: NetCat умеет это сам (nc_user::attempt_to_authorize),
 * настройка просто не была включена. Уровни:
 *   0 — не проверять;
 *   1..3 — совпадение первых N октетов IP;
 *   4 — точное совпадение IP.
 *
 * Ставим 2 (первые два октета, /16). Почему не 4: у мобильных операторов
 * адрес меняется в пределах пула на каждом переподключении, и точная
 * привязка разлогинивала бы людей по нескольку раз в день. /16 переживает
 * смену адреса внутри пула, но кука, унесённая в другую сеть, не работает.
 *
 * Если после выкатки пойдут жалобы «постоянно разлогинивает» — уровень
 * снижается настройкой в админке (модуль «Авторизация»), не кодом.
 */
return array(
    'up' => function ($nc) {
        $db = $nc->db;
        // рабочие каталоги сайта; 0 — общие настройки
        $db->query("SELECT DISTINCT `Catalogue_ID` FROM `Settings` "
            . "WHERE `Key`='ip_check_level' AND `Module`='auth'", ARRAY_A);
        $rows = (array)$db->last_result;
        foreach ($rows as $r) {
            $cat = (int)$r['Catalogue_ID'];
            $db->query("UPDATE `Settings` SET `Value`='2' "
                . "WHERE `Key`='ip_check_level' AND `Module`='auth' "
                . "AND `Catalogue_ID`=$cat AND `Value`+0 < 1");
        }
        // если настройки не было вовсе — заводим для каталога сайта
        if (!$rows) {
            $db->query("INSERT INTO `Settings` SET `Key`='ip_check_level', "
                . "`Value`='2', `Module`='auth', `Catalogue_ID`=1");
        }
    },

    'down' => function ($nc) {
        // Возврат к «не проверять» — как было до миграции.
        $nc->db->query("UPDATE `Settings` SET `Value`='0' "
            . "WHERE `Key`='ip_check_level' AND `Module`='auth'");
    },
);
