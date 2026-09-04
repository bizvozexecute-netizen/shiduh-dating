# -*- coding: utf-8 -*-
"""
C3 этапа «До идеала»: единый CSRF-заслон на всех изменяющих эндпоинтах.

Было: shiduh_check_origin() пропускал запрос, если браузер не прислал ни
Origin, ни Referer («fail-open»), а полноценный nc_token считался непригодным,
пока SecretKey = md5('') — общеизвестная константа. После ротации ключа
(миграция 019) токен md5(user_id + SecretKey) снова непредсказуем, значит
заслон можно сделать настоящим.

Стало: shiduh_csrf_check() — сначала fail-closed проверка источника, затем
сверка токена из заголовка X-CSRF-Token (или поля csrf_token) с nc_token
текущего пользователя. Отказ помечается кодом «csrf», чтобы его нельзя было
спутать с отказом по роли.

    python -m pytest tests/csrf_test.py -q
"""
import os
import re
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_llm import BASE, csrf_token, dbq, login, post_json  # noqa: E402

ADMIN = "u5760fc1c6357@example.local"      # User_ID 1, директор
SHADHAN = "u3f9bb8c20b01@example.local"    # User_ID 57
CANDIDATE = "uad322c9ddbba@example.local"  # User_ID 44

# Все эндпоинты, меняющие состояние. Роль — та, под которой запрос имеет
# смысл; тело намеренно минимальное: заслон обязан отработать до валидации.
ENDPOINTS = [
    # Тела намеренно НЕВАЛИДНЫЕ: заслон обязан отработать раньше валидации,
    # а тест не должен ничего записывать. С «валидными» телами он сохранял
    # промпт и ответы опроса и ронял чужие наборы через общее состояние.
    ("/api/ai_chat.php", CANDIDATE, {"message": ""}),
    ("/api/ai_greet.php", CANDIDATE, {"thread": "999999"}),        # 409: такого треда нет
    ("/api/ai_rate.php", CANDIDATE, {"message_id": "0", "rating": "5"}),
    # ai_thread_new.php у него нет невалидного тела — верный токен создал бы
    # разговор; его CSRF проверяет chat_thread_test::test_requires_csrf
    ("/api/assessment_answer.php", CANDIDATE, {"case_id": "0", "answer": ""}),
    ("/api/cases_save.php", ADMIN, {"action": ""}),
    ("/api/prompt_save.php", ADMIN, {"action": ""}),
    ("/api/ai_settings_save.php", ADMIN, {"temperature": "9"}),  # 400: вне границ, ничего не пишет
    ("/api/ai_ping.php", ADMIN, {"target": "x"}),              # 400: неизвестная цель
    ("/api/summary_generate.php", SHADHAN, {"anketa_id": "0"}),
    ("/api/match_list.php", SHADHAN, {"anketa_id": "0"}),
    ("/api/match_feedback.php", SHADHAN, {"anketa_id": "0"}),
    ("/api/match_explain.php", SHADHAN, {"anketa_id": "0"}),
    ("/api/invite_create.php", SHADHAN, {"anketa_id": "0"}),
    ("/api/invite_send_email.php", SHADHAN, {"anketa_id": "0", "email": ""}),
    ("/api/invite_claim.php", CANDIDATE, {"email": "", "password": ""}),
    ("/api/mailing_preview.php", ADMIN, {"gender": "1"}),          # только SELECT
    ("/api/mailing_send.php", ADMIN, {"subject": "", "body": ""}),
]
IDS = [e[0].rsplit("/", 1)[-1] for e in ENDPOINTS]

_SID = {}


def sid_of(email):
    if email not in _SID:
        _SID[email] = login(email)
    return _SID[email]


WATCHED = ("assessment_answers", "ai_prompts", "mailings", "invite_tokens", "ai_chat_messages")
_COUNTS = {}


def counts():
    return {t: dbq("SELECT COUNT(*) FROM `%s`" % t) for t in WATCHED}


def setup_module(module):
    _COUNTS.update(counts())


def teardown_module(module):
    """Набор проверяет заслон, а не поведение эндпоинтов, и обязан быть
    read-only: иначе он ломает наборы, читающие те же таблицы (так и вышло —
    scoring_test чистит только своего пользователя)."""
    after = counts()
    changed = {t: (_COUNTS[t], after[t]) for t in WATCHED if _COUNTS[t] != after[t]}
    assert not changed, "набор изменил состояние: %s" % changed


def is_csrf_refusal(code, js):
    return code == 403 and isinstance(js, dict) and js.get("code") == "csrf"


# ------------------------------------------------------------ токен на странице ---

def test_meta_token_present_for_authenticated():
    token = csrf_token(sid_of(SHADHAN))
    assert re.match(r"^[0-9a-f]{64}$", token or ""), "в разметке нет валидного csrf-token: %r" % token


def test_meta_token_differs_between_users():
    assert csrf_token(sid_of(SHADHAN)) != csrf_token(sid_of(CANDIDATE)), \
        "токен обязан зависеть от пользователя — иначе чужой подойдёт"


def test_meta_token_empty_for_anonymous():
    import urllib.request
    html = urllib.request.urlopen(BASE + "/", timeout=60).read().decode("utf-8", "replace")
    m = re.search(r'name="csrf-token"[^>]*content="([^"]*)"', html)
    assert not (m and m.group(1)), "анонимной странице токен не нужен: %r" % (m and m.group(1))


# ---------------------------------------------------------------- сам заслон ---

@pytest.mark.parametrize("path,who,body", ENDPOINTS, ids=IDS)
def test_rejects_request_without_token(path, who, body):
    code, js = post_json(path, sid_of(who), body, origin=BASE, csrf=False)
    assert is_csrf_refusal(code, js), "запрос без токена принят: %s %s" % (code, str(js)[:120])


@pytest.mark.parametrize("path,who,body", ENDPOINTS, ids=IDS)
def test_rejects_foreign_token(path, who, body):
    other = CANDIDATE if who != CANDIDATE else SHADHAN
    code, js = post_json(path, sid_of(who), body, origin=BASE, csrf=csrf_token(sid_of(other)))
    assert is_csrf_refusal(code, js), "принят токен другого пользователя: %s %s" % (code, str(js)[:120])


@pytest.mark.parametrize("path,who,body", ENDPOINTS, ids=IDS)
def test_rejects_when_no_origin_and_no_referer(path, who, body):
    # «fail-open» прежней проверки: браузер без Origin/Referer проходил насквозь.
    # origin=False — совсем без заголовка; origin=None означало бы «свой сайт»
    # и проверяло бы совсем не то (найдено на ревью).
    code, js = post_json(path, sid_of(who), body, origin=False, csrf=False)
    assert is_csrf_refusal(code, js), "запрос без Origin и Referer принят: %s %s" % (code, str(js)[:120])


@pytest.mark.parametrize("path,who,body", ENDPOINTS, ids=IDS)
def test_accepts_own_token(path, who, body):
    # Тело минимальное, поэтому ответ может быть 400/404/409 — важно лишь,
    # что отказ НЕ по CSRF.
    code, js = post_json(path, sid_of(who), body, origin=BASE)
    assert not is_csrf_refusal(code, js), "свой токен отвергнут: %s %s" % (code, str(js)[:160])


def test_foreign_origin_still_rejected():
    code, js = post_json("/api/ai_chat.php", sid_of(CANDIDATE), {"message": "привет"},
                         origin="https://evil.example")
    assert code == 403, "запрос с чужого источника принят: %s" % code


# ------------------------------------------------ статические инварианты C3 ---
#
# Заслон и гейты ролей должны жить в одном месте: раньше проверка источника
# была скопирована в пять эндпоинтов, JSON-конверт — в четыре, а гейт роли
# шадхана — в семь (AUDIT №36). Одна правка политики означала семь правок кода.

import io  # noqa: E402

from fake_llm import ROOT  # noqa: E402

API_DIR = os.path.join(ROOT, "shiduh.org", "api")


def api_files():
    return [n for n in sorted(os.listdir(API_DIR)) if n.endswith(".php")]


def api_src(name):
    return io.open(os.path.join(API_DIR, name), encoding="utf-8").read()


def test_no_inline_origin_checks():
    bad = [n for n in api_files() if "HTTP_ORIGIN" in api_src(n) and n != "admin_lib.php"]
    assert not bad, "встроенная проверка источника вместо shiduh_csrf_check: " + ", ".join(bad)


def test_no_json_envelope_clones():
    # Ищем по ТЕЛУ, а не по имени: клон — это функция, которая сама ставит код
    # ответа, печатает JSON и завершает запрос. Иначе под подозрение попадают
    # безобидные имена вроде chat_emitter_json (фабрика эмиттера).
    bad = []
    for n in api_files():
        if n == "admin_lib.php":
            continue
        src = api_src(n)
        for m in re.finditer(r"function (\w+)\s*\([^)]*\)\s*\{", src):
            body = src[m.end():m.end() + 400]
            if "http_response_code(" in body and "json_encode(" in body and "exit" in body:
                bad.append("%s:%s" % (n, m.group(1)))
    assert not bad, "клон JSON-конверта вместо shiduh_json: " + ", ".join(bad)


def test_no_copy_pasted_role_gates():
    bad = [n for n in api_files() if "SELECT `Type` FROM `User`" in api_src(n) and n != "admin_lib.php"]
    assert not bad, "гейт роли скопирован вместо shiduh_require_shadhan: " + ", ".join(bad)


def test_every_state_changing_endpoint_checks_csrf():
    missing = []
    for path, _who, _body in ENDPOINTS:
        name = path.rsplit("/", 1)[-1]
        if "shiduh_csrf_check(" not in api_src(name):
            missing.append(name)
    assert not missing, "нет заслона CSRF: " + ", ".join(missing)


# ------------------------------------------------- сам /js/csrf.js (без браузера) ---
#
# Тесты выше проставляют заголовок сами, поэтому обёртка fetch остаётся
# непроверенной. Гоняем её в Node на стабе DOM: важно, что заголовок
# добавляется своим изменяющим запросам и НЕ добавляется чужому сайту.

import json as _json  # noqa: E402
import subprocess  # noqa: E402
import shutil  # noqa: E402

NODE = shutil.which("node") or r"C:\Program Files\nodejs\node.exe"

HARNESS = r"""
const fs = require('fs');
const calls = [];
class H {
  constructor(init) { this.m = new Map(); if (init) for (const k in init) this.m.set(k.toLowerCase(), init[k]); }
  has(k) { return this.m.has(k.toLowerCase()); }
  set(k, v) { this.m.set(k.toLowerCase(), v); }
  get(k) { return this.m.get(k.toLowerCase()); }
}
global.Headers = H;
global.URL = require('url').URL;
global.location = { href: 'http://localhost:8080/my/', origin: 'http://localhost:8080' };
global.document = { querySelector: () => ({ getAttribute: () => 'TOKEN123' }) };
global.XMLHttpRequest = function () {};
global.XMLHttpRequest.prototype.open = function () {};
global.XMLHttpRequest.prototype.send = function () {};
global.window = global;
global.fetch = function (input, init) {
  const h = (init && init.headers) || {};
  calls.push({ url: String(input), token: h.get ? h.get('X-CSRF-Token') : undefined,
               creds: init && init.credentials });
  return Promise.resolve({});
};
eval(fs.readFileSync(process.argv[2], 'utf8'));
fetch('/api/ai_chat.php', { method: 'POST' });
fetch('/api/ai_history.php', { method: 'GET' });
fetch('https://evil.example/x', { method: 'POST' });
console.log(JSON.stringify(calls));
"""


@pytest.mark.skipif(not os.path.exists(NODE), reason="нет node для прогона браузерного кода")
def test_csrf_js_adds_header_only_to_own_unsafe_requests(tmp_path):
    harness = tmp_path / "harness.cjs"   # .cjs: выше по дереву есть package.json с "type": "module"
    harness.write_text(HARNESS, encoding="utf-8")
    js = os.path.join(ROOT, "shiduh.org", "js", "csrf.js")
    r = subprocess.run([NODE, str(harness), js], capture_output=True, text=True,
                       encoding="utf-8", timeout=60)
    assert r.returncode == 0, "csrf.js упал в node:\n" + (r.stderr or "")[:400]
    calls = _json.loads(r.stdout.strip().splitlines()[-1])
    post_own, get_own, post_foreign = calls
    assert post_own["token"] == "TOKEN123", "свой POST остался без токена: %s" % post_own
    assert post_own["creds"] == "same-origin", "не проставлены куки: %s" % post_own
    assert get_own.get("token") is None, "GET не требует токена: %s" % get_own
    assert post_foreign.get("token") is None, "токен утёк на чужой сайт: %s" % post_foreign


def test_standalone_admin_pages_expose_token():
    """Автономные админ-экраны рисуют свой <head> в обход макета, поэтому
    метатег из template/3 до них не доходит — их AJAX получал бы 403.
    (Нашло adversarial-ревью: тесты этого не видели, потому что берут токен
    с главной страницы, а он валиден везде.)"""
    import urllib.request
    sid = sid_of(ADMIN)
    for page in ("/admin-mailing.php", "/admin-cases.php", "/admin-ai-prompt.php"):
        req = urllib.request.Request(BASE + page)
        req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)
        html = urllib.request.urlopen(req, timeout=60).read().decode("utf-8", "replace")
        m = re.search(r'name="csrf-token"[^>]*content="([0-9a-f]{64})"', html)
        assert m, page + ": нет метатега с токеном"
        assert m.group(1) == csrf_token(sid), page + ": токен не тот"
        assert "/js/csrf.js" in html, page + ": не подключена обёртка fetch"
