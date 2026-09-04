# -*- coding: utf-8 -*-
"""
A6 этапа «До идеала»: раскладка страницы консультанта.

Чат — то, ради чего человек пришёл; опрос и пары — рядом, но не перед ним.
На широких экранах чат занимает левую колонку во всю высоту, опрос и пары —
правую; на узких чат идёт первым, остальное — под ним.

Заодно закрываем пробел, найденный при вопросе «а приходит ли в чат письмо
шадхана?»: не приходит и не может — это другой раздел. Но человек сидит
здесь, а письмо лежит там. Полоса «N новых сообщений от шадхана» под шапкой
чата ведёт в раздел сообщений; счётчик тот же, что в меню кабинета.

Инлайновых <style> и style="" в шаблоне больше нет — кроме ширины полосы
прогресса, которая есть данные, а не оформление.

    python -m pytest tests/consultant_layout_test.py -q
"""
import os
import re
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import dbq, get_text, login  # noqa: E402

CANDIDATE = "u091ff2b36c83@example.local"   # User 50 — проверенная анкета, есть опрос
PAGE = "/my/ai-consultant/"
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
TEMPLATE = os.path.join(ROOT, "shiduh.org", "netcat_template", "class", "323", "FormPrefix.html")

_SID = {}
_MSG = {}
MARK = "Тест A6 — письмо-фикстура 60606"


def sid():
    if "s" not in _SID:
        _SID["s"] = login(CANDIDATE)
    return _SID["s"]


def uid():
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE))


def page():
    code, html = get_text(PAGE, sid())
    assert code == 200, code
    return html


def teardown_module(module):
    # только своё письмо — по теме-маркеру, никогда по id из MAX()
    dbq("DELETE FROM `Message6` WHERE Subject='%s'" % MARK)
    # настоящие непрочитанные письма пользователя возвращаем в непрочитанные
    if _MSG.get("was_unread"):
        dbq("UPDATE `Message6` SET Status=0 WHERE Message_ID IN (%s)" % _MSG["was_unread"])


# ------------------------------------------------------------- раскладка ---

def test_chat_in_main_column_survey_and_pairs_in_side():
    html = page()
    main = html.find('class="ai-page__main"')
    side = html.find('class="ai-page__side"')
    assert main > 0 and side > 0, "нет колонок раскладки"
    assert main < side, "чат должен идти первым — на узком экране это порядок блоков"
    chat = html.find('id="shChat"')
    survey = html.find('id="aiSurvey"')
    mine = html.find('id="lkMine"')
    assert chat > 0, "на странице нет чата"
    assert survey > 0, "нет блока опроса: у фикстуры должны быть активные сценарии"
    assert mine > 0, "нет каркаса пар: у фикстуры должна быть проверенная анкета"
    assert main < chat < side, "чат не в основной колонке"
    assert survey > side, "опрос не в боковой колонке"
    assert mine > side, "пары не в боковой колонке"


def test_layer_defines_two_column_grid_on_wide_screens():
    src = open(os.path.join(ROOT, "shiduh.org", "css", "one.css"), encoding="utf-8").read()
    m = re.search(r"@media \(min-width: ?1280px\)\s*\{[^@]*?\.ai-page\s*\{([^}]*)\}", src, re.S)
    assert m, "нет правила двух колонок для .ai-page на широких экранах"
    assert "grid-template-columns" in m.group(1), "раскладка не сеткой: " + m.group(1)


def test_template_has_no_inline_styles():
    """Оформление живёт в слое, а не в шаблоне: инлайновые стили ломали
    единый вид и не подчинялись токенам. Исключение — ширина полосы
    прогресса: это данные."""
    src = open(TEMPLATE, encoding="utf-8").read()
    assert "<style" not in src, "в шаблоне остался <style>"
    inline = [m for m in re.findall(r'style="([^"]*)"', src) if not m.startswith("width:")]
    assert inline == [], "инлайновые стили в шаблоне: %s" % inline


def test_claim_form_keeps_its_hooks():
    """Форма «закрепить аккаунт» переехала на классы и в consultant.js —
    её id держат invite_test и сам скрипт."""
    src = open(TEMPLATE, encoding="utf-8").read()
    for hook in ('id="aiClaimForm"', 'id="aiClaimEmail"', 'id="aiClaimPass"', 'id="aiClaimMsg"'):
        assert hook in src, "потерян хук формы закрепления: " + hook
    js = open(os.path.join(ROOT, "shiduh.org", "js", "consultant.js"), encoding="utf-8").read()
    assert "aiClaimForm" in js and "invite_claim.php" in js, "обработчик формы не переехал в consultant.js"


# ------------------------------------------- письмо шадхана — в другом разделе ---

def test_no_unread_strip_without_messages():
    # запоминаем настоящие непрочитанные, чтобы teardown вернул их как были:
    # тест правит живые письма dev-БД и не имеет права оставлять след
    _MSG["was_unread"] = dbq("SELECT IFNULL(GROUP_CONCAT(Message_ID), '') FROM `Message6` "
                             "WHERE ToUser=%d AND Status=0" % uid())
    dbq("UPDATE `Message6` SET Status=1 WHERE ToUser=%d AND Status=0" % uid())
    html = page()
    assert "sh-chat__unread" not in html, "полоса о новых сообщениях показана без них"


def test_unread_strip_points_to_messages():
    sub = dbq("SELECT Sub_Class_ID, Subdivision_ID FROM `Sub_Class` WHERE Class_ID=6 "
              "AND Catalogue_ID=1 ORDER BY Sub_Class_ID LIMIT 1")
    if not sub:
        pytest.skip("на стенде нет раздела сообщений")
    sub_class, subdivision = [int(x) for x in sub.split("\t")]
    # Строгий sql_mode: все NOT NULL-колонки без дефолта задаём явно. Своё
    # письмо находим по уникальной теме, а не по MAX(id): при неудавшемся
    # INSERT MAX(id) указал бы на чужое настоящее письмо, и teardown удалил
    # бы его.
    dbq("INSERT INTO `Message6` (User_ID, Subdivision_ID, Sub_Class_ID, Keyword, Checked, "
        "Created, LastUser_ID, ToUser, Status, Subject, Message) VALUES (57, %d, %d, '', 1, "
        "NOW(), 57, %d, 0, '%s', 'письмо шадхана 60606')" % (subdivision, sub_class, uid(), MARK))
    found = dbq("SELECT Message_ID FROM `Message6` WHERE Subject='%s' AND ToUser=%d" % (MARK, uid()))
    assert found, "письмо-фикстура не записалось"
    _MSG["id"] = int(found)
    html = page()
    assert "sh-chat__unread" in html, "нет полосы о новом сообщении шадхана"
    assert 'href="/my/message/"' in html, "полоса не ведёт в раздел сообщений"
    strip = html[html.find("sh-chat__unread"):html.find("</a>", html.find("sh-chat__unread"))]
    assert "1 новое сообщение" in strip, "счётчик или падеж не те: " + strip[:200]
    # текст письма в чат не попадает — это другой раздел
    assert "60606" not in html, "текст письма шадхана утёк на страницу консультанта"
