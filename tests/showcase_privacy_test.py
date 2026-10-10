# -*- coding: utf-8 -*-
"""
Тесты приватности витрины анкет (ROADMAP B-1, модель определена заказчиком).

  /my/candidate/candidate_<id>.html — карточка витрины (шаблон class/204).

Правила:
  * кандидат (не владелец, не шадхан) видит ЧУЖУЮ карточку только если
    анкета открытая (Open=1), и БЕЗ идентифицирующего: ФИО, еврейское имя,
    контакты раввина/синагоги, фото скрыты; пол/возраст/город/образование
    видны;
  * закрытая чужая анкета кандидату недоступна целиком;
  * шадханы и владелец видят как раньше (открытые — с именем; ведущий — всё).

    python -m pytest tests/showcase_privacy_test.py -q
"""
import os
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login
from fake_llm import backup_row, restore_row  # noqa: E402

CANDIDATE = "u091ff2b36c83@example.local"   # User 50 — посторонний кандидат
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету X
OWNER = "u051e2e9933c7@example.local"       # User 1640 — владелец X

X = 2033
CARD = "/my/candidate/candidate_%d.html" % X

NAME_SENT = "Витрина-ФИО-88231"
JEWISH_SENT = "Витрина-Еврейское-88232"
RAVIN_SENT = "Витрина-Раввин-Контакт-88233"
EMAIL_SENT = "vitrina-leak-88234@sent.local"
PHONE_SENT = "+70009998877"
EN_CARD = "/en/my/candidate/candidate_%d.html" % X

FIELDS = ["Open", "Checked", "Name", "Jewish_name", "Your_ravin_contact",
          "Email", "Phone", "Shadhan_1", "Shadhan_2", "Shadhan_3", "Gender"]
BACKUP = {}


def sql_str(s):
    return str(s).replace("\\", "\\\\").replace("'", "''")


def set_fields(**kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % sql_str(v)))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), X))


def get_page(path, sid, lang="Russian"):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=%s" % (sid, lang))
    opener = urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(req, timeout=180) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def setup_module(module):
    # одно поле с разделителем 0x1f (не таб): mysql -B иначе рвал бы значения
    # с табами/переносами по колонкам и teardown записал бы их не в те поля
    BACKUP.update(backup_row("Message204", X, FIELDS))
    set_fields(Open=1, Checked=1, Name=NAME_SENT, Jewish_name=JEWISH_SENT,
               Your_ravin_contact=RAVIN_SENT, Email=EMAIL_SENT, Phone=PHONE_SENT,
               Shadhan_1=57, Shadhan_2=None, Shadhan_3=None, Gender=1)


def teardown_module(module):
    restore_row("Message204", X, BACKUP)


def test_candidate_sees_open_without_identity():
    code, html = get_page(CARD, login(CANDIDATE))
    assert code == 200, "открытая карточка не открылась кандидату: %d" % code
    assert NAME_SENT not in html, "ФИО видно постороннему кандидату"
    assert JEWISH_SENT not in html, "еврейское имя видно постороннему"
    assert RAVIN_SENT not in html, "контакт раввина виден постороннему"
    assert "lk-questionnaire__body-img" not in html, "фото видно постороннему"
    # общие поля остаются: карточка не должна стать пустой
    assert "Пол:" in html, "общие поля пропали из обезличенной карточки"


def test_candidate_blocked_on_closed():
    set_fields(Open=None)
    try:
        code, html = get_page(CARD, login(CANDIDATE))
        assert code == 200, "страница закрытой анкеты упала: %d" % code
        assert "Анкета закрыта" in html, "нет заглушки закрытой анкеты"
        assert NAME_SENT not in html, "ФИО в закрытой анкете"
        assert "Пол:" not in html, "поля закрытой анкеты видны постороннему"
    finally:
        set_fields(Open=1)


def test_shadhan_sees_open_fully():
    code, html = get_page(CARD, login(SHADHAN))
    assert code == 200
    assert NAME_SENT in html, "шадхан не видит имени открытой анкеты"
    assert RAVIN_SENT in html, "ведущий шадхан не видит контакт раввина"


def test_leading_shadhan_sees_closed_fully():
    # ведущий шадхан видит и закрытую анкету — «по-прежнему полностью»
    set_fields(Open=None)
    try:
        code, html = get_page(CARD, login(SHADHAN))
        assert code == 200
        assert NAME_SENT in html, "ведущий шадхан не видит закрытую анкету"
    finally:
        set_fields(Open=1)


def test_name_filter_is_shadhan_only():
    # фильтр по ФИО работал оракулом: совпало/не совпало раскрывает скрытое
    # Name посимвольно, в т.ч. у закрытых анкет (ревью, блокер Б-1)
    def enc(s):
        return urllib.parse.quote(s, safe="")
    sid = login(CANDIDATE)
    base = "/my/candidate/?srchPat%%5B%%5D=&id_candidate=%d&name=" % X
    code, hit = get_page(base + enc("%"), sid)
    assert code == 200
    # у кандидата фильтр по имени игнорируется -> анкета X (её id задан) видна
    # независимо от name; а «выдуманное» имя не должно её отфильтровать
    code, miss = get_page(base + enc("НетТакогоИмени999"), sid)
    assert code == 200
    assert ("candidate_%d" % X in hit) == ("candidate_%d" % X in miss), \
        "фильтр по ФИО раскрывает имя постороннему кандидату (оракул)"
    # а шадхану поиск по имени по-прежнему работает
    ssid = login(SHADHAN)
    _, s_hit = get_page(
        "/my/candidate/?srchPat%%5B%%5D=&name=" + enc(NAME_SENT[:6]), ssid)
    _, s_miss = get_page(
        "/my/candidate/?srchPat%%5B%%5D=&name=" + enc("ЗаведомоНет777"), ssid)
    assert s_hit != s_miss, "у шадхана поиск по имени перестал работать"


def test_write_button_hidden_from_stranger():
    # кнопка «Написать» ведёт на экран, печатающий Name адресата — обходя
    # обезличивание (ревью, блокер Б-2)
    code, html = get_page(CARD, login(CANDIDATE))
    assert code == 200
    assert "body-write" not in html, "постороннему видна кнопка «Написать»"
    # шадхану кнопка остаётся
    code, html = get_page(CARD, login(SHADHAN))
    assert "body-write" in html, "шадхан потерял кнопку «Написать»"


def test_list_avatars_are_placeholders_for_candidate():
    # фото идентифицирует («Фото — уточнить» в модели заказчика) — в списке
    # витрины постороннему кандидату показываются только плейсхолдеры
    # результаты рендерятся только при заданном srchPat (см. Class.html)
    code, html = get_page("/my/candidate/?srchPat%5B%5D=", login(CANDIDATE))
    assert code == 200, "список витрины не открылся: %d" % code
    # проверяем именно data-src аватарных <img>, а не совпадения по всей
    # странице (в aside/меню есть свои /images/avatar/ — маскировали бы утечку)
    import re
    srcs = re.findall(r'table-cell-picture-img[^>]*data-src="([^"]*)"', html)
    assert srcs, "в списке нет ни одной строки с аватаром"
    leaked = [s for s in srcs if "netcat_files" in s]
    assert not leaked, "у постороннего в списке настоящие фото анкет: %r" % leaked[:3]


def test_list_shows_name_instead_of_id():
    """Приёмка 2026-10, решение заказчика 2026-10-09: в списке поиска вместо
    «ID N» — ФИО (и у закрытой анкеты), плюс «открыта / закрыта». Фото и
    контакты в списке по-прежнему скрыты (тест выше)."""
    url = "/my/candidate/?srchPat%%5B%%5D=&id_candidate=%d" % X
    code, html = get_page(url, login(CANDIDATE))
    assert code == 200, code
    assert NAME_SENT in html, "в списке нет ФИО кандидата"
    assert "открыта" in html
    set_fields(Open=None)
    try:
        code, html = get_page(url, login(CANDIDATE))
        assert NAME_SENT in html, "у закрытой анкеты в списке нет ФИО"
        assert "закрыта" in html and "шадхану кандидата" in html, "нет пометки и подсказки про закрытую анкету"
        has_shadhan = dbq("SELECT (IFNULL(Shadhan_1,0)+IFNULL(Shadhan_2,0)+IFNULL(Shadhan_3,0))>0 "
                          "OR (SELECT Type FROM User WHERE User_ID=m.User_ID)=2 FROM Message204 m "
                          "WHERE Message_ID=%d" % X) == "1"
        assert ("js-interest" in html) == has_shadhan, "кнопка «Мне интересно» не по правилу"
    finally:
        set_fields(Open=1)


def test_owner_not_on_showcase_at_all():
    # витрина фильтрует собственные анкеты на уровне выборки (свою человек
    # смотрит в кабинете) — фиксация фактического поведения: 404, не утечка
    code, _ = get_page(CARD, login(OWNER))
    assert code == 404, "витрина отдала владельцу его же карточку: %d" % code


def test_en_showcase_anonymized():
    # EN-копия (шаблон 238): на ней закрывалась специфичная дыра — Email/Phone
    # показывались ЛЮБОМУ при непустом поле
    code, html = get_page(EN_CARD, login(CANDIDATE), lang="English")
    assert code == 200, "EN-карточка не открылась кандидату: %d" % code
    assert NAME_SENT not in html, "EN: ФИО видно постороннему"
    assert EMAIL_SENT not in html, "EN: email видно постороннему (дыра 238)"
    assert PHONE_SENT not in html, "EN: телефон видно постороннему (дыра 238)"
    assert RAVIN_SENT not in html, "EN: контакт раввина виден постороннему"
    # шадхан на EN видит контакты
    code, html = get_page(EN_CARD, login(SHADHAN), lang="English")
    assert code == 200
    assert EMAIL_SENT in html, "EN: ведущий шадхан не видит email"
