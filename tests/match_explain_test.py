# -*- coding: utf-8 -*-
"""
Тесты обоснования пары (ROADMAP Э3.3-a).

  POST /api/match_explain.php {anketa_id, target_id}
      только шадхан, ведущий anketa_id; пара обязана проходить жёсткие
      фильтры («обоснование только по реальным данным» — «Готовность Э3»)

Правила:
  * обоснование строится ИЗ ПОСЧИТАННЫХ компонентов и белого списка фактов —
    имена, здоровье и прочие чувствительные поля во внешнюю модель не уходят;
  * ответ LLM кэшируется по хэшу фактов: повторный запрос не тратит денег,
    изменение профиля инвалидирует кэш;
  * без ключа (провайдер stub) — детерминированный текст из компонентов,
    честный и бесплатный, в кэш не пишется;
  * лимит генераций на шадхана в час — защита баланса.

    python -m pytest tests/match_explain_test.py -q
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (clear_ai_settings, dbq, fake_calls, login, post_json,
                      set_fake_mode, setting, start_fake_server,
                      stop_fake_server, use_fake_provider, web)
from fake_llm import backup_row, restore_row  # noqa: E402

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету A
CANDIDATE = "u091ff2b36c83@example.local"

A = 2033
CITY = "Обоснование-Тест"

# Профили подобраны под известные компоненты:
#   ценности: budget 100 vs budget 100 -> совпадение 100%
#   языки любви: time 60/words 40 vs time 40/words 60 -> L1=40 -> 80%
PROFILE_A = {"love_languages": {"time": 60, "words": 40},
             "family_values_scores": {"budget": 100}}
PROFILE_T = {"love_languages": {"time": 40, "words": 60},
             "family_values_scores": {"budget": 100}}

NAME_SENTINEL = "Иехиэль-Идентификатор-31337"
HEALTH_SENTINEL = "диагноз-секрет-XYZ"

FIELDS = ["Gender", "Date_of_birth", "City", "Are_you_ready_to_move",
          "Search_age_start", "Search_age_end", "Kashrut_level", "Shabbat_level",
          "Psychological_profile", "Checked", "Name", "Health_conditions"]
T = None          # совместимая цель
T_MALE = None     # однополая «цель» — вне жёстких фильтров
BACKUP = {}


def sql_str(s):
    return str(s).replace("\\", "\\\\").replace("'", "''")


def set_fields(anketa, **kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % sql_str(v)))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), anketa))


def explain(sid, target, anketa_id=None, origin=None):
    return post_json("/api/match_explain.php", sid,
                     {"anketa_id": anketa_id if anketa_id is not None else A,
                      "target_id": target}, origin=origin)


def cache_count():
    return int(dbq("SELECT COUNT(*) FROM `match_explanations`") or 0)


def clear_cache():
    dbq("DELETE FROM `match_explanations`")


def setup_module(module):
    global T, T_MALE
    start_fake_server()
    T = int(dbq("SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=2 "
                "AND Message_ID<>%d ORDER BY Message_ID DESC LIMIT 1" % A))
    T_MALE = int(dbq("SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=1 "
                     "AND Message_ID<>%d ORDER BY Message_ID DESC LIMIT 1" % A))
    for aid in (A, T):
        BACKUP[aid] = backup_row("Message204", aid, FIELDS)
    set_fields(A, Gender=1, Date_of_birth="1994-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
               Kashrut_level=0, Shabbat_level=0,
               Psychological_profile=json.dumps(PROFILE_A, ensure_ascii=False), Checked=1)
    set_fields(T, Gender=2, Date_of_birth="1996-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
               Kashrut_level=0, Shabbat_level=0,
               Psychological_profile=json.dumps(PROFILE_T, ensure_ascii=False), Checked=1,
               Name=NAME_SENTINEL, Health_conditions=HEALTH_SENTINEL)
    clear_cache()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    clear_cache()
    for aid, fields in BACKUP.items():
        restore_row("Message204", aid, fields)


# --- доступ ------------------------------------------------------------------

def test_guest_and_candidate_forbidden():
    code, _ = explain(None, T)
    assert code == 403, "гость получил обоснование"
    code, _ = explain(login(CANDIDATE), T)
    assert code == 403, "кандидат получил обоснование"


def test_foreign_anketa_forbidden():
    other = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID<>57 "
                    "AND (Shadhan_1 IS NULL OR Shadhan_1<>57) "
                    "AND (Shadhan_2 IS NULL OR Shadhan_2<>57) "
                    "AND (Shadhan_3 IS NULL OR Shadhan_3<>57) ORDER BY Message_ID LIMIT 1"))
    code, _ = explain(login(SHADHAN), T, anketa_id=other)
    assert code == 403, "обоснование по чужой анкете выдано"


def test_foreign_origin_forbidden():
    code, _ = explain(login(SHADHAN), T, origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён"


def test_pair_outside_hard_filters_rejected():
    # однополая пара не проходит жёсткие фильтры — обосновывать нечего
    code, data = explain(login(SHADHAN), T_MALE)
    assert code == 400, "пара вне фильтров получила обоснование: %s" % data


# --- провайдер stub: честный текст из компонентов, бесплатно ------------------

def test_stub_gives_deterministic_text_without_cache():
    clear_ai_settings()
    clear_cache()
    code, data = explain(login(SHADHAN), T)
    assert code == 200, "stub-обоснование не выдано: %s" % data
    text = data.get("explanation", "")
    assert "100%" in text, "нет совпадения ценностей в тексте: " + text
    assert "80%" in text, "нет совпадения языков любви в тексте: " + text
    assert data.get("provider") == "stub"
    assert cache_count() == 0, "stub-текст попал в кэш"


# --- LLM + кэш ---------------------------------------------------------------

def test_llm_generates_and_caches():
    use_fake_provider()
    set_fake_mode("ok")
    clear_cache()
    sid = login(SHADHAN)
    calls0 = fake_calls()
    code, data = explain(sid, T)
    assert code == 200, "обоснование не выдано: %s" % data
    assert data.get("explanation"), "пустое обоснование"
    assert data.get("cached") is False
    assert fake_calls() == calls0 + 1, "модель не вызвана"
    assert cache_count() == 1, "ответ не закэширован"

    code, data = explain(sid, T)
    assert code == 200
    assert data.get("cached") is True, "повторный запрос не из кэша"
    assert fake_calls() == calls0 + 1, "повторный запрос потратил вызов модели"


def test_cache_invalidated_when_profile_changes():
    use_fake_provider()
    set_fake_mode("ok")
    clear_cache()
    sid = login(SHADHAN)
    explain(sid, T)
    calls0 = fake_calls()
    changed = {"love_languages": {"time": 90, "words": 10},
               "family_values_scores": {"budget": 100}}
    set_fields(T, Psychological_profile=json.dumps(changed, ensure_ascii=False))
    try:
        code, data = explain(sid, T)
        assert code == 200
        assert data.get("cached") is False, "изменение профиля не инвалидировало кэш"
        assert fake_calls() == calls0 + 1
    finally:
        set_fields(T, Psychological_profile=json.dumps(PROFILE_T, ensure_ascii=False))


def test_prompt_has_facts_but_no_identifiers_or_health():
    use_fake_provider()
    set_fake_mode("echo_prompt")   # модель возвращает весь полученный запрос
    clear_cache()
    code, data = explain(login(SHADHAN), T)
    assert code == 200, "echo_prompt не сработал: %s" % data
    sent = data.get("explanation", "")
    assert CITY in sent, "город не дошёл до модели"
    assert "100%" in sent, "посчитанные компоненты не дошли до модели"
    assert NAME_SENTINEL not in sent, "ИМЯ уехало во внешнюю модель"
    assert HEALTH_SENTINEL not in sent, "данные о здоровье уехали во внешнюю модель"
    assert str(A) not in sent and str(T) not in sent, "ID анкет уехали в модель"


def test_llm_failure_is_502_and_not_cached():
    use_fake_provider()
    set_fake_mode("always_500")
    clear_cache()
    code, data = explain(login(SHADHAN), T)
    assert code == 502, "ошибка модели не превратилась в 502: %s" % data
    assert cache_count() == 0, "ошибка попала в кэш"


def test_openrouter_without_key_falls_back_to_stub():
    # полунастроенный стенд: провайдер выбран, ключ ещё не задан (ревью В3)
    clear_ai_settings()
    setting("provider", "openrouter")
    clear_cache()
    try:
        code, data = explain(login(SHADHAN), T)
        assert code == 200, "без ключа не выдан stub-текст: %s" % data
        assert data.get("provider") == "stub"
        assert "100%" in data.get("explanation", "")
        assert cache_count() == 0
    finally:
        clear_ai_settings()


def test_rejected_pair_is_not_explained():
    # шадхан сам убрал пару из выдачи — генерацию на неё не тратим (ревью)
    use_fake_provider()
    set_fake_mode("ok")
    clear_cache()
    a, b = sorted([A, T])
    dbq("INSERT INTO `match_feedback` (anketa_a, anketa_b, shadhan_id, action, "
        "reason, created_at) VALUES (%d, %d, 57, 'rejected', 'other', NOW())" % (a, b))
    try:
        code, data = explain(login(SHADHAN), T)
        assert code == 400, "отклонённая пара получила обоснование: %s" % data
        assert cache_count() == 0
    finally:
        dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")


def test_emoji_stripped_before_cache():
    # база в utf8 (3 байта): эмодзи от модели MySQL молча обрезал бы вместе с
    # хвостом текста, а пустой кэш = вечный промах и безлимитные генерации
    # одной пары (ревью Б2). Санитайз должен убрать эмодзи, кэш — выжить.
    use_fake_provider()
    set_fake_mode("emoji")
    clear_cache()
    sid = login(SHADHAN)
    code, data = explain(sid, T)
    assert code == 200, "ответ с эмодзи не обработан: %s" % data
    text = data.get("explanation", "")
    assert "Отличная пара" in text, "текст потерян при санитайзе: " + text
    assert "\U0001F48D" not in text, "эмодзи не вырезано"
    stored = dbq("SELECT explanation FROM `match_explanations` ORDER BY id DESC LIMIT 1")
    assert "Отличная пара" in stored, "в кэше обрезанный текст: " + repr(stored)
    calls0 = fake_calls()
    code, data = explain(sid, T)
    assert data.get("cached") is True, "кэш с эмодзи-ответом не сработал"
    assert fake_calls() == calls0, "повторная генерация той же пары"


def test_hash_is_direction_symmetric():
    # кэш общий для обоих направлений пары: шадханы двух половин не должны
    # платить за одно обоснование дважды (ревью В1)
    php = (
        "require '/var/www/html/api/match_lib.php';"
        "$x = array('Message_ID'=>100,'Gender'=>1,'age'=>30,'City'=>'Хайфа',"
        "'Are_you_ready_to_move'=>0,'Kashrut_level'=>2,'Shabbat_level'=>0,"
        "'Psychological_profile'=>'');"
        "$y = array('Message_ID'=>200,'Gender'=>2,'age'=>28,'City'=>'Хайфа',"
        "'Are_you_ready_to_move'=>1,'Kashrut_level'=>0,'Shabbat_level'=>3,"
        "'Psychological_profile'=>'');"
        "$s = array('score'=>0.5,'breakdown'=>array('values_match'=>1.0,"
        "'love_language_match'=>0.8,'semantic_similarity'=>0));"
        "echo match_explain_hash(match_explain_facts($x,$y,$s),'m'), ' ',"
        "match_explain_hash(match_explain_facts($y,$x,$s),'m');"
    )
    out = (web("php", "-r", php).stdout or "").split()
    assert len(out) == 2 and out[0] == out[1], \
        "хэш зависит от направления запроса: %r" % out


def test_rate_limit_per_shadhan():
    use_fake_provider()
    set_fake_mode("ok")
    clear_cache()
    for i in range(30):
        dbq("INSERT INTO `match_explanations` "
            "(anketa_a, anketa_b, shadhan_id, source_hash, explanation, created_at) "
            "VALUES (%d, %d, 57, 'ratelimit-%d', 'x', NOW())" % (7000000 + i, 8000000, i))
    try:
        code, data = explain(login(SHADHAN), T)
        assert code == 429, "лимит генераций в час не сработал: %s" % data
    finally:
        clear_cache()
