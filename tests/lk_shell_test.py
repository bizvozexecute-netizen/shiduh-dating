# -*- coding: utf-8 -*-
"""
A3 этапа «До идеала»: оболочка кабинета и сообщения.

Что было: меню кабинета жило двумя копиями по 25 пунктов (сайдбар и
мобильное меню) и уже разошлось; аватары отдавались оригиналом; мобильная
шапка бандла имела width:100vw и давала 15px горизонтальной прокрутки;
список сообщений печатал <div href>, шесть инлайновых стилей на строку,
четыре запроса get_by_id на строку и HTML чужого письма как разметку;
у администратора (34 тысячи писем) список без LIMIT падал по памяти.

    python -m pytest tests/lk_shell_test.py -q
"""
import io
import os
import re
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, dbq, login, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
PARTIALS = os.path.join(WEBROOT, "netcat_template", "template", "3", "partials")
MSG = os.path.join(WEBROOT, "netcat_template", "class", "netcat_module_auth_message")

CANDIDATE = "u091ff2b36c83@example.local"   # User 50
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
ADMIN = "u5760fc1c6357@example.local"       # User 1
MARK = "lk-shell-test-mark-70707"
XSS = '<img src=x onerror="alert(70707)">'

_SID = {}
_SEEDED = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def read(path):
    return io.open(path, encoding="utf-8", errors="replace").read()


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def page(path, sid):
    """Без следования редиректам: кандидата с неполной анкетой макет уводит
    на /my/modify (по https) — это отдельное поведение, а не «страница»."""
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.build_opener(_NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def teardown_module(module):
    dbq("DELETE FROM `Message6` WHERE Subject='%s'" % MARK)


def seed_letter():
    """Письмо кандидата (50) шадхану (57) с HTML внутри — по маркеру, не по
    MAX(id). Получатель — шадхан: кандидата с неполной анкетой макет уводит
    с любой страницы кабинета на /my/modify."""
    if "id" in _SEEDED:
        return _SEEDED["id"]
    sub = dbq("SELECT Sub_Class_ID, Subdivision_ID FROM `Sub_Class` WHERE Class_ID=6 AND Catalogue_ID=1 ORDER BY Sub_Class_ID LIMIT 1")
    sub_class, subdivision = [int(x) for x in sub.split("\t")]
    text = ("привет " + XSS + " " + MARK).replace("'", "''")
    dbq("INSERT INTO `Message6` (User_ID, Subdivision_ID, Sub_Class_ID, Keyword, Checked, Created, LastUser_ID, "
        "ToUser, Status, Subject, Message) VALUES (50, %d, %d, '', 1, NOW(), 50, 57, 0, '%s', '%s')"
        % (subdivision, sub_class, MARK, text))
    _SEEDED["id"] = int(dbq("SELECT Message_ID FROM `Message6` WHERE Subject='%s'" % MARK))
    return _SEEDED["id"]


# ------------------------------------------------------------------ меню ---

def test_menu_lives_in_one_partial():
    body = read(os.path.join(PARTIALS, "lk-aside-body.html"))
    assert body.count('class="lk-aside__nav-list"') == 1
    for name in ("aside_profile.html", "lk-menu-mobile.html"):
        src = read(os.path.join(PARTIALS, name))
        assert "$this->partial('lk-aside-body'" in src, name + " не подключает общий партиал меню"
        assert "lk-aside__nav-list" not in src, name + ": своя копия списка"
        assert 'style="' not in src, name + ": инлайновый стиль"
    assert 'style="' not in body


def test_menu_rendered_twice_from_one_source_and_hooks_kept():
    code, html = page("/my/ai-consultant/", sid_of(CANDIDATE))
    assert code == 200
    assert html.count('class="lk-aside__nav-list"') == 2, "сайдбар и мобильное меню — два списка на странице"
    assert "lk-aside__nav" in html                         # держит ai_chat_test
    assert 'href="/my/ai-consultant/"' in html
    assert html.count('aria-current="page"') == 0          # активна не «Главная»
    assert 'lk-aside__nav-item--active' in html
    for mark in ("Fatal error", "Warning: ", "Notice: "):
        assert mark not in html


def test_admin_link_only_for_admin():
    code, html = page("/my/message/", sid_of(ADMIN))
    assert re.search(r'class="lk-aside__nav-item lk-aside__nav-item--admin">\s*<a class="lk-aside__nav-link" href="([^"]+)"', html), "у администратора нет ссылки в консоль"
    code, html = page("/my/message/", sid_of(SHADHAN))
    assert "lk-aside__nav-item--admin" not in html


def test_unread_counter_hidden_when_zero():
    sid = sid_of(SHADHAN)
    uid = 57
    was = dbq("SELECT IFNULL(GROUP_CONCAT(Message_ID), '') FROM `Message6` WHERE ToUser=%d AND Status=0" % uid)
    dbq("UPDATE `Message6` SET Status=1 WHERE ToUser=%d AND Status=0" % uid)
    try:
        code, html = page("/my/", sid)
        m = re.search(r'<span class="lk-aside__nav-link-counter([^"]*)"[^>]*>(\d+)</span>', html)
        assert m and "--hidden" in m.group(1) and m.group(2) == "0", "«0» в кружке счётчика показан: %s" % (m and m.group(0))
    finally:
        if was:
            dbq("UPDATE `Message6` SET Status=0 WHERE Message_ID IN (%s)" % was)


def test_logoff_links_encoded():
    code, html = page("/my/", sid_of(SHADHAN))
    links = re.findall(r'href="(/netcat/modules/auth/\?logoff=1[^"]*)"', html)
    assert links, "нет ссылок выхода"
    for l in links:
        assert "REQUESTED_FROM=%2F" in l and "\n" not in l, l


def test_avatars_are_thumbnails():
    """Аватар в сайдбаре — миниатюра ядра, а не оригинал на несколько МБ."""
    src = dbq("SELECT Photo_avatar FROM `User` WHERE User_ID=57")
    if not src:
        import pytest
        pytest.skip("у шадхана-фикстуры нет фото")
    # в обезличенном комплекте netcat_files файла может не быть, хотя поле в
    # базе заполнено — тогда ядру не из чего резать миниатюру
    if web("test", "-f", "/var/www/html/netcat_files/UserFields/Photo_avatar/" + src.split(":")[0]).returncode != 0:
        import pytest
        pytest.skip("файла аватара нет на стенде (обезличенные данные): " + src.split(":")[0])
    code, html = page("/my/", sid_of(SHADHAN))
    m = re.search(r'lk-aside__avatar-img[^>]*data-src="([^"]*)"', html)
    assert m and "/netcat_files/generated/User/Photo_avatar/290x290/57/" in m.group(1), m and m.group(1)
    url = m.group(1).replace("&amp;", "&")
    req = urllib.request.Request(BASE + url)
    r = urllib.request.urlopen(req, timeout=60)
    assert r.status == 200 and len(r.read()) > 500, "миниатюра не генерируется"


def test_mobile_header_no_100vw():
    css = read(os.path.join(WEBROOT, "css", "one.css"))
    assert "§lk" in css
    assert re.search(r"\.lk-header-mobile\s*\{\s*width:\s*100%", css), "ширина мобильной шапки не исправлена"
    assert ".lk-aside__nav-item--active .lk-aside__nav-link" in css


# ------------------------------------------------------------- сообщения ---

def test_message_list_rows_are_links_and_text_is_text():
    seed_letter()
    code, html = page("/my/message/", sid_of(SHADHAN))
    assert code == 200
    block = html.split('class="lk-messages"', 1)[1].split("</section>", 1)[0]
    assert "<div href=" not in block, "строка письма — div с href"
    assert re.search(r'<article class="lk-messages__item lk-messages__item--active">', block), "непрочитанное не отмечено"
    assert "sh-pill--gold" in block, "нет метки «новое»"
    assert 'style="' not in block, "инлайновые стили в списке"
    assert "<script" not in block, "инлайновый скрипт в списке"
    assert 'onerror="alert(70707)"' not in html, "HTML из письма исполняется в списке"
    assert MARK in block, "текст письма не показан"        # теги в превью срезаны, текст остался
    assert re.search(r'lk-messages__item-name-img[^>]*data-src="(/images/avatar/|/netcat_files/generated/)', block), "нет аватара собеседника"


def test_letter_page_shows_id_and_escapes_html():
    mid = seed_letter()
    keyword = dbq("SELECT Keyword FROM `Message6` WHERE Message_ID=%d" % mid)
    path = "/my/message/%s.html" % (keyword or "soobscheniya_%d" % mid)
    # открытие письма помечает прочитанной всю переписку 50→57 — настоящие
    # непрочитанные письма фикстуры возвращаем как были
    was = dbq("SELECT IFNULL(GROUP_CONCAT(Message_ID), '') FROM `Message6` WHERE ToUser=57 AND User_ID=50 AND Status=0 AND Message_ID<>%d" % mid)
    try:
        code, html = page(path, sid_of(SHADHAN))
    finally:
        if was:
            dbq("UPDATE `Message6` SET Status=0 WHERE Message_ID IN (%s)" % was)
    assert code == 200, path
    assert "Сообщение от кандидата" in html
    assert 'lk-messages-write__head-id">ID 50</span>' in html, "ID собеседника не печатается (раньше <? без =)"
    assert 'onerror="alert(70707)"' not in html, "HTML из письма исполняется в письме"
    assert "&lt;img src=x" in html or "img src=x" not in html
    assert MARK in html, "текст письма не показан в переписке"
    assert 'style="display:inline-block;"' not in html
    assert re.search(r'lk-messages-write__history-item-date" data-time="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d"', html), \
        "data-time не в ISO со смещением — браузер сдвигал бы время на свой пояс"
    # письмо отмечено прочитанным
    assert dbq("SELECT Status FROM `Message6` WHERE Message_ID=%d" % mid) == "1"


def test_outgoing_letter_opens_thread():
    """Переписка, открытая по исходящему письму, была пустой: ветвление шло
    по несуществующему $f_UserID."""
    seed_letter()
    out_mark = MARK + "-out"
    sub = dbq("SELECT Sub_Class_ID, Subdivision_ID FROM `Sub_Class` WHERE Class_ID=6 AND Catalogue_ID=1 ORDER BY Sub_Class_ID LIMIT 1")
    sub_class, subdivision = [int(x) for x in sub.split("\t")]
    dbq("INSERT INTO `Message6` (User_ID, Subdivision_ID, Sub_Class_ID, Keyword, Checked, Created, LastUser_ID, "
        "ToUser, Status, Subject, Message) VALUES (57, %d, %d, '', 1, NOW(), 57, 50, 1, '%s', 'ответ шадхана <br> <a href=\"/my/\">кабинет</a> %s')"
        % (subdivision, sub_class, MARK, out_mark))
    mid = int(dbq("SELECT Message_ID FROM `Message6` WHERE Subject='%s' AND User_ID=57 ORDER BY Message_ID DESC LIMIT 1" % MARK))
    keyword = dbq("SELECT Keyword FROM `Message6` WHERE Message_ID=%d" % mid)
    path = "/my/message/%s.html" % (keyword or "soobscheniya_%d" % mid)
    code, html = page(path, sid_of(SHADHAN))
    assert code == 200, path
    thread = html.split("lk-messages-write__history", 1)[1]
    assert out_mark in thread and MARK in thread, "переписка по исходящему письму пуста"
    assert "&lt;br&gt;" not in thread and "&lt;a href" not in thread, "теги системного письма показаны как текст"
    assert "кабинет (/my/)" in thread, "ссылка не сведена к «текст (адрес)»"
    assert "nc_msg_out" in thread, "исходящее письмо не отмечено как своё"


def test_menu_role_matrix():
    """Самое рискованное в объединении меню — условия пунктов по ролям."""
    cases = {
        # Приёмка 2026-10: «Поиск шидуха» и «Создание шидуха» убраны из меню —
        # их заменил «Подбор пар с ИИ»; история шидухов одна на роль.
        CANDIDATE: {"yes": ["/my/ai-consultant/", "/my/moi-shadhany/", "/my/candidate/", "/my/favourites/",
                            "/my/predlozhennye-kandidaty/"],
                    "no": ["/my/poisk-shiduha/", "/my/sozdanie-shiduha/", "/my/podbor-par/", "/my/istoriya-shiduha/",
                           "/my/my-candidates/", "/my/aktivatsiya-shadhana/", "/my/delete-account/", "/my/add-article/"]},
        SHADHAN: {"yes": ["/my/podbor-par/", "/my/istoriya-shiduha/", "/my/candidate/", "/my/access-candidates/",
                          "/my/my-candidates/", "/my/add_candidate/", "add_soobscheniya.html?uid=119"],
                  "no": ["/my/poisk-shiduha/", "/my/sozdanie-shiduha/", "/my/predlozhennye-kandidaty/",
                         "/my/ai-consultant/", "/my/moi-shadhany/", "/my/aktivatsiya-shadhana/", "/my/delete-account/", "/my/add-article/"]},
        # директор (1) — сам типа «шадхан»: подбор пар ему положен, а «Мои
        # кандидаты» и «Добавить кандидата» скрыты для служебных 1 и 119
        ADMIN: {"yes": ["/my/aktivatsiya-shadhana/", "/my/delete-account/", "/my/add-article/", "/my/podbor-par/"],
                "no": ["/my/my-candidates/", "/my/add_candidate/", "/my/ai-consultant/", "/my/poisk-shiduha/"]},
    }
    for email, exp in cases.items():
        code, html = page("/my/message/", sid_of(email))
        assert code == 200, email
        aside = html.split('<aside class="lk-aside">', 1)[1].split("</aside>", 1)[0]
        for href in exp["yes"]:
            assert href in aside, "%s: нет пункта %s" % (email, href)
        for href in exp["no"]:
            assert href not in aside, "%s: лишний пункт %s" % (email, href)


def test_empty_state_for_user_without_messages():
    # только шадханы: кандидата с неполной анкетой макет уводит на /my/modify
    email = dbq("SELECT Email FROM `User` WHERE Type=2 AND Checked=1 AND Email LIKE 'u%%@example.local' "
                "AND User_ID NOT IN (SELECT ToUser FROM `Message6`) AND User_ID NOT IN (SELECT User_ID FROM `Message6`) "
                "AND User_ID NOT IN (1,119) LIMIT 1")
    if not email:
        import pytest
        pytest.skip("нет пользователя без писем")
    code, html = page("/my/message/", login(email))
    assert code == 200
    assert "sh-empty" in html and "Сообщений пока нет" in html, "нет пустого состояния"


def test_admin_message_list_renders_within_limit():
    """34 тысячи писем администратора: без предела список падал по памяти."""
    code, html = page("/my/message/", sid_of(ADMIN))
    assert code == 200, code
    rows = re.findall(r'<article class="lk-messages__item', html)
    assert 0 < len(rows) <= 1000
    src = read(os.path.join(MSG, "265", "Settings.html"))
    assert "$recNum = 1000;" in src and "a.Created DESC" in src


def test_class_html_twins_match_parts():
    """Class.html = FormPrefix + RecordTemplate + FormSuffix между маркерами
    (ядро сверяет md5 и пересобирает части)."""
    for ctpl in ("265", "266"):
        d = os.path.join(MSG, ctpl)
        cls = read(os.path.join(d, "Class.html"))
        for name in ("FormPrefix", "FormSuffix"):
            body = read(os.path.join(d, name + ".html"))
            m = re.search(r"<!-- %s -->(.*?)<!-- /%s -->" % (name, name), cls, re.S)
            assert m and m.group(1) == body, "%s/%s разошёлся с Class.html" % (ctpl, name)
        # служебную часть ядро может переформатировать (одна строка ↔ много);
        # сравниваем содержимое между её концом и началом
        rt = read(os.path.join(d, "RecordTemplate.html"))
        inner = rt[rt.index("/* Конец служебной части */?>") + len("/* Конец служебной части */?>"):rt.rindex("<?/* Служебная часть */")]
        m = re.search(r"<!-- RecordTemplate -->(.*?)<!-- /RecordTemplate -->", cls, re.S)
        assert m and m.group(1).strip() == inner.strip(), ctpl + "/RecordTemplate разошёлся с Class.html"
    for rel in ("265/FormPrefix.html", "265/RecordTemplate.html", "265/RecordTemplateFull.html", "266/RecordTemplate.html"):
        assert 'style="' not in read(os.path.join(MSG, rel)), rel + ": инлайновый стиль"
        r = web("php", "-l", "/var/www/html/netcat_template/class/netcat_module_auth_message/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel


def test_local_dates_moved_to_layer():
    js = read(os.path.join(WEBROOT, "js", "one.js"))
    assert "localDates" in js and "data-time" in js
    assert "<script" not in read(os.path.join(MSG, "265", "FormSuffix.html"))
