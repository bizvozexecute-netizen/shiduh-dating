<?php
/*
 * Согласие на обработку чувствительных данных (ROADMAP X-5, 152-ФЗ).
 *
 * Сведения о здоровье — специальная категория персональных данных. Их
 * обработка требует ОТДЕЛЬНОГО осознанного согласия, а не общего
 * «соглашения об использовании сайта», и согласие должно быть доказуемым:
 * когда дано и на какую именно редакцию текста.
 *
 *   Consent_sensitive          — галочка в форме анкеты (0/1)
 *   Consent_sensitive_at       — когда дано (проставляет сервер)
 *   Consent_sensitive_version  — версия текста согласия (проставляет сервер)
 *
 * Почему поля анкеты, а не отдельная таблица: согласие даётся в той же
 * форме и тем же POST, что и сама анкета, — так оно не может «потеряться»
 * между шагами, и правило «нет согласия → нет данных о здоровье»
 * проверяется в одном месте (api/consent_lib.php).
 *
 * Механизм и откат — как в 003/006: add_field() пишет в Field, делает
 * ALTER TABLE ADD и обновляет кэш шаблонов.
 */

$FIELDS = array(
    // Field_Name,                  TypeOfData_ID, Description
    array('Consent_sensitive',         5, 'Согласие на обработку сведений о здоровье (152-ФЗ)'),
    array('Consent_sensitive_at',      8, 'Когда дано согласие на обработку сведений о здоровье'),
    array('Consent_sensitive_version', 1, 'Версия текста согласия, на который дано согласие'),
);

return array(
    'up' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach ($FIELDS as $f) {
            list($name, $type, $desc) = $f;
            if ($db->get_var("SHOW COLUMNS FROM `Message204` LIKE '" . $db->prepare($name) . "'")) {
                continue;   // идемпотентность
            }
            $nc->component->add_field(204, array(
                'TypeOfData_ID' => $type,
                'Field_Name'    => $name,
                'Description'   => $desc,
                'Format'        => '',
                'DefaultState'  => '',
                'NotNull'       => 0,
                'DoSearch'      => 0,
            ));
        }
    },

    'down' => function ($nc) use ($FIELDS) {
        $db = $nc->db;
        foreach (array_reverse($FIELDS) as $f) {
            $name = $f[0];
            $db->query("DELETE FROM `Field` WHERE `Class_ID`=204 AND `Field_Name`='" . $db->prepare($name) . "'");
            if ($db->get_var("SHOW COLUMNS FROM `Message204` LIKE '" . $db->prepare($name) . "'")) {
                $db->query("ALTER TABLE `Message204` DROP COLUMN `" . $name . "`");
            }
        }
    },
);
