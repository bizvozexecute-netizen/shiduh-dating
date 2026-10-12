#!/usr/bin/env bash
# ===========================================================================
#  Полный прогон автотестов на Linux (Ubuntu): поднимает изолированный
#  стенд docker-compose.tests.yml, заливает обезличенный дамп, применяет
#  миграции и гоняет pytest + security_test.py.
#
#  Запуск из корня репозитория:
#      bash tests/run_ubuntu.sh                 # всё
#      bash tests/run_ubuntu.sh tests/match_test.py -x   # выборочно (аргументы pytest)
#      bash tests/run_ubuntu.sh --down          # остановить и удалить стенд с базой
#
#  Нужно: docker с compose v2, python3, pytest (python3 -m pytest или
#  PYTHONPATH с колёсами pytest — см. SHIDUH_PYLIB ниже), дамп
#  dump-out/dump.anonymized.sql. node — по желанию: без него два теста
#  браузерного кода пропускаются.
#
#  Порт 8080 на 127.0.0.1 должен быть свободен: тесты зашиты на него.
# ===========================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
export COMPOSE_FILE="$ROOT/docker-compose.tests.yml"
C="docker compose"

if [ "${1:-}" = "--down" ]; then
    $C down -v
    exit $?
fi

command -v docker >/dev/null || { echo "нет docker"; exit 2; }
[ -f dump-out/dump.anonymized.sql ] || { echo "нет dump-out/dump.anonymized.sql (только обезличенный дамп!)"; exit 2; }
[ -n "${SHIDUH_PYLIB:-}" ] && export PYTHONPATH="$SHIDUH_PYLIB${PYTHONPATH:+:$PYTHONPATH}"
python3 -m pytest --version >/dev/null 2>&1 || { echo "нет pytest: pip install pytest или SHIDUH_PYLIB=<каталог с колёсами>"; exit 2; }

# Зависимости RAG-сервиса для контейнера rag (tests/rag_prompt_test.py).
# Чанки rag/data/chunks.jsonl не в git — их надо принести отдельно.
if [ ! -d .pylib-rag/uvicorn ]; then
    echo "== зависимости RAG-сервиса (.pylib-rag)"
    docker run --rm -v "$ROOT/.pylib-rag":/t python:3.12-slim         pip install -q --no-cache-dir --target /t 'uvicorn>=0.30' 'fastapi>=0.110' 'numpy>=2' 'httpx>=0.27'         || echo "не поставились — rag-тесты упадут, остальное пройдёт"
fi
[ -f rag/data/chunks.jsonl ] || echo "ВНИМАНИЕ: нет rag/data/chunks.jsonl (не в git) — rag-тестам нечего искать"

echo "== стенд"
$C up -d --build --wait || { $C ps; exit 1; }

# Веб-сервер в контейнере пишет кэш, файлы и шаблоны; на Linux права
# bind-mount настоящие (на Windows всё открыто), поэтому открываем дерево.
$C exec -T web sh -c 'chmod -R a+rwX /var/www/html/netcat_files /var/www/html/netcat_cache /var/www/html/netcat_template 2>/dev/null; true'

sql() { $C exec -T db mysql -uroot -proot_local_dev -N -B shiduh_org -e "$1" 2>/dev/null; }
if [ "$(sql 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="shiduh_org"')" = "0" ]; then
    echo "== заливка обезличенного дампа (несколько минут)"
    $C exec -T db sh -c 'mysql -uroot -proot_local_dev --default-character-set=utf8 \
        --init-command="SET SESSION sql_mode=\"NO_AUTO_VALUE_ON_ZERO\"" shiduh_org < /dump/anonymized.sql' \
        || { echo "дамп не залит"; exit 1; }
fi

echo "== миграции"
$C exec -T web php /var/www/html/migrations/migrate.php up || exit 1

echo "== pytest"
if [ $# -gt 0 ]; then
    python3 -m pytest "$@"
    exit $?
fi
python3 -m pytest tests --ignore=tests/security_test.py -q -p no:cacheprovider
rc1=$?
echo "== security_test.py"
python3 tests/security_test.py
rc2=$?
echo "== итог: pytest rc=$rc1, security rc=$rc2"
[ $rc1 -eq 0 ] && [ $rc2 -eq 0 ]
