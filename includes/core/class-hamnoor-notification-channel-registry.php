<?php
if (!defined('ABSPATH')) { exit; }

/** Core-owned registry of notification channel adapters. */
class Hamnoor_Notification_Channel_Registry {
    private static $channels = array();

    public static function register($id, $channel) {
        $id = sanitize_key((string)$id);
        if (!$id || !($channel instanceof Hamnoor_Notification_Channel_Interface)) { return false; }
        self::$channels[$id] = $channel;
        if (class_exists('Hamnoor_Notifications') && Hamnoor_Notifications::available()) {
            Hamnoor_Notifications::reset_channels_cache();
        }
        return true;
    }

    public static function unregister($id) {
        $id = sanitize_key((string)$id);
        if (!$id || !isset(self::$channels[$id])) { return false; }
        unset(self::$channels[$id]);
        if (class_exists('Hamnoor_Notifications') && Hamnoor_Notifications::available()) {
            Hamnoor_Notifications::reset_channels_cache();
        }
        return true;
    }

    public static function all() {
        $channels = apply_filters('hamnoor_notification_channels', self::$channels);
        $valid = array();
        foreach ((array)$channels as $key => $channel) {
            $key = sanitize_key((string)$key);
            if ($key && $channel instanceof Hamnoor_Notification_Channel_Interface) { $valid[$key] = $channel; }
        }
        return $valid;
    }
}
