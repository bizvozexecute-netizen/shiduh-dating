<?php
/*
 * Помощники оболочки кабинета (этап «До идеала», A3): аватар-миниатюра,
 * ссылки выхода и профиля, краткая карточка собеседника.
 *
 * Аватары в кабинете отдавались оригиналом (до нескольких МБ на строку
 * списка сообщений); ядро умеет миниатюры само (nc_image_path::resize —
 * файл генерируется один раз и кэшируется рядом с оригиналом), нужно лишь
 * обернуть путь из таблицы User в объект.
 */

/* Ссылка выхода: адрес возврата закодирован, иначе query после «?» терялась. */
function lk_logoff_url() {
    return '/netcat/modules/auth/?logoff=1&REQUESTED_FROM=' . rawurlencode((string)$_SERVER['REQUEST_URI'])
        . '&REQUESTED_BY=' . rawurlencode((string)$_SERVER['REQUEST_METHOD']);
}

/* Страница правки профиля: у кандидата — анкета, у остальных — профиль. */
function lk_profile_url($type_id) {
    return (int)$type_id === 1 ? '/my/modify/' : '/my/modify-shidhan/';
}

/*
 * URL аватара размером $size×$size (обрезка по центру). Пустой путь или
 * не-картинка → заглушка по полу. Плейсхолдеры не ресайзим: они и так малы.
 * Ядру нужны сущность/поле/объект (User/Photo_avatar/id) — без них оно
 * молча возвращает оригинал; сам файл генерируется при первом запросе и
 * кладётся в netcat_files/generated/.
 */
function lk_avatar_url($path, $gender_id, $size = 96, $user_id = 0) {
    $path = trim((string)$path);
    if ($path === '') {
        return (int)$gender_id === 1 ? '/images/avatar/man.png' : '/images/avatar/woman.png';
    }
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ((int)$user_id <= 0 || !in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)
        || !class_exists('nc_image_path_field')) {
        return $path;
    }
    try {
        $img = new nc_image_path_field($path, $ext);
        $img->set_entity('User')->set_field('Photo_avatar')->set_object((int)$user_id);
        $url = (string)$img->resize((int)$size, (int)$size);
        return $url !== '' ? $url : $path;
    } catch (Exception $e) {
        return $path;
    }
}

/*
 * Собеседник одним запросом: раньше шаблон списка сообщений звал
 * get_by_id четыре раза на строку (аватар дважды, пол, имя).
 */
function lk_user_brief($user_id, $avatar_size = 96) {
    static $cache = array();
    $user_id = (int)$user_id;
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    $row = $user_id > 0 ? nc_core::get_object()->user->get_by_id($user_id) : null;
    $row = is_array($row) ? $row : array();
    $cache[$user_id] = array(
        'id'     => $user_id,
        'name'   => isset($row['Name']) ? (string)$row['Name'] : '',
        'type'   => isset($row['Type_id']) ? (int)$row['Type_id'] : 0,
        'avatar' => lk_avatar_url(isset($row['Photo_avatar']) ? $row['Photo_avatar'] : '',
            isset($row['Gender_id']) ? $row['Gender_id'] : 0, $avatar_size, $user_id),
    );
    return $cache[$user_id];
}

/*
 * Текст письма для показа. Письма хранятся как ввели (иногда с HTML от
 * системных уведомлений: <br>, <a href>), а старые шаблоны печатали их
 * через htmlspecialchars_decode — то есть исполняли HTML чужого письма у
 * получателя. Здесь HTML сводится к тексту: <br> → перенос, ссылка →
 * «текст (адрес)», остальные теги срезаются. $preview — одной строкой без
 * разметки (для списка), иначе — экранированный HTML с переносами.
 */
function lk_message_text($raw, $preview = false) {
    $t = htmlspecialchars_decode((string)$raw, ENT_QUOTES);
    $t = preg_replace('~<br[^>]*>~i', $preview ? ' ' : "\n", $t);
    $t = preg_replace_callback('~<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>~is', function ($m) {
        $txt = trim(strip_tags($m[2]));
        $href = trim($m[1]);
        if ($txt === '') {
            return $href;
        }
        return $txt . ($href !== '' && $href !== $txt ? ' (' . $href . ')' : '');
    }, $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    if ($preview) {
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
    return nl2br(htmlspecialchars(trim($t), ENT_QUOTES, 'UTF-8'));
}

/*
 * Момент письма для data-time: ISO 8601 со смещением сервера. Раньше
 * печаталось местное время с меткой GMT+0000, и браузер прибавлял к нему
 * свой пояс — москвич видел +3 часа.
 */
function lk_time_attr($y, $m, $d, $h, $i) {
    $ts = mktime((int)$h, (int)$i, 0, (int)$m, (int)$d, (int)$y);
    return $ts ? date(DATE_ATOM, $ts) : '';
}

/* Имя пользователя для печати: поле может прийти уже экранированным ядром —
   декодируем и экранируем ровно один раз. */
function lk_esc($s) {
    return htmlspecialchars(htmlspecialchars_decode((string)$s, ENT_QUOTES), ENT_QUOTES, 'UTF-8');
}
