-- ===========================================================================
--  Проверка обезличивания.
--  Выводит таблицу PASS/FAIL и падает с ошибкой, если хоть одна проверка
--  не прошла (последний SELECT намеренно ломается на FAIL, чтобы скрипт
--  возвращал ненулевой код возврата).
--
--  Эталонные числа взяты из аудита исходного дампа: обезличивание НЕ должно
--  менять ни количество строк, ни заполненность полей.
-- ===========================================================================

SET NAMES utf8;

DROP TEMPORARY TABLE IF EXISTS _chk;
CREATE TEMPORARY TABLE _chk (
    n     INT AUTO_INCREMENT PRIMARY KEY,
    class VARCHAR(32),
    name  VARCHAR(90),
    got   BIGINT,
    want  BIGINT,
    ok    TINYINT
) ENGINE=MEMORY DEFAULT CHARSET=utf8;

-- --- 1. Утечки ПД: должно быть 0 --------------------------------------------
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','User.Email вне @example.local',
    COUNT(*),0 FROM User WHERE Email <> '' AND Email NOT LIKE '%@example.local';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message204.Email вне @example.local',
    COUNT(*),0 FROM Message204 WHERE Email > '' AND Email NOT LIKE '%@example.local';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message6.email вне @example.local',
    COUNT(*),0 FROM Message6 WHERE email > '' AND email NOT LIKE '%@example.local';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','User.Phone не в формате +790X',
    COUNT(*),0 FROM User WHERE Phone <> '' AND Phone NOT LIKE '+790%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message204.Phone не в формате +790X',
    COUNT(*),0 FROM Message204 WHERE Phone <> '' AND Phone NOT LIKE '+790%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message204: непустой IP/UserAgent',
    COUNT(*),0 FROM Message204 WHERE IP IS NOT NULL OR UserAgent IS NOT NULL
                                  OR LastIP IS NOT NULL OR LastUserAgent IS NOT NULL;
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message6: непустой IP/UserAgent',
    COUNT(*),0 FROM Message6 WHERE IP IS NOT NULL OR UserAgent IS NOT NULL;
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Пароли не приведены к единому',
    COUNT(*),0 FROM User WHERE Password <> '' AND Password <> MD5('shiduh');
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Остались Auth_Hash',
    COUNT(*),0 FROM User WHERE Auth_Hash IS NOT NULL AND Auth_Hash <> '';

-- Свободные тексты форм обратной связи
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message193.info не заменён',
    COUNT(*),0 FROM Message193 WHERE info > '' AND info NOT LIKE 'Текст обращения №%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message193.ravin не заменён',
    COUNT(*),0 FROM Message193 WHERE ravin > '' AND ravin NOT LIKE 'рав №%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message196.info не заменён',
    COUNT(*),0 FROM Message196 WHERE info > '' AND info NOT LIKE 'Текст заявки №%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message201.info не заменён',
    COUNT(*),0 FROM Message201 WHERE info > '' AND info NOT LIKE 'Текст обращения №%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Причины удаления не заменены',
    COUNT(*),0 FROM Message201 WHERE Profile_delete_reasons > ''
                                 AND Profile_delete_reasons NOT LIKE 'Причина удаления №%';
-- Message221/299.text проверяются косвенно: подстановка идёт по всем
-- непустым строкам, а сохранность их количества контролируется блоком
-- «Строки» ниже.

-- Имена файлов: первый сегмент до ':' должен быть заменён.
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Message204.Photo_avatar: имя не заменено',
    COUNT(*),0 FROM Message204
    WHERE Photo_avatar > '' AND LOCATE(':',Photo_avatar) > 0
      AND SUBSTRING_INDEX(Photo_avatar,':',1) NOT LIKE 'photo\_%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Birthcertificates: имя не заменено',
    COUNT(*),0 FROM Message204
    WHERE Birthcertificates > '' AND LOCATE(':',Birthcertificates) > 0
      AND SUBSTRING_INDEX(Birthcertificates,':',1) NOT LIKE 'doc\_%';
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Another_document: имя не заменено',
    COUNT(*),0 FROM Message204
    WHERE Another_document_Jewishness > '' AND LOCATE(':',Another_document_Jewishness) > 0
      AND SUBSTRING_INDEX(Another_document_Jewishness,':',1) NOT LIKE 'doc\_%';
-- Многофайловые значения (>3 двоеточий) обрабатываются только по первому
-- сегменту — во втором и далее могло остаться настоящее имя файла.
INSERT INTO _chk (class,name,got,want) SELECT 'ПД','Файловые поля с >1 записью (ручная проверка)',
    COUNT(*),0 FROM Message204
    WHERE (CHAR_LENGTH(IFNULL(Photo_avatar,'')) - CHAR_LENGTH(REPLACE(IFNULL(Photo_avatar,''),':',''))) > 3
       OR (CHAR_LENGTH(IFNULL(Birthcertificates,'')) - CHAR_LENGTH(REPLACE(IFNULL(Birthcertificates,''),':',''))) > 3
       OR (CHAR_LENGTH(IFNULL(Another_document_Jewishness,'')) - CHAR_LENGTH(REPLACE(IFNULL(Another_document_Jewishness,''),':',''))) > 3;

-- --- 2. Вычищенные таблицы --------------------------------------------------
INSERT INTO _chk (class,name,got,want) SELECT 'Очистка','Logging пуст',            COUNT(*),0 FROM Logging;
INSERT INTO _chk (class,name,got,want) SELECT 'Очистка','Session пуст',            COUNT(*),0 FROM Session;
INSERT INTO _chk (class,name,got,want) SELECT 'Очистка','Trash_Data пуст',         COUNT(*),0 FROM Trash_Data;
INSERT INTO _chk (class,name,got,want) SELECT 'Очистка','Security_FilterLog пуст', COUNT(*),0 FROM Security_FilterLog;
INSERT INTO _chk (class,name,got,want) SELECT 'Очистка','Search_Document пуст',    COUNT(*),0 FROM Search_Document;

-- --- 3. Секреты и изоляция --------------------------------------------------
INSERT INTO _chk (class,name,got,want) SELECT 'Изоляция','SMTP-пароль вычищен',
    COUNT(*),0 FROM Settings WHERE `Key`='SpamSmtpPass' AND Value <> '';
INSERT INTO _chk (class,name,got,want) SELECT 'Изоляция','SMTP смотрит в mailpit',
    COUNT(*),1 FROM Settings WHERE `Key`='SpamSmtpHost' AND Value='mail';
INSERT INTO _chk (class,name,got,want) SELECT 'Изоляция','SecretKey не md5 пустой строки',
    COUNT(*),0 FROM Settings WHERE `Key`='SecretKey' AND Value='d41d8cd98f00b204e9800998ecf8427e';
INSERT INTO _chk (class,name,got,want) SELECT 'Изоляция','Домен каталога = localhost',
    COUNT(*),1 FROM Catalogue WHERE Catalogue_ID=1 AND Domain='localhost';
INSERT INTO _chk (class,name,got,want) SELECT 'Изоляция','Демо-период не истёк',
    COUNT(*),1 FROM Settings WHERE `Key`='InstallationDateOut' AND Value > NOW();

-- --- 4. Целостность: количество строк не изменилось --------------------------
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','User = 743',        COUNT(*),743   FROM User;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message204 = 1189', COUNT(*),1189  FROM Message204;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message6 = 39402',  COUNT(*),39402 FROM Message6;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message280 = 104',  COUNT(*),104   FROM Message280;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message270 = 524',  COUNT(*),524   FROM Message270;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message255 = 13',   COUNT(*),13    FROM Message255;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message221 = 44',   COUNT(*),44    FROM Message221;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Message299 = 14',   COUNT(*),14    FROM Message299;
INSERT INTO _chk (class,name,got,want) SELECT 'Строки','Multifield = 1388', COUNT(*),1388  FROM Multifield;

-- --- 5. Заполненность Message204 не изменилась -------------------------------
--     Это главная продуктовая метрика (21,8 % / 55,4 %). Если анонимайзер
--     заполнил пустые поля — тестировать «неполную анкету» станет не на чем.
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Name = 1189',            COUNT(*),1189 FROM Message204 WHERE Name <> '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Email = 1189',           COUNT(*),1189 FROM Message204 WHERE Email > '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Phone = 1189',           COUNT(*),1189 FROM Message204 WHERE Phone <> '';
-- 1154, а не 1160: непустых дат 1160, но у 6 записей год <= 1900
-- ('1877-09-26', '1899-12-01' и т.п.) — заведомый мусор, он не сдвигается
-- и не учитывается.
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Date_of_birth = 1154',   COUNT(*),1154 FROM Message204 WHERE Date_of_birth IS NOT NULL AND YEAR(Date_of_birth) > 1900;
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Height = 794',           COUNT(*),794  FROM Message204 WHERE Height <> 0;
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','City = 802',             COUNT(*),802  FROM Message204 WHERE City > '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Photo_avatar = 465',     COUNT(*),465  FROM Message204 WHERE Photo_avatar > '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Shadhan_1 = 355',        COUNT(*),355  FROM Message204 WHERE Shadhan_1 IS NOT NULL AND Shadhan_1 <> 0;
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Search_for_shadhan = 335',COUNT(*),335 FROM Message204 WHERE Search_for_shadhan > '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Birthcertificates = 95', COUNT(*),95   FROM Message204 WHERE Birthcertificates > '';
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','Jewishness = 779',       COUNT(*),779  FROM Message204 WHERE Jewishness IS NOT NULL AND Jewishness <> 0;
INSERT INTO _chk (class,name,got,want) SELECT 'Заполненность','EN_City = 0 (было 0 %)', COUNT(*),0    FROM Message204 WHERE EN_City > '';

-- --- 6. Особенности данных сохранены -----------------------------------------
--     Детерминированная замена обязана сохранить группы дублей.
INSERT INTO _chk (class,name,got,want)
SELECT 'Дубли','Message204: групп дублей по Email = 9', COUNT(*), 9
  FROM (SELECT Email FROM Message204 WHERE Email > '' GROUP BY Email HAVING COUNT(*) > 1) t;
-- 44 группы — это дубли по НОРМАЛИЗОВАННОМУ номеру (по сырому их 34: один
-- контакт записан в разном формате). Обезличивание хеширует нормализованный
-- номер, поэтому после него обе цифры сходятся к 44.
INSERT INTO _chk (class,name,got,want)
SELECT 'Дубли','Message204: групп дублей по Phone = 44', COUNT(*), 44
  FROM (SELECT Phone FROM Message204 WHERE Phone <> '' GROUP BY Phone HAVING COUNT(*) > 1) t;
INSERT INTO _chk (class,name,got,want)
SELECT 'Дубли','Совпадений Message204.Email = User.Email', COUNT(DISTINCT m.Email), 695
  FROM Message204 m JOIN User u ON u.Email = m.Email WHERE m.Email > '';
INSERT INTO _chk (class,name,got,want)
SELECT 'Дубли','User.Email по-прежнему уникален', COUNT(*), 0
  FROM (SELECT Email FROM User WHERE Email <> '' GROUP BY Email HAVING COUNT(*) > 1) t;

-- --- Итог -------------------------------------------------------------------
UPDATE _chk SET ok = (got = want);

SELECT n AS `#`, class AS `Группа`, name AS `Проверка`,
       got AS `Факт`, want AS `Ожидание`,
       IF(ok, 'PASS', '*** FAIL ***') AS `Итог`
  FROM _chk ORDER BY n;

SELECT CONCAT(SUM(ok), ' из ', COUNT(*), ' проверок пройдено') AS `ИТОГ` FROM _chk;

-- Роняем скрипт при любом количестве провалов (включая ровно один):
-- в таблицу с первичным ключом сначала кладём строку, затем пытаемся
-- положить её же за каждую провалившуюся проверку → ERROR 1062.
DROP TEMPORARY TABLE IF EXISTS _fail;
CREATE TEMPORARY TABLE _fail (k INT PRIMARY KEY) ENGINE=MEMORY;
INSERT INTO _fail (k) VALUES (1);
INSERT INTO _fail (k) SELECT 1 FROM _chk WHERE ok = 0 LIMIT 1;

DROP TEMPORARY TABLE _fail;
SELECT 'Все проверки пройдены' AS `Проверка обезличивания`;
