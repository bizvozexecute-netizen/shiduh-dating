# -*- coding: utf-8 -*-
"""
A5 этапа «До идеала»: карточки кандидата — у шадхана (204/213) и на витрине (204).

Что было: фото оригиналом в липкой колонке, два <style>-блока и три
инлайновых <script> в шаблоне (корень блока — document.currentScript),
39 инлайновых стилей, 14 get_by_id в блоке «Шадханы», три одинаковых
запроса списка шадханов в админ-форме, заглушка закрытой анкеты — голый
<p style>.

Теперь: шапка-герой (миниатюра 320×400, возраст/город, статусы пилюлями,
действия), подменю разделов из секций data-sh-sec (lk-card.js, scroll-spy),
стили — в слое, скрипты — во внешнем файле с явными корнями, пустое
состояние — .sh-empty. Хуки js-* и тексты разделов сохранены (их держат
соседние наборы).

    python -m pytest tests/card_layout_test.py -q
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
C204 = os.path.join(WEBROOT, "netcat_template", "class", "204")
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def page(path, sid):
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.build_opener(_NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def read(path):
    return io.open(path, encoding="utf-8", errors="replace").read()


def card_id():
    return int(dbq("SELECT MIN(Message_ID) FROM `Message204` WHERE Shadhan_1=57 AND Checked=1"))


def open_card_id():
    """Открытая анкета чужого кандидата с фото — для витрины."""
    return int(dbq("SELECT a.Message_ID FROM `Message204` a INNER JOIN `User` u ON u.User_ID=a.User_ID AND u.Checked=1 "
                   "WHERE a.Checked=1 AND a.Open=1 AND a.User_ID<>50 AND IFNULL(a.Shadhan_1,0)<>50 "
                   "AND a.Photo_avatar<>'' AND a.Name<>'' ORDER BY a.Message_ID LIMIT 1"))


# --------------------------------------------------------------- шадхан ---

def test_shadhan_card_hero_and_sections():
    code, html = page("/my/my-candidates/moi-kandidaty_%d.html" % card_id(), sid_of(SHADHAN))
    assert code == 200
    for mark in ("Fatal error", "Warning: ", "Notice: "):
        assert mark not in html, mark
    assert 'class="sh-card-hero"' in html, "нет шапки-героя"
    assert 'data-sh-subnav' in html, "нет подменю"
    secs = re.findall(r'data-sh-sec="([^"]+)"', html)
    assert "Анкета" in secs and "Предпочтения" in secs and "Резюме от ИИ" in secs and "Рекомендации ИИ" in secs, secs
    assert len(secs) == len(set(secs)), "дубли разделов: %s" % secs
    ids = re.findall(r'id="(sec-[a-z]+)"', html)
    assert len(ids) == len(set(ids)) == len(secs)
    # хуки соседних наборов на месте
    for hook in ("js-ai-summary-refresh", "js-match-load", "js-invite-create", "Психологический портрет"):
        assert hook in html, hook
    assert 'data-sh-match' in html and 'data-sh-invite' in html, "корни блоков для lk-card.js"
    assert "/js/lk-card.js?v=" in html
    assert "<style>" not in html.split('class="lk-questionnaire', 1)[1], "<style> в карточке"
    card = html.split('class="lk-questionnaire lk-questionnaire--candidate"', 1)[1].split("lk-questionnaire__goback", 1)[0]
    assert "document.currentScript" not in card
    # width: — ширины полос (данные); object-fit / align-items — вложенные
    # формы и списки других компонентов (nc_objects_list 221/255/299/312), не
    # карточка. Сам шаблон карточки без style= держит test_layer_has_card_rules.
    inline = [m for m in re.findall(r'style="([^"]*)"', card)
              if not (m.startswith("width:") or m.startswith("object-fit:") or m.startswith("align-items:"))]
    assert inline == [], "инлайновые стили в карточке: %s" % inline[:5]
    assert "lk-questionnaire__body-picture" not in html, "старая липкая колонка фото осталась"


def test_shadhan_card_avatar_is_thumbnail():
    aid = card_id()
    photo = dbq("SELECT IFNULL(Photo_avatar,'') FROM `Message204` WHERE Message_ID=%d" % aid)
    code, html = page("/my/my-candidates/moi-kandidaty_%d.html" % aid, sid_of(SHADHAN))
    m = re.search(r'sh-card-hero__img[^>]*data-src="([^"]*)"', html)
    if photo not in ("", "NULL"):
        assert m and "/generated/204/" in m.group(1) and "320x400" in m.group(1), m and m.group(1)
        assert 'width="320" height="400"' in html
    else:
        assert "sh-card-hero__img--empty" in html


def test_shadhans_block_without_per_row_queries():
    src = read(os.path.join(C204, "213", "RecordTemplateFull.html"))
    assert src.count("->get_by_id(") == 0, "остались get_by_id в карточке 213"
    assert "lk_user_brief(" in src
    src204 = read(os.path.join(C204, "RecordTemplateFull.html"))
    assert src204.count("->get_by_id(") == 0
    assert src204.count("SELECT * FROM User WHERE Type = 2") == 1, "список шадханов админ-формы запрашивается не один раз"


def test_card_script_served_and_has_hooks():
    r = urllib.request.urlopen(BASE + "/js/lk-card.js", timeout=60)
    js = r.read().decode("utf-8", "replace")
    assert r.status == 200 and len(js) < 20 * 1024
    for hook in ("[data-sh-match]", "[data-sh-invite]", ".js-ai-summary-refresh", "/api/match_list.php",
                 "/api/match_feedback.php", "/api/match_explain.php", "/api/invite_create.php",
                 "/api/invite_send_email.php", "/api/summary_generate.php", "[data-sh-subnav]", "IntersectionObserver"):
        assert hook in js, hook
    assert not re.search(r"\.style\.color\s*=", js), "цвета статусов — классами, не style.color"
    assert "currentScript" not in js


# --------------------------------------------------------------- витрина ---

def test_showcase_card_hero_for_shadhan_and_anon():
    aid = open_card_id()
    code, html = page("/my/candidate/candidate_%d.html" % aid, sid_of(SHADHAN))
    assert code == 200
    assert 'class="sh-card-hero"' in html
    assert "lk-questionnaire__body-img" in html and "320x400" in html, "шадхан не видит фото в шапке"
    assert "body-write" in html
    # посторонний кандидат: без имени, без фото (плейсхолдер), без «Написать»
    code, html = page("/my/candidate/candidate_%d.html" % aid, sid_of(CANDIDATE))
    assert code == 200
    assert 'class="sh-card-hero"' in html
    assert "lk-questionnaire__body-img" not in html, "постороннему показано фото"
    assert "sh-card-hero__img--empty" in html
    assert "body-write" not in html
    # имя — в заголовке шапки; в остальной странице то же слово может быть
    # именем шадхана или самого зрителя (обезличивание берёт имена из одного пула)
    name = dbq("SELECT Name FROM `Message204` WHERE Message_ID=%d" % aid)
    hero = html.split("sh-card-hero__title", 1)[1].split("</h1>", 1)[0]
    assert name not in hero, "постороннему видно имя"
    for mark in ("Fatal error", "Warning: ", "Notice: "):
        assert mark not in html, mark


def test_closed_card_uses_empty_state():
    closed = dbq("SELECT a.Message_ID FROM `Message204` a INNER JOIN `User` u ON u.User_ID=a.User_ID AND u.Checked=1 "
                 "WHERE a.Checked=1 AND a.Open=0 AND a.User_ID<>50 AND IFNULL(a.Shadhan_1,0)<>50 ORDER BY a.Message_ID LIMIT 1")
    if not closed:
        import pytest
        pytest.skip("нет закрытой анкеты")
    code, html = page("/my/candidate/candidate_%s.html" % closed, sid_of(CANDIDATE))
    assert code == 200
    assert "sh-empty" in html and "Анкета закрыта" in html
    assert "Пол:" not in html


def test_layer_has_card_rules_and_no_style_left():
    css = read(os.path.join(WEBROOT, "css", "one.css"))
    for needle in (".sh-card-hero", ".sh-subnav__link.is-active", ".lk-match__rec", ".sh-alert", ".lk-risk"):
        assert needle in css, needle
    for rel in ("213/RecordTemplateFull.html", "RecordTemplateFull.html"):
        src = read(os.path.join(C204, rel))
        assert "<style>" not in src, rel
        assert "<script>" not in src, rel + ": инлайновый <script>"
        r = web("php", "-l", "/var/www/html/netcat_template/class/204/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
