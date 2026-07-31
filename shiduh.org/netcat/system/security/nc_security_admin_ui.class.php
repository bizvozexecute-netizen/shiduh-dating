<?php

class nc_security_admin_ui extends ui_config {
    protected $action = 'settings';

    public $actionButtons = array(
        array(
            'id' => 'submit',
            'caption' => NETCAT_SECURITY_SETTINGS_SAVE,
            'action' => 'mainView.submitIframeForm()',
            'align' => 'right'
        )
    );

    /**
     * @param $site_id
     */
    public function set_site_id($site_id) {
        $this->locationHash = 'security.' . $this->action . ($site_id ? '(' . $site_id . ')' : '');
    }
}

