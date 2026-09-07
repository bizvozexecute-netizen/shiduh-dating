<?php
/*
 * Правила регистрации (этап «До идеала», A2): возраст, пароль, согласия —
 * на сервере, а не только в браузере.
 *
 * Что было. Дату рождения собирали три скрытых поля с «01.01.2024» по
 * умолчанию, которые заполнял календарь бандла: у кого он не сработал,
 * тот регистрировался «двухлетним» — в базе 224 таких даты и три реальных
 * несовершеннолетних. Минимальной длины пароля не было (pass_min = 0),
 * галочка согласия стояла заранее.
 *
 * Здесь чистые функции без ядра: их зовёт AddCond регистрации и тесты
 * через php -r. Ошибки возвращаются по полям — форма печатает каждую у
 * своего поля, а не одной строкой вверху.
 */

/* Порог совершеннолетия — тот же, что MATCH_MIN_AGE в api/match_lib.php:
   иначе анкета, принятая при регистрации, выпадала бы из подбора. Тест
   сверяет оба значения. */
if (!defined('AUTH_MIN_AGE')) {
    define('AUTH_MIN_AGE', 18);
}
/* Верхняя граница — не «возрастная дискриминация», а фильтр опечаток в
   годе: 1925 вместо 1995. */
if (!defined('AUTH_MAX_AGE')) {
    define('AUTH_MAX_AGE', 100);
}
if (!defined('AUTH_PASS_MIN_DEFAULT')) {
    define('AUTH_PASS_MIN_DEFAULT', 8);
}

/*
 * Дата из поля: «ГГГГ-ММ-ДД» (type="date") или «ДД.ММ.ГГГГ» (старый
 * календарь бандла и привычка руками). Возвращает array(y, m, d) или null.
 */
function auth_dob_parse($raw) {
    $raw = trim((string)$raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
        $y = (int)$m[3]; $mo = (int)$m[2]; $d = (int)$m[1];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }
    return array($y, $mo, $d);
}

/* Полных лет на дату $today (timestamp; по умолчанию сейчас). */
function auth_age_years(array $ymd, $today = null) {
    $today = $today === null ? time() : (int)$today;
    $ty = (int)date('Y', $today); $tm = (int)date('n', $today); $td = (int)date('j', $today);
    $age = $ty - $ymd[0];
    if ($tm < $ymd[1] || ($tm === $ymd[1] && $td < $ymd[2])) {
        $age--;
    }
    return $age;
}

/*
 * Границы для атрибутов min/max поля даты: браузер не даст выбрать
 * запрещённую дату, но проверка всё равно серверная — атрибут правится в
 * инспекторе за секунду.
 */
function auth_dob_bounds($today = null) {
    $today = $today === null ? time() : (int)$today;
    return array(
        'min' => date('Y-m-d', strtotime('-' . AUTH_MAX_AGE . ' years', $today)),
        'max' => date('Y-m-d', strtotime('-' . AUTH_MIN_AGE . ' years', $today)),
    );
}

/* Телефон: 7–15 цифр после очистки (E.164 допускает до 15). */
function auth_phone_ok($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    $len = strlen($digits);
    return $len >= 7 && $len <= 15;
}

/*
 * Проверка полей регистрации. $in — сырые значения из POST (без слэшей):
 *   name, dob, phone, password1, password2, age18 (bool), agreed (bool),
 *   type ('1' кандидат / '2' шадхан; отсутствует — не проверяется).
 * Возвращает array(поле => текст ошибки); пустой массив — всё в порядке.
 * Тексты — для человека у поля, поэтому короткие и без кодов.
 */
function auth_register_validate(array $in, $pass_min = AUTH_PASS_MIN_DEFAULT, $today = null) {
    $errors = array();
    $pass_min = (int)$pass_min > 0 ? (int)$pass_min : AUTH_PASS_MIN_DEFAULT;

    $name = trim(isset($in['name']) ? (string)$in['name'] : '');
    if ($name === '') {
        $errors['name'] = 'Укажите имя.';
    } elseif (mb_strlen($name, 'UTF-8') < 2) {
        $errors['name'] = 'Имя слишком короткое.';
    }

    $raw_dob = isset($in['dob']) ? (string)$in['dob'] : '';
    if (trim($raw_dob) === '') {
        $errors['dob'] = 'Укажите дату рождения.';
    } else {
        $dob = auth_dob_parse($raw_dob);
        if (!$dob) {
            $errors['dob'] = 'Дата рождения указана неверно.';
        } else {
            $age = auth_age_years($dob, $today);
            if ($age < 0) {
                $errors['dob'] = 'Дата рождения ещё не наступила.';
            } elseif ($age < AUTH_MIN_AGE) {
                $errors['dob'] = 'Регистрация — с ' . AUTH_MIN_AGE . ' лет.';
            } elseif ($age > AUTH_MAX_AGE) {
                $errors['dob'] = 'Проверьте год рождения.';
            }
        }
    }

    // Тип — только кандидат или шадхан: подмена select даёт «раввина» или
    // мусор в Type; сама регистрация шадханом — штатный путь (?shadchan=1).
    if (isset($in['type']) && !in_array((string)$in['type'], array('1', '2'), true)) {
        $errors['type'] = 'Выберите, кто регистрируется.';
    }

    if (empty($in['age18'])) {
        $errors['age18'] = 'Подтвердите, что вам есть ' . AUTH_MIN_AGE . ' лет.';
    }
    if (empty($in['agreed'])) {
        $errors['agreed'] = 'Нужно принять условия пользовательского соглашения.';
    }

    $phone = isset($in['phone']) ? (string)$in['phone'] : '';
    if (trim($phone) === '') {
        $errors['phone'] = 'Укажите телефон.';
    } elseif (!auth_phone_ok($phone)) {
        $errors['phone'] = 'Телефон указан неверно.';
    }

    $p1 = isset($in['password1']) ? (string)$in['password1'] : '';
    $p2 = isset($in['password2']) ? (string)$in['password2'] : '';
    if (mb_strlen($p1, 'UTF-8') < $pass_min) {
        $errors['password1'] = 'Пароль — не короче ' . $pass_min . ' символов.';
    } elseif ($p1 !== $p2) {
        $errors['password2'] = 'Пароли не совпадают.';
    }

    return $errors;
}
