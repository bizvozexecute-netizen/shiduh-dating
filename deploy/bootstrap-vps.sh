#!/usr/bin/env bash
# ===========================================================================
#  Подготовка чистого VPS (Ubuntu 22.04/24.04) под тестовый стенд.
#  Запускать один раз, от root:  bash bootstrap-vps.sh
#
#  Что делает: ставит Docker, заводит непривилегированного пользователя
#  для стенда, закрывает файрвол, включает автообновления безопасности.
#  Ничего специфичного для проекта — только машина.
# ===========================================================================
set -euo pipefail

STAGE_USER="${STAGE_USER:-shiduh}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Запускайте от root." >&2
    exit 1
fi

echo "==> Обновляю пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq ca-certificates curl gnupg ufw unattended-upgrades

echo "==> Ставлю Docker"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin
fi
docker --version
docker compose version

echo "==> Завожу пользователя $STAGE_USER"
if ! id "$STAGE_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$STAGE_USER"
fi
usermod -aG docker "$STAGE_USER"
install -d -o "$STAGE_USER" -g "$STAGE_USER" "/home/$STAGE_USER/.ssh"
if [ -f /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys "/home/$STAGE_USER/.ssh/authorized_keys"
    chown "$STAGE_USER:$STAGE_USER" "/home/$STAGE_USER/.ssh/authorized_keys"
    chmod 600 "/home/$STAGE_USER/.ssh/authorized_keys"
fi

echo "==> Подкачка"
# На машине с 2 ГБ памяти и без swap сборка образа PHP и импорт дампа
# упираются в OOM: сборщик и mysqld просят пик одновременно. Гигабайты
# подкачки медленные, но выкрутиться дают.
if [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    # база в контейнере: своп нужен как страховка, а не как основная память
    sysctl -w vm.swappiness=10
    grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
fi
free -h

echo "==> Файрвол: наружу только SSH, HTTP и HTTPS"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose

echo "==> Автообновления безопасности"
dpkg-reconfigure -f noninteractive unattended-upgrades

# Вход по паролю выключается ОТДЕЛЬНЫМ шагом и только осознанно: сделать это
# до того, как владелец сервера положил свой ключ, — значит запереть его
# снаружи (останется только консоль хостера).
if [ "${DISABLE_PASSWORD_AUTH:-0}" = "1" ]; then
    echo "==> Отключаю вход по паролю в SSH"
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
    systemctl reload ssh || systemctl reload sshd
else
    echo "==> Вход по паролю оставлен включённым."
    echo "    Положите свой ключ в ~/.ssh/authorized_keys и повторите запуск"
    echo "    с DISABLE_PASSWORD_AUTH=1 — это последний шаг подготовки машины."
fi

cat <<EOF

Готово. Дальше — под пользователем $STAGE_USER:

  ssh $STAGE_USER@<ip>
  git clone <репозиторий> shiduh && cd shiduh
  # затем deploy/README.stage.md, раздел «Первый запуск»

Порты 8025 (почта) и 13306 (база) наружу НЕ открыты — только через
SSH-туннель, это сделано намеренно.
EOF
