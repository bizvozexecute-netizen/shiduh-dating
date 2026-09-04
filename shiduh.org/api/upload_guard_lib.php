<?php
/*
 * Серверная проверка загруженных файлов анкеты (этап «До идеала», C5).
 *
 * Ядро проверяет тип файла по MIME, который заявил КЛИЕНТ ($_FILES['type']):
 * переименованный x.php с Content-Type: image/png проходит. Лимит формата
 * (миграция 024) отсекает размер и заявленный тип, а здесь — реальные байты
 * и расширение файла на диске.
 *
 * ВАЖНО: проверяем только поля, по которым В ЭТОМ запросе реально пришёл
 * файл. Иначе обычная правка одного текстового поля (или правка анкеты из
 * админки) удалила бы ранее загруженные документы: до C5 поля принимали и
 * PDF-аватары, и сканы в других форматах — это спецкатегория ПДн, стирать её
 * при постороннем сохранении нельзя.
 *
 * Не прошедший файл удаляется с диска, поле обнуляется, в лог уходит запись.
 * Для multifile-поля (галерея) ничего не делаем: nc_multifield_saver сам
 * проверяет тип через finfo.
 */

require_once dirname(__FILE__) . '/admin_lib.php';   // shiduh_log()

/* Поле → допустимый вид и расширения на диске. */
function upload_guard_fields() {
    return array(
        'Photo_avatar'                => array('image'),
        'Birthcertificates'           => array('image', 'pdf'),
        'Another_document_Jewishness' => array('image', 'pdf'),
    );
}

function upload_guard_ext_map() {
    return array(
        'image' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'),
        'pdf'   => array('pdf'),
    );
}

/*
 * Что это за файл на самом деле: 'image' | 'pdf' | 'other'. getimagesize
 * понимает только настоящие растры; PDF узнаём по сигнатуре, не по расширению.
 */
function upload_guard_kind($path) {
    if (!is_file($path)) {
        return 'other';
    }
    $info = @getimagesize($path);
    if ($info && !empty($info[2]) && in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP), true)) {
        return 'image';
    }
    $fh = @fopen($path, 'rb');
    if ($fh) {
        $head = (string)fread($fh, 5);
        fclose($fh);
        if ($head === '%PDF-') {
            return 'pdf';
        }
    }
    return 'other';
}

/* В этом запросе реально загружен файл в поле $col? */
function upload_guard_uploaded($col) {
    $key = 'f_' . $col;
    return isset($_FILES[$key]) && is_array($_FILES[$key])
        && isset($_FILES[$key]['error']) && $_FILES[$key]['error'] === UPLOAD_ERR_OK;
}

/*
 * Проверить только что загруженные файлы анкеты $message_id. Возвращает
 * список отвергнутых полей. Значение поля в формате ядра (fs2):
 * "имя:mime:размер:sub/cc/файл" — путь относительно netcat_files.
 */
function upload_guard_check($db, $message_id) {
    $message_id = (int)$message_id;
    if (!$message_id) {
        return array();
    }
    $fields = upload_guard_fields();
    // только поля с новой загрузкой — не трогаем уже лежащее
    $touched = array();
    foreach (array_keys($fields) as $col) {
        if (upload_guard_uploaded($col)) {
            $touched[] = $col;
        }
    }
    if (!$touched) {
        return array();
    }

    $cols = implode(', ', array_map(function ($c) { return "`$c`"; }, $touched));
    $row = $db->get_row("SELECT $cols FROM `Message204` WHERE `Message_ID`=$message_id", ARRAY_A);
    if (!$row) {
        return array();
    }
    $nc_core = nc_core::get_object();
    $files_root = realpath(rtrim($nc_core->DOCUMENT_ROOT, '/') . '/netcat_files');
    $ext_map = upload_guard_ext_map();
    $rejected = array();

    foreach ($touched as $col) {
        $value = (string)$row[$col];
        if ($value === '') {
            continue;
        }
        $parts = explode(':', $value);
        if (count($parts) < 4 || $parts[3] === '') {
            continue;                                // не «стандартная ФС» — не наш случай
        }
        $rel = ltrim(str_replace('\\', '/', $parts[3]), '/');
        $abs = realpath($files_root . '/' . $rel);
        // файл обязан лежать ВНУТРИ netcat_files: realpath снимает .. и симлинки
        if ($abs === false || $files_root === false || strpos($abs, $files_root) !== 0) {
            $rejected[] = $col;
            upload_guard_drop($db, $message_id, $col, null, 'путь вне каталога: ' . $rel);
            continue;
        }

        $allowed = $fields[$col];
        $kind = upload_guard_kind($abs);
        $ext = strtolower((string)pathinfo($abs, PATHINFO_EXTENSION));
        $ext_ok = false;
        foreach ($allowed as $k) {
            if ($kind === $k && in_array($ext, $ext_map[$k], true)) {
                $ext_ok = true;
                break;
            }
        }
        // и содержимое из белого списка, и расширение ему соответствует —
        // иначе polyglot (валидный PNG с .phtml) остался бы под исполняемым именем
        if (!$ext_ok) {
            $rejected[] = $col;
            upload_guard_drop($db, $message_id, $col, $abs,
                'содержимое: ' . $kind . ', расширение: .' . $ext . ', заявлено: ' . $parts[1]);
        }
    }
    return $rejected;
}

/* Удалить файл и обнулить поле. */
function upload_guard_drop($db, $message_id, $col, $abs, $why) {
    if ($abs !== null && is_file($abs)) {
        if (!@unlink($abs)) {
            // не удалить — хуже, чем не сохранить: файл остаётся по
            // предсказуемому адресу, поле уже обнулим
            shiduh_log('upload', 'НЕ удалён отклонённый файл ' . $abs . ' (поле ' . $col . ')');
        }
        $preview = dirname($abs) . '/preview_' . basename($abs);
        if (is_file($preview)) {
            @unlink($preview);
        }
    }
    $db->query("UPDATE `Message204` SET `$col`='' WHERE `Message_ID`=" . (int)$message_id);
    // остаток ActionTemplate не должен увидеть старое значение из кэша
    $nc_core = nc_core::get_object();
    if (isset($nc_core->file_info) && method_exists($nc_core->file_info, 'clear_object_cache')) {
        @$nc_core->file_info->clear_object_cache(204, (int)$message_id);
    }
    shiduh_log('upload', 'файл поля ' . $col . ' анкеты ' . (int)$message_id . ' отвергнут: ' . $why);
}
