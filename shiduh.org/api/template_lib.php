<?php
/*
 * Мелкие помощники для шаблонов NetCat (этап «До идеала», C2).
 *
 * shiduh_back_url(): ссылка «Назад». Раньше в href подставлялся сырой
 * $_SERVER['HTTP_REFERER'] (AUDIT №9, 40 мест) — это отражённый XSS через
 * заголовок и утечка чужого адреса в разметку. Теперь: Referer принимается,
 * только если его хост совпадает с хостом сайта, и возвращается ТОЛЬКО путь
 * с query (без схемы/хоста), уже экранированный для атрибута. Иначе — $default.
 */
function shiduh_back_url($default) {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($ref === '' || $host === '') {
        return shiduh_e($default);
    }
    $parts = parse_url($ref);
    if (!$parts || empty($parts['host']) || strcasecmp($parts['host'], $host) !== 0) {
        return shiduh_e($default);
    }
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    // Только абсолютный путь ОДНОГО сайта: "//evil.com/x" и "/\evil.com/x" браузер
    // читает как внешний адрес (protocol-relative) — parse_url при этом даёт
    // host = наш (Referer вида http://наш-хост//evil.com/x). Найдено на ревью C2.
    if ($path[0] !== '/' || (isset($path[1]) && ($path[1] === '/' || $path[1] === chr(92)))) {
        return shiduh_e($default);
    }
    $url = $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    return shiduh_e($url);
}

/* Экранирование для HTML-атрибутов и текста. */
function shiduh_e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
