# -*- coding: utf-8 -*-
"""
A7 этапа «До идеала»: мастер анкеты кандидата (209 — добавление шадханом,
206 — правка) и серверная проверка (class/204/{Add,Edit}Cond.html →
api/anketa_validate_lib.php).

Что было: ~100 полей одной страницей с двумя «вкладками»-якорями, единственная
защита — required в HTML и календарь без нижней границы (в базе 224 даты
«по умолчанию» и анкеты моложе 18). Теперь: семь шагов одной формы (один
POST — ядро делает единый INSERT с побочными эффектами), ошибки у полей,
дата рождения type="date" с границами, проверка на сервере в любом случае.

    python -m pytest tests/anketa_wizard_test.py -q
"""
import datetime
import glob
import io
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, NoRedirect, backup_row, dbq, login, restore_row, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
C204 = os.path.join(WEBROOT, "netcat_template", "class", "204")
TPL = os.path.join(WEBROOT, "netcat_template", "template", "3")
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50, владеет анкетой 42
ANKETA = 42
ADD_PAGE = "/my/add_candidate/"
EDIT_PAGE = "/my/modify/"
MARK = "Мастер-Тест-Кандидат"
MARK_EMAIL = "anketa-wizard-test@example.local"
# EditActionTemplate применяет согласие при каждом сохранении: без f_Consent_sensitive
# в POST поле здоровья стирается — бэкапим и его
ROW_FIELDS = ("Name", "Date_of_birth", "City", "Height", "LastUpdated", "Health_status", "Consent_sensitive",
              "Consent_sensitive_at", "Consent_sensitive_version")
SHADHAN_FIELDS = ("Number_of_candidate_add", "Account")   # AddActionTemplate меняет их у добавившего

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def page(path, sid):
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def post(path, fields, sid):
    body = urllib.parse.urlencode(fields, doseq=True).encode()
    req = urllib.request.Request(BASE + path, data=body, method="POST")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    req.add_header("Origin", BASE)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=180)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def read(path):
    return io.open(path, encoding="utf-8", errors="replace").read()


def form_block(html):
    # в кабинете бывает и другой adminForm раньше (списки ядра) — берём форму мастера
    m = re.search(r'<form[^>]*data-sh-wizard.*?</form>', html, re.S)
    assert m, "на странице нет формы мастера"
    return m.group(0)


def service_fields(html):
    """Служебные скрытые поля формы (nc_token — только со страницы)."""
    form = form_block(html)
    fields = {}
    for name, value in re.findall(r"<input[^>]*name='([^']+)'[^>]*value='([^']*)'", form):
        fields[name] = value
    for name, value in re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', form):
        fields[name] = value
    for need in ("nc_token", "catalogue", "cc", "sub", "posting"):
        assert need in fields, "в форме нет %s" % need
    keys = ("admin_mode", "nc_token", "catalogue", "cc", "sub", "message", "posting", "curPos",
            "f_Parent_Message_ID", "partial")
    return {k: fields[k] for k in keys if k in fields}


_TODAY = None


def php_date(expr):
    out = (web("php", "-r", "echo date('Y-m-d', strtotime('%s'));" % expr).stdout or "").strip()
    return out.splitlines()[-1]


def dob_parts(iso):
    y, m, d = iso.split("-")
    return {"f_Date_of_birth": iso, "f_Date_of_birth_day": str(int(d)), "f_Date_of_birth_month": str(int(m)),
            "f_Date_of_birth_year": y, "f_Date_of_birth_hours": "00", "f_Date_of_birth_minutes": "00",
            "f_Date_of_birth_seconds": "00"}


def add_fields(html, **over):
    f = service_fields(html)
    f.update({
        "f_Name": MARK, "f_Gender": "1", "f_Place_of_birth": "Тест-Город", "f_Country": "1",
        "f_City": "Тест-Город", "f_Height": "178", "f_Phone": "+7 900 000-00-01", "f_Email": MARK_EMAIL,
        "f_Jewishness": "1", "f_Origin": "1", "f_Marital_status": "1", "f_Education": "1",
        "f_Specialization": "Тестировщик", "f_Consent_sensitive": "0",
    })
    f.update(dob_parts(php_date("-30 years")))
    for k, v in over.items():
        if v is None:
            f.pop(k, None)
        else:
            f[k] = v
    return f


def anketa_count():
    return int(dbq("SELECT COUNT(*) FROM `Message204`"))


def cleanup_added():
    ids = dbq("SELECT GROUP_CONCAT(Message_ID) FROM `Message204` WHERE Email='%s' OR Name='%s'" % (MARK_EMAIL, MARK))
    for i in [x for x in (ids or "").split(",") if x]:
        # AddActionTemplate рассылает уведомление всем шадханам — убираем и его
        dbq("DELETE FROM `Message6` WHERE Message LIKE '%%candidate_%s.html%%'" % i)
    dbq("DELETE FROM `Message204` WHERE Email='%s' OR Name='%s'" % (MARK_EMAIL, MARK))


def setup_module(module):
    cleanup_added()


def teardown_module(module):
    cleanup_added()


# ------------------------------------------------------------- страницы ---

def _assert_wizard(html, prefix):
    for mark in ("Fatal error", "Warning: ", "Notice: ", "Parse error"):
        assert mark not in html, mark
    form = form_block(html)
    steps = re.findall(r'data-sh-step="(\d+)"', form)
    assert steps == [str(i) for i in range(1, 8)], steps
    assert 'data-sh-wizard' in form and 'data-sh-native' in form, "форма не помечена для мастера"
    assert 'id="%s_preferences"' % prefix in form, "нет якоря предпочтений"
    assert "/js/anketa-wizard.js?v=" in html
    m = re.search(r'<input[^>]*name="f_Date_of_birth"[^>]*>', form)
    assert m and 'type="date"' in m.group(0) and 'min="' in m.group(0) and 'max="' in m.group(0), \
        "дата рождения — не type=date с границами"
    assert "datepicker__el" not in form, "остался календарь бандла"
    skip = re.search(r'data-sh-draft-skip="([^"]*)"', form)
    assert skip, "нет списка полей вне черновика"
    for n in ("Health_status", "Health_conditions", "Medications", "Income_property", "Jewishness", "Belonging_denomination"):
        assert n in skip.group(1).split(","), "черновик не исключает " + n
    # согласие — рядом с полем здоровья (X-5)
    i_health = form.find('name="f_Health_status"')
    i_consent = form.find('name="f_Consent_sensitive"')
    assert 0 < abs(i_health - i_consent) < 4000
    assert 'style="' not in form.replace('style="width:', ''), "инлайновые стили в форме"


def test_add_page_is_wizard():
    code, html = page(ADD_PAGE, sid_of(SHADHAN))
    assert code == 200, code
    _assert_wizard(html, "addcandidate")
    assert "lk-add-candidate__tabs" not in html, "старые вкладки на месте"


def test_edit_page_is_wizard_with_values():
    code, html = page(EDIT_PAGE, sid_of(CANDIDATE))
    assert code == 200, code
    _assert_wizard(html, "profile")
    form = form_block(html)
    assert 'name="f_Open"' in form
    for f in ("Income_property", "Health_conditions", "Medications", "Sport_attitude"):
        assert 'name="f_%s"' % f in form, f
    dob = dbq("SELECT DATE(Date_of_birth) FROM `Message204` WHERE Message_ID=%d" % ANKETA)
    assert 'value="%s"' % dob in form, "дата рождения не подставлена в type=date"
    assert "lk-profile__tabs" not in html
    assert "password_change.php" in html, "ссылка на смену пароля пропала вместе с вкладками"


# ------------------------------------------------------------- добавление -

@pytest.mark.parametrize("expr", ["-15 years", "-101 years", "+1 day"])
def test_add_rejects_bad_dob_and_keeps_count(expr):
    sid = sid_of(SHADHAN)
    _, html = page(ADD_PAGE, sid)
    before = anketa_count()
    code, out = post("/netcat/add.php", add_fields(html, **dob_parts(php_date(expr))), sid)
    assert code == 200, code
    assert "<div class='warnText'" in out, "нет отказа сервера"
    assert 'id="err-Date_of_birth"' in out, "ошибка не у поля даты"
    assert 'data-sh-open-step="1"' in out, "мастер не откроет шаг с ошибкой"
    assert anketa_count() == before, "анкета всё-таки создана"


def test_add_rejects_empty_name_and_keeps_typed_values():
    sid = sid_of(SHADHAN)
    _, html = page(ADD_PAGE, sid)
    before = anketa_count()
    code, out = post("/netcat/add.php", add_fields(html, f_Name="", f_City="Хайфа'2"), sid)
    assert code == 200 and 'id="err-Name"' in out
    assert anketa_count() == before
    # введённое не пропало и не обросло слэшами (эмуляция magic quotes)
    assert 'value="Хайфа&#039;2"' in out, "значение поля после отказа искажено"


def test_add_rejects_height_in_metres():
    sid = sid_of(SHADHAN)
    _, html = page(ADD_PAGE, sid)
    before = anketa_count()
    code, out = post("/netcat/add.php", add_fields(html, f_Height="1.75"), sid)
    assert code == 200 and 'id="err-Height"' in out
    assert anketa_count() == before


@pytest.mark.parametrize("with_parts", [True, False])
def test_add_valid_creates_anketa(with_parts):
    """С частями даты (мастер) и одним полем type=date (без JS)."""
    sid = sid_of(SHADHAN)
    _, html = page(ADD_PAGE, sid)
    iso = php_date("-30 years")
    fields = add_fields(html)
    if not with_parts:
        for k in ("f_Date_of_birth_day", "f_Date_of_birth_month", "f_Date_of_birth_year"):
            fields.pop(k)
    shadhan_saved = backup_row("User", 57, SHADHAN_FIELDS, key="User_ID")
    try:
        code, out = post("/netcat/add.php", fields, sid)
        assert code == 200, code
        assert "<div class='warnText'" not in out, re.search(r"warnText[^<]*<[^>]*>([^<]*)", out)
        row = dbq("SELECT Name, DATE(Date_of_birth), Height, Gender, User_ID FROM `Message204` WHERE Email='%s'" % MARK_EMAIL)
        assert row, "анкета не создана"
        name, dob, height, gender, uid = row.split("\t")
        assert name == MARK and dob == iso and height == "178" and gender == "1" and uid == "57", row
    finally:
        cleanup_added()
        restore_row("User", 57, shadhan_saved, key="User_ID")


# ------------------------------------------------------------- правка -----

def test_edit_rejects_minor_dob_keeps_row():
    sid = sid_of(CANDIDATE)
    saved = backup_row("Message204", ANKETA, ROW_FIELDS)
    try:
        _, html = page(EDIT_PAGE, sid)
        f = service_fields(html)
        assert f.get("message") == str(ANKETA)
        f.update(dob_parts(php_date("-15 years")))
        f["f_Name"] = "Проверка-15"
        code, out = post("/netcat/message.php", f, sid)
        assert code == 200 and "<div class='warnText'" in out and 'id="err-Date_of_birth"' in out
        assert 'value="Проверка-15"' in out, "введённое имя пропало после отказа"
        assert 'name=\'message\' type=\'hidden\' value=\'%d\'' % ANKETA in out, "после отказа форма потеряла id анкеты"
        row = dbq("SELECT Name, Date_of_birth FROM `Message204` WHERE Message_ID=%d" % ANKETA)
        assert row.split("\t") == [saved["Name"], saved["Date_of_birth"]], "строка изменилась при отказе"
    finally:
        restore_row("Message204", ANKETA, saved)


def test_edit_rejects_sent_but_empty_dob():
    """Мастер шлёт скрытые части даты всегда; пустые — отказ, а не NULL в базе."""
    sid = sid_of(CANDIDATE)
    saved = backup_row("Message204", ANKETA, ROW_FIELDS)
    try:
        _, html = page(EDIT_PAGE, sid)
        f = service_fields(html)
        f.update({"f_Date_of_birth": "", "f_Date_of_birth_day": "", "f_Date_of_birth_month": "", "f_Date_of_birth_year": ""})
        code, out = post("/netcat/message.php", f, sid)
        assert code == 200 and 'id="err-Date_of_birth"' in out
        assert dbq("SELECT Date_of_birth FROM `Message204` WHERE Message_ID=%d" % ANKETA) == saved["Date_of_birth"]
    finally:
        restore_row("Message204", ANKETA, saved)


def test_edit_partial_post_is_accepted():
    """partial=1: формы выбора шадхана и загрузка файла шлют несколько полей."""
    sid = sid_of(CANDIDATE)
    saved = backup_row("Message204", ANKETA, ROW_FIELDS)
    try:
        _, html = page(EDIT_PAGE, sid)
        f = service_fields(html)
        f["f_City"] = "Город-Частично-XYZ"
        code, out = post("/netcat/message.php", f, sid)
        # успех: страница «Данные изменены» или редирект ядра
        assert code in (200, 302) and "<div class='warnText'" not in out, code
        assert dbq("SELECT City FROM `Message204` WHERE Message_ID=%d" % ANKETA) == "Город-Частично-XYZ"
        assert dbq("SELECT Name FROM `Message204` WHERE Message_ID=%d" % ANKETA) == saved["Name"], "частичная правка задела другие поля"
    finally:
        restore_row("Message204", ANKETA, saved)


def test_partial_post_keeps_consent_and_health():
    """Форма без галочки согласия (выбор шадхана, файл) не отзывает согласие."""
    sid = sid_of(CANDIDATE)
    saved = backup_row("Message204", ANKETA, ROW_FIELDS)
    try:
        dbq("UPDATE `Message204` SET Health_status='здоров-СЕНТИНЕЛ', Consent_sensitive=1, "
            "Consent_sensitive_at='2026-08-01 10:00:00', Consent_sensitive_version='consent-v1' WHERE Message_ID=%d" % ANKETA)
        _, html = page(EDIT_PAGE, sid)
        f = service_fields(html)
        f["f_City"] = "Город-Согласие-XYZ"
        code, out = post("/netcat/message.php", f, sid)
        assert code in (200, 302) and "<div class='warnText'" not in out, code
        row = dbq("SELECT Health_status, Consent_sensitive FROM `Message204` WHERE Message_ID=%d" % ANKETA)
        assert row.split("\t") == ["здоров-СЕНТИНЕЛ", "1"], "частичная правка отозвала согласие: " + row
        # а форма мастера без галочки — отзывает (поле в форме есть, значение 0)
        f["f_Consent_sensitive"] = "0"
        code, out = post("/netcat/message.php", f, sid)
        assert code in (200, 302)
        assert dbq("SELECT IFNULL(Health_status,'') FROM `Message204` WHERE Message_ID=%d" % ANKETA) == ""
    finally:
        restore_row("Message204", ANKETA, saved)


def test_edit_form_escapes_stored_values():
    sid = sid_of(CANDIDATE)
    saved = backup_row("Message204", ANKETA, ROW_FIELDS)
    try:
        dbq("UPDATE `Message204` SET Name='Имя \"><b>x</b>' WHERE Message_ID=%d" % ANKETA)
        code, html = page(EDIT_PAGE, sid)
        assert code == 200
        form = form_block(html)
        assert 'value="Имя &quot;&gt;&lt;b&gt;x&lt;/b&gt;"' in form, "значение печатается без экранирования"
        assert '"><b>x</b>' not in form
    finally:
        restore_row("Message204", ANKETA, saved)


# ------------------------------------------------------------- файлы ------

def test_wizard_script_served_within_budget():
    r = urllib.request.urlopen(BASE + "/js/anketa-wizard.js", timeout=60)
    js = r.read().decode("utf-8", "replace")
    assert r.status == 200 and len(js) <= 16 * 1024, len(js)
    for hook in ("data-sh-wizard", "data-sh-step", "novalidate", "localStorage", "data-sh-draft-skip", "data-sh-summary",
                 "data-sh-open-step", "aria-current"):
        assert hook in js, hook


def test_validation_rules_unit():
    boot = ("$r='/var/www/html'; require_once $r.'/api/anketa_validate_lib.php'; "
            "$ok=array('f_Name'=>'A B','f_Gender'=>'1','f_Place_of_birth'=>'x','f_Country'=>'1','f_City'=>'x','f_Height'=>'170',"
            "'f_Phone'=>'+7 900 123 45 67','f_Email'=>'a@b.cc','f_Jewishness'=>'1','f_Origin'=>'1','f_Marital_status'=>'1',"
            "'f_Education'=>'1','f_Specialization'=>'x','f_Date_of_birth'=>'1990-05-05'); "
            "echo count(anketa_validate($ok,'add')), '|'; "
            "echo implode(',', array_keys(anketa_validate(array('f_City'=>'x'),'edit'))), '|'; "
            "echo implode(',', array_keys(anketa_validate(array('f_City'=>'', 'f_Height'=>'1.75', 'f_Email'=>'nope'),'edit'))), '|'; "
            "echo count(anketa_validate(array(),'add')) >= 13 ? 'all' : 'few', '|'; "
            "echo anketa_field_step('Search_gender'), anketa_field_step('Photo_avatar'), anketa_field_step('Health_status');")
    out = (web("php", "-r", boot).stdout or "").strip().splitlines()[-1]
    assert out == "0||City,Height,Email|all|564", out


def test_templates_clean_twins_and_lint():
    inc = os.path.join(WEBROOT, "api", "anketa_form.inc.php")
    files = [os.path.join(C204, "209", "AddTemplate.html"), os.path.join(C204, "206", "RecordTemplate.html"),
             os.path.join(C204, "206", "Class.html"), inc, os.path.join(WEBROOT, "api", "anketa_edit_form.inc.php")]
    for t in ("18", "22", "23"):
        files += [os.path.join(TPL, t, "Header.html"), os.path.join(TPL, t, "Template.html")]
    for p in files:
        src = read(p)
        assert "<style" not in src and 'style="' not in src, p + ": инлайновые стили"
        assert "<script>" not in src, p + ": инлайновый <script>"
    # Class.html 206 = FormPrefix + RecordTemplate + FormSuffix между маркерами
    d = os.path.join(C204, "206")
    cls = read(os.path.join(d, "Class.html"))
    rt = read(os.path.join(d, "RecordTemplate.html"))
    inner = rt[rt.index("/* Конец служебной части */?>") + len("/* Конец служебной части */?>"):rt.rindex("<?/* Служебная часть */")]
    m = re.search(r"<!-- RecordTemplate -->(.*?)<!-- /RecordTemplate -->", cls, re.S)
    assert m and m.group(1).strip() == inner.strip(), "206/RecordTemplate разошёлся с Class.html"
    # макеты: Template.html = Header + Footer
    for t in ("18", "22", "23"):
        h = read(os.path.join(TPL, t, "Header.html")).replace("%Header", "").strip()
        f = read(os.path.join(TPL, t, "Footer.html")).replace("%Footer", "").strip()
        tpl = read(os.path.join(TPL, t, "Template.html"))
        assert h in tpl and f in tpl, "template/3/%s: Template.html разошёлся с Header/Footer" % t
        assert "__tabs" not in tpl, "template/3/%s: вкладки остались" % t
    # подшаблоны подключают корневые Cond (иначе POST с их cc обойдёт проверку)
    for rel in ("209/AddCond.html", "240/AddCond.html"):
        assert "AddCond.html" in read(os.path.join(C204, rel)), rel
    for rel in ("206/EditCond.html", "239/EditCond.html"):
        assert "EditCond.html" in read(os.path.join(C204, rel)), rel
    for rel in ("class/204/209/AddTemplate.html", "class/204/206/RecordTemplate.html", "class/204/AddCond.html",
                "class/204/EditCond.html"):
        r = web("php", "-l", "/var/www/html/netcat_template/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
    for rel in ("class/204/AddTemplate.html", "class/204/EditTemplate.html", "class/204/206/EditTemplate.html"):
        r = web("php", "-l", "/var/www/html/netcat_template/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
    for rel in ("api/anketa_form.inc.php", "api/anketa_validate_lib.php", "api/anketa_edit_form.inc.php"):
        r = web("php", "-l", "/var/www/html/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
