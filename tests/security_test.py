# -*- coding: utf-8 -*-
"""
Проверки безопасности против локального стенда.

Запуск (окружение должно быть поднято):
    python tests/security_test.py

Тесты бьют по реальному приложению через HTTP — без моков. Пароль всех
учётных записей после обезличивания: shiduh.

Тестовые данные подобраны запросом к обезличенной базе:
  * пользователь 51 — шадхан, у него анкета 1191 в избранном;
  * анкета 33 ему НЕ доступна: не владелец, не шадхан, не в избранном,
    доступ через Message255 не выдан.
"""
import re
import sys
import urllib.request
import urllib.parse
import urllib.error

BASE = "http://localhost:8080"
SHADHAN = "ue4bcee4af47d@example.local"    # User_ID 51
GRANTEE = "ue3fd6199f1a0@example.local"    # User_ID 43
PASSWORD = "shiduh"
OWN_FAV_CANDIDATE = 1191                   # в избранном у 51
FOREIGN_CANDIDATE = 33                     # чужая анкета: у 51 нет никакого доступа
# Анкета 255 выдана пользователю 43 через Message255 (ID_User=43), при этом
# 43 ей не владелец (125), не шадхан (57/51) и не в избранном. Модерирована
# (Checked=1). Значит 43 видит её ТОЛЬКО по выданному доступу.
GRANTED_CANDIDATE = 255

results = []


def login(email, password):
    body = urllib.parse.urlencode({
        "AuthPhase": "1", "REQUESTED_FROM": "/my/", "REQUESTED_BY": "GET",
        "catalogue": "1", "sub": "8", "cc": "0",
        "AUTH_USER": email, "AUTH_PW": password,
    }).encode()
    req = urllib.request.Request(BASE + "/netcat/modules/auth/", data=body,
                                 method="POST")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **kw):
            return None

    opener = urllib.request.build_opener(NoRedirect)
    try:
        resp = opener.open(req, timeout=120)
        headers = resp.headers
    except urllib.error.HTTPError as e:
        headers = e.headers
    for raw in headers.get_all("Set-Cookie") or []:
        m = re.search(r"PHP_AUTH_SID=([^;]+)", raw)
        if m:
            return m.group(1)
    raise RuntimeError("не удалось авторизоваться под " + email)


def get(path, sid):
    """Возвращает (код ответа, тело). Редиректы не следуем — они значимы."""
    req = urllib.request.Request(BASE + path)
    req.add_header("Cookie", "PHP_AUTH_SID=%s; PHP_AUTH_LANG=Russian" % sid)

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **kw):
            return None

    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=300)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def candidate_ids(html):
    """ID анкет, попавших в выдачу.

    Префикс ссылки зависит от раздела: на /my/candidate/ это
    candidate_N.html, на /my/favourites/ — ankety-kandidatov_N.html.
    Поэтому берём любой префикс, кроме ссылок на шадханов.
    """
    return {n for pref, n in re.findall(r"([a-zA-Z-]+)_(\d+)\.html", html)
            if pref not in ("shadhan",)}


def check(name, passed, detail=""):
    results.append((name, passed, detail))
    print("  %s  %s%s" % ("OK  " if passed else "ПРОВАЛ", name,
                          ("\n          " + detail) if detail else ""))


# ===========================================================================
print("Авторизуюсь…")
sid = login(SHADHAN, PASSWORD)
print("Сессия получена.\n")

print("SQL-инъекция в поиске кандидатов")
# Список результатов включается непустым srchPat. Инъекция сейчас ломает
# SQL-запрос, и NetCat отвечает редиректом (302); безобидный ввод даёт 200.
# Поэтому корректная проверка — «ввод обезврежен и трактуется как данные»:
# ответ 200 и не больше одной анкеты, а не 302 из-за сломанного запроса.

# Опорная точка: поиск по конкретному ID возвращает ровно эту анкету.
code, html = get("/my/candidate/?srchPat=&id_candidate=%d" % FOREIGN_CANDIDATE, sid)
baseline = candidate_ids(html)
check("поиск по id_candidate вообще работает",
      code == 200 and str(FOREIGN_CANDIDATE) in baseline,
      "код %s, найдено анкет: %d" % (code, len(baseline)))

# Инъекция: «0 OR 1=1». До фикса ломает запрос → 302. После фикса
# (int) превращает ввод в 0 → 200 и ноль анкет.
inj = urllib.parse.quote("0 OR 1=1")
code, html = get("/my/candidate/?srchPat=&id_candidate=" + inj, sid)
leaked = candidate_ids(html)
check("id_candidate обезврежен (ввод как данные, не как SQL)",
      code == 200 and len(leaked) <= 1,
      "код %s, вернулось анкет: %d (ожидалось 200 и не больше 1)" % (code, len(leaked)))

# Инъекция в массив, который подставляется в IN (...) через implode.
inj2 = urllib.parse.quote("1) OR (1=1")
code, html = get("/my/candidate/?srchPat=&country[]=" + inj2, sid)
leaked2 = candidate_ids(html)
check("country[] обезврежен (ввод как данные, не как SQL)",
      code == 200,
      "код %s (ожидалось 200; 302 = запрос сломан инъекцией)" % code)

print("\nДоступ к чужой анкете через раздел «Избранное»")

code, html = get("/my/favourites/ankety-kandidatov_%d.html" % FOREIGN_CANDIDATE, sid)
rendered = "lk-questionnaire--candidate" in html
check("чужая анкета не открывается (RU)",
      not (code == 200 and rendered),
      "код %s, карточка отрисована: %s" % (code, "да" if rendered else "нет"))

# То же зеркало на английском: без правки фикс обходится через /en/.
code, html = get("/en/my/favourites/ankety-kandidatov_%d.html" % FOREIGN_CANDIDATE, sid)
rendered = "lk-questionnaire--candidate" in html
check("чужая анкета не открывается (EN)",
      not (code == 200 and rendered),
      "код %s, карточка отрисована: %s" % (code, "да" if rendered else "нет"))

print("\nДоступ по выданному доступу (Message255)")

# Отдельный пользователь — грантополучатель. Видит анкету только потому,
# что ему выдали доступ. Guard обязан проверять колонку ID_User (кому
# выдано), а не User_ID (кто выдал).
sid43 = login(GRANTEE, PASSWORD)
code, html = get("/my/favourites/ankety-kandidatov_%d.html" % GRANTED_CANDIDATE, sid43)
check("грантополучатель видит выданную ему анкету",
      code == 200 and "lk-questionnaire--candidate" in html,
      "код %s, карточка отрисована: %s"
      % (code, "да" if "lk-questionnaire--candidate" in html else "нет"))

print("\nРегрессия — то, что ломать нельзя")

code, html = get("/my/favourites/ankety-kandidatov_%d.html" % OWN_FAV_CANDIDATE, sid)
check("своя анкета из избранного по-прежнему открывается",
      code == 200 and "lk-questionnaire--candidate" in html,
      "код %s" % code)

code, html = get("/my/candidate/?srchPat=&gender=2", sid)
found = candidate_ids(html)
check("обычный поиск по полу работает",
      code == 200 and len(found) > 0,
      "найдено анкет: %d" % len(found))

code, html = get("/my/candidate/?srchPat=&height_min=170&height_max=180", sid)
found = candidate_ids(html)
check("числовой фильтр по росту работает",
      code == 200 and len(found) > 0,
      "найдено анкет: %d" % len(found))

# ===========================================================================
bad = [r for r in results if not r[1]]
print("\n" + "=" * 66)
print("Пройдено %d из %d" % (len(results) - len(bad), len(results)))
if bad:
    print("Провалено:")
    for n, _, d in bad:
        print("  - " + n)
print("=" * 66)
sys.exit(1 if bad else 0)
