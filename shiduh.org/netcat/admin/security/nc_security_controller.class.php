<?php

class nc_security_controller extends nc_ui_controller {

    protected $ui_config;
    protected $set_current_site_id_as_default = false;

    protected function before_action() {
        /** @var Permission $perm */
        global $perm;
        if (!$perm->isDirector() && !$perm->isSupervisor() && !$perm->isGuest()) {
            die(NETCAT_MODERATION_ERROR_NORIGHT);
        }

        $this->ui_config = new nc_security_admin_ui();
        $this->ui_config->set_site_id($this->site_id);
        return parent::before_action();
    }

    /**
     * @param nc_ui_view $view
     * @return nc_ui_view
     */
    protected function init_view(nc_ui_view $view) {
        return $view->with('site_id', $this->site_id);
    }

    /**
     * @param $result
     * @return string
     */
    protected function after_action($result) {
        return BeginHtml() . $result . EndHtml();
    }

    /**
     *
     */
    protected function action_show_settings() {
        $this->ui_config->headerText = NETCAT_SECURITY_SETTINGS;

        $nc_core = nc_core::get_object();

        $view = $this->view('settings', array(
                    'saved' => false,
                    'default_settings_link' => $nc_core->ADMIN_PATH . '#security.settings(0)',
                ));

        $this->set_view_filter_variables($view);
        $this->set_view_captcha_variables($view);

        return $view;
    }

    /**
     * @param nc_ui_view $view
     */
    protected function set_view_filter_variables(nc_ui_view $view) {
        $site_has_own_filter_settings = true;

        if ($this->site_id) {
            $site_has_own_filter_settings = $this->site_has_own_settings_like('Security%Filter%');
        }

        $view->with('site_has_own_filter_settings', $site_has_own_filter_settings);

        $nc_core = nc_core::get_object();
        $configuration_errors = array_merge(
            $nc_core->security->xss_filter->get_configuration_errors(),
            $nc_core->security->sql_filter->get_configuration_errors(),
            $nc_core->security->php_filter->get_configuration_errors()
        );

        $view->with('filter_configuration_errors', $configuration_errors);
    }

    /**
     * @param nc_ui_view $view
     */
    protected function set_view_captcha_variables(nc_ui_view $view) {
        $nc_core = nc_core::get_object();

        $site_has_own_captcha_settings = true;
        if ($this->site_id) {
            $site_has_own_captcha_settings = $this->site_has_own_settings_like('AuthCaptcha%');
        }

        $captcha_enabled = $nc_core->get_settings('AuthCaptchaEnabled', 'system', false, $this->site_id);
        $captcha_attempts =  $nc_core->get_settings('AuthCaptchaAttempts', 'system', false, $this->site_id);

        if ($captcha_enabled) {
            $captcha_mode = $captcha_attempts ? 'count' : 'always';
        }
        else {
            $captcha_mode = 'disabled';
        }

        $view->with('site_has_own_captcha_settings', $site_has_own_captcha_settings)
             ->with('captcha_mode', $captcha_mode)
             ->with('captcha_free_attempts', $captcha_attempts);
    }

    /**
     * @param $pattern
     * @return bool
     */
    protected function site_has_own_settings_like($pattern) {
        return (bool)nc_db()->get_var(
                "SELECT 1 
                   FROM `Settings` 
                  WHERE `Key` LIKE '$pattern' 
                    AND `Module` = 'system' 
                    AND `Catalogue_ID` = $this->site_id
                  LIMIT 1"
            );
    }

    /**
     * @param $pattern
     */
    protected function delete_site_settings_like($pattern) {
        nc_db()->query(
            "DELETE FROM `Settings` 
              WHERE `Key` LIKE '$pattern'
                AND `Module` = 'system'
                AND `Catalogue_ID` = $this->site_id"
        );
        nc_core::get_object()->get_settings('', '', true, $this->site_id);
    }

    /**
     * @return nc_ui_view
     */
    protected function action_save_settings() {
        /** @var Permission $perm */
        global $perm;
        if ($perm->isGuest()) {
            return $this->view('settings', array('saved' => false));
        }

        $this->save_filter_settings();
        $this->save_captcha_settings();

        return $this->action_show_settings()->with('saved', true);
    }

    /**
     *
     */
    protected function save_filter_settings() {
        $nc_core = nc_core::get_object();

        if ($this->site_id && $nc_core->input->fetch_post('filters_use_default_settings')) {
            $this->delete_site_settings_like('Security%Filter%');
        }
        else {
            $settings = (array)$nc_core->input->fetch_post('filter_settings');
            foreach ($settings as $k => $v) {
                $nc_core->set_settings($k, $v, 'system', $this->site_id);
            }
        }
    }

    /**
     *
     */
    protected function save_captcha_settings() {
        $nc_core = nc_core::get_object();

        if ($this->site_id && $nc_core->input->fetch_post('captcha_use_default_settings')) {
            $this->delete_site_settings_like('AuthCaptcha%');
        }
        else {
            $mode = $nc_core->input->fetch_post('captcha_mode');
            $attempts = $nc_core->input->fetch_post('captcha_free_attempts');
            $nc_core->set_settings('AuthCaptchaEnabled', $mode === 'disabled' ? 0 : 1, 'system', $this->site_id);
            $nc_core->set_settings('AuthCaptchaAttempts', $mode === 'always' ? 0 : $attempts, 'system', $this->site_id);
        }
    }

    /**
     * @return nc_ui_view
     */
    protected function action_disable_all_filters() {
        $nc_core = nc_core::get_object();

        $nc_core->db->get_var(
            "UPDATE `Settings`
                SET `Value` = 0
              WHERE `Key` LIKE 'Security%Filter%' 
                AND `Module` = 'system'"
        );

        return $this->view('message')->with('message', NETCAT_SECURITY_FILTERS_DISABLED);
    }

}
