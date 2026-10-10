# -*- coding: utf-8 -*-
"""
Приёмка 2026-10: «Мне интересно» в поиске кандидатов — кандидат одной
кнопкой сообщает шадхану чужой анкеты о заинтересованности
(api/interest_send.php, таблица interest_requests — миграция 031).

    python -m pytest tests/interest_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import backup_row, dbq, login, post_json, restore_row  # noqa: E402

CANDIDATE = "u091ff2b36c83@example.local"   # User 50
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57
URL = "/api/interest_send.php"
STATE = {}
BACKUP = {}


def send(email, anketa):
    return post_json(URL, login(email), {"anketa_id": anketa})


def setup_module(module):
    STATE["m6"] = int(dbq("SELECT IFNULL(MAX(Message_ID),0) FROM `Message6`"))
    STATE["uid"] = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    STATE["anketa"] = int(dbq("SELECT Message_ID FROM `Message204` WHERE Checked=1 AND User_ID NOT IN (50,57) "
                              "ORDER BY Message_ID LIMIT 1"))
    BACKUP["row"] = backup_row("Message204", STATE["anketa"], ["Shadhan_1"])
    dbq("UPDATE `Message204` SET Shadhan_1=57 WHERE Message_ID=%d" % STATE["anketa"])
    dbq("DELETE FROM `interest_requests` WHERE user_id=%d" % STATE["uid"])


def teardown_module(module):
    dbq("DELETE FROM `interest_requests` WHERE user_id=%d" % STATE["uid"])
    dbq("DELETE FROM `Message6` WHERE Message_ID>%d" % STATE["m6"])
    restore_row("Message204", STATE["anketa"], BACKUP["row"])


def test_candidate_notifies_lead_shadhan_once():
    code, data = send(CANDIDATE, STATE["anketa"])
    assert code == 200 and data.get("repeat") is False, data
    assert dbq("SELECT COUNT(*) FROM `Message6` WHERE Message_ID>%d AND ToUser=57 AND User_ID=%d"
               % (STATE["m6"], STATE["uid"])) == "1", "шадхан не получил сообщение"
    code, data = send(CANDIDATE, STATE["anketa"])
    assert code == 200 and data.get("repeat") is True, data
    assert dbq("SELECT COUNT(*) FROM `Message6` WHERE Message_ID>%d AND ToUser=57 AND User_ID=%d"
               % (STATE["m6"], STATE["uid"])) == "1", "повтор отправил второе сообщение"


def test_shadhan_cannot_use_candidate_button():
    code, _ = send(SHADHAN, STATE["anketa"])
    assert code == 403


def test_foreign_origin_rejected():
    code, _ = post_json(URL, login(CANDIDATE), {"anketa_id": STATE["anketa"]}, origin="https://evil.example.com")
    assert code == 403
