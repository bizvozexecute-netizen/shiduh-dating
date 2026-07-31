<?php

require_once(dirname(__FILE__)."/nc_search.class.php");
nc_search::init();

nc_core::get_object()->event->add_listener(nc_event::AFTER_SITE_CREATED, function($site_id) {
    nc_search::create_search_result_subdivision($site_id);
});