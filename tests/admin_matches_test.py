# -*- coding: utf-8 -*-
"""
C12 этапа «До идеала»: экран статистики подбора — решения шадханов по парам
(match_feedback) и обоснования пар (match_explanations). Цифры на экране —
те же COUNT, что в SQL.

    python -m pytest tests/admin_matches_test.py -q
"""
import os
import re
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, NoRedirect, dbq, login, web  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
CANDIDATE = "u091ff2b36c83@example.local"
A, B = 42, 2033
MARK_MODEL = "test/model-c12"

_SID = {}
STATE = {"ids": [], "eids": []}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def get(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    try:
        r = urllib.request.build_opener(NoRedirect).open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def setup_module(module):
    # UNIQUE pair_shadhan(anketa_a, anketa_b, shadhan_id): каждой строке — своя пара
    for i, (action, reason) in enumerate((("proposed", ""), ("rejected", "age"), ("rejected", "geography"))):
        dbq("INSERT INTO `match_feedback` (anketa_a, anketa_b, shadhan_id, action, reason, created_at) "
            "VALUES (%d, %d, 57, '%s', '%s', NOW())" % (A, B + i, action, reason))
        STATE["ids"].append(int(dbq("SELECT MAX(id) FROM `match_feedback`")))
    dbq("INSERT INTO `match_explanations` (anketa_a, anketa_b, shadhan_id, source_hash, explanation, model, created_at) "
        "VALUES (%d, %d, 57, 'c12test', 'тест', '%s', NOW())" % (A, B, MARK_MODEL))
    STATE["eids"].append(int(dbq("SELECT MAX(id) FROM `match_explanations`")))


def teardown_module(module):
    for i in STATE["ids"]:
        dbq("DELETE FROM `match_feedback` WHERE id=%d" % i)
    for i in STATE["eids"]:
        dbq("DELETE FROM `match_explanations` WHERE id=%d" % i)


def num(html, el_id):
    m = re.search(r'id="%s">(\d+)<' % el_id, html)
    assert m, "нет счётчика " + el_id
    return int(m.group(1))


def test_numbers_match_sql():
    code, html = get("/admin-matches.php", sid_of(ADMIN))
    assert code == 200, code
    for mark in ("Fatal error", "Parse error", "Warning:"):
        assert mark not in html
    assert num(html, "statFeedback") == int(dbq("SELECT COUNT(*) FROM `match_feedback`"))
    assert num(html, "statExplain") == int(dbq("SELECT COUNT(*) FROM `match_explanations`"))
    assert num(html, "statExplain7") == int(dbq("SELECT COUNT(*) FROM `match_explanations` WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"))
    assert num(html, "statPairs") == int(dbq("SELECT COUNT(DISTINCT CONCAT(LEAST(anketa_a,anketa_b),'-',GREATEST(anketa_a,anketa_b))) FROM `match_feedback` WHERE action='proposed'"))
    rejects = int(dbq("SELECT COUNT(*) FROM `match_feedback` WHERE action='rejected'"))
    m = re.search(r'data-action="rejected">.*?<td class="u-right">(\d+)</td>', html, re.S)
    assert m and int(m.group(1)) == rejects, "число отклонений не совпало"
    age = int(dbq("SELECT COUNT(*) FROM `match_feedback` WHERE action='rejected' AND reason='age'"))
    m = re.search(r'data-reason="age">.*?<td class="u-right">(\d+)</td>', html, re.S)
    assert m and int(m.group(1)) == age
    assert "Возраст" in html and 'data-shadhan="57"' in html and MARK_MODEL in html
    # на экране нет имён кандидатов пары
    name = dbq("SELECT Name FROM `Message204` WHERE Message_ID=%d" % A)
    assert name not in html


def test_forbidden_for_others_and_lint():
    for who in (SHADHAN, CANDIDATE, None):
        code, _ = get("/admin-matches.php", sid_of(who) if who else None)
        assert code == 403, "%s: %d" % (who, code)
    for rel in ("api/match_stats_lib.php", "admin-matches.php"):
        r = web("php", "-l", "/var/www/html/" + rel)
        assert "No syntax errors" in (r.stdout or ""), rel
