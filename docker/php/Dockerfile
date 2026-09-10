# PHP 7.4 — потолок, продиктованный кодовой базой:
#   * enshrined/svg-sanitize 0.7.2 объявляет "^5.4|^7.0";
#   * Swift Mailer 5.4.1 (netcat/require/lib/Swift) не поддерживает PHP 8;
#   * create_function() и $str{0} в netcat/require/lib/{dompdf,excel}
#     дают fatal error на PHP 8.0.
# mod_php (а не FPM) — потому что .htaccess проекта настраивает PHP
# через <IfModule mod_php7.c>.
FROM php:7.4-apache

# Debian bullseye под этим образом снят с поддержки, и его репозиторий
# безопасности перестал существовать: индекс протух, пакеты с зеркала убраны,
# в archive.debian.org ветка bullseye-security не выложена. Сборка падала на
# пустом месте (проверено 2026-09-10 при развёртывании стенда).
#
# Оставляем только основную ветку bullseye — она отдаётся зеркалом и заморожена,
# поэтому сборка снова воспроизводима. Проверку срока снимаем по той же
# причине: индекс больше никто не переподписывает.
#
# Цена решения: системные библиотеки замирают на версиях релиза без
# последующих заплат безопасности. Для стенда это приемлемо (наружу торчит
# только Caddy, PHP за ним), для прода — нет: там PHP ставит хостинг. Уйдёт
# вместе с переездом на PHP 8 (ROADMAP B-7).
RUN echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until \
    && sed -i '/debian-security/d' /etc/apt/sources.list

# --- Системные библиотеки для расширений -----------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libgmp-dev \
        libzip-dev \
        libonig-dev \
        msmtp \
        default-mysql-client \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# --- Расширения PHP --------------------------------------------------------
# curl, iconv, json, mbstring, openssl, session, tokenizer, ctype, dom,
# libxml, simplexml уже собраны в базовом образе.
# Требования ядра: netcat/system/nc_db.class.php:110 (mysqli),
# netcat/admin/report/function.inc.php:110-126 (остальные),
# netcat/modules/auth/crypto/* (gmp).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        gd \
        gmp \
        zip \
        opcache

# --- Apache ----------------------------------------------------------------
# rewrite нужен для .htaccess проекта (все 404 уходят в netcat/require/e404.php);
# headers — для nosniff на загруженных файлах (netcat_files/.htaccess, C5),
# как на проде.
RUN a2enmod rewrite headers \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# .htaccess проекта должен применяться — в Debian-образе AllowOverride по
# умолчанию None.
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/netcat-override.conf \
    && a2enconf netcat-override

# --- Перехват исходящей почты ----------------------------------------------
# Страховка на случай прямых вызовов mail(): всё уходит в mailpit,
# наружу не улетает ничего. Основной путь (Swift/SMTP) настраивается
# через таблицу Settings в tools/02_anonymize.sql.
RUN printf '%s\n' \
    'account default' \
    'host mail' \
    'port 1025' \
    'auth off' \
    'tls off' \
    'from noreply@shiduh.local' \
    > /etc/msmtprc \
    && chmod 0644 /etc/msmtprc

WORKDIR /var/www/html
