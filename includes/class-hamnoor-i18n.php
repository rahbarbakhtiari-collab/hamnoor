<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_I18n {
    public static function init() {
        add_action('init', array(__CLASS__, 'load_textdomain'), 0);
    }

    public static function load_textdomain() {
        load_plugin_textdomain('hamnoor', false, dirname(HAMNOOR_BASENAME) . '/languages');
    }
}
