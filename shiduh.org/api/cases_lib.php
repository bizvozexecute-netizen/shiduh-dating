<?php
/*
 * Общее для кейсов ценностного опроса (ROADMAP Э2.2-a): состав групп и
 * измерений. Одно место — им пользуются и эндпоинт редактирования
 * (api/cases_save.php), и экран админки (admin-cases.php), и будущий
 * опрос в диалоге (Э2.2-c) со скорингом (Э2.3).
 *
 * Языки любви — классические пять. Ценностные измерения соответствуют
 * справочнику Classificator_Value_dimension (миграция 002).
 */

function cases_groups() {
    return array(
        'love_language' => array(
            'title' => 'Языки любви',
            'dimensions' => array(
                'words'   => 'Слова поддержки',
                'time'    => 'Время вдвоём',
                'gifts'   => 'Подарки',
                'service' => 'Помощь и забота',
                'touch'   => 'Прикосновения',
            ),
        ),
        'family_value' => array(
            'title' => 'Ценностные измерения',
            'dimensions' => array(
                'budget'     => 'Бюджет семьи',
                'roles'      => 'Роли в доме',
                'children'   => 'Воспитание детей',
                'parents'    => 'Отношения с родителями',
                'religion'   => 'Отношение к религии',
                'career'     => 'Карьера и дом',
                'relocation' => 'Готовность к переезду',
            ),
        ),
    );
}

/* Человекочитаемое имя измерения; неизвестный ключ возвращаем как есть
   (админ мог завести своё измерение — не прячем его за пустотой). */
function cases_dimension_title($group, $dim) {
    $groups = cases_groups();
    if (isset($groups[$group]['dimensions'][$dim])) {
        return $groups[$group]['dimensions'][$dim];
    }
    return $dim;
}
