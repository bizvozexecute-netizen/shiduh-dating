#!/bin/sh
# ===========================================================================
#  Выгрузка ОБЕЗЛИЧЕННОГО дампа — единственная версия базы, которую можно
#  передавать коллегам, класть в CI или прикладывать к задаче.
#
#  Запуск:  docker compose exec db sh /tools/04_export.sh
#  Результат: /tools/dump.anonymized.sql (виден на хосте как
#             tools/dump.anonymized.sql)
#
#  ВНИМАНИЕ: перед выгрузкой скрипт заново прогоняет 03_verify.sql и
#  отказывается работать, если хоть одна проверка не прошла.
# ===========================================================================
set -eu

DB="${MYSQL_DATABASE:-shiduh_org}"
MYSQL="mysql -h 127.0.0.1 -uroot -p${MYSQL_ROOT_PASSWORD}"
OUT=/tools/dump.anonymized.sql

echo "==> Перепроверяю обезличивание перед выгрузкой"
if ! $MYSQL -t "$DB" < /tools/03_verify.sql; then
    echo >&2
    echo "ОТКАЗ: проверка обезличивания не пройдена, выгрузка отменена." >&2
    exit 1
fi

echo "==> Выгружаю $DB"
mysqldump -h 127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    --default-character-set=utf8 \
    --single-transaction=false \
    --lock-tables=false \
    --hex-blob \
    --routines=false \
    --triggers=false \
    "$DB" > "$OUT"

echo "==> Готово: $OUT ($(du -h "$OUT" | cut -f1))"
echo
echo "Напоминание: этот файл всё ещё в .gitignore (*.sql) и коммитить его"
echo "не нужно — передавайте отдельно."
