<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('hamnoor_settings', array());
$delete_data = is_array($settings) && !empty($settings['delete_data_on_uninstall']);
if (!$delete_data) {
    return;
}

global $wpdb;
wp_clear_scheduled_hook('hamnoor_daily_cleanup');
wp_clear_scheduled_hook('hamnoor_auto_release_parts');
wp_clear_scheduled_hook('hamnoor_internal_reminders');
wp_clear_scheduled_hook('hamnoor_personal_reminders');

$pages = get_option('hamnoor_pages', array());
if (is_array($pages)) {
    foreach ($pages as $key => $page_id) {
        $page_id = (int) $page_id;
        if ($page_id && (string) get_post_meta($page_id, '_hamnoor_auto_page', true) === (string) $key) {
            wp_delete_post($page_id, true);
        }
    }
}

// Keep uninstall coverage aligned with Hamnoor_Schema_Manifest. The explicit
// dependency-safe order avoids leaving Foundation tables behind when test data
// removal is requested.
foreach (array(
    'hamnoor_notification_deliveries',
    'hamnoor_push_subscriptions',
    'hamnoor_notification_channels',
    'hamnoor_notifications',
    'hamnoor_rubika_links',
    'hamnoor_reading_progress',
    'hamnoor_personal_quran_progress',
    'hamnoor_reminder_schedules',
    'hamnoor_claim_events',
    'hamnoor_contributions',
    'hamnoor_parts',
    'hamnoor_participants',
    'hamnoor_khatms',
    'hamnoor_content_items',
    'hamnoor_sessions',
    'hamnoor_otps',
    'hamnoor_logs',
    'hamnoor_migrations',
    'hamnoor_users',
) as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}
foreach (array('hamnoor_settings', 'hamnoor_pages', 'hamnoor_db_version', 'hamnoor_rewrite_version', 'hamnoor_texts', 'hamnoor_creation_policy_admin_set', 'hamnoor_creation_policy_migrated_251', 'hamnoor_repeat_limit_schema_version', 'hamnoor_custom_users_migrated_310', 'hamnoor_reminder_cursor', 'hamnoor_webpush_vapid', 'hamnoor_webpush_rewrite_version', 'hamnoor_eitaa_settings', 'hamnoor_bale_settings', 'hamnoor_rubika_settings', 'hamnoor_plugin_version', 'hamnoor_upgrade_backup', 'hamnoor_reminder_last_run', 'hamnoor_personal_reminder_last_run') as $option) {
    delete_option($option);
}

$admin = get_role('administrator');
if ($admin) {
    $admin->remove_cap('hamnoor_create_khatm');
    $admin->remove_cap('hamnoor_manage_own_khatm');
    $admin->remove_cap('hamnoor_manage_all_khatms');
}
remove_role('hamnoor_manager');
remove_role('hamnoor_member');


$option_like = $wpdb->esc_like('_transient_hamnoor_') . '%';
$timeout_like = $wpdb->esc_like('_transient_timeout_hamnoor_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $option_like, $timeout_like));
