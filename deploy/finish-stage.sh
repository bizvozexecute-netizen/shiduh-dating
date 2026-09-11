#!/usr/bin/env bash
# Довести стенд после старта контейнеров. Запускается на сервере отсоединённо
# (nohup), чтобы обрыв SSH не прерывал работу: импорт, проверка обезличивания,
# домен, миграции, заглушка провайдера, крон, заглушка index.html, сертификат.
set -u
cd /home/shiduh/shiduh
C="docker compose -f docker-compose.stage.yml"
DOMAIN="${1:-31-129-111-63.sslip.io}"
STAGE_PASS="${2:-srAogX53ZoZ4tlyz}"
step() { echo; echo "=== $1 ==="; }

step "жду базу"
for i in $(seq 1 40); do
  $C exec -T db sh -c 'mysqladmin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1 && { echo "база отвечает"; break; }
  sleep 3
done

step "импорт"
$C exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS shiduh_org; CREATE DATABASE shiduh_org DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci; GRANT ALL ON shiduh_org.* TO shiduh_org@\"%\"; FLUSH PRIVILEGES;"' 2>&1 | grep -v Warning
$C exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --init-command="SET SESSION sql_mode=\"NO_AUTO_VALUE_ON_ZERO\"" shiduh_org < /out/dump.anonymized.sql' 2>&1 | grep -v Warning
$C exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT CONCAT(\"анкет: \", (SELECT COUNT(*) FROM Message204), \", пользователей: \", (SELECT COUNT(*) FROM User))" shiduh_org' 2>&1 | grep -v Warning

step "обезличивание (итоги по колонке «Итог»)"
$C exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B shiduh_org < /tools/03_verify.sql' 2>&1 | grep -v Warning | awk -F'\t' '{print $NF}' | sort | uniq -c

step "домен и лицензия"
$C exec -T db sh /tools/05_set_domain.sh "$DOMAIN" 2>&1 | grep -v Warning | tail -4

step "миграции"
$C exec -T web php /var/www/html/migrations/migrate.php up 2>&1 | tail -2
echo "применено: $($C exec -T web php /var/www/html/migrations/migrate.php status 2>&1 | grep -c 'применена')"

step "провайдер-заглушка, крон, index.html"
$C exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "INSERT INTO Settings (Module,\`Key\`,\`Value\`,Catalogue_ID) VALUES (\"ai\",\"provider\",\"stub\",0) ON DUPLICATE KEY UPDATE \`Value\`=\"stub\";" shiduh_org' 2>&1 | grep -v Warning
printf 'SHELL=/bin/bash\n0 9 * * * root cd /home/shiduh/shiduh && /usr/bin/docker compose -f docker-compose.stage.yml exec -T web php /var/www/html/check_birthday_candidates/index.php >> /var/log/shiduh-birthday.log 2>&1\n' > /etc/cron.d/shiduh-stage-birthday
chmod 644 /etc/cron.d/shiduh-stage-birthday
rm -f shiduh.org/index.html
echo "ок"

step "сертификат и главная"
for i in $(seq 1 20); do
  $C logs proxy 2>&1 | grep -q "certificate obtained" && { echo "сертификат получен"; break; }
  sleep 5
done
curl -s -o /dev/null -w "главная без пароля: %{http_code}\n" "https://$DOMAIN/"
curl -s -o /dev/null -w "главная с паролем: %{http_code}, %{size_download} байт\n" -u "team:$STAGE_PASS" "https://$DOMAIN/"

echo; echo "FINISH_DONE"
