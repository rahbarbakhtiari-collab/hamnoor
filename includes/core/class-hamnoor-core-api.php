<?php
if (!defined('ABSPATH')) { exit; }

/** Stable facade that add-ons should depend on instead of implementation files. */
class Hamnoor_Core_API {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function version() { return defined('HAMNOOR_CORE_API_VERSION') ? HAMNOOR_CORE_API_VERSION : '0.0.0'; }
    public function core_version() { return defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '0.0.0'; }
    public function events() { return Hamnoor_Event_Bus::instance(); }
    public function addons() { return Hamnoor_Addon_Manager::instance(); }
    public function services() { return Hamnoor_Core::instance()->services(); }
    public function service($id, $default = null) { return $this->services()->get($id, $default); }
    public function notifications() {
        $service = $this->service('notifications', null);
        return ($service instanceof Hamnoor_Notification_Engine_Interface) ? $service : null;
    }
    public function emit($event, $payload = array(), $context = array()) { return $this->events()->emit($event, $payload, $context); }
    public function on($event, $callback, $priority = 10, $owner = '') { return $this->events()->on($event, $callback, $priority, $owner); }
    public function durable_events_after($event, $after_id = 0, $limit = 50) { return class_exists('Hamnoor_Domain_Event_Store') ? Hamnoor_Domain_Event_Store::get_after($event, $after_id, $limit) : array(); }
    public function durable_event_for_subject($event, $subject_user_id) { return class_exists('Hamnoor_Domain_Event_Store') ? Hamnoor_Domain_Event_Store::latest_for_subject($event, $subject_user_id) : null; }
    public function current_user_id() { return class_exists('Hamnoor_Mobile_Access') ? (int) Hamnoor_Mobile_Access::current_user_id() : 0; }
    public function health() { return Hamnoor_Core_Health::snapshot(); }
}
