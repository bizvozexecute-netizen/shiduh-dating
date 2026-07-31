<?php

class nc_version_record_object extends nc_version_record {

    protected $entity_table = 'Message';
    static protected $ignore_field_changes = array(
        'Message_ID' => true,
        'Created' => true,
        'User_ID' => true,
        'UserAgent' => true,
        'IP' => true,
        'LastUpdated' => true,
        'LastUser_ID' => true,
        'LastIP' => true,
        'LastUserAgent' => true,
    );

    /**
     * @param string $property
     * @param mixed $value
     * @param bool $add_new_property
     * @return nc_version_record_object
     * @throws nc_record_exception
     */
    public function set($property, $value, $add_new_property = false) {
        if ($property === 'Class_ID') {
            $this->entity_table = 'Message' . (int)$value;
        }
        return parent::set($property, $value, $add_new_property);
    }

    /**
     * @return string
     */
    protected function get_same_item_query_condition() {
        return "`Entity` = 'object' AND `Class_ID` = " . $this->get('Class_ID') . ' AND `Message_ID` = ' . $this->get('Message_ID');
    }

    public function get_name_from_snapshot() {
        $snapshot = $this->get('Snapshot');
        $component_id = $this->get('Class_ID');
        $name_field = nc_core::get_object()->get_component($component_id)->get_possible_object_name_field();
        if ($name_field !== 'Message_ID') {
            return $snapshot[$this->entity_table][$name_field];
        }
    }

    /**
     * @return array
     */
    public function get_entity_current_files() {
        return $this->get_entity_files_from_fields($this->get('Class_ID'), $this->get('Message_ID'));
    }


    /**
     * @return array
     */
    protected function get_entity_snapshot_data() {
        $nc_core = nc_core::get_object();

        $component_id = $this->get('Class_ID');
        $object_id = $this->get('Message_ID');

        $object_data = (array)$nc_core->db->get_results(
            "SELECT * FROM `Message$component_id` WHERE `Message_ID` = $object_id",
            ARRAY_A,
            'Message_ID'
        );

        if ($object_data) {
            // сохраним информацию в кэш file_info, так как дальше будет вызов get_all_files()
            $nc_core->file_info->cache_object_data($component_id, $object_data);
        }

        return array(
            "Message$component_id" => $object_data,
            'Filetable' => $this->get_entity_snapshot_file_data($component_id, $object_id, 'Filetable', NC_FIELDTYPE_FILE),
            'Multifield' => $this->get_entity_snapshot_file_data($component_id, $object_id, 'Multifield', NC_FIELDTYPE_MULTIFILE),
        );
    }

    /**
     *
     */
    protected function delete_entity() {
        nc_core::get_object()->message->delete_by_id($this->get('Message_ID'), $this->get('Class_ID'));
    }

    /**
     * @return bool
     */
    protected function parent_entity_exists() {
        try {
            return (bool)nc_core::get_object()->sub_class->get_by_id($this->get('Sub_Class_ID'), 'Sub_Class_ID');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @return bool
     */
    protected function entity_exists() {
        try {
            return (bool)nc_core::get_object()->message->get_by_id($this->get('Class_ID'), $this->get('Message_ID'), 'Message_ID');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     *
     */
    protected function prepare_version_restore() {
        $this->remove_file_records_on_version_restore($this->get('Class_ID'), $this->get('Message_ID'));
    }

    /**
     *
     */
    protected function finalize_version_restore() {
        $this->remove_generated_field_images($this->get('Class_ID'), $this->get('Message_ID'));
    }
}