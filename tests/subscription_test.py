# -*- coding: utf-8 -*-
"""
Тесты монетизации (ROADMAP Э4).

  Э4-1: таблица ai_subscriptions (нет строки = free, 0 сообщений)
  Э4-2: единая точка доступа (subscription_lib) в ИИ-чате: у free после
        N сообщений (настройка ai.free_message_limit, по умолчанию 3) —
        402 + флаг paywall. Заглушка (stub-провайдер) бесплатна: без
        реального ИИ блокировать нечего. trial/paid — без лимита.
  Э4-4: админ-экран ручного переключения статуса.

    python -m pytest tests/subscription_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (clear_ai_settings, dbq, login, post_json, set_fake_mode,
                      setting, start_fake_server, stop_fake_server,
                      use_fake_provider)

import urllib.request
from fake_llm import BASE, NoRedirect

CANDIDATE = "u091ff2b36c83@example.local"   # User 50
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 (не админ)
ADMIN = "u5760fc1c6357@example.local"       # User 1, директор
UID = 50


def chat(sid, text, origin=None):
    return post_json("/api/ai_chat.php", sid, {"message": text}, origin=origin)


def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(req, timeout=180) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def clear_sub(uid=UID):
    dbq("DELETE FROM `ai_subscriptions` WHERE user_id=%d" % uid)


def sub_row(uid=UID):
    return dbq("SELECT status, ai_messages_used FROM `ai_subscriptions` "
               "WHERE user_id=%d" % uid)


def setup_module(module):
    start_fake_server()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    clear_sub()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)


def fresh_paid_setup(status=None):
    """Фейковый провайдер, лимит 3, чистый счётчик; при status — строка в базе."""
    use_fake_provider()
    setting("free_message_limit", 3)
    set_fake_mode("ok")
    clear_sub()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % UID)
    if status:
        dbq("INSERT INTO `ai_subscriptions` (user_id, status, ai_messages_used, "
            "updated_at) VALUES (%d, '%s', 99, NOW())" % (UID, status))


# --- Э4-2: пейволл в чате -----------------------------------------------------

def test_free_user_blocked_after_limit():
    fresh_paid_setup()
    sid = login(CANDIDATE)
    for i in range(3):
        code, data = chat(sid, "сообщение %d" % i)
        assert code == 200, "бесплатное сообщение %d не прошло: %s" % (i, data)
    code, data = chat(sid, "четвёртое")
    assert code == 402, "лимит free не сработал: %d %s" % (code, data)
    assert data.get("paywall") is True, "нет флага paywall: %s" % data
    assert sub_row() == "free\t3", "счётчик неверен: " + repr(sub_row())


def test_paid_and_trial_unlimited():
    for status in ("paid", "trial"):
        fresh_paid_setup(status)
        code, data = chat(login(CANDIDATE), "сообщение")
        assert code == 200, "%s заблокирован: %s" % (status, data)


def test_stub_provider_is_free():
    # без реального ИИ блокировать нечего: заглушка денег не тратит
    clear_ai_settings()
    setting("free_message_limit", 3)
    clear_sub()
    dbq("INSERT INTO `ai_subscriptions` (user_id, status, ai_messages_used, "
        "updated_at) VALUES (%d, 'free', 99, NOW())" % UID)
    code, data = chat(login(CANDIDATE), "привет")
    assert code == 200, "заглушка попала под пейволл: %s" % data


def test_paywall_message_not_stored():
    # заблокированное сообщение не должно попадать в историю диалога
    fresh_paid_setup()
    sid = login(CANDIDATE)
    for i in range(3):
        chat(sid, "ок %d" % i)
    before = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d" % UID)
    chat(sid, "лишнее")
    after = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d" % UID)
    assert before == after, "заблокированное сообщение сохранилось в истории"


def test_consultant_page_has_paywall_ui():
    _, html = get_page("/my/ai-consultant/", login(CANDIDATE))
    assert "ai-paywall" in html, "в консультанте нет обработчика пейволла"


# --- Э4-4: админ-экран --------------------------------------------------------

def test_admin_screen_access():
    code, _ = get_page("/admin-subscriptions.php", login(CANDIDATE))
    assert code == 403, "экран подписок открылся кандидату"
    code, _ = get_page("/admin-subscriptions.php", login(SHADHAN))
    assert code == 403, "экран подписок открылся шадхану"
    code, html = get_page("/admin-subscriptions.php", login(ADMIN))
    assert code == 200, "экран подписок не открылся админу: %d" % code
    assert "подписк" in html.lower(), "на экране нет темы подписок"


def test_admin_switches_status():
    clear_sub()
    sid = login(ADMIN)
    code, data = post_json("/api/subscription_set.php", sid,
                           {"user_id": UID, "status": "paid"})
    assert code == 200, "статус не переключился: %s" % data
    assert sub_row().startswith("paid"), "статус в базе не paid: " + repr(sub_row())
    # обратно в free
    code, _ = post_json("/api/subscription_set.php", sid,
                        {"user_id": UID, "status": "free"})
    assert code == 200
    assert sub_row().startswith("free")
    clear_sub()


def test_status_switch_validation_and_access():
    sid = login(ADMIN)
    code, _ = post_json("/api/subscription_set.php", sid,
                        {"user_id": UID, "status": "vip"})
    assert code == 400, "выдуманный статус принят"
    code, _ = post_json("/api/subscription_set.php", sid,
                        {"user_id": 99999999, "status": "paid"})
    assert code == 404, "несуществующий пользователь принят"
    code, _ = post_json("/api/subscription_set.php", login(SHADHAN),
                        {"user_id": UID, "status": "paid"})
    assert code == 403, "не-админ переключил статус"
    code, _ = post_json("/api/subscription_set.php", login(ADMIN),
                        {"user_id": UID, "status": "paid"},
                        origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён"
    clear_sub()


def test_status_change_resets_counter():
    # исчерпал free -> купил paid -> подписка кончилась -> вернули free:
    # человек должен снова получить свои бесплатные сообщения (ревью Б2)
    clear_sub()
    dbq("INSERT INTO `ai_subscriptions` (user_id, status, ai_messages_used, "
        "updated_at) VALUES (%d, 'paid', 99, NOW())" % UID)
    sid = login(ADMIN)
    code, _ = post_json("/api/subscription_set.php", sid,
                        {"user_id": UID, "status": "free"})
    assert code == 200
    assert sub_row() == "free\t0", "счётчик не сброшен: " + repr(sub_row())
    clear_sub()


def test_expired_subscription_is_free():
    # недельный триал не должен превращаться в вечный безлимит (ревью В5)
    fresh_paid_setup()
    dbq("INSERT INTO `ai_subscriptions` (user_id, status, ai_messages_used, "
        "expires_at, updated_at) VALUES (%d, 'paid', 99, "
        "DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())" % UID)
    code, data = chat(login(CANDIDATE), "сообщение")
    assert code == 402, "истёкший paid остался безлимитным: %s" % data
    # непросроченный срок работает
    dbq("UPDATE `ai_subscriptions` SET expires_at=DATE_ADD(NOW(), INTERVAL 1 DAY) "
        "WHERE user_id=%d" % UID)
    code, data = chat(login(CANDIDATE), "сообщение")
    assert code == 200, "действующий срок заблокирован: %s" % data
    clear_sub()


def test_limit_zero_and_negative_semantics():
    # 0 = ни одного бесплатного (интуитивное прочтение), -1 = лимит отключён
    fresh_paid_setup()
    setting("free_message_limit", 0)
    sid = login(CANDIDATE)
    code, data = chat(sid, "первое")
    assert code == 402, "лимит 0 пропустил сообщение: %s" % data
    setting("free_message_limit", -1)
    dbq("UPDATE `ai_subscriptions` SET ai_messages_used=999 WHERE user_id=%d" % UID)
    code, data = chat(sid, "безлимит")
    assert code == 200, "отрицательный лимит не отключил пейволл: %s" % data


def test_admin_sets_trial_with_days():
    clear_sub()
    code, data = post_json("/api/subscription_set.php", login(ADMIN),
                           {"user_id": UID, "status": "trial", "days": 7})
    assert code == 200 and data.get("expires_at"), "срок не установлен: %s" % data
    exp = dbq("SELECT expires_at FROM `ai_subscriptions` WHERE user_id=%d" % UID)
    assert exp and exp != "NULL", "expires_at пуст в базе"
    clear_sub()


# --- Э4-3: интерфейс платёжного шлюза (заглушки) ------------------------------

def test_payment_gateway_stubs_exist():
    from fake_llm import web
    php = (
        "require '/var/www/html/api/payment_lib.php';"
        "$g = payment_gateway('yookassa');"
        "$r = $g->create_payment(50, 990, 'RUB', 'Подписка');"
        "echo ($g instanceof PaymentGateway) ? 'iface ' : 'NOIFACE ';"
        "echo (!$r['ok'] && $r['error'] !== '') ? 'stub ' : 'NOSTUB ';"
        "$s = payment_gateway('stripe');"
        "echo ($s instanceof PaymentGateway) ? 'iface2' : 'NOIFACE2';"
    )
    out = (web("php", "-r", php).stdout or "").strip()
    assert out == "iface stub iface2", "заглушки шлюзов не соответствуют интерфейсу: %r" % out
