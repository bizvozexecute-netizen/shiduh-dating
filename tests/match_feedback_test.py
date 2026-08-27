# -*- coding: utf-8 -*-
"""
Тесты фидбека по парам (ROADMAP Э3.3-b).

  POST /api/match_feedback.php {anketa_id, target_id, action, reason?}
      action: proposed | rejected — только шадхан, ведущий anketa_id

Правила:
  * отклонённая пара исключается из выдачи подбора («фидбек влияет на
    выдачу» — «Готовность Э3»);
  * пара нормализуется (X,Y)==(Y,X);
  * повторное решение перезаписывает прошлое — шадхан вправе передумать;
  * причина отклонения понижает вес компонента в подборе этого шадхана
    (простая эвристика).

    python -m pytest tests/match_feedback_test.py -q
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import dbq, login, post_json

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"

A = 2033
CITY = "Фидбек-Тест"

FIELDS = ["Gender", "Date_of_birth", "City", "Are_you_ready_to_move",
          "Search_age_start", "Search_age_end", "Kashrut_level", "Shabbat_level",
          "Psychological_profile", "Checked"]
TARGETS = []
BACKUP = {}


def sql_str(s):
    return str(s).replace("\\", "\\\\").replace("'", "''")


def set_fields(anketa, **kw):
    parts = []
    for k, v in kw.items():
        parts.append("`%s`=%s" % (k, "NULL" if v is None else "'%s'" % sql_str(v)))
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), anketa))


def feedback(sid, target, action, reason="", anketa_id=None, origin=None):
    return post_json("/api/match_feedback.php", sid,
                     {"anketa_id": anketa_id if anketa_id is not None else A,
                      "target_id": target, "action": action, "reason": reason},
                     origin=origin)


def matches(sid):
    _, data = post_json("/api/match_list.php", sid, {"anketa_id": A, "limit": 500})
    return [r["anketa_id"] for r in data.get("matches", [])]


def clear_feedback():
    dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")


def setup_module(module):
    global TARGETS
    TARGETS = [int(x) for x in dbq(
        "SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=2 "
        "AND Message_ID<>%d ORDER BY Message_ID DESC LIMIT 2" % A).split()]
    for aid in [A] + TARGETS:
        row = dbq("SELECT %s FROM `Message204` WHERE Message_ID=%d"
                  % (", ".join("IFNULL(`%s`,'\\0')" % f for f in FIELDS), aid)).split("\t")
        BACKUP[aid] = dict(zip(FIELDS, row))
    set_fields(A, Gender=1, Date_of_birth="1994-01-01", City=CITY,
               Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
               Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    for t in TARGETS:
        set_fields(t, Gender=2, Date_of_birth="1996-01-01", City=CITY,
                   Are_you_ready_to_move=0, Search_age_start=None, Search_age_end=None,
                   Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    clear_feedback()


def teardown_module(module):
    clear_feedback()
    for aid, fields in BACKUP.items():
        parts = []
        for k, v in fields.items():
            parts.append("`%s`=%s" % (k, "NULL" if v == "\0" else "'%s'" % sql_str(v)))
        dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), aid))


# --- доступ ------------------------------------------------------------------

def test_guest_and_candidate_forbidden():
    clear_feedback()
    code, _ = feedback(None, TARGETS[0], "rejected")
    assert code == 403, "гость оставил фидбек"
    code, _ = feedback(login(CANDIDATE), TARGETS[0], "rejected")
    assert code == 403, "кандидат оставил фидбек"
    assert dbq("SELECT COUNT(*) FROM `match_feedback`") == "0"


def test_foreign_anketa_forbidden():
    other = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID<>57 "
                    "AND (Shadhan_1 IS NULL OR Shadhan_1<>57) "
                    "AND (Shadhan_2 IS NULL OR Shadhan_2<>57) "
                    "AND (Shadhan_3 IS NULL OR Shadhan_3<>57) ORDER BY Message_ID LIMIT 1"))
    code, _ = feedback(login(SHADHAN), TARGETS[0], "rejected", anketa_id=other)
    assert code == 403, "фидбек по чужой анкете принят"


def test_foreign_origin_forbidden():
    clear_feedback()
    code, _ = feedback(login(SHADHAN), TARGETS[0], "rejected",
                       origin="https://evil.example.com")
    assert code == 403, "чужой Origin не отклонён"
    assert dbq("SELECT COUNT(*) FROM `match_feedback`") == "0"


# --- влияние на выдачу --------------------------------------------------------

def test_rejected_pair_leaves_output():
    clear_feedback()
    sid = login(SHADHAN)
    assert TARGETS[0] in matches(sid), "цель не в выдаче до отклонения"
    code, data = feedback(sid, TARGETS[0], "rejected", "values")
    assert code == 200 and data.get("ok") is True, "фидбек не принят: %s" % data
    assert TARGETS[0] not in matches(sid), "отклонённая пара осталась в выдаче"
    # вторая цель не пострадала
    assert TARGETS[1] in matches(sid), "фидбек зацепил чужую пару"


def test_pair_is_normalized():
    # отклонение (A, T) должно закрывать и направление (T, A)
    clear_feedback()
    sid = login(SHADHAN)
    feedback(sid, TARGETS[0], "rejected", "other")
    a, b = sorted([A, TARGETS[0]])
    row = dbq("SELECT anketa_a, anketa_b FROM `match_feedback` WHERE shadhan_id=57")
    assert row == "%d\t%d" % (a, b), "пара не нормализована: " + row


def test_decision_can_be_changed():
    clear_feedback()
    sid = login(SHADHAN)
    feedback(sid, TARGETS[0], "rejected", "values")
    assert TARGETS[0] not in matches(sid)
    code, data = feedback(sid, TARGETS[0], "proposed")
    assert code == 200, "смена решения не принята: %s" % data
    assert TARGETS[0] in matches(sid), "пара не вернулась после смены решения"
    n = dbq("SELECT COUNT(*) FROM `match_feedback` WHERE shadhan_id=57")
    assert n == "1", "решение продублировалось: " + n


def test_proposed_is_recorded_but_not_hidden():
    clear_feedback()
    sid = login(SHADHAN)
    feedback(sid, TARGETS[1], "proposed")
    assert TARGETS[1] in matches(sid), "предложенная пара пропала из выдачи"
    act = dbq("SELECT action FROM `match_feedback` WHERE shadhan_id=57")
    assert act == "proposed"


# --- валидация ---------------------------------------------------------------

def test_validation():
    clear_feedback()
    sid = login(SHADHAN)
    code, _ = feedback(sid, TARGETS[0], "bogus")
    assert code == 400, "неизвестное действие принято"
    code, _ = feedback(sid, TARGETS[0], "rejected", "выдуманная_причина")
    assert code == 400, "причина вне списка принята"
    code, _ = feedback(sid, 99999999, "rejected")
    assert code == 404, "несуществующая цель принята"
    code, _ = feedback(sid, A, "rejected")
    assert code == 400, "пара анкеты с самой собой принята"
    assert dbq("SELECT COUNT(*) FROM `match_feedback`") == "0"


# --- эвристика весов ----------------------------------------------------------

def test_rejection_reason_lowers_component_weight():
    """Причина «не совпали ценности» понижает вес values в подборе ЭТОГО шадхана."""
    clear_feedback()
    sid = login(SHADHAN)
    profile_a = {"love_languages": {"time": 50, "words": 50},
                 "family_values_scores": {"budget": 100}}
    profile_t = {"love_languages": {"time": 50, "words": 50},
                 "family_values_scores": {"budget": 100}}
    set_fields(A, Psychological_profile=json.dumps(profile_a, ensure_ascii=False))
    set_fields(TARGETS[1], Psychological_profile=json.dumps(profile_t, ensure_ascii=False))
    try:
        _, data = post_json("/api/match_list.php", sid, {"anketa_id": A, "limit": 500})
        before = next(r for r in data["matches"] if r["anketa_id"] == TARGETS[1])["score"]
        # три отклонения других пар с причиной values
        others = [int(x) for x in dbq(
            "SELECT Message_ID FROM `Message204` WHERE Checked=1 AND Gender=2 "
            "AND Message_ID NOT IN (%d,%d) ORDER BY Message_ID LIMIT 3"
            % (TARGETS[0], TARGETS[1])).split()]
        for o in others:
            feedback(sid, o, "rejected", "values")
        _, data = post_json("/api/match_list.php", sid, {"anketa_id": A, "limit": 500})
        after = next(r for r in data["matches"] if r["anketa_id"] == TARGETS[1])["score"]
        assert after < before, \
            "вес values не понизился после отклонений: %s -> %s" % (before, after)
    finally:
        set_fields(A, Psychological_profile=None)
        set_fields(TARGETS[1], Psychological_profile=None)
        clear_feedback()
