<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_I18n {
    public static function init() {
        load_plugin_textdomain('hamnoor', false, dirname(HAMNOOR_BASENAME) . '/languages');
    }
}
