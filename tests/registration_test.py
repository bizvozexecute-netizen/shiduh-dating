# -*- coding: utf-8 -*-
"""
A2 этапа «До идеала»: регистрация и вход.

Что было: дату рождения собирали три скрытых поля с «01.01.2024» по
умолчанию, которые заполнял календарь бандла — у кого он не сработал, тот
регистрировался «двухлетним» (224 таких даты, три реальных
несовершеннолетних). Минимальной длины пароля не было (pass_min = 0),
галочка согласия стояла заранее.

Теперь: одно поле type="date", порог возраста (AUTH_MIN_AGE, 16 с 2026-10) и правила пароля проверяет
сервер (api/auth_rules_lib.php из AddCond регистрации), ошибки печатаются у
полей, введённое не теряется, согласия не предустановлены. Капча не мешает:
пока серверный секрет не задан, заслон C7 пропускает (captcha_test).

    python -m pytest tests/registration_test.py -q
"""
import datetime
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, NoRedirect, dbq, login, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")
# Порог возраста — AUTH_MIN_AGE в api/auth_rules_lib.php (решение заказчика 2026-10-09).
MIN_AGE = 16
AUTH_TPL = os.path.join(WEBROOT, "netcat_template", "class", "netcat_module_auth_user")
LAYOUT = os.path.join(WEBROOT, "netcat_template", "template", "3")

EMAIL = "reg-test-a2@example.local"
EXISTING = "u091ff2b36c83@example.local"     # кандидат из фикстур (User 50)
SHADHAN = "u3f9bb8c20b01@example.local"      # шадхан (User 57): кандидата с неполной анкетой макет уводит на /my/modify
GOOD_PASSWORD = "pa55word-ok"


def teardown_module(module):
    cleanup_all()


def cleanup_all():
    # Хук ядра ListenUser::add_candidat (modules/default/function.inc.php)
    # заводит анкету Message204 на каждого нового кандидата — убираем и её.
    dbq("DELETE FROM `Message204` WHERE `Email` LIKE 'reg-test-%%'")
    dbq("DELETE FROM `User` WHERE `Email` LIKE 'reg-test-%%'")


_TODAY = None


def today():
    """«Сегодня» по часам контейнера: границы даты считает PHP там, и 29
    февраля strtotime('-18 years') даёт не то же, что replace(year=…)."""
    global _TODAY
    if _TODAY is None:
        out = (web("php", "-r", "echo date('Y-m-d');").stdout or "").strip().splitlines()[-1]
        _TODAY = datetime.date(*map(int, out.split("-")))
    return _TODAY


def php_years_ago(years):
    out = (web("php", "-r", "echo date('Y-m-d', strtotime('-%d years'));" % years).stdout or "").strip()
    return out.splitlines()[-1]


def years_ago(years, days=0):
    today_ = today()
    try:
        d = today_.replace(year=today_.year - years)
    except ValueError:                       # 29 февраля
        d = today_.replace(year=today_.year - years, day=28)
    return d + datetime.timedelta(days=days)


def iso(d):
    return d.strftime("%Y-%m-%d")


def fields(**over):
    f = {
        "admin_mode": "", "catalogue": "1", "cc": "3", "sub": "5", "posting": "1", "curPos": "0",
        "f_Name": "Тест Регистрации", "f_Type": "1", "f_Gender": "1",
        "nc_dob": iso(years_ago(25)), "f_Phone": "+7 900 123-45-67", "f_Email": EMAIL,
        "Password1": GOOD_PASSWORD, "Password2": GOOD_PASSWORD,
        "nc_agreed": "1", "nc_age18": "1",
    }
    for k, v in over.items():
        if v is None:
            f.pop(k, None)
        else:
            f[k] = v
    return f


def submit(f):
    """POST формы регистрации гостем через add.php — как браузер."""
    body = urllib.parse.urlencode(f).encode()
    req = urllib.request.Request(BASE + "/netcat/add.php", data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def user_row(email=EMAIL):
    return dbq("SELECT User_ID, Date_of_birth, Type FROM `User` WHERE Email='%s'" % email)


def cleanup(email=EMAIL):
    dbq("DELETE FROM `Message204` WHERE `Email`='%s'" % email)
    dbq("DELETE FROM `User` WHERE `Email`='%s'" % email)


def page(path, sid=None):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    return urllib.request.urlopen(req, timeout=60).read().decode("utf-8", "replace")


def read(path):
    return open(path, encoding="utf-8", errors="replace").read()


# ------------------------------------------------------------------ форма ---

def test_form_markup():
    html = page("/my/registration/")
    m = re.search(r'<input[^>]*name="nc_dob"[^>]*>', html)
    assert m, "нет поля даты рождения nc_dob"
    dob = m.group(0)
    assert 'type="date"' in dob, "дата рождения — не type=date"
    assert 'max="%s"' % php_years_ago(MIN_AGE) in dob, "max поля даты не равен «MIN_AGE лет назад»: " + dob
    assert 'min="' in dob, "нет нижней границы даты"
    assert "f_Date_of_birth_year" not in html, "остались скрытые поля даты с датой по умолчанию"
    assert "datepicker__el" not in html.split("form--registration", 1)[1].split("</form>", 1)[0], \
        "календарь бандла подключён к регистрации"
    p1 = re.search(r"<input[^>]*id='f_Password1'[^>]*>", html).group(0)
    assert "minlength='8'" in p1, "нет minlength у пароля: " + p1
    assert "autocomplete='new-password'" in p1
    agreed = re.search(r'<input[^>]*name="nc_agreed"[^>]*>', html).group(0)
    assert " checked" not in agreed, "согласие предустановлено: " + agreed
    assert 'name="nc_age18"' in html, "нет подтверждения «Мне есть 18 лет»"
    assert 'data-sh-pw="f_Password1"' in html, "нет живых правил пароля"
    form_tag = re.search(r"<form[^>]*form--registration[^>]*>", html).group(0)
    assert "data-sh-native" in form_tag, "форма без data-sh-native: бандл перехватит submit и покажет «успех» при отказе"
    assert "modules/auth/auth.js" not in html, "auth.js ядра подключён — ему нужен jQuery, которого нет"


def test_native_submit_guard_in_layer():
    """Бандл отправляет form.form через fetch и показывает «Регистрация
    успешна», не читая ответ. one.js обязан снять перехват для форм с
    data-sh-native — иначе отказ сервера (занятый e-mail, 17 лет) человек
    не увидит."""
    js = read(os.path.join(WEBROOT, "js", "one.js"))
    assert "data-sh-native" in js and "stopPropagation" in js and "addEventListener('submit'" in js
    assert re.search(r"addEventListener\('submit',.*?\}, true\)", js, re.S), "слушатель submit не в фазе захвата"


def test_subtemplates_share_the_gate():
    """У компонента User 10+ инфоблоков с подшаблонами (208, 232 …): POST с их
    cc шёл через свой AddCond без порога 18+ и капчи."""
    cleanup()
    f = fields(cc="41", sub="44", nc_dob=None, nc_age18=None)
    f.update({"f_Date_of_birth_day": "1", "f_Date_of_birth_month": "1", "f_Date_of_birth_year": "2015",
              "f_Date_of_birth_hours": "0", "f_Date_of_birth_minutes": "0", "f_Date_of_birth_seconds": "0"})
    submit(f)
    assert not user_row(), "регистрация через подшаблон 232 (cc=41) обошла порог 18+"
    import glob
    for path in glob.glob(os.path.join(AUTH_TPL, "[0-9]*", "AddCond.html")):
        assert "require dirname(__DIR__) . '/AddCond.html'" in read(path), path


def test_bad_type_rejected():
    cleanup()
    submit(fields(f_Type="3"))
    assert not user_row(), "тип 3 (раввин) принят при саморегистрации"


def test_profile_edit_keeps_age_gate():
    """Порог при регистрации бессмыслен, если в профиле дату можно поменять
    на 2015 год (форма правки шлёт части f_Date_of_birth_*)."""
    sid = login(SHADHAN)
    uid = dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % SHADHAN)
    before = dbq("SELECT Date_of_birth FROM `User` WHERE User_ID=%s" % uid)
    html = page("/my/modify-shidhan/", sid)
    html = html.split("form--lk-profile-shadhan", 1)[1].split("</form>", 1)[0]   # на странице несколько форм
    hidden = dict(re.findall(r"<input name='([^']+)' type='hidden' value='([^']*)'", html))
    token = re.search(r'name=["\']nc_token["\'][^>]*value=["\']([^"\']+)', html)
    f = {k: hidden.get(k, "") for k in ("admin_mode", "catalogue", "cc", "sub", "message", "curPos", "f_Parent_Message_ID")}
    f.update({"posting": "1", "f_Date_of_birth_day": "1", "f_Date_of_birth_month": "1", "f_Date_of_birth_year": "2015",
              "f_Date_of_birth_hours": "0", "f_Date_of_birth_minutes": "0", "f_Date_of_birth_seconds": "0"})
    if token:
        f["nc_token"] = token.group(1)
    body = urllib.parse.urlencode(f).encode()
    req = urllib.request.Request(BASE + "/netcat/message.php", data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        resp = urllib.request.build_opener(NoRedirect).open(req, timeout=60).read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        resp = e.read().decode("utf-8", "replace")
    after = dbq("SELECT Date_of_birth FROM `User` WHERE User_ID=%s" % uid)
    if after != before:
        dbq("UPDATE `User` SET Date_of_birth='%s' WHERE User_ID=%s" % (before, uid))
    assert after == before, "дата рождения в профиле изменена на 2015"
    assert "%d лет" % MIN_AGE in resp, "отказ без объяснения про возраст"


def test_form_has_no_inline_styles_and_no_ghost_fields():
    src = read(os.path.join(AUTH_TPL, "AddTemplate.html"))
    assert 'style="' not in src and "<style" not in src
    # PHP внутри HTML-комментария исполняется и печатал второй чекбокс с тем же id
    assert "<!--<?php" not in src and "<!--<div" not in src, "остался закомментированный блок с PHP"
    html = page("/my/registration/")
    assert html.count("id='nc_agreed'") + html.count('id="nc_agreed"') == 1, "id nc_agreed встречается не один раз"


def test_templates_lint():
    for rel in ("AddTemplate.html", "AddCond.html"):
        r = web("php", "-l", "/var/www/html/netcat_template/class/netcat_module_auth_user/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel + ": " + (r.stdout or r.stderr)
    r = web("php", "-l", "/var/www/html/api/auth_rules_lib.php")
    assert "No syntax errors" in (r.stdout or "")


# ------------------------------------------------------------ регистрация ---

def test_valid_registration_creates_user():
    cleanup()
    code, html = submit(fields())
    row = user_row()
    assert row, "пользователь не создан; ответ: " + re.sub(r"\s+", " ", html)[:600]
    _, dob, utype = row.split("\t")
    assert dob.startswith(iso(years_ago(25))), "дата рождения записана неверно: " + dob
    assert utype == "1"
    # ядро заводит кандидату анкету и копирует туда дату — она должна быть настоящей
    anketa = dbq("SELECT Date_of_birth FROM `Message204` WHERE Email='%s'" % EMAIL)
    assert anketa.startswith(iso(years_ago(25))), "в анкете дата рождения не та: " + anketa
    cleanup()


def test_dotted_date_format_accepted():
    """Кто набирает дату руками в старом формате — не должен получать отказ."""
    cleanup()
    d = years_ago(30)
    submit(fields(nc_dob=d.strftime("%d.%m.%Y")))
    row = user_row()
    assert row and row.split("\t")[1].startswith(iso(d)), "дата ДД.ММ.ГГГГ не принята: " + row
    cleanup()
    # после ошибки в другом поле дата возвращается в форму уже в ISO — иначе
    # type="date" её молча отбросит
    code, html = submit(fields(nc_dob=d.strftime("%d.%m.%Y"), Password1="short", Password2="short"))
    assert 'value="%s"' % iso(d) in html, "дата ДД.ММ.ГГГГ не нормализована при повторном показе"


def test_exactly_min_age_accepted():
    cleanup()
    submit(fields(nc_dob=iso(years_ago(MIN_AGE))))
    assert user_row(), "ровно %d лет — отказ" % MIN_AGE
    cleanup()


def test_minor_rejected():
    cleanup()
    code, html = submit(fields(nc_dob=iso(years_ago(MIN_AGE, days=1))))
    assert not user_row(), "несовершеннолетний зарегистрирован"
    assert "%d лет" % MIN_AGE in html, "нет объяснения про возраст"


def test_future_and_ancient_dates_rejected():
    cleanup()
    submit(fields(nc_dob=iso(today() + datetime.timedelta(days=1))))
    assert not user_row(), "дата из будущего принята"
    submit(fields(nc_dob=iso(years_ago(101))))
    assert not user_row(), "101 год принят"
    submit(fields(nc_dob="2000-13-45"))
    assert not user_row(), "несуществующая дата принята"
    submit(fields(nc_dob=""))
    assert not user_row(), "пустая дата принята"


def test_short_password_rejected():
    cleanup()
    code, html = submit(fields(Password1="1234567", Password2="1234567"))
    assert not user_row(), "пароль из 7 символов принят"
    assert "8 символов" in html, "нет объяснения про длину пароля"


def test_password_mismatch_rejected():
    cleanup()
    code, html = submit(fields(Password2=GOOD_PASSWORD + "x"))
    assert not user_row()
    assert "не совпадают" in html


def test_without_age18_rejected():
    cleanup()
    code, html = submit(fields(nc_age18=None))
    assert not user_row(), "регистрация без подтверждения возраста прошла"
    assert "%d лет" % MIN_AGE in html


def test_without_agreement_rejected():
    cleanup()
    code, html = submit(fields(nc_agreed=None))
    assert not user_row(), "регистрация без согласия прошла"


def test_duplicate_email_rejected():
    before = dbq("SELECT COUNT(*) FROM `User` WHERE Email='%s'" % EXISTING)
    code, html = submit(fields(f_Email=EXISTING))
    assert dbq("SELECT COUNT(*) FROM `User` WHERE Email='%s'" % EXISTING) == before
    assert "уже зарегистрирован" in html


def test_values_kept_after_error():
    """После ошибки форма не должна очищаться: заново вводить ~10 полей —
    прямая потеря регистраций."""
    cleanup()
    code, html = submit(fields(Password1="short", Password2="short",
                               f_Name="Имя-Сохранено-7781", f_Phone="+7 999 777-81-00"))
    assert 'value="Имя-Сохранено-7781"' in html, "имя потеряно после ошибки"
    assert 'value="+7 999 777-81-00"' in html, "телефон потерян после ошибки"
    assert 'value="%s"' % iso(years_ago(25)) in html, "дата потеряна после ошибки"
    assert 'id="err-password1"' in html, "ошибка не показана у поля пароля"
    warn = re.search(r"<div class='warnText'[^>]*>(.*?)</div>", html, re.S).group(1)
    assert "8 символов" not in warn, "текст ошибки продублирован вверху и у поля"
    assert html.count('class="sh-field__error"') == 1, "ошибок у полей должно быть ровно одна"
    assert "value='short'" not in html and 'value="short"' not in html, "пароль напечатан обратно в форму"


# ------------------------------------------------------- настройки и код ---

def test_pass_min_setting_is_8():
    rows = dbq("SELECT Catalogue_ID, Value FROM `Settings` WHERE Module='auth' AND `Key`='pass_min' ORDER BY 1")
    assert rows, "нет настройки pass_min"
    for ln in rows.splitlines():
        cat, val = ln.split("\t")
        assert int(val) >= 8, "pass_min в каталоге %s = %s" % (cat, val)


def test_min_age_matches_matching():
    """Порог регистрации и порог подбора — одно число: анкета, принятая при
    регистрации, не должна выпадать из подбора."""
    auth = read(os.path.join(WEBROOT, "api", "auth_rules_lib.php"))
    match = read(os.path.join(WEBROOT, "api", "match_lib.php"))
    a = re.search(r"define\('AUTH_MIN_AGE',\s*(\d+)\)", auth).group(1)
    assert a == str(MIN_AGE), a
    # подбор не держит своего числа — выводит порог из AUTH_MIN_AGE
    assert re.search(r"define\('MATCH_MIN_AGE',\s*AUTH_MIN_AGE\)", match), "MATCH_MIN_AGE задан отдельным числом"
    show = read(os.path.join(WEBROOT, "api", "showcase_lib.php"))
    assert re.search(r"define\('MATCH_MIN_AGE',\s*AUTH_MIN_AGE\)", show), "витрина держит свой порог"


def test_validate_unit_cases():
    """Чистые функции без ядра: граничные случаи, до которых формой не добраться."""
    code = (
        "require '/var/www/html/api/auth_rules_lib.php';"
        "$t = mktime(0,0,0,3,1,2026);"                                   # 1 марта 2026
        "$base = array('name'=>'Ая','dob'=>'2000-02-29','phone'=>'+972 50 000 0000',"
        "  'password1'=>'12345678','password2'=>'12345678','age18'=>1,'agreed'=>1);"
        "$ok = auth_register_validate($base, 8, $t);"
        "$b = $base; $b['dob'] = '2010-03-02'; $minor = auth_register_validate($b, 8, $t);"   # 15 лет 364 дня
        "$b = $base; $b['dob'] = '2010-03-01'; $adult = auth_register_validate($b, 8, $t);"   # ровно 16
        "$b = $base; $b['phone'] = '123'; $ph = auth_register_validate($b, 8, $t);"
        "$b = $base; $b['name'] = ' '; $nm = auth_register_validate($b, 8, $t);"
        "$b = $base; $b['password1'] = $b['password2'] = 'Пароль123'; $utf = auth_register_validate($b, 8, $t);"
        "$b = $base; $zero = auth_register_validate($b, 0, $t);"                                # pass_min=0 → дефолт 8
        "$b = $base; $b['password1'] = $b['password2'] = '1234567'; $zero7 = auth_register_validate($b, 0, $t);"
        "echo json_encode(array('ok'=>$ok,'minor'=>isset($minor['dob']),'adult'=>isset($adult['dob']),"
        "  'phone'=>isset($ph['phone']),'name'=>isset($nm['name']),'utf'=>$utf,'zero'=>$zero,'zero7'=>isset($zero7['password1']),"
        "  'age'=>auth_age_years(array(2000,2,29), $t)));"
    )
    r = web("php", "-r", code)
    import json
    js = json.loads((r.stdout or "").strip().splitlines()[-1])
    assert js["ok"] == [], js
    assert js["minor"] is True and js["adult"] is False, js
    assert js["phone"] is True and js["name"] is True, js
    assert js["utf"] == [], "пароль из 9 кириллических символов отвергнут: %s" % js
    assert js["zero"] == [] and js["zero7"] is True, "pass_min=0 не заменён дефолтом 8"
    assert js["age"] == 26, js


# -------------------------------------------------------------------- вход ---

def test_login_modal_accessible():
    html = page("/")
    block = html.split('class="modal modal--lk ', 1)[1].split("</form>", 1)[0]
    assert 'role="dialog"' in html.split('modal--lk ', 1)[1][:200], "у модалки входа нет role=dialog"
    assert 'aria-modal="true"' in html.split('modal--lk ', 1)[1][:200]
    assert 'autocomplete="email"' in block, "нет autocomplete=email у логина"
    assert 'autocomplete="current-password"' in block, "нет autocomplete=current-password"
    assert "<label" in block, "поля входа без подписей"
    assert 'style="display: none;"' not in html, "инлайновый display:none в макете"


def test_logoff_link_is_single_line():
    """Ссылка «Выйти» содержала переносы строк внутри href — адрес уходил с
    %0A и пробелами."""
    sid = login(EXISTING)
    html = page("/", sid)
    for m in re.finditer(r"href='(/netcat/modules/auth/\?logoff=1[^']*)'", html):
        assert "\n" not in m.group(1) and "  " not in m.group(1), "перенос строки в href выхода: %r" % m.group(1)
    assert "logoff=1" in html, "ссылки выхода нет на странице для вошедшего"
