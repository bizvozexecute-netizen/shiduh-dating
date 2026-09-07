<?php
/*
 * Правила пароля при регистрации (этап «До идеала», A2).
 *
 * Было: pass_min = 0 в обоих каталогах. Ядро проверяет минимальную длину
 * только при ненулевом значении (netcat/message_fields.php, «if ($pass_min
 * && …)»), то есть пароль из одного символа принимался. Ставим 8 — это
 * нижняя граница, с которой bcrypt (C6) имеет смысл: короткий пароль
 * перебирается и с дорогим хэшем.
 *
 * agreed = 1 уже стоит, но закрепляем: форма больше не предустанавливает
 * галочку согласия (A2), и серверная проверка из формальности становится
 * настоящей — без явной галочки регистрация не проходит.
 *
 * Механизм не наш: обе настройки читает ядро; мы лишь задаём значения, как
 * 018 для ip_check_level. Число 8 продублировано атрибутом minlength в
 * форме и в подсказке правил — источником остаётся эта настройка.
 */
return array(
    'up' => function ($nc) {
        $db = $nc->db;
        foreach (array('pass_min' => 8, 'agreed' => 1) as $key => $val) {
            $db->query("SELECT DISTINCT `Catalogue_ID` FROM `Settings` "
                . "WHERE `Key`='$key' AND `Module`='auth'", ARRAY_A);
            $rows = (array)$db->last_result;
            foreach ($rows as $r) {
                $cat = (int)$r['Catalogue_ID'];
                $db->query("UPDATE `Settings` SET `Value`='$val' "
                    . "WHERE `Key`='$key' AND `Module`='auth' "
                    . "AND `Catalogue_ID`=$cat AND `Value`+0 < $val");
            }
            // настройки не было вовсе — заводим для общих (0) и каталога сайта (1)
            if (!$rows) {
                foreach (array(0, 1) as $cat) {
                    $db->query("INSERT INTO `Settings` SET `Key`='$key', "
                        . "`Value`='$val', `Module`='auth', `Catalogue_ID`=$cat");
                }
            }
        }
    },

    'down' => function ($nc) {
        // Возврат к «без минимума» — как было до миграции (на проде pass_min
        // был 0 в обоих каталогах; иное прежнее значение откат не восстановит).
        // agreed не трогаем: он и до неё был равен 1. Строки, заведённые up
        // для отсутствовавших каталогов, остаются со значением 0.
        $nc->db->query("UPDATE `Settings` SET `Value`='0' "
            . "WHERE `Key`='pass_min' AND `Module`='auth'");
    },
);
