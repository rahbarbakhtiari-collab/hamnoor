<?php
if (!defined('ABSPATH')) { exit; }

/** Adapter from the Core contract to the established Notification Engine 2.0. */
class Hamnoor_Notification_Engine_Service implements Hamnoor_Notification_Engine_Interface {
    public function priority() { return Hamnoor_Notification_Dispatcher::priority(); }
    public function channels() { return Hamnoor_Notification_Dispatcher::channels(); }
    public function reset_channels_cache() { Hamnoor_Notification_Dispatcher::reset_channels_cache(); return true; }
    public function dispatch($notification, $context = array()) { return Hamnoor_Notification_Dispatcher::dispatch($notification, $context); }
    public function dispatch_all($notification, $context = array()) { return Hamnoor_Notification_Dispatcher::dispatch_all($notification, $context); }
    public function dispatch_all_by_id($notification_id, $context = array()) { return Hamnoor_Notification_Dispatcher::dispatch_all_by_id($notification_id, $context); }
    public function process_queue($limit = 40) { return Hamnoor_Notification_Dispatcher::process_queue($limit); }
    public function delivery_summary($notification_id) { return Hamnoor_Notification_Dispatcher::delivery_summary($notification_id); }
    public function recent_deliveries($limit = 50) { return Hamnoor_Notification_Dispatcher::recent_deliveries($limit); }
    public function delivery_stats($days = 7) { return Hamnoor_Notification_Dispatcher::delivery_stats($days); }
    public function queue_stats() { return Hamnoor_Notification_Dispatcher::queue_stats(); }
    public function retry_delivery($delivery_id) { return Hamnoor_Notification_Dispatcher::retry_delivery($delivery_id); }
    public function user_channel_enabled($user_id, $channel) { return Hamnoor_Notification_Dispatcher::user_channel_enabled($user_id, $channel); }
    public function route_diagnostics($user_id) { return Hamnoor_Notification_Dispatcher::route_diagnostics($user_id); }
    public function set_user_channel($user_id, $channel, $enabled, $status = 'connected', $metadata = array()) {
        return Hamnoor_Notification_Dispatcher::set_user_channel($user_id, $channel, $enabled, $status, $metadata);
    }
}
