# -*- coding: utf-8 -*-
"""
A2.2 этапа «До идеала»: публичные экраны — лендинг, шапка, подвал, меню.

Что держим:
  - числа на лендинге настоящие: счётчики анкет и шадханов печатает PHP из
    базы, лента городов — из анкет; захардкоженных «1000+» больше нет;
  - макет без инлайновых стилей (кроме ширины прогресс-полос — это данные):
    иначе CSP на этих страницах невозможна, а править их можно только в трёх
    копиях сразу;
  - копии шаблонов лендинга (Template.html ↔ Header.html) не расходятся —
    ядро пересобирает части из Template.html при расхождении;
  - логотипы с width/height (против скачка макета), картинки героя — без
    них: у них адаптивные <source> разного размера;
  - шапка липкая, подвал и меню — на слое; всё движение выключается при
    prefers-reduced-motion.

    python -m pytest tests/landing_test.py -q
"""
import io
import os
import re
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, dbq, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
TPL = os.path.join(WEBROOT, "netcat_template", "template", "3")
LANDING = os.path.join(TPL, "4")


def read(path):
    return io.open(path, encoding="utf-8", errors="replace").read()


_HOME = {}


def home():
    if "html" not in _HOME:
        _HOME["html"] = urllib.request.urlopen(BASE + "/", timeout=60).read().decode("utf-8", "replace")
    return _HOME["html"]


# ------------------------------------------------------------- счётчики ---

def test_counters_are_real_numbers_from_db():
    html = home()
    anketas = int(dbq("SELECT COUNT(*) FROM `Message204` WHERE Checked=1"))
    shadhans = int(dbq("SELECT COUNT(*) FROM `User` WHERE Type=2 AND Checked=1 AND User_ID NOT IN (1,119)"))
    counts = re.findall(r'data-sh-count="(\d+)"', html)
    assert str(anketas) in counts, "счётчик анкет не из базы: %s (в базе %d)" % (counts, anketas)
    assert str(shadhans) in counts, "счётчик шадханов не из базы: %s (в базе %d)" % (counts, shadhans)
    hero = html.split('<section class="hero">', 1)[1].split("</section>", 1)[0]
    assert "1000+" not in hero and "100+" not in hero, "остались захардкоженные числа"
    # число напечатано в разметке (без JS счётчик всё равно виден); разряды
    # могут быть разделены любым пробелом
    m = re.search(r'data-sh-count="%d"[^>]*>([^<]*)<' % anketas, html)
    assert m and re.sub(r"\s", "", m.group(1)) == str(anketas), "число не напечатано внутри счётчика: %r" % (m and m.group(1))


def test_city_marquee_lists_real_cities():
    html = home()
    m = re.search(r'<div class="sh-marquee"[^>]*>(.*?)</div>\s*</div>\s*</section>', html, re.S) \
        or re.search(r'class="sh-marquee".*?</section>', html, re.S)
    assert m, "нет ленты городов .sh-marquee"
    block = m.group(0)
    cities = re.findall(r'class="sh-marquee__item"[^>]*>\s*([^<]+?)\s*<', block)
    assert len(cities) >= 6, "в ленте меньше шести городов: %r" % cities[:6]
    top = dbq("SELECT TRIM(City) FROM `Message204` WHERE Checked=1 AND TRIM(City)<>'' "
              "GROUP BY TRIM(City) HAVING COUNT(*)>=3 ORDER BY COUNT(*) DESC, TRIM(City) LIMIT 40").splitlines()
    top_l = {c.strip().lower() for c in top}
    for c in cities[:6]:
        assert c.strip().lower() in top_l, "город не из базы: " + c
    # тестовые сентинелы и служебные значения в ленту не попадают
    assert not any("тест" in c.lower() for c in cities), cities
    # копии ленты — только для бесшовной прокрутки, диктор их не читает
    tracks = re.findall(r'<div class="sh-marquee__track"([^>]*)>', block)
    assert len(tracks) >= 3, "меньше трёх дорожек: на широком экране при коротком списке виден пробел"
    assert tracks[0].strip() == "" and all('aria-hidden="true"' in t for t in tracks[1:]), tracks


# ---------------------------------------------------------------- макет ---

def test_landing_twins_are_identical():
    """3/4/Template.html ↔ 3/4/Header.html: ядро пересобирает части из
    Template.html при расхождении md5."""
    t = read(os.path.join(LANDING, "Template.html"))
    h = read(os.path.join(LANDING, "Header.html"))
    mh = re.search(r"<!-- Header -->(.*?)<!-- /Header -->", t, re.S)
    assert mh and mh.group(1) == h, "секция Header лендинга разошлась с Header.html"


def test_no_inline_styles_in_public_layout():
    for rel in ("Template.html", "Header.html", "Footer.html", os.path.join("4", "Template.html"),
                os.path.join("13", "Template.html")):
        src = read(os.path.join(TPL, rel))
        assert "<style" not in src, rel + ": <style> в макете"
        for m in re.finditer(r'style="([^"]*)"', src):
            assert m.group(1).startswith("width:"), rel + ": инлайновый стиль " + m.group(0)


def test_hero_images_are_responsive_and_logos_sized():
    """У картинок героя <picture> с разными размерами под брейкпоинты — атрибуты
    width/height зафиксировали бы самый большой (575px на телефоне поверх
    текста). У логотипов источник один — размеры нужны против скачка макета."""
    src = read(os.path.join(LANDING, "Template.html"))
    imgs = re.findall(r"<img[^>]*hero__(?:round-img|img)[^>]*>", src)
    assert len(imgs) == 4, imgs
    for tag in imgs:
        assert 'width="' not in tag, "фиксированный размер у адаптивной картинки: " + tag
    header = re.sub(r"<!--.*?-->", "", read(os.path.join(TPL, "Header.html")), flags=re.S)   # без закомментированного
    logos = re.findall(r"<img[^>]*logo[^>]*>", header)
    assert logos, "логотип в шапке не найден"
    for tag in logos:
        assert 'width="' in tag and 'height="' in tag, "логотип без размеров: " + tag


def test_hero_uses_layer_motion_not_bundle_appearance():
    src = read(os.path.join(LANDING, "Template.html"))
    hero = src.split('<section class="hero">', 1)[1].split('<div class="hero__banner">', 1)[0]
    assert "data-sh-lines" in hero, "заголовок героя без построчного входа"
    assert "appearance" not in hero, "текст героя всё ещё на .appearance бандла (двойная анимация)"
    assert hero.count('data-sh-reveal="up"') >= 2


def test_layer_has_header_footer_menu_rules():
    css = read(os.path.join(WEBROOT, "css", "one.css"))
    for needle, why in (
        (".header.is-scrolled", "нет липкого состояния шапки"),
        ("position: sticky", "шапка не липкая"),
        (".sh-marquee", "нет ленты городов"),
        ("@keyframes sh-marquee", "лента не движется"),
        (".hystmodal--active .modal__main-list", "нет ступенчатого входа пунктов меню"),
        (".footer::before", "нет золотой линии подвала"),
        (".modal--video .lite-youtube", "фон видео-модалки не перенесён из инлайнового стиля"),
    ):
        assert needle in css, why
    # reduced-motion: лента и меню тоже движение
    blocks = re.findall(r"@media \(prefers-reduced-motion: reduce\)\s*\{(.*?)\n\}", css, re.S)
    assert any(".sh-marquee__track" in b and "animation: none" in b for b in blocks), \
        "лента не останавливается при reduced-motion"


def test_layer_waits_for_curtain():
    """Первый заход: прелоадер бандла закрывает страницу ~1 с; вход героя,
    запущенный под занавесом, никто не увидит."""
    js = read(os.path.join(WEBROOT, "js", "one.js"))
    assert "preloader--hide" in js and "MutationObserver" in js, "one.js не ждёт прелоадер"


def test_public_pages_render_and_lint():
    for path in ("/", "/my/registration/", "/news/"):
        html = urllib.request.urlopen(BASE + path, timeout=60).read().decode("utf-8", "replace")
        for mark in ("Fatal error", "Parse error", "Uncaught", "Warning: "):
            assert mark not in html, path + ": " + mark
    for rel in ("Template.html", "Header.html", "Footer.html", "4/Template.html", "4/Header.html"):
        r = web("php", "-l", "/var/www/html/netcat_template/template/3/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel + ": " + (r.stdout or r.stderr)


def test_landing_helper_is_cached_per_request_and_safe():
    """Числа и города считает api/landing_lib.php: один запрос на страницу,
    города экранированы (значение поля — пользовательский ввод)."""
    src = read(os.path.join(WEBROOT, "api", "landing_lib.php"))
    assert "function landing_stats" in src and "function landing_cities" in src
    assert "static" in src, "нет кэша на запрос"
    tpl = read(os.path.join(LANDING, "Template.html"))
    assert "htmlspecialchars" in tpl or "shiduh_e(" in tpl, "города печатаются без экранирования"
