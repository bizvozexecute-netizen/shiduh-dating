# README.dev.md — локальное окружение shiduh.org

Как поднять копию прода на своей машине. Полный разбор системы — в [AUDIT.md](AUDIT.md).

> **Правило номер один.** Никогда не подключайте это окружение к продакшен-базе и продакшен-серверу. Разработка — только локально и только на обезличенных данных (п.1 запретов ТЗ).

---

## 0. Что нужно один раз установить

**Docker Desktop.** Требует прав администратора и интерактивного подтверждения UAC, поэтому ставится вручную:

```powershell
winget install --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
```

Установщик запросит повышение прав — подтвердите. После установки может понадобиться выход из системы или перезагрузка. Проверка:

```powershell
docker --version
docker compose version
docker info
```

WSL2 нужен как бэкенд Docker Desktop. На этой машине он уже есть (WSL 2.7.10, Ubuntu 26.04).

Больше ничего ставить не нужно: PHP, MySQL и composer живут внутри контейнеров.

---

## 1. Файлы на месте

В корне проекта должны лежать:

| Файл | Откуда |
|---|---|
| `shiduh.org/` | из архива заказчика |
| `shiduh.org/netcat_files/` | 2,68 ГБ загрузок; без них анкеты будут с битыми картинками |
| `shiduh_org_2026-07-17.sql` | дамп прода, 51 МБ |

Всё три — в `.gitignore` и в репозиторий не попадают.

---

## 2. Конфигурация

```powershell
Copy-Item .env.example .env
Copy-Item shiduh.org\vars.inc.php.example shiduh.org\vars.inc.php
```

Править `.env` обычно не нужно. Значения по умолчанию:

| Переменная | Значение | Зачем |
|---|---|---|
| `NC_MYSQL_PASSWORD` | `shiduh_local_dev` | пароль БД, читается в `vars.inc.php` через `getenv()` |
| `NC_HTTP_HOST` | `localhost:8080` | подставляется в `netcat/admin/crontab.php` вместо боевого домена |
| `HTTP_PORT` | 8080 | сайт |
| `MAILPIT_PORT` | 8025 | веб-интерфейс почты |
| `ADMINER_PORT` | 8081 | просмотр БД |
| `DB_PORT` | 13306 | MySQL наружу |

Меняйте порты, если они заняты.

---

## 3. Запуск

```powershell
docker compose up -d --build
```

Первая сборка — несколько минут (собираются расширения PHP). Дальше — секунды.

Затем залить и **сразу обезличить** базу:

```powershell
docker compose exec db sh /tools/01_import.sh
```

Скрипт делает четыре вещи: пересоздаёт базу, заливает дамп, прогоняет обезличивание, прогоняет проверку. Занимает 3–7 минут. В конце выводится таблица `PASS/FAIL` — все строки должны быть `PASS`.

Готово: **http://localhost:8080**

---

## 4. Что где

| Адрес | Что |
|---|---|
| http://localhost:8080 | сайт |
| http://localhost:8080/netcat/admin/ | админка NetCat |
| http://localhost:8025 | **вся исходящая почта** (mailpit) |
| http://localhost:8081 | Adminer, сервер `db`, база `shiduh_org` |

### Учётные записи

После обезличивания у **всех** пользователей один пароль: `shiduh`. Логин — email, он у всех вида `u<hash>@example.local`.

Найти кого-нибудь для входа:

```sql
-- кандидат
SELECT User_ID, Email, Name FROM User WHERE Type = 1 AND Checked = 1 LIMIT 5;
-- шадхан
SELECT User_ID, Email, Name FROM User WHERE Type = 2 AND Checked = 1 LIMIT 5;
-- шадхан с наибольшим числом анкет — у него интереснее всего
SELECT u.User_ID, u.Email, COUNT(*) AS anket
  FROM User u JOIN Message204 m ON m.Shadhan_1 = u.User_ID
 GROUP BY u.User_ID ORDER BY anket DESC LIMIT 5;
```

Меню личного кабинета различается по роли: у шадхана дополнительно видны «Поиск шидуха», «Создание шидуха», «История Шидуха», «Доступ к анкетам», «Мои кандидаты», «Добавить Кандидата».

---

## 5. Изоляция от прода

В окружение встроены три меры. Не отключайте их.

1. **Почта никуда не уходит.** `sendmail_path` через msmtp и настройки `Settings.SpamSmtp*` заведены на mailpit. Всё, что отправляет сайт, видно на http://localhost:8025.
2. **Cron не бьёт по проду.** В `netcat/admin/crontab.php` раньше был захардкожен боевой домен `shid.o-d.one` — cron из любого окружения дёргал задачи на проде, включая рассылку. Теперь домен берётся из `NC_HTTP_HOST`.
3. **Cron вообще не запущен.** Строка в системный crontab не добавлена. Если понадобится — запускайте вручную:
   ```powershell
   docker compose exec web php /var/www/html/netcat/admin/crontab.php
   ```
   Сначала убедитесь, что `NC_HTTP_HOST` указывает на localhost.

Плюс обезличивание вычищает SMTP-пароль, `SecretKey`, `IndexerSecretKey` и привязку модуля airee к боевому домену.

---

## 6. Повседневные операции

```powershell
# логи
docker compose logs -f web
docker compose logs -f db

# консоль MySQL
docker compose exec db mysql -uroot -proot_local_dev shiduh_org

# перепроверить обезличивание
docker compose exec db sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD -t shiduh_org < /tools/03_verify.sql'

# выгрузить обезличенный дамп для коллеги (tools/dump.anonymized.sql)
docker compose exec db sh /tools/04_export.sh

# начать с чистого листа
docker compose down -v
docker compose up -d
docker compose exec db sh /tools/01_import.sh
```

Правки шаблонов в `shiduh.org/netcat_template/` подхватываются сами (OPcache перечитывает файлы раз в 2 секунды). Перезапуск нужен только при изменении `php.ini`, `Dockerfile` или `my.cnf`.

---

## 7. Особенности, о которых стоит знать заранее

**Короткие PHP-теги обязательны.** Без `short_open_tag = On` ядро печатает `short_open_tag value must be on!` и завершает работу. Включено в `docker/php/php.ini`.

**Две копии кода на диске.** В каждой папке компонента лежит и `Class.html` (склейка), и те же куски отдельными файлами (`FormPrefix.html`, `RecordTemplate.html`, `FormSuffix.html`). Правьте через админку NetCat, иначе копии разъедутся.

**Скорость.** Webroot смонтирован с диска Windows через WSL2, и обращение к каждому файлу стоит дороже, чем на Linux. Первая загрузка страницы может занять несколько секунд, дальше выручает OPcache. Если станет невыносимо — перенесите проект внутрь файловой системы WSL2 (`\\wsl$\Ubuntu\home\...`), это даёт кратный выигрыш.

**Капча.** Ключи Yandex SmartCaptcha привязаны к домену `shiduh.org`, на localhost виджет не отрисуется. Серверной проверки токена в коде нет (см. находку №3 в AUDIT.md), поэтому формы должны отправляться и без него; если JS всё же блокирует сабмит — уберите `data-sitekey` в нужном шаблоне локально.

**Демо-лицензия NetCat.** Сайт работает в 30-дневном демо-режиме. Скрипт обезличивания продлевает его на год вперёд. Если поверх сайта всё же появилась модалка «пробный период закончился» — перезапустите `02_anonymize.sql` или откройте `http://localhost:8080/netcat_regkey.php` и примените выведенные им два `UPDATE`.

**MySQL 5.7, а не 8.** Намеренно: на MySQL 8 дамп не заливается из-за нулевых дат (~40 `DEFAULT '0000-00-00'` и 67 строк данных) против `NO_ZERO_DATE`. Апгрейд — отдельная задача, см. §11 AUDIT.md.

---

## 8. Если что-то не работает

| Симптом | Причина и что делать |
|---|---|
| `NC_MYSQL_PASSWORD is not set` | нет `.env` или `shiduh.org/vars.inc.php` — вернитесь к §2 |
| `short_open_tag value must be on!` | не подключился `docker/php/php.ini`; проверьте монтирование в `docker-compose.yml` |
| Белый экран | `docker compose logs web` — ошибки PHP пишутся в stderr контейнера |
| `ERROR 1067 Invalid default value` при импорте | поднялся MySQL 8 вместо 5.7; проверьте `image: mysql:5.7` |
| Битые картинки в анкетах | не смонтирован `shiduh.org/netcat_files/` |
| Порт занят | поменяйте `HTTP_PORT` / `DB_PORT` в `.env` |
| Разделы `/my/…` обрывают соединение | вы не авторизованы. Guard'ы редиректят на **захардкоженный `https://`**, которого локально нет (находка №16а в AUDIT.md). Сначала войдите — после авторизации редиректа не будет |
| Страницы ЛК открываются 5–8 секунд | ожидаемо: webroot смонтирован с диска Windows, плюс проблема N+1 в шаблонах (находка №17). См. §7 про перенос в WSL2 |
| Модалка «пробный период закончился» | см. §7, демо-лицензия |
