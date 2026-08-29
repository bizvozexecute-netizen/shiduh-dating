<?php
/*
 * Отображение ценностного профиля (ТЗ Э2.4).
 *
 * Только представление: читает готовый профиль и превращает его в строки полос
 * и в координаты радара. Ничего не считает и не пишет — расчёт живёт в
 * scoring_lib.php, а он тянет ai_client.php (сеть), которому в шаблоне карточки
 * делать нечего.
 *
 * Всё, что печатают функции ниже, они экранируют сами: на вход им приходит уже
 * разобранный массив, а не поле NetCat. Это противоположно правилу для полей
 * анкеты в шаблоне (те выводятся сырыми, иначе двойное экранирование).
 *
 * ПОЧЕМУ ЧИТАЕМ ИЗ БАЗЫ, А НЕ ИЗ $f_Psychological_profile:
 * NetCat применяет к полям типа 3 htmlspecialchars(ENT_QUOTES) при выводе — все
 * кавычки становятся &quot;, и json_decode вернёт null. Читаем колонку напрямую,
 * как карточка уже читает Message255.
 *
 * ПОЧЕМУ ПО User_ID, А НЕ ПО Message_ID:
 * scoring_save_profile() пишет профиль в ПЕРВУЮ анкету пользователя. Профиль
 * принадлежит человеку, а не конкретной анкете, поэтому читаем тем же правилом —
 * иначе у человека с несколькими анкетами профиль «пропадал» бы на всех, кроме
 * первой.
 */

require_once dirname(__FILE__) . '/cases_lib.php';

/* Порядок осей радара — как в прототипе; в cases_groups() порядок другой. */
function profile_radar_axes() {
    return array('time', 'words', 'service', 'touch', 'gifts');
}

/*
 * Короткие подписи для осей: полные («Помощь и забота») не помещаются вокруг
 * диаграммы. Держим здесь, а не в cases_groups(), чтобы не менять контракт
 * админки кейсов ради вёрстки.
 */
function profile_axis_short($dim) {
    $short = array(
        'time' => 'Время', 'words' => 'Слова', 'service' => 'Забота',
        'touch' => 'Касания', 'gifts' => 'Подарки',
    );
    return isset($short[$dim]) ? $short[$dim] : cases_dimension_title('love_language', $dim);
}

/* Профиль пользователя или null, если не пройден / битый JSON. */
function profile_load($db, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return null;
    }
    $raw = (string)$db->get_var(
        "SELECT `Psychological_profile` FROM `Message204` WHERE `User_ID`=$user_id "
        . "ORDER BY `Message_ID` LIMIT 1"
    );
    if (trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;              // битое значение не должно ронять карточку
    }
    // профиль считается пройденным, только если есть хоть одна оценка
    $has = !empty($data['love_languages']) || !empty($data['family_values_scores']);
    return $has ? $data : null;
}

/*
 * Строки для полос: [['key','title','percent'], ...].
 * Проценты уже отсортированы по убыванию при расчёте, порядок сохраняем —
 * первая строка это ведущее измерение.
 */
function profile_rows($profile, $group) {
    $key = ($group === 'love_language') ? 'love_languages' : 'family_values_scores';
    $values = isset($profile[$key]) ? (array)$profile[$key] : array();
    $rows = array();
    foreach ($values as $dim => $percent) {
        $percent = (int)$percent;
        if ($percent <= 0) {
            continue;
        }
        $rows[] = array(
            'key' => (string)$dim,
            'title' => cases_dimension_title($group, (string)$dim),
            'percent' => $percent,
        );
    }
    return $rows;
}

/*
 * Радар языков любви как готовый SVG.
 *
 * Считаем на сервере намеренно: в прототипе радар рисует canvas скриптом, но
 * тогда в разметке остаётся пустой тег и HTTP-тест не может проверить, что
 * нарисовано. У SVG координаты лежат прямо в HTML — отрисовка проверяема,
 * работает без JS и при печати.
 *
 * Координаты — ЦЕЛЫЕ. С дробными был бы скрытый баг: NetCat выставляет русскую
 * локаль, а sprintf('%f') в PHP 7.4 зависит от LC_NUMERIC и на системе с
 * установленной ru_RU дал бы «126,5» — SVG молча сломался бы. Локально это не
 * воспроизводится (в контейнере локали нет), то есть вылезло бы только на проде.
 *
 * Шкала: 50% = край сетки. При пяти измерениях, дающих в сумме 100, ведущее
 * всегда ≥20%, поэтому фигура остаётся читаемой; точные числа всё равно
 * подписаны рядом с полосами.
 */
define('PROFILE_RADAR_SCALE', 50);
define('PROFILE_RADAR_MIN_AXES', 3);

function profile_radar_svg($profile, $size = 220) {
    $values = isset($profile['love_languages']) ? (array)$profile['love_languages'] : array();
    // Меньше трёх измерений — рисовать нечего: «фигура» выродится в луч и будет
    // выглядеть как вывод, которого данные не дают.
    $filled = 0;
    foreach ($values as $v) {
        if ((int)$v > 0) {
            $filled++;
        }
    }
    if ($filled < PROFILE_RADAR_MIN_AXES) {
        return '';
    }

    $axes = profile_radar_axes();
    $n = count($axes);
    $size = (int)$size;
    // Поле шире, чем высота: подписи осей уходят вбок и на квадратном холсте
    // обрезались бы.
    $w = (int)round($size * 1.25);
    $cx = (int)round($w / 2);
    $cy = (int)round($size / 2);
    $r = (int)round($size * 0.30);

    // Точка на оси по ДЛИНЕ (а не по проценту): подписи выносятся за сетку,
    // а пересчёт из процента ограничен шкалой и наружу не пускает.
    $at = function ($i, $len) use ($cx, $cy, $n) {
        $angle = -M_PI / 2 + $i * 2 * M_PI / $n;
        return array((int)round($cx + $len * cos($angle)), (int)round($cy + $len * sin($angle)));
    };
    $point = function ($i, $percent) use ($at, $r) {
        $len = $r * max(0, min(PROFILE_RADAR_SCALE, (float)$percent)) / PROFILE_RADAR_SCALE;
        return $at($i, $len);
    };

    $aria = array();
    foreach ($axes as $dim) {
        if (!empty($values[$dim])) {
            $aria[] = profile_axis_short($dim) . ' ' . (int)$values[$dim] . '%';
        }
    }

    $svg = '<svg class="lk-psy__radar" viewBox="0 0 ' . $w . ' ' . $size . '" width="' . $w
         . '" height="' . $size . '" role="img" aria-label="Языки любви: '
         . htmlspecialchars(implode(', ', $aria), ENT_QUOTES, 'UTF-8') . '">';

    // сетка
    foreach (array(25, 50, 75, 100) as $ring) {
        $pts = array();
        for ($i = 0; $i < $n; $i++) {
            $p = $at($i, $r * $ring / 100);
            $pts[] = $p[0] . ',' . $p[1];
        }
        $svg .= '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="#e3ddd2"/>';
    }
    // оси
    for ($i = 0; $i < $n; $i++) {
        $p = $at($i, $r);
        $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $p[0] . '" y2="' . $p[1]
              . '" stroke="#e3ddd2"/>';
    }
    // фигура
    $shape = array();
    foreach ($axes as $i => $dim) {
        $shape[] = $point($i, isset($values[$dim]) ? (int)$values[$dim] : 0);
    }
    $pts = array();
    foreach ($shape as $p) {
        $pts[] = $p[0] . ',' . $p[1];
    }
    $svg .= '<polygon class="lk-psy__shape" points="' . implode(' ', $pts)
          . '" fill="rgba(200,174,136,.45)" stroke="#a68556" stroke-width="2"/>';
    foreach ($shape as $p) {
        $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="3" fill="#a68556"/>';
    }
    // подписи — ЗА сеткой, иначе они легли бы ровно на внешнее кольцо и на
    // саму фигуру (множитель внутри $point молча срезался ограничителем шкалы)
    foreach ($axes as $i => $dim) {
        $p = $at($i, (int)round($r * 1.35));
        $svg .= '<text class="lk-psy__axis" x="' . $p[0] . '" y="' . $p[1] . '" text-anchor="middle" '
              . 'dominant-baseline="middle" font-size="10" fill="#756d65">'
              . htmlspecialchars(profile_axis_short($dim), ENT_QUOTES, 'UTF-8') . '</text>';
    }

    return $svg . '</svg>';
}

/*
 * Полосы измерений. $group — 'love_language' | 'family_value'.
 * $prefix — префикс CSS-классов: карточка шадхана и консультант оформлены
 * по-разному, но разметка одна. Параметр, а не подмена строк снаружи: при
 * переименовании классов подмена молча перестала бы совпадать и полосы
 * остались бы без стилей, ничего не сломав явно.
 */
function profile_bars_html($profile, $group, $prefix = 'lk-psy') {
    $rows = profile_rows($profile, $group);
    if (!$rows) {
        return '';
    }
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', (string)$prefix);
    $html = '<ul class="' . $prefix . '__bars">';
    foreach ($rows as $i => $row) {
        // ширина по той же шкале, что и радар: 50% упирается в край
        $width = (int)round(min(PROFILE_RADAR_SCALE, $row['percent']) * 100 / PROFILE_RADAR_SCALE);
        $html .= '<li class="' . $prefix . '__bar' . ($i === 0 ? ' ' . $prefix . '__bar--top' : '') . '">'
               . '<b>' . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . '</b>'
               . '<em>' . $row['percent'] . '%</em>'
               . '<span class="' . $prefix . '__track"><i style="width:' . $width . '%"></i></span></li>';
    }
    return $html . '</ul>';
}

/* «12 января 2026» — дата фиксации профиля. */
function profile_format_date($datetime) {
    $ts = strtotime((string)$datetime);
    if (!$ts) {
        return '';
    }
    $months = array('января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря');
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/*
 * X-4. Оговорка, без которой портрет читать нельзя.
 *
 * Шадханы просили клинические психотипы (Маквильямс) — мы их сознательно не
 * внедряем: опрос из бытовых кейсов не инструмент психиатрической типизации,
 * а ярлык о живом человеке в руках свата вредит. Показываем ЦЕННОСТИ, а не
 * тип личности, и говорим об этом прямо там, где человек видит проценты.
 */
function profile_disclaimer() {
    return 'Это не диагноз и не оценка личности. Данные получены самоопросом —
        человек отвечал сам о себе, свободным текстом. Портрет показывает, что
        для него важно, и служит ориентиром для разговора, а не заключением о
        нём. Проверяйте выводы в живой беседе.';
}
