<?php
/*
 * Общий код массовой рассылки (ROADMAP Раздел IV): JSON-ответ, проверка
 * прав администратора, сборка WHERE сегмента из фильтров запроса.
 *
 * Сегмент: кандидаты (User.Type=1) с email, кроме аккаунтов-заготовок
 * приглашений и отписавшихся (mailing_optout.opted_out=1). Письма уходят
 * только подтверждённым (Confirmed=1) — это решает вызывающий код.
 * Экранирование строк — только prepare() (находка №35), id — (int).
 */

require_once dirname(__FILE__) . '/admin_lib.php';



/*
 * WHERE сегмента (для таблицы `User` u) из POST-фильтров.
 * Возвращает array($where, $filters): $filters — нормализованные значения
 * для сохранения в mailings.filters (JSON).
 */
function mailing_segment_where($db, $input) {
    $where = "u.`Type`=1 AND u.`Email`<>'' "
        . "AND u.`Email` NOT LIKE 'invite-%@invite.shiduh.org' "
        . "AND NOT EXISTS (SELECT 1 FROM `mailing_optout` o "
        . "WHERE o.`user_id`=u.`User_ID` AND o.`opted_out`=1)";
    $filters = array();

    $gender = (int)$input->fetch_post('gender');
    if ($gender === 1 || $gender === 2) {
        $where .= " AND u.`Gender`=$gender";
        $filters['gender'] = $gender;
    }

    $age_min = (int)$input->fetch_post('age_min');
    $age_max = (int)$input->fetch_post('age_max');
    if ($age_min > 0) {
        $where .= " AND TIMESTAMPDIFF(YEAR, u.`Date_of_birth`, CURDATE()) >= $age_min";
        $filters['age_min'] = $age_min;
    }
    if ($age_max > 0) {
        $where .= " AND TIMESTAMPDIFF(YEAR, u.`Date_of_birth`, CURDATE()) <= $age_max";
        $filters['age_max'] = $age_max;
    }

    // Город — по анкете кандидата (в User город пуст). Подстрока, без регистра.
    $city = trim((string)$input->fetch_post('city'));
    if ($city !== '') {
        $city = mb_substr($city, 0, 100);
        $like = $db->prepare(addcslashes($city, '%_'));
        $where .= " AND EXISTS (SELECT 1 FROM `Message204` m "
            . "WHERE m.`User_ID`=u.`User_ID` AND m.`City` LIKE '%$like%')";
        $filters['city'] = $city;
    }

    return array($where, $filters);
}

/*
 * Поставить письма в очередь (вынесено из mailing_send.php для C11: запрос
 * согласия на сведения о здоровье шлёт тем же путём).
 *
 * Создаёт запись в mailings, каждому адресату — персональный токен отписки
 * (заводится при первом касании), письмо кладётся в Mail_Queue штатной
 * nc_mail2queue — реально шлёт крон порциями (троттлинг Р-2). Адресат без
 * рабочего токена пропускается с записью «error» в лог: ссылка отписки в
 * письме не должна быть мёртвой. Возвращает array(mailing_id, queued, skipped).
 * Вызывающий код сам решает, кто в $recipients (подтверждённый e-mail и т. п.).
 */
function mailing_enqueue($db, $uid, $subject, $body, array $filters, array $recipients) {
    if (!function_exists('nc_mail2queue')) {
        require_once dirname(__FILE__) . '/../netcat/admin/mail.inc.php';   // очередь ядра
    }
    $uid = (int)$uid;
    $db->query(
        "INSERT INTO `mailings` SET "
        . "`created_by`=$uid, "
        . "`filters`='" . $db->prepare(json_encode($filters, JSON_UNESCAPED_UNICODE)) . "', "
        . "`subject`='" . $db->prepare($subject) . "', "
        . "`body`='" . $db->prepare($body) . "', "
        . "`recipients_total`=0, "
        . "`created_at`=NOW()"
    );
    if ($db->is_error || !$db->insert_id) {
        return array('mailing_id' => 0, 'queued' => 0, 'skipped' => 0);
    }
    $mailing_id = (int)$db->insert_id;

    $nc_core = nc_core::get_object();
    $host = (string)(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'shiduh.org');
    $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host;
    $from = $nc_core->get_settings('FromEmail', 'subscriber')
         ?: ($nc_core->get_settings('SpamFromEmail', 'system') ?: ('noreply@' . $host));

    // Сегмент может быть большим (тысячи адресатов): не даём PHP умереть по
    // таймауту посреди цикла — как штатный bulk-отправитель admin/user/MessageToAll.php.
    @set_time_limit(0);
    @ignore_user_abort(true);

    $queued = 0;
    $skipped = 0;
    foreach ($recipients as $r) {
        $r_uid = (int)$r['User_ID'];
        $email = (string)$r['Email'];

        // Персональный токен отписки: заводим при первом касании, дальше переиспользуем.
        $token = $db->get_var("SELECT `token` FROM `mailing_optout` WHERE `user_id`=$r_uid");
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $db->query(
                "INSERT IGNORE INTO `mailing_optout` SET "
                . "`user_id`=$r_uid, `token`='" . $db->prepare($token) . "', "
                . "`opted_out`=0, `created_at`=NOW()"
            );
            // гонка двух запросов: перечитываем фактический токен
            $token = $db->get_var("SELECT `token` FROM `mailing_optout` WHERE `user_id`=$r_uid");
        }

        if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            $db->query(
                "INSERT INTO `mailing_log` SET "
                . "`mailing_id`=$mailing_id, `user_id`=$r_uid, "
                . "`email`='" . $db->prepare($email) . "', "
                . "`status`='error', `queued_at`=NOW()"
            );
            $skipped++;
            continue;
        }

        $message = $body . "\n\n---\n"
            . "Отписаться от рассылки: $base/api/mailing_unsubscribe.php?t=$token";

        nc_mail2queue($email, $from, $subject, $message);

        $db->query(
            "INSERT INTO `mailing_log` SET "
            . "`mailing_id`=$mailing_id, `user_id`=$r_uid, "
            . "`email`='" . $db->prepare($email) . "', "
            . "`status`='queued', `queued_at`=NOW()"
        );
        // счётчик копим инкрементально: при обрыве соединения шапка не останется
        // с нулём при реально ушедших письмах
        $db->query("UPDATE `mailings` SET `recipients_total`=`recipients_total`+1 WHERE `id`=$mailing_id");
        $queued++;
    }

    return array('mailing_id' => $mailing_id, 'queued' => $queued, 'skipped' => $skipped);
}
