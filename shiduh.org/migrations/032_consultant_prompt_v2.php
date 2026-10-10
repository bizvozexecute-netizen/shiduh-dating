<?php
/*
 * Промпт консультанта, версия 2 (2026-10-10). Текст — AI_DEFAULT_SYSTEM_PROMPT
 * в api/ai_client.php; почему он такой — там же.
 *
 * Встроенный текст действует, только пока промпт ни разу не сохраняли в
 * админке. На стенде его сохраняли, поэтому новую версию дописываем в
 * ai_prompts тем же путём, что и кнопка «Сохранить» (prompt_store): история
 * остаётся полной, к прошлой версии можно вернуться из админки.
 *
 * Откат удаляет только строку, добавленную этой миграцией, и только если
 * после неё промпт никто не менял.
 */
require_once dirname(__FILE__) . '/../api/ai_client.php';
require_once dirname(__FILE__) . '/../api/prompt_lib.php';

return array(
    'up' => function ($nc) {
        $db = $nc->db;
        $active = prompt_active($db);
        if ($active && (string)$active['content'] === AI_DEFAULT_SYSTEM_PROMPT) {
            return;                       // уже включена
        }
        if (!prompt_store($db, AI_DEFAULT_SYSTEM_PROMPT, 0)) {
            throw new Exception('не удалось записать промпт v2 в ai_prompts');
        }
    },

    'down' => function ($nc) {
        $db = $nc->db;
        $active = prompt_active($db);
        if ($active && (string)$active['content'] === AI_DEFAULT_SYSTEM_PROMPT
            && (int)$active['updated_by'] === 0) {
            $db->query("DELETE FROM `ai_prompts` WHERE `id`=" . (int)$active['id']);
        }
    },
);
