<?php
/*
 * «Предложить шидух» из подбора пар с ИИ (приёмка 2026-10).
 *
 * Раньше кнопка писала только строку статистики в match_feedback — шидух
 * не создавался, никто ничего не получал, а шадхан видел «Предложение
 * зафиксировано» и не понимал, что произошло. Теперь предложение — это
 * настоящий шидух (Message280, тот же, что создавал раздел «Создание
 * шидуха»), и о нём узнают те, кому дальше действовать:
 *
 *   - вторая анкета ЗАКРЫТА: связь с кандидатом только через его шадхана —
 *     предложение получает шадхан (шадханы) второй стороны;
 *   - вторая анкета ОТКРЫТА и у неё есть свой аккаунт кандидата: предложение
 *     получает сам кандидат (общение напрямую), его шадханы — копию;
 *   - своему кандидату (если у анкеты есть аккаунт кандидата) — тоже.
 *
 * Сообщения уходят от имени предложившего шадхана, чтобы ответ пришёл ему,
 * а не служебному аккаунту. Плюс письмо (sendEmail ядра сайта).
 *
 * Старое уведомление class/280/AddActionTemplate.html писало «Уважаемый
 * кандидат» владельцу анкеты — а владельцем часто был шадхан. Здесь
 * получатель выбирается по роли.
 */

define('SHIDUH_SUBDIVISION_ID', 77);   // /my/istoriya-shiduha/
define('SHIDUH_SUB_CLASS_ID', 82);
define('SHIDUH_MESSAGES_SUB', 28);     // /my/message/
define('SHIDUH_MESSAGES_CC', 29);

/* Анкета с тем, что нужно для решения «кому писать». */
function shiduh_anketa($db, $id) {
    $id = (int)$id;
    $row = $db->get_row(
        "SELECT m.`Message_ID`, m.`User_ID`, m.`Name`, m.`Open`, m.`City`, m.`Date_of_birth`, "
        . "m.`Shadhan_1`, m.`Shadhan_2`, m.`Shadhan_3`, u.`Type` AS `owner_type`, u.`Email` AS `owner_email` "
        . "FROM `Message204` m LEFT JOIN `User` u ON u.`User_ID`=m.`User_ID` "
        . "WHERE m.`Message_ID`=$id",
        ARRAY_A
    );
    return $row ?: null;
}

/* Шадханы анкеты: слоты Shadhan_N и владелец, если он сам шадхан. */
function shiduh_anketa_shadhans($db, array $a) {
    $ids = array();
    foreach (array('Shadhan_1', 'Shadhan_2', 'Shadhan_3') as $col) {
        if ((int)$a[$col] > 0) {
            $ids[] = (int)$a[$col];
        }
    }
    if ((int)$a['owner_type'] === 2) {
        $ids[] = (int)$a['User_ID'];
    }
    return array_values(array_unique($ids));
}

/* Есть ли у анкеты собственный аккаунт кандидата (а не шадхан-владелец). */
function shiduh_has_candidate_account(array $a) {
    // заготовка приглашения (invite-…@invite.shiduh.org) — не адресат: её
    // почта вымышленная, а кабинетом кандидат ещё не пользуется
    return (int)$a['User_ID'] > 0 && (int)$a['owner_type'] === 1
        && !preg_match('/@invite\.shiduh\.org$/', (string)$a['owner_email']);
}

function shiduh_user_name($db, $uid) {
    $name = trim((string)$db->get_var("SELECT `Name` FROM `User` WHERE `User_ID`=" . (int)$uid));
    return $name !== '' ? $name : 'ID ' . (int)$uid;
}

function shiduh_base_url() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'shiduh.org';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function shiduh_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Личное сообщение (Message6) + письмо. Текст — HTML, как у штатных уведомлений. */
function shiduh_notify($db, $from_uid, $to_uid, $subject, $html) {
    $to_uid = (int)$to_uid;
    if ($to_uid <= 0 || $to_uid === (int)$from_uid) {
        return false;
    }
    $db->query(
        "INSERT INTO `Message6` (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `LastUser_ID`, "
        . "`Keyword`, `Subdivision_ID`, `Sub_Class_ID`, `Created`, `Status`) VALUES ("
        . "1, $to_uid, '" . $db->prepare($subject) . "', '" . $db->prepare($html) . "', "
        . (int)$from_uid . ", " . (int)$from_uid . ", '', "
        . SHIDUH_MESSAGES_SUB . ", " . SHIDUH_MESSAGES_CC . ", NOW(), 0)"
    );
    if ($db->is_error) {
        shiduh_log('shiduh', 'не записано уведомление о шидухе для ' . $to_uid);
        return false;
    }
    if (function_exists('sendEmail')) {
        try {
            sendEmail($to_uid, $subject, $html);
        } catch (Exception $e) {
            // письмо — дубль сообщения в кабинете; его сбой не отменяет шидух
            shiduh_log('shiduh', 'не отправлено письмо о шидухе для ' . $to_uid);
        }
    }
    return true;
}

/*
 * Создать шидух «наш кандидат $anketa_id × $target_id» от шадхана $uid и
 * уведомить стороны. Права (шадхан ведёт $anketa_id) проверяет вызывающий.
 *
 * Возвращает array(
 *   'created'   => bool — создан сейчас (false — уже был),
 *   'shiduh_id' => int,
 *   'target_open' => bool,
 *   'via'       => 'shadhan' | 'direct' — как дальше идёт общение,
 *   'notified'  => [имена получателей],
 *   'message'   => текст для шадхана: что произошло и что дальше,
 * )
 */
function shiduh_propose($db, $uid, $anketa_id, $target_id) {
    $uid = (int)$uid;
    $own = shiduh_anketa($db, $anketa_id);
    $other = shiduh_anketa($db, $target_id);
    if (!$own || !$other) {
        return array('created' => false, 'shiduh_id' => 0, 'error' => 'Анкета не найдена');
    }

    $target_open = (int)$other['Open'] === 1;
    $direct = $target_open && shiduh_has_candidate_account($other);
    $other_name = trim((string)$other['Name']) !== '' ? $other['Name'] : 'ID ' . (int)$other['Message_ID'];
    $own_name = trim((string)$own['Name']) !== '' ? $own['Name'] : 'ID ' . (int)$own['Message_ID'];

    // Тот же шидух от того же шадхана — не дублируем (правило 280/AddCond).
    $existing = (int)$db->get_var(
        "SELECT `Message_ID` FROM `Message280` WHERE `User_ID`=$uid AND ("
        . "(`Candidate1`=" . (int)$anketa_id . " AND `Candidate2`=" . (int)$target_id . ") OR "
        . "(`Candidate1`=" . (int)$target_id . " AND `Candidate2`=" . (int)$anketa_id . ")) LIMIT 1"
    );
    if ($existing) {
        return array(
            'created' => false, 'shiduh_id' => $existing, 'target_open' => $target_open,
            'via' => $direct ? 'direct' : 'shadhan', 'notified' => array(),
            'message' => 'Этот шидух вы уже предлагали — он в разделе «История шидухов». '
                . 'Повторно уведомления не отправлялись.',
        );
    }

    $priority = (int)$db->get_var(
        "SELECT IFNULL(MAX(`Priority`),0)+1 FROM `Message280` WHERE `Sub_Class_ID`=" . SHIDUH_SUB_CLASS_ID
    );
    $db->query(
        "INSERT INTO `Message280` SET `User_ID`=$uid, "
        . "`Subdivision_ID`=" . SHIDUH_SUBDIVISION_ID . ", `Sub_Class_ID`=" . SHIDUH_SUB_CLASS_ID . ", "
        . "`Priority`=$priority, `Keyword`='', `Checked`=1, `Parent_Message_ID`=0, "
        . "`Created`=NOW(), `LastUser_ID`=$uid, "
        . "`Candidate1`=" . (int)$anketa_id . ", `Candidate2`=" . (int)$target_id . ", `choose`=0"
    );
    $shiduh_id = (int)$db->insert_id;
    if ($db->is_error || !$shiduh_id) {
        return array('created' => false, 'shiduh_id' => 0, 'error' => 'Не удалось создать шидух');
    }

    $base = shiduh_base_url();
    $me = shiduh_user_name($db, $uid);
    $me_link = '<a href="' . $base . '/my/shadhany/shadhan_' . $uid . '.html">' . shiduh_esc($me) . '</a>';
    $card = function ($a) use ($base) {
        return $base . '/my/candidate/candidate_' . (int)$a['Message_ID'] . '.html';
    };
    $sign = '<br><br>Ответить можно прямо на это сообщение.<br>С уважением,<br>Команда One Soul';
    $notified = array();

    // Шадханы второй стороны — всегда (при прямом общении — для сведения).
    foreach (shiduh_anketa_shadhans($db, $other) as $sid) {
        $html = 'Здравствуйте!<br>Шадхан ' . $me_link . ' предлагает шидух вашему кандидату '
            . '<a href="' . $card($other) . '">' . shiduh_esc($other_name) . '</a> с кандидатом '
            . '<a href="' . $card($own) . '">' . shiduh_esc($own_name) . '</a>.'
            . ($direct
                ? '<br>Анкета вашего кандидата открыта, поэтому предложение получил и сам кандидат.'
                : '<br>Решение о знакомстве — за вами и кандидатом.')
            . $sign;
        if (shiduh_notify($db, $uid, $sid, 'Предложение шидуха', $html)) {
            $notified[] = shiduh_user_name($db, $sid);
        }
    }

    // Вторая анкета открыта — сам кандидат, напрямую.
    if ($direct) {
        $html = 'Здравствуйте!<br>Шадхан ' . $me_link . ' предлагает вам познакомиться с кандидатом '
            . '<a href="' . $card($own) . '">' . shiduh_esc($own_name) . '</a>. '
            . 'Посмотрите анкету и ответьте шадхану, интересно ли вам знакомство.' . $sign;
        if (shiduh_notify($db, $uid, (int)$other['User_ID'], 'Вам предложен шидух', $html)) {
            $notified[] = $other_name;
        }
    }

    // Свой кандидат — если у анкеты есть его аккаунт.
    if (shiduh_has_candidate_account($own)) {
        $html = 'Здравствуйте!<br>Ваш шадхан ' . $me_link . ' предложил вам шидух с кандидатом '
            . '<a href="' . $card($other) . '">' . shiduh_esc($other_name) . '</a>. '
            . 'Дальнейшие шаги шадхан обсудит с вами.' . $sign;
        shiduh_notify($db, $uid, (int)$own['User_ID'], 'Вам предложен шидух', $html);
    }

    if ($direct) {
        $message = 'Шидух создан и записан в «Историю шидухов». Анкета «' . $other_name
            . '» открыта — кандидат получил предложение в сообщениях и на почту, дальше вы общаетесь с ним напрямую.';
    } elseif ($notified) {
        $message = 'Шидух создан и записан в «Историю шидухов». '
            . ($target_open
                ? 'Анкета «' . $other_name . '» открыта, но своего аккаунта у кандидата нет — связь через его шадхана. '
                : 'Анкета «' . $other_name . '» закрыта — связь с кандидатом только через его шадхана. ')
            . 'Предложение отправлено: ' . implode(', ', $notified) . '. Ответ придёт в «Сообщения».';
    } else {
        $message = 'Шидух создан и записан в «Историю шидухов». У анкеты «' . $other_name
            . '» нет ни шадхана, ни своего аккаунта — уведомить некого; свяжитесь с администратором.';
    }

    return array(
        'created' => true, 'shiduh_id' => $shiduh_id, 'target_open' => $target_open,
        'via' => $direct ? 'direct' : 'shadhan', 'notified' => $notified, 'message' => $message,
    );
}
