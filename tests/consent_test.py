# -*- coding: utf-8 -*-
"""
Тесты согласия на обработку чувствительных данных (ROADMAP X-5, 152-ФЗ).

Сведения о здоровье — специальная категория персональных данных: их
обработка требует ОТДЕЛЬНОГО осознанного согласия, а не общего «соглашения
об использовании сайта».

Правила:
  * в форме анкеты (добавление и правка) есть галочка согласия и сам текст
    согласия — читаемый на месте, а не только ссылкой;
  * согласие фиксируется с датой и версией текста: «когда и на что именно»;
  * ГЛАВНОЕ (серверная гарантия): без согласия поле о здоровье НЕ
    сохраняется — сняли галочку, данные о здоровье стираются;
  * остальные поля анкеты сохраняются как обычно: отсутствие согласия не
    блокирует человеку работу с сайтом.

    python -m pytest tests/consent_test.py -q
"""
import os
import sys
import re
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login, web

OWNER = "u051e2e9933c7@example.local"       # User 1640 — владелец анкеты 2033
A = 2033

MODIFY_FORM = "/my/modify/"          # своя анкета: рабочая форма правки
CONSENT_VERSION_MARK = "consent-v1"

BACKUP_TABLE = "consent_test_backup"


def dbq_(sql):
    return dbq(sql)


def set_fields(**kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % str(v).replace("\\", "\\\\").replace("'", "''")))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), A))


def get_field(name):
    return dbq("SELECT IFNULL(`%s`,'') FROM `Message204` WHERE Message_ID=%d" % (name, A))


def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=180) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def setup_module(module):
    # полный бэкап строки: форма правки перезаписывает всю анкету, поэтому
    # восстанавливаем её целиком, а не по отдельным полям
    dbq("DROP TABLE IF EXISTS `%s`" % BACKUP_TABLE)
    dbq("CREATE TABLE `%s` AS SELECT * FROM `Message204` WHERE Message_ID=%d"
        % (BACKUP_TABLE, A))


def teardown_module(module):
    cols = dbq("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.columns "
               "WHERE table_schema=DATABASE() AND table_name='Message204'").split(",")
    sets = ", ".join("m.`%s`=b.`%s`" % (c, c) for c in cols if c != "Message_ID")
    dbq("UPDATE `Message204` m JOIN `%s` b ON b.Message_ID=m.Message_ID SET %s"
        % (BACKUP_TABLE, sets))
    dbq("DROP TABLE IF EXISTS `%s`" % BACKUP_TABLE)


def php(code):
    """Выполнить PHP в контейнере с подключённой библиотекой согласия."""
    # в CLI веб-сервер не задаёт DOCUMENT_ROOT — поднимаем ядро так же,
    # как это делает migrations/_bootstrap.php
    boot = ("$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
            "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
            "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; "
            "require_once $r.'/api/consent_lib.php'; $db=nc_core::get_object()->db; ")
    return (web("php", "-r", boot + code).stdout or "").strip()


# --- поля в схеме ------------------------------------------------------------

def test_consent_columns_exist():
    for col in ("Consent_sensitive", "Consent_sensitive_at", "Consent_sensitive_version"):
        assert dbq("SHOW COLUMNS FROM `Message204` LIKE '%s'" % col) != "",             "нет колонки " + col


# --- текст согласия ----------------------------------------------------------

def test_consent_text_covers_required_points():
    """Текст должен отвечать на обязательные вопросы: что, зачем, кто видит,
    куда не уходит, как отозвать, срок."""
    text = php("$t=consent_text(); echo $t['intro'].' '.implode(' ', $t['points']);")
    for mark, what in (("специальной категории", "спецкатегория данных"),
                       ("здоровь", "предмет согласия"),
                       ("шадхан", "кто видит"),
                       ("искусственного интеллекта", "куда НЕ передаётся"),
                       ("отозвать", "порядок отзыва"),
                       ("Срок", "срок обработки"),
                       ("не обязательно", "добровольность")):
        assert mark.lower() in text.lower(), "в тексте согласия нет: " + what


def test_consent_version_is_stamped_not_hardcoded_elsewhere():
    v = php("echo consent_version();")
    assert v.startswith("consent-v"), "версия согласия странного вида: " + repr(v)


# --- серверная гарантия ------------------------------------------------------

def test_consent_given_stamps_date_and_version():
    set_fields(Health_status="аллергия-СЕНТИНЕЛ", Consent_sensitive=0,
               Consent_sensitive_at=None, Consent_sensitive_version=None)
    php("consent_apply($db, %d, true);" % A)
    assert get_field("Consent_sensitive") in ("1", "1.0"), "флаг согласия не выставлен"
    assert get_field("Consent_sensitive_at") not in ("", "0000-00-00 00:00:00"),         "не зафиксирована дата согласия"
    assert CONSENT_VERSION_MARK in get_field("Consent_sensitive_version"),         "не зафиксирована версия: " + repr(get_field("Consent_sensitive_version"))
    assert "СЕНТИНЕЛ" in get_field("Health_status"), "данные о здоровье потеряны при согласии"


def test_no_consent_erases_health_data():
    """Ключевая гарантия: нет согласия — данных о здоровье в базе нет."""
    set_fields(Health_status="хроническое-СЕНТИНЕЛ", Consent_sensitive=1,
               Consent_sensitive_at="2026-08-01 10:00:00",
               Consent_sensitive_version=CONSENT_VERSION_MARK)
    php("consent_apply($db, %d, false);" % A)
    assert "СЕНТИНЕЛ" not in get_field("Health_status"),         "данные о здоровье остались без согласия: " + repr(get_field("Health_status"))
    assert get_field("Consent_sensitive") in ("0", "0.0", ""), "флаг согласия не снят"
    assert get_field("Consent_sensitive_at") == "", "дата отозванного согласия не снята"
    assert get_field("Consent_sensitive_version") == "", "версия отозванного согласия не снята"


def test_withdrawal_does_not_touch_rest_of_anketa():
    """Отзыв согласия не должен ломать остальную анкету."""
    set_fields(Health_status="что-то-СЕНТИНЕЛ", Consent_sensitive=1, Name="Проверка-Имя-XYZ")
    php("consent_apply($db, %d, false);" % A)
    assert get_field("Name") == "Проверка-Имя-XYZ", "отзыв согласия затронул другие поля"


# --- форма -------------------------------------------------------------------

def test_form_shows_consent_block_and_text():
    code, html = get_page(MODIFY_FORM, login(OWNER))
    assert code == 200, "форма правки не открылась: %d" % code
    assert "f_Consent_sensitive" in html, "в форме нет галочки согласия"
    assert "специальной категории" in html, "в форме нет текста про спецкатегорию"
    assert "отозвать" in html.lower(), "в форме не сказано, как отозвать согласие"
    # галочка стоит рядом с полем здоровья, а не где-то внизу страницы
    i_health = html.find("f_Health_status")
    i_consent = html.find("f_Consent_sensitive")
    assert abs(i_health - i_consent) < 4000,         "галочка согласия далеко от поля здоровья (%d символов)" % abs(i_health - i_consent)
