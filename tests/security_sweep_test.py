# -*- coding: utf-8 -*-
"""
Сквозная проверка безопасности и наблюдаемости (этап «До идеала», поток C).

C1 — наблюдаемость:
  - прод-.htaccess не глушит ошибки: log_errors on, error_reporting ≠ 0,
    display_errors off (раньше стояло error_reporting 0 — фаталы уходили в никуда,
    и Docker-стенд наследовал ту же слепоту);
  - api/*.php при фатале отвечают JSON 500 без деталей (register_shutdown_function),
    обработчик регистрируется ДО загрузки ядра;
  - единая точка логирования shiduh_log($channel, $msg) — сырых error_log() в
    нашем коде не остаётся.

    python -m pytest tests/security_sweep_test.py -q
"""
import io
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import ROOT, web  # noqa: E402

WEBROOT = os.path.join(ROOT, "shiduh.org")


def read(rel):
    return io.open(os.path.join(WEBROOT, rel), encoding="utf-8", errors="replace").read()


def our_php_files():
    files = [os.path.join("api", n) for n in sorted(os.listdir(os.path.join(WEBROOT, "api")))
             if n.endswith(".php")]
    files += [n for n in sorted(os.listdir(WEBROOT)) if n.startswith("admin-") and n.endswith(".php")]
    return files


# ---------------------------------------------------------------- C1: логи ---

def test_htaccess_logging_config():
    """Каждый блок mod_php в .htaccess: ошибки пишутся в лог, не показываются."""
    ht = read(".htaccess")
    blocks = re.findall(r"<IfModule (mod_php[^>]*)>(.*?)</IfModule>", ht, re.S)
    assert blocks, "в .htaccess нет блоков mod_php"
    for name, body in blocks:
        assert re.search(r"php_flag\s+log_errors\s+on", body), name + ": нет log_errors on"
        assert re.search(r"php_flag\s+display_errors\s+off", body), name + ": display_errors должен быть off"
        m = re.search(r"php_value\s+error_reporting\s+(\S+)", body)
        assert m, name + ": нет error_reporting"
        assert m.group(1) != "0", name + ": error_reporting 0 — прод слеп к фаталам"


def test_fatal_in_api_returns_json_500_without_details():
    """Фатал в эндпоинте → JSON {"error":…} без имени функции/файла в теле."""
    code = ("require '/var/www/html/api/admin_lib.php'; shiduh_bootstrap_api(); "
            "undefined_fn_for_test();")
    r = web("php", "-d", "display_errors=0", "-d", "log_errors=0", "-r", code)
    out = (r.stdout or "")
    assert '"error"' in out, "нет JSON-ответа об ошибке: %r" % out[:200]
    assert "Внутренняя ошибка сервера" in out, out[:200]
    assert "undefined_fn_for_test" not in out, "деталь фатала утекла в ответ"
    assert "admin_lib.php" not in out, "путь файла утёк в ответ"


def test_shiduh_log_writes_channel_prefix():
    """shiduh_log('ai', 'текст') → в error_log строка '[ai] текст'."""
    code = ("require '/var/www/html/api/admin_lib.php'; "
            "shiduh_log('ai', 'проверка-канала-7731');")
    r = web("php", "-d", "log_errors=1", "-d", "error_log=/dev/stderr", "-r", code)
    err = (r.stderr or "")
    assert "[ai] проверка-канала-7731" in err, "нет префикса канала в логе: %r" % err[:300]


def test_no_raw_error_log_in_our_code():
    """Единая точка логирования: сырой error_log() только внутри shiduh_log()."""
    offenders = []
    for rel in our_php_files():
        if rel == os.path.join("api", "admin_lib.php"):
            continue   # здесь живёт сама shiduh_log()
        for i, line in enumerate(read(rel).split("\n"), 1):
            if "error_log(" in line:
                offenders.append("%s:%d" % (rel, i))
    assert not offenders, "сырой error_log() вне shiduh_log(): " + ", ".join(offenders)


def test_json_endpoints_register_fatal_handler():
    """Каждый JSON-эндпоинт вызывает shiduh_bootstrap_api() до загрузки ядра —
    иначе фатал внутри include отдаст пустой ответ 200 (ob_start ядра)."""
    missing = []
    for rel in our_php_files():
        src = read(rel)
        if "connect_io.php" not in src or "Content-Type: application/json" not in src:
            continue   # библиотеки и HTML-страницы
        pos_boot = src.find("shiduh_bootstrap_api()")
        pos_core = src.find("connect_io.php")
        if pos_boot < 0 or pos_boot > pos_core:
            missing.append(rel)
    assert not missing, "нет shiduh_bootstrap_api() до connect_io: " + ", ".join(missing)
