<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Stable notification facade exposed by Hamnoor Core.
 *
 * Consumers use this class only. The concrete engine is resolved from the
 * Core service container and may live in a separate add-on package.
 */
class Hamnoor_Notifications {
    private static function engine() {
        if (!class_exists('Hamnoor_Core')) { return null; }
        $service = Hamnoor_Core::instance()->services()->get('notifications', null);
        return ($service instanceof Hamnoor_Notification_Engine_Interface) ? $service : null;
    }

    public static function available() { return null !== self::engine(); }

    private static function unavailable_error() {
        return new WP_Error('hamnoor_notifications_unavailable', __('ماژول اعلان هم‌نور در دسترس نیست.', 'hamnoor'));
    }

    public static function priority() {
        $engine = self::engine();
        return $engine ? $engine->priority() : array();
    }

    public static function channels() {
        $engine = self::engine();
        return $engine ? $engine->channels() : array();
    }

    public static function reset_channels_cache() {
        $engine = self::engine();
        return $engine ? $engine->reset_channels_cache() : false;
    }

    public static function dispatch($notification, $context = array()) {
        $engine = self::engine();
        return $engine ? $engine->dispatch($notification, $context) : self::unavailable_error();
    }

    public static function dispatch_all($notification, $context = array()) {
        $engine = self::engine();
        return $engine ? $engine->dispatch_all($notification, $context) : self::unavailable_error();
    }

    public static function dispatch_all_by_id($notification_id, $context = array()) {
        $engine = self::engine();
        return $engine ? $engine->dispatch_all_by_id($notification_id, $context) : self::unavailable_error();
    }

    public static function process_queue($limit = 40) {
        $engine = self::engine();
        return $engine ? $engine->process_queue($limit) : 0;
    }

    public static function delivery_summary($notification_id) {
        $engine = self::engine();
        return $engine ? $engine->delivery_summary($notification_id) : array(
            'notification_id' => (int)$notification_id,
            'status' => 'unavailable',
            'channel' => '',
            'error' => __('ماژول اعلان در دسترس نیست.', 'hamnoor'),
            'attempts' => 0,
            'rows' => array(),
        );
    }

    public static function recent_deliveries($limit = 50) {
        $engine = self::engine();
        return $engine ? $engine->recent_deliveries($limit) : array();
    }

    public static function delivery_stats($days = 7) {
        $engine = self::engine();
        return $engine ? $engine->delivery_stats($days) : array();
    }

    public static function queue_stats() {
        $engine = self::engine();
        return $engine ? $engine->queue_stats() : array('pending'=>0,'queued'=>0,'retry_wait'=>0,'sending'=>0,'next_attempt'=>'');
    }

    public static function retry_delivery($delivery_id) {
        $engine = self::engine();
        return $engine ? $engine->retry_delivery($delivery_id) : self::unavailable_error();
    }

    public static function user_channel_enabled($user_id, $channel) {
        $engine = self::engine();
        return $engine ? (bool)$engine->user_channel_enabled($user_id, $channel) : false;
    }

    public static function route_diagnostics($user_id) {
        $engine = self::engine();
        return $engine ? $engine->route_diagnostics($user_id) : array('winner'=>'','supplemental'=>array(),'rows'=>array());
    }

    public static function set_user_channel($user_id, $channel, $enabled, $status = 'connected', $metadata = array()) {
        $engine = self::engine();
        return $engine ? $engine->set_user_channel($user_id, $channel, $enabled, $status, $metadata) : false;
    }
}
