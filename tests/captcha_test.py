# -*- coding: utf-8 -*-
"""
C7 этапа «До идеала»: серверная проверка SmartCaptcha.

В формах регистрации и обратной связи стоит виджет Яндекса, но проверялся он
только в браузере: кто шлёт POST напрямую (боты, спам), капчу не замечал.
Теперь api/captcha_lib.php проверяет токен на сервере, а AddCond форм
отменяет запись, если проверка не прошла.

Пока серверный секрет не задан — пропускаем всех (регистрация не должна
вставать из-за ненастроенной капчи). Проверка обращается к API Яндекса;
в тестах его заменяет маршрут /validate фейкового сервера (good-token → ok,
slow → 5 c, иначе failed; фейк записывает присланные secret/ip).

    python -m pytest tests/captcha_test.py -q
"""
import json
import os
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (BASE, FAKE_BASE, NoRedirect, ROOT, dbq,  # noqa: E402
                      start_fake_server, stop_fake_server)

DOCKER = r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
VALIDATE = FAKE_BASE + "/validate"          # изнутри контейнера — 127.0.0.1:8099
ADDCOND = os.path.join(ROOT, "shiduh.org", "netcat_template", "class")
MARK = "captcha-test-193-marker-60606"


def setup_module(module):
    start_fake_server()


def teardown_module(module):
    stop_fake_server()
    clear_captcha_settings()
    dbq("DELETE FROM `Message193` WHERE info LIKE '%%%s%%'" % MARK)


def set_captcha(secret):
    for k, v in (("secret", secret), ("validate_url", VALIDATE), ("fail_open", "0")):
        dbq("DELETE FROM `Settings` WHERE Module='captcha' AND `Key`='%s' AND Catalogue_ID=0" % k)
        dbq("INSERT INTO `Settings` SET Module='captcha', `Key`='%s', Value='%s', Catalogue_ID=0" % (k, v))


def clear_captcha_settings():
    dbq("DELETE FROM `Settings` WHERE Module='captcha' AND `Key` IN ('secret','validate_url','fail_open')")


def submit_feedback(token=None, info=None):
    """POST формы обратной связи (класс 193) гостем через add.php."""
    fields = {"admin_mode": "", "catalogue": "1", "cc": "11", "sub": "14",
              "posting": "1", "curPos": "0", "f_Parent_Message_ID": "0",
              "f_name": "Гость", "f_email": "guest@example.local",
              "f_info": info or ("вопрос " + MARK)}
    if token is not None:
        fields["smart-token"] = token
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + "/netcat/add.php", data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=60)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def feedback_count(info):
    return int(dbq("SELECT COUNT(*) FROM `Message193` WHERE info='%s'" % info) or 0)


def php(code):
    """Выполнить PHP в web-контейнере, вернуть stdout (без warning-строк лога)."""
    r = subprocess.run([DOCKER, "compose", "exec", "-T", "web", "php", "-r", code],
                       cwd=ROOT, capture_output=True, text=True, timeout=60)
    return "\n".join(ln for ln in (r.stdout or "").splitlines()
                     if "[captcha]" not in ln and not ln.startswith("["))


def verify(token, secret="S3CR3T", fail_open="true", timeout=3):
    code = (
        "require '/var/www/html/api/captcha_lib.php';"
        "$cfg = array('secret'=>%r,'validate_url'=>%r,'timeout'=>%d,'fail_open'=>%s);"
        "echo json_encode(captcha_verify(%r, '203.0.113.7', $cfg));"
        % (secret, VALIDATE, timeout, fail_open, token)
    )
    out = php(code)
    return json.loads(out.strip().splitlines()[-1])


# ---------------------------------------------------------------- проверка ---

def test_no_secret_skips():
    r = verify("whatever", secret="")
    assert r["ok"] is True and r["code"] == "skipped", r


def test_good_token_passes():
    r = verify("good-token")
    assert r["ok"] is True and r["code"] == "ok", r


def test_bad_token_rejected():
    r = verify("definitely-not-solved")
    assert r["ok"] is False and r["code"] == "failed", r


def test_missing_token_rejected():
    r = verify("")
    assert r["ok"] is False and r["code"] == "no_token", r


def test_secret_and_ip_reach_yandex():
    """Секрет и IP уходят сервису, а не остаются на клиенте. Идёт ДО slow-
    тестов: фейк однопоточный, и висящий 5-секундный запрос перезаписал бы
    записанное этим тестом."""
    verify("good-token", secret="MY-SERVER-SECRET")
    out = php("echo @file_get_contents(sys_get_temp_dir().'/fake_captcha.json');")
    sent = json.loads(out.strip().splitlines()[-1])
    assert sent["secret"] == "MY-SERVER-SECRET", sent
    assert sent["ip"] == "203.0.113.7", sent
    assert sent["token"] == "good-token", sent


def test_slow_service_fail_open():
    r = verify("slow", fail_open="true", timeout=2)
    assert r["ok"] is True and r["code"] == "fail_open", r


def test_slow_service_fail_closed():
    # пауза, чтобы прошлый 5-секундный slow-запрос отпустил однопоточный фейк
    import time
    time.sleep(5)
    r = verify("slow", fail_open="false", timeout=2)
    assert r["ok"] is False and r["code"] == "unreachable", r


# ------------------------------------------------------------ хуки в формах ---

def test_addcond_forms_call_guard():
    """Заслон подключён в регистрации и трёх формах обратной связи."""
    forms = ["netcat_module_auth_user/AddCond.html", "193/AddCond.html",
             "196/AddCond.html", "201/AddCond.html"]
    for rel in forms:
        src = open(os.path.join(ADDCOND, rel), encoding="utf-8").read()
        assert "captcha_guard_addcond" in src, rel + ": нет серверной проверки капчи"
        assert "captcha_lib.php" in src, rel + ": не подключён captcha_lib"
    # подшаблоны компонента User (208, 232, …) — свой AddCond был обходом:
    # POST с cc такого инфоблока шёл мимо капчи и порога 18+
    import glob
    subs = glob.glob(os.path.join(ADDCOND, "netcat_module_auth_user", "[0-9]*", "AddCond.html"))
    assert len(subs) >= 10, "подшаблоны регистрации не найдены"
    for path in subs:
        src = open(path, encoding="utf-8").read()
        assert "require dirname(__DIR__) . '/AddCond.html'" in src, path + ": свой AddCond без заслона"


def test_validate_url_allowlist():
    """validate_url из Settings уводит секрет POST-ом; чужой хост подменять
    нельзя — принимаются только домен Яндекса и loopback (dev-фейк)."""
    code = (
        "require '/var/www/html/api/captcha_lib.php';"
        "$cases = array('https://smartcaptcha.yandexcloud.net/validate'=>1,"
        "  'https://ai.eu-central.smartcaptcha.yandexcloud.net/validate'=>1,"
        "  'http://127.0.0.1:8099/validate'=>1, 'http://localhost/x'=>1,"
        "  'https://evil.example/validate'=>0, 'http://169.254.169.254/'=>0,"
        "  'https://smartcaptcha.yandexcloud.net.evil.com/x'=>0);"
        "foreach ($cases as $u=>$want) {"
        "  $got = captcha_host_allowed($u) ? 1 : 0;"
        "  if ($got !== $want) { echo 'FAIL '.$u.' got='.$got; exit; } }"
        "echo 'ALL_OK';"
    )
    r = subprocess.run([DOCKER, "compose", "exec", "-T", "web", "php", "-r", code],
                       cwd=ROOT, capture_output=True, text=True, timeout=60)
    assert "ALL_OK" in r.stdout, "allowlist хостов неверен: " + (r.stdout or r.stderr)


# --------------------------------------------------- блокировка формы (193) ---

def test_feedback_blocked_without_token_when_secret_set():
    set_captcha("SRV-SECRET")
    info = "нет токена " + MARK
    before = feedback_count(info)
    submit_feedback(token=None, info=info)
    assert feedback_count(info) == before, "форма прошла без токена при заданном секрете"


def test_feedback_passes_with_good_token():
    set_captcha("SRV-SECRET")
    info = "хороший токен " + MARK
    before = feedback_count(info)
    submit_feedback(token="good-token", info=info)
    assert feedback_count(info) == before + 1, "форма с хорошим токеном не прошла"


def test_feedback_rejected_with_bad_token():
    set_captcha("SRV-SECRET")
    info = "плохой токен " + MARK
    before = feedback_count(info)
    submit_feedback(token="not-solved", info=info)
    assert feedback_count(info) == before, "форма прошла с неверным токеном"


def test_feedback_passes_when_no_secret():
    clear_captcha_settings()
    info = "без секрета " + MARK
    before = feedback_count(info)
    submit_feedback(token=None, info=info)
    assert feedback_count(info) == before + 1, "без секрета капча заблокировала форму"


def test_guard_does_not_touch_authorized_user():
    """Капча — от ботов на публичных формах; авторизованному она ни к чему."""
    code = (
        "require '/var/www/html/api/captcha_lib.php';"
        "$GLOBALS['current_user'] = array('User_ID'=>42);"
        "$warn=''; $posting=1;"
        # даже с заданным секретом и без токена — не блокируем вошедшего
        "putenv('X');"
        "captcha_guard_addcond($warn, $posting);"
        "echo $posting.'|'.$warn;"
    )
    out = php(code).strip().splitlines()[-1]
    assert out.startswith("1|"), "капча заблокировала авторизованного: " + out
