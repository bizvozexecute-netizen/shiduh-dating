# -*- coding: utf-8 -*-
"""
Тесты генерации резюме кандидата для шадхана (ROADMAP Э1.4).

  POST /api/summary_generate.php {anketa_id} — только шадхан, ведущий анкету

Главное требование (Э1.4-b, 152-ФЗ): в модель уходит ТОЛЬКО белый список
полей. Проверяем именно это — что в запрос не попали ФИО, телефон, email,
данные о здоровье и точная дата рождения (вместо неё — возраст).

    python -m pytest tests/summary_test.py -q
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, clear_ai_settings, dbq, login, post_json,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider)

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"   # User 50 — не шадхан
GEN_URL = "/api/summary_generate.php"

ANKETA = 2033          # Shadhan_1=57, Checked=1
OWNER = None
BACKUP = {}
FIELDS = ["Name", "Phone", "Email", "Health_status", "Date_of_birth",
          "City", "About_self", "Languages_spoken", "Kashrut_level",
          "AI_summary", "AI_summary_meta"]


def gen(sid, anketa_id=None, origin=None):
    return post_json(GEN_URL, sid,
                     {"anketa_id": anketa_id if anketa_id is not None else ANKETA},
                     origin=origin)


def field(name, anketa=None):
    return dbq("SELECT IFNULL(`%s`,'') FROM `Message204` WHERE Message_ID=%d"
               % (name, anketa or ANKETA))


def set_field(name, value, anketa=None):
    esc = str(value).replace("\\", "\\\\").replace("'", "''")
    dbq("UPDATE `Message204` SET `%s`='%s' WHERE Message_ID=%d"
        % (name, esc, anketa or ANKETA))


def clear_meta():
    dbq("UPDATE `Message204` SET AI_summary=NULL, AI_summary_meta=NULL "
        "WHERE Message_ID=%d" % ANKETA)


def setup_module(module):
    global OWNER
    OWNER = int(dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % ANKETA))
    for f in FIELDS:
        BACKUP[f] = field(f)
    start_fake_server()
    clear_ai_settings()
    clear_meta()


def teardown_module(module):
    for f, v in BACKUP.items():
        set_field(f, v)
    clear_ai_settings()
    clear_meta()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % OWNER)
    stop_fake_server()


# --- доступ ------------------------------------------------------------------

def test_guest_forbidden():
    clear_meta()
    code, data = gen(None)
    assert code == 403, "гость сгенерировал резюме: %s" % data


def test_candidate_forbidden():
    clear_meta()
    code, data = gen(login(CANDIDATE))
    assert code == 403, "кандидат сгенерировал резюме: %s" % data
    assert field("AI_summary") == ""


def test_foreign_origin_forbidden():
    clear_meta()
    code, data = gen(login(SHADHAN), origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data
    assert field("AI_summary") == ""


def test_unknown_anketa():
    code, _ = gen(login(SHADHAN), anketa_id=99999999)
    assert code == 404


def test_not_my_anketa_forbidden():
    # анкета, которую шадхан 57 не ведёт
    other = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID<>57 "
                    "AND (Shadhan_1 IS NULL OR Shadhan_1<>57) "
                    "AND (Shadhan_2 IS NULL OR Shadhan_2<>57) "
                    "AND (Shadhan_3 IS NULL OR Shadhan_3<>57) ORDER BY Message_ID LIMIT 1"))
    code, data = gen(login(SHADHAN), anketa_id=other)
    assert code == 403, "шадхан сгенерировал резюме к чужой анкете: %s" % data
    assert field("AI_summary", other) == ""


# --- приватность: что уходит в модель ----------------------------------------

def test_identifiers_and_health_not_sent_to_model():
    """Главная проверка Э1.4-b: белый список действительно белый."""
    clear_meta()
    use_fake_provider()
    set_fake_mode("echo_prompt")
    set_field("Name", "Иванов Пётр Соломонович")
    set_field("Phone", "+79161234567")
    set_field("Email", "petr.secret@example.com")
    set_field("Health_status", "гипертония, наблюдаюсь у врача")
    set_field("City", "Хайфа")
    set_field("Languages_spoken", "иврит, русский")
    code, data = gen(login(SHADHAN))
    assert code == 200, "генерация не прошла: %s" % data
    sent = data.get("summary", "")   # фейк вернул то, что получил
    # идентификаторы не ушли
    for leak in ("Иванов", "Соломонович", "79161234567", "petr.secret"):
        assert leak not in sent, "в модель ушёл идентификатор «%s»" % leak
    # данные о здоровье не ушли
    for leak in ("гипертония", "наблюдаюсь у врача"):
        assert leak not in sent, "в модель ушли данные о здоровье: %s" % leak
    # а разрешённые поля ушли
    assert "Хайфа" in sent, "разрешённое поле не попало в запрос: " + sent
    assert "иврит" in sent, "разрешённое поле не попало в запрос: " + sent


def test_age_sent_instead_of_birth_date():
    clear_meta()
    use_fake_provider()
    set_fake_mode("echo_prompt")
    set_field("Date_of_birth", "1990-03-17")
    code, data = gen(login(SHADHAN))
    assert code == 200, "генерация не прошла: %s" % data
    sent = data.get("summary", "")
    assert "1990-03-17" not in sent and "1990" not in sent, \
        "точная дата рождения ушла в модель: " + sent
    assert "Возраст:" in sent, "возраст не передан вместо даты: " + sent


def test_sensitive_006_fields_not_sent():
    clear_meta()
    use_fake_provider()
    set_fake_mode("echo_prompt")
    dbq("UPDATE `Message204` SET Income_property='зарплата 9000', "
        "Health_conditions='депрессия', Medications='сертралин' "
        "WHERE Message_ID=%d" % ANKETA)
    try:
        code, data = gen(login(SHADHAN))
        assert code == 200, "генерация не прошла: %s" % data
        sent = data.get("summary", "")
        for leak in ("9000", "депрессия", "сертралин"):
            assert leak not in sent, "чувствительное поле ушло в модель: " + leak
    finally:
        dbq("UPDATE `Message204` SET Income_property=NULL, Health_conditions=NULL, "
            "Medications=NULL WHERE Message_ID=%d" % ANKETA)


def test_chat_words_included():
    clear_meta()
    use_fake_provider()
    set_fake_mode("echo_prompt")
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % OWNER)
    dbq("INSERT INTO `ai_chat_messages` SET user_id=%d, role='user', "
        "content='Люблю учиться и ценю тишину дома 24680', tokens_used=0, "
        "created_at=NOW()" % OWNER)
    try:
        code, data = gen(login(SHADHAN))
        assert code == 200, "генерация не прошла: %s" % data
        assert "24680" in data.get("summary", ""), \
            "слова кандидата из беседы не попали в запрос"
    finally:
        dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % OWNER)


def test_contacts_scrubbed_from_free_text_and_chat():
    # человек мог сам написать телефон/почту в «о себе» или в переписке —
    # во внешнюю модель это уходить не должно
    clear_meta()
    use_fake_provider()
    set_fake_mode("echo_prompt")
    set_field("About_self", "Пишите мне на my.mail@example.com или +7 916 123-45-67")
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % OWNER)
    dbq("INSERT INTO `ai_chat_messages` SET user_id=%d, role='user', "
        "content='мой телефон 89991234567, звоните', tokens_used=0, created_at=NOW()"
        % OWNER)
    try:
        code, data = gen(login(SHADHAN))
        assert code == 200, "генерация не прошла: %s" % data
        sent = data.get("summary", "")
        for leak in ("my.mail@example.com", "916 123-45-67", "89991234567"):
            assert leak not in sent, "контакт ушёл в модель: " + leak
        assert "контакт удалён" in sent, "вычищение не применилось: " + sent
    finally:
        dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % OWNER)
        set_field("About_self", "")


def test_shadhan_hourly_cap():
    # потолок на шадхана: 20 пересчётов в час по всем его анкетам.
    # Готовим 20 свежих метаданных от автора 57 и ждём отказ на следующем.
    clear_meta()
    use_fake_provider()
    set_fake_mode("ok")
    now = dbq("SELECT NOW()")               # время БД, а не хоста
    now_ts = dbq("SELECT UNIX_TIMESTAMP()")  # им же сравнивает эндпоинт
    ids = dbq("SELECT Message_ID FROM `Message204` WHERE Shadhan_1=57 "
              "AND Message_ID<>%d ORDER BY Message_ID LIMIT 20" % ANKETA).split()
    assert len(ids) == 20, "мало анкет для проверки потолка: %d" % len(ids)
    meta = ('{"generated_at":"%s","generated_at_ts":%s,"generated_by":57}'
            % (now, now_ts))
    saved = {}
    try:
        for mid in ids:
            saved[mid] = dbq("SELECT IFNULL(AI_summary_meta,'') FROM `Message204` "
                             "WHERE Message_ID=" + mid)
            dbq("UPDATE `Message204` SET AI_summary_meta='%s' WHERE Message_ID=%s"
                % (meta.replace("'", "''"), mid))
        code, data = gen(login(SHADHAN))
        assert code == 429, "потолок на шадхана не сработал: %s" % data
        assert "за последний час" in (data.get("error") or "").lower()
    finally:
        for mid, v in saved.items():
            esc = v.replace("\\", "\\\\").replace("'", "''")
            dbq("UPDATE `Message204` SET AI_summary_meta='%s' WHERE Message_ID=%s"
                % (esc, mid))


def test_generation_is_audited():
    clear_meta()
    use_fake_provider()
    set_fake_mode("ok")
    dbq("DELETE FROM `ai_field_writes` WHERE user_id=%d AND field='AI_summary'" % OWNER)
    code, _ = gen(login(SHADHAN))
    assert code == 200
    log = dbq("SELECT field, source FROM `ai_field_writes` WHERE user_id=%d "
              "AND field='AI_summary' ORDER BY id DESC LIMIT 1" % OWNER)
    assert log == "AI_summary	ai", "пересчёт резюме не попал в журнал: " + log


# --- кнопка в карточке шадхана ------------------------------------------------

def test_card_button_is_active_and_wired():
    import urllib.request
    from fake_llm import NoRedirect
    sid = login(SHADHAN)
    req = urllib.request.Request(
        BASE + "/my/my-candidates/moi-kandidaty_%d.html" % ANKETA)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    html = urllib.request.build_opener(NoRedirect).open(req, timeout=120)        .read().decode("utf-8", "replace")
    assert "Fatal error" not in html and "Parse error" not in html
    assert "js-ai-summary-refresh" in html, "нет кнопки обновления резюме"
    # A5: обработчик кнопки вынесен из шаблона в js/lk-card.js — вызов
    # генерации ищем там, а не в HTML карточки
    assert "/js/lk-card.js" in html, "скрипт карточки не подключён"
    js = urllib.request.urlopen(BASE + "/js/lk-card.js", timeout=60).read().decode("utf-8", "replace")
    assert "summary_generate.php" in js and "js-ai-summary-refresh" in js, "кнопка не вызывает генерацию"
    assert 'data-anketa="%d"' % ANKETA in html, "кнопка без id анкеты"
    # кнопка больше не заблокирована
    assert "disabled title=\"Появится на следующем этапе\"" not in html,         "кнопка осталась неактивной"


# --- сохранение результата ---------------------------------------------------

def test_summary_saved_with_meta():
    clear_meta()
    use_fake_provider()
    set_fake_mode("ok")
    code, data = gen(login(SHADHAN))
    assert code == 200 and data.get("ok") is True, "резюме не сгенерировано: %s" % data
    assert "ФЕЙК-ОТВЕТ" in field("AI_summary"), "резюме не сохранено: " + field("AI_summary")
    meta = json.loads(field("AI_summary_meta"))
    assert meta["generated_by"] == 57, "не записан автор пересчёта: %s" % meta
    assert meta["tokens"] > 0, "не записаны токены: %s" % meta
    assert meta["provider"] == "openrouter"
    assert meta["generated_at"], "нет времени генерации"


def test_rate_limited_per_anketa():
    clear_meta()
    use_fake_provider()
    set_fake_mode("ok")
    sid = login(SHADHAN)
    code, _ = gen(sid)
    assert code == 200, "первая генерация не прошла"
    code, data = gen(sid)
    assert code == 429, "повторный пересчёт не ограничен: %s" % data
    assert "мин" in (data.get("error") or ""), "нет понятного сообщения о паузе"


def test_provider_failure_keeps_old_summary():
    clear_meta()
    set_field("AI_summary", "Прежнее резюме 13579")
    use_fake_provider()
    set_fake_mode("always_500")
    code, data = gen(login(SHADHAN))
    assert code == 503, "сбой модели не отражён: %s" % data
    assert "Прежнее резюме 13579" in field("AI_summary"), \
        "прежнее резюме потеряно при сбое: " + field("AI_summary")
