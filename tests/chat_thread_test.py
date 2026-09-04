# -*- coding: utf-8 -*-
"""
B7 этапа «До идеала»: «Новый диалог» отдельным тредом.

Зачем. Переписка с консультантом росла одной бесконечной лентой. Человек,
вернувшийся через месяц с другой темой, тащил в промпт двадцать реплик
прошлого разговора — модель отвечала «по мотивам» старого, а сам он не мог
ни начать с чистого листа, ни найти прежнюю беседу.

Почему номер треда, а не флаг «в архиве»: флаг требовал бы UPDATE по всем
строкам пользователя (MyISAM блокирует таблицу целиком) и слил бы прошлые
разговоры в одну кучу. Номер оставляет их раздельно читаемыми.

Что защищаем:
  - прошлый диалог — только для чтения. Дописывать в него нельзя, иначе
    реплика уходит туда, где человек её не увидит, а модель получает промпт
    не того разговора;
  - резюме шадхану по-прежнему смотрит на ВСЕ разговоры: человек рассказал о
    себе — неважно, в каком из них;
  - предел на число новых диалогов в сутки: каждый начинается приветствием,
    а с живым ключом это платный вызов.

    python -m pytest tests/chat_thread_test.py -q
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import (  # noqa: E402
    clear_ai_settings, dbq, get_json, get_text, login, post_json, set_fake_mode,
    start_fake_server, stop_fake_server, use_fake_provider,
)

CANDIDATE = "uad322c9ddbba@example.local"   # User_ID 44
NEW_THREAD = "/api/ai_thread_new.php"
PAGE = "/my/ai-consultant/"
CHAT = "/api/ai_chat.php"
HISTORY = "/api/ai_history.php"

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


def uid():
    return int(dbq("SELECT User_ID FROM User WHERE Email='%s'" % CANDIDATE))


def setup_module(module):
    start_fake_server()
    use_fake_provider()
    set_fake_mode("ok")
    wipe()


def teardown_module(module):
    stop_fake_server()
    clear_ai_settings()
    wipe()


def wipe():
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d" % uid())
    dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d" % uid())


def say(text, thread=None):
    set_fake_mode("ok")
    fields = {"message": text}
    if thread is not None:
        fields["thread"] = thread
    return post_json(CHAT, sid_of(CANDIDATE), fields)


def new_thread():
    return post_json(NEW_THREAD, sid_of(CANDIDATE), {})


def threads_in_db():
    out = dbq("SELECT DISTINCT thread FROM `ai_chat_messages` WHERE user_id=%d ORDER BY thread" % uid())
    return [int(x) for x in (out or "").split()]


# ----------------------------------------------------------- новый диалог ---

def test_new_thread_gets_next_number():
    wipe()
    say("первый разговор 11111")
    code, js = new_thread()
    assert code == 200, js
    assert js["thread"] == 2, "номер не следующий: %s" % js
    assert threads_in_db() == [1, 2], threads_in_db()


def test_new_thread_starts_with_greeting():
    """Пустой новый диалог — тот же чистый лист, от которого уходили в B5."""
    wipe()
    say("первый разговор 22222")
    code, js = new_thread()
    assert code == 200, js
    assert js.get("message"), "новый диалог без приветствия: %s" % js
    where = dbq("SELECT thread FROM `ai_chat_messages` WHERE id=%d" % js["message"]["id"])
    assert where == "2", "приветствие ушло в старый диалог: " + where


def test_old_conversation_stays_readable():
    wipe()
    say("это было раньше 33333")
    new_thread()
    code, js = get_json(HISTORY, sid_of(CANDIDATE), "thread=1")
    assert code == 200, js
    texts = " ".join(m["content"] for m in js["messages"])
    assert "33333" in texts, "прошлый разговор потерян: " + texts[:200]


def test_thread_list_shows_both():
    wipe()
    say("разговор один 44444")
    new_thread()
    code, js = get_json(HISTORY, sid_of(CANDIDATE), "threads=1")
    assert code == 200, js
    numbers = [t["thread"] for t in js["threads"]]
    assert numbers == [2, 1], "список диалогов не тот: %s" % numbers
    assert js["current"] == 2, "текущим остался старый: %s" % js.get("current")


def test_new_conversation_does_not_carry_old_context():
    """Ради чего всё и делается: в промпт нового разговора не должно попасть
    ничего из прошлого."""
    wipe()
    say("я говорил про Тверию 55555")
    new_thread()
    set_fake_mode("echo_dialog")
    code, js = post_json(CHAT, sid_of(CANDIDATE), {"message": "новый вопрос 66666"})
    assert code == 200, js
    dialog = js.get("reply", "")
    assert "66666" in dialog, "новая реплика не дошла до модели"
    assert "55555" not in dialog, "в новый разговор затащило старый: " + dialog[:300]


# ------------------------------------------------ прошлый диалог только читают ---

def test_cannot_write_into_past_thread():
    """Иначе реплика уходит туда, где человек её не увидит, а модель получает
    промпт не того разговора."""
    wipe()
    say("старый разговор 77777")
    new_thread()
    code, js = say("дописываю в старый", thread=1)
    assert code == 409, "принята запись в прошлый диалог: %s %s" % (code, js)
    assert js.get("code") == "stale_thread", js
    left = dbq("SELECT COUNT(*) FROM `ai_chat_messages` WHERE user_id=%d AND thread=1 "
               "AND content LIKE '%%дописываю%%'" % uid())
    assert left == "0", "реплика всё-таки записана в прошлый диалог"


def test_current_thread_still_writable():
    wipe()
    say("разговор 88888")
    new_thread()
    code, js = say("продолжаю здесь", thread=2)
    assert code == 200, "текущий диалог перестал принимать сообщения: %s" % js


# ------------------------------------------------------------------ пределы ---

def test_repeated_click_does_not_burn_threads():
    """Нажал «Новый диалог» дважды — это один новый диалог, а не два. Иначе
    пустые треды копятся, а с живым ключом каждый стоит приветствия."""
    wipe()
    say("разговор 31337")
    code, first = new_thread()
    code2, second = new_thread()
    assert code2 == 200, second
    assert second["thread"] == first["thread"], \
        "пустой диалог продублирован: %s → %s" % (first.get("thread"), second.get("thread"))
    assert threads_in_db() == [1, 2], threads_in_db()


def test_daily_limit_on_new_threads():
    """Каждый новый диалог начинается приветствием — с живым ключом это
    платный вызов, и кнопку нельзя оставлять без предела."""
    wipe()
    say("начало 99999")
    last = None
    for i in range(12):
        code, js = new_thread()
        last = (code, js)
        if code != 200:
            break
        say("реплика в диалоге %d" % i)   # иначе следующий вызов переиспользует пустой
    assert last[0] == 429, "предел на новые диалоги не сработал: %s" % (last,)
    # ровно предел: считается и первый разговор дня — он тоже начат сегодня
    assert len(threads_in_db()) == 10, "создано не столько, сколько разрешено: %s" % threads_in_db()


# ---------------------------------------------- обходы предела через другие входы ---

def test_greeting_cannot_open_future_thread():
    """Приветствие в несуществующий тред — это создание разговора в обход
    предела (и платный вызов на каждый POST при живом ключе). Заодно оно
    сносило бы незакрытые вопросы о замене значений из текущего разговора."""
    wipe()
    say("разговор 60606")
    before = threads_in_db()
    code, js = post_json("/api/ai_greet.php", sid_of(CANDIDATE), {"thread": 999})
    assert code == 409, "приветствие открыло выдуманный тред: %s %s" % (code, js)
    assert js.get("code") == "no_such_thread", js
    assert threads_in_db() == before, "строка в несуществующем треде: %s" % threads_in_db()


def test_chat_cannot_write_into_future_thread():
    """Тот же обход через отправку сообщения; вдобавок номер у границы int
    ломал бы «Новый диалог» этому пользователю навсегда."""
    wipe()
    say("разговор 70707")
    for bogus in (2, 2147483647):
        code, js = say("в будущее", thread=bogus)
        assert code == 409, "принята запись в несуществующий тред %d: %s" % (bogus, js)
        assert js.get("code") == "stale_thread", js
    assert threads_in_db() == [1], threads_in_db()


def test_new_thread_drops_open_questions():
    """Незакрытый вопрос «поменять X на Y?» относится к прошлому разговору —
    в новом «да» подтверждало бы неизвестно что."""
    wipe()
    say("разговор 80808")
    dbq("INSERT INTO `ai_pending_overwrites` (user_id, field, tool_key, thread, current_value, "
        "current_label, proposed_value, proposed_label, asked_message_id, asked_at) "
        "VALUES (%d, 'City', 'city', 1, 'A', 'A', 'B', 'B', 1, NOW())" % uid())
    new_thread()
    left = dbq("SELECT COUNT(*) FROM `ai_pending_overwrites` WHERE user_id=%d" % uid())
    assert left == "0", "вопрос прошлого разговора пережил новый"


def test_older_page_of_history_carries_rating():
    """Догруженные «показать ранее» ответы должны нести оценку, иначе клиент
    рисует их неоценёнными и клик молча перезаписывает прежнее."""
    wipe()
    say("оценю 90909")
    mid = int(dbq("SELECT MAX(id) FROM `ai_chat_messages` WHERE user_id=%d AND role='assistant'" % uid()))
    dbq("UPDATE `ai_chat_messages` SET rating=-1 WHERE id=%d" % mid)
    say("ещё одна 91919")
    last_user = int(dbq("SELECT MAX(id) FROM `ai_chat_messages` WHERE user_id=%d AND role='user'" % uid()))
    code, js = get_json(HISTORY, sid_of(CANDIDATE), "thread=1&before_id=%d" % last_user)
    assert code == 200, js
    rated = [m for m in js["messages"] if m["id"] == mid]
    assert rated and rated[0]["rating"] == -1, "история не отдаёт оценку: %s" % rated


def test_requires_auth():
    code, js = post_json(NEW_THREAD, None, {})
    assert code == 403, code


def test_requires_csrf():
    wipe()
    say("разговор 10101")
    code, js = post_json(NEW_THREAD, sid_of(CANDIDATE), {}, csrf=False)
    assert code == 403, "новый диалог создан без токена: %s" % code
    assert threads_in_db() == [1], threads_in_db()


def test_get_not_allowed():
    code, js = get_json(NEW_THREAD, sid_of(CANDIDATE))
    assert code == 405, code


# --------------------------------------------- резюме смотрит на все разговоры ---

def test_summary_reads_across_threads():
    """Человек рассказал о себе — неважно, в каком разговоре. Резюме шадхану
    не должно терять сказанное только потому, что человек нажал «Новый
    диалог»."""
    wipe()
    say("я преподаю музыку 12321")
    new_thread()
    say("и живу в Хайфе 45654")

    lines = dbq("SELECT content FROM `ai_chat_messages` WHERE user_id=%d AND role='user' "
                "ORDER BY id DESC LIMIT 10" % uid())
    assert "12321" in lines and "45654" in lines, \
        "реплики разных разговоров не оба на месте: " + lines

    # Сторож против правки «за компанию»: добавив треды, легко машинально
    # отфильтровать по ним и здесь — и резюме шадхану начнёт терять
    # рассказанное в прошлых разговорах.
    src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            "..", "shiduh.org", "api", "summary_lib.php"),
               encoding="utf-8").read()
    body = src.split("function summary_chat_excerpt")[1].split("\nfunction ")[0]
    assert "thread" not in body, \
        "выдержка для резюме стала смотреть только на один разговор:\n" + body


# ---------------------------------------------------------------- страница ---

def test_page_offers_new_thread_and_switcher():
    wipe()
    say("разговор 20202")
    new_thread()
    code, html = get_text(PAGE, sid_of(CANDIDATE))
    assert code == 200, code
    assert 'id="shNewThread"' in html, "нет кнопки «Новый диалог»"
    assert 'id="shThreads"' in html, "нет переключателя разговоров"
    select = html[html.find('id="shThreads"'):]
    select = select[:select.find("</select>")]
    assert select.count("<option") == 2, "в переключателе не два разговора"
    assert "Текущий разговор" in select and "Разговор от" in select, select[:300]
    assert 'id="shForm"' in html, "в текущем разговоре пропало поле ввода"


def test_switcher_hidden_for_single_thread():
    """Пустой переключатель только отвлекает: один разговор — нечего выбирать."""
    wipe()
    say("единственный разговор 30303")
    code, html = get_text(PAGE, sid_of(CANDIDATE))
    assert 'id="shThreads"' not in html, "переключатель показан при одном разговоре"


def test_past_thread_page_is_read_only():
    """Поле ввода не показываем вовсе, а не блокируем: иначе человек ищет,
    почему не отправляется. Вместо него — объяснение и дорога назад."""
    wipe()
    say("прошлый 40404")
    new_thread()
    code, html = get_text(PAGE, sid_of(CANDIDATE), "thread=1")
    assert code == 200, code
    assert "40404" in html, "прошлый разговор не показан"
    assert 'id="shForm"' not in html, "у прошлого разговора есть поле ввода"
    assert "sh-chat__readonly" in html, "нет пояснения «только для чтения»"
    assert 'href="/my/ai-consultant/"' in html, "нет дороги назад к текущему"
    assert "readOnly: true" in html, "клиент не знает, что разговор архивный"
    assert "needsGreeting: false" in html, "архивный разговор просит приветствие"


def test_bogus_thread_falls_back_to_current():
    wipe()
    say("текущий 50505")
    code, html = get_text(PAGE, sid_of(CANDIDATE), "thread=99")
    assert code == 200, code
    assert "50505" in html and 'id="shForm"' in html, "несуществующий номер сломал страницу"
