# -*- coding: utf-8 -*-
"""
Живой прогон ИИ-функций на стенде с настоящим провайдером (OpenRouter) —
подбор модели по цене и качеству.

Что делает для каждой модели из списка:
  1. выставляет провайдера, ключ и модель через штатный эндпоинт настроек
     (/api/ai_settings_save.php — как администратор со страницы /admin-ai.php);
  2. «Проверить подключение» (/api/ai_ping.php);
  3. диалог кандидата с консультантом в новом треде: знакомство → факты для
     автозаполнения анкеты (город, рост, работа) → перезапись города →
     подтверждение → просьба задать вопрос из опроса → ответ на него;
  4. резюме кандидата и обоснование пары от имени ведущего шадхана;
  5. снимает токены, задержки и ошибки из ai_usage_log, считает стоимость
     по прайсу OpenRouter (файл models.json из /api/v1/models) и печатает
     сравнение; полный протокол — в JSON рядом.

Ключ НЕ хранится в репозитории: читается из файла (--key-file) или из
переменной окружения OPENROUTER_KEY. Все правки фикстурной анкеты (42,
кандидат 50) откатываются; сообщения бенч-тредов и записи журналов за
прогон удаляются.

    python tools/live_bench.py --key-file ~/openrouter.key \
        --models deepseek/deepseek-v4-flash,openai/gpt-4.1-nano
"""
import argparse
import io
import json
import os
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tests"))
from fake_llm import backup_row, dbq, login, post_json, restore_row  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"       # User 1
CANDIDATE = "u091ff2b36c83@example.local"   # User 50, анкета 42
SHADHAN = "u3f9bb8c20b01@example.local"     # User 57, ведёт анкету 2033
SHADHAN_ID = 57
CANDIDATE_ID = 50
ANKETA = 42
LED_ANKETA = 2033
# Psychological_profile: ответ на кейс пересчитывает портрет (scoring_save_profile)
ROW_FIELDS = ("City", "Height", "What_does", "Specialization", "Hobby_description", "Languages_spoken",
              "Sport_attitude", "AI_summary", "AI_summary_meta", "LastUpdated", "Psychological_profile")
LED_FIELDS = ("AI_summary", "AI_summary_meta", "LastUpdated")

DIALOG = [
    ("intro", "Здравствуйте! Что вы умеете и чем можете помочь?"),
    ("facts", "Я живу в Хайфе, мой рост 178 см, работаю программистом. Люблю походы."),
    ("overwrite", "Кстати, я недавно переехал в Тель-Авив."),
    ("confirm", "Да, поменяйте город на Тель-Авив."),
    ("confirm2", "Да, подтверждаю: Тель-Авив."),
    ("case", "Задайте мне вопрос из опроса о ценностях."),
    ("answer", "Думаю, такие вопросы стоит решать вместе с супругой, обсуждая бюджет заранее."),
]


def now_sql():
    return dbq("SELECT NOW()")


def usage_since(ts):
    # последнее поле — с маркером: пустая ошибка в конце строки иначе съедается strip() в dbq
    rows = dbq("SELECT kind, model, prompt_tokens, completion_tokens, latency_ms, attempts, ok, CONCAT('#', IFNULL(error,'')) "
               "FROM `ai_usage_log` WHERE created_at >= '%s' ORDER BY id" % ts)
    out = []
    for line in rows.splitlines():
        if not line.strip():
            continue
        k, m, pt, ct, ms, att, ok, err = line.split("\t")
        out.append({"kind": k, "model": m, "prompt": int(pt), "completion": int(ct), "ms": int(ms),
                    "attempts": int(att), "ok": ok == "1", "error": err.lstrip("#")})
    return out


def prices(models_json):
    if not models_json or not os.path.exists(models_json):
        return {}
    d = json.load(io.open(models_json, encoding="utf-8"))["data"]
    return {m["id"]: (float(m["pricing"]["prompt"]) * 1e6, float(m["pricing"]["completion"]) * 1e6) for m in d}


def set_model(sid_admin, key, model, live=False):
    """Для прогона — одна попытка и снятые лимиты (иначе сценарий упрётся в
    6 сообщений в минуту); live=True — значения для работы стенда."""
    fields = {
        "provider": "openrouter", "api_key": key, "base_url": "https://openrouter.ai/api/v1",
        "model": model, "timeout": 90, "stream_timeout": 120, "first_byte_timeout": 40,
        "max_retries": 1, "temperature": 0.4, "max_tokens": 700,
        "rate_limit_per_hour": 500, "burst_per_minute": 60,
    }
    if live:
        fields.update({"timeout": 60, "first_byte_timeout": 30, "max_retries": 2,
                       "temperature": 0.5, "rate_limit_per_hour": 30, "burst_per_minute": 6})
    code, js = post_json("/api/ai_settings_save.php", sid_admin, fields)
    assert code == 200, "настройки не сохранились: %s %s" % (code, js)


def chat(sid, thread, text):
    t0 = time.time()
    code, js = post_json("/api/ai_chat.php", sid, {"message": text, "thread": thread}, timeout=240)
    return code, js, round(time.time() - t0, 1)


def run_model(model, key, sid_admin, sid_cand, sid_shad, price, rep):
    set_model(sid_admin, key, model)
    started = now_sql()

    # 1. подключение
    code, js = post_json("/api/ai_ping.php", sid_admin, {"target": "provider"}, timeout=60)
    rep["ping"] = js
    if not (code == 200 and js.get("ok")):
        rep["problems"].append("ping: %s" % js)
        return rep

    # 2. диалог
    code, js = post_json("/api/ai_thread_new.php", sid_cand, {}, timeout=120)
    if code != 200 or not js.get("ok"):
        rep["problems"].append("thread_new: %s" % js)
        return rep
    thread = int(js["thread"])
    rep["thread"] = thread
    rep["greeting"] = js.get("message")
    before_city = dbq("SELECT IFNULL(City,'') FROM `Message204` WHERE Message_ID=%d" % ANKETA)
    for name, text in DIALOG:
        code, js, sec = chat(sid_cand, thread, text)
        step = {"step": name, "code": code, "sec": sec, "reply": (js.get("reply") or js.get("error") or js.get("_raw") or "")[:700],
                "tools": [e.get("name") for e in (js.get("tool_events") or [])]}
        if name == "facts":
            step["writes"] = dbq("SELECT GROUP_CONCAT(CONCAT(field,'=',new_value)) FROM `ai_field_writes` "
                                 "WHERE user_id=%d AND created_at >= '%s'" % (CANDIDATE_ID, started))
        if name in ("overwrite", "confirm", "confirm2"):
            step["pending"] = dbq("SELECT GROUP_CONCAT(CONCAT(field,':',proposed_value)) FROM `ai_pending_overwrites` "
                                  "WHERE user_id=%d" % CANDIDATE_ID)
            step["city_after"] = dbq("SELECT IFNULL(City,'') FROM `Message204` WHERE Message_ID=%d" % ANKETA)
        if name == "answer":
            step["case_answers"] = dbq("SELECT COUNT(*) FROM `assessment_answers` WHERE user_id=%d AND created_at >= '%s'"
                                       % (CANDIDATE_ID, started))
        rep["steps"].append(step)
        if code != 200:
            rep["problems"].append("%s: HTTP %s %s" % (name, code, step["reply"][:120]))

    # 3. резюме и обоснование — шадхан
    t0 = time.time()
    code, js = post_json("/api/summary_generate.php", sid_shad, {"anketa_id": LED_ANKETA}, timeout=240)
    rep["summary"] = {"code": code, "sec": round(time.time() - t0, 1),
                      "text": (js.get("summary") or js.get("error") or "")[:600]}
    if code != 200:
        rep["problems"].append("summary: %s" % js)
    code, js = post_json("/api/match_list.php", sid_shad, {"anketa_id": LED_ANKETA, "limit": 3}, timeout=120)
    target = 0
    for m in (js.get("matches") or []):
        target = int(m.get("anketa_id") or m.get("id") or 0)
        if target:
            break
    if target:
        dbq("DELETE FROM `match_explanations` WHERE anketa_a=%d AND anketa_b=%d" % (LED_ANKETA, target))
        t0 = time.time()
        code, js = post_json("/api/match_explain.php", sid_shad, {"anketa_id": LED_ANKETA, "target_id": target}, timeout=240)
        rep["explain"] = {"code": code, "sec": round(time.time() - t0, 1), "target": target,
                          "text": (js.get("explanation") or js.get("error") or "")[:600]}
        if code != 200:
            rep["problems"].append("explain: %s" % js)
    else:
        rep["problems"].append("match_list: пусто (%s)" % js)

    # 4. расход
    usage = usage_since(started)
    rep["usage"] = usage
    pin, pout = price.get(model, (0.0, 0.0))
    p_tok = sum(u["prompt"] for u in usage)
    c_tok = sum(u["completion"] for u in usage)
    rep["totals"] = {"calls": len(usage), "failed": sum(1 for u in usage if not u["ok"]),
                     "prompt_tokens": p_tok, "completion_tokens": c_tok,
                     "avg_ms": int(sum(u["ms"] for u in usage) / max(1, len(usage))),
                     "max_ms": max([u["ms"] for u in usage] or [0]),
                     "usd": round((p_tok * pin + c_tok * pout) / 1e6, 5)}
    rep["restored_city"] = before_city
    return rep


def snapshot_settings():
    """Настройки ИИ стенда как есть (с ключом): прогон их перезапишет."""
    out = dbq("SELECT CONCAT_WS(CHAR(31), '#', `Key`, IFNULL(`Value`,'')) FROM `Settings` WHERE `Module`='ai'")
    rows = []
    for line in (out or "").splitlines():
        parts = line.split(chr(31))
        if len(parts) == 3:
            rows.append((parts[1], parts[2]))
    return rows


def restore_settings(rows):
    dbq("DELETE FROM `Settings` WHERE `Module`='ai'")
    for k, v in rows:
        dbq("INSERT INTO `Settings` (`Module`, `Key`, `Value`, `Catalogue_ID`) VALUES ('ai', '%s', '%s', 0)"
            % (k.replace("'", "''"), v.replace("\\", "\\\\").replace("'", "''")))


def cleanup(started, saved42, saved2033, threads, pending_before):
    restore_row("Message204", ANKETA, saved42)
    restore_row("Message204", LED_ANKETA, saved2033)
    dbq("DELETE FROM `ai_field_writes` WHERE user_id=%d AND created_at >= '%s'" % (CANDIDATE_ID, started))
    dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d AND id NOT IN (%s)"
        % (CANDIDATE_ID, ",".join(pending_before) or "0"))
    dbq("DELETE FROM `assessment_answers` WHERE user_id=%d AND created_at >= '%s'" % (CANDIDATE_ID, started))
    for t in threads:
        dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d AND thread=%d" % (CANDIDATE_ID, t))
    # только следы прогона: сообщения кандидата и генерации шадхана по ведомой анкете
    dbq("DELETE FROM `ai_usage_log` WHERE created_at >= '%s' AND user_id IN (%d, %d)"
        % (started, CANDIDATE_ID, SHADHAN_ID))
    dbq("DELETE FROM `match_explanations` WHERE created_at >= '%s' AND anketa_a=%d" % (started, LED_ANKETA))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--key-file")
    ap.add_argument("--models", required=True)
    ap.add_argument("--models-json", default="")
    ap.add_argument("--out", default="")
    ap.add_argument("--keep-settings", default="", help="какую модель оставить в настройках после прогона")
    a = ap.parse_args()
    key = os.environ.get("OPENROUTER_KEY", "")
    if a.key_file:
        key = io.open(a.key_file, encoding="utf-8").read().strip()
    assert key.startswith("sk-"), "нет ключа: --key-file или OPENROUTER_KEY"

    sid_admin, sid_cand, sid_shad = login(ADMIN), login(CANDIDATE), login(SHADHAN)
    price = prices(a.models_json)
    started = now_sql()
    settings_before = snapshot_settings()
    pending_before = [p for p in (dbq("SELECT id FROM `ai_pending_overwrites` WHERE user_id=%d" % CANDIDATE_ID) or "").split() if p.isdigit()]
    saved42 = backup_row("Message204", ANKETA, ROW_FIELDS)
    saved2033 = backup_row("Message204", LED_ANKETA, LED_FIELDS)
    reports, threads = [], []
    try:
        for model in [m.strip() for m in a.models.split(",") if m.strip()]:
            print("==", model, flush=True)
            rep = {"model": model, "problems": [], "steps": []}
            try:
                run_model(model, key, sid_admin, sid_cand, sid_shad, price, rep)
            except Exception as e:  # noqa: BLE001
                rep["problems"].append("exception: %r" % e)
            if rep.get("thread"):
                threads.append(rep["thread"])
            reports.append(rep)
            t = rep.get("totals") or {}
            print("   ping=%s calls=%s failed=%s tokens=%s/%s avg=%sms max=%sms usd=%s problems=%d" % (
                (rep.get("ping") or {}).get("ok"), t.get("calls"), t.get("failed"), t.get("prompt_tokens"),
                t.get("completion_tokens"), t.get("avg_ms"), t.get("max_ms"), t.get("usd"), len(rep["problems"])), flush=True)
            # откат между моделями, чтобы каждая начинала с одного состояния:
            # анкета кандидата, метка резюме (лимит — раз в час на анкету),
            # сообщения бенч-треда (лимит новых диалогов — десять в сутки)
            restore_row("Message204", ANKETA, saved42)
            restore_row("Message204", LED_ANKETA, saved2033)
            dbq("DELETE FROM `ai_pending_overwrites` WHERE user_id=%d AND id NOT IN (%s)"
                % (CANDIDATE_ID, ",".join(pending_before) or "0"))
            if rep.get("thread"):
                dbq("DELETE FROM `ai_chat_messages` WHERE user_id=%d AND thread=%d" % (CANDIDATE_ID, rep["thread"]))
    finally:
        cleanup(started, saved42, saved2033, threads, pending_before)
        # настройки стенда возвращаются как были; --keep-settings оставляет
        # ключ и названную модель с боевыми лимитами
        restore_settings(settings_before)
        if a.keep_settings:
            set_model(sid_admin, key, a.keep_settings, live=True)
    if a.out:
        io.open(a.out, "w", encoding="utf-8").write(json.dumps(reports, ensure_ascii=False, indent=1))
    return reports


if __name__ == "__main__":
    main()
