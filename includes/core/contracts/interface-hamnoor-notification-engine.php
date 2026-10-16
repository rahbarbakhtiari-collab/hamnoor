<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Stable Core contract for the optional Notification Engine add-on.
 *
 * Core and other add-ons must depend on this contract/facade, never on the
 * concrete dispatcher implementation. This lets the engine move to a separate
 * WordPress add-on package later without changing consumers.
 */
interface Hamnoor_Notification_Engine_Interface {
    public function priority();
    public function channels();
    public function reset_channels_cache();
    public function dispatch($notification, $context = array());
    public function dispatch_all($notification, $context = array());
    public function dispatch_all_by_id($notification_id, $context = array());
    public function process_queue($limit = 40);
    public function delivery_summary($notification_id);
    public function recent_deliveries($limit = 50);
    public function delivery_stats($days = 7);
    public function queue_stats();
    public function retry_delivery($delivery_id);
    public function user_channel_enabled($user_id, $channel);
    public function route_diagnostics($user_id);
    public function set_user_channel($user_id, $channel, $enabled, $status = 'connected', $metadata = array());
}
