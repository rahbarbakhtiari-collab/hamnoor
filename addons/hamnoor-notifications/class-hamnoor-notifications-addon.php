<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Notifications_Addon implements Hamnoor_Addon_Interface {
    public function manifest() {
        return array(
            'id' => 'notifications',
            'name' => 'Hamnoor Notifications',
            'version' => HAMNOOR_NOTIFICATIONS_VERSION,
            'requires_core' => '4.0.0',
            'requires_api' => '1.1.0',
            'dependencies' => array(),
            'default_enabled' => true,
        );
    }

    public function boot($api) {
        $engine = new Hamnoor_Notification_Engine_Service();
        $api->services()->register('notifications', $engine, true);
        Hamnoor_Notification_Dispatcher::init();
        do_action('hamnoor_notifications_ready', $engine, $api);
    }
}
