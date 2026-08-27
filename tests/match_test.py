# -*- coding: utf-8 -*-
"""
Тесты подбора пар (ROADMAP Э3.1 + Э3.2-a).

  POST /api/match_list.php {anketa_id} — только шадхан, ведущий анкету

Слой 1 — жёсткие фильтры, и они ОБЯЗАТЕЛЬНЫ («Готовность Э3»: пары вне
фильтров НИКОГДА не в выдаче):
  * противоположный пол;
  * возраст асимметрично: Б в диапазоне А И А в диапазоне Б
    (незаданный диапазон не ограничивает);
  * география: один город ИЛИ у кого-то готовность к переезду;
  * религиозная совместимость по матрице (дефолт до ответа раввина:
    соседние уровни совместимы, крайние нет; НЕ УКАЗАНО совместимо со
    всем — новые поля кашрута/шаббата пока пусты у всей базы, иначе
    выдача была бы пустой).

Слой 2 — ранжирование: score = 0.4·values + 0.3·love + 0.3·semantic
(semantic пока 0 — эмбеддинги ждут ключа; веса НЕ перенормируются, чтобы
баллы не скакнули при подключении). Ранжирование воспроизводимо.

    python -m pytest tests/match_test.py -q
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login, post_json

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету A
CANDIDATE = "u091ff2b36c83@example.local"   # не шадхан

A = 2033          # мужская анкета, Shadhan_1=57
CITY = "Матчбург-Тест"
FAR = "Далёкоград-Тест"

# поля, которые тест переставляет и обязан вернуть
FIELDS = ["Gender", "Date_of_birth", "City", "Are_you_ready_to_move",
          "Search_age_start", "Search_age_end", "Kashrut_level", "Shabbat_level",
          "Psychological_profile", "Checked"]

TARGETS = []      # женские анкеты-цели, выбираются в setup
BACKUP = {}


def sql_str(s):
    return str(s).replace("\\", "\\\\").replace("'", "''")


def set_fields(anketa, **kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % sql_str(v)))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), anketa))


def match(sid, anketa_id=None, origin=None):
    # limit=500: после починки NULL-диапазонов выдача реальной базы шире 50,
    # и тестовые цели с «хвостовыми» Message_ID не влезали бы в топ при
    # равных (нулевых) баллах
    return post_json("/api/match_list.php", sid,
                     {"anketa_id": anketa_id if anketa_id is not None else A,
                      "limit": 500},
                     origin=origin)


def ids(data):
    return [r["anketa_id"] for r in data.get("matches", [])]


def setup_module(module):
    global TARGETS
    TARGETS = [int(x) for x in dbq(
        "SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=2 "
        "AND Message_ID<>%d ORDER BY Message_ID DESC LIMIT 5" % A).split()]
    assert len(TARGETS) == 5, "не набрали анкет-целей"
    for aid in [A] + TARGETS:
        row = dbq("SELECT %s FROM `Message204` WHERE Message_ID=%d"
                  % (", ".join("IFNULL(`%s`,'\\0')" % f for f in FIELDS), aid)).split("\t")
        BACKUP[aid] = dict(zip(FIELDS, row))

    # Базовый сценарий.
    # A: мужчина 32 лет из Матчбурга, ищет 25–35, религия не указана.
    set_fields(A, Gender=1, Date_of_birth="1994-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=25, Search_age_end=35,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    t = TARGETS
    # T0 «идеальная»: 30 лет, тот же город, её диапазон включает 32
    set_fields(t[0], Gender=2, Date_of_birth="1996-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=28, Search_age_end=38,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    # T1 «я не в её диапазоне»: ищет 20–25, а A 32 года
    set_fields(t[1], Gender=2, Date_of_birth="1996-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=20, Search_age_end=25,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    # T2 «она не в моём диапазоне»: ей 45
    set_fields(t[2], Gender=2, Date_of_birth="1981-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=25, Search_age_end=60,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    # T3 «другой город, без переезда»
    set_fields(t[3], Gender=2, Date_of_birth="1996-01-01", City=FAR,
               Are_you_ready_to_move=0, Search_age_start=28, Search_age_end=38,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    # T4 «крайняя религия»: город тот же, возраст ок, но кашрут 1 (не соблюдаю)
    set_fields(t[4], Gender=2, Date_of_birth="1996-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=28, Search_age_end=38,
               Kashrut_level=1, Shabbat_level=0, Psychological_profile=None, Checked=1)


def teardown_module(module):
    for aid, fields in BACKUP.items():
        parts = []
        for k, v in fields.items():
            parts.append("`%s`=%s" % (k, "NULL" if v == "\0" else "'%s'" % sql_str(v)))
        dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), aid))


# --- доступ ------------------------------------------------------------------

def test_guest_forbidden():
    code, data = match(None)
    assert code == 403, "гость получил подбор: %s" % data


def test_candidate_forbidden():
    code, data = match(login(CANDIDATE))
    assert code == 403, "кандидат получил подбор: %s" % data


def test_foreign_anketa_forbidden():
    other = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID<>57 "
                    "AND (Shadhan_1 IS NULL OR Shadhan_1<>57) "
                    "AND (Shadhan_2 IS NULL OR Shadhan_2<>57) "
                    "AND (Shadhan_3 IS NULL OR Shadhan_3<>57) ORDER BY Message_ID LIMIT 1"))
    code, data = match(login(SHADHAN), anketa_id=other)
    assert code == 403, "подбор по чужой анкете: %s" % data


def test_foreign_origin_forbidden():
    code, data = match(login(SHADHAN), origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён: %s" % data


# --- жёсткие фильтры: никогда не в выдаче ------------------------------------

def test_ideal_pair_is_found():
    code, data = match(login(SHADHAN))
    assert code == 200, "подбор не отработал: %s" % data
    assert TARGETS[0] in ids(data), "идеальная пара не найдена: %s" % ids(data)


def test_no_same_sex_ever():
    code, data = match(login(SHADHAN))
    assert code == 200
    genders = dbq("SELECT DISTINCT Gender FROM `Message204` WHERE Message_ID IN (%s)"
                  % (",".join(str(i) for i in ids(data)) or "0"))
    assert genders in ("", "2"), "в выдаче однополая пара: гендеры %s" % genders


def test_age_asymmetric_my_range():
    # ей 45, мой диапазон 25–35 → никогда
    _, data = match(login(SHADHAN))
    assert TARGETS[2] not in ids(data), "пара вне моего возрастного диапазона в выдаче"


def test_age_asymmetric_her_range():
    # мне 32, её диапазон 20–25 → никогда
    _, data = match(login(SHADHAN))
    assert TARGETS[1] not in ids(data), "пара, у которой я вне диапазона, в выдаче"


def test_geography_requires_city_or_relocation():
    _, data = match(login(SHADHAN))
    assert TARGETS[3] not in ids(data), "другой город без переезда в выдаче"
    # добавили готовность к переезду — пара появляется
    set_fields(TARGETS[3], Are_you_ready_to_move=1)
    try:
        _, data = match(login(SHADHAN))
        assert TARGETS[3] in ids(data), "релокация не открыла пару из другого города"
    finally:
        set_fields(TARGETS[3], Are_you_ready_to_move=0)


def test_religion_extremes_never():
    # A строгий (4) × T4 не соблюдает (1) → никогда
    set_fields(A, Kashrut_level=4)
    try:
        _, data = match(login(SHADHAN))
        assert TARGETS[4] not in ids(data), "крайние уровни кашрута в выдаче"
        # сосед (3) проходит
        set_fields(TARGETS[4], Kashrut_level=3)
        _, data = match(login(SHADHAN))
        assert TARGETS[4] in ids(data), "соседние уровни кашрута отфильтрованы"
    finally:
        set_fields(A, Kashrut_level=0)
        set_fields(TARGETS[4], Kashrut_level=1)


def test_unknown_religion_is_compatible():
    # у всей базы уровни пока не заполнены — «не указано» не должно резать выдачу
    _, data = match(login(SHADHAN))
    assert TARGETS[0] in ids(data), "неуказанная религия отфильтровала пару"


def test_null_age_range_does_not_exclude():
    """У 674 из 1133 анкет Search_age_start — NULL. CAST(NULL AS UNSIGNED)=0
    даёт NULL, а не TRUE, и такие анкеты молча выпадали из выдачи —
    «незаданный диапазон не ограничивает» обязан работать и для NULL,
    не только для пустой строки."""
    set_fields(TARGETS[0], Search_age_start=None, Search_age_end=None)
    try:
        _, data = match(login(SHADHAN))
        assert TARGETS[0] in ids(data),             "анкета с NULL-диапазоном выпала из выдачи: %s" % ids(data)
    finally:
        set_fields(TARGETS[0], Search_age_start=28, Search_age_end=38)


def test_unchecked_anketa_never():
    set_fields(TARGETS[0], Checked=0)
    try:
        _, data = match(login(SHADHAN))
        assert TARGETS[0] not in ids(data), "немодерированная анкета в выдаче"
    finally:
        set_fields(TARGETS[0], Checked=1)


# --- ранжирование (Э3.2-a) ----------------------------------------------------

PROFILE_A = {"love_languages": {"time": 40, "words": 30, "service": 15, "touch": 10, "gifts": 5},
             "family_values_scores": {"budget": 60, "children": 40},
             "cases_answered": 7, "completed_at": "2026-08-24 08:00:00", "version": 1}
PROFILE_CLOSE = {"love_languages": {"time": 38, "words": 32, "service": 15, "touch": 10, "gifts": 5},
                 "family_values_scores": {"budget": 55, "children": 45},
                 "cases_answered": 7, "completed_at": "2026-08-24 08:00:00", "version": 1}
PROFILE_FAR = {"love_languages": {"gifts": 80, "touch": 20},
               "family_values_scores": {"career": 90, "budget": 10},
               "cases_answered": 7, "completed_at": "2026-08-24 08:00:00", "version": 1}


def _owner(anketa):
    return int(dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % anketa))


def _put_profile(anketa, profile):
    dbq("UPDATE `Message204` SET Psychological_profile=%s WHERE Message_ID=%d"
        % ("NULL" if profile is None else "'%s'" % sql_str(json.dumps(profile, ensure_ascii=False)),
           anketa))


def test_scoring_orders_by_similarity():
    # T0 — близкий профиль, T4 — далёкий (обоим открываем религию)
    _put_profile(A, PROFILE_A)
    _put_profile(TARGETS[0], PROFILE_CLOSE)
    _put_profile(TARGETS[4], PROFILE_FAR)
    set_fields(TARGETS[4], Kashrut_level=0)
    try:
        _, data = match(login(SHADHAN))
        got = ids(data)
        assert TARGETS[0] in got and TARGETS[4] in got, "пары пропали из выдачи: %s" % got
        assert got.index(TARGETS[0]) < got.index(TARGETS[4]), \
            "близкий профиль не выше далёкого: %s" % got
        rows = {r["anketa_id"]: r for r in data["matches"]}
        assert rows[TARGETS[0]]["score"] > rows[TARGETS[4]]["score"]
    finally:
        _put_profile(A, None)
        _put_profile(TARGETS[0], None)
        _put_profile(TARGETS[4], None)
        set_fields(TARGETS[4], Kashrut_level=1)


def test_score_breakdown_present():
    _put_profile(A, PROFILE_A)
    _put_profile(TARGETS[0], PROFILE_CLOSE)
    try:
        _, data = match(login(SHADHAN))
        row = next(r for r in data["matches"] if r["anketa_id"] == TARGETS[0])
        for key in ("values_match", "love_language_match", "semantic_similarity"):
            assert key in row["breakdown"], "нет компонента %s" % key
        # semantic пока не считается — честный ноль, не выдуманное число
        assert row["breakdown"]["semantic_similarity"] == 0
    finally:
        _put_profile(A, None)
        _put_profile(TARGETS[0], None)


def test_ranking_is_reproducible():
    _put_profile(A, PROFILE_A)
    _put_profile(TARGETS[0], PROFILE_CLOSE)
    try:
        sid = login(SHADHAN)
        first = match(sid)[1]["matches"]
        second = match(sid)[1]["matches"]
        assert first == second, "ранжирование невоспроизводимо"
    finally:
        _put_profile(A, None)
        _put_profile(TARGETS[0], None)


def test_match_row_shows_target_shadhan():
    # Э3.3-c: видно, кто ведёт кандидата
    _, data = match(login(SHADHAN))
    row = next(r for r in data["matches"] if r["anketa_id"] == TARGETS[0])
    assert "shadhan" in row, "в паре не показан ведущий шадхан"


def test_lead_shadhan_falls_back_to_second_slot():
    # ведущий — первый ЗАПОЛНЕННЫЙ слот: у части анкет Shadhan_1 пуст,
    # а кандидата реально ведёт Shadhan_2/3
    t = TARGETS[0]
    saved = dbq("SELECT IFNULL(Shadhan_1,'\\0'), IFNULL(Shadhan_2,'\\0'), "
                "IFNULL(Shadhan_3,'\\0') FROM `Message204` WHERE Message_ID=%d" % t).split("\t")
    name57 = dbq("SELECT Name FROM `User` WHERE User_ID=57")
    dbq("UPDATE `Message204` SET Shadhan_1=NULL, Shadhan_2=57, Shadhan_3=NULL "
        "WHERE Message_ID=%d" % t)
    try:
        _, data = match(login(SHADHAN))
        row = next(r for r in data["matches"] if r["anketa_id"] == t)
        assert row["shadhan"] == name57, \
            "ведущий из Shadhan_2 не показан: %r" % row.get("shadhan")
    finally:
        parts = []
        for col, v in zip(("Shadhan_1", "Shadhan_2", "Shadhan_3"), saved):
            parts.append("`%s`=%s" % (col, "NULL" if v == "\0" else "'%s'" % sql_str(v)))
        dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), t))


# --- инварианты против прямого SQL (гэпы из ревью) ----------------------------

def test_pool_count_matches_direct_sql():
    """Счётный инвариант: сколько анкет проходит фильтры по прямому SQL,
    столько и в выдаче. Точечный тест «цель нашлась» такой регресс не ловит:
    NULL-баг выкидывал 82% пар, а конкретная цель уцелела бы."""
    _, data = match(login(SHADHAN))
    got = len(ids(data))
    my_age = "TIMESTAMPDIFF(YEAR, '1994-01-01', CURDATE())"
    expected = int(dbq(
        "SELECT COUNT(*) FROM `Message204` m WHERE m.Checked=1 AND m.Gender=2 "
        "AND m.Message_ID<>%d AND m.Date_of_birth IS NOT NULL "
        "AND m.Date_of_birth NOT LIKE '0000%%' "
        "AND ((m.City='%s') OR m.Are_you_ready_to_move=1) "
        "AND TIMESTAMPDIFF(YEAR, m.Date_of_birth, CURDATE()) BETWEEN 25 AND 35 "
        "AND (m.Search_age_start IS NULL OR m.Search_age_start <= 0 OR %s >= m.Search_age_start) "
        "AND (m.Search_age_end IS NULL OR m.Search_age_end <= 0 OR %s <= m.Search_age_end)"
        % (A, CITY, my_age, my_age)))
    assert got == expected, "выдача (%d) разошлась с прямым SQL (%d)" % (got, expected)


def test_requester_null_range_means_unbounded():
    # симметричная половина инварианта: NULL-диапазон у ЗАПРАШИВАЮЩЕГО
    set_fields(A, Search_age_start=None, Search_age_end=None)
    try:
        _, data = match(login(SHADHAN))
        # T2 (45 лет) была вне диапазона 25–35 — без диапазона обязана войти
        assert TARGETS[2] in ids(data),             "NULL-диапазон запрашивающего не снял ограничение возраста"
    finally:
        set_fields(A, Search_age_start=25, Search_age_end=35)


def test_empty_city_is_not_a_match():
    """«Пустой город не считается совпадением»: 367 из 1133 анкет без города —
    они не должны липнуть к анкете, у которой города тоже нет."""
    set_fields(A, City=None)
    set_fields(TARGETS[0], City=None, Are_you_ready_to_move=0)
    try:
        _, data = match(login(SHADHAN))
        assert TARGETS[0] not in ids(data),             "две анкеты без города совпали по географии"
    finally:
        set_fields(A, City=CITY)
        set_fields(TARGETS[0], City=CITY)
