<?php

/**
 * Class nc_Security
 */

class nc_Security extends nc_System {

    /** @var  nc_security_filter_sql */
    public $sql_filter;

    /** @var  nc_security_filter_php */
    public $php_filter;

    /** @var  nc_security_filter_xss */
    public $xss_filter;

    /**
     *
     */
    public function __construct() {
        parent::__construct();
        $this->sql_filter = new nc_security_filter_sql();
        $this->php_filter = new nc_security_filter_php();
        $this->xss_filter = new nc_security_filter_xss();
    }

    /**
     * @internal
     */
    public function init_filters() {
        $nc_core = nc_core::get_object();
        $this->sql_filter->set_mode($nc_core->get_settings('SecurityInputFilterSQL'));
        $this->php_filter->set_mode($nc_core->get_settings('SecurityInputFilterPHP'));
        $this->xss_filter->set_mode($nc_core->get_settings('SecurityInputFilterXSS'));
    }

    /**
     * Добавляет данные к проверяемым значениям
     * @param array[] $additional_input (ключ — '_GET', '_POST', '_COOKIE', '_SESSION')
     */
    public function add_checked_input(array $additional_input) {
        $this->sql_filter->add_checked_input($additional_input);
        $this->php_filter->add_checked_input($additional_input);
        $this->xss_filter->add_checked_input($additional_input);
    }

    /**
     * XSS filter
     */
    public function xss_clean($data) {
        $charset = nc_core::get_object()->NC_CHARSET;

        // supported charsets
        if (!in_array(strtolower($charset), array('utf-8', 'cp1251', 'windows-1251', 'win-1251', '1251'))) {
            return $data;
        }

        // Fix &entity\n;
        $data = str_replace(array('&amp;', '&lt;', '&gt;'), array('&amp;amp;', '&amp;lt;', '&amp;gt;'), $data);
        $data = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $data);
        $data = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $data);
        $data = html_entity_decode($data, ENT_COMPAT, $charset);

        // Remove any attribute starting with "on" or xmlns
        $data = preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);

        // Remove javascript: and vbscript: protocols
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $data);

        // Only works in IE: <span style="width: expression(alert('Ping!'));"></span>
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu', '$1>', $data);

        // Remove namespaced elements (we do not need them)
        $data = preg_replace('#</*\w+:\w[^>]*+>#i', '', $data);

        do {
            // Remove really unwanted tags
            $old_data = $data;
            $data = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $data);
        } while ($old_data !== $data);

        // JS
        $find = array(
            '/data:/i' => 'd&#097;ta:',
            '/about:/i' => '&#097;bout:',
            '/vbscript:/i' => 'vbscript<b></b>:',
            '/(?:on(click|load|unload|abort|error|blur|change|focus|reset|submit|dblclick|keydown|keypress|keyup|mousedown|mouseup|mouseover|mouseout|select))/i' => '&#111;n$1',
            '/javascript/i' => 'j&#097;vascript',
            '#<script#i' => '&lt;script'
        );

        foreach ($find as $key => $value) {
            $data = preg_replace($key, $value, $data);
        }

        return $data;
    }

    /**
     * Проверяет, находится ли путь в пределах одного из сайтов под управлением системы
     *
     * @param $url
     * @return bool
     */
    public function url_matches_local_site($url) {
        $double_slash_position = strpos($url, '//');
        if ($double_slash_position === false) {
            return true;
        }

        // parse_url() до PHP 5.4.7 не поддерживает ссылки без указания протокола (https://bugs.php.net/bug.php?id=66274)
        if ($double_slash_position === 0) {
            $url = 'http:' . $url;
        }

        $host_name = parse_url($url, PHP_URL_HOST);
        $host_name_regexp = '/\s' . preg_quote($host_name, '/') . '\s/';
        foreach (nc_core::get_object()->catalogue->get_all() as $site) {
            if (preg_match($host_name_regexp, " $site[Domain] $site[Mirrors] ")) {
                return true;
            }
        }

        return false;
    }

}