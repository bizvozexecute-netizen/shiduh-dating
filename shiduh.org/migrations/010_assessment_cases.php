<?php
/*
 * Кейсы ценностного опроса (ТЗ Э2.2-a).
 *
 * ИИ вплетает кейсы в диалог по одному (Э2.2-c), ответ классифицирует LLM
 * (Э2.3-a), итоговый профиль считает КОД (Э2.3-b). Здесь — хранилище кейсов
 * и их редактирование в админке.
 *
 *   dimension_group — 'love_language' (языки любви, 5) | 'family_value'
 *                     (ценностные измерения, справочник Classificator_Value_dimension)
 *   dimension       — ключ измерения внутри группы
 *   scenario_text   — текст кейса, который ИИ задаёт в диалоге
 *   active          — выключенный кейс не попадает в опрос (мягкое удаление)
 *   sort_order      — порядок предъявления (order — зарезервированное слово)
 *
 * Стартовый набор — по одному кейсу на измерение: показывает формат и делает
 * экран админки наполненным. Полное покрытие 5–7 кейсов на измерение — Э2.2-b;
 * формулировки согласовать с заказчиком/раввином.
 *
 * Таблица модуля (не компонент NetCat): обычный SQL, MyISAM + utf8 как база.
 */

$SEED = array(
    // group,          dimension,          scenario_text
    // Измерение «слова»: важно ли ЧЕЛОВЕКУ слышать поддержку. Поэтому с
    // трудного дня возвращается он сам — иначе вопрос читался как «супруг
    // устал, а поддержки ждёте вы» (перевёрнутая формулировка правилась 023).
    array('love_language', 'words',        'Вы вернулись домой после трудного дня. Что вам важнее всего в такой момент: услышать слова поддержки и признания — или что-то другое?'),
    array('love_language', 'time',         'Выдался свободный вечер. Что для вас ценнее: провести его вдвоём без спешки и телефонов — или каждому заняться своим делом рядом?'),
    array('love_language', 'gifts',        'Близкий человек принёс вам небольшой подарок без повода. Насколько для вас важны такие знаки внимания?'),
    array('love_language', 'service',      'Вы устали, и супруг(а) молча взял(а) на себя часть ваших дел по дому. Насколько это для вас проявление любви?'),
    array('love_language', 'touch',        'Насколько для вас важны объятия и прикосновения в повседневном общении с близким человеком?'),

    array('family_value',  'budget',       'Семья планирует крупную покупку. Как вам ближе решать: общий бюджет и совместное решение, раздельные деньги, или решает тот, кто зарабатывает?'),
    array('family_value',  'roles',        'Как вы представляете распределение домашних дел и ответственности в семье?'),
    array('family_value',  'children',     'В чём, по-вашему, главная задача родителей в воспитании детей — и где граница между строгостью и свободой?'),
    array('family_value',  'parents',      'Насколько родители супругов могут участвовать в жизни новой семьи? Где для вас проходит граница?'),
    array('family_value',  'religion',     'Каким вы видите уклад соблюдения в вашем доме — и что будете делать, если у супруга(и) он окажется иным?'),
    array('family_value',  'career',       'Если работа начнёт занимать больше времени, чем семья, — как для вас выглядит правильный выбор?'),
    array('family_value',  'relocation',   'Насколько вы готовы к переезду в другой город или страну ради семьи?'),
);

return array(
    'up' => function ($nc) use ($SEED) {
        $db = $nc->db;
        $db->query("CREATE TABLE IF NOT EXISTS `assessment_cases` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `dimension_group` varchar(32) NOT NULL,
            `dimension` varchar(64) NOT NULL,
            `scenario_text` text NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `dim` (`dimension_group`, `dimension`),
            KEY `active_order` (`active`, `sort_order`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        // Стартовые кейсы — идемпотентно по (группа, измерение): повторный up
        // не дублирует, а дозаполняет недостающее (как в 002).
        $order = 0;
        foreach ($SEED as $row) {
            list($group, $dim, $text) = $row;
            $order++;
            $exists = (int)$db->get_var(
                "SELECT COUNT(*) FROM `assessment_cases` "
                . "WHERE `dimension_group`='" . $db->escape($group) . "' "
                . "AND `dimension`='" . $db->escape($dim) . "'"
            );
            if ($exists === 0) {
                $db->query("INSERT INTO `assessment_cases` SET "
                    . "`dimension_group`='" . $db->escape($group) . "', "
                    . "`dimension`='" . $db->escape($dim) . "', "
                    . "`scenario_text`='" . $db->escape($text) . "', "
                    . "`active`=1, `sort_order`=$order, `created_at`=NOW()");
            }
        }
    },

    'down' => function ($nc) {
        // Таблица целиком наша: кейсы, добавленные админом, тоже уйдут.
        // На проде down не запускать (см. предупреждение в migrate.php).
        $nc->db->query("DROP TABLE IF EXISTS `assessment_cases`");
    },
);
