<?php
/*
 * Системные промпты с версиями (ROADMAP Э1.2).
 *
 * Хранилище — таблица ai_prompts (миграция 001). Правило: история только
 * дописывается. Сохранение = новая строка с version = max+1; активен промпт
 * с наибольшей версией. Откат к прошлой версии — тоже новая версия с её
 * содержимым, поэтому журнал «кто и когда менял» остаётся полным, а вернуться
 * можно к любому состоянию.
 *
 * Пока промпт не задан, работает встроенный текст по умолчанию из ai_client.php
 * (AI_DEFAULT_SYSTEM_PROMPT) — сайт исправен «из коробки».
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

define('PROMPT_KEY_CONSULTANT', 'consultant_system');
// С запасом выше любого реального промпта: он приписывается к КАЖДОМУ
// запросу, то есть оплачивается на каждом ходу диалога.
define('PROMPT_MAX_LEN', 6000);

/* Активная (последняя) версия промпта или null, если ещё не задавали. */
function prompt_active($db, $key = PROMPT_KEY_CONSULTANT) {
    // `id` DESC вторым ключом: если версии когда-нибудь задвоятся (например,
    // после перевода таблиц на InnoDB, где INSERT..SELECT не блокирует
    // источник), выбор активной версии останется определённым, а экран
    // админки не покажет одну версию, пока модели уходит другая.
    $row = $db->get_row(
        "SELECT `id`, `content`, `version`, `updated_by`, `updated_at` "
        . "FROM `ai_prompts` WHERE `key`='" . $db->prepare($key) . "' "
        . "ORDER BY `version` DESC, `id` DESC LIMIT 1",
        ARRAY_A
    );
    if ($db->is_error) {
        // молча уйти на промпт по умолчанию нельзя: подмена роли консультанта
        // должна быть видна в логе
        shiduh_log('ai', 'prompt_active: ошибка чтения ai_prompts');
    }
    return $row ? $row : null;
}

/* Все версии, свежие сверху — для журнала на экране админки. */
function prompt_history($db, $key = PROMPT_KEY_CONSULTANT, $limit = 50) {
    $limit = (int)$limit;
    $db->query(
        "SELECT `content`, `version`, `updated_by`, `updated_at` "
        . "FROM `ai_prompts` WHERE `key`='" . $db->prepare($key) . "' "
        . "ORDER BY `version` DESC, `id` DESC LIMIT $limit",
        ARRAY_A
    );
    return (array)$db->last_result;
}

/* Содержимое конкретной версии или null. */
function prompt_version($db, $version, $key = PROMPT_KEY_CONSULTANT) {
    $version = (int)$version;
    return $db->get_var(
        "SELECT `content` FROM `ai_prompts` WHERE `key`='" . $db->prepare($key) . "' "
        . "AND `version`=$version ORDER BY `id` DESC LIMIT 1"
    );
}

/*
 * Записывает новую версию. Возвращает её номер или 0 при ошибке.
 * Номер берём как max+1 в одном запросе — так две одновременные правки не
 * получат одинаковую версию молча (UNIQUE на паре нет, но и потери нет:
 * обе строки сохранятся, активной станет последняя).
 */
function prompt_store($db, $content, $author_id, $key = PROMPT_KEY_CONSULTANT) {
    $key_esc = $db->prepare($key);
    $author_id = (int)$author_id;
    $db->query(
        "INSERT INTO `ai_prompts` (`key`, `content`, `version`, `updated_by`, `updated_at`) "
        . "SELECT '$key_esc', '" . $db->prepare($content) . "', "
        . "IFNULL(MAX(`version`),0)+1, $author_id, NOW() "
        . "FROM `ai_prompts` WHERE `key`='$key_esc'"
    );
    if ($db->is_error || !$db->insert_id) {
        return 0;
    }
    return (int)$db->get_var(
        "SELECT `version` FROM `ai_prompts` WHERE `id`=" . (int)$db->insert_id
    );
}
