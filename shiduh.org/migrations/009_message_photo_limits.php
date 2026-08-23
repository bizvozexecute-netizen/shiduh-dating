<?php
/*
 * Ограничение типа и размера вложений в личных сообщениях (ROADMAP Ф-2).
 *
 * Поле image (Field_ID=648, тип multifile) компонента «Личные сообщения»
 * имело пустой формат → грузилось что угодно (уже прилетали .mp4 до 18 МБ,
 * .pdf). Формат multifile-поля: "<size_bytes>:<mime,mime,...>;<настройки>".
 * Ставим лимит 10 МБ и только изображения (image/*). Серверную проверку
 * делает штатный nc_multifield_saver (finfo по реальным байтам, тип не
 * подделать переименованием) — правим только данные, код движка не трогаем.
 *
 * Хэш настроек виджета (settings_image_hash) от size/type НЕ зависит
 * (get_setting_hash считает use_name, path, preview-, resize-, min, max и
 * SecretKey), поэтому существующая загрузка на проде не ломается.
 */
// Значения — локальные в замыканиях (без define на уровне файла: миграции
// подключаются через require и константа могла бы конфликтовать).
return array(
    'up' => function ($nc) {
        $fid = 648;                          // поле image компонента сообщений
        $prefix = '10485760:image/*';        // 10 МБ, только изображения
        // идемпотентно: не добавляем префикс повторно
        $nc->db->query(
            "UPDATE `Field` SET `Format`=CONCAT('$prefix', `Format`) "
            . "WHERE `Field_ID`=$fid AND `Format` NOT LIKE '$prefix%'"
        );
    },

    'down' => function ($nc) {
        $fid = 648;
        $prefix = '10485760:image/*';
        // снимаем ровно наш префикс из начала строки (no-op, если его нет)
        $nc->db->query(
            "UPDATE `Field` SET `Format`=TRIM(LEADING '$prefix' FROM `Format`) "
            . "WHERE `Field_ID`=$fid"
        );
    },
);
