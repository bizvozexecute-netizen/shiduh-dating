<?php
/*
 * Лимиты размера и типа файлов анкеты (этап «До идеала», C5).
 *
 * Поля анкеты (класс 204) принимали что угодно и любого размера: формат
 * файловых полей был без ограничений, а проверка типа в ядре — по заявленному
 * клиентом MIME. Ставим лимиты тем же способом, что миграция 009 для вложений
 * сообщений: префикс "<байт>:<mime,...>" в Format поля.
 *
 *   507/547 Photo_avatar            — 8 МБ, только изображения
 *   508     Photo_questionnaire     — 10 МБ, только изображения (multifile)
 *   509     Birthcertificates       — 10 МБ, изображения и PDF
 *   510     Another_document_Jewishness — 10 МБ, изображения и PDF
 *
 * Аватар и раньше по смыслу был картинкой: в форме accept предлагал ещё и
 * .pdf, это правится в шаблонах тем же коммитом. Хэш настроек виджета
 * multifile (settings_*_hash) от size/type не зависит — формы не ломаются.
 *
 * Идемпотентно: префикс не добавляется повторно; down снимает ровно его.
 */

$LIMITS = array(
    // Field_ID => префикс. Инвариант, на котором держится склейка:
    //  * у одиночных полей (507/547/509/510) префикс кончается на ':' — он
    //    и отделяет тип от следующего сегмента "fs2:inline;…";
    //  * у multifile 508 формат начинается с ';', поэтому "size:type" + ";…"
    //    даёт валидное "size:type;…".
    // Парсер ядра (nc_field_parse_format) в обоих случаях берёт size:type из
    // головы до первого ';' — проверено на реальных данных полей.
    507 => '8388608:image/*:',
    547 => '8388608:image/*:',
    508 => '10485760:image/*',
    509 => '10485760:image/*,application/pdf:',
    510 => '10485760:image/*,application/pdf:',
);

return array(
    'up' => function ($nc) use ($LIMITS) {
        $db = $nc->db;
        foreach ($LIMITS as $fid => $prefix) {
            $p = $db->prepare($prefix);
            $db->query(
                "UPDATE `Field` SET `Format`=CONCAT('$p', `Format`) "
                . "WHERE `Field_ID`=" . (int)$fid . " AND `Format` NOT LIKE '" . $db->prepare($prefix) . "%'"
            );
        }
    },

    'down' => function ($nc) use ($LIMITS) {
        $db = $nc->db;
        foreach ($LIMITS as $fid => $prefix) {
            $p = $db->prepare($prefix);
            $db->query(
                "UPDATE `Field` SET `Format`=TRIM(LEADING '$p' FROM `Format`) WHERE `Field_ID`=" . (int)$fid
            );
        }
    },
);
