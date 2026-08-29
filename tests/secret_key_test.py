# -*- coding: utf-8 -*-
"""
Тесты стойкости SecretKey (ROADMAP B-3, находка AUDIT №4).

На проде SecretKey = md5('') — общеизвестная константа. Токен NetCat это
md5($user_id . $key), поэтому со знанием ключа валидный nc_token для любого
пользователя вычисляется в одну строку, и CSRF-защита движка не работает.

Проверяем:
  * ключ не равен известным слабым значениям и достаточно длинный;
  * миграция ротации идемпотентна — стойкий ключ она не трогает;
  * наши эндпоинты не полагаются на nc_token (у них свой заслон по Origin),
    поэтому ротация их не ломает.

    python -m pytest tests/secret_key_test.py -q
"""
import hashlib
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import dbq, login, post_json, web

WEAK = {
    "",
    hashlib.md5(b"").hexdigest(),          # d41d8cd98f00b204e9800998ecf8427e
    hashlib.md5(b"0").hexdigest(),
}


def secret_key():
    return dbq("SELECT `Value` FROM `Settings` "
               "WHERE `Key`='SecretKey' AND Module='system'").strip()


def test_secret_key_is_not_well_known():
    key = secret_key()
    assert key, "SecretKey не задан"
    assert key not in WEAK, "SecretKey — общеизвестное значение: " + key
    assert len(key) >= 32, "SecretKey слишком короткий (%d символов)" % len(key)


def test_token_is_not_forgeable_from_empty_md5():
    """Токен пользователя не должен совпадать с посчитанным по md5('')."""
    key = secret_key()
    uid = 50
    forged = hashlib.md5((str(uid) + hashlib.md5(b"").hexdigest()).encode()).hexdigest()
    real = hashlib.md5((str(uid) + key).encode()).hexdigest()
    assert forged != real, "токен вычисляется по общеизвестному ключу"


def test_rotation_migration_is_idempotent():
    """Повторный прогон не должен менять уже стойкий ключ."""
    before = secret_key()
    out = (web("php", "/var/www/html/migrations/migrate.php", "status").stdout or "")
    assert "019_rotate_secret_key" in out, "миграция ротации не зарегистрирована"
    # up ещё раз: применённая миграция пропускается, ключ на месте
    web("php", "/var/www/html/migrations/migrate.php", "up")
    assert secret_key() == before, "повторный прогон сменил стойкий ключ"


def test_our_endpoints_do_not_depend_on_nc_token():
    """Наши API защищены Origin-заслоном, а не nc_token: ротация их не ломает."""
    sid = login("u3f9bb8c20b01@example.local")     # шадхан
    code, data = post_json("/api/match_list.php", sid, {"anketa_id": 2033, "limit": 5})
    assert code == 200, "эндпоинт отказал без nc_token: %d %s" % (code, data)
    # и при этом чужой Origin по-прежнему отбивается
    code, _ = post_json("/api/match_list.php", sid, {"anketa_id": 2033},
                        origin="https://evil.example.com")
    assert code == 403, "Origin-заслон не работает"
