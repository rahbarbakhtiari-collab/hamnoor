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

$pages = get_option('hamnoor_pages', array());
if (is_array($pages)) {
    foreach ($pages as $key => $page_id) {
        $page_id = (int) $page_id;
        if ($page_id && (string) get_post_meta($page_id, '_hamnoor_auto_page', true) === (string) $key) {
            wp_delete_post($page_id, true);
        }
    }
}

foreach (array('hamnoor_otps', 'hamnoor_logs', 'hamnoor_parts', 'hamnoor_participants', 'hamnoor_khatms') as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}
foreach (array('hamnoor_settings', 'hamnoor_pages', 'hamnoor_db_version', 'hamnoor_rewrite_version', 'hamnoor_texts', 'hamnoor_creation_policy_admin_set', 'hamnoor_creation_policy_migrated_251', 'hamnoor_repeat_limit_schema_version') as $option) {
    delete_option($option);
}

$admin = get_role('administrator');
if ($admin) {
    $admin->remove_cap('hamnoor_create_khatm');
    $admin->remove_cap('hamnoor_manage_own_khatm');
    $admin->remove_cap('hamnoor_manage_all_khatms');
}
remove_role('hamnoor_manager');

foreach (array('hamnoor_phone', 'hamnoor_phone_verified', 'hamnoor_verified_contacts') as $meta_key) {
    delete_metadata('user', 0, $meta_key, '', true);
}

$option_like = $wpdb->esc_like('_transient_hamnoor_') . '%';
$timeout_like = $wpdb->esc_like('_transient_timeout_hamnoor_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $option_like, $timeout_like));
