<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Deactivator {
    public static function deactivate() {
        wp_clear_scheduled_hook('hamnoor_daily_cleanup');
        wp_clear_scheduled_hook('hamnoor_auto_release_parts');
        flush_rewrite_rules(false);
    }
}
