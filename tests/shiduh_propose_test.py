# -*- coding: utf-8 -*-
"""
Приёмка 2026-10: «Предложить шидух» в рекомендациях ИИ создаёт настоящий
шидух и уведомляет стороны (api/shiduh_lib.php через api/match_feedback.php).

  * создаётся Message280 (тот же шидух, что раньше делал «Создание шидуха»);
  * повтор не плодит второй шидух и второе уведомление;
  * вторая анкета закрыта — сообщение её шадхану, ответ шадхану понятен;
  * вторая анкета открыта и у неё свой аккаунт кандидата — сообщение
    самому кандидату («общение напрямую»).

    python -m pytest tests/shiduh_propose_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import backup_row, dbq, login, post_json, restore_row  # noqa: E402

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
A = 2033                                     # анкета, которую ведёт 57 (как в match_feedback_test)
FIELDS = ["Gender", "Date_of_birth", "City", "Are_you_ready_to_move", "Search_age_start", "Search_age_end",
          "Kashrut_level", "Shabbat_level", "Psychological_profile", "Checked", "Open",
          "Shadhan_1", "Shadhan_2", "Shadhan_3"]
CITY = "Шидух-Тест"
STATE = {}
BACKUP = {}


def set_fields(anketa, **kw):
    parts = ["`%s`=%s" % (k, "NULL" if v is None else "'%s'" % v) for k, v in kw.items()]
    dbq("UPDATE `Message204` SET %s WHERE Message_ID=%d" % (", ".join(parts), anketa))


def propose(target):
    return post_json("/api/match_feedback.php", login(SHADHAN),
                     {"anketa_id": A, "target_id": target, "action": "proposed", "reason": ""})


def messages_to(uid):
    return int(dbq("SELECT COUNT(*) FROM `Message6` WHERE Message_ID>%d AND ToUser=%d AND User_ID=57"
                   % (STATE["m6"], uid)))


def setup_module(module):
    STATE["m280"] = int(dbq("SELECT IFNULL(MAX(Message_ID),0) FROM `Message280`"))
    STATE["m6"] = int(dbq("SELECT IFNULL(MAX(Message_ID),0) FROM `Message6`"))
    # другой шадхан — получатель предложения по закрытой анкете
    STATE["other_shadhan"] = int(dbq("SELECT User_ID FROM `User` WHERE Type=2 AND Checked=1 "
                                     "AND User_ID NOT IN (1,57,119) ORDER BY User_ID LIMIT 1"))
    # две анкеты противоположного пола с владельцем-кандидатом (Type=1)
    rows = dbq("SELECT m.Message_ID, m.User_ID FROM `Message204` m JOIN `User` u ON u.User_ID=m.User_ID "
               "WHERE u.Type=1 AND u.Email NOT LIKE 'invite-%%' AND m.Message_ID<>%d "
               "ORDER BY m.Message_ID DESC LIMIT 2" % A).splitlines()
    STATE["closed"], STATE["closed_owner"] = [int(x) for x in rows[0].split("\t")]
    STATE["open"], STATE["open_owner"] = [int(x) for x in rows[1].split("\t")]
    for aid in (A, STATE["closed"], STATE["open"]):
        BACKUP[aid] = backup_row("Message204", aid, FIELDS)
    base = dict(Date_of_birth="1995-01-01", City=CITY, Are_you_ready_to_move=0, Search_age_start=None,
                Search_age_end=None, Kashrut_level=0, Shabbat_level=0, Psychological_profile=None, Checked=1)
    set_fields(A, Gender=1, **base)
    set_fields(STATE["closed"], Gender=2, Open=0, Shadhan_1=STATE["other_shadhan"], Shadhan_2=None,
               Shadhan_3=None, **base)
    set_fields(STATE["open"], Gender=2, Open=1, Shadhan_1=None, Shadhan_2=None, Shadhan_3=None, **base)


def teardown_module(module):
    dbq("DELETE FROM `Message280` WHERE Message_ID>%d" % STATE["m280"])
    dbq("DELETE FROM `Message6` WHERE Message_ID>%d" % STATE["m6"])
    dbq("DELETE FROM `match_feedback` WHERE shadhan_id=57")
    for aid, fields in BACKUP.items():
        restore_row("Message204", aid, fields)


def test_propose_to_closed_creates_shiduh_and_notifies_shadhan():
    code, data = propose(STATE["closed"])
    assert code == 200, data
    sh = data.get("shiduh") or {}
    assert sh.get("created") is True and sh.get("via") == "shadhan", sh
    assert dbq("SELECT COUNT(*) FROM `Message280` WHERE User_ID=57 AND Candidate1=%d AND Candidate2=%d "
               "AND Message_ID>%d" % (A, STATE["closed"], STATE["m280"])) == "1", "шидух не создан"
    assert messages_to(STATE["other_shadhan"]) == 1, "шадхан второй стороны не получил предложение"
    assert messages_to(STATE["closed_owner"]) == 0, "по закрытой анкете написали кандидату напрямую"
    assert "закрыта" in sh.get("message", ""), "шадхану не объяснили, что связь через шадхана"


def test_repeat_does_not_duplicate():
    code, data = propose(STATE["closed"])
    assert code == 200, data
    assert (data.get("shiduh") or {}).get("created") is False
    assert dbq("SELECT COUNT(*) FROM `Message280` WHERE User_ID=57 AND Candidate2=%d AND Message_ID>%d"
               % (STATE["closed"], STATE["m280"])) == "1", "повтор создал второй шидух"
    assert messages_to(STATE["other_shadhan"]) == 1, "повтор разослал уведомление ещё раз"


def test_propose_to_open_goes_directly_to_candidate():
    code, data = propose(STATE["open"])
    assert code == 200, data
    sh = data.get("shiduh") or {}
    assert sh.get("via") == "direct", sh
    assert messages_to(STATE["open_owner"]) == 1, "кандидат открытой анкеты не получил предложение"
    assert "напрямую" in sh.get("message", "")
