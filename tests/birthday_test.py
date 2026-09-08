# -*- coding: utf-8 -*-
"""
C13 этапа «До идеала»: поздравления с днём рождения (ROADMAP B-5).

Что было: скрипт без дедупликации по несуществующему адресу в CronTasks
«каждую минуту». Теперь: только CLI, выборка по дате в SQL, одно письмо в
год адресату, экранирование имени.

    python -m pytest tests/birthday_test.py -q
"""
import os
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, backup_row, dbq, restore_row, web  # noqa: E402

ANKETA = 42        # кандидат 50, ведущим станет 57
CANDIDATE_ID = 50
SHADHAN_ID = 57
NAME = "O'Нил-Тест"
STATE = {}


def run_script():
    r = web("php", "/var/www/html/check_birthday_candidates/index.php")
    return (r.stdout or "") + (r.stderr or "")


def count(to_user, subject):
    return int(dbq("SELECT COUNT(*) FROM `Message6` WHERE ToUser=%d AND User_ID=1 AND Subject='%s' "
                   "AND Keyword=CONCAT('birthday:%d:', YEAR(CURDATE()))" % (to_user, subject, ANKETA)))


def cleanup():
    # всё, что создал скрипт за прогон, — по любым именинникам dev-базы, не только по фикстуре
    dbq("DELETE FROM `Message6` WHERE Keyword LIKE 'birthday:%%' AND Created >= '%s'" % STATE["start"])


def setup_module(module):
    STATE["start"] = dbq("SELECT NOW()")
    STATE["row"] = backup_row("Message204", ANKETA, ("Name", "Date_of_birth", "Shadhan_1", "Checked", "LastUpdated"))
    dbq("UPDATE `Message204` SET Name='%s', Date_of_birth=CONCAT('1990-', DATE_FORMAT(CURDATE(), '%%m-%%d'), ' 00:00:00'), "
        "Shadhan_1=%d, Checked=1 WHERE Message_ID=%d" % (NAME.replace("'", "''"), SHADHAN_ID, ANKETA))
    dbq("DELETE FROM `Message6` WHERE Keyword=CONCAT('birthday:%d:', YEAR(CURDATE()))" % ANKETA)


def teardown_module(module):
    cleanup()
    restore_row("Message204", ANKETA, STATE["row"])


def test_double_run_sends_once_and_escapes_name():
    out1 = run_script()
    assert "greeted" in out1, out1
    out2 = run_script()
    assert count(CANDIDATE_ID, "С днём рождения") == 1, "кандидату ушло не одно письмо"
    assert count(SHADHAN_ID, "День рождения у кандидата") == 1, "шадхану ушло не одно письмо"
    text = dbq("SELECT Message FROM `Message6` WHERE ToUser=%d AND User_ID=1 AND Subject='С днём рождения' "
               "ORDER BY Message_ID DESC LIMIT 1" % CANDIDATE_ID)
    assert NAME in text, "имя с апострофом потерялось: " + text[:80]
    # у шадхана могут быть и другие именинники сегодня — берём письмо по метке анкеты
    note = dbq("SELECT Message FROM `Message6` WHERE ToUser=%d AND User_ID=1 AND Keyword=CONCAT('birthday:%d:', YEAR(CURDATE())) "
               "ORDER BY Message_ID DESC LIMIT 1" % (SHADHAN_ID, ANKETA))
    assert "candidate_%d.html" % ANKETA in note
    assert "greeted 0" in out2 and "notified 0" in out2, "второй запуск снова поздравил: " + out2


def test_http_access_denied():
    req = urllib.request.Request(BASE + "/check_birthday_candidates/index.php")
    try:
        r = urllib.request.urlopen(req, timeout=60)
        code = r.status
    except urllib.error.HTTPError as e:
        code = e.code
    assert code == 403, "скрипт доступен по HTTP: %d" % code


def test_cron_task_removed_and_clock_in_sync():
    assert dbq("SELECT COUNT(*) FROM `CronTasks` WHERE Cron_Script_URL LIKE '%%check_birthday_candidates%%'") == "0", \
        "сломанная задача планировщика осталась"
    # часы MySQL и PHP должны совпадать ($MYSQL_TIMEZONE в vars.inc.php: ядро делает
    # SET TIME_ZONE в своём соединении — mysql CLI тестов этого не видит)
    boot = ("$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
            "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
            "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; $db=nc_core::get_object()->db; "
            "echo $db->get_var('SELECT @@session.time_zone'), '|', $db->get_var('SELECT NOW()'), '|', date('Y-m-d H:i');")
    out = (web("php", "-r", boot).stdout or "").strip().splitlines()[-1]
    tz, my_now, php_now = out.split("|")
    assert tz not in ("SYSTEM", ""), "MySQL-сессии не задан часовой пояс ($MYSQL_TIMEZONE): " + out
    assert my_now[:16] == php_now, "часы PHP и MySQL расходятся: " + out
