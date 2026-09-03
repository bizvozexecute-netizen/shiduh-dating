# -*- coding: utf-8 -*-
"""
C2 этапа «До идеала»: SQL-инъекции, reflected XSS, Referer в href, мёртвые компоненты.

Перенос девяти проверок из tests/security_test.py (скрипт остаётся для ручного
запуска) + пробы по ВСЕМ копиям уязвимых шаблонов, которые AUDIT №1 закрыл только
в 204/Settings.html и 204/238/Settings.html. Критерий один: ввод трактуется как
данные — 200 и столько же строк, сколько при заведомо «пустом» значении.
Сломанный инъекцией запрос даёт 302/ошибку или полную выдачу.

    python -m pytest tests/injection_sweep_test.py -q
"""
import io
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, login  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")

SHADHAN51 = "ue4bcee4af47d@example.local"    # User_ID 51
GRANTEE43 = "ue3fd6199f1a0@example.local"    # User_ID 43
OWN_FAV_CANDIDATE = 1191                     # в избранном у 51
FOREIGN_CANDIDATE = 33                       # чужая анкета: у 51 нет доступа
GRANTED_CANDIDATE = 255                      # выдана 43 через Message255

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def get(path, sid=None, referer=None):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    if referer:
        req.add_header("Referer", referer)
    try:
        r = urllib.request.build_opener(_NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def read(rel):
    return io.open(os.path.join(WEBROOT, rel), encoding="utf-8", errors="replace").read()


def candidate_ids(html):
    return {n for pref, n in re.findall(r"([a-zA-Z-]+)_(\d+)\.html", html) if pref != "shadhan"}


def q(s):
    return urllib.parse.quote(s, safe="")


# ------------------------------------------------- перенос security_test.py ---

def test_id_candidate_search_works():
    code, html = get("/my/candidate/?srchPat=&id_candidate=%d" % FOREIGN_CANDIDATE, sid_of(SHADHAN51))
    assert code == 200 and str(FOREIGN_CANDIDATE) in candidate_ids(html)


def test_id_candidate_injection_treated_as_data():
    code, html = get("/my/candidate/?srchPat=&id_candidate=" + q("0 OR 1=1"), sid_of(SHADHAN51))
    assert code == 200 and len(candidate_ids(html)) <= 1


def test_country_injection_treated_as_data():
    code, html = get("/my/candidate/?srchPat=&country[]=" + q("1) OR (1=1"), sid_of(SHADHAN51))
    assert code == 200


@pytest.mark.parametrize("prefix", ["", "/en"])
def test_foreign_card_not_opened(prefix):
    code, html = get("%s/my/favourites/ankety-kandidatov_%d.html" % (prefix, FOREIGN_CANDIDATE),
                     sid_of(SHADHAN51))
    assert not (code == 200 and "lk-questionnaire--candidate" in html)


def test_grantee_sees_granted_card():
    code, html = get("/my/favourites/ankety-kandidatov_%d.html" % GRANTED_CANDIDATE, sid_of(GRANTEE43))
    assert code == 200 and "lk-questionnaire--candidate" in html


def test_own_favourite_still_opens():
    code, html = get("/my/favourites/ankety-kandidatov_%d.html" % OWN_FAV_CANDIDATE, sid_of(SHADHAN51))
    assert code == 200 and "lk-questionnaire--candidate" in html


def test_search_by_gender_works():
    code, html = get("/my/candidate/?srchPat=&gender=2", sid_of(SHADHAN51))
    assert code == 200 and candidate_ids(html)


def test_height_filter_works():
    code, html = get("/my/candidate/?srchPat=&height_min=170&height_max=180", sid_of(SHADHAN51))
    assert code == 200 and candidate_ids(html)


# ------------------------------- копии Settings.html 204-семейства (шадханские) ---
#
# (url, параметр-инъекция, параметр-«пустое значение»). Строк с инъекцией должно
# быть ровно столько, сколько с заведомо несуществующим значением.
INJ_ID = "id_candidate=" + q("0 OR 1=1")
INJ_NAME = "name=" + q("' OR '1'='1")
INJ_LIST = q("1) OR (1=1")
LIST_PROBES = [
    ("/my/my-candidates/",        INJ_ID,                              "id_candidate=999999999"),
    ("/my/my-candidates/",        INJ_NAME,                            "name=zzzzqqqq"),
    ("/my/my-candidates/",        "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/en/my/my-candidates/",     INJ_ID,                              "id_candidate=999999999"),
    ("/en/my/my-candidates/",     INJ_NAME,                            "name=zzzzqqqq"),
    ("/en/my/my-candidates/",     "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/my/sozdanie-shiduha/",     "gender=" + q("0 OR 1=1"),           "gender=999"),
    ("/my/sozdanie-shiduha/",     INJ_ID,                              "id_candidate=999999999"),
    ("/my/sozdanie-shiduha/",     INJ_NAME,                            "name=zzzzqqqq"),
    ("/my/sozdanie-shiduha/",     "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/my/poisk-shiduha/",        "gender=" + q("0 OR 1=1"),           "gender=999"),
    ("/my/poisk-shiduha/",        INJ_ID,                              "id_candidate=999999999"),
    ("/my/poisk-shiduha/",        INJ_NAME,                            "name=zzzzqqqq"),
    ("/my/poisk-shiduha/",        "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/my/poisk-shiduha/",        "Education[]=" + INJ_LIST,           "Education[]=999999"),
    ("/my/poisk-shiduha/",        "Belonging_denomination[]=" + INJ_LIST, "Belonging_denomination[]=999999"),
    ("/my/access-candidates/",    "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/en/my/access-candidates/", "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/my/favourites/",           "country[]=" + INJ_LIST,             "country[]=999999"),
    ("/en/my/favourites/",        "country[]=" + INJ_LIST,             "country[]=999999"),
]


@pytest.mark.parametrize("url,inj,benign", LIST_PROBES,
                         ids=["%s?%s" % (p[0], p[1][:22]) for p in LIST_PROBES])
def test_list_filters_treat_injection_as_data(url, inj, benign):
    sid = sid_of(SHADHAN51)
    code_b, html_b = get(url + "?srchPat=&search=1&" + benign, sid)
    code_i, html_i = get(url + "?srchPat=&search=1&" + inj, sid)
    assert code_b == 200, "опорный запрос упал: %d" % code_b
    assert code_i == 200, "инъекция сломала запрос (код %d)" % code_i
    assert candidate_ids(html_i) == candidate_ids(html_b), (
        "инъекция дала другую выдачу: %d строк против %d"
        % (len(candidate_ids(html_i)), len(candidate_ids(html_b))))


# ------------------------------- публичные разделы (183): ?autor= и ?user= ---

PUBLIC_183 = ["/news/", "/en/news/", "/events/", "/hupot/"]
PRIVATE_183 = ["/my/blog/", "/en/my/blog/", "/my/events/", "/my/hupot/"]


def item_links(html):
    return re.findall(r'href="(/[a-z/]*(?:news|events|hupot|blog)/[^"]+\.html)"', html)


@pytest.mark.parametrize("url", PUBLIC_183 + PRIVATE_183)
@pytest.mark.parametrize("param", ["autor", "user"])
def test_author_filter_treats_injection_as_data(url, param):
    sid = sid_of(SHADHAN51) if url.startswith(("/my", "/en/my")) else None
    code_b, html_b = get("%s?%s=999999999" % (url, param), sid)
    code_i, html_i = get("%s?%s=%s" % (url, param, q("0 OR 1=1")), sid)
    assert code_b == 200 and code_i == 200, (code_b, code_i)
    for html in (html_b, html_i):
        assert "You have an error in your SQL" not in html and "MySQL" not in html, "SQL-ошибка ушла в ответ"
    assert len(item_links(html_i)) == len(item_links(html_b)), (
        "инъекция дала другую выдачу: %d ссылок против %d"
        % (len(item_links(html_i)), len(item_links(html_b))))


# ------------------------------- reflected XSS через srchPat[2]/[12] (183, 194) ---

# /en/my/blog/ (шаблон 183/236) исключён: там нет формы поиска — только
# Settings.html, srchPat не печатается вовсе (SQL-фикс ему всё равно нужен).
XSS_PAGES = [u for u in PUBLIC_183 + PRIVATE_183 if u != "/en/my/blog/"] + ["/video/", "/en/video/"]


@pytest.mark.parametrize("url", XSS_PAGES)
def test_srchpat_is_not_reflected_raw(url):
    """srchPat печатался внутри value="…" сырым — выход из атрибута.

    Проверяем именно выход (`"><svg`), а не наличие «<svg» или «onload=alert»:
    первое есть на каждой странице легитимно (иконки-спрайты), второе остаётся
    подстрокой уже экранированного значения (htmlspecialchars не трогает «=»).
    Заодно требуем, чтобы экранированный вид присутствовал — иначе тест
    прошёл бы вхолостую на странице, где значение вообще не печатается.
    """
    sid = sid_of(SHADHAN51) if url.startswith(("/my", "/en/my")) else None
    payload = q('"><svg onload=alert(1)>')
    code, html = get("%s?srchPat[2]=%s&srchPat[12]=%s" % (url, payload, payload), sid)
    assert code == 200, code
    for breakout in ('"><svg', "'><svg", '"><SVG'):
        assert breakout not in html, "srchPat отражён в HTML без экранирования: " + breakout
    assert "&quot;&gt;&lt;svg" in html, "значение не дошло до страницы — тест бессмысленен"


# ------------------------------------------------- Referer в href «Назад» ---

# Карточки, которые у шадхана 51 реально открываются (200) и печатают «Назад».
# Чужая анкета 33 закрыта guard'ом, а /my/my-candidates/ уводит редиректом.
REFERER_PAGES = [
    "/my/candidate/candidate_%d.html" % OWN_FAV_CANDIDATE,
    "/my/favourites/ankety-kandidatov_%d.html" % OWN_FAV_CANDIDATE,
    "/en/my/favourites/ankety-kandidatov_%d.html" % OWN_FAV_CANDIDATE,
]


@pytest.mark.parametrize("url", REFERER_PAGES)
def test_referer_is_not_reflected_into_href(url):
    sid = sid_of(SHADHAN51)
    evil = 'https://evil.example/"onmouseover="alert(1)'
    code, html = get(url, sid, referer=evil)
    assert code == 200, code
    assert "evil.example" not in html and "onmouseover" not in html, "чужой Referer попал в href"
    code, html = get(url, sid, referer=BASE + "/my/candidate/?x=1&y=2")
    assert ('href="/my/candidate/?x=1&amp;y=2"' in html or 'href="/my/candidate/?x=1&y=2"' in html), \
        "свой Referer должен превращаться в относительный путь"


# ------------------------------------------------- статика: паттерны в шаблонах ---

def settings_files():
    out = []
    for cls in ("204", "183", "194"):
        base = os.path.join(WEBROOT, "netcat_template", "class", cls)
        for dirpath, dirs, files in os.walk(base):
            for n in files:
                if n == "Settings.html":
                    out.append(os.path.relpath(os.path.join(dirpath, n), WEBROOT))
    return sorted(out)


def test_settings_templates_cast_all_get_input():
    """Каждое присваивание из $_GET в Settings.html — с (int)/intval/prepare."""
    bad = []
    for rel in settings_files():
        for i, line in enumerate(read(rel).split("\n"), 1):
            if "$_GET[" not in line:
                continue
            if (re.search(r"=\s*\$_GET\[|implode\([^)]*\$_GET\[", line)
                    and not re.search(r"\(int\)|intval|->prepare\(|is_numeric", line)):
                bad.append("%s:%d" % (rel, i))
    assert not bad, "сырой $_GET в SQL-фильтре: " + ", ".join(bad)


def test_settings_312_has_no_register_globals_reads():
    """Шаблон 312 читал $ID/$gender/$country… как «глобалы» из GET (эмуляция
    register_globals в NetCat) — живая инъекция, замаскированная под мёртвый код."""
    src = read(os.path.join("netcat_template", "class", "204", "312", "Settings.html"))
    assert not re.search(r"if\s*\(\s*\$ID\b", src) and "$gender = $gender" not in src, \
        "312/Settings.html всё ещё читает необъявленные глобалы"


def test_dead_chat_components_removed():
    for cls in ("292", "293", "251"):
        assert not os.path.isdir(os.path.join(WEBROOT, "netcat_template", "class", cls)), \
            "мёртвый компонент %s (open redirect в 293/CheckActionTemplate) не удалён" % cls


def test_check_action_template_has_no_undefined_userdate():
    src = read(os.path.join("netcat_template", "class", "204", "CheckActionTemplate.html"))
    assert "userDate" not in src, "Message6.Created пишется из неопределённой $userDate (AUDIT №12)"


# ------------------------------------------- находки adversarial-ревью C2 ---

def test_login_form_requested_from_does_not_reflect_referer():
    """Скрытое поле REQUESTED_FROM формы входа (макет + module/auth): если
    REQUEST_URI содержит «netcat/modules/auth» (а это и query-string!), в
    value подставлялся сырой HTTP_REFERER — без авторизации, на любой странице."""
    evil = "http://localhost:8080/x'\"><script>alert(1)</script>"
    code, html = get("/?q=netcat/modules/auth", referer=evil)
    assert code == 200, code
    assert "<script>alert(1)</script>" not in html, "Referer отражён в REQUESTED_FROM без экранирования"
    assert "REQUESTED_FROM" in html, "форма входа не отрендерилась — тест бессмысленен"


@pytest.mark.parametrize("ref_path", ["//evil.com/x", "/" + chr(92) + "evil.com/x", "//evil.com"])
def test_back_url_rejects_protocol_relative_paths(ref_path):
    r"""Referer своего хоста, но путь начинается с // или /\ — браузер трактует
    это как внешний адрес (protocol-relative). Ссылка «Назад» не должна вести
    на evil.com; ожидаем дефолт раздела."""
    sid = sid_of(SHADHAN51)
    code, html = get("/my/candidate/candidate_%d.html" % OWN_FAV_CANDIDATE, sid, referer=BASE + ref_path)
    assert code == 200, code
    assert "evil.com" not in html, "protocol-relative путь из Referer попал в href"
