<?php
/*
 * Скоринг ценностного профиля (ТЗ Э2.3).
 *
 * Разделение ролей жёсткое и намеренное:
 *   * LLM классифицирует ОДИН ответ на ОДИН кейс → баллы по измерениям и
 *     уверенность (Э2.3-a). Больше она ни на что не влияет;
 *   * итоговый профиль — проценты по измерениям — считает КОД (Э2.3-b).
 *
 * Почему так: если бы профиль собирала модель, один и тот же набор ответов
 * давал бы разный результат от запуска к запуску. Здесь баллы каждого
 * ответа сохраняются навсегда, а проценты — чистая арифметика над ними,
 * поэтому повтор с тем же набором ответов даёт тот же профиль.
 *
 * Версии (Э2.3-c): повторный ответ на уже отвеченный кейс начинает новую
 * версию опроса. Прошлые ответы не переписываются — профиль считается
 * только по текущей версии, история остаётся.
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()
require_once dirname(__FILE__) . '/anketa_target_lib.php';

require_once dirname(__FILE__) . '/ai_client.php';
require_once dirname(__FILE__) . '/cases_lib.php';

define('SCORING_PROFILE_VERSION_KEY', 'version');

/* Допустимые измерения по группам — из одного справочника с кейсами. */
function scoring_dimensions($group) {
    $groups = cases_groups();
    return isset($groups[$group]['dimensions'])
        ? array_keys($groups[$group]['dimensions'])
        : array();
}

/*
 * Классификация одного ответа моделью (Э2.3-a).
 * Температура 0 и требование строгого JSON: задача механическая, простор
 * для «творчества» здесь только вредит.
 * Возвращает array('scores'=>[измерение=>балл], 'confidence'=>float)
 * либо null, если модель ответила негодным.
 */
function scoring_classify($case_row, $answer, $user_id = 0) {
    $group = (string)$case_row['dimension_group'];
    $allowed = scoring_dimensions($group);
    if (!$allowed) {
        return null;
    }

    $messages = array(
        array('role' => 'system', 'content' =>
            'Вы — классификатор ответов ценностного опроса. По ответу человека '
            . 'оцените, насколько выражено каждое измерение, целым числом от 0 до 5. '
            . 'Разрешённые измерения: ' . implode(', ', $allowed) . '. '
            . 'Ответьте ТОЛЬКО JSON-объектом вида '
            . '{"scores":{"измерение":балл},"confidence":0.0-1.0}. '
            . 'Никаких пояснений и текста вне JSON. Если ответ не позволяет судить, '
            . 'верните пустой scores и низкий confidence.'),
        array('role' => 'user', 'content' =>
            "Вопрос: " . (string)$case_row['scenario_text'] . "\n"
            . "Ответ человека: " . $answer),
    );

    $res = ai_chat($messages, array('temperature' => 0,
        'kind' => 'classify', 'user_id' => $user_id, 'max_tokens' => 200));
    if (!$res['ok']) {
        return null;
    }
    return scoring_parse_classification($res['content'], $allowed);
}

/*
 * Разбор ответа классификатора. Всё, что не похоже на ожидаемую структуру,
 * отбрасывается: лучше не иметь баллов, чем выдумать их из мусора.
 */
function scoring_parse_classification($content, $allowed) {
    $content = trim((string)$content);
    // модель могла обернуть JSON в ```json ... ```
    if (strpos($content, '{') !== false) {
        $content = substr($content, strpos($content, '{'));
        $content = substr($content, 0, strrpos($content, '}') + 1);
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['scores']) || !is_array($data['scores'])) {
        return null;
    }

    $scores = array();
    foreach ($data['scores'] as $dim => $value) {
        if (!in_array($dim, $allowed, true)) {
            continue;              // измерение вне справочника — мимо
        }
        if (!is_numeric($value)) {
            continue;
        }
        $value = (int)round((float)$value);
        if ($value < 0 || $value > 5) {
            continue;              // балл вне шкалы — мимо
        }
        if ($value > 0) {
            $scores[$dim] = $value;
        }
    }

    $confidence = isset($data['confidence']) && is_numeric($data['confidence'])
        ? (float)$data['confidence'] : 0.0;
    $confidence = max(0.0, min(1.0, $confidence));

    return array('scores' => $scores, 'confidence' => $confidence);
}

/* Текущая версия опроса пользователя (0, если ещё не отвечал). */
function scoring_current_version($db, $user_id) {
    $user_id = (int)$user_id;
    return (int)$db->get_var(
        "SELECT IFNULL(MAX(`version`),0) FROM `assessment_answers` WHERE `user_id`=$user_id"
    );
}

/*
 * Версия, в которую попадёт новый ответ: та же, пока кейс не повторяется;
 * следующая — если на этот кейс в текущей версии уже отвечали.
 */
function scoring_version_for_answer($db, $user_id, $case_id) {
    $user_id = (int)$user_id;
    $case_id = (int)$case_id;
    $version = scoring_current_version($db, $user_id);
    if ($version === 0) {
        return 1;
    }
    $answered = (int)$db->get_var(
        "SELECT COUNT(*) FROM `assessment_answers` "
        . "WHERE `user_id`=$user_id AND `case_id`=$case_id AND `version`=$version"
    );
    return $answered > 0 ? $version + 1 : $version;
}

/* Сохранение ответа с баллами. Возвращает id строки или 0. */
function scoring_store_answer($db, $user_id, $case_id, $answer, $classification, $version) {
    $user_id = (int)$user_id;
    $case_id = (int)$case_id;
    $version = (int)$version;
    $scores = ($classification && !empty($classification['scores']))
        ? json_encode($classification['scores'], JSON_UNESCAPED_UNICODE) : '';
    $confidence = $classification ? (float)$classification['confidence'] : 0.0;

    // IGNORE + UNIQUE user_case_version (миграция 015): гонка двух путей
    // ответа (опрос и диалог, Э2.2-c) не плодит дубль в одной версии.
    // number_format, не sprintf('%f'): NetCat ставит ru_RU-локаль, и sprintf
    // дал бы '0,90' — SQL-ошибка только на проде (ловушка из Э2.4).
    $db->query(
        "INSERT IGNORE INTO `assessment_answers` SET "
        . "`user_id`=$user_id, `case_id`=$case_id, "
        . "`answer`='" . $db->prepare(mb_substr((string)$answer, 0, 4000)) . "', "
        . "`scores`='" . $db->prepare($scores) . "', "
        . "`confidence`=" . number_format($confidence, 2, '.', '') . ", "
        . "`version`=$version, `created_at`=NOW()"
    );
    if ($db->is_error) {
        return 0;
    }
    if (!$db->rows_affected) {
        // дубль по UNIQUE: ответ на этот кейс в этой версии уже записан
        // параллельным запросом — идемпотентно возвращаем существующий
        return (int)$db->get_var(
            "SELECT `id` FROM `assessment_answers` WHERE `user_id`=$user_id "
            . "AND `case_id`=$case_id AND `version`=$version LIMIT 1"
        );
    }
    return (int)$db->insert_id;
}

/*
 * Профиль по сохранённым баллам ТЕКУЩЕЙ версии — чистая арифметика (Э2.3-b).
 * Баллы измерения суммируются, суммы переводятся в проценты внутри своей
 * группы. Остаток от округления отдаётся наибольшему измерению, чтобы сумма
 * всегда была ровно 100 и профиль не «плавал» на копейки.
 */
function scoring_compute_profile($db, $user_id) {
    $user_id = (int)$user_id;
    $version = scoring_current_version($db, $user_id);
    if (!$version) {
        return null;
    }

    $db->query(
        "SELECT a.`scores`, c.`dimension_group` FROM `assessment_answers` a "
        . "JOIN `assessment_cases` c ON c.`id`=a.`case_id` "
        . "WHERE a.`user_id`=$user_id AND a.`version`=$version",
        ARRAY_A
    );
    $rows = (array)$db->last_result;

    $totals = array();
    $answered = 0;
    foreach ($rows as $r) {
        $answered++;
        $scores = json_decode((string)$r['scores'], true);
        if (!is_array($scores)) {
            continue;
        }
        $group = (string)$r['dimension_group'];
        foreach ($scores as $dim => $value) {
            if (!isset($totals[$group][$dim])) {
                $totals[$group][$dim] = 0;
            }
            $totals[$group][$dim] += (int)$value;
        }
    }

    return array(
        'love_languages' => scoring_percentages(
            isset($totals['love_language']) ? $totals['love_language'] : array()),
        'family_values_scores' => scoring_percentages(
            isset($totals['family_value']) ? $totals['family_value'] : array()),
        'cases_answered' => $answered,
        'completed_at' => (string)$db->get_var("SELECT NOW()"),
        'version' => $version,
    );
}

/* Суммы → проценты, ровно 100 в сумме. */
function scoring_percentages($totals) {
    $sum = array_sum($totals);
    if ($sum <= 0) {
        return new stdClass();      // пусто, но валидный JSON-объект
    }
    arsort($totals);
    $result = array();
    $accumulated = 0;
    $keys = array_keys($totals);
    foreach ($keys as $i => $dim) {
        if ($i === count($keys) - 1) {
            $result[$dim] = 100 - $accumulated;   // остаток последнему
        } else {
            $value = (int)round($totals[$dim] * 100 / $sum);
            $result[$dim] = $value;
            $accumulated += $value;
        }
    }
    return $result;
}

/*
 * Текст психотипа (Э2.3-c). Генерируется ОДИН РАЗ на версию профиля и
 * кэшируется прямо в нём: при просмотрах ничего не пересчитывается и не
 * тратится. Просим модель только описать уже посчитанные цифры — считать
 * она ничего не должна.
 *
 * Порог в 3 ответа: по одному-двум описывать нечего, а генерация стоит денег.
 */
define('SCORING_TEXT_MIN_ANSWERS', 3);

function scoring_psychotype_text($profile, $user_id = 0) {
    if ((int)$profile['cases_answered'] < SCORING_TEXT_MIN_ANSWERS) {
        return null;
    }
    $groups = cases_groups();
    $lines = array();
    foreach (array('love_language' => 'love_languages',
                   'family_value' => 'family_values_scores') as $group => $key) {
        $values = (array)$profile[$key];
        if (!$values) {
            continue;
        }
        $parts = array();
        foreach ($values as $dim => $percent) {
            $title = isset($groups[$group]['dimensions'][$dim])
                ? $groups[$group]['dimensions'][$dim] : $dim;
            $parts[] = $title . ' — ' . (int)$percent . '%';
        }
        $lines[] = $groups[$group]['title'] . ': ' . implode(', ', $parts);
    }
    if (!$lines) {
        return null;
    }

    /*
     * X-4. Шадханы просили «психотипы по Маквильямс». Клиническую типологию
     * мы сознательно НЕ внедряем: 12 бытовых кейсов — не инструмент
     * психиатрической типизации, а ярлык о живом человеке в руках свата
     * вредит. Поэтому описываем ЦЕННОСТИ («что важно»), а не тип личности,
     * и запрещаем модели ярлыки и клинику явным списком — модель охотно
     * скатывается в «типаж», если её не остановить.
     */
    $res = ai_chat(array(
        array('role' => 'system', 'content' =>
            AI_SAFETY_PREAMBLE . "\n\n"
            . 'Вы описываете шадхану ценностный профиль кандидата. Вам дают уже '
            . 'посчитанные проценты — ничего не пересчитывайте и не добавляйте '
            . 'фактов. Напишите 2–3 предложения: что для человека важнее всего '
            . 'и на что шадхану обратить внимание при разговоре. '
            . 'ЗАПРЕЩЕНО: ставить диагнозы, называть тип личности или психотип, '
            . 'навешивать ярлыки, употреблять клиническую и психиатрическую '
            . 'терминологию, оценивать человека как «здорового» или «проблемного», '
            . 'предсказывать поведение в браке. Пишите о предпочтениях, а не о '
            . 'личности: «важно время вместе», а не «он такой-то человек». '
            . 'Нейтральный тон, без оценок.'),
        array('role' => 'user', 'content' => implode("\n", $lines)),
    ), array('temperature' => 0, 'kind' => 'psychotype', 'user_id' => $user_id, 'max_tokens' => 400));

    if (!$res['ok']) {
        return null;
    }
    $text = trim((string)$res['content']);
    return $text === '' ? null : mb_substr($text, 0, 2000);
}

/* Фиксация профиля в анкете. */
function scoring_save_profile($db, $user_id, $profile) {
    $user_id = (int)$user_id;
    $anketa_id = ai_target_anketa($db, $user_id);
    if (!$anketa_id) {
        return false;
    }
    $db->query(
        "UPDATE `Message204` SET `Psychological_profile`='"
        . $db->prepare(json_encode($profile, JSON_UNESCAPED_UNICODE)) . "' "
        . "WHERE `Message_ID`=$anketa_id"
    );
    return !$db->is_error;
}

/*
 * Потолок обращений к модели за сутки на пользователя: опрос из 12 кейсов
 * проходится многократно, но бесконечно жать кнопку (или гонять диалог,
 * Э2.2-c) не выйдет. Живёт здесь, потому что ответы принимают ДВА пути:
 * явный опрос (assessment_answer.php) и инструмент диалога (ai_tools.php).
 */
if (!defined('ASSESSMENT_MAX_PER_DAY')) {
    define('ASSESSMENT_MAX_PER_DAY', 300);
}

function scoring_daily_cap_reached($db, $user_id) {
    $user_id = (int)$user_id;
    $today = (int)$db->get_var(
        "SELECT COUNT(*) FROM `assessment_answers` WHERE `user_id`=$user_id "
        . "AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    return $today >= ASSESSMENT_MAX_PER_DAY;
}

/*
 * Полный конвейер приёма одного ответа на кейс (Э2.3): классификация
 * моделью -> сохранение с баллами (негодный разбор не роняет конвейер —
 * ответ хранится без баллов) -> пересчёт профиля КОДОМ -> текст психотипа
 * (один раз на версию) -> фиксация в анкете.
 *
 * Общий для явного опроса и вплетения в диалог (Э2.2-c): оба пути обязаны
 * вести себя одинаково, иначе профиль зависел бы от способа ответа.
 *
 * Возвращает array(answer_id, classified, version, profile); answer_id=0 —
 * ответ сохранить не удалось; profile — зафиксированный профиль или null.
 *
 * Сериализация: GET_LOCK на пользователя. Ответы приходят ДВУМЯ дверями
 * (форма опроса и инструмент диалога), и без замка гонка «прочитал версию →
 * вставил» либо дублирует кейс в версии, либо, хуже, уводит второй ответ в
 * версию V+1 — профиль молча пересчитывается по одному ответу (ревью Э2.2-c).
 * UNIQUE user_case_version в схеме — страховка от первой ветки, замок
 * закрывает обе. MySQL 5.7: GET_LOCK честно ждёт и не сбрасывает чужие замки.
 */
function scoring_record_answer($db, $user_id, $case_row, $answer) {
    $user_id = (int)$user_id;

    // классификация — долгий вызов модели: ДО замка, чтобы не держать
    // сериализацию на время сети
    $classification = scoring_classify($case_row, $answer, $user_id);

    $lock = 'shiduh_scoring_' . $user_id;
    $got = (int)$db->get_var("SELECT GET_LOCK('" . $db->prepare($lock) . "', 5)");
    if (!$got) {
        // параллельная запись держит замок дольше 5с — честно отказываем,
        // а не пишем вслепую мимо сериализации
        return array('answer_id' => 0, 'classified' => false, 'version' => 0,
            'profile' => null);
    }

    $version = scoring_version_for_answer($db, $user_id, (int)$case_row['id']);
    $answer_id = scoring_store_answer($db, $user_id, (int)$case_row['id'],
        $answer, $classification, $version);
    if (!$answer_id) {
        $db->get_var("SELECT RELEASE_LOCK('" . $db->prepare($lock) . "')");
        return array('answer_id' => 0, 'classified' => false, 'version' => $version,
            'profile' => null);
    }

    $profile = scoring_compute_profile($db, $user_id);
    // Профиль и текст психотипа живут в анкете. Без анкеты сохранять некуда,
    // и кэш «психотип раз на версию» не работал бы — каждый ответ порождал бы
    // новую платную генерацию текста. Поэтому без анкеты не генерируем вовсе.
    $anketa_id = ai_target_anketa($db, $user_id);
    if ($profile && $anketa_id) {
        // Текст психотипа — один раз на версию: если уже посчитан, переносим
        // как есть, модель не тревожим.
        $stored = json_decode((string)$db->get_var(
            "SELECT `Psychological_profile` FROM `Message204` "
            . "WHERE `Message_ID`=$anketa_id"
        ), true);
        $cached = (is_array($stored)
            && (int)(isset($stored['version']) ? $stored['version'] : 0) === (int)$profile['version']
            && !empty($stored['psychotype_text']))
            ? $stored['psychotype_text'] : null;

        $profile['psychotype_text'] = $cached !== null
            ? $cached
            : scoring_psychotype_text($profile, $user_id);

        if (!scoring_save_profile($db, $user_id, $profile)) {
            // молчать нельзя: профиль посчитан, но в анкете его нет
            shiduh_log('scoring', 'профиль не сохранён для пользователя ' . $user_id);
        }
    }
    $db->get_var("SELECT RELEASE_LOCK('" . $db->prepare($lock) . "')");

    return array(
        'answer_id' => (int)$answer_id,
        'classified' => $classification !== null,
        'version' => $version,
        'profile' => ($profile && $anketa_id) ? $profile : null,
    );
}
