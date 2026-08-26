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

/* --- состояние опроса (Э2.4) ------------------------------------------------
 * Живёт здесь, а не в scoring_lib.php: тому нужен ai_client.php (сеть), а
 * шаблону консультанта — только справочник кейсов и счётчики.
 */

/* Текущая версия опроса пользователя (0 — ещё не отвечал). */
function cases_current_version($db, $user_id) {
    $user_id = (int)$user_id;
    return (int)$db->get_var(
        "SELECT IFNULL(MAX(`version`),0) FROM `assessment_answers` WHERE `user_id`=$user_id"
    );
}

/*
 * Состояние опроса: сколько активных кейсов, сколько отвечено в текущей версии
 * и какой кейс следующий (по sort_order). Возвращает next=null, когда пройдено
 * всё.
 */
function cases_survey_state($db, $user_id, $restart = false) {
    $user_id = (int)$user_id;
    $version = cases_current_version($db, $user_id);
    $total = (int)$db->get_var("SELECT COUNT(*) FROM `assessment_cases` WHERE `active`=1");

    // Повторный проход: смотрим на СЛЕДУЮЩУЮ версию — там ответов ещё нет,
    // поэтому опрос начинается с первого кейса, а прогресс честно равен нулю.
    // Сама версия появится в базе, когда придёт первый ответ
    // (scoring_version_for_answer), — здесь ничего не пишем.
    $target = $restart ? $version + 1 : max(1, $version);

    $answered = ($version && !$restart)
        ? (int)$db->get_var(
            "SELECT COUNT(DISTINCT a.`case_id`) FROM `assessment_answers` a "
            . "JOIN `assessment_cases` c ON c.`id`=a.`case_id` AND c.`active`=1 "
            . "WHERE a.`user_id`=$user_id AND a.`version`=$version")
        : 0;

    $next = $db->get_row(
        "SELECT c.`id`, c.`dimension_group`, c.`dimension`, c.`scenario_text` "
        . "FROM `assessment_cases` c "
        . "LEFT JOIN `assessment_answers` a ON a.`case_id`=c.`id` "
        . "AND a.`user_id`=$user_id AND a.`version`=$target "
        . "WHERE c.`active`=1 AND a.`id` IS NULL "
        . "ORDER BY c.`sort_order`, c.`id` LIMIT 1",
        ARRAY_A
    );

    if ($next) {
        // MySQL отдаёт всё строками; клиенту (и тестам) нужен настоящий int
        $next['id'] = (int)$next['id'];
    }

    return array(
        'version' => $version,
        'total' => $total,
        'answered' => $answered,
        'next' => $next ? $next : null,
    );
}
