<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Addons {
    private static $notification_providers = array();

    public static function init() {
        // Keep the established 3.x signal for backwards compatibility, then
        // let the 4.x manager discover manifest-based add-ons and boot them in
        // dependency order behind an isolation boundary.
        do_action('hamnoor_addons_loaded');
        if (class_exists('Hamnoor_Addon_Manager')) {
            Hamnoor_Addon_Manager::instance()->discover();
            Hamnoor_Addon_Manager::instance()->boot_all();
        }
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

    public static function register_notification_channel($id, $channel) {
        // 4.x compatibility shim. Channel ownership now belongs to Core's
        // contract registry, not to the Notification Engine implementation.
        return Hamnoor_Notification_Channel_Registry::register($id, $channel);
    }

    public static function notification_channels() {
        $channels = Hamnoor_Notification_Channel_Registry::all();
        $channels = apply_filters('hamnoor_addon_notification_channels', $channels);
        return is_array($channels) ? $channels : array();
    }

    public static function android_api_enabled() {
        return (bool) apply_filters('hamnoor_android_api_enabled', true);
    }
}
