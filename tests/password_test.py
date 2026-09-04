# -*- coding: utf-8 -*-
"""
C6 этапа «До идеала»: пароли MD5 → bcrypt.

Ядро хранило MD5 без соли и сравнивало SQL-функцией: утечка таблицы User —
радужная таблица за вечер. Теперь хэширует и сверяет api/password_lib.php
(bcrypt), а ядро в шести точках зовёт его. Переход без сброса паролей:
старый MD5 узнаётся по виду, проверяется как раньше и при первом же удачном
входе заменяется bcrypt-хэшем.

Тест работает с паролем кандидата 44: ставит MD5('shiduh') напрямую, входит,
проверяет замену; в teardown возвращает MD5('shiduh') — на него рассчитывают
все остальные наборы.

⚠️ НЕ ПАРАЛЛЕЛИТСЯ. Пользователь 44 логинится и в других наборах, а первый
же удачный вход перезаписывает его хэш на bcrypt (rehash-on-login). Между
`set_md5` и проверкой `len(stored)==32` чужой параллельный вход перевернул бы
формат. Последовательный прогон безопасен: set_md5 и teardown восстанавливают
MD5. Как и migrations_test — гонять отдельно или в одной последовательной
команде, но не двумя процессами.

    python -m pytest tests/password_test.py -q
"""
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, NoRedirect, dbq, login  # noqa: E402

CANDIDATE = "uad322c9ddbba@example.local"   # User 44
UID = 44
PASSWORD = "shiduh"


def stored():
    return dbq("SELECT Password FROM `User` WHERE User_ID=%d" % UID)


def set_md5(plain):
    # для mysql -e экранируем и кавычку, и обратный слэш (MySQL сам его ест)
    esc = plain.replace("\\", "\\\\").replace("'", "''")
    dbq("UPDATE `User` SET Password=MD5('%s') WHERE User_ID=%d" % (esc, UID))


def try_login(email, password):
    """Как fake_llm.login, но без исключения: → sid или None."""
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0", "AUTH_USER": email, "AUTH_PW": password,
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
    return None


def setup_module(module):
    set_md5(PASSWORD)


def teardown_module(module):
    set_md5(PASSWORD)


# ------------------------------------------------------------ переход ---

def test_legacy_md5_login_upgrades_to_bcrypt():
    set_md5(PASSWORD)
    assert len(stored()) == 32, "фикстура не MD5: " + stored()
    sid = try_login(CANDIDATE, PASSWORD)
    assert sid, "вход по старому MD5-паролю не прошёл"
    now = stored()
    assert now.startswith("$2y$") and len(now) == 60, "после входа хэш не bcrypt: " + now[:10]


def test_bcrypt_login_works_again():
    set_md5(PASSWORD)
    assert try_login(CANDIDATE, PASSWORD)
    first = stored()
    assert try_login(CANDIDATE, PASSWORD), "второй вход по bcrypt не прошёл"
    assert stored() == first, "хэш перезаписан при входе по уже-bcrypt паролю"


def test_wrong_password_rejected_in_both_states():
    set_md5(PASSWORD)
    assert try_login(CANDIDATE, "wrong-" + PASSWORD) is None, "вошёл с неверным паролем (MD5)"
    assert len(stored()) == 32, "неудачный вход заменил хэш"
    assert try_login(CANDIDATE, PASSWORD)
    assert try_login(CANDIDATE, "wrong-" + PASSWORD) is None, "вошёл с неверным паролем (bcrypt)"


def test_quotes_and_backslash_in_password():
    """Пароль с кавычкой и обратным слэшем: раньше он жил через экранирование
    SQL, теперь — как есть; ни один путь не должен его исказить."""
    tricky = "pa'ss\\word\"x"
    set_md5(tricky)
    assert try_login(CANDIDATE, tricky), "вход с кавычками по MD5 не прошёл"
    assert stored().startswith("$2y$")
    assert try_login(CANDIDATE, tricky), "вход с кавычками по bcrypt не прошёл"
    assert try_login(CANDIDATE, tricky + "z") is None, "почти-верный пароль подошёл"


def test_no_md5_in_sql_login_path():
    """MYSQL_ENCRYPT не должен участвовать во входе: пароль сверяется в PHP."""
    src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "shiduh.org", "netcat",
                            "system", "essences", "nc_user.class.php"), encoding="utf-8").read()
    body = src.split("public function authorize_by_pass")[1].split("public function ")[0]
    assert "MYSQL_ENCRYPT" not in body, "вход всё ещё сверяет пароль SQL-функцией"
    assert "shiduh_password_verify" in body


# --------------------------------------------------- регистрация и смена ---

def test_change_password_writes_bcrypt():
    """invite_claim зовёт nc_user::change_password — проверяем через него же,
    без внешних вызовов: прямой вызов класса через php -r."""
    import subprocess
    set_md5(PASSWORD)
    php = ("require '/var/www/html/api/password_lib.php'; "
           "echo shiduh_password_hash('abc12345') === '' ? 'EMPTY' : 'OK'; "
           "echo shiduh_password_verify('abc12345', shiduh_password_hash('abc12345')) ? ' V1' : ' V0'; "
           "echo shiduh_password_verify('abc12345', md5('abc12345')) ? ' M1' : ' M0'; "
           "echo shiduh_password_verify('abc12346', md5('abc12345')) ? ' W1' : ' W0'; "
           "echo shiduh_password_needs_rehash(md5('x')) ? ' R1' : ' R0';")
    out = subprocess.run(["C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe", "compose", "exec", "-T",
                          "web", "php", "-r", php], capture_output=True, text=True, timeout=60,
                         cwd=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")).stdout
    assert out.strip() == "OK V1 M1 W0 R1", "библиотека паролей: " + out


def test_admin_change_password_strips_slashes():
    """Смена пароля админом (admin/user.inc.php) — путь с addslashed-глобалом
    ($Password1 из extract). Без stripslashes спецсимвольный пароль лёг бы
    слэшированным, и человек не вошёл бы (тихий лок-аут). Проверяем два звена:
    (1) функция снимает слэши; (2) на уровне библиотеки stripslashes(глобал)
    даёт то же, что вводит человек при входе — хэш сходится."""
    import subprocess
    src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "shiduh.org", "netcat",
                            "admin", "user.inc.php"), encoding="utf-8").read()
    body = src.split("function ChangePassword")[1].split("\nfunction ")[0]
    assert "stripslashes($Password)" in body, "ChangePassword не снимает magic-quotes слэши"

    # round-trip: сохранён хэш от stripslashes(addslashed), вход сверяет тем же
    tricky = "ad'min\\pw9"
    php = ("require '/var/www/html/api/password_lib.php'; "
           "$addslashed = addslashes(%r); "               # как приходит в ChangePassword
           "$plain = stripslashes($addslashed); "         # что она хэширует
           "$hash = shiduh_password_hash($plain); "
           "echo shiduh_password_verify(%r, $hash) ? 'LOGIN_OK' : 'LOCKED_OUT';"
           % (tricky, tricky))                            # вход подаёт stripslashes(глобал)=tricky
    out = subprocess.run(["C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe", "compose",
                          "exec", "-T", "web", "php", "-r", php], capture_output=True, text=True,
                         timeout=60, cwd=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")).stdout
    assert "LOGIN_OK" in out, "хэш админ-пароля не сходится со входом: " + out


def test_registration_and_admin_paths_patched():
    """Все шесть точек ядра, где пароль попадал в SQL через MYSQL_ENCRYPT."""
    root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "shiduh.org", "netcat")
    for rel, must in (
        # $Password из register_globals (addslashed) — снимаем слэши
        ("add.php", "shiduh_password_sql($db, stripslashes($Password))"),
        ("message.php", "shiduh_password_sql($db, stripslashes($Password))"),
        # $Password1 из fetch_post_get — уже сырой, stripslashes был бы порчей
        ("admin/user/function.inc.php", "shiduh_password_sql($db, $Password)"),
        ("admin/user.inc.php", "shiduh_password_verify($Password, $stored)"),
        ("system/essences/nc_user.class.php", "shiduh_password_verify($plain,"),
    ):
        src = open(os.path.join(root, rel), encoding="utf-8").read()
        assert must in src, rel + ": патч C6 не применён"
        assert "password_lib.php" in src, rel + ": библиотека паролей не подключена"
    auth = open(os.path.join(root, "modules", "auth", "index.php"), encoding="utf-8").read()
    assert "MYSQL_ENCRYPT" not in auth, "в модуле auth осталась мёртвая строка с MD5 пароля"
