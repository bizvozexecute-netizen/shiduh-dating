# -*- coding: utf-8 -*-
"""
C9 этапа «До идеала»: экран «ИИ и подключение».

До этого ключ провайдера вводился только правкой таблицы Settings, а о цене
диалогов узнавали из счёта провайдера. Теперь: форма с границами значений,
проверка подключения (дотягиваемся ли до провайдера с этим ключом, жив ли
RAG) и расход из ai_usage_log.

Что защищаем:
  - секреты не покидают сервер: в HTML и в JSON — только хвост из четырёх
    символов; пустое поле секрета = «оставить как есть»;
  - ошибка в любом поле — не сохраняется ничего: частичное сохранение
    оставило бы форму в состоянии, которое администратор не видел;
  - ответ проверки подключения не содержит фрагментов ключа и внутренних
    адресов, даже когда провайдер вернул их в тексте ошибки.

    python -m pytest tests/admin_ai_test.py -q
"""
import os
import re
import sys
import time

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    FAKE_BASE, FAKE_KEY, clear_ai_settings, dbq, get_text, login, post_json,
    set_fake_mode, setting, start_fake_server, stop_fake_server, use_fake_provider,
)

ADMIN = "u5760fc1c6357@example.local"
SHADHAN = "u3f9bb8c20b01@example.local"
PAGE = "/admin-ai.php"
SAVE = "/api/ai_settings_save.php"
PING = "/api/ai_ping.php"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def setting_of(module, key):
    return dbq("SELECT IFNULL(Value,'') FROM `Settings` WHERE Module='%s' AND `Key`='%s' AND Catalogue_ID=0"
               % (module, key))


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    set_fake_mode("ok")


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    dbq("DELETE FROM `Settings` WHERE Module IN ('captcha','security') AND `Key` IN ('secret','sitekey','fail_open','superadmin_ids')")
    dbq("DELETE FROM `ai_usage_log` WHERE `model`='admin-ai-test'")


def save(fields, who=ADMIN):
    return post_json(SAVE, sid_of(who), fields)


# ---------------------------------------------------------------- страница ---

def test_page_shows_form_without_secrets():
    code, html = get_text(PAGE, sid_of(ADMIN))
    assert code == 200, code
    assert FAKE_KEY not in html, "ключ провайдера напечатан на странице"
    assert "сейчас: …" + FAKE_KEY[-4:] in html, "нет хвоста ключа — администратор не поймёт, какой ключ стоит"
    for name in ("provider", "api_key", "base_url", "model", "temperature", "max_tokens",
                 "rag_url", "captcha_secret", "superadmin_ids"):
        assert 'name="%s"' % name in html, "нет поля " + name
    assert 'id="btnPingProvider"' in html and 'id="btnPingRag"' in html
    assert "Расход за 30 дней" in html
    assert 'style="' not in html, "инлайновый стиль — CSP его не пустит"


def test_page_denied_to_shadhan():
    code, html = get_text(PAGE, sid_of(SHADHAN))
    assert code == 403, code


def test_captcha_banner_until_secret_set():
    dbq("DELETE FROM `Settings` WHERE Module='captcha' AND `Key`='secret'")
    _, html = get_text(PAGE, sid_of(ADMIN))
    assert "Серверный секрет SmartCaptcha не задан" in html, "нет предупреждения о декоративной капче"
    code, js = save({"captcha_secret": "ysc2_serverSECRET9999"})
    assert code == 200, js
    _, html = get_text(PAGE, sid_of(ADMIN))
    assert "Серверный секрет SmartCaptcha не задан" not in html, "предупреждение осталось после ввода секрета"
    assert "ysc2_serverSECRET9999" not in html, "секрет капчи напечатан на странице"
    assert "…9999" in html, "нет хвоста секрета капчи"


# ------------------------------------------------------------- сохранение ---

def test_save_round_trip():
    code, js = save({"model": "test/other", "temperature": "0.4", "max_tokens": "900",
                     "rate_limit_per_hour": "45", "greet_with_llm": "1"})
    assert code == 200, js
    assert set(js["saved"]) >= {"model", "temperature", "max_tokens", "rate_limit_per_hour", "greet_with_llm"}
    assert setting_of("ai", "model") == "test/other"
    assert setting_of("ai", "temperature") == "0.4"
    assert setting_of("ai", "max_tokens") == "900"
    assert setting_of("ai", "greet_with_llm") == "1"
    # возвращаем то, на что рассчитывают соседние наборы — и проверяем, что
    # восстановление прошло: иначе greet_with_llm=1 утёк бы в следующие наборы
    code, js = save({"model": "test/model", "temperature": "0.7", "max_tokens": "700",
                     "rate_limit_per_hour": "500", "greet_with_llm": "0"})
    assert code == 200, "восстановление не прошло: %s" % js
    setting("rate_limit_per_hour", 1000)      # 1000 вне границ формы — только напрямую


def test_empty_key_keeps_previous():
    code, js = save({"api_key": "", "model": "test/model"})
    assert code == 200, js
    assert "api_key" not in js["saved"], "пустой ключ засчитан как изменение"
    assert setting_of("ai", "api_key") == FAKE_KEY, "прежний ключ затёрт пустым"
    assert js["api_key"]["set"] is True and js["api_key"]["tail"] == "…" + FAKE_KEY[-4:], js["api_key"]


def test_key_never_returned_whole():
    code, js = save({"api_key": "sk-or-v1-NEWKEY-abcdef0123"})
    assert code == 200, js
    raw = str(js)
    assert "NEWKEY" not in raw, "ключ вернулся в ответе целиком"
    assert js["api_key"]["tail"] == "…0123", js["api_key"]
    assert setting_of("ai", "api_key") == "sk-or-v1-NEWKEY-abcdef0123"
    setting("api_key", FAKE_KEY)


def test_invalid_field_saves_nothing():
    before = setting_of("ai", "model")
    code, js = save({"model": "will-not-be-saved", "temperature": "9"})
    assert code == 400, js
    assert "temperature" in js.get("fields", {}), js
    assert setting_of("ai", "model") == before, "при ошибке в одном поле сохранилось другое"


@pytest.mark.parametrize("field,value", [
    ("base_url", "ftp://x"),
    ("base_url", "https://user:pass@evil.example/v1"),
    ("base_url", "not a url"),
    ("max_tokens", "5"),
    ("timeout", "0"),
    ("provider", "openai"),
    ("superadmin_ids", "1,abc"),
    ("api_key", "has space in key"),
    ("api_key", "short"),                                   # секрет короче 8 символов
    ("base_url", "http://evil.example/v1"),                 # http вне localhost
    ("base_url", "http://169.254.169.254/latest/"),         # метаданные облака
    ("base_url", "https://openrouter.ai/api/v1?x=1"),       # query
    ("rag_url", "http://10.0.0.5:8000"),                    # http на чужой хост
])
def test_out_of_range_rejected(field, value):
    code, js = save({field: value})
    assert code == 400, "%s=%r принято: %s" % (field, value, js)
    assert field in js.get("fields", {}), js


def test_superadmin_ids_normalised():
    code, js = save({"superadmin_ids": " 1, 119 ,43, 1 "})
    assert code == 200, js
    assert setting_of("security", "superadmin_ids") == "1,119,43"


def test_unknown_keys_ignored():
    code, js = save({"model": "test/model", "hacker": "1", "Module": "system"})
    assert code == 200, js
    assert js["saved"] == ["model"], js


def test_save_requires_admin_and_csrf():
    code, js = save({"model": "x"}, who=SHADHAN)
    assert code == 403, code
    code, js = post_json(SAVE, sid_of(ADMIN), {"model": "x"}, csrf=False)
    assert code == 403, "принято без токена"
    assert setting_of("ai", "model") == "test/model"


# ------------------------------------------------------ проверка подключения ---

def test_ping_provider_ok():
    set_fake_mode("ok")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "provider"})
    assert code == 200, js
    assert js["ok"] is True, js
    assert js["models_count"] == 2 and js["model_known"] is True, js
    assert isinstance(js["latency_ms"], int)


def test_ping_provider_failure_hides_details():
    """Провайдер вернул текст с фрагментом ключа — наружу он не уходит."""
    set_fake_mode("always_500")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "provider"})
    set_fake_mode("ok")
    assert code == 200, js
    assert js["ok"] is False and js["http"] == 500, js
    assert "sk-" not in str(js) and "127.0.0.1" not in str(js), "детали отказа утекли: %s" % js


def test_ping_provider_bad_key():
    setting("api_key", "sk-wrong")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "provider"})
    setting("api_key", FAKE_KEY)
    assert js["ok"] is False and js.get("code") == "unauthorized", js


def test_ping_provider_stub():
    setting("provider", "stub")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "provider"})
    setting("provider", "openrouter")
    assert js["ok"] is False and js.get("code") == "stub", js


def test_ping_rag_dead_port_fails_fast():
    setting("rag_url", "http://127.0.0.1:9")
    t0 = time.time()
    code, js = post_json(PING, sid_of(ADMIN), {"target": "rag"})
    dbq("DELETE FROM `Settings` WHERE Module='ai' AND `Key`='rag_url'")
    assert code == 200 and js["ok"] is False, js
    assert time.time() - t0 < 6, "проверка мёртвого RAG висела дольше 6 секунд"


def test_ping_rag_off():
    dbq("DELETE FROM `Settings` WHERE Module='ai' AND `Key`='rag_url'")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "rag"})
    assert js["ok"] is False and js.get("code") == "off", js


def test_ping_bad_target_and_rights():
    code, js = post_json(PING, sid_of(ADMIN), {"target": "x"})
    assert code == 400, js
    code, js = post_json(PING, sid_of(SHADHAN), {"target": "provider"})
    assert code == 403, code


# ------------------------------------------------------------------ расход ---

def test_usage_stats_match_log():
    dbq("DELETE FROM `ai_usage_log` WHERE `model`='admin-ai-test'")
    dbq("INSERT INTO `ai_usage_log` (user_id, kind, provider, model, prompt_tokens, completion_tokens, "
        "total_tokens, latency_ms, attempts, streamed, ok, error, created_at) VALUES "
        "(44, 'chat', 'openrouter', 'admin-ai-test', 100, 50, 150, 900, 1, 0, 1, '', NOW()), "
        "(44, 'summary', 'openrouter', 'admin-ai-test', 200, 100, 300, 1200, 1, 0, 1, '', NOW()), "
        "(57, 'chat', 'openrouter', 'admin-ai-test', 10, 5, 15, 300, 2, 0, 0, 'boom', NOW())")
    calls_7 = int(dbq("SELECT COUNT(*) FROM `ai_usage_log` WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"))
    tokens_30 = int(dbq("SELECT IFNULL(SUM(total_tokens),0) FROM `ai_usage_log` WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"))
    errors_30 = int(dbq("SELECT COUNT(*) FROM `ai_usage_log` WHERE ok=0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"))
    _, html = get_text(PAGE, sid_of(ADMIN))
    got_calls = int(re.search(r'id="statCalls7">(\d+)<', html).group(1))
    got_tokens = int(re.search(r'id="statTokens30">([\d ]+)<', html).group(1).replace(" ", ""))
    got_errors = int(re.search(r'id="statErrors30">(\d+)<', html).group(1))
    assert got_calls == calls_7, (got_calls, calls_7)
    assert got_tokens == tokens_30, (got_tokens, tokens_30)
    assert got_errors == errors_30, (got_errors, errors_30)
    assert "ID 44" in html and "ID 57" in html, "самые активные не показаны"
    assert "@example.local" not in html.split("Самые активные")[1][:3000], "в расходе показаны email, а не ID"


# ------------------------------------------------- увод ключа через адрес ---

def test_base_url_host_change_resets_key():
    """Тот, кто может править адрес API, не должен уводить ключ заказчика на
    свой хост, не зная его: смена хоста без нового ключа обнуляет ключ и
    переводит провайдера на заглушку."""
    code, js = save({"base_url": "https://evil.example/v1"})
    assert code == 200, js
    assert js["key_reset"] is True and "ключ сброшен" in js["note"], js
    assert setting_of("ai", "api_key") == "", "ключ пережил смену хоста"
    assert setting_of("ai", "provider") == "stub", "провайдер остался openrouter без ключа"
    # возвращаем стенд: адрес + ключ одним сохранением — сброса нет
    code, js = save({"base_url": FAKE_BASE, "api_key": FAKE_KEY, "provider": "openrouter"})
    assert code == 200 and js["key_reset"] is False, js
    assert setting_of("ai", "api_key") == FAKE_KEY


def test_base_url_same_host_keeps_key():
    code, js = save({"base_url": FAKE_BASE + "/"})
    assert code == 200 and js["key_reset"] is False, js
    assert setting_of("ai", "api_key") == FAKE_KEY
    assert setting_of("ai", "base_url") == FAKE_BASE, "хвостовой слэш не срезан"


def test_chat_completions_suffix_stripped():
    code, js = save({"base_url": FAKE_BASE + "/chat/completions"})
    assert code == 200, js
    assert setting_of("ai", "base_url") == FAKE_BASE


def test_openrouter_without_key_rejected():
    setting("api_key", "")
    code, js = save({"provider": "openrouter"})
    setting("api_key", FAKE_KEY)
    assert code == 400 and "api_key" in js.get("fields", {}), js


def test_captcha_secret_empty_keeps_previous():
    save({"captcha_secret": "ysc2_keepme_secret_0001"})
    code, js = save({"captcha_secret": "", "captcha_sitekey": "ysc1_site"})
    assert code == 200, js
    assert setting_of("captcha", "secret") == "ysc2_keepme_secret_0001", "секрет капчи затёрт пустым"


# ------------------------------------------------------ суперадминистраторы ---

SUPERVISOR = "ud68292209b5b@example.local"   # User 119, AdminType 6


def test_superadmin_list_only_by_superadmin():
    # пользователь 1 — суперадминистратор по умолчанию; 119 — только при
    # перечислении. Сужаем список до 1 и пробуем править от 119.
    code, js = save({"superadmin_ids": "1"})
    assert code == 200, js
    code, js = save({"superadmin_ids": "1,119"}, who=SUPERVISOR)
    assert code == 403, "супервизор вписал себя в суперадминистраторы: %s" % js
    assert setting_of("security", "superadmin_ids") == "1"
    # себя убрать нельзя
    code, js = save({"superadmin_ids": "119"})
    assert code == 400 and "superadmin_ids" in js.get("fields", {}), js
    save({"superadmin_ids": "1,119,43"})


# ------------------------------------------------- подмена ответа провайдера ---

def test_ping_provider_non_json_200_is_failure():
    """200 со страницей прокси — это не связь, а подмена; и ключ из такой
    страницы наружу не уходит."""
    set_fake_mode("models_garbage")
    code, js = post_json(PING, sid_of(ADMIN), {"target": "provider"})
    set_fake_mode("ok")
    assert code == 200 and js["ok"] is False and js.get("code") == "bad_body", js
    assert FAKE_KEY not in str(js) and "sk-" not in str(js)
