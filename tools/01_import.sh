#!/bin/sh
# ===========================================================================
#  Заливка исходного дампа в локальную БД + немедленное обезличивание.
#
#  Запускается ВНУТРИ контейнера db:
#     docker compose exec db sh /tools/01_import.sh
#
#  Сырые ПД живут в локальной базе ровно между шагом 1 и шагом 2 и дальше
#  не сохраняются нигде: исходный .sql смонтирован read-only, а наружу
#  выгружается только обезличенная версия (04_export.sh).
# ===========================================================================
set -eu

DUMP=/dump/original.sql
DB="${MYSQL_DATABASE:-shiduh_org}"
MYSQL="mysql -h 127.0.0.1 -uroot -p${MYSQL_ROOT_PASSWORD}"

if [ ! -f "$DUMP" ]; then
    echo "ОШИБКА: не найден $DUMP" >&2
    echo "Проверьте, что shiduh_org_2026-07-17.sql лежит в корне проекта." >&2
    exit 1
fi

echo "==> Пересоздаю базу $DB"
$MYSQL -e "DROP DATABASE IF EXISTS \`$DB\`;
           CREATE DATABASE \`$DB\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
           GRANT ALL ON \`$DB\`.* TO '${MYSQL_USER}'@'%';
           FLUSH PRIVILEGES;"

echo "==> Заливаю дамп ($(du -h "$DUMP" | cut -f1))"
# sql_mode сбрасывается принудительно: в дампе ~40 колонок объявлены как
# DEFAULT '0000-00-00 00:00:00' и 67 строк содержат нулевые даты.
$MYSQL --init-command="SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'" "$DB" < "$DUMP"

echo "==> Таблиц загружено: $($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'")"

echo "==> Обезличиваю"
$MYSQL "$DB" < /tools/02_anonymize.sql

echo "==> Проверяю"
$MYSQL -t "$DB" < /tools/03_verify.sql

echo
echo "Готово. Пароль всех учётных записей: shiduh"
