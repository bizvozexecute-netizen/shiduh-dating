<?php
/*
 * Пароли: bcrypt вместо MD5 (этап «До идеала», C6).
 *
 * Ядро хранило MD5 пароля без соли (`$MYSQL_ENCRYPT = "MD5"`) и сравнивало
 * его SQL-функцией: утечка таблицы User = радужная таблица за вечер. Здесь —
 * единственное место, где пароль хэшируется и сверяется; ядро в шести
 * точках зовёт эти функции (список патчей — DEPLOY.prod.md §2.1).
 *
 * Переход без принудительного сброса: старые MD5 (32 hex) и SHA1 (40 hex)
 * узнаются по виду и проверяются как раньше, а при первом же удачном входе
 * заменяются bcrypt-хэшем (rehash-on-login). Бэкфилл невозможен —
 * из MD5 пароль не восстановить.
 *
 * Файл не зависит от ядра: его подключают и nc_user, и add.php до загрузки
 * остального.
 */

define('SHIDUH_BCRYPT_COST', 10);

/* Хэш для записи в User.Password (60 символов, $2y$…). */
function shiduh_password_hash($plain) {
    return password_hash((string)$plain, PASSWORD_BCRYPT, array('cost' => SHIDUH_BCRYPT_COST));
}

/*
 * Сверка с сохранённым значением любого из трёх поколений:
 *   $2y$…       — bcrypt (текущее);
 *   32 hex      — MD5 без соли (ядро до C6);
 *   40 hex      — SHA1 без соли (если $MYSQL_ENCRYPT когда-то был SHA).
 * Сравнение — константное по времени: длина ответа не должна подсказывать,
 * сколько символов совпало.
 */
function shiduh_password_verify($plain, $stored) {
    $plain = (string)$plain;
    $stored = (string)$stored;
    if ($stored === '' || $plain === '') {
        return false;
    }
    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0) {
        return password_verify($plain, $stored);
    }
    if (preg_match('/^[0-9a-f]{32}$/i', $stored)) {
        return hash_equals(strtolower($stored), md5($plain));
    }
    if (preg_match('/^[0-9a-f]{40}$/i', $stored)) {
        return hash_equals(strtolower($stored), sha1($plain));
    }
    return false;
}

/* Хранимое значение надо заменить bcrypt-хэшем при ближайшем удачном входе. */
function shiduh_password_needs_rehash($stored) {
    $stored = (string)$stored;
    if (strpos($stored, '$2y$') !== 0) {
        return true;
    }
    return password_needs_rehash($stored, PASSWORD_BCRYPT, array('cost' => SHIDUH_BCRYPT_COST));
}

/*
 * SQL-литерал с хэшем — на замену MD5('…') в INSERT/UPDATE ядра.
 * $plain — ЧИСТЫЙ пароль: там, где ядро подставляло в запрос уже
 * экранированную переменную, вызывающий обязан снять экранирование сам.
 */
function shiduh_password_sql($db, $plain) {
    return "'" . $db->escape(shiduh_password_hash($plain)) . "'";
}
