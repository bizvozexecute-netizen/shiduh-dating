<?php
/*
 * Можно ли выдать приглашение по анкете (XI-2) — одно правило и для
 * эндпоинта, и для карточки, чтобы кнопка не обещала того, что сервер
 * откажет (замечание приёмки 2026-10: «создать ссылку не удалось»).
 *
 * Приглашение ПЕРЕДАЁТ владение анкетой заготовке. Это допустимо, когда
 * анкетой владеет:
 *   - сам шадхан, который приглашает;
 *   - заготовка из прошлого приглашения (ещё не закреплённая);
 *   - другой шадхан (он завёл анкету, а этот ведёт её как Shadhan_N) — тогда
 *     прежний владелец остаётся в одном из слотов Shadhan_N и доступа не
 *     теряет.
 * Нельзя отбирать анкету у зарегистрированного кандидата: он входит в неё сам.
 *
 * $anketa — строка Message204 с User_ID и Shadhan_1..3. Возвращает
 * array('ok'=>bool, 'reason'=>код, 'message'=>текст для человека,
 *       'keep_slot'=>имя колонки Shadhan_N для прежнего владельца или '').
 */
function invite_owner_state($db, array $anketa, $uid) {
    $uid = (int)$uid;
    $owner_id = (int)$anketa['User_ID'];
    $ok = array('ok' => true, 'reason' => '', 'message' => '', 'keep_slot' => '');
    if ($owner_id === $uid) {
        return $ok;
    }
    $owner = $db->get_row(
        "SELECT `Type`, `Email` FROM `User` WHERE `User_ID`=$owner_id", ARRAY_A
    );
    if ($owner && preg_match('/^invite-[0-9a-f]+@invite\.shiduh\.org$/', (string)$owner['Email'])) {
        return $ok;
    }
    if ($owner && (int)$owner['Type'] === 2) {
        // прежний владелец-шадхан — в свободный слот, если его там нет
        $slots = array('Shadhan_1', 'Shadhan_2', 'Shadhan_3');
        foreach ($slots as $col) {
            if ((int)$anketa[$col] === $owner_id) {
                return $ok;
            }
        }
        foreach ($slots as $col) {
            if (!(int)$anketa[$col]) {
                $ok['keep_slot'] = $col;
                return $ok;
            }
        }
        return array('ok' => false, 'reason' => 'no_slot', 'keep_slot' => '',
            'message' => 'У анкеты уже три шадхана, и завёл её другой шадхан — '
                . 'приглашение лишило бы его доступа. Попросите его создать ссылку.');
    }
    return array('ok' => false, 'reason' => 'registered', 'keep_slot' => '',
        'message' => 'Кандидат уже зарегистрирован на сайте и сам входит в свою анкету — '
            . 'приглашение не нужно. Напишите ему в «Сообщениях».');
}
