# -*- coding: utf-8 -*-
"""
A8 этапа «До идеала»: доступность экранов.

Что было (замер в браузере до правок): на главной 39 кнопок и ссылок без
доступного имени (бургер, логотипы, иконки соцсетей, стрелки слайдера,
крестики модалок, картинки-ссылки новостей) и 7 полей без подписи — экранный
диктор читал их как «ссылка», «поле ввода». На регистрации и у консультанта
не было <h1>.

Теперь: у элементов-иконок aria-label, у полей без видимой подписи —
aria-label из подсказки, картинка-ссылка новости скрыта от диктора (рядом
такая же ссылка-заголовок), заголовки страниц — <h1>.

Осталось известное: ссылку студии-разработчика в подвал вставляет бандл
(в наших шаблонах её нет) — единственные два элемента без имени на главной.

    python -m pytest tests/a11y_test.py -q
"""
import io
import os
import re
import sys
import urllib.error
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, NoRedirect, login  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
TPL = os.path.join(WEBROOT, "netcat_template")
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def page(path, email=None):
    req = urllib.request.Request(BASE + path)
    if email:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid_of(email))
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def read(*parts):
    return io.open(os.path.join(*parts), encoding="utf-8", errors="replace").read()


# --------------------------------------------------------------- главная ---

def test_landing_icon_controls_have_names():
    code, html = page("/")
    assert code == 200
    for label in ('aria-label="Открыть меню"', 'aria-label="One Soul — на главную"',
                  'aria-label="One Soul в YouTube"', 'aria-label="One Soul в Instagram"',
                  'aria-label="One Soul в Telegram"', 'aria-label="Назад"', 'aria-label="Дальше"',
                  'aria-label="Закрыть"'):
        assert label in html, "нет подписи: " + label
    # крестик модалки и стрелки слайдера — кнопки, а не «отправить форму»
    assert '<button class="modal__close" type="button"' in html or 'class="modal__close" data-hystclose type="button"' in html


def test_landing_modal_forms_have_field_names():
    _, html = page("/")
    for label in ('aria-label="Ваше имя"', 'aria-label="Ваш e-mail"', 'aria-label="Ваш телефон"',
                  'aria-label="Дополнительная информация"'):
        assert label in html, "поле без подписи: " + label


def test_news_image_link_is_hidden_from_screen_reader():
    """Картинка-ссылка дублирует ссылку-заголовок рядом — второй раз её читать незачем."""
    _, html = page("/")
    heads = re.findall(r'<a class="news-item__head"[^>]*>', html)
    assert heads, "на главной нет блока новостей"
    for tag in heads:
        assert 'aria-hidden="true"' in tag and 'tabindex="-1"' in tag, tag


# --------------------------------------------------------------- заголовки -

@pytest.mark.parametrize("path,email,needle", [
    ("/", None, "<h1"),
    ("/my/registration/", None, '<h1 class="page-head__title">Регистрация</h1>'),
    ("/my/ai-consultant/", CANDIDATE, '<h1 class="sh-chat__title">Ваш ИИ-консультант</h1>'),
])
def test_page_has_main_heading(path, email, needle):
    code, html = page(path, email)
    assert code == 200, "%s: %d" % (path, code)
    assert needle in html, path + ": нет заголовка страницы"
    assert html.count("<h1") >= 1


# --------------------------------------------------------------- формы -----

def test_showcase_filter_fields_have_names():
    _, html = page("/my/candidate/", SHADHAN)
    for label in ('aria-label="ID анкеты"', 'aria-label="Ф.И.О."', 'aria-label="Возраст от"',
                  'aria-label="Возраст до"', 'aria-label="Город"', 'aria-label="Рост до"'):
        assert label in html, "фильтр витрины без подписи: " + label


def test_wizard_family_fields_have_names():
    _, html = page("/my/modify/", CANDIDATE)
    assert html.count('aria-label="Введите имя') >= 20, "у полей состава семьи нет подписей"
    for label in ('aria-label="Введите имя Папы"', 'aria-label="Введите имя Мамы"'):
        assert label in html, label


# --------------------------------------------------------------- шаблоны ---

def test_twins_kept_in_sync():
    """Правки доступности внесены и в Header/Footer, и в склейку Template.html."""
    for t in ("3", "3/4", "3/13"):
        d = os.path.join(TPL, "template", *t.split("/"))
        tpl = read(d, "Template.html")
        for name in ("Header.html", "Footer.html"):
            p = os.path.join(d, name)
            if not os.path.exists(p):
                continue
            body = read(p).replace("%Header", "").replace("%Footer", "").strip()
            assert body in tpl, "template/%s: %s разошёлся с Template.html" % (t, name)
    # у Class.html компонентов новостей — та же разметка, что в частях
    for sub in ("184", "185", "186", "187"):
        d = os.path.join(TPL, "class", "183", sub)
        rt = read(d, "RecordTemplate.html")
        cls_path = os.path.join(d, "Class.html")
        if not os.path.exists(cls_path):
            continue
        n_rt = rt.count('news-item__head" tabindex="-1" aria-hidden="true"')
        n_cls = read(cls_path).count('news-item__head" tabindex="-1" aria-hidden="true"')
        assert n_rt == n_cls == 1, "183/%s: разметка новости разошлась (%d/%d)" % (sub, n_rt, n_cls)


def test_no_inline_styles_left_in_touched_templates():
    """Наши правки не тащат style= — политика слоя.

    Исключение то же, что в design_layer_test: style="width:…" — это данные
    (ширина полосы прогресса), а не оформление."""
    for rel in ("template/3/Header.html", "template/3/Footer.html", "template/3/13/Header.html",
                "class/323/FormPrefix.html"):
        src = read(TPL, *rel.split("/")).replace('style="width:', "")
        assert 'style="' not in src, rel + ": инлайновый стиль"
