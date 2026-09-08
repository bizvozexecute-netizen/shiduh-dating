# -*- coding: utf-8 -*-
"""
C13 этапа «До идеала», мелочи: escape() → prepare() в миграциях (escape()
ядра делает stripslashes перед экранированием — значение с обратной косой
портится), статический index.html убран (главную отдаёт index.php), список
администраторов сайта — одной функцией (настройка), а не «== 1 || == 119» по шаблонам.

    python -m pytest tests/housekeeping_test.py -q
"""
import glob
import io
import os
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, ROOT, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")


def read(p):
    return io.open(p, encoding="utf-8", errors="replace").read()


def test_migrations_use_prepare_not_escape():
    bad = []
    for p in glob.glob(os.path.join(WEBROOT, "migrations", "*.php")):
        if "->escape(" in read(p):
            bad.append(os.path.basename(p))
    assert bad == [], "escape() в миграциях: %s" % bad


def test_static_index_html_is_gone():
    assert not os.path.exists(os.path.join(WEBROOT, "index.html")), "index.html всё ещё в репозитории"
    try:
        r = urllib.request.urlopen(BASE + "/index.html", timeout=60)
        code = r.status
    except urllib.error.HTTPError as e:
        code = e.code
    assert code == 404, "index.html отдаётся: %d" % code
    html = urllib.request.urlopen(BASE + "/", timeout=60).read().decode("utf-8", "replace")
    assert "data-sh-count" in html, "главную отдаёт не index.php"


def test_superadmin_check_is_one_function():
    files = [os.path.join(WEBROOT, "netcat_template", "class", "204", n)
             for n in ("RecordTemplateFull.html", "RecordTemplate.html", "Class.html")]
    for p in files:
        src = read(p)
        assert "== 119" not in src and "=== 119" not in src and "== 1 ||" not in src, p + ": id администратора зашит"
        assert "shiduh_is_site_admin(" in src, p + ": нет shiduh_is_site_admin"
    # список — настройка; по умолчанию как было: 1 и 119 (шадхан 43 из superadmin_ids прав к документам не получает)
    boot = ("$r='/var/www/html'; putenv(\"DOCUMENT_ROOT=$r\"); putenv('HTTP_HOST=localhost'); "
            "$_SERVER['DOCUMENT_ROOT']=$r; $_SERVER['HTTP_HOST']='localhost'; "
            "include_once $r.'/vars.inc.php'; require_once $ROOT_FOLDER.'connect_io.php'; require_once $r.'/api/admin_lib.php'; ")
    out = (web("php", "-r", boot + "echo SHIDUH_SITE_ADMIN_DEFAULT, '|', shiduh_is_site_admin(43) ? 'y' : 'n', '|', shiduh_is_site_admin(119) ? 'y' : 'n';").stdout or "").strip().splitlines()[-1]
    assert out == "1,119|n|y", out


def test_timezone_in_config_example():
    src = read(os.path.join(WEBROOT, "vars.inc.php.example"))
    assert "$MYSQL_TIMEZONE" in src, "в примере конфига нет $MYSQL_TIMEZONE"
