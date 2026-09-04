<?php
/*
 * Безопасная разметка ответов консультанта (этап «До идеала», B4).
 *
 * Модель отвечает markdown-ом: списки, выделения, иногда ссылка. Сейчас текст
 * показывается как есть, поэтому человек видит «**важно**» и «- пункт» сырыми.
 *
 * Готовый разборщик markdown сюда не годится: он умеет пропускать HTML, а
 * текст модели — это чужой ввод. Поэтому собственная узкая грамматика,
 * устроенная так, что вставить разметку НЕВОЗМОЖНО: сначала весь текст
 * экранируется целиком, и только потом на экранированной строке расставляются
 * теги. Никакого «разрешённого HTML» нет вовсе.
 *
 * Та же грамматика продублирована в js/chat.js для живых сообщений. Совпадение
 * двух реализаций держится общими фикстурами (tests/fixtures/markdown_lite_cases.json)
 * — расхождение сразу ловится тестом.
 *
 * Поддерживается: абзацы, перенос строки, жирный и курсив (звёздочками и
 * подчёркиванием), код в обратных кавычках, маркированные и нумерованные
 * списки, ссылки http и https.
 */

define('MDLITE_LINK_ATTRS', ' rel="noopener noreferrer" target="_blank"');

function markdown_lite_html($text) {
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim($text) === '') {
        return '';
    }

    $out = '';
    $list = null;       // 'ul' | 'ol' | null — открытый список
    $para = array();    // строки текущего абзаца

    $close_para = function () use (&$para, &$out) {
        if ($para) {
            $out .= '<p>' . implode('<br>', $para) . '</p>';
            $para = array();
        }
    };
    $close_list = function () use (&$list, &$out) {
        if ($list) {
            $out .= '</' . $list . '>';
            $list = null;
        }
    };

    foreach (explode("\n", $text) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $close_para();
            $close_list();
            continue;
        }

        // Пункт списка: маркер только в начале строки, иначе «как-то так»
        // превратилось бы в список.
        if (preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $m)) {
            $close_para();
            if ($list !== 'ul') {
                $close_list();
                $out .= '<ul>';
                $list = 'ul';
            }
            $out .= '<li>' . markdown_lite_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/u', $line, $m)) {
            $close_para();
            if ($list !== 'ol') {
                $close_list();
                $out .= '<ol>';
                $list = 'ol';
            }
            $out .= '<li>' . markdown_lite_inline($m[1]) . '</li>';
            continue;
        }

        $close_list();
        $para[] = markdown_lite_inline($trimmed);
    }

    $close_para();
    $close_list();
    return $out;
}

/*
 * Оформление внутри строки. Порядок важен: сначала экранируем ВЕСЬ текст,
 * потом расставляем теги по уже безопасной строке. Поэтому «<b>» из ответа
 * модели останется видимым текстом при любых сочетаниях символов.
 */
function markdown_lite_inline($text) {
    $s = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');

    // Код — первым: внутри него оформление не действует.
    $s = preg_replace_callback('/`([^`]+)`/u', function ($m) {
        return '<code>' . $m[1] . '</code>';
    }, $s);

    // Ссылка вида [текст](адрес). Адрес — только http(s) и без кавычек и
    // пробелов: «javascript:» и попытка выйти из атрибута остаются текстом.
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\s()<>"\']+)\)/u',
        function ($m) {
            return '<a href="' . $m[2] . '"' . MDLITE_LINK_ATTRS . '>' . $m[1] . '</a>';
        }, $s);

    // Голая ссылка. Хвостовая пунктуация в адрес не входит: «читайте
    // https://site/faq.» — точка это конец предложения, а не часть адреса.
    //
    // Адрес обрывается и на экранированных последовательностях: к моменту
    // разбора кавычка и угловая скобка уже стали &quot; и &lt;, и без этого
    // они уехали бы внутрь href. Просто исключить «&» нельзя — он разделяет
    // параметры запроса и в адресах законен.
    $s = preg_replace_callback(
        '/(?<!href=")(?<!>)(https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#0?39;)[^\s<>"\'])+)/u',
        function ($m) {
            $url = $m[1];
            $tail = '';
            while ($url !== '' && strpos('.,;:!?)»', substr($url, -1)) !== false) {
                $tail = substr($url, -1) . $tail;
                $url = substr($url, 0, -1);
            }
            if ($url === '') {
                return $m[1];
            }
            return '<a href="' . $url . '"' . MDLITE_LINK_ATTRS . '>' . $url . '</a>' . $tail;
        }, $s);

    // Жирный до курсива: иначе «**текст**» распался бы на две звёздочки.
    $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s);
    // Границы слова заданы через \p{L}\p{N}, а не \w: в обоих языках \w — это
    // только латиница, и «имя_поля_анкеты» с кириллицей стало бы курсивом.
    $s = preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\p{L}\p{N}*])/u',
        '<em>$1</em>', $s);
    $s = preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)([^_]+?)(?<=\S)_(?![\p{L}\p{N}_])/u',
        '<em>$1</em>', $s);

    return $s;
}
