<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Deactivator {
    public static function deactivate() {
        wp_clear_scheduled_hook('hamnoor_daily_cleanup');
        wp_clear_scheduled_hook('hamnoor_auto_release_parts');
        wp_clear_scheduled_hook('hamnoor_internal_reminders');
        wp_clear_scheduled_hook('hamnoor_personal_reminders');
        do_action('hamnoor_deactivate_addons');
        flush_rewrite_rules(false);
    }
}
