# -*- coding: utf-8 -*-
"""
Тесты экрана «Рекомендации ИИ» в карточке шадхана (ROADMAP Э3.3-a).

Список подбора грузится AJAX-ом по кнопке (сам подбор проверяет
match_test.py, обоснование — match_explain_test.py); здесь — что врезка
есть у шадхана, скрыта от кандидата и не роняет карточку.

    python -m pytest tests/match_screen_test.py -q
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import BASE, NoRedirect, dbq, login

import urllib.request

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету 2033
CANDIDATE = "u091ff2b36c83@example.local"   # User 50

ANKETA = 2033
CARD = "/my/my-candidates/moi-kandidaty_%d.html" % ANKETA


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


def test_shadhan_sees_match_screen():
    code, html = get_page(CARD, login(SHADHAN))
    assert code == 200, "карточка не открылась: %d" % code
    assert "Рекомендации ИИ" in html, "нет блока рекомендаций"
    assert "js-match-load" in html, "нет кнопки загрузки подбора"
    assert 'data-anketa="%d"' % ANKETA in html, "кнопка не привязана к анкете"
    # кнопки решений и обоснования рисуются JS-ом из этого же шаблона
    assert "js-match-propose" in html and "js-match-reject" in html
    assert "js-match-explain" in html
    # причины отклонения — ровно те, что принимает match_feedback.php
    for reason in ("values", "love_language", "geography", "age", "other"):
        assert "'%s'" % reason in html, "в списке причин нет " + reason


def test_candidate_does_not_see_match_screen():
    own = int(dbq("SELECT Message_ID FROM `Message204` WHERE User_ID=50 "
                  "ORDER BY Message_ID LIMIT 1"))
    code, html = get_page("/my/my-candidates/moi-kandidaty_%d.html" % own,
                          login(CANDIDATE))
    # без этой проверки тест «зеленел» бы на любом 302/404 — то есть не защищал
    assert code == 200, "карточка кандидата не открылась: %d" % code
    assert "js-match-load" not in html, "кандидат видит кнопку подбора"
    assert "Рекомендации ИИ" not in html, "кандидат видит блок рекомендаций"
