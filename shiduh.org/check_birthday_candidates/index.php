<?php
/*
 * Поздравления с днём рождения (этап «До идеала», C13; ROADMAP B-5).
 *
 * Что было: скрипт читал ВСЕ анкеты (SELECT * без условий), сравнивал дату в
 * PHP, вставлял имя в SQL без экранирования и не помнил, кого уже поздравил —
 * задача в CronTasks стояла «каждую минуту» по несуществующему адресу
 * (/netcat/modules/...), то есть либо молчала, либо слала бы поздравление
 * 1440 раз в день. Теперь: только CLI (веб-запрос — 403), выборка по
 * MONTH/DAY в SQL, по одному письму в год адресату (NOT EXISTS по году),
 * prepare()/(int)/NOW(). Запуск — системным cron раз в день (DEPLOY, §Cron):
 *
 *   0 9 * * *  php /path/webroot/check_birthday_candidates/index.php
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CLI only';
    exit;
}

$NETCAT_FOLDER = realpath(dirname(__FILE__) . '/..') . '/';
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    putenv('DOCUMENT_ROOT=' . rtrim($NETCAT_FOLDER, '/'));
    $_SERVER['DOCUMENT_ROOT'] = rtrim($NETCAT_FOLDER, '/');
}
if (empty($_SERVER['HTTP_HOST'])) {
    putenv('HTTP_HOST=shiduh.org');
    $_SERVER['HTTP_HOST'] = 'shiduh.org';
}
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $NETCAT_FOLDER . 'api/admin_lib.php';

$nc_core = nc_core::get_object();
$db = $nc_core->db;

define('BIRTHDAY_SUBJECT', 'С днём рождения');
define('BIRTHDAY_SHADHAN_SUBJECT', 'День рождения у кандидата');
define('BIRTHDAY_SENDER', 1);   // системный отправитель — как во всех уведомлениях сайта (и шадхану тоже: письмо не от кандидата)

/* Метка письма: анкета + год — по ней и дедупликация (Keyword в Message6 иначе не используется). */
function birthday_key($anketa_id) {
    return 'birthday:' . (int)$anketa_id . ':' . date('Y');
}

/* Есть ли уже письмо этого года адресату $to по анкете. */
function birthday_already_sent($db, $to, $anketa_id) {
    return (bool)$db->get_var(
        "SELECT 1 FROM `Message6` WHERE `ToUser`=" . (int)$to
        . " AND `Keyword`='" . $db->prepare(birthday_key($anketa_id)) . "' LIMIT 1"
    );
}

function birthday_send($db, $to, $anketa_id, $subject, $text) {
    $db->query(
        "INSERT INTO `Message6` SET `Checked`=1, `ToUser`=" . (int)$to . ", `User_ID`=" . BIRTHDAY_SENDER
        . ", `LastUser_ID`=" . BIRTHDAY_SENDER . ", `Keyword`='" . $db->prepare(birthday_key($anketa_id)) . "'"
        . ", `Subdivision_ID`=28, `Sub_Class_ID`=29, `Status`=0"
        . ", `Subject`='" . $db->prepare($subject) . "', `Message`='" . $db->prepare($text) . "', `Created`=NOW()"
    );
    return !$db->is_error;
}

$db->query(
    "SELECT `Message_ID`, `Name`, `User_ID`, `Shadhan_1`, `Shadhan_2`, `Shadhan_3` FROM `Message204` "
    . "WHERE `Checked`=1 AND `User_ID`>0 AND `Date_of_birth` IS NOT NULL AND `Date_of_birth` NOT LIKE '0000%' "
    . "AND MONTH(`Date_of_birth`)=MONTH(CURDATE()) AND DAY(`Date_of_birth`)=DAY(CURDATE())",
    ARRAY_A
);
$rows = (array)$db->last_result;

$sent_candidates = 0;
$sent_shadhans = 0;
foreach ($rows as $r) {
    $uid = (int)$r['User_ID'];
    $name = trim((string)$r['Name']);
    $text = 'Мазаль Тов' . ($name !== '' ? ', ' . $name : '') . "! ❤️\n\n"
        . "Команда сервиса One Soul поздравляет Вас и желает всех благословений с Небес!\n\n"
        . "Пусть Творец радует Вас изо дня в день и делает каждый момент жизни приятнее!\n\n"
        . "Желаем создать счастливый еврейский дом, в котором царит шлом байт, крепкого здоровья и парнасы. "
        . "Пусть Б-г оберегает вас от всего дурного и ведёт самым лучшим путём!\n\n"
        . "С уважением,\nКоманда One Soul";
    $aid = (int)$r['Message_ID'];
    if (!birthday_already_sent($db, $uid, $aid) && birthday_send($db, $uid, $aid, BIRTHDAY_SUBJECT, $text)) {
        $sent_candidates++;
    }

    $note = 'Сегодня день рождения у кандидата ' . ($name !== '' ? $name . ' ' : '') . '(ID ' . (int)$r['Message_ID'] . '). '
        . "Поздравьте — тёплое слово от шадхана запоминается.\n\n"
        . 'Анкета: https://shiduh.org/my/candidate/candidate_' . (int)$r['Message_ID'] . '.html';
    foreach (array($r['Shadhan_1'], $r['Shadhan_2'], $r['Shadhan_3']) as $sh) {
        $sh = (int)$sh;
        if ($sh <= 0 || $sh === $uid) {
            continue;
        }
        if (!birthday_already_sent($db, $sh, $aid) && birthday_send($db, $sh, $aid, BIRTHDAY_SHADHAN_SUBJECT, $note)) {
            $sent_shadhans++;
        }
    }
}

$summary = 'birthday: anketas today ' . count($rows) . ', greeted ' . $sent_candidates . ', shadhans notified ' . $sent_shadhans;
shiduh_log('cron', $summary);
echo $summary, "\n";
