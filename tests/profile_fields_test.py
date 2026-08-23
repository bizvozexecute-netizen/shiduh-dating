# -*- coding: utf-8 -*-
"""
Тесты вывода новых полей анкеты (миграция 006, встреча с шадханами) в
карточке кандидата у шадхана — Э0.1-h / отображение.

7 обычных полей (спорт, жильё, языки, диаспора, соблюдение, раввинат,
интроверт/экстраверт) выводятся как строки анкеты при заполнении.
3 чувствительных (доход, здоровье, медикаменты) — отдельным блоком,
видимым только шадхану (Type_id=2). Пусто — не выводится.

    python -m pytest tests/profile_fields_test.py -q
"""
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
SHADHAN = "u3f9bb8c20b01@example.local"     # User_ID 57
CARD_ANKETA = 2033                          # Shadhan_1=57, Checked=1
CARD_URL = "/my/my-candidates/moi-kandidaty_%d.html" % CARD_ANKETA

NORMAL_FIELDS = [
    "Sport_attitude", "Housing_after_marriage", "Languages_spoken",
    "Diaspora_openness", "Observance_growth", "Rabbinate_registration",
    "Introvert_extrovert",
]
SENSITIVE_FIELDS = ["Income_property", "Health_conditions", "Medications"]
ALL_FIELDS = NORMAL_FIELDS + SENSITIVE_FIELDS


def dbq(sql):
    r = subprocess.run(
        [DOCKER, "compose", "exec", "-T", "db", "mysql", "-uroot", "-proot_local_dev",
         "-N", "-B", "--default-character-set=utf8", "shiduh_org", "-e", sql],
        cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=60,
    )
    return (r.stdout or "").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def login(email):
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0", "AUTH_USER": email, "AUTH_PW": PASSWORD,
    }).encode()
    req = urllib.request.Request(BASE + "/netcat/modules/auth/", data=body, method="POST")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        headers = opener.open(req, timeout=60).headers
    except urllib.error.HTTPError as e:
        headers = e.headers
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("не удалось авторизоваться: " + email)


def get_card(sid):
    req = urllib.request.Request(BASE + CARD_URL)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def set_field(name, value):
    if value is None:
        dbq("UPDATE Message204 SET `%s`=NULL WHERE Message_ID=%d" % (name, CARD_ANKETA))
    else:
        esc = value.replace("\\", "\\\\").replace("'", "''")
        dbq("UPDATE Message204 SET `%s`='%s' WHERE Message_ID=%d" % (name, esc, CARD_ANKETA))


def clear_all():
    dbq("UPDATE Message204 SET " + ", ".join("`%s`=NULL" % f for f in ALL_FIELDS)
        + " WHERE Message_ID=%d" % CARD_ANKETA)


def teardown_module(module):
    clear_all()


def test_card_opens_clean():
    clear_all()
    _, html = get_card(login(SHADHAN))
    assert "Fatal error" not in html and "Parse error" not in html


def test_normal_field_shown_when_filled():
    clear_all()
    set_field("Languages_spoken", "иврит, русский, английский маркер71828")
    _, html = get_card(login(SHADHAN))
    assert "иврит, русский, английский маркер71828" in html, "язык не отрисован"
    assert "Языки общения" in html, "нет подписи поля языков"


def test_all_normal_fields_render():
    clear_all()
    for i, f in enumerate(NORMAL_FIELDS):
        set_field(f, "значение_%s_%d" % (f, i))
    _, html = get_card(login(SHADHAN))
    for i, f in enumerate(NORMAL_FIELDS):
        assert ("значение_%s_%d" % (f, i)) in html, "не выведено поле " + f


def _search_columns():
    out = dbq("SELECT COLUMN_NAME FROM information_schema.columns WHERE "
              "table_schema=DATABASE() AND table_name='Message204' "
              "AND COLUMN_NAME LIKE 'Search\\\\_%'")
    return [c for c in out.split() if c]


def test_normal_fields_independent_of_preferences():
    # поля кандидата — своя секция, НЕ внутри «Предпочтений»: должны
    # показываться, даже если ни одно предпочтение (Search_*) не заполнено
    clear_all()
    cols = _search_columns()
    assert cols, "не нашли Search_* колонки"
    sel = ", ".join("IFNULL(`%s`,'')" % c for c in cols)
    snapshot = dbq("SELECT %s FROM Message204 WHERE Message_ID=%d" % (sel, CARD_ANKETA)).split("\t")
    try:
        dbq("UPDATE Message204 SET " + ", ".join("`%s`=NULL" % c for c in cols)
            + " WHERE Message_ID=%d" % CARD_ANKETA)
        set_field("Languages_spoken", "иврит русский маркер55555")
        _, html = get_card(login(SHADHAN))
        assert "иврит русский маркер55555" in html, \
            "поле кандидата скрыто, когда не заполнены предпочтения (регресс гейта секции)"
    finally:
        for c, v in zip(cols, snapshot):
            esc = v.replace("\\", "\\\\").replace("'", "''")
            dbq("UPDATE Message204 SET `%s`='%s' WHERE Message_ID=%d" % (c, esc, CARD_ANKETA))
        clear_all()


def test_empty_fields_not_rendered():
    clear_all()
    _, html = get_card(login(SHADHAN))
    # пустые поля не создают подписей
    for label in ("Языки общения", "Спорт", "Жильё после брака"):
        assert label not in html, "пустое поле показало подпись: " + label


def test_sensitive_field_shown_to_shadhan_with_marker():
    clear_all()
    set_field("Health_conditions", "хроническое состояние маркер31415")
    _, html = get_card(login(SHADHAN))
    assert "хроническое состояние маркер31415" in html, "чувствительное поле не показано шадхану"
    # блок помечен как для шадхана / чувствительный
    assert "олько для шадхана" in html or "увствительн" in html, \
        "нет пометки о чувствительности/видимости только шадхану"


def test_sensitive_value_html_escaped():
    clear_all()
    set_field("Medications", "<script>alert(1)</script> мед27182")
    _, html = get_card(login(SHADHAN))
    assert "<script>alert(1)</script>" not in html, "XSS: сырой payload в чувствительном поле"
    assert "мед27182" in html


# --- форма анкеты (/my/modify) ---------------------------------------------

CANDIDATE = "u091ff2b36c83@example.local"    # User 50, владеет Checked=1 анкетой 42
MODIFY_URL = "/my/modify/"


def get_modify(sid):
    req = urllib.request.Request(BASE + MODIFY_URL)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def test_modify_form_has_all_new_inputs():
    code, html = get_modify(login(CANDIDATE))
    assert code == 200
    assert "Fatal error" not in html and "Parse error" not in html
    for f in ALL_FIELDS:
        assert ('name="f_%s"' % f) in html, "нет поля в форме анкеты: " + f


def test_modify_form_sensitive_optional_and_private():
    _, html = get_modify(login(CANDIDATE))
    # чувствительные поля подаются мягко: по желанию + видно только шадхану
    assert "олько шадхан" in html or "идно только шадхан" in html, \
        "нет пометки приватности у чувствительных полей формы"
    assert "о желанию" in html, "нет пометки необязательности"
