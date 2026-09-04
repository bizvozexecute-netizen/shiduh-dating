# -*- coding: utf-8 -*-
"""
A0 этапа «До идеала»: каркас дизайн-слоя (css/one.css + js/one.js).

Исходников бандла styles-90.css нет (316 КБ минифицированного кода без
sourcemap), поэтому слой аддитивный: подключается ПОСЛЕ бандла и переопределяет
его тем же селектором, выигрывая порядком каскада, а не !important.

Тесты держат три вещи, которые легко сломать незаметно:
  - склейку макета: Template.html — это Header.html + Footer.html побайтно,
    и ядро пересобирает части из Template.html при расхождении md5;
  - бюджеты размера: слой обязан оставаться слоем, а не вторым бандлом;
  - контраст пар «текст/фон» по WCAG — у бандла белый текст на золоте даёт
    2.1:1, и повторить эту ошибку в токенах нельзя.

    python -m pytest tests/design_layer_test.py -q
"""
import io
import os
import re
import sys
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
TPL = os.path.join(WEBROOT, "netcat_template", "template", "3")

CSS_BUDGET_KB = 60
JS_BUDGET_KB = 15


def read(path):
    return io.open(path, encoding="utf-8", errors="replace").read()


def fetch(path):
    r = urllib.request.urlopen(BASE + path, timeout=60)
    return r.status, r.read().decode("utf-8", "replace")


# --------------------------------------------------------------- подключение ---

def test_layout_splice_stays_intact():
    """Template.html = Header.html + Footer.html. Если разойдутся, ядро
    пересоберёт части из Template.html и правка в Header.html пропадёт."""
    t, h, f = (read(os.path.join(TPL, n)) for n in ("Template.html", "Header.html", "Footer.html"))
    mh = re.search(r"<!-- Header -->(.*?)<!-- /Header -->", t, re.S)
    mf = re.search(r"<!-- Footer -->(.*?)<!-- /Footer -->", t, re.S)
    assert mh and mh.group(1) == h, "секция Header в Template.html разошлась с Header.html"
    assert mf and mf.group(1) == f, "секция Footer в Template.html разошлась с Footer.html"


@pytest.mark.parametrize("name", ["Template.html", "Header.html"])
def test_layer_is_linked_after_bundle(name):
    src = read(os.path.join(TPL, name))
    assert "/css/one.css" in src, name + ": слой не подключён"
    assert "/js/one.js" in src, name + ": скрипт слоя не подключён"
    # Порядок каскада — единственный способ переопределить бандл без
    # !important. Сравниваем именно теги <link>: имя файла встречается ещё и
    # в PHP-блоке, который считает версию по filemtime, выше по документу.
    sheets = re.findall(r'<link[^>]+rel="stylesheet"[^>]+href="([^"?]+)', src)
    assert "/css/styles-90.css" in sheets and "/css/one.css" in sheets, \
        name + ": не найдены оба листа: %s" % sheets
    assert sheets.index("/css/styles-90.css") < sheets.index("/css/one.css"), \
        name + ": слой должен идти ПОСЛЕ бандла, порядок %s" % sheets


@pytest.mark.parametrize("path,budget_kb", [("/css/one.css", CSS_BUDGET_KB), ("/js/one.js", JS_BUDGET_KB)])
def test_asset_is_served_and_within_budget(path, budget_kb):
    code, body = fetch(path)
    assert code == 200, path + " не отдаётся"
    kb = len(body.encode("utf-8")) / 1024.0
    assert kb <= budget_kb, "%s разросся: %.1f КБ при бюджете %d КБ" % (path, kb, budget_kb)


def test_fonts_are_preloaded():
    """Шрифты самохостятся и грузятся из CSS — без preload текст ждёт бандл."""
    src = read(os.path.join(TPL, "Header.html"))
    links = re.findall(r'<link rel="preload"[^>]*as="font"[^>]*>', src)
    assert len(links) >= 2, "не заданы preload шрифтов: %d" % len(links)
    assert all("crossorigin" in l for l in links), "preload шрифта без crossorigin не сработает"


def test_skip_link_and_main_landmark():
    code, html = fetch("/")
    assert code == 200
    assert 'class="sh-skip"' in html, "нет ссылки «к содержимому» для клавиатуры"
    assert 'id="main"' in html, "нет якоря основного содержимого"


# ------------------------------------------------------------------- токены ---

REQUIRED_TOKENS = [
    "--sh-gold", "--sh-gold-ink", "--sh-navy", "--sh-ink", "--sh-ink-2",
    "--sh-cream", "--sh-paper", "--sh-line",
    "--sh-fs-display", "--sh-fs-h1", "--sh-fs-body",
    "--sh-s-3", "--sh-r-m", "--sh-t-base", "--sh-ease-out", "--sh-z-header",
]


def css():
    return read(os.path.join(WEBROOT, "css", "one.css"))


def test_tokens_are_defined_on_root():
    src = css()
    root = re.search(r":root\s*\{(.*?)\}", src, re.S)
    assert root, "нет блока :root с токенами"
    missing = [t for t in REQUIRED_TOKENS if t + ":" not in root.group(1).replace(" ", "")]
    assert not missing, "нет токенов: " + ", ".join(missing)


def test_important_budget():
    """Слой побеждает порядком каскада. Единственное исключение —
    перенесённое из макета правило CKEditor."""
    code = re.sub(r"/\*.*?\*/", "", css(), flags=re.S)   # комментарии не в счёт
    n = code.count("!important")
    assert n <= 1, "в слое %d !important — значит специфичность вышла из-под контроля" % n


def test_reduced_motion_is_respected():
    assert "prefers-reduced-motion" in css(), "нет блока для тех, кому анимация мешает"


def test_no_dark_scheme_guesswork():
    """Бренд светлый, арты нарисованы под крем. Тёмную тему не изображаем,
    но и не отдаём на откуп браузеру — иначе нативные контролы почернеют."""
    assert "color-scheme" in css(), "не задана color-scheme"


# ------------------------------------------------------------------ контраст ---

def luminance(hex_color):
    h = hex_color.lstrip("#")
    if len(h) == 3:                       # короткая запись: #fff
        h = "".join(c * 2 for c in h)
    r, g, b = (int(h[i:i + 2], 16) / 255.0 for i in (0, 2, 4))

    def ch(c):
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4

    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)


def contrast(a, b):
    la, lb = luminance(a), luminance(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def token_values():
    src = css()
    root = re.search(r":root\s*\{(.*?)\}", src, re.S).group(1)
    return {m.group(1): m.group(2).strip()
            for m in re.finditer(r"(--sh-[\w-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;", root)}


# (текст, фон, минимум). 4.5 — обычный текст, 3.0 — крупный/дисплейный.
CONTRAST_PAIRS = [
    ("--sh-ink", "--sh-cream", 4.5),
    ("--sh-ink", "--sh-paper", 4.5),
    ("--sh-ink-2", "--sh-cream", 4.5),
    ("--sh-gold-ink", "--sh-cream", 4.5),
    ("--sh-navy", "--sh-gold", 4.5),      # основная кнопка: у бандла тут белый = 2.1:1
    ("--sh-paper", "--sh-navy", 4.5),
    ("--sh-gold-deep", "--sh-paper", 3.0),
]


@pytest.mark.parametrize("fg,bg,minimum", CONTRAST_PAIRS,
                         ids=["%s-on-%s" % (p[0][5:], p[1][5:]) for p in CONTRAST_PAIRS])
def test_token_pair_contrast(fg, bg, minimum):
    vals = token_values()
    for t in (fg, bg):
        assert t in vals, "нет токена-цвета " + t
    ratio = contrast(vals[fg], vals[bg])
    assert ratio >= minimum, "%s на %s даёт %.2f:1 при минимуме %.1f" % (fg, bg, ratio, minimum)


# ----------------------------------------------------------------- живой сайт ---

PAGES = ["/", "/news/", "/video/"]


@pytest.mark.parametrize("path", PAGES)
def test_pages_render_without_php_errors(path):
    code, html = fetch(path)
    assert code == 200, "%s отдал %s" % (path, code)
    for marker in ("Fatal error", "Parse error", "Uncaught Error", "Warning: require"):
        assert marker not in html, "%s: в разметке %s" % (path, marker)


def test_layer_files_have_no_syntax_errors():
    """CSS/JS не компилируются, но битую разметку макета php -l поймает."""
    for rel in ("netcat_template/template/3/Header.html",
                "netcat_template/template/3/Template.html",
                "netcat_template/template/3/Footer.html"):
        r = web("php", "-l", "/var/www/html/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel + ": " + (r.stdout or r.stderr)[:200]


# ============================ A1: каталог компонентов =======================
#
# Компоненты нужны раньше экранов: на них опирается чат (B2), админ-консоль
# (C8) и переезд инлайновых стилей из шаблонов (A5–A7). Тест держит состав
# каталога и бюджеты — чтобы слой не превратился во второй бандл.

# (класс, зачем он) — по одному представителю на группу
CATALOG = [
    (".sh-card", "карточка"),
    (".sh-sec-head", "заголовок раздела"),
    (".sh-eyebrow", "надзаголовок"),
    (".sh-pill", "пилюля-метка"),
    (".sh-bar", "полоса-индикатор"),
    (".sh-toast", "всплывающее сообщение"),
    (".sh-skel", "скелетон загрузки"),
    (".sh-empty", "пустое состояние"),
    (".sh-field", "поле формы"),
    (".sh-field__error", "ошибка у поля"),
    (".sh-stepper", "шаги мастера"),
    (".sh-chat", "панель чата"),
    (".sh-msg", "реплика в чате"),
    (".sh-typing", "индикатор ожидания ответа"),
    (".sh-composer", "поле ввода сообщения"),
    (".sh-chip", "быстрый ответ"),
    (".sh-row", "строка списка"),
    (".sh-pager", "постраничная навигация"),
    (".sh-avatar", "аватар"),
]


@pytest.mark.parametrize("cls,what", CATALOG, ids=[c[0] for c in CATALOG])
def test_component_is_in_catalog(cls, what):
    assert cls in css(), "нет компонента %s (%s)" % (cls, what)


def test_primary_button_is_readable():
    """У бандла .button — белый текст на золоте (2.1:1). Слой обязан это
    переопределить, иначе основная кнопка сайта остаётся нечитаемой."""
    # без комментариев: в шапке правила процитирован исходный стиль бандла
    src = re.sub(r"/\*.*?\*/", "", css(), flags=re.S)
    m = re.search(r"\.button[^{]*\{([^}]*)\}", src)
    assert m, "слой не переопределяет .button"
    body = m.group(1)
    assert "--sh-navy" in body, "текст кнопки должен быть тёмно-синим: " + body[:120]


def test_admin_stylesheet_exists_and_maps_tokens():
    """Автономные админ-экраны рисуют свой <head> в обход макета: им нужен
    отдельный лист, который приводит их локальные переменные к токенам слоя."""
    path = os.path.join(WEBROOT, "css", "one-admin.css")
    assert os.path.exists(path), "нет css/one-admin.css"
    src = read(path)
    kb = len(src.encode("utf-8")) / 1024.0
    # C8: лист принял стили всех админ-экранов (раньше — три копии в самих
    # файлах), поэтому бюджет вырос с 8 до 12 КБ
    assert kb <= 12, "one-admin.css разросся: %.1f КБ" % kb
    assert "var(--sh-" in src, "лист админки не опирается на токены слоя"


def test_chat_bubbles_wrap_long_words():
    """Ссылка или длинное слово в ответе модели не должны растягивать
    страницу по горизонтали — на телефоне это ломает всю раскладку."""
    src = css()
    m = re.search(r"\.sh-msg\b[^{]*\{([^}]*)\}", src)
    assert m, "нет базового правила .sh-msg"
    assert "word-break" in m.group(1) or "overflow-wrap" in m.group(1), \
        "реплика не переносит длинные слова: " + m.group(1)[:150]
