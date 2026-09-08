<?php
/*
 * Поля анкеты кандидата — семь шагов мастера (этап «До идеала», A7).
 *
 * Одна разметка для добавления (class/204/209/AddTemplate.html, шадхан) и
 * правки (class/204/206/RecordTemplate.html и его склейка Class.html):
 * раньше это были две копии по ~100 полей, расходившиеся по мелочам
 * (required не там, value не везде, разные списки). Подключается require
 * из шаблона — переменные $f_* остаются в области видимости шаблона.
 *
 * Ожидает: $nc_af_mode ('add' | 'edit'); $nc_sh_errors (из AddCond/EditCond,
 * может не быть); $arfloor, $class_id/$classID, $current_user — из ядра.
 *
 * Значения печатаются через anketa_v(): раньше «<?= $f_Name ?>» шёл в
 * атрибут как есть — имя с кавычкой ломало форму шадхану (хранимый XSS).
 * Подписи — сверху, не «плавающие»; ошибка — под полем. Переменные
 * файла с префиксом nc_af_, чтобы не задеть глобалы ядра.
 */
require_once dirname(__FILE__) . '/anketa_validate_lib.php';
require_once dirname(__FILE__) . '/consent_lib.php';

$nc_af_mode = isset($nc_af_mode) ? $nc_af_mode : 'edit';
$nc_af_edit = ($nc_af_mode === 'edit');
$nc_af_err = isset($nc_sh_errors) && is_array($nc_sh_errors) ? $nc_sh_errors : array();
$nc_af_pref = $nc_af_edit ? 'profile' : 'addcandidate';
$nc_af_ctpl = isset($class_id) && $class_id ? $class_id : (isset($classID) ? $classID : 204);
$nc_cns = consent_text();

// На первом показе формы добавления переменных $f_* ещё нет.
$nc_af_all = array('Name', 'Jewish_name', 'Gender', 'Date_of_birth', 'Date_of_birth_day', 'Date_of_birth_month', 'Date_of_birth_year',
    'Place_of_birth', 'Country', 'City', 'Height', 'Phone', 'Email', 'Open',
    'Jewishness', 'Origin', 'Mother_Origin', 'Father_Origin', 'Marital_status', 'Number_of_children', 'Are_you_ready_to_move', 'Country_of_relocation',
    'Father', 'Mother', 'Brother', 'Sister', 'Personal_family_structure_father', 'Personal_family_structure_mother',
    'Degree_of_compliance', 'Course_of_compliance', 'Belonging_denomination', 'Your_ravin', 'Your_ravin_contact', 'Your_sinagoga',
    'Observance_growth', 'Rabbinate_registration', 'Diaspora_openness',
    'Education', 'Specialization', 'What_does', 'Hobby_description', 'Bad_habits', 'Sport_attitude', 'Languages_spoken',
    'Introvert_extrovert', 'Housing_after_marriage', 'Health_status', 'Consent_sensitive', 'Consent_sensitive_at', 'Consent_sensitive_version',
    'Income_property', 'Health_conditions', 'Medications',
    'Search_gender', 'Search_height_start', 'Search_height_end', 'Search_age_start', 'Search_age_end', 'Search_place_of_birth',
    'Search_country', 'Search_belonging_denomination', 'Search_education', 'Search_was_previously_married', 'Search_number_of_children',
    'Search_are_you_ready_to_move', 'Search_for_shadhan');
for ($nc_af_i = 1; $nc_af_i <= 10; $nc_af_i++) {
    $nc_af_all[] = 'Age_of_children_' . $nc_af_i;
    $nc_af_all[] = 'Personal_family_structure_brother_' . $nc_af_i;
    $nc_af_all[] = 'Personal_family_structure_sister_' . $nc_af_i;
}
foreach ($nc_af_all as $nc_af_n) {
    if (!isset(${'f_' . $nc_af_n})) {
        ${'f_' . $nc_af_n} = '';
    }
}

// Выбранное значение списка: ядро даёт $f_X_id, после отказа сервера — $f_X из POST.
$nc_af_sel = array();
foreach (array('Gender', 'Country', 'Jewishness', 'Origin', 'Mother_Origin', 'Father_Origin', 'Marital_status', 'Number_of_children',
    'Are_you_ready_to_move', 'Course_of_compliance', 'Belonging_denomination', 'Education', 'Bad_habits', 'Search_gender',
    'Degree_of_compliance', 'Country_of_relocation', 'Search_country', 'Search_belonging_denomination', 'Search_education',
    'Search_was_previously_married', 'Search_number_of_children', 'Search_are_you_ready_to_move') as $nc_af_n) {
    $nc_af_sel[$nc_af_n] = isset(${'f_' . $nc_af_n . '_id'}) ? ${'f_' . $nc_af_n . '_id'} : ${'f_' . $nc_af_n};
}

// Дата рождения для type="date": части от ядра или из POST, иначе — из DATETIME строки.
$nc_af_dob = '';
if ((int)$f_Date_of_birth_year > 0) {
    $nc_af_dob = sprintf('%04d-%02d-%02d', (int)$f_Date_of_birth_year, (int)$f_Date_of_birth_month, (int)$f_Date_of_birth_day);
} elseif (is_string($f_Date_of_birth) && ($nc_af_ymd = auth_dob_parse(substr($f_Date_of_birth, 0, 10)))) {
    $nc_af_dob = sprintf('%04d-%02d-%02d', $nc_af_ymd[0], $nc_af_ymd[1], $nc_af_ymd[2]);
}
$nc_af_bounds = anketa_dob_bounds();
// $arfloor задаёт Settings.html списка; в add.php/message.php его нет
$arfloor = isset($arfloor) && is_array($arfloor) ? $arfloor : array('element' => '%ELEMENT', 'divider' => ',', 'prefix' => '', 'suffix' => '');
// после отказа с приложенным файлом $f_<файл> — массив из $_FILES
foreach (array('Photo_avatar', 'Birthcertificates', 'Another_document_Jewishness') as $nc_af_n) {
    if (!isset(${'f_' . $nc_af_n}) || is_array(${'f_' . $nc_af_n})) { ${'f_' . $nc_af_n} = ''; }
    if (!isset(${'f_' . $nc_af_n . '_name'}) || !is_string(${'f_' . $nc_af_n . '_name'})) { ${'f_' . $nc_af_n . '_name'} = ''; }
}

$nc_af_phone = $nc_af_edit ? $f_Phone : (isset($current_user['Phone']) ? $current_user['Phone'] : '');
$nc_af_email = $nc_af_edit ? $f_Email : (isset($current_user['Email']) ? $current_user['Email'] : '');

// ------------------------------------------------------------ помощники --
$nc_af_e = function ($field) use ($nc_af_err) { return anketa_error_html($nc_af_err, $field); };
$nc_af_cls = function ($field, $extra = '') use ($nc_af_err) {
    return 'form__item tpl-field' . ($extra !== '' ? ' ' . $extra : '') . (isset($nc_af_err[$field]) ? ' is-invalid' : '');
};
$nc_af_aria = function ($field) use ($nc_af_err) {
    return isset($nc_af_err[$field]) ? ' aria-invalid="true" aria-describedby="err-' . $field . '"' : '';
};
$nc_af_text = function ($field, $label, $value, $opts = array()) use ($nc_af_e, $nc_af_cls, $nc_af_aria) {
    $type = isset($opts['type']) ? $opts['type'] : 'text';
    $attrs = ' class="form__input tpl-field-input" id="f_' . $field . '" name="f_' . $field . '" type="' . $type . '"';
    $attrs .= ' value="' . anketa_v($value) . '"';
    if ($type === 'text' || $type === 'tel' || $type === 'email') { $attrs .= ' maxlength="255"'; }
    foreach (array('placeholder', 'min', 'max', 'step', 'autocomplete', 'inputmode', 'data-value') as $a) {
        if (isset($opts[$a])) { $attrs .= ' ' . $a . '="' . anketa_v($opts[$a]) . '"'; }
    }
    if (!empty($opts['required'])) { $attrs .= ' required'; }
    $attrs .= $nc_af_aria($field);
    return '<div class="' . $nc_af_cls($field, isset($opts['class']) ? $opts['class'] : '') . '"' . (!empty($opts['private']) ? ' data-sh-private' : '') . '>'
        . '<label class="form__item-name" for="f_' . $field . '">' . $label . '</label>'
        . (isset($opts['hint']) ? '<span class="sh-field__hint">' . $opts['hint'] . '</span>' : '')
        . '<input' . $attrs . '>' . $nc_af_e($field) . '</div>';
};
$nc_af_area = function ($field, $label, $value, $placeholder = '', $private = false) use ($nc_af_e, $nc_af_cls, $nc_af_aria) {
    return '<div class="' . $nc_af_cls($field, 'form__item--2col') . '"' . ($private ? ' data-sh-private' : '') . '>'
        . '<label class="form__item-name" for="f_' . $field . '">' . $label . '</label>'
        . ($private ? '<span class="sh-field__hint">По желанию, видно только шадхану</span>' : '')
        . '<textarea class="form__textarea" id="f_' . $field . '" name="f_' . $field . '" placeholder="' . anketa_v($placeholder) . '"' . $nc_af_aria($field) . '>'
        . anketa_v($value) . '</textarea>' . $nc_af_e($field) . '</div>';
};
// Список классификатора: nc_list_select ядра, аргументы как в прежних шаблонах.
// Ядро прогоняет строки через eval в двойных кавычках — атрибуты только в одинарных.
$nc_af_list = function ($field, $label, $table, $selected, $opts = array()) use ($nc_af_e, $nc_af_cls, $nc_af_aria) {
    $flag = isset($opts['flag']) ? $opts['flag'] : '';
    $open = "<select class='select__el' id='f_" . $field . "' name='f_" . $field . "'" . (!empty($opts['required']) ? ' required' : '')
        . str_replace('"', "'", $nc_af_aria($field)) . '>'
        . "<option data-placeholder='true' value=''>-- Выбрать --</option>";
    $html = nc_list_select($table, $field, $selected, $flag, '', $open, '<option value=\'$value_id\' $value_selected>$value_name</option>');
    return '<div class="' . $nc_af_cls($field) . '">'
        . '<label class="form__item-name" for="f_' . $field . '">' . $label . '</label>'
        . '<div' . (isset($opts['wrap_id']) ? ' id="' . $opts['wrap_id'] . '"' : '') . ' class="select form__select' . (isset($opts['wrap']) ? ' ' . $opts['wrap'] : '') . '">' . $html . '</div>'
        . $nc_af_e($field) . '</div>';
};
// Множественный выбор: nc_multilist_field ядра (сам печатает <select multiple>).
$nc_af_multi = function ($field, $label, $selected, $wrap = '') use ($nc_af_e, $nc_af_cls, $nc_af_ctpl, $arfloor) {
    $html = nc_multilist_field($field, 'class="select__el" id="f_' . $field . '"', 'select', $nc_af_ctpl, false,
        nc_array_to_string(is_array($selected) ? $selected : array(), $arfloor));
    return '<div class="' . $nc_af_cls($field) . '">'
        . '<label class="form__item-name" for="f_' . $field . '">' . $label . '</label>'
        . '<div class="select form__select' . ($wrap !== '' ? ' ' . $wrap : '') . '">' . $html . '</div>'
        . $nc_af_e($field) . '</div>';
};
$nc_af_step = function ($n, $title, $id) {
    return '<section class="form__body-section sh-step" data-sh-step="' . $n . '" data-sh-title="' . $title . '" id="' . $id . '">'
        . '<div class="form__body-container"><h2 class="form__body-title">' . $title . '</h2>';
};
?>
<div class="form__body">

<?= $nc_af_step(1, 'Основное', $nc_af_pref . '_personal_data') ?>
  <fieldset class="form__fieldset">
    <?php if ($nc_af_edit): ?>
    <div class="form__item form__item--2col tpl-field">
      <input type="hidden" name="f_Open" value="0">
      <label class="lk-questionnaire__switch">
        <input type="checkbox" name="f_Open" value="1" <?= $f_Open ? 'checked' : '' ?>>
        <div class="lk-questionnaire__text-2">Закрытая анкета</div>
        <span class="lk-questionnaire__slider lk-questionnaire__round"></span>
        <div class="lk-questionnaire__text-1">Открытая анкета</div>
      </label>
      <span class="sh-field__hint">Открытую анкету видят все шадханы и проверенные кандидаты, закрытую — только ваши шадханы.</span>
    </div>
    <?php endif; ?>
    <?= $nc_af_text('Name', 'Ф.И.О.', $f_Name, array('required' => 1, 'placeholder' => 'Фамилия, имя, отчество', 'autocomplete' => 'name')) ?>
    <?= $nc_af_text('Jewish_name', 'Еврейское имя', $f_Jewish_name, array('placeholder' => 'Если есть')) ?>
    <?= $nc_af_list('Gender', 'Пол', 'Sex', $nc_af_sel['Gender'], array('required' => 1, 'wrap_id' => 'candidateGenderSelect')) ?>
    <div class="<?= $nc_af_cls('Date_of_birth') ?>">
      <label class="form__item-name" for="f_Date_of_birth">Дата рождения</label>
      <input class="form__input tpl-field-input" id="f_Date_of_birth" name="f_Date_of_birth" type="date" data-sh-dob
             value="<?= anketa_v($nc_af_dob) ?>" min="<?= $nc_af_bounds['min'] ?>" max="<?= $nc_af_bounds['max'] ?>"
             data-sh-min-age="<?= ANKETA_MIN_AGE ?>" data-sh-max-age="<?= ANKETA_MAX_AGE ?>" required<?= $nc_af_aria('Date_of_birth') ?>>
      <span class="sh-field__hint">Кандидату должно быть не меньше <?= ANKETA_MIN_AGE ?> лет.</span>
      <?php /* части даты для ядра: заполняет мастер; без JS их соберёт AddCond/EditCond */ ?>
      <input type="hidden" name="f_Date_of_birth_day" value="<?= (int)$f_Date_of_birth_day > 0 ? (int)$f_Date_of_birth_day : '' ?>">
      <input type="hidden" name="f_Date_of_birth_month" value="<?= (int)$f_Date_of_birth_month > 0 ? (int)$f_Date_of_birth_month : '' ?>">
      <input type="hidden" name="f_Date_of_birth_year" value="<?= (int)$f_Date_of_birth_year > 0 ? (int)$f_Date_of_birth_year : '' ?>">
      <input type="hidden" name="f_Date_of_birth_hours" value="00">
      <input type="hidden" name="f_Date_of_birth_minutes" value="00">
      <input type="hidden" name="f_Date_of_birth_seconds" value="00">
      <?= $nc_af_e('Date_of_birth') ?>
    </div>
    <?= $nc_af_text('Place_of_birth', 'Место рождения', $f_Place_of_birth, array('required' => 1, 'placeholder' => 'Город, страна')) ?>
    <?= $nc_af_list('Country', 'Страна проживания', 'Country', $nc_af_sel['Country'], array('required' => 1, 'wrap' => 'select--search form__select--country')) ?>
    <?= $nc_af_text('City', 'Город проживания', $f_City, array('required' => 1, 'placeholder' => 'Где живёте сейчас')) ?>
    <?= $nc_af_text('Height', 'Рост', $f_Height, array('required' => 1, 'type' => 'number', 'min' => ANKETA_HEIGHT_MIN, 'max' => ANKETA_HEIGHT_MAX, 'step' => 'any', 'placeholder' => 'В сантиметрах', 'inputmode' => 'numeric')) ?>
    <?= $nc_af_text('Phone', 'Телефон', $nc_af_phone, array('required' => 1, 'type' => 'tel', 'placeholder' => '+7', 'autocomplete' => 'tel', 'data-value' => $nc_af_phone)) ?>
    <?= $nc_af_text('Email', 'Email', $nc_af_email, array('required' => 1, 'type' => 'email', 'placeholder' => 'name@example.com', 'autocomplete' => 'email')) ?>
  </fieldset>
</div></section>

<?= $nc_af_step(2, 'Происхождение и семья', $nc_af_pref . '_family') ?>
  <fieldset class="form__fieldset">
    <?= $nc_af_list('Jewishness', 'Еврейство', 'Jewishness', $nc_af_sel['Jewishness'], array('required' => 1, 'wrap' => 'form__select--nation')) ?>
    <?= $nc_af_list('Origin', 'Происхождение', 'Origin', $nc_af_sel['Origin'], array('required' => 1, 'flag' => '1', 'wrap' => 'form__select--nation')) ?>
    <?= $nc_af_list('Mother_Origin', 'Происхождение мамы', 'Origin', $nc_af_sel['Mother_Origin'], array('flag' => '1', 'wrap' => 'form__select--nation')) ?>
    <?= $nc_af_list('Father_Origin', 'Происхождение папы', 'Origin', $nc_af_sel['Father_Origin'], array('flag' => '1', 'wrap' => 'form__select--nation')) ?>
    <?= $nc_af_list('Marital_status', 'Семейное положение', 'Marital_status', $nc_af_sel['Marital_status'], array('required' => 1)) ?>
    <?= $nc_af_list('Number_of_children', 'Количество детей', 'Number_of_children', $nc_af_sel['Number_of_children'], array('wrap' => 'form__select--childs')) ?>
    <?php for ($nc_af_i = 1; $nc_af_i <= 10; $nc_af_i++): ?>
    <div class="form__item form__item--child form__item--hidden">
      <label class="form__item-name" for="f_Age_of_children_<?= $nc_af_i ?>">Возраст ребёнка <?= $nc_af_i ?></label>
      <input class="form__input" id="f_Age_of_children_<?= $nc_af_i ?>" name="f_Age_of_children_<?= $nc_af_i ?>" type="number" min="0" max="100" step="any" value="<?= anketa_v(${'f_Age_of_children_' . $nc_af_i}) ?>" placeholder="Лет">
    </div>
    <?php endfor; ?>
<div class="form__item form__item--family-structure">
              <div class="form__item-name">Состав семьи</div>
              <div class="form__item-checkbox-row">
                <label class="checkbox form__checkbox form__checkbox--family-structure-father">
                  <input class="checkbox__input" type="checkbox" name="f_Father" <?= $f_Father ? 'checked' : '';?> >
                  <div class="checkbox__body">
                    <span class="checkbox__el">
                      <svg class="checkbox__el-icon">
                        <use xlink:href="/images/sprite.svg#checked"></use>
                      </svg>
                    </span>
                    <span class="checkbox__text">Папа</span>
                  </div>
                </label>
                <label class="checkbox form__checkbox form__checkbox--family-structure-mother">
                  <input class="checkbox__input" type="checkbox" name="f_Mother" <?= $f_Mother ? 'checked' : '';?> >
                  <div class="checkbox__body">
                    <span class="checkbox__el">
                      <svg class="checkbox__el-icon">
                        <use xlink:href="/images/sprite.svg#checked"></use>
                      </svg>
                    </span>
                    <span class="checkbox__text">Мама</span>
                  </div>
                </label>
                <label class="checkbox form__checkbox form__checkbox--family-structure-brother">
                  <input class="checkbox__input" type="checkbox" name="f_Brother" <?= $f_Brother ? 'checked' : '';?> >
                  <div class="checkbox__body">
                    <span class="checkbox__el">
                      <svg class="checkbox__el-icon">
                        <use xlink:href="/images/sprite.svg#checked"></use>
                      </svg>
                    </span>
                    <span class="checkbox__text">Брат</span>
                  </div>
                </label>
                <label class="checkbox form__checkbox form__checkbox--family-structure-sister">
                  <input class="checkbox__input" type="checkbox" name="f_Sister" <?= $f_Sister ? 'checked' : '';?> >
                  <div class="checkbox__body">
                    <span class="checkbox__el">
                      <svg class="checkbox__el-icon">
                        <use xlink:href="/images/sprite.svg#checked"></use>
                      </svg>
                    </span>
                    <span class="checkbox__text">Сестра</span>
                  </div>
                </label>
              </div>
              <div class="form__item-family-list">
                <div class="form__item-family-item form__item-family-item--father form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_father" name="f_Personal_family_structure_father" value="<?= anketa_v($f_Personal_family_structure_father) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Папы" aria-label="Введите имя Папы">
                </div>
                <div class="form__item-family-item form__item-family-item--mother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_mother" name="f_Personal_family_structure_mother" value="<?= anketa_v($f_Personal_family_structure_mother) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Мамы" aria-label="Введите имя Мамы">
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-1" name="f_Personal_family_structure_brother_1" value="<?= anketa_v($f_Personal_family_structure_brother_1) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата" aria-label="Введите имя Брата">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-2" name="f_Personal_family_structure_brother_2" value="<?= anketa_v($f_Personal_family_structure_brother_2) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 2" aria-label="Введите имя Брата 2">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-3" name="f_Personal_family_structure_brother_3" value="<?= anketa_v($f_Personal_family_structure_brother_3) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 3" aria-label="Введите имя Брата 3">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-4" name="f_Personal_family_structure_brother_4" value="<?= anketa_v($f_Personal_family_structure_brother_4) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 4" aria-label="Введите имя Брата 4">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-5" name="f_Personal_family_structure_brother_5" value="<?= anketa_v($f_Personal_family_structure_brother_5) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 5" aria-label="Введите имя Брата 5">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-6" name="f_Personal_family_structure_brother_6" value="<?= anketa_v($f_Personal_family_structure_brother_6) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 6" aria-label="Введите имя Брата 6">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-7" name="f_Personal_family_structure_brother_7" value="<?= anketa_v($f_Personal_family_structure_brother_7) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 7" aria-label="Введите имя Брата 7">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-8" name="f_Personal_family_structure_brother_8" value="<?= anketa_v($f_Personal_family_structure_brother_8) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 8" aria-label="Введите имя Брата 8">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-9" name="f_Personal_family_structure_brother_9" value="<?= anketa_v($f_Personal_family_structure_brother_9) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 9" aria-label="Введите имя Брата 9">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--brother form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_brother-10" name="f_Personal_family_structure_brother_10" value="<?= anketa_v($f_Personal_family_structure_brother_10) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Брата 10" aria-label="Введите имя Брата 10">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-1" name="f_Personal_family_structure_sister_1" value="<?= anketa_v($f_Personal_family_structure_sister_1) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры" aria-label="Введите имя Сестры">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-2" name="f_Personal_family_structure_sister_2" value="<?= anketa_v($f_Personal_family_structure_sister_2) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 2" aria-label="Введите имя Сестры 2">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-3" name="f_Personal_family_structure_sister_3" value="<?= anketa_v($f_Personal_family_structure_sister_3) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 3" aria-label="Введите имя Сестры 3">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-4" name="f_Personal_family_structure_sister_4" value="<?= anketa_v($f_Personal_family_structure_sister_4) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 4" aria-label="Введите имя Сестры 4">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-5" name="f_Personal_family_structure_sister_5" value="<?= anketa_v($f_Personal_family_structure_sister_5) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 5" aria-label="Введите имя Сестры 5">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-6" name="f_Personal_family_structure_sister_6" value="<?= anketa_v($f_Personal_family_structure_sister_6) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 6" aria-label="Введите имя Сестры 6">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-7" name="f_Personal_family_structure_sister_7" value="<?= anketa_v($f_Personal_family_structure_sister_7) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 7" aria-label="Введите имя Сестры 7">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-8" name="f_Personal_family_structure_sister_8" value="<?= anketa_v($f_Personal_family_structure_sister_8) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 8" aria-label="Введите имя Сестры 8">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-9" name="f_Personal_family_structure_sister_9" value="<?= anketa_v($f_Personal_family_structure_sister_9) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 9" aria-label="Введите имя Сестры 9">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
                <div class="form__item-family-item form__item-family-item--sister form__item-family-item--hidden">
                  <input class="form__input " id="personal_family_structure_sister-10" name="f_Personal_family_structure_sister_10" value="<?= anketa_v($f_Personal_family_structure_sister_10) ?>" maxlength="255" size="50" type="text" placeholder="Введите имя Сестры 10" aria-label="Введите имя Сестры 10">
                  <div class="form__item-family-item-btn-wrapper">
                    <button class="form__item-family-item-btn form__item-family-item-btn--remove" type="button">-</button>
                    <button class="form__item-family-item-btn form__item-family-item-btn--add" type="button">+</button>
                  </div>
                </div>
              </div>
            </div>
    <?= $nc_af_list('Are_you_ready_to_move', 'Готовность к переезду', 'Are_you_ready_to_move', $nc_af_sel['Are_you_ready_to_move'], array('wrap' => 'form__select--removal')) ?>
    <?= $nc_af_multi('Country_of_relocation', 'Страна переезда', $nc_af_sel['Country_of_relocation'], 'select--search') ?>
  </fieldset>
</div></section>

<?= $nc_af_step(3, 'Религиозность', $nc_af_pref . '_religion') ?>
  <fieldset class="form__fieldset">
    <?= $nc_af_multi('Degree_of_compliance', 'Степень соблюдения', $nc_af_sel['Degree_of_compliance']) ?>
    <?= $nc_af_list('Course_of_compliance', 'Течение соблюдения', 'Course_of_compliance', $nc_af_sel['Course_of_compliance'], array('flag' => '1')) ?>
    <?= $nc_af_list('Belonging_denomination', 'Принадлежность к конфессии', 'Belonging_denomination', $nc_af_sel['Belonging_denomination'], array('flag' => '1')) ?>
    <?= $nc_af_text('Your_ravin', 'Ваш раввин', $f_Your_ravin, array('placeholder' => 'Имя раввина')) ?>
    <?= $nc_af_text('Your_ravin_contact', 'Контакты раввина', $f_Your_ravin_contact, array('placeholder' => 'Телефон или e-mail')) ?>
    <?= $nc_af_text('Your_sinagoga', 'Ваша синагога', $f_Your_sinagoga, array('placeholder' => 'Название, город')) ?>
    <?= $nc_af_area('Observance_growth', 'Готовность расти в соблюдении заповедей', $f_Observance_growth, 'Что для вас важно и где ваши границы') ?>
    <?= $nc_af_area('Rabbinate_registration', 'Документы о еврействе, регистрация в раввинате', $f_Rabbinate_registration, 'По желанию') ?>
    <?= $nc_af_area('Diaspora_openness', 'Открытость к другим общинам и диаспорам', $f_Diaspora_openness, 'Только своя община или готовы рассмотреть другие') ?>
  </fieldset>
</div></section>

<?= $nc_af_step(4, 'Образование, работа, образ жизни', $nc_af_pref . '_lifestyle') ?>
  <fieldset class="form__fieldset">
    <?= $nc_af_list('Education', 'Образование', 'Education', $nc_af_sel['Education'], array('required' => 1, 'flag' => 2)) ?>
    <?= $nc_af_text('Specialization', 'Специальность', $f_Specialization, array('required' => 1, 'placeholder' => 'Кем учились')) ?>
    <?= $nc_af_text('What_does', 'Чем занимается', $f_What_does, array('placeholder' => 'Работа сейчас')) ?>
    <?= $nc_af_text('Hobby_description', 'Хобби', $f_Hobby_description, array('placeholder' => 'Что любите делать')) ?>
    <?= $nc_af_list('Bad_habits', 'Вредные привычки', 'Bad_habits', $nc_af_sel['Bad_habits']) ?>
    <?= $nc_af_area('Sport_attitude', 'Спорт и активный отдых', $f_Sport_attitude, 'Например: бегаю по утрам, люблю походы') ?>
    <?= $nc_af_area('Languages_spoken', 'Языки, на которых говорите', $f_Languages_spoken, 'Например: русский, иврит, английский') ?>
    <?= $nc_af_area('Introvert_extrovert', 'Скорее интроверт или экстраверт?', $f_Introvert_extrovert, 'Как вам комфортнее') ?>
    <?= $nc_af_area('Housing_after_marriage', 'Жильё после свадьбы', $f_Housing_after_marriage, 'С семьёй, отдельно, пока не решили…') ?>
    <?= $nc_af_text('Health_status', 'Состояние здоровья', $f_Health_status, array('placeholder' => 'Заполнять не обязательно', 'class' => 'form__item--2col', 'hint' => 'По желанию, видно только шадхану', 'private' => 1)) ?>
    <?php /* X-5 (152-ФЗ): здоровье — специальная категория, отдельное согласие
       показываем целиком рядом с полем. Серверная гарантия — api/consent_lib.php. */ ?>
    <div class="form__item tpl-field sh-consent">
      <input type="hidden" name="f_Consent_sensitive" value="0">
      <label class="checkbox form__checkbox">
        <input class="checkbox__input" type="checkbox" name="f_Consent_sensitive" value="1" <?= !empty($f_Consent_sensitive) ? 'checked' : '' ?>>
        <div class="checkbox__body">
          <span class="checkbox__el"><svg class="checkbox__el-icon"><use xlink:href="/images/sprite.svg#checked"></use></svg></span>
          <span class="checkbox__text"><?= anketa_v($nc_cns['checkbox']) ?></span>
        </div>
      </label>
      <p class="sh-consent__intro"><?= anketa_v($nc_cns['intro']) ?></p>
      <ul class="sh-consent__points">
        <?php foreach ($nc_cns['points'] as $nc_af_p): ?><li><?= anketa_v($nc_af_p) ?></li><?php endforeach; ?>
      </ul>
      <?php if (!empty($f_Consent_sensitive_at)): ?>
      <p class="sh-consent__stamp">Согласие дано <?= anketa_v($f_Consent_sensitive_at) ?> (редакция <?= anketa_v($f_Consent_sensitive_version) ?>)</p>
      <?php endif; ?>
    </div>
    <?= $nc_af_area('Income_property', 'Материальное положение', $f_Income_property, 'Работа, жильё, доход — если считаете нужным поделиться', true) ?>
    <?= $nc_af_area('Health_conditions', 'Здоровье', $f_Health_conditions, 'Важные особенности здоровья, если хотите указать', true) ?>
    <?= $nc_af_area('Medications', 'Приём медикаментов', $f_Medications, 'По желанию', true) ?>
  </fieldset>
</div></section>

<?= $nc_af_step(5, 'Предпочтения', $nc_af_pref . '_preferences') ?>
  <p class="sh-wizard__lead">Кого ищете: всё необязательно, но чем точнее, тем лучше подбор.</p>
  <fieldset class="form__fieldset">
    <?= $nc_af_list('Search_gender', 'Пол', 'Search_gender', $nc_af_sel['Search_gender'], array('wrap_id' => 'preferencesGenderSelect')) ?>
    <div class="<?= $nc_af_cls('Search_height_start') ?>">
      <label class="form__item-name" for="f_Search_height_start">Рост</label>
      <div class="form__item-range">
        <input class="form__input tpl-field-input" id="f_Search_height_start" name="f_Search_height_start" type="number" min="<?= ANKETA_HEIGHT_MIN ?>" max="<?= ANKETA_HEIGHT_MAX ?>" step="any" value="<?= anketa_v($f_Search_height_start) ?>" placeholder="от" aria-label="Рост от">
        <span class="form__item-range-separator">—</span>
        <input class="form__input tpl-field-input" id="f_Search_height_end" name="f_Search_height_end" type="number" min="<?= ANKETA_HEIGHT_MIN ?>" max="<?= ANKETA_HEIGHT_MAX ?>" step="any" value="<?= anketa_v($f_Search_height_end) ?>" placeholder="до" aria-label="Рост до">
      </div>
      <?= $nc_af_e('Search_height_start') ?><?= $nc_af_e('Search_height_end') ?>
    </div>
    <div class="<?= $nc_af_cls('Search_age_start') ?>">
      <label class="form__item-name" for="f_Search_age_start">Возраст</label>
      <div class="form__item-range">
        <input class="form__input tpl-field-input" id="f_Search_age_start" name="f_Search_age_start" type="number" min="<?= ANKETA_MIN_AGE ?>" max="<?= ANKETA_MAX_AGE ?>" step="1" value="<?= anketa_v($f_Search_age_start) ?>" placeholder="от" aria-label="Возраст от">
        <span class="form__item-range-separator">—</span>
        <input class="form__input tpl-field-input" id="f_Search_age_end" name="f_Search_age_end" type="number" min="<?= ANKETA_MIN_AGE ?>" max="<?= ANKETA_MAX_AGE ?>" step="1" value="<?= anketa_v($f_Search_age_end) ?>" placeholder="до" aria-label="Возраст до">
      </div>
      <?= $nc_af_e('Search_age_start') ?><?= $nc_af_e('Search_age_end') ?>
    </div>
    <?= $nc_af_text('Search_place_of_birth', 'Место рождения', $f_Search_place_of_birth, array('placeholder' => 'Если важно')) ?>
    <?= $nc_af_multi('Search_country', 'Страна проживания', $nc_af_sel['Search_country'], 'select--search') ?>
    <?= $nc_af_multi('Search_belonging_denomination', 'Принадлежность к конфессии', $nc_af_sel['Search_belonging_denomination']) ?>
    <?= $nc_af_multi('Search_education', 'Образование', $nc_af_sel['Search_education']) ?>
    <?= $nc_af_multi('Search_was_previously_married', 'Состоял(а) ли ранее в браке', $nc_af_sel['Search_was_previously_married']) ?>
    <?= $nc_af_multi('Search_number_of_children', 'Количество детей', $nc_af_sel['Search_number_of_children']) ?>
    <?= $nc_af_multi('Search_are_you_ready_to_move', 'Готовность к переезду', $nc_af_sel['Search_are_you_ready_to_move'], 'form__select--removal') ?>
    <?= $nc_af_area('Search_for_shadhan', 'Заметка для шадхана', $f_Search_for_shadhan, 'Что важно знать шадхану о ваших ожиданиях') ?>
  </fieldset>
</div></section>

<?= $nc_af_step(6, 'Фото и документы', $nc_af_pref . '_photos') ?>
  <p class="sh-wizard__lead">Фото — jpeg или png до 8 МБ; документы — картинка или PDF до 10 МБ.</p>
  <fieldset class="form__fieldset">
    <div class="form__item form__item--add-photos">
      <div class="form__item-name">Фото для анкеты</div>
      <?= is_object($f_Photo_questionnaire) ? $f_Photo_questionnaire->form() : '' ?>
    </div>
    <div class="form__item form__item--upload tpl-field">
      <div class="upload upload--default form__upload">
        <input class="upload__input" id="nc-upload-input-479" value="<?= anketa_v((string)$f_Photo_avatar) ?>" data-name="<?= anketa_v($f_Photo_avatar_name) ?>" name="f_Photo_avatar" type="file" accept="image/png, image/jpg, image/jpeg" data-sh-max-mb="8">
        <label class="upload__body" for="nc-upload-input-479">
          <div class="upload__preview"></div>
          <div class="upload__text">
            <div class="upload__text-title">Фото для аватара</div>
            <div class="upload__text-description upload__text-description--unselected">jpeg, png — до 8 МБ</div>
            <div class="upload__text-description upload__text-description--selected">Можно заменить фото для аватара</div>
          </div>
          <div class="upload__button">Обзор</div>
        </label>
      </div>
    </div>
    <div class="form__item form__item--upload tpl-field">
      <div class="upload upload--default form__upload">
        <input class="upload__input" id="nc-upload-input-480" value="<?= anketa_v((string)$f_Birthcertificates) ?>" data-name="<?= anketa_v($f_Birthcertificates_name) ?>" name="f_Birthcertificates" type="file" accept="image/png, image/jpg, image/jpeg, .pdf" data-sh-max-mb="10">
        <label class="upload__body" for="nc-upload-input-480">
          <div class="upload__preview"></div>
          <div class="upload__text">
            <div class="upload__text-title">Свидетельство о рождении</div>
            <div class="upload__text-description upload__text-description--unselected">jpeg, png, pdf — до 10 МБ</div>
            <div class="upload__text-description upload__text-description--selected">Можно заменить свидетельство о рождении</div>
          </div>
          <div class="upload__button">Обзор</div>
        </label>
      </div>
    </div>
    <div class="form__item form__item--upload tpl-field">
      <div class="upload upload--default form__upload">
        <input class="upload__input" id="nc-upload-input-481" value="<?= anketa_v((string)$f_Another_document_Jewishness) ?>" data-name="<?= anketa_v($f_Another_document_Jewishness_name) ?>" name="f_Another_document_Jewishness" type="file" accept="image/png, image/jpg, image/jpeg, .pdf" data-sh-max-mb="10">
        <label class="upload__body" for="nc-upload-input-481">
          <div class="upload__preview"></div>
          <div class="upload__text">
            <div class="upload__text-title">Иной документ, подтверждающий еврейство</div>
            <div class="upload__text-description upload__text-description--unselected">jpeg, png, pdf — до 10 МБ</div>
            <div class="upload__text-description upload__text-description--selected">Можно заменить документ</div>
          </div>
          <div class="upload__button">Обзор</div>
        </label>
      </div>
    </div>
  </fieldset>
</div></section>

<?= $nc_af_step(7, 'Проверка', $nc_af_pref . '_review') ?>
  <p class="sh-wizard__lead">Посмотрите, что получилось. Исправить можно, вернувшись к нужному шагу — данные не пропадут.</p>
  <div data-sh-summary><p class="sh-wizard__lead">Сводка появится здесь после заполнения шагов.</p></div>
</div></section>

</div>
