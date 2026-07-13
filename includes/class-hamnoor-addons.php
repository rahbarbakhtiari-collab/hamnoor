<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Addons {
    private static $notification_providers = array();

    public static function init() {
        do_action('hamnoor_addons_loaded');
    }

    public static function register_notification_provider($id, $provider) {
        $id = sanitize_key($id);
        if (!$id || !($provider instanceof Hamnoor_Notification_Provider_Interface)) {
            return false;
        }
        self::$notification_providers[$id] = $provider;
        return true;
    }

    public static function notification_providers() {
        $providers = apply_filters('hamnoor_notification_providers', self::$notification_providers);
        return is_array($providers) ? $providers : array();
    }

    public static function android_api_enabled() {
        return (bool) apply_filters('hamnoor_android_api_enabled', true);
    }
}
