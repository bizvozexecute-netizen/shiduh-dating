-- ===========================================================================
--  Обезличивание локальной копии shiduh_org
--  Запускается ПОСЛЕ tools/01_import.sh, ДО начала любой разработки.
--  Требование ТЗ, п.1 и п.5 глобальных запретов.
--
--  Принципы:
--   1. Замены ДЕТЕРМИНИРОВАНЫ (fake = f(original)). Одинаковый исходник даёт
--      одинаковый фейк, поэтому сохраняются реальные особенности данных:
--      9 групп дублей по email, 44 по телефону и совпадение
--      Message204.Email = User.Email в 695 случаях.
--   2. Паттерн пустоты полей НЕ трогается. Заполненность 21,8 % / 55,4 % —
--      главная продуктовая метрика; если заполнить NULL фейками, тестировать
--      сценарий «неполная анкета» будет не на чем.
--   3. Связи, справочники, числа и флаги остаются как есть.
--   4. Свободные тексты заменяются на осмысленные фразы из пулов, а не на
--      lorem: они понадобятся для эмбеддингов в Э3.
-- ===========================================================================

SET NAMES utf8;
SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
SET SESSION group_concat_max_len = 1000000;

-- Соль. Меняется — меняются все фейки. Хранить не нужно.
SET @salt = 'shiduh-local-dev-2026';

-- Телефоны в базе записаны в разном формате: «+7 900 123-45-67», «79001234567»,
-- «(900) 123-45-67». Один и тот же контакт даёт разные строки, поэтому хешировать
-- надо НОРМАЛИЗОВАННЫЙ номер — иначе 44 реальные группы «один контакт на много
-- анкет» распадутся на 34. REGEXP_REPLACE в MySQL 5.7 нет, чистим вручную.
-- Использование: MD5(CONCAT(@salt, NORM(Phone))) через макрос ниже не сделать,
-- поэтому выражение повторяется в каждом UPDATE.


-- ===========================================================================
--  0. Пулы правдоподобных значений
-- ===========================================================================

DROP TABLE IF EXISTS _anon_name_m, _anon_name_f, _anon_city, _anon_prof,
                     _anon_hobby, _anon_health, _anon_wish, _anon_lorem;

CREATE TABLE _anon_name_m (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_name_m VALUES
 (0,'Авраам Гольдберг'),(1,'Ицхак Рубинштейн'),(2,'Яаков Шапиро'),(3,'Моше Каплан'),
 (4,'Аарон Лифшиц'),(5,'Давид Мизрахи'),(6,'Шломо Абрамов'),(7,'Биньямин Кацев'),
 (8,'Йосеф Ханукаев'),(9,'Реувен Бабаев'),(10,'Шимон Ильяев'),(11,'Леви Юсуфов'),
 (12,'Йегуда Пинхасов'),(13,'Нафтали Дадашев'),(14,'Гад Шаулов'),(15,'Ашер Нисанов'),
 (16,'Дан Мушаилов'),(17,'Звулун Рахамимов'),(18,'Иссахар Ягудаев'),(19,'Менаше Симхаев'),
 (20,'Эфраим Малаев'),(21,'Хаим Авшалумов'),(22,'Мордехай Изгияев'),(23,'Барух Мардахаев'),
 (24,'Элиягу Ашуров'),(25,'Натан Соломонов'),(26,'Ариэль Ефраимов'),(27,'Меир Ханукаев'),
 (28,'Шмуэль Юханаев'),(29,'Цви Мигиров'),(30,'Дов Рафаилов'),(31,'Йона Матаев'),
 (32,'Рафаэль Биньяминов'),(33,'Гавриэль Ниязов'),(34,'Михаэль Данилов'),(35,'Ури Шамаилов'),
 (36,'Эли Азарьев'),(37,'Амос Пейсахов'),(38,'Ноах Якубов'),(39,'Товия Мошиашвили');

CREATE TABLE _anon_name_f (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_name_f VALUES
 (0,'Сара Гольдберг'),(1,'Ривка Рубинштейн'),(2,'Рахель Шапиро'),(3,'Лея Каплан'),
 (4,'Мирьям Лифшиц'),(5,'Хана Мизрахи'),(6,'Эстер Абрамова'),(7,'Двора Кацева'),
 (8,'Яэль Ханукаева'),(9,'Наоми Бабаева'),(10,'Рут Ильяева'),(11,'Тамар Юсуфова'),
 (12,'Батшева Пинхасова'),(13,'Авигайль Дадашева'),(14,'Шифра Шаулова'),(15,'Дина Нисанова'),
 (16,'Циппора Мушаилова'),(17,'Билха Рахамимова'),(18,'Зильпа Ягудаева'),(19,'Йохевед Симхаева'),
 (20,'Элишева Малаева'),(21,'Хава Авшалумова'),(22,'Малка Изгияева'),(23,'Браха Мардахаева'),
 (24,'Ципора Ашурова'),(25,'Ноа Соломонова'),(26,'Ариэла Ефраимова'),(27,'Меира Ханукаева'),
 (28,'Шошана Юханаева'),(29,'Това Мигирова'),(30,'Двойра Рафаилова'),(31,'Йона Матаева'),
 (32,'Рафаэла Биньяминова'),(33,'Габриэла Ниязова'),(34,'Михаль Данилова'),(35,'Ора Шамаилова'),
 (36,'Ализа Азарьева'),(37,'Хила Пейсахова'),(38,'Нехама Якубова'),(39,'Тирца Мошиашвили');

CREATE TABLE _anon_city (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_city VALUES
 (0,'Приморск'),(1,'Заречье'),(2,'Северодольск'),(3,'Белогорск'),(4,'Новоречинск'),
 (5,'Южноморск'),(6,'Верхнегорск'),(7,'Каменнобродск'),(8,'Луговое'),(9,'Соснополь'),
 (10,'Тихоречье'),(11,'Ясногорск'),(12,'Краснодольск'),(13,'Синегорье'),(14,'Озёрное'),
 (15,'Светлоград'),(16,'Дальнереченск'),(17,'Медногорск'),(18,'Полесск'),(19,'Славногорск');

CREATE TABLE _anon_prof (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_prof VALUES
 (0,'программист'),(1,'бухгалтер'),(2,'врач-терапевт'),(3,'преподаватель математики'),
 (4,'юрист'),(5,'дизайнер'),(6,'инженер-строитель'),(7,'предприниматель'),
 (8,'медсестра'),(9,'логопед'),(10,'повар'),(11,'экономист'),
 (12,'фотограф'),(13,'парикмахер'),(14,'менеджер по продажам'),(15,'переводчик'),
 (16,'воспитатель в детском саду'),(17,'стоматолог'),(18,'водитель'),(19,'швея');

CREATE TABLE _anon_hobby (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_hobby VALUES
 (0,'Изучаю Тору, люблю пешие прогулки'),(1,'Читаю, готовлю, вожу машину'),
 (2,'Занимаюсь плаванием и бегом по утрам'),(3,'Играю на гитаре, увлекаюсь фотографией'),
 (4,'Люблю путешествия и изучение языков'),(5,'Волонтёрю в общине, помогаю с праздниками'),
 (6,'Шахматы, настольные игры, походы'),(7,'Рисую, хожу в театр'),
 (8,'Йога, здоровое питание, велосипед'),(9,'Слушаю лекции по еврейской истории');

CREATE TABLE _anon_health (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_health VALUES
 (0,'Здоров'),(1,'Здорова'),(2,'Хронических заболеваний нет'),(3,'Без ограничений'),
 (4,'Аллергия на пыльцу'),(5,'Ношу очки'),(6,'Всё в порядке'),(7,'Хорошее');

-- VARCHAR, а не TEXT: движок MEMORY не поддерживает BLOB/TEXT-колонки.
CREATE TABLE _anon_wish (i INT PRIMARY KEY, v VARCHAR(500)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_wish VALUES
 (0,'Ищу спутника жизни для создания настоящего еврейского дома. Важно, чтобы человек соблюдал традиции и уважал семью.'),
 (1,'Хочу построить дом, где будет тепло и спокойно. Ценю честность и чувство юмора.'),
 (2,'Мне важно, чтобы будущий супруг был близок по уровню соблюдения и готов учиться вместе.'),
 (3,'Ищу серьёзные отношения. Для меня главное — доброта, ответственность и общие ценности.'),
 (4,'Хочу встретить человека, с которым можно вместе расти духовно и растить детей в традиции.'),
 (5,'Ищу спокойного, надёжного человека. Готова к переезду, если это будет нужно семье.'),
 (6,'Важна близость по мировоззрению и отношение к родителям. Остальное обсуждаемо.'),
 (7,'Мечтаю о крепкой семье, где уважают друг друга и соблюдают шаббат вместе.');

CREATE TABLE _anon_lorem (i INT PRIMARY KEY, v VARCHAR(255)) ENGINE=MEMORY DEFAULT CHARSET=utf8;
INSERT INTO _anon_lorem VALUES
 (0,'Здравствуйте! Спасибо за ваше сообщение, отвечу подробнее чуть позже. '),
 (1,'Добрый день. Посмотрел анкету, готов обсудить детали при встрече. '),
 (2,'Шалом! Рад знакомству. Расскажите, пожалуйста, немного о себе. '),
 (3,'Благодарю за информацию, приму к сведению и свяжусь с вами. ');


-- ===========================================================================
--  1. Полное удаление таблиц, состоящих из одних ПД
--     Минус ~20 МБ из 51 и почти весь остаточный риск.
-- ===========================================================================

TRUNCATE TABLE Logging;              -- 67 320 строк аудита с именами и ссылками
TRUNCATE TABLE Session;              -- сессии, IP
TRUNCATE TABLE Security_FilterLog;   -- IP, Referer
TRUNCATE TABLE Trash_Data;           -- XML-дампы удалённых материалов целиком
TRUNCATE TABLE Captchas;
TRUNCATE TABLE Search_Document;      -- проиндексированные тексты страниц
TRUNCATE TABLE Search_Index;
TRUNCATE TABLE Search_Index_Field1;
TRUNCATE TABLE Search_Index_Field2;
TRUNCATE TABLE Search_Index_Field3;
TRUNCATE TABLE Search_Index_Field4;
TRUNCATE TABLE Search_Index_Field5;
TRUNCATE TABLE Search_Index_Term;
TRUNCATE TABLE Search_Log;
TRUNCATE TABLE Search_Query;
-- Stats_IP2Country НЕ трогаем: это справочник geoip, а не данные о людях.
TRUNCATE TABLE Stats_Log;
TRUNCATE TABLE Stats_IP;
TRUNCATE TABLE Stats_Geo;
TRUNCATE TABLE Stats_Attendance;
TRUNCATE TABLE Stats_Referer;
TRUNCATE TABLE Stats_Phrases;


-- ===========================================================================
--  2. User
-- ===========================================================================

UPDATE User u JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, u.Name)), 1, 6), 16, 10) % 40
   SET u.Name = p.v
 WHERE u.Gender = 1 AND u.Name <> '';

UPDATE User u JOIN _anon_name_f p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, u.Name)), 1, 6), 16, 10) % 40
   SET u.Name = p.v
 WHERE u.Gender <> 1 AND u.Name <> '';

UPDATE User SET
    Email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, Email)), 1, 12), '@example.local')
 WHERE Email <> '';

UPDATE User SET
    Phone = CONCAT('+7900', LPAD(CONV(SUBSTRING(MD5(CONCAT(@salt,
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(Phone,' ',''),'+',''),'-',''),'(',''),')',''),'.','')
    )), 1, 8), 16, 10) % 10000000, 7, '0'))
 WHERE Phone <> '';

-- Дату рождения переносим внутри того же года: распределение по декадам
-- и корреляция с Search_age_* сохраняются, точная дата теряется.
UPDATE User SET
    Date_of_birth = MAKEDATE(YEAR(Date_of_birth),
                             1 + CONV(SUBSTRING(MD5(CONCAT(@salt, Date_of_birth, User_ID)), 1, 6), 16, 10) % 365)
 WHERE Date_of_birth IS NOT NULL AND YEAR(Date_of_birth) > 1900;

UPDATE User u JOIN _anon_city c
    ON c.i = CONV(SUBSTRING(MD5(CONCAT(@salt, u.City)), 1, 6), 16, 10) % 20
   SET u.City = c.v
 WHERE u.City IS NOT NULL AND u.City <> '';

UPDATE User u JOIN _anon_city c
    ON c.i = CONV(SUBSTRING(MD5(CONCAT(@salt, u.Place_of_birth)), 1, 6), 16, 10) % 20
   SET u.Place_of_birth = c.v
 WHERE u.Place_of_birth IS NOT NULL AND u.Place_of_birth <> '';

-- Единый пароль для всех: логин под любым аккаунтом.
-- NetCat хранит MD5 средствами MySQL ($MYSQL_ENCRYPT = "MD5" в vars.inc.php).
UPDATE User SET Password = MD5('shiduh') WHERE Password <> '';

UPDATE User SET Auth_Hash = NULL, RegistrationCode = '';

-- Путь к аватару сохраняем (файл лежит в netcat_files), меняем только
-- человекочитаемое имя перед первым двоеточием — оно содержит фамилии.
UPDATE User SET
    Photo_avatar = CONCAT(
        'avatar_', SUBSTRING(MD5(CONCAT(@salt, User_ID)), 1, 10), '.',
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(Photo_avatar, ':', 1), '.', -1)),
        SUBSTRING(Photo_avatar, LOCATE(':', Photo_avatar)))
 WHERE Photo_avatar IS NOT NULL AND Photo_avatar <> '' AND LOCATE(':', Photo_avatar) > 0;


-- ===========================================================================
--  3. Message204 — анкеты кандидатов
-- ===========================================================================

UPDATE Message204 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Name)), 1, 6), 16, 10) % 40
   SET m.Name = p.v
 WHERE m.Gender = 1 AND m.Name <> '';

UPDATE Message204 m JOIN _anon_name_f p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Name)), 1, 6), 16, 10) % 40
   SET m.Name = p.v
 WHERE m.Gender <> 1 AND m.Name <> '';

-- Тот же алгоритм, что и для User → совпадение Message204.Email = User.Email
-- (695 случаев) и группы дублей сохраняются.
UPDATE Message204 SET
    Email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, Email)), 1, 12), '@example.local')
 WHERE Email IS NOT NULL AND Email <> '';

UPDATE Message204 SET
    Phone = CONCAT('+7900', LPAD(CONV(SUBSTRING(MD5(CONCAT(@salt,
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(Phone,' ',''),'+',''),'-',''),'(',''),')',''),'.','')
    )), 1, 8), 16, 10) % 10000000, 7, '0'))
 WHERE Phone <> '';

UPDATE Message204 SET
    Date_of_birth = MAKEDATE(YEAR(Date_of_birth),
                             1 + CONV(SUBSTRING(MD5(CONCAT(@salt, Date_of_birth, Message_ID)), 1, 6), 16, 10) % 365)
 WHERE Date_of_birth IS NOT NULL AND YEAR(Date_of_birth) > 1900;

UPDATE Message204 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Jewish_name)), 1, 6), 16, 10) % 40
   SET m.Jewish_name = SUBSTRING_INDEX(p.v, ' ', 1)
 WHERE m.Gender = 1 AND m.Jewish_name IS NOT NULL AND m.Jewish_name <> '';

UPDATE Message204 m JOIN _anon_name_f p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Jewish_name)), 1, 6), 16, 10) % 40
   SET m.Jewish_name = SUBSTRING_INDEX(p.v, ' ', 1)
 WHERE m.Gender <> 1 AND m.Jewish_name IS NOT NULL AND m.Jewish_name <> '';

UPDATE Message204 m JOIN _anon_city c
    ON c.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.City)), 1, 6), 16, 10) % 20
   SET m.City = c.v
 WHERE m.City IS NOT NULL AND m.City <> '';

UPDATE Message204 m JOIN _anon_city c
    ON c.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Place_of_birth)), 1, 6), 16, 10) % 20
   SET m.Place_of_birth = c.v
 WHERE m.Place_of_birth IS NOT NULL AND m.Place_of_birth <> '';

-- Свободные тексты → правдоподобные фразы (не lorem: понадобятся для
-- эмбеддингов в Э3.2).
UPDATE Message204 m JOIN _anon_prof p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Specialization)), 1, 6), 16, 10) % 20
   SET m.Specialization = p.v
 WHERE m.Specialization IS NOT NULL AND m.Specialization <> '';

UPDATE Message204 m JOIN _anon_prof p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.What_does)), 1, 6), 16, 10) % 20
   SET m.What_does = p.v
 WHERE m.What_does IS NOT NULL AND m.What_does <> '';

UPDATE Message204 m JOIN _anon_hobby p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Hobby_description)), 1, 6), 16, 10) % 10
   SET m.Hobby_description = p.v
 WHERE m.Hobby_description IS NOT NULL AND m.Hobby_description <> '';

UPDATE Message204 m JOIN _anon_health p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Health_status)), 1, 6), 16, 10) % 8
   SET m.Health_status = p.v
 WHERE m.Health_status IS NOT NULL AND m.Health_status <> '';

UPDATE Message204 m JOIN _anon_wish p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Message_ID)), 1, 6), 16, 10) % 8
   SET m.Search_for_shadhan = p.v
 WHERE m.Search_for_shadhan IS NOT NULL AND m.Search_for_shadhan <> '';

-- Контакты третьих лиц (раввин, синагога, рекомендатель)
UPDATE Message204 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Your_ravin)), 1, 6), 16, 10) % 40
   SET m.Your_ravin = CONCAT('рав ', p.v)
 WHERE m.Your_ravin IS NOT NULL AND m.Your_ravin <> '';

UPDATE Message204 SET
    Your_ravin_contact = CONCAT('+7901', LPAD(CONV(SUBSTRING(MD5(CONCAT(@salt, Your_ravin_contact)), 1, 8), 16, 10) % 10000000, 7, '0'))
 WHERE Your_ravin_contact IS NOT NULL AND Your_ravin_contact <> '';

UPDATE Message204 m JOIN _anon_city c
    ON c.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Your_sinagoga)), 1, 6), 16, 10) % 20
   SET m.Your_sinagoga = CONCAT('Синагога г. ', c.v)
 WHERE m.Your_sinagoga IS NOT NULL AND m.Your_sinagoga <> '';

UPDATE Message204 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Who_can_give_recommendation)), 1, 6), 16, 10) % 40
   SET m.Who_can_give_recommendation = p.v
 WHERE m.Who_can_give_recommendation IS NOT NULL AND m.Who_can_give_recommendation <> '';

-- Имена родственников (24 колонки). Отец и братья — из мужского пула,
-- мать и сёстры — из женского.
UPDATE Message204 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Personal_family_structure_father)), 1, 6), 16, 10) % 40
   SET m.Personal_family_structure_father = p.v
 WHERE m.Personal_family_structure_father IS NOT NULL AND m.Personal_family_structure_father <> '';

UPDATE Message204 m JOIN _anon_name_f p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Personal_family_structure_mother)), 1, 6), 16, 10) % 40
   SET m.Personal_family_structure_mother = p.v
 WHERE m.Personal_family_structure_mother IS NOT NULL AND m.Personal_family_structure_mother <> '';

-- Братья 1..10
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_1)),1,6),16,10)%40 SET m.Personal_family_structure_brother_1 = p.v WHERE m.Personal_family_structure_brother_1 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_2)),1,6),16,10)%40 SET m.Personal_family_structure_brother_2 = p.v WHERE m.Personal_family_structure_brother_2 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_3)),1,6),16,10)%40 SET m.Personal_family_structure_brother_3 = p.v WHERE m.Personal_family_structure_brother_3 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_4)),1,6),16,10)%40 SET m.Personal_family_structure_brother_4 = p.v WHERE m.Personal_family_structure_brother_4 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_5)),1,6),16,10)%40 SET m.Personal_family_structure_brother_5 = p.v WHERE m.Personal_family_structure_brother_5 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_6)),1,6),16,10)%40 SET m.Personal_family_structure_brother_6 = p.v WHERE m.Personal_family_structure_brother_6 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_7)),1,6),16,10)%40 SET m.Personal_family_structure_brother_7 = p.v WHERE m.Personal_family_structure_brother_7 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_8)),1,6),16,10)%40 SET m.Personal_family_structure_brother_8 = p.v WHERE m.Personal_family_structure_brother_8 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_9)),1,6),16,10)%40 SET m.Personal_family_structure_brother_9 = p.v WHERE m.Personal_family_structure_brother_9 > '';
UPDATE Message204 m JOIN _anon_name_m p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_brother_10)),1,6),16,10)%40 SET m.Personal_family_structure_brother_10 = p.v WHERE m.Personal_family_structure_brother_10 > '';

-- Сёстры 1..10
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_1)),1,6),16,10)%40 SET m.Personal_family_structure_sister_1 = p.v WHERE m.Personal_family_structure_sister_1 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_2)),1,6),16,10)%40 SET m.Personal_family_structure_sister_2 = p.v WHERE m.Personal_family_structure_sister_2 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_3)),1,6),16,10)%40 SET m.Personal_family_structure_sister_3 = p.v WHERE m.Personal_family_structure_sister_3 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_4)),1,6),16,10)%40 SET m.Personal_family_structure_sister_4 = p.v WHERE m.Personal_family_structure_sister_4 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_5)),1,6),16,10)%40 SET m.Personal_family_structure_sister_5 = p.v WHERE m.Personal_family_structure_sister_5 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_6)),1,6),16,10)%40 SET m.Personal_family_structure_sister_6 = p.v WHERE m.Personal_family_structure_sister_6 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_7)),1,6),16,10)%40 SET m.Personal_family_structure_sister_7 = p.v WHERE m.Personal_family_structure_sister_7 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_8)),1,6),16,10)%40 SET m.Personal_family_structure_sister_8 = p.v WHERE m.Personal_family_structure_sister_8 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_9)),1,6),16,10)%40 SET m.Personal_family_structure_sister_9 = p.v WHERE m.Personal_family_structure_sister_9 > '';
UPDATE Message204 m JOIN _anon_name_f p ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt,m.Personal_family_structure_sister_10)),1,6),16,10)%40 SET m.Personal_family_structure_sister_10 = p.v WHERE m.Personal_family_structure_sister_10 > '';

-- Файловые поля: формат <имя>:<mime>:<размер>:<путь>.
-- Меняем ТОЛЬКО имя перед первым двоеточием — оно содержит фамилии
-- (напр. «Шейнин ВМ - св-во о рождении.jpg»). Путь обязан остаться прежним,
-- иначе файлы в netcat_files перестанут находиться.
UPDATE Message204 SET
    Photo_avatar = CONCAT('photo_', SUBSTRING(MD5(CONCAT(@salt, Message_ID)), 1, 10), '.',
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(Photo_avatar, ':', 1), '.', -1)),
        SUBSTRING(Photo_avatar, LOCATE(':', Photo_avatar)))
 WHERE Photo_avatar IS NOT NULL AND Photo_avatar <> '' AND LOCATE(':', Photo_avatar) > 0;

UPDATE Message204 SET
    Birthcertificates = CONCAT('doc_', SUBSTRING(MD5(CONCAT(@salt, 'bc', Message_ID)), 1, 10), '.',
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(Birthcertificates, ':', 1), '.', -1)),
        SUBSTRING(Birthcertificates, LOCATE(':', Birthcertificates)))
 WHERE Birthcertificates IS NOT NULL AND Birthcertificates <> '' AND LOCATE(':', Birthcertificates) > 0;

UPDATE Message204 SET
    Another_document_Jewishness = CONCAT('doc_', SUBSTRING(MD5(CONCAT(@salt, 'aj', Message_ID)), 1, 10), '.',
        LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(Another_document_Jewishness, ':', 1), '.', -1)),
        SUBSTRING(Another_document_Jewishness, LOCATE(':', Another_document_Jewishness)))
 WHERE Another_document_Jewishness IS NOT NULL AND Another_document_Jewishness <> '' AND LOCATE(':', Another_document_Jewishness) > 0;


-- ===========================================================================
--  4. Message6 — личные сообщения (39 402 строки)
-- ===========================================================================

UPDATE Message6 m JOIN _anon_lorem p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Message_ID)), 1, 6), 16, 10) % 4
   SET m.Message = LEFT(REPEAT(p.v, CEIL(CHAR_LENGTH(m.Message) / 60) + 1), CHAR_LENGTH(m.Message))
 WHERE m.Message IS NOT NULL AND m.Message <> '';

UPDATE Message6 SET Subject = CONCAT('Тема сообщения №', Message_ID)
 WHERE Subject IS NOT NULL AND Subject <> '';

UPDATE Message6 m JOIN _anon_name_m p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.name)), 1, 6), 16, 10) % 40
   SET m.name = p.v
 WHERE m.name IS NOT NULL AND m.name <> '';

UPDATE Message6 SET
    email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, email)), 1, 12), '@example.local')
 WHERE email IS NOT NULL AND email <> '';


-- ===========================================================================
--  5. Приватные заметки шадханов
-- ===========================================================================

UPDATE Message221 m JOIN _anon_lorem p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Message_ID)), 1, 6), 16, 10) % 4
   SET m.text = LEFT(REPEAT(p.v, CEIL(CHAR_LENGTH(m.text) / 60) + 1), CHAR_LENGTH(m.text))
 WHERE m.text IS NOT NULL AND m.text <> '';

UPDATE Message299 m JOIN _anon_lorem p
    ON p.i = CONV(SUBSTRING(MD5(CONCAT(@salt, m.Message_ID)), 1, 6), 16, 10) % 4
   SET m.text = LEFT(REPEAT(p.v, CEIL(CHAR_LENGTH(m.text) / 60) + 1), CHAR_LENGTH(m.text))
 WHERE m.text IS NOT NULL AND m.text <> '';


-- ===========================================================================
--  6. Формы обратной связи
-- ===========================================================================

-- Помимо name/email/phone здесь есть свободные тексты: info (текст вопроса
-- или заявки), ravin (имя раввина) и Profile_delete_reasons (причина
-- удаления аккаунта) — всё это тоже ПД.
UPDATE Message193 SET
    name  = CONCAT('Пользователь ', Message_ID),
    email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, email)), 1, 12), '@example.local'),
    ravin = CASE WHEN ravin > '' THEN CONCAT('рав №', Message_ID) ELSE ravin END,
    info  = CASE WHEN info  > '' THEN CONCAT('Текст обращения №', Message_ID) ELSE info  END;

UPDATE Message196 SET
    name  = CONCAT('Партнёр ', Message_ID),
    email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, email)), 1, 12), '@example.local'),
    phone = CONCAT('+7902', LPAD(CONV(SUBSTRING(MD5(CONCAT(@salt, phone)), 1, 8), 16, 10) % 10000000, 7, '0')),
    info  = CASE WHEN info > '' THEN CONCAT('Текст заявки №', Message_ID) ELSE info END;

UPDATE Message201 SET
    name  = CONCAT('Пользователь ', Message_ID),
    email = CONCAT('u', SUBSTRING(MD5(CONCAT(@salt, email)), 1, 12), '@example.local'),
    info  = CASE WHEN info > '' THEN CONCAT('Текст обращения №', Message_ID) ELSE info END,
    Profile_delete_reasons = CASE WHEN Profile_delete_reasons > ''
                                  THEN CONCAT('Причина удаления №', Message_ID)
                                  ELSE Profile_delete_reasons END;


-- ===========================================================================
--  7. Multifield — оригинальные имена загруженных файлов
--     Path и Preview НЕ трогаем: по ним файлы находятся на диске.
-- ===========================================================================

UPDATE Multifield SET
    Name = CONCAT('file_', SUBSTRING(MD5(CONCAT(@salt, ID)), 1, 10), '.',
                  LOWER(SUBSTRING_INDEX(Name, '.', -1)))
 WHERE Name IS NOT NULL AND Name <> '';


-- ===========================================================================
--  8. Сетевые идентификаторы во всех непустых Message*
-- ===========================================================================

UPDATE Message1   SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message6   SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message45  SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message183 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message188 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message189 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message191 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message193 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message194 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message196 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message197 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message198 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message199 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message200 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message201 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message204 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message221 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message255 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message270 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message280 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;
UPDATE Message299 SET IP=NULL, LastIP=NULL, UserAgent=NULL, LastUserAgent=NULL;


-- ===========================================================================
--  9. Settings — секреты и изоляция от внешнего мира
-- ===========================================================================

-- Вся почта — в mailpit. Ни одно письмо не должно уйти реальным адресатам.
UPDATE Settings SET Value = 'Smtp'   WHERE `Key` = 'SpamUseTransport';
UPDATE Settings SET Value = 'mail'   WHERE `Key` = 'SpamSmtpHost';
UPDATE Settings SET Value = '1025'   WHERE `Key` = 'SpamSmtpPort';
UPDATE Settings SET Value = '0'      WHERE `Key` = 'SpamSmtpAuthUse';
UPDATE Settings SET Value = ''       WHERE `Key` = 'SpamSmtpUser';
UPDATE Settings SET Value = ''       WHERE `Key` = 'SpamSmtpPass';
UPDATE Settings SET Value = ''       WHERE `Key` = 'SpamSmtpEncryption';
UPDATE Settings SET Value = 'noreply@shiduh.local' WHERE `Key` = 'SpamFromEmail';

-- SecretKey на проде = MD5 от пустой строки, то есть общеизвестен, а им
-- защищены cron-эндпоинты. Локально ставим случайный.
UPDATE Settings SET Value = SHA1(CONCAT(@salt, 'secret'))  WHERE `Key` = 'SecretKey';
UPDATE Settings SET Value = SHA1(CONCAT(@salt, 'indexer')) WHERE `Key` = 'IndexerSecretKey';

-- Внешний сервис airee (CDN) — отвязываем от прод-домена и почты владельца.
UPDATE Settings SET Value = '' WHERE `Key` IN ('Domain', 'Email') AND Module = 'airee';

-- Счётчики аналитики не должны стучаться из dev.
UPDATE Settings SET Value = '0' WHERE `Key` = 'Analytics_Enabled';

-- Демо-период NetCat: продлеваем на год, иначе поверх сайта ляжет модалка
-- «30-дневный пробный период закончился». Формула — из netcat_regkey.php
-- и netcat/connect_io.php (функция nc_demo_expired).
SET @d = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 365 DAY), '%Y-%m-%d %H:%i:%s');
UPDATE Settings SET Value = @d WHERE `Key` = 'InstallationDateOut';
UPDATE Settings SET Value = SHA1(CONCAT(
        'Net_^^_Cat', @d,
        'nc63968534304df688ffedc8ca2e3f59f4a26c255b010e5bc647121dca79e55017726',
        SUBSTRING(@d, 8, 3)))
 WHERE `Key` = 'InstallationID';

-- Домен сайта в каталоге → localhost, иначе NetCat не опознает запрос.
UPDATE Catalogue SET Domain = 'localhost' WHERE Catalogue_ID = 1;


-- ===========================================================================
--  10. Уборка
-- ===========================================================================

DROP TABLE IF EXISTS _anon_name_m, _anon_name_f, _anon_city, _anon_prof,
                     _anon_hobby, _anon_health, _anon_wish, _anon_lorem;

SELECT 'Обезличивание завершено. Запустите tools/03_verify.sql.' AS status;
