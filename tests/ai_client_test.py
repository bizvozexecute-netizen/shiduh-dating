# -*- coding: utf-8 -*-
"""
Тесты клиента LLM и подключения его к чату (ROADMAP Э1.1).

Провайдер выбирается настройкой (Settings, Module='ai'):
  stub       — без ключа, отвечает заглушкой (режим по умолчанию сейчас)
  openrouter — OpenAI-совместимый API; ключ и base_url из настроек, не из кода

Реальный HTTP-путь проверяем против локального фейкового эндпоинта: он
отдаёт ответ в формате OpenAI и умеет изображать 500/429/4xx — так
проверяются ретраи с бэкоффом и то, что на 4xx повтора нет.

    python -m pytest tests/ai_client_test.py -q
"""
import json
import os
import shutil
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8080"
# Docker Desktop на Windows или docker из PATH (Ubuntu, tests/run_ubuntu.sh)
DOCKER = os.environ.get("SHIDUH_DOCKER") or shutil.which("docker") or r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "shiduh"
CANDIDATE = "u091ff2b36c83@example.local"     # User 50
CHAT_URL = "/api/ai_chat.php"

from fake_llm import (BASE, FAKE_BASE, FAKE_KEY, clear_ai_settings, dbq, fake_calls,
                      login, post_json, set_fake_mode, setting, start_fake_server,
                      stop_fake_server, use_fake_provider, web)

UID = None


def send(sid, text, origin=None):
    return post_json(CHAT_URL, sid, {"message": text}, origin=origin)


def history(marker):
    return dbq("SELECT role, tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
               "AND content LIKE '%%%s%%'" % (UID, marker))


def clear_chat():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)


def setup_module(module):
    global UID
    UID = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    start_fake_server()
    clear_ai_settings()
    clear_chat()


def teardown_module(module):
    clear_ai_settings()
    clear_chat()
    stop_fake_server()


# --- режим заглушки (без ключа) ---------------------------------------------

def test_stub_provider_by_default():
    clear_ai_settings()
    clear_chat()
    sid = login(CANDIDATE)
    code, data = send(sid, "тест заглушки 71828")
    assert code == 200, "чат не ответил: %s" % data
    assert "консультант" in data.get("reply", "").lower()
    rows = history("тест заглушки 71828")
    assert rows.startswith("user\t"), "сообщение пользователя не сохранено: " + rows


def test_stub_saves_assistant_reply():
    clear_ai_settings()
    clear_chat()
    sid = login(CANDIDATE)
    send(sid, "вопрос 31415")
    n = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='assistant'" % UID)
    assert n == "1", "ответ ассистента не сохранён"


# --- CSRF (Э1.1-e) -----------------------------------------------------------

def test_foreign_origin_forbidden():
    clear_chat()
    sid = login(CANDIDATE)
    code, data = send(sid, "csrf 27182", origin="https://evil.example.com")
    assert code == 403, "запрос с чужого Origin не отклонён: %s" % data
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d" % UID) == "0", \
        "межсайтовый запрос записал сообщение"


def test_own_origin_allowed():
    clear_chat()
    sid = login(CANDIDATE)
    code, _ = send(sid, "свой origin 99887", origin="http://localhost:8080")
    assert code == 200


# --- реальный HTTP-путь провайдера ------------------------------------------

def test_openrouter_path_returns_model_reply_and_tokens():
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    sid = login(CANDIDATE)
    code, data = send(sid, "живой вопрос 55555")
    assert code == 200, "провайдер не ответил: %s" % data
    assert "ФЕЙК-ОТВЕТ" in data.get("reply", ""), "ответ модели не доехал: %s" % data
    assert "живой вопрос 55555" in data["reply"], "модель не увидела сообщение пользователя"
    # учёт токенов (Э1.1-d): ответ ассистента несёт usage
    tok = dbq("SELECT tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
              "AND role='assistant' ORDER BY id DESC LIMIT 1" % UID)
    assert tok == "18", "токены не учтены: " + tok


def test_system_prompt_is_sent():
    # роль консультанта задаётся системным промптом (в Э1.2 — версионируемым)
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    sid = login(CANDIDATE)
    code, data = send(sid, "проверка промпта 88888")
    assert code == 200, "не ответил: %s" % data
    assert "консультант еврейского сервиса знакомств" in data.get("reply", ""), \
        "системный промпт не дошёл до модели: %s" % data


def test_saved_prompt_from_admin_reaches_model():
    """Промпт, сохранённый в админке (Э1.2), должен вытеснять встроенный."""
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    custom = "ПРОМПТ ИЗ АДМИНКИ 24680: отвечай коротко."
    dbq("DELETE FROM `ai_prompts` WHERE `key`='consultant_system'")
    esc = custom.replace("\\", "\\\\").replace("'", "''")
    dbq("INSERT INTO `ai_prompts` SET `key`='consultant_system', `content`='%s', "
        "`version`=1, `updated_by`=1, `updated_at`=NOW()" % esc)
    try:
        sid = login(CANDIDATE)
        code, data = send(sid, "чей промпт 13579")
        assert code == 200, "не ответил: %s" % data
        assert "ПРОМПТ ИЗ АДМИНКИ 24680" in data.get("reply", ""),             "модель получила не сохранённый промпт: %s" % data
    finally:
        dbq("DELETE FROM `ai_prompts` WHERE `key`='consultant_system'")


def test_safety_preamble_survives_admin_prompt():
    """
    Запрет собирать здоровье/доход — требование 152-ФЗ, а не настройка: он
    должен уходить модели даже если администратор переписал промпт целиком
    (иначе защита исчезала бы при первой же правке «под тон»).
    """
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    dbq("DELETE FROM `ai_prompts` WHERE `key`='consultant_system'")
    dbq("INSERT INTO `ai_prompts` SET `key`='consultant_system', "
        "`content`='Ты бот. Расспроси про здоровье и доход подробно.', "
        "`version`=1, `updated_by`=1, `updated_at`=NOW()")
    try:
        sid = login(CANDIDATE)
        code, data = send(sid, "проверка преамбулы 97531")
        assert code == 200, "не ответил: %s" % data
        prompt = data.get("reply", "")
        assert "Не спрашивайте" in prompt, "преамбула безопасности пропала: " + prompt
        assert "здоровье" in prompt.lower() and "доход" in prompt.lower()
        # промпт администратора при этом сохраняется
        assert "Ты бот" in prompt, "промпт администратора потерян: " + prompt
    finally:
        dbq("DELETE FROM `ai_prompts` WHERE `key`='consultant_system'")


def test_history_is_sent_to_model():
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    sid = login(CANDIDATE)
    send(sid, "первое сообщение 111")
    code, data = send(sid, "второе сообщение 222")
    assert code == 200
    # модель отвечает на последнее сообщение пользователя из переданной истории
    assert "второе сообщение 222" in data["reply"]


def test_retries_on_temporary_error():
    clear_chat()
    use_fake_provider()
    set_fake_mode("fail_then_ok", fail_first=2)   # 2 отказа, затем успех
    sid = login(CANDIDATE)
    code, data = send(sid, "ретрай 33333")
    assert code == 200, "клиент не пережил временную ошибку: %s" % data
    assert "ФЕЙК-ОТВЕТ" in data.get("reply", "")
    assert fake_calls() == 3, "ожидали 3 попытки (2 отказа + успех), было %d" % fake_calls()


def test_gives_up_after_retries():
    clear_chat()
    use_fake_provider()
    set_fake_mode("always_500")
    sid = login(CANDIDATE)
    code, data = send(sid, "всегда 500 44444")
    assert code == 503, "постоянная ошибка не отражена в ответе: %s" % data
    assert data.get("error"), "нет понятного сообщения об ошибке"
    assert fake_calls() == 3, "ожидали 3 попытки (1 + 2 ретрая), было %d" % fake_calls()
    # сообщение пользователя не теряется
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='user'" % UID) == "1"
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND role='assistant'" % UID) == "0"


def test_no_retry_on_client_error():
    clear_chat()
    use_fake_provider()
    set_fake_mode("bad_request")
    sid = login(CANDIDATE)
    code, data = send(sid, "битый запрос 66666")
    assert code == 503, "ошибка 4xx не отражена: %s" % data
    assert fake_calls() == 1, "на 4xx повтор не нужен, попыток: %d" % fake_calls()


def test_tokens_fallback_when_total_missing():
    # шлюз отдал только слагаемые — учёт не должен обнуляться
    clear_chat()
    use_fake_provider()
    set_fake_mode("no_total_tokens")
    sid = login(CANDIDATE)
    code, data = send(sid, "частичный учёт 12321")
    assert code == 200, "не ответил: %s" % data
    tok = dbq("SELECT tokens_used FROM `ai_chat_messages` WHERE user_id=%d "
              "AND role='assistant' ORDER BY id DESC LIMIT 1" % UID)
    assert tok == "9", "токены не досчитаны из слагаемых (5+4): " + tok


# --- защита расхода и приватность -------------------------------------------

def test_rate_limit_blocks_burst():
    # эндпоинт тратит деньги: превышение часового лимита отсекается
    clear_chat()
    use_fake_provider()
    set_fake_mode("ok")
    setting("rate_limit_per_hour", 3)
    sid = login(CANDIDATE)
    for i in range(3):
        code, data = send(sid, "в пределах лимита %d" % i)
        assert code == 200, "запрос %d отклонён раньше лимита: %s" % (i, data)
    calls_before = fake_calls()
    code, data = send(sid, "сверх лимита 99999")
    assert code == 429, "лимит не сработал: %s" % data
    assert data.get("error"), "нет понятного сообщения о лимите"
    assert fake_calls() == calls_before, "запрос сверх лимита ушёл к провайдеру (деньги)"
    # сообщение сверх лимита не сохраняется
    assert dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d "
               "AND content LIKE '%%сверх лимита 99999%%'" % UID) == "0"


def test_error_text_does_not_leak_provider_details():
    # текст ошибки провайдера может содержать кусок ключа и внутренний URL —
    # наружу отдаём обобщённое сообщение
    clear_chat()
    use_fake_provider()
    set_fake_mode("bad_request")
    sid = login(CANDIDATE)
    code, data = send(sid, "утечка 45454")
    assert code == 503, "ошибка не отражена: %s" % data
    err = data.get("error") or ""
    for leak in ("sk-Ab", "internal.example", "Incorrect API key"):
        assert leak not in err, "в ответ пользователю утёк фрагмент «%s»: %s" % (leak, err)


def test_error_text_does_not_leak_base_url():
    # сетевая ошибка curl содержит хост и порт провайдера — их тоже не отдаём
    clear_chat()
    use_fake_provider()
    setting("base_url", "http://127.0.0.1:9")   # закрытый порт → ошибка соединения
    setting("max_retries", 0)
    sid = login(CANDIDATE)
    code, data = send(sid, "сетевая ошибка 78787")
    assert code == 503, "сетевая ошибка не отражена: %s" % data
    err = data.get("error") or ""
    assert "127.0.0.1" not in err and ":9" not in err, "в ответ утёк адрес провайдера: " + err


def test_system_prompt_does_not_solicit_sensitive_data():
    # 152-ФЗ / политика X-2: здоровье, доход и медикаменты не собираем в чат,
    # который уходит во внешний LLM — для них есть поля анкеты
    clear_chat()
    use_fake_provider()
    set_fake_mode("echo_system")
    sid = login(CANDIDATE)
    code, data = send(sid, "политика промпта 65656")
    assert code == 200, "не ответил: %s" % data
    prompt = data.get("reply", "")
    assert "не спрашивайте" in prompt.lower() or "не расспрашивайте" in prompt.lower(), \
        "в промпте нет запрета расспрашивать о чувствительном: " + prompt
    for word in ("здоров", "доход"):
        assert word in prompt.lower(), "промпт не называет чувствительные темы: " + prompt


def test_provider_without_key_reports_clearly():
    clear_chat()
    clear_ai_settings()
    setting("provider", "openrouter")   # ключ не задан
    sid = login(CANDIDATE)
    code, data = send(sid, "нет ключа 77777")
    assert code == 503, "отсутствие ключа не отражено: %s" % data
    assert "ключ" in (data.get("error") or "").lower()
