<?php

class nc_version_controller extends nc_ui_controller {

    protected $use_layout = true;

    protected function init() {
        $nc_core = nc_Core::get_object();
        if ($nc_core->input->fetch_get('isNaked')) {
            $this->use_layout = false;
        }
    }

    protected function action_show_page_versions() {
        $nc_core = nc_Core::get_object();
        $subdivision_id = (int)$nc_core->input->fetch_get('subdivision_id');

        /** @var Permission $perm */
        global $perm;
        $perm->ExitIfNotAccess(NC_PERM_ITEM_SUB, NC_PERM_ACTION_ADMIN, $subdivision_id);

        $versions = nc_version_record_collection::load(
            'nc_version_record',
            "SELECT * FROM `Version` WHERE `Subdivision_ID` = " . $subdivision_id . " GROUP BY `Version_Changeset_ID` ORDER BY `Version_ID` DESC"
        );

        return $this->view('version/page', array(
            'versions' => $versions,
        ));
    }

    protected function action_restore_page() {
        $nc_core = nc_Core::get_object();
        $this->use_layout = false;
        $subdivision_id = (int)$nc_core->input->fetch_get_post('subdivision_id');
        $id = (int)$nc_core->input->fetch_post('id');

        /** @var Permission $perm */
        global $perm;
        $perm->ExitIfNotAccess(NC_PERM_ITEM_SUB, NC_PERM_ACTION_ADMIN, $subdivision_id);

        if (!$id) {
            die('Wrong ID');
        }

        /** @var Permission $perm */
        global $perm;
        $perm->ExitIfNotAccess(NC_PERM_ITEM_SUB, NC_PERM_ACTION_ADMIN, $subdivision_id);

        $version = $nc_core->version->load_newest_in_chain($id, $subdivision_id);

        $nc_core->version->init_changeset(sprintf(NETCAT_VERSION_CHANGESET_RESTORE_PAGE, $version->get_id(), $version->get('Timestamp')));

        // все не актуальные версии инфоблоков, сделанные до восстанавливаемой версии
        $infoblock_versions = nc_version_record_collection::load(
            "nc_version_record",
            "SELECT  `v`.* FROM `Version` AS `v`
             INNER JOIN (
                SELECT `Sub_Class_ID`, MAX(`Version_ID`) AS `Max_Version_ID`
                FROM `Version`
                WHERE `Version_ID` < " . $version->get_id() . " AND `Entity` = 'infoblock' AND `Subdivision_ID` = " . $subdivision_id . "
                GROUP BY `Sub_Class_ID`
            ) AS `v2` ON `v`.`Sub_Class_ID` = `v2`.`Sub_Class_ID` AND `v`.`Version_ID` = `v2`.`Max_Version_ID`
            WHERE `v`.`IsActual` != 1 
            " . ($version->get('Entity') == 'infoblock' ? "
                AND NOT (`v`.`Entity` = 'infoblock' AND `v`.`Sub_Class_ID` = " . (int)$version->get('Sub_Class_ID') . ")
            " : null) . "
            ORDER BY `v`.`Version_ID` ASC"
        );

        // все не актуальные версии объектов, сделанные до восстанавливаемой версии
        $object_versions = nc_version_record_collection::load(
            "nc_version_record",
            "SELECT  `v`.* FROM `Version` AS `v`
             INNER JOIN (
                SELECT `Class_ID`, `Message_ID`, MAX(`Version_ID`) AS `Max_Version_ID`
                FROM `Version`
                WHERE `Version_ID` < " . $version->get_id() . " AND `Entity` = 'object' AND `Subdivision_ID` = " . $subdivision_id . " 
                GROUP BY `Class_ID`, `Message_ID`
            ) AS `v2` ON `v`.`Class_ID` = `v2`.`Class_ID` AND `v`.`Message_ID` = `v2`.`Message_ID` AND `v`.`Version_ID` = `v2`.`Max_Version_ID`
            WHERE `v`.`IsActual` != 1 
            " . ($version->get('Entity') == 'object' ? "
                AND NOT (`v`.`Entity` = 'object' AND `v`.`Class_ID` = " . (int)$version->get('Class_ID') . " AND `v`.`Message_ID` = " . (int)$version->get('Message_ID') . ")
            " : null) . "
            ORDER BY `v`.`Version_ID` ASC"
        );

        // если восстанавливают не версию раздела, то надо посмотреть ближайшую предыдущую версию раздела и, если она не актуальная, то восстановить ее
        if ($version->get('Entity') != 'subdivision') {
            $subdivision_version_data = $nc_core->db->get_row("
                SELECT *
                FROM Version
                WHERE Version_ID < " . $version->get_id() . " AND Entity = 'subdivision' AND Subdivision_ID = " . $subdivision_id . "
                ORDER BY Version_ID DESC
                LIMIT 1
            ", ARRAY_A);
            if (!empty($subdivision_version_data) && $subdivision_version_data['IsActual'] != 1) {
                $subdivision_version = new nc_version_record_subdivision();
                $subdivision_version->set_values_from_database_result($subdivision_version_data);
                $subdivision_version->restore();
            }
        }

        // восстанавливаем все необходимые версии инфоблоков
        if ($infoblock_versions->count() > 0) {
            /** @var nc_version_record_infoblock $infoblock_version */
            foreach ($infoblock_versions as $infoblock_version) {
                $infoblock_version->restore();
            }
        }

        // восстанавливаем все необходимые версии объектов
        if ($object_versions->count() > 0) {
            /** @var nc_version_record_object $object_version */
            foreach ($object_versions as $object_version) {
                $object_version->restore();
            }
        }

        // надо удалить все инфоблоки и объекты, которые были созданые позднее восстанавливаемой версии
        $infoblocks_for_delete = $nc_core->db->get_col("
            SELECT `Sub_Class_ID`
            FROM `Version`
            WHERE `Version_ID` > " . $version->get_id() . " AND `Entity` = 'infoblock' AND `Subdivision_ID` = " . $subdivision_id . " AND `Action` = 'created'
        ", ARRAY_A);
        $objects_for_delete = $nc_core->db->get_results("
            SELECT `Class_ID`, `Message_ID`
            FROM `Version`
            WHERE `Version_ID` > " . $version->get_id() . " AND `Entity` = 'object' AND `Subdivision_ID` = " . $subdivision_id . " AND `Action` = 'created'
        ", ARRAY_A);


        if (!empty($objects_for_delete)) {
            foreach ($objects_for_delete as $object) {
                $nc_core->message->delete_by_id($object['Message_ID'], $object['Class_ID'], $nc_core->get_settings('TrashUse'));
            }
        }
        if (!empty($infoblocks_for_delete)) {
            foreach ($infoblocks_for_delete as $infoblock_id) {
                $nc_core->sub_class->delete($infoblock_id);
            }
        }

        if (!$version->get('IsActual')) {
            $version->restore();
        }

        return null;
    }


    protected function after_action($result) {
        if (!$this->use_layout) {
            return $result;
        }

        return BeginHtml() . $result . EndHtml();
    }

}