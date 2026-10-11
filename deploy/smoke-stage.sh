#!/usr/bin/env bash
# ===========================================================================
#  Приёмочный прогон тестового стенда.
#
#  Запускать НА СЕРВЕРЕ после первого запуска и после каждой выкатки:
#      bash deploy/smoke-stage.sh https://stage.example.org team пароль
#
#  Проверяет то, что ломается молча: доступ по ролям, разметку чата и
#  мастера, пагинацию витрины, миниатюры, экраны админ-консоли.
#  Данные не меняет — только читает страницы.
#
#  Учётные записи — из обезличенного дампа, пароль у всех «shiduh».
# ===========================================================================
set -uo pipefail

B="${1:?укажите адрес стенда, например https://stage.example.org}"
BAUSER="${2:-}"
BAPASS="${3:-}"
A=()
[ -n "$BAUSER" ] && A=(-u "$BAUSER:$BAPASS")

ADMIN="u5760fc1c6357@example.local"        # User 1
SHADHAN="u3f9bb8c20b01@example.local"      # User 57, ведёт анкету 2033
CANDIDATE="u091ff2b36c83@example.local"    # User 50, владеет анкетой 42
PASS="shiduh"
# Витрина без параметра поиска показывает пустую форму — это не ошибка,
# список появляется только с srchPat.
SHOWCASE="/my/candidate/?srchPat%5B%5D="

ok=0; bad=0

sid() {
    curl -s -i "${A[@]}" -X POST "$B/netcat/modules/auth/" \
        -d "AuthPhase=1&REQUESTED_FROM=/my/&REQUESTED_BY=GET&catalogue=1&sub=8&cc=0&AUTH_USER=$1&AUTH_PW=$PASS" \
        | grep -oi 'PHP_AUTH_SID=[^;]*' | head -1
}
code() { curl -s -o /dev/null -w '%{http_code}' "${A[@]}" -H "Cookie: $1; PHP_AUTH_LANG=Russian" "$B$2"; }
body() { curl -s "${A[@]}" -H "Cookie: $1; PHP_AUTH_LANG=Russian" "$B$2"; }
count() { printf '%s' "$2" | grep -o "$1" | wc -l | tr -d ' '; }

pass() { echo "  ok    $1"; ok=$((ok+1)); }
fail() { echo "  ПЛОХО $1"; bad=$((bad+1)); }
is()   { [ "$2" = "$3" ] && pass "$1 ($3)" || fail "$1: ждали $2, получили $3"; }
least(){ [ "$3" -ge "$2" ] && pass "$1 ($3)" || fail "$1: ждали не меньше $2, получили $3"; }
none() { [ "$3" -eq 0 ] && pass "$1" || fail "$1: найдено $3"; }

echo "== Публичная часть =="
is "главная" 200 "$(curl -s -o /dev/null -w '%{http_code}' "${A[@]}" "$B/")"
[ -n "$BAUSER" ] && is "без пароля закрыт" 401 "$(curl -s -o /dev/null -w '%{http_code}' "$B/")"
is "английская версия" 200 "$(curl -s -o /dev/null -w '%{http_code}' "${A[@]}" "$B/en/")"
is "новости" 200 "$(curl -s -o /dev/null -w '%{http_code}' "${A[@]}" "$B/news/")"
HOME=$(curl -s "${A[@]}" "$B/")
least "слой дизайна подключён" 1 "$(count 'one\.css' "$HOME")"
none "демо-модалки NetCat нет" 0 "$(count 'пробный период' "$HOME")"

echo "== Кандидат =="
SC=$(sid "$CANDIDATE")
[ -n "$SC" ] && pass "вход кандидатом" || fail "вход кандидатом"
is "консультант" 200 "$(code "$SC" /my/ai-consultant/)"
CHAT=$(body "$SC" /my/ai-consultant/)
least "разметка чата" 1 "$(count 'sh-chat__scroll' "$CHAT")"
least "поле ввода" 1 "$(count 'sh-composer' "$CHAT")"
WIZ=$(body "$SC" /my/modify/)
is "мастер анкеты: семь шагов" 7 "$(count 'data-sh-step' "$WIZ")"
least "форма помечена для мастера" 1 "$(count 'data-sh-wizard' "$WIZ")"
none "деликатные поля не в черновике" 0 "$(count 'Health_status\"[^>]*data-sh-draft' "$WIZ")"
is "админ-консоль кандидату закрыта" 403 "$(code "$SC" /admin-ai.php)"

echo "== Шадхан =="
SS=$(sid "$SHADHAN")
[ -n "$SS" ] && pass "вход шадханом" || fail "вход шадханом"
SHOW=$(body "$SS" "$SHOWCASE")
is "витрина: 24 анкеты на странице" 24 "$(count 'lk-search-candidate-result__table-row' "$SHOW")"
least "витрина: пейджер" 1 "$(count 'sh-pager__state' "$SHOW")"
least "витрина: миниатюры" 1 "$(count 'netcat_files/' "$SHOW")"
is "мои кандидаты" 200 "$(code "$SS" /my/my-candidates/)"
is "добавление кандидата: семь шагов" 7 "$(count 'data-sh-step' "$(body "$SS" /my/add_candidate/)")"
is "подбор пар с ИИ" 200 "$(code "$SS" /my/podbor-par/)"
is "подбор пар: выбор кандидата" 1 "$(count 'id="lkPairsAnketa"' "$(body "$SS" /my/podbor-par/)")"
# «Поиск шидуха» заменён «Подбором пар с ИИ» (приёмка 2026-10) — старый адрес уводит туда
is "поиск шидуха → подбор пар" 302 "$(code "$SS" /my/poisk-shiduha/)"
is "админ-консоль шадхану закрыта" 403 "$(code "$SS" /admin-ai.php)"

echo "== Администратор =="
SA=$(sid "$ADMIN")
[ -n "$SA" ] && pass "вход администратором" || fail "вход администратором"
for p in /admin-ai.php /admin-ai-prompt.php /admin-cases.php /admin-mailing.php \
         /admin-moderation.php /admin-consent.php /admin-matches.php; do
    is "$p" 200 "$(code "$SA" "$p")"
done
AI=$(body "$SA" /admin-ai.php)
none "ключ провайдера не печатается" 0 "$(count 'sk-or-v1' "$AI")"
CONS=$(body "$SA" /admin-consent.php)
none "сведения о здоровье не печатаются" 0 "$(count 'Health_status' "$CONS")"
is "админка NetCat" 200 "$(code "$SA" /netcat/admin/)"

echo
echo "Итог: успешно $ok, провалено $bad"
[ "$bad" -eq 0 ]
