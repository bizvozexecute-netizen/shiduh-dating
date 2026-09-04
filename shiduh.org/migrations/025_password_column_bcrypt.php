<?php
/*
 * Ширина колонки User.Password под bcrypt (этап «До идеала», C6).
 *
 * MD5 занимал 32 символа, колонка — varchar(45); bcrypt-хэш — 60. Без
 * расширения первый же вход после патча ядра обрезал бы хэш при
 * rehash-on-login, и человек не смог бы войти второй раз.
 *
 * Сами хэши не трогаем: из MD5 пароль не восстановить, замена происходит
 * при удачном входе (api/password_lib.php). down отказывает, если в таблице
 * уже есть bcrypt-хэши: сужение колонки их порезало бы, и это была бы потеря
 * данных, а не откат.
 */

return array(
    'up' => function ($nc) {
        $nc->db->query("ALTER TABLE `User` MODIFY `Password` varchar(255) NOT NULL DEFAULT ''");
    },

    'down' => function ($nc) {
        $n = (int)$nc->db->get_var("SELECT COUNT(*) FROM `User` WHERE `Password` LIKE '\$2y\$%'");
        if ($n > 0) {
            throw new Exception("откат невозможен: $n bcrypt-хэшей не поместятся в varchar(45)");
        }
        $nc->db->query("ALTER TABLE `User` MODIFY `Password` varchar(45) NOT NULL DEFAULT ''");
    },
);
