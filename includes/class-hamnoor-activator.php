<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Activator {
    public static function activate() {
        Hamnoor_DB::install();
        if (!wp_next_scheduled('hamnoor_auto_release_parts')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'hamnoor_auto_release_parts');
        }
        if (!wp_next_scheduled('hamnoor_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'hamnoor_daily_cleanup');
        }
        Hamnoor_Router::instance()->register_rewrite_rules();
        flush_rewrite_rules(false);
    }
}
