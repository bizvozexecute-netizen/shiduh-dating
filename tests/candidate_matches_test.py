# -*- coding: utf-8 -*-
"""
Тесты подбора для кандидата (ROADMAP Э3.4).

Кандидат видит в консультанте СВОИ рекомендованные пары — тем же движком
match_list (Э3.4-b: не считаем отдельно), но ОБЕЗЛИЧЕННО: без имён и
ссылок на анкеты, связь ведётся через шадхана. Пары, отклонённые ведущим
шадханом анкеты, кандидату тоже не показываются.

    python -m pytest tests/candidate_matches_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login

import urllib.request

OWNER = "u051e2e9933c7@example.local"       # User 1640 — владелец анкеты 2033
NOANKETA = "ua9c6c028bf40@example.local"    # User 32 — анкеты нет

A = 2033                                     # Shadhan_1=57 — ведущий шадхан
CITY = "Кандидат-Подбор-Тест"
NAME_SENTINEL = "Цель-Обезличенность-77441"

PAGE = "/my/ai-consultant/"
ROW_MARK = "lk-mine__rec"

FIELDS = ["Gender", "Date_of_birth", "City", "Are_you_ready_to_move",
          "Search_age_start", "Search_age_end", "Kashrut_level", "Shabbat_level",
          "Psychological_profile", "Checked", "Name",
          "Shadhan_1", "Shadhan_2", "Shadhan_3"]
TARGETS = []
BACKUP = {}


def sql_str(s):
    return str(s).replace("\\", "\\\\").replace("'", "''")


def set_fields(anketa, **kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % sql_str(v)))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), anketa))


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


def setup_module(module):
    global TARGETS
    TARGETS = [int(x) for x in dbq(
        "SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=2 "
        "AND Message_ID<>%d ORDER BY Message_ID DESC LIMIT 2" % A).split()]
    for aid in [A] + TARGETS:
        row = dbq("SELECT %s FROM `Message204` WHERE Message_ID=%d"
                  % (", ".join("IFNULL(`%s`,'\\0')" % f for f in FIELDS), aid)).split("\t")
        BACKUP[aid] = dict(zip(FIELDS, row))
    # Профили дают целям РАЗЛИЧИМЫЕ баллы (70% и 55%): строки блока
    # обезличены («Пара N», возраст, город), и только по проценту тест
    # может понять, какая пара показана. Заодно баллы поднимают цели в
    # топ-5 — при общем нуле их вытеснили бы анкеты с меньшими Message_ID.
    profile_a = ('{"love_languages": {"time": 100}, '
                 '"family_values_scores": {"budget": 100}}')
    profile_half = ('{"love_languages": {"time": 50, "words": 50}, '
                    '"family_values_scores": {"budget": 100}}')
    set_fields(A, Gender=1, Date_of_birth="1994-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=profile_a,
               Checked=1, Shadhan_1=57, Shadhan_2=None, Shadhan_3=None)
    for t, prof in zip(TARGETS, (profile_a, profile_half)):
        set_fields(t, Gender=2, Date_of_birth="1996-01-01", City=CITY,
                   Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
                   Kashrut_level=0, Shabbat_level=0, Psychological_profile=prof,
                   Checked=1, Name=NAME_SENTINEL)
    dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")


def teardown_module(module):
    dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")
    for aid, fields in BACKUP.items():
        parts = []
        for k, v in fields.items():
            parts.append("`%s`=%s" % (k, "NULL" if v == "\0" else "'%s'" % sql_str(v)))
        dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), aid))


def test_candidate_sees_anonymized_pairs():
    code, html = get_page(PAGE, login(OWNER))
    assert code == 200, "консультант не открылся: %d" % code
    assert "Рекомендованные пары" in html, "нет блока рекомендаций"
    assert html.count(ROW_MARK) >= 2, "цели-фикстуры не попали в блок"
    # обезличенность: ни имени, ни ссылки на карточку цели
    assert NAME_SENTINEL not in html, "ИМЯ цели видно кандидату"
    for t in TARGETS:
        assert "moi-kandidaty_%d" % t not in html, "ссылка на анкету цели у кандидата"
    # возраст и город показываем — как в «открытых анкетах»
    assert CITY in html, "город пары не показан"


def test_pair_rejected_by_lead_shadhan_is_hidden():
    # T0 (70%) отклонена ведущим шадханом -> из блока пропадает именно она,
    # T1 (55%) остаётся; после отмены отклонения 70% возвращается
    sid = login(OWNER)
    _, html = get_page(PAGE, sid)
    assert "70%" in html and "55%" in html, "фикстуры не видны до отклонения"
    a, b = sorted([A, TARGETS[0]])
    dbq("INSERT INTO `match_feedback` (anketa_a, anketa_b, shadhan_id, action, "
        "reason, created_at) VALUES (%d, %d, 57, 'rejected', 'other', NOW())" % (a, b))
    try:
        _, html = get_page(PAGE, sid)
        assert "70%" not in html, "отклонённая шадханом пара видна кандидату"
        assert "55%" in html, "отклонение зацепило чужую пару"
    finally:
        dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")
    _, html = get_page(PAGE, sid)
    assert "70%" in html, "пара не вернулась после отмены отклонения"


def test_no_anketa_no_block():
    code, html = get_page(PAGE, login(NOANKETA))
    assert code == 200
    assert "Рекомендованные пары" not in html, "блок показан без анкеты"


def test_unchecked_anketa_no_block():
    set_fields(A, Checked=0)
    try:
        code, html = get_page(PAGE, login(OWNER))
        assert code == 200
        assert "Рекомендованные пары" not in html, "блок показан по немодерированной анкете"
    finally:
        set_fields(A, Checked=1)
