# -*- coding: utf-8 -*-
"""
Тесты визуализации ценностного профиля (ROADMAP Э2.4).

Карточка шадхана показывает диаграмму языков любви, ценностные измерения и
текст психотипа; пока профиля нет — «Тест не пройден».

Профиль ФИКСИРУЕТСЯ при опросе (Э2.3) и при просмотре не пересчитывается —
это проверяется отдельно. Радар рисуется на сервере как SVG: canvas было бы
нечем проверить (в разметке остался бы пустой тег), а координаты полигона
лежат прямо в HTML.

    python -m pytest tests/profile_view_test.py -q
"""
import base64
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (BASE, NoRedirect, clear_ai_settings, dbq, login, post_json,
                      set_fake_mode, start_fake_server, stop_fake_server,
                      use_fake_provider)

import urllib.request

SHADHAN = "u3f9bb8c20b01@example.local"     # User 57 — ведёт анкету
CANDIDATE = "u091ff2b36c83@example.local"   # User 50 — владелец своей анкеты

ANKETA = 2033                                # Shadhan_1=57, Checked=1
CARD = "/my/my-candidates/moi-kandidaty_%d.html" % ANKETA

OWNER = None          # владелец анкеты 2033
CAND_UID = None       # User 50
CAND_ANKETA = None

PROFILE = {
    "love_languages": {"time": 40, "words": 30, "service": 15, "touch": 10, "gifts": 5},
    "family_values_scores": {"budget": 55, "children": 30, "religion": 15},
    "cases_answered": 7,
    "completed_at": "2026-08-24 08:13:38",
    "version": 2,
    "psychotype_text": "Для неё главное — присутствие рядом 71828",
}


def sql_str(s):
    return s.replace("\\", "\\\\").replace("'", "''")


def set_profile(user_id, profile):
    """Пишем профиль тем же правилом, каким его пишет scoring_save_profile:
    в первую анкету пользователя."""
    if profile is None:
        dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE User_ID=%d" % user_id)
        return
    raw = profile if isinstance(profile, str) else json.dumps(profile, ensure_ascii=False)
    dbq("UPDATE `Message204` SET Psychological_profile='%s' WHERE Message_ID="
        "(SELECT m FROM (SELECT MIN(Message_ID) m FROM `Message204` WHERE User_ID=%d) t)"
        % (sql_str(raw), user_id))


def read_profile(user_id):
    """Профиль через base64: вывод mysql -B удваивает экранирование и ломает JSON."""
    raw = dbq("SELECT IFNULL(REPLACE(REPLACE(TO_BASE64(Psychological_profile),"
              "CHAR(10),''),CHAR(13),''),'') FROM `Message204` WHERE User_ID=%d "
              "ORDER BY Message_ID LIMIT 1" % user_id)
    if not raw.strip():
        return None
    return json.loads(base64.b64decode(raw).decode("utf-8"))


def get_page(path, sid):
    req = urllib.request.Request(BASE + path)
    if sid:
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=120)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def card(sid=None):
    return get_page(CARD, sid or login(SHADHAN))[1]


PHP_ERRORS = ["Fatal error", "Parse error", "Uncaught", "Warning: ", "Notice: "]


def assert_clean(html):
    for mark in PHP_ERRORS:
        assert mark not in html, "след ошибки PHP: " + mark


def setup_module(module):
    global OWNER, CAND_UID, CAND_ANKETA
    OWNER = int(dbq("SELECT User_ID FROM `Message204` WHERE Message_ID=%d" % ANKETA))
    CAND_UID = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % CANDIDATE))
    CAND_ANKETA = int(dbq("SELECT MIN(Message_ID) FROM `Message204` WHERE User_ID=%d" % CAND_UID))
    # Часть тестов реально проходит опрос — для классификации ответов нужен
    # фейковый LLM, иначе баллы не проставятся и профиль останется пустым.
    start_fake_server()
    clear_ai_settings()
    set_profile(OWNER, None)
    set_profile(CAND_UID, None)
    dbq("DELETE FROM `assessment_answers` WHERE user_id IN (%d, 57)" % CAND_UID)


def teardown_module(module):
    set_profile(OWNER, None)
    set_profile(CAND_UID, None)
    dbq("DELETE FROM `assessment_answers` WHERE user_id IN (%d, 57)" % CAND_UID)
    clear_ai_settings()
    stop_fake_server()


# --- заглушка -----------------------------------------------------------------

def test_placeholder_when_no_profile():
    set_profile(OWNER, None)
    html = card()
    assert "Тест не пройден" in html, "нет заглушки при отсутствии профиля"
    assert "lk-psy__radar" not in html, "диаграмма показана без профиля"
    assert_clean(html)


# --- отрисовка ----------------------------------------------------------------

def test_profile_block_rendered():
    set_profile(OWNER, PROFILE)
    html = card()
    assert "Психологический портрет" in html, "нет заголовка блока"
    assert "Языки любви" in html
    assert "Время вдвоём" in html and "40%" in html, "нет ведущего измерения"
    assert "Ценностный профиль" in html
    assert "Бюджет семьи" in html and "55%" in html, "нет ценностного измерения"
    assert "Тест не пройден" not in html
    assert_clean(html)


def test_radar_is_server_rendered_svg():
    # закрепляем решение: рисуем на сервере, без canvas и без библиотек —
    # иначе тест не смог бы проверить, что нарисовано
    set_profile(OWNER, PROFILE)
    html = card()
    assert "lk-psy__radar" in html and "<polygon" in html, "радар не отрисован как SVG"
    assert "<canvas" not in html, "появился canvas — отрисовку станет нечем проверить"


def test_radar_coordinates_are_integers():
    # sprintf('%f') в PHP 7.4 зависит от локали: на системе с ru_RU дробные
    # координаты превратились бы в «126,5» и SVG сломался бы. Локально это не
    # воспроизводится (локали нет в контейнере) — ловим тестом.
    set_profile(OWNER, PROFILE)
    html = card()
    shapes = re.findall(r'<polygon class="lk-psy__shape" points="([^"]+)"', html)
    assert shapes, "не нашли фигуру радара"
    # Пары «x,y» через пробел. Класс [0-9, ]+ здесь не годится: он пропустил бы
    # «126,500000 130,250000» — то есть ровно ту поломку, ради которой тест и
    # написан (запятая как дробный разделитель при русской локали).
    assert re.fullmatch(r"\d+,\d+( \d+,\d+)*", shapes[0]), "координаты не целые: " + shapes[0]
    assert len(shapes[0].split()) == 5, "в фигуре должно быть 5 вершин: " + shapes[0]


def test_axis_labels_are_outside_the_grid():
    # множитель выноса подписей молча срезался ограничителем шкалы, и подписи
    # ложились ровно на внешнее кольцо и на саму фигуру
    set_profile(OWNER, PROFILE)
    html = card()
    rings = re.findall(r'<polygon points="([^"]+)"', html)
    assert rings, "не нашли сетку радара"
    outer = set(rings[-1].split())
    labels = re.findall(r'<text class="lk-psy__axis" x="(\d+)" y="(\d+)"', html)
    assert len(labels) == 5, "подписей осей должно быть 5: %s" % labels
    for x, y in labels:
        assert "%s,%s" % (x, y) not in outer,             "подпись оси легла на внешнее кольцо: %s,%s" % (x, y)


def test_radar_is_deterministic():
    set_profile(OWNER, PROFILE)
    first = re.findall(r'<polygon class="lk-psy__shape" points="([^"]+)"', card())
    second = re.findall(r'<polygon class="lk-psy__shape" points="([^"]+)"', card())
    assert first == second and first, "диаграмма изменилась между открытиями: %s / %s" % (
        first, second)


def test_footer_shows_cases_and_version():
    set_profile(OWNER, PROFILE)
    html = card()
    assert "7 кейс" in html, "не показано число кейсов"
    assert "24 августа 2026" in html, "не показана дата фиксации"
    assert "версия 2" in html.lower(), "не показана версия профиля"


# --- устойчивость -------------------------------------------------------------

def test_psychotype_escaped_not_double_escaped():
    profile = dict(PROFILE)
    profile["psychotype_text"] = "<script>alert(1)</script> маркер92653"
    set_profile(OWNER, profile)
    html = card()
    assert "<script>alert(1)</script>" not in html, "XSS: сырой payload в тексте психотипа"
    assert "&lt;script&gt;" in html, "текст психотипа не выведен"
    assert "&amp;lt;script&amp;gt;" not in html, "двойное экранирование"
    assert "маркер92653" in html


def test_broken_json_degrades_to_placeholder():
    set_profile(OWNER, "это не json")
    html = card()
    assert "Тест не пройден" in html, "битый профиль не свёлся к заглушке"
    assert_clean(html)


def test_empty_love_languages_hides_radar():
    # ровно то, что отдаёт scoring_percentages() при пустой группе
    set_profile(OWNER, {"love_languages": {}, "family_values_scores": {"budget": 100},
                        "cases_answered": 1, "completed_at": "2026-08-24 08:00:00",
                        "version": 1, "psychotype_text": None})
    html = card()
    assert "lk-psy__radar" not in html, "радар нарисован без данных"
    assert "Бюджет семьи" in html, "ценностные измерения пропали"
    assert_clean(html)


def test_single_axis_hides_radar():
    # один ответ даёт {"words":100} — «фигура» выродилась бы в луч
    set_profile(OWNER, {"love_languages": {"words": 100}, "family_values_scores": {},
                        "cases_answered": 1, "completed_at": "2026-08-24 08:00:00",
                        "version": 1, "psychotype_text": None})
    html = card()
    assert "lk-psy__radar" not in html, "радар нарисован по одному измерению"
    assert "Слова поддержки" in html, "полосы должны остаться"
    assert_clean(html)


def test_view_does_not_recompute_profile():
    """Профиль фиксируется при опросе и при просмотрах не меняется (Э2.3-c)."""
    set_profile(OWNER, PROFILE)
    before = dbq("SELECT REPLACE(REPLACE(TO_BASE64(Psychological_profile),CHAR(10),''),"
                 "CHAR(13),'') FROM `Message204` WHERE User_ID=%d ORDER BY Message_ID LIMIT 1"
                 % OWNER)
    card()
    after = dbq("SELECT REPLACE(REPLACE(TO_BASE64(Psychological_profile),CHAR(10),''),"
                "CHAR(13),'') FROM `Message204` WHERE User_ID=%d ORDER BY Message_ID LIMIT 1"
                % OWNER)
    assert before == after, "просмотр карточки изменил профиль"


# --- доступ -------------------------------------------------------------------

def test_candidate_does_not_see_psy_block():
    # владелец анкеты может открыть свою карточку, но психопрофиль — инструмент
    # шадхана
    set_profile(CAND_UID, PROFILE)
    own_card = "/my/my-candidates/moi-kandidaty_%d.html" % CAND_ANKETA
    code, html = get_page(own_card, login(CANDIDATE))
    # без этой проверки тест «зеленел» бы на любом 302/404 — то есть не защищал
    assert code == 200, "карточка кандидата не открылась: %d" % code
    assert "Психологический портрет" not in html, "кандидат видит блок психопрофиля"
    assert "71828" not in html, "кандидату виден текст психотипа"
    set_profile(CAND_UID, None)


# --- опрос у кандидата (Э2.4, вторая часть) -----------------------------------

CONSULTANT = "/my/ai-consultant/"


def clear_answers(uid):
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % uid)


def answer_case(sid, case_id, text):
    return post_json("/api/assessment_answer.php", sid,
                     {"case_id": case_id, "answer": text})


def active_cases():
    out = dbq("SELECT id FROM `assessment_cases` WHERE active=1 ORDER BY sort_order, id")
    return [int(x) for x in out.split() if x]


def test_consultant_shows_first_case():
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)
    total = len(active_cases())
    first_text = dbq("SELECT scenario_text FROM `assessment_cases` WHERE active=1 "
                     "ORDER BY sort_order, id LIMIT 1")
    code, html = get_page(CONSULTANT, login(CANDIDATE))
    assert code == 200, "консультант не открылся: %d" % code
    assert "Ценностный опрос" in html, "нет блока опроса"
    assert first_text[:40] in html, "не показан первый кейс"
    assert "Отвечено 0 из %d" % total in html, "неверный прогресс"
    assert_clean(html)


def test_case_text_is_escaped():
    clear_answers(CAND_UID)
    cid = active_cases()[0]
    old = dbq("SELECT scenario_text FROM `assessment_cases` WHERE id=%d" % cid)
    try:
        dbq("UPDATE `assessment_cases` SET scenario_text='<b>жирный</b> маркер31415' "
            "WHERE id=%d" % cid)
        _, html = get_page(CONSULTANT, login(CANDIDATE))
        assert "<b>жирный</b>" not in html, "XSS: текст кейса не экранирован"
        assert "&lt;b&gt;" in html and "маркер31415" in html
    finally:
        dbq("UPDATE `assessment_cases` SET scenario_text='%s' WHERE id=%d"
            % (sql_str(old), cid))


def test_answer_returns_next_and_progress():
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"words": 4}, "confidence": 0.9})
    cases = active_cases()
    code, data = answer_case(login(CANDIDATE), cases[0], "мне важны слова поддержки")
    assert code == 200 and data.get("ok") is True, "ответ не принят: %s" % data
    assert data.get("next") and data["next"]["id"] == cases[1],         "не пришёл следующий кейс: %s" % data.get("next")
    assert data.get("answered") == 1 and data.get("total") == len(cases),         "неверный прогресс: %s" % data


def test_page_advances_after_answer():
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"time": 3}, "confidence": 0.8})
    cases = active_cases()
    sid = login(CANDIDATE)
    answer_case(sid, cases[0], "ответ на первый")
    _, html = get_page(CONSULTANT, sid)
    second = dbq("SELECT scenario_text FROM `assessment_cases` WHERE id=%d" % cases[1])
    assert second[:40] in html, "страница не перешла ко второму кейсу"
    assert "Отвечено 1 из %d" % len(cases) in html, "прогресс не обновился"


def test_finished_state_hides_psychotype_text():
    """Кандидат видит свои проценты, но НЕ текст психотипа: он написан для шадхана."""
    clear_answers(CAND_UID)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"words": 3}, "confidence": 0.9})
    sid = login(CANDIDATE)
    for cid in active_cases():
        answer_case(sid, cid, "ответ на кейс %d" % cid)
    # подменяем текст психотипа заметным маркером
    prof = read_profile(CAND_UID)
    assert prof, "профиль не построился после опроса"
    prof["psychotype_text"] = "СЕКРЕТНОЕ ОПИСАНИЕ ДЛЯ ШАДХАНА 98765"
    set_profile(CAND_UID, prof)
    _, html = get_page(CONSULTANT, sid)
    assert "Опрос пройден" in html, "не показано завершение опроса"
    assert "98765" not in html, "кандидату показан текст психотипа"
    assert "Слова поддержки" in html, "кандидату не показаны его проценты"
    assert "Пройти заново" in html, "нет кнопки повторного прохождения"
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)


def test_retake_warns_about_new_version():
    # по правилам Э2.3 повтор начинает новую версию, и шадхан увидит профиль
    # только по новым ответам — предупреждаем об этом явно
    clear_answers(CAND_UID)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"words": 3}, "confidence": 0.9})
    sid = login(CANDIDATE)
    for cid in active_cases():
        answer_case(sid, cid, "ответ")
    _, html = get_page(CONSULTANT, sid)
    assert "confirm(" in html, "повтор не переспрашивает"
    assert "заново" in html and "новый профиль" in html,         "предупреждение не объясняет последствия повтора"
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)


def test_retake_actually_restarts_survey():
    """Кнопка обещала новый проход — проверяем, что он действительно начинается."""
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"words": 3}, "confidence": 0.9})
    sid = login(CANDIDATE)
    cases = active_cases()
    for cid in cases:
        answer_case(sid, cid, "ответ")
    # обычная страница показывает завершение
    _, done = get_page(CONSULTANT, sid)
    assert "Опрос пройден" in done

    # а по ссылке повтора — снова первый кейс и нулевой прогресс
    _, again = get_page(CONSULTANT + "?retake=1", sid)
    first_text = dbq("SELECT scenario_text FROM `assessment_cases` WHERE id=%d" % cases[0])
    assert first_text[:40] in again, "повтор не показал первый кейс"
    assert "Отвечено 0 из %d" % len(cases) in again, "прогресс не обнулился при повторе"

    # ответ в новом проходе создаёт версию 2
    answer_case(sid, cases[0], "ответ нового прохода")
    version = dbq("SELECT MAX(version) FROM `assessment_answers` WHERE user_id=%d" % CAND_UID)
    assert version == "2", "новый проход не создал версию 2: " + version
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)


def test_psychotype_text_not_returned_to_candidate():
    """Текст психотипа не должен приходить кандидату даже в JSON-ответе."""
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"words": 3}, "confidence": 0.9})
    sid = login(CANDIDATE)
    last = None
    for cid in active_cases():
        code, last = answer_case(sid, cid, "ответ")
    assert last and last.get("profile"), "профиль не пришёл в ответе"
    assert "psychotype_text" not in last["profile"],         "текст психотипа ушёл кандидату в JSON: %s" % list(last["profile"].keys())
    clear_answers(CAND_UID)
    set_profile(CAND_UID, None)


def test_other_users_answers_not_leaked():
    clear_answers(CAND_UID)
    use_fake_provider()
    set_fake_mode("classify", tool_args={"scores": {"budget": 4}, "confidence": 0.9})
    cases = active_cases()
    answer_case(login(SHADHAN), cases[0], "ответ шадхана 55555")
    _, html = get_page(CONSULTANT, login(CANDIDATE))
    assert "55555" not in html, "чужой ответ виден"
    assert "Отвечено 0 из" in html, "чужие ответы попали в мой прогресс"
    shadhan_uid = int(dbq("SELECT User_ID FROM `User` WHERE Email='%s'" % SHADHAN))
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d" % shadhan_uid)
    dbq("UPDATE `Message204` SET Psychological_profile=NULL WHERE User_ID=%d" % shadhan_uid)
