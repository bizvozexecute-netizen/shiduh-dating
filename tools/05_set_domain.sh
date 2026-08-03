#!/bin/sh
# ===========================================================================
#  Настройка залитой базы под домен конкретного окружения.
#
#  Зачем: NetCat опознаёт сайт по HTTP_HOST, сверяя его с Catalogue.Domain.
#  Скрипт обезличивания ставит туда 'localhost' — для стенда это надо
#  переписать, иначе сайт просто не откроется, причём молча.
#
#  Запуск:  docker compose -f docker-compose.stage.yml exec db \
#               sh /tools/05_set_domain.sh stage.example.org
# ===========================================================================
set -eu

DOMAIN="${1:-${STAGE_DOMAIN:-}}"
if [ -z "$DOMAIN" ]; then
    echo "Укажите домен: sh /tools/05_set_domain.sh stage.example.org" >&2
    exit 1
fi

DB="${MYSQL_DATABASE:-shiduh_org}"
MYSQL="mysql -h 127.0.0.1 -uroot -p${MYSQL_ROOT_PASSWORD}"

echo "==> Ставлю домен: $DOMAIN"
$MYSQL "$DB" -e "UPDATE Catalogue SET Domain='$DOMAIN' WHERE Catalogue_ID=1;"

echo "==> Продлеваю демо-период NetCat на год"
# Формула — из netcat_regkey.php и функции nc_demo_expired в connect_io.php.
# Без этого поверх сайта ляжет модалка «пробный период закончился».
$MYSQL "$DB" -e "
SET @d = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 365 DAY), '%Y-%m-%d %H:%i:%s');
UPDATE Settings SET Value=@d WHERE \`Key\`='InstallationDateOut';
UPDATE Settings SET Value=SHA1(CONCAT('Net_^^_Cat', @d,
  'nc63968534304df688ffedc8ca2e3f59f4a26c255b010e5bc647121dca79e55017726',
  SUBSTRING(@d,8,3))) WHERE \`Key\`='InstallationID';"

echo "==> Страхуюсь: почта заперта в mailpit"
$MYSQL "$DB" -e "
UPDATE Settings SET Value='Smtp' WHERE \`Key\`='SpamUseTransport';
UPDATE Settings SET Value='mail' WHERE \`Key\`='SpamSmtpHost';
UPDATE Settings SET Value='1025' WHERE \`Key\`='SpamSmtpPort';
UPDATE Settings SET Value='0'    WHERE \`Key\`='SpamSmtpAuthUse';
UPDATE Settings SET Value=''     WHERE \`Key\`='SpamSmtpPass';
UPDATE Settings SET Value=''     WHERE \`Key\`='SpamSmtpEncryption';"

echo
echo "==> Проверка"
$MYSQL -t "$DB" -e "
SELECT 'домен каталога' AS param, Domain AS value FROM Catalogue WHERE Catalogue_ID=1
UNION ALL SELECT 'демо до', Value FROM Settings WHERE \`Key\`='InstallationDateOut'
UNION ALL SELECT 'SMTP', CONCAT(
    (SELECT Value FROM Settings WHERE \`Key\`='SpamSmtpHost'), ':',
    (SELECT Value FROM Settings WHERE \`Key\`='SpamSmtpPort'))
UNION ALL SELECT 'SMTP-пароль', IF((SELECT Value FROM Settings WHERE \`Key\`='SpamSmtpPass')='', 'пусто (верно)', 'ЗАДАН - ОПАСНО');"

echo
echo "Готово. Сайт должен открываться на https://$DOMAIN"
