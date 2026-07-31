<?php

$NETCAT_FOLDER = realpath(__DIR__ . '/../../../../../') . '/';
require_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
require_once $nc_core->ADMIN_FOLDER . 'function.inc.php';

/**
 * Field properties as JSON
 */

/**
 * Output:
 *      { ComponentTypeName:
 *          [
 *              { id: 'classId:fieldId',
 *                description: 'readable field name',
 *                type: 'string|integer|...',
 *                classifier: 'ClassifierTableName' },
 *              { another field ... }
 *          ],
 *         NextComponentTypeName: ...
 *      }
 */

$input = nc_core('input');
$sub_class_id = (int)$input->fetch_get_post('sub_class_id');

$exporter = new nc_condition_admin_fieldexporter($sub_class_id);
echo nc_array_json($exporter->export());
