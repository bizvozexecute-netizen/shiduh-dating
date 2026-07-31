<?php

class nc_version_record_collection extends nc_record_collection {

    protected $items_class = 'nc_version_record';

    /**
     * @param array $row
     * @return string
     */
    protected function get_item_class_for_database_row(array $row) {
        return 'nc_version_record_' . $row['Entity'];
    }

}