<?php
/*
 * Проверка анкеты кандидата на сервере (этап «До идеала», A7).
 *
 * Что было: единственная защита — атрибуты required в HTML и календарь
 * бандла без нижней границы; в базе 224 даты рождения «по умолчанию» и
 * анкеты моложе 18. Исполняется из class/204/AddCond.html (добавление
 * шадханом, шаблон 209) и class/204/EditCond.html (правка, шаблон 206) —
 * ядро включает их ДО записи: $posting = 0 отменяет запись, тексты ошибок
 * форма печатает у полей ($nc_sh_errors), а мастер открывает нужный шаг
 * ($nc_sh_open_step).
 *
 * Правка идёт с partial=1 (ядро пишет только присланные поля): поле,
 * которого нет в POST, не проверяется — так живут формы выбора шадхана
 * (217/247) и загрузка файла отдельным запросом. Присланное пустым
 * обязательное поле — ошибка.
 */

require_once dirname(__FILE__) . '/auth_rules_lib.php';

define('ANKETA_MIN_AGE', AUTH_MIN_AGE);   // как при регистрации и в подборе
define('ANKETA_MAX_AGE', AUTH_MAX_AGE);   // 100
define('ANKETA_HEIGHT_MIN', 100);          // сантиметры; в базе встречались «1.75»
define('ANKETA_HEIGHT_MAX', 250);
/*
 * Поля, которые мастер не кладёт в черновик localStorage: специальные
 * категории по ст. 10 152-ФЗ — здоровье, а также происхождение и религиозные
 * убеждения; плюс доход. Имена без префикса f_ (атрибут формы не должен
 * быть первым вхождением «f_Health_status» на странице).
 */
define('ANKETA_DRAFT_SKIP', 'Health_status,Health_conditions,Medications,Income_property,'
    . 'Jewishness,Origin,Mother_Origin,Father_Origin,Degree_of_compliance,Course_of_compliance,'
    . 'Belonging_denomination,Your_ravin,Your_ravin_contact,Your_sinagoga,Rabbinate_registration,Observance_growth');

/* Обязательные поля и подписи для сообщений (порядок — порядок шагов). */
function anketa_required_fields() {
    return array(
        'Name'           => 'Ф.И.О.',
        'Gender'         => 'Пол',
        'Date_of_birth'  => 'Дата рождения',
        'Place_of_birth' => 'Место рождения',
        'Country'        => 'Страна проживания',
        'City'           => 'Город проживания',
        'Height'         => 'Рост',
        'Phone'          => 'Телефон',
        'Email'          => 'Email',
        'Jewishness'     => 'Еврейство',
        'Origin'         => 'Происхождение',
        'Marital_status' => 'Семейное положение',
        'Education'      => 'Образование',
        'Specialization' => 'Специальность',
    );
}

/*
 * Шаг мастера для поля — чтобы после отказа сервера открыть тот шаг, где
 * ошибка. Тот же порядок, что в api/anketa_form.inc.php.
 */
function anketa_field_step($field) {
    static $map = array(
        1 => array('Name', 'Jewish_name', 'Gender', 'Date_of_birth', 'Place_of_birth', 'Country', 'City', 'Height', 'Phone', 'Email', 'Open'),
        2 => array('Jewishness', 'Origin', 'Mother_Origin', 'Father_Origin', 'Marital_status', 'Number_of_children', 'Are_you_ready_to_move', 'Country_of_relocation'),
        3 => array('Degree_of_compliance', 'Course_of_compliance', 'Belonging_denomination', 'Your_ravin', 'Your_ravin_contact', 'Your_sinagoga', 'Observance_growth', 'Rabbinate_registration', 'Diaspora_openness'),
        4 => array('Education', 'Specialization', 'What_does', 'Hobby_description', 'Bad_habits', 'Sport_attitude', 'Languages_spoken', 'Introvert_extrovert', 'Housing_after_marriage', 'Health_status', 'Consent_sensitive', 'Income_property', 'Health_conditions', 'Medications'),
        5 => array('Search_gender', 'Search_height_start', 'Search_height_end', 'Search_age_start', 'Search_age_end', 'Search_place_of_birth', 'Search_country', 'Search_belonging_denomination', 'Search_education', 'Search_was_previously_married', 'Search_number_of_children', 'Search_are_you_ready_to_move', 'Search_for_shadhan'),
        6 => array('Photo_questionnaire', 'Photo_avatar', 'Birthcertificates', 'Another_document_Jewishness'),
    );
    foreach ($map as $step => $fields) {
        if (in_array($field, $fields, true)) {
            return $step;
        }
    }
    return 1;
}

/*
 * Дата рождения из POST: три части f_Date_of_birth_day/_month/_year
 * (скрытые поля, их заполняет мастер) или одно поле f_Date_of_birth
 * («ГГГГ-ММ-ДД» из type="date" без JS, «ДД.ММ.ГГГГ» из старого календаря).
 * Возвращает array(y, m, d), null — не прислано, false — прислано, но
 * не дата.
 */
function anketa_dob_from_post(array $post) {
    $parts = array();
    foreach (array('year', 'month', 'day') as $k) {
        $parts[$k] = isset($post['f_Date_of_birth_' . $k]) ? trim((string)$post['f_Date_of_birth_' . $k]) : '';
    }
    if ($parts['year'] !== '' || $parts['month'] !== '' || $parts['day'] !== '') {
        $y = (int)$parts['year']; $m = (int)$parts['month']; $d = (int)$parts['day'];
        return ($y > 0 && checkdate($m, $d, $y)) ? array($y, $m, $d) : false;
    }
    if (isset($post['f_Date_of_birth']) && trim((string)$post['f_Date_of_birth']) !== '') {
        $ymd = auth_dob_parse($post['f_Date_of_birth']);
        return $ymd ? $ymd : false;
    }
    return null;
}

/*
 * $post — сырые значения (input->fetch_post, без слэшей). $mode: 'add' —
 * все обязательные поля должны быть; 'edit' — проверяются только
 * присланные. Возвращает array(поле => текст ошибки).
 */
function anketa_validate(array $post, $mode = 'add', $today = null) {
    $errors = array();
    $today = $today === null ? time() : (int)$today;
    $edit = ($mode === 'edit');

    $val = function ($name) use ($post) {
        return isset($post['f_' . $name]) ? $post['f_' . $name] : null;
    };
    $sent = function ($name) use ($post) {
        return array_key_exists('f_' . $name, $post);
    };
    $blank = function ($v) {
        if (is_array($v)) {
            return count(array_filter($v, function ($x) { return trim((string)$x) !== ''; })) === 0;
        }
        return trim((string)$v) === '' || (string)$v === '0';
    };

    foreach (anketa_required_fields() as $name => $label) {
        if ($name === 'Date_of_birth') {
            continue; // отдельно ниже
        }
        if ($edit && !$sent($name)) {
            continue;
        }
        if ($blank($val($name))) {
            $errors[$name] = 'Заполните поле «' . $label . '».';
        }
    }

    // дата рождения: обязательна при добавлении; при правке — если прислана
    $dob = anketa_dob_from_post($post);
    $dob_sent = array_key_exists('f_Date_of_birth', $post) || array_key_exists('f_Date_of_birth_year', $post)
        || array_key_exists('f_Date_of_birth_month', $post) || array_key_exists('f_Date_of_birth_day', $post);
    if ($dob === null) {
        // присланная пустой дата — тоже ошибка: иначе ядро запишет NULL в NOT NULL
        if (!$edit || $dob_sent) {
            $errors['Date_of_birth'] = 'Укажите дату рождения.';
        }
    } elseif ($dob === false) {
        $errors['Date_of_birth'] = 'Дата рождения указана неверно.';
    } else {
        $age = auth_age_years($dob, $today);
        if ($age < 0) {
            $errors['Date_of_birth'] = 'Дата рождения ещё не наступила.';
        } elseif ($age < ANKETA_MIN_AGE) {
            $errors['Date_of_birth'] = 'Кандидату должно быть не меньше ' . ANKETA_MIN_AGE . ' лет.';
        } elseif ($age > ANKETA_MAX_AGE) {
            $errors['Date_of_birth'] = 'Проверьте год рождения.';
        }
    }

    $name = trim((string)$val('Name'));
    if (!isset($errors['Name']) && $name !== '' && mb_strlen($name, 'UTF-8') > 255) {
        $errors['Name'] = 'Слишком длинное имя.';
    }

    $gender = $val('Gender');
    if (!isset($errors['Gender']) && $gender !== null && trim((string)$gender) !== '' && !in_array((int)$gender, array(1, 2), true)) {
        $errors['Gender'] = 'Выберите пол.';
    }

    $height = $val('Height');
    if (!isset($errors['Height']) && $height !== null && trim((string)$height) !== '') {
        $h = str_replace(',', '.', trim((string)$height));
        if (!is_numeric($h) || (float)$h < ANKETA_HEIGHT_MIN || (float)$h > ANKETA_HEIGHT_MAX) {
            $errors['Height'] = 'Рост в сантиметрах: от ' . ANKETA_HEIGHT_MIN . ' до ' . ANKETA_HEIGHT_MAX . '.';
        }
    }

    $phone = $val('Phone');
    if (!isset($errors['Phone']) && $phone !== null && trim((string)$phone) !== '' && !auth_phone_ok($phone)) {
        $errors['Phone'] = 'Телефон: от 7 до 15 цифр.';
    }

    $email = $val('Email');
    if (!isset($errors['Email']) && $email !== null && trim((string)$email) !== ''
        && !filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL)) {
        $errors['Email'] = 'Похоже, это не e-mail.';
    }

    foreach (array('Search_height_start', 'Search_height_end') as $f) {
        $v = $val($f);
        if ($v !== null && trim((string)$v) !== '' && ((float)$v < ANKETA_HEIGHT_MIN || (float)$v > ANKETA_HEIGHT_MAX)) {
            $errors[$f] = 'Рост в сантиметрах: от ' . ANKETA_HEIGHT_MIN . ' до ' . ANKETA_HEIGHT_MAX . '.';
        }
    }
    foreach (array('Search_age_start', 'Search_age_end') as $f) {
        $v = $val($f);
        if ($v !== null && trim((string)$v) !== '' && ((int)$v < ANKETA_MIN_AGE || (int)$v > ANKETA_MAX_AGE)) {
            $errors[$f] = 'Возраст: от ' . ANKETA_MIN_AGE . ' до ' . ANKETA_MAX_AGE . '.';
        }
    }

    return $errors;
}

/* Первый (по порядку шагов) шаг с ошибкой — его откроет мастер. */
function anketa_errors_step(array $errors) {
    $step = 0;
    foreach (array_keys($errors) as $field) {
        $s = anketa_field_step($field);
        if ($step === 0 || $s < $step) {
            $step = $s;
        }
    }
    return $step;
}

/* Разметка ошибки у поля; пусто, если ошибки нет. */
function anketa_error_html(array $errors, $field) {
    if (empty($errors[$field])) {
        return '';
    }
    return '<span class="sh-field__error" role="alert" id="err-' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</span>';
}

/*
 * Экранирование значения в атрибут/текст. В форме правки ядро уже прогнало
 * $f_* через htmlspecialchars (convert2txt), при добавлении и после отказа
 * значения сырые — поэтому сначала снимаем сущности, потом ставим: результат
 * один и тот же, двойного &amp;quot; не бывает.
 */
function anketa_v($v) {
    $s = (string)(is_array($v) ? '' : $v);
    return htmlspecialchars(htmlspecialchars_decode($s, ENT_QUOTES), ENT_QUOTES, 'UTF-8');
}

/* Границы для type="date": от −100 до −18 лет. */
function anketa_dob_bounds($today = null) {
    return auth_dob_bounds($today);
}
