# -*- coding: utf-8 -*-
"""
A4 этапа «До идеала»: витрина кандидатов и «Мои кандидаты» — страницы,
возраст в SQL, владелец через JOIN.

Что было: список тянул все анкеты без LIMIT (~1200 строк), возраст
отсеивался в PHP после выборки, «владелец проверен» — get_by_id на каждой
строке (до семи запросов), аватар — оригинал фото. Постраничность ядра при
этом была невозможна.

    python -m pytest tests/showcase_pagination_test.py -q
"""
import datetime
import io
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, backup_row, dbq, login, restore_row, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
C204 = os.path.join(WEBROOT, "netcat_template", "class", "204")
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50
LIST = "/my/candidate/?srchPat%5B%5D="
MINE = "/my/my-candidates/"
PER_PAGE = 24
MIN_AGE = 16   # AUTH_MIN_AGE в api/auth_rules_lib.php
# условия, которые ядро добавляет к списку инфоблока 22 (раздел 23) само
CORE_WHERE = "a.Checked=1 AND a.Subdivision_ID=23 AND a.Sub_Class_ID=22 AND a.Parent_Message_ID=0"

_SID = {}
_ROW = {}


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


def rows(html):
    return re.findall(r'<div class="lk-search-candidate-result__table-row"', html)


def row_ids(html):
    """ID анкет из строк таблицы (не «ID 57» из сайдбара пользователя)."""
    body = html.split('lk-search-candidate-result__table-body', 1)[-1]
    return [int(x) for x in re.findall(r'</div>\s*ID (\d+)\s*</div>', body)]


def no_php_errors(html, where):
    for mark in ("Fatal error", "Warning: ", "Notice: ", "Uncaught"):
        assert mark not in html, where + ": " + mark


def teardown_module(module):
    if _ROW:
        restore_row("Message204", _ROW["id"], _ROW["backup"])


# --------------------------------------------------------------- витрина ---

def test_first_page_is_limited_and_counted():
    code, html = page(LIST, sid_of(SHADHAN))
    assert code == 200
    no_php_errors(html, LIST)
    n = len(rows(html))
    assert 0 < n <= PER_PAGE, "на странице %d строк" % n
    # «из N» — столько, сколько отдаёт SQL с теми же условиями
    total = int(dbq("SELECT COUNT(*) FROM `Message204` a INNER JOIN `User` u ON u.User_ID=a.User_ID AND u.Checked=1 "
                    "WHERE " + CORE_WHERE + " AND a.Height<>'' AND a.Phone<>'' "
                    "AND a.Date_of_birth IS NOT NULL AND a.Date_of_birth NOT LIKE '0000%%' "
                    "AND TIMESTAMPDIFF(YEAR, a.Date_of_birth, CURDATE()) BETWEEN %d AND 120" % MIN_AGE))
    m = re.search(r'sh-pager__state">(\d+)–(\d+) из (\d+)<', html)
    assert m, "нет пейджера"
    assert int(m.group(3)) == total, "«из N» %s не равно COUNT %d" % (m.group(3), total)
    assert (int(m.group(1)), int(m.group(2))) == (1, min(PER_PAGE, total))
    assert 'rel="next"' in html and 'href=' in html.split('sh-pager', 1)[1]
    # кнопка бандла «Показать ещё» остаётся в разметке: его скрипт падает без неё
    assert 'class="lk-search-candidate-result__table-load-more-btn"' in html


def test_second_page_has_other_rows():
    code, html1 = page(LIST, sid_of(SHADHAN))
    m = re.search(r'rel="next" href="([^"]+)"', html1)
    assert m, "нет ссылки на следующую страницу"
    href = m.group(1).replace("&amp;", "&")
    href = href if href.startswith("/") else "/" + href.split("//", 1)[-1].split("/", 1)[1]
    code, html2 = page(href, sid_of(SHADHAN))
    assert code == 200, href
    no_php_errors(html2, href)
    ids1, ids2 = set(row_ids(html1)), set(row_ids(html2))
    assert ids2 and not (ids1 & ids2), "страницы пересекаются: %s" % (ids1 & ids2)
    assert 'rel="prev" href=' in html2
    assert re.search(r'sh-pager__state">%d–' % (PER_PAGE + 1), html2)


def test_minor_and_default_dates_excluded():
    """На день младше порога и «дата по умолчанию» не попадают на витрину; ровно порог — да."""
    aid = int(dbq("SELECT a.Message_ID FROM `Message204` a INNER JOIN `User` u ON u.User_ID=a.User_ID AND u.Checked=1 "
                  "WHERE " + CORE_WHERE + " AND a.Height<>'' AND a.Phone<>'' "
                  "AND TIMESTAMPDIFF(YEAR, a.Date_of_birth, CURDATE()) BETWEEN 25 AND 40 ORDER BY a.Message_ID LIMIT 1"))
    # LastUpdated тоже: UPDATE двигает его (ON UPDATE CURRENT_TIMESTAMP)
    backup = backup_row("Message204", aid, ["Date_of_birth", "LastUpdated"])
    _ROW["id"], _ROW["backup"] = aid, backup
    today = datetime.date.today()
    if today.month == 2 and today.day == 29:
        today = today.replace(day=28)

    def set_dob(d):
        dbq("UPDATE `Message204` SET Date_of_birth='%s 00:00:00' WHERE Message_ID=%d" % (d, aid))

    def listed():
        code, html = page(LIST + "&id_candidate=%d" % aid, sid_of(SHADHAN))
        return aid in row_ids(html)

    assert listed(), "контрольная анкета не видна до правки"
    set_dob(today.replace(year=today.year - MIN_AGE) + datetime.timedelta(days=1))
    assert not listed(), "на день младше порога — на витрине"
    set_dob("2024-01-01")
    assert not listed(), "дата по умолчанию 2024 — на витрине"
    set_dob(today.replace(year=today.year - MIN_AGE))
    assert listed(), "ровно порог — не на витрине"
    restore_row("Message204", aid, _ROW["backup"])
    _ROW.clear()


def test_age_filter_applies_in_sql():
    code, html = page(LIST + "&age%5B0%5D=30&age%5B1%5D=35", sid_of(SHADHAN))
    ids = row_ids(html)
    assert ids, "пустая выдача по возрасту 30–35"
    ages = dbq("SELECT TIMESTAMPDIFF(YEAR, Date_of_birth, CURDATE()) FROM `Message204` WHERE Message_ID IN (%s)"
               % ",".join(str(i) for i in ids)).split()
    assert all(30 <= int(a) <= 35 for a in ages), ages
    # нижняя граница не опускается ниже совершеннолетия
    code, html = page(LIST + "&age%5B0%5D=5&age%5B1%5D=17", sid_of(SHADHAN))
    assert not row_ids(html), "фильтр 5–17 отдал анкеты"


def test_owner_unchecked_excluded_by_join():
    """«Владелец проверен» — в SQL, а не get_by_id на строке."""
    src = read(os.path.join(C204, "Settings.html"))
    assert "showcase_owner_join()" in src and "$recNum = SHOWCASE_PER_PAGE" in src
    rt = read(os.path.join(C204, "RecordTemplate.html"))
    assert "->get_by_id(" not in rt, "в строке остались запросы get_by_id"
    assert "get_age(" not in rt


def test_avatars_thumbnails_and_privacy():
    code, html = page(LIST, sid_of(SHADHAN))
    srcs = re.findall(r'table-cell-picture-img[^>]*data-src="([^"]*)"', html)
    assert srcs
    assert all("/generated/" in s or "/images/avatar/" in s for s in srcs), "аватар не миниатюра: %s" % [s for s in srcs if "generated" not in s][:2]
    assert re.search(r'table-cell-picture-img[^>]*width="45" height="45"', html)
    # постороннему кандидату — только плейсхолдеры (B-1)
    code, html = page(LIST, sid_of(CANDIDATE))
    assert code == 200
    srcs = re.findall(r'table-cell-picture-img[^>]*data-src="([^"]*)"', html)
    assert srcs and all("netcat_files" not in s for s in srcs)


# ------------------------------------------------------- мои кандидаты ---

def test_my_candidates_paged_and_counted():
    code, html = page(MINE, sid_of(SHADHAN))
    assert code == 200
    no_php_errors(html, MINE)
    n = len(re.findall(r'class="lk-candidates__item lk-sort-item', html))
    assert 0 < n <= PER_PAGE
    # у инфоблока 28 SrcMirror=22: ядро подставляет условия витрины (раздел 23, инфоблок 22)
    total = int(dbq("SELECT COUNT(*) FROM `Message204` a INNER JOIN `User` u ON u.User_ID=a.User_ID AND u.Checked=1 "
                    "WHERE " + CORE_WHERE + " AND (a.User_ID=57 OR a.Shadhan_1=57 OR a.Shadhan_2=57 OR a.Shadhan_3=57)"))
    m = re.search(r'sh-pager__state">(\d+)–(\d+) из (\d+)<', html)
    if total > PER_PAGE:
        assert m and int(m.group(3)) == total, "«из N» не равно COUNT %d: %s" % (total, m and m.group(0))
    else:
        assert not m and n == total
    assert 'class="lk-load-more__btn"' in html
    assert 'style="' not in html.split('class="lk-candidates"', 1)[1].split("</section>", 1)[0], "инлайновые стили в строках"
    assert "generated/" in html or "/images/avatar/" in html


def test_my_candidates_keep_bad_dates_visible():
    """Без фильтра возраста шадхан видит и анкеты с битой датой — иначе их не починить."""
    src = read(os.path.join(C204, "213", "Settings.html"))
    assert "showcase_age_bounds(isset($_GET['age']) ? $_GET['age'] : null, false)" in src
    rt = read(os.path.join(C204, "213", "RecordTemplate.html"))
    assert "->get_by_id(" not in rt and "<!--<div" not in rt


def test_class_html_matches_parts():
    for d in (C204, os.path.join(C204, "213")):
        cls = read(os.path.join(d, "Class.html"))
        for name in ("FormPrefix", "FormSuffix"):
            body = read(os.path.join(d, name + ".html"))
            m = re.search(r"<!-- %s -->(.*?)<!-- /%s -->" % (name, name), cls, re.S)
            assert m and m.group(1) == body, "%s/%s разошёлся с Class.html" % (d, name)
        rt = read(os.path.join(d, "RecordTemplate.html"))
        inner = rt[rt.index("/* Конец служебной части */?>") + len("/* Конец служебной части */?>"):rt.rindex("<?/* Служебная часть */")]
        m = re.search(r"<!-- RecordTemplate -->(.*?)<!-- /RecordTemplate -->", cls, re.S)
        assert m and m.group(1).strip() == inner.strip(), d + "/RecordTemplate разошёлся с Class.html"
    for rel in ("Settings.html", "RecordTemplate.html", "FormSuffix.html", "213/Settings.html", "213/RecordTemplate.html", "213/FormSuffix.html", "213/FormPrefix.html"):
        r = web("php", "-l", "/var/www/html/netcat_template/class/204/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
    r = web("php", "-l", "/var/www/html/api/showcase_lib.php")
    assert "No syntax errors" in (r.stdout or "")


def test_age_helper_unit():
    code = (
        "require '/var/www/html/api/showcase_lib.php';"
        "echo json_encode(array(showcase_age_bounds(null, true), showcase_age_bounds(array('5','15'), true),"
        " showcase_age_bounds(array('30','35'), true), showcase_age_bounds(null, false), showcase_age_bounds(array('40',''), false),"
        " showcase_age_bounds(array('x','999'), true), showcase_age_text('0000-00-00 00:00:00'), showcase_age_text('1990-06-15 00:00:00') !== ''));"
    )
    import json
    r = web("php", "-r", code)
    js = json.loads((r.stdout or "").strip().splitlines()[-1])
    assert js[0] == [MIN_AGE, 120] and js[1] == [MIN_AGE, MIN_AGE - 1] and js[2] == [30, 35]   # 5–15 → пустой диапазон
    assert js[3] is None and js[4] == [40, 120] and js[5] == [MIN_AGE, 120]
    assert js[6] == "" and js[7] is True
