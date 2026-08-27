# -*- coding: utf-8 -*-
"""
Тесты подстановки RAG-чанков в промпт консультанта (ROADMAP Э2.1-d).

Поднимается НАСТОЯЩИЙ ретривер-сервис (rag/service, оффлайн-эмбеддер,
1271 реальный чанк) на хосте; PHP из контейнера ходит к нему через
host.docker.internal. Правила: отвечать по фрагментам, не выдумывать,
пересказ вместо длинных цитат (юр. требование); сервис лежит — чат
работает без фрагментов.

    python -m pytest tests/rag_prompt_test.py -q
"""
import json
import os
import subprocess
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fake_llm import (ROOT, clear_ai_settings, dbq, login, post_json,
                      set_fake_mode, setting, start_fake_server,
                      stop_fake_server, use_fake_provider)

CANDIDATE = "u091ff2b36c83@example.local"
RAG_PORT = 8123
RAG_URL_FOR_PHP = "http://host.docker.internal:%d" % RAG_PORT

RAG_PROC = None


def chat(sid, text):
    return post_json("/api/ai_chat.php", sid, {"message": text})


def start_rag():
    global RAG_PROC
    # 0.0.0.0 сознательно (README советует 127.0.0.1): PHP ходит из
    # контейнера через host.docker.internal — это работает на Docker Desktop;
    # на Linux-CI понадобился бы extra_hosts: host-gateway
    RAG_PROC = subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "--factory", "rag.service.app:get_app",
         "--host", "0.0.0.0", "--port", str(RAG_PORT)],
        cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    for _ in range(60):
        try:
            with urllib.request.urlopen("http://127.0.0.1:%d/health" % RAG_PORT,
                                        timeout=2) as r:
                if r.status == 200:
                    return
        except Exception:
            pass
        time.sleep(0.5)
    raise RuntimeError("RAG-сервис не поднялся")


def stop_rag():
    global RAG_PROC
    if RAG_PROC:
        try:
            RAG_PROC.terminate()
            RAG_PROC.wait(timeout=10)
        except Exception:
            RAG_PROC.kill()
        RAG_PROC = None


def setup_module(module):
    start_fake_server()
    try:
        start_rag()
    except Exception:
        # teardown_module при упавшем setup не вызывается — убираем за собой,
        # иначе следующий прогон навсегда падает на занятом порту
        stop_rag()
        stop_fake_server()
        raise
    use_fake_provider()


def teardown_module(module):
    stop_rag()
    stop_fake_server()
    clear_ai_settings()
    dbq("DELETE FROM `ai_chat_messages` WHERE user_id=50")


def test_health_real_chunks():
    with urllib.request.urlopen("http://127.0.0.1:%d/health" % RAG_PORT,
                                timeout=5) as r:
        health = json.loads(r.read().decode("utf-8"))
    assert health["chunks"] > 1000, "сервис поднялся не на реальных чанках"


def test_chunks_reach_prompt_with_rules():
    setting("rag_url", RAG_URL_FOR_PHP)
    set_fake_mode("echo_system")
    code, data = chat(login(CANDIDATE), "Что важно в шаббат для семьи?")
    assert code == 200, "чат не ответил: %s" % data
    sent = data.get("reply", "")
    assert "Фрагменты книг" in sent, "чанки не дошли до промпта"
    assert "— [" in sent, "нет ни одного фрагмента с пометкой книги"
    assert "своими словами" in sent, "нет правила пересказа (юр. требование)"
    assert "не выдумывайте источников" in sent, "нет запрета выдумывать источники"
    assert "не относятся к теме" in sent, \
        "нет правила игнорировать нерелевантное (до Э2.1-b порога нет)"
    # преамбула безопасности повторена ПОСЛЕ данных книг — главный инвариант
    assert sent.rfind("Не спрашивайте") > sent.find("Фрагменты книг"), \
        "преамбула не повторена после фрагментов"
    # фрагменты без переносов строк внутри текста: границы блока целы
    # (зона — от заголовка до строки правил, дальше могут идти другие блоки)
    frag_zone = sent[sent.find("Фрагменты книг"):sent.find("Отвечая на вопросы")]
    for line in frag_zone.split("\n"):
        assert not line or line.startswith(("Фрагменты", "— [")), \
            "перенос строки из чанка порвал структуру блока: %r" % line[:60]


def test_rag_and_case_together_preamble_last():
    # единственный случай, где флаг «данные добавлены» делает что-то
    # нетривиальное: оба блока (книги + кейс) есть, преамбула — после обоих
    setting("rag_url", RAG_URL_FOR_PHP)
    set_fake_mode("echo_system")
    dbq("DELETE FROM `assessment_answers` WHERE user_id=50")
    code, data = chat(login(CANDIDATE), "Расскажите о ценностях семьи в традиции")
    assert code == 200
    sent = data.get("reply", "")
    assert "Фрагменты книг" in sent and "record_case_answer" in sent, \
        "нет одного из блоков данных (книги/кейс)"
    last_preamble = sent.rfind("Не спрашивайте")
    assert last_preamble > sent.find("Фрагменты книг"), "преамбула раньше книг"
    assert last_preamble > sent.find("record_case_answer"), "преамбула раньше кейса"
    # преамбула в конце ровно одна (не по разу на блок)
    assert sent.count("Не спрашивайте") == 2, \
        "преамбула должна быть в начале и один раз в конце"


def test_short_message_skips_rag():
    # «привет» осмысленного поиска не даёт: хэш-эмбеддер до Э2.1-b ранжирует
    # короткие запросы шумом (замер ревью: score «привет» выше, чем у
    # содержательного вопроса) — ретривер не дёргаем
    setting("rag_url", RAG_URL_FOR_PHP)
    set_fake_mode("echo_system")
    code, data = chat(login(CANDIDATE), "привет")
    assert code == 200
    assert "Фрагменты книг" not in data.get("reply", ""), \
        "короткое сообщение получило фрагменты"


def test_rag_service_down_chat_still_works_and_fast():
    setting("rag_url", "http://host.docker.internal:59999")   # мёртвый порт
    set_fake_mode("ok")
    t0 = time.time()
    code, data = chat(login(CANDIDATE), "расскажите про важное в семье")
    elapsed = time.time() - t0
    assert code == 200, "лежащий RAG уронил чат: %s" % data
    assert data.get("reply"), "пустой ответ при лежащем RAG"
    # connection refused отбивается мгновенно; худший случай (blackhole)
    # ограничен rag_timeout=3с — держим общий запас
    assert elapsed < 10, "чат при лежащем RAG занял %.1fс" % elapsed


def test_rag_off_no_fragments():
    setting("rag_url", "")
    set_fake_mode("echo_system")
    code, data = chat(login(CANDIDATE), "Что важно в шаббат?")
    assert code == 200
    assert "Фрагменты книг" not in data.get("reply", ""), \
        "фрагменты в промпте при выключенном RAG"
