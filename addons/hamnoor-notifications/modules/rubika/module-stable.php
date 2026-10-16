<?php
/**
 * Plugin Name: Hamnoor Rubika | روبیکا هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: اتصال مستقل هم‌نور به روبیکا بر پایه getUpdates رسمی و تک‌مسیره.
 * Version: 4.0.0
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-rubika
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_RUBIKA_VERSION', '4.0.0');
define('HAMNOOR_RUBIKA_FILE', __FILE__);
define('HAMNOOR_RUBIKA_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_RUBIKA_URL', plugin_dir_url(__FILE__));

function hamnoor_rubika_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('rubika')) { return; }
    $result = $manager->register(array(
        'id' => 'rubika',
        'name' => 'Hamnoor Rubika',
        'version' => HAMNOOR_RUBIKA_VERSION,
        'requires_core' => '4.0.0',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        require_once HAMNOOR_RUBIKA_PATH . 'includes/class-hamnoor-rubika-stable.php';
        Hamnoor_Rubika::init();
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_rubika_admin_diagnostics');
        add_filter('hamnoor_notification_admin_tabs', 'hamnoor_rubika_admin_tab');
        add_action('hamnoor_render_notification_admin_tab_rubika', 'hamnoor_rubika_render_admin_tab');
        add_filter('hamnoor_rubika_dashboard_data', 'hamnoor_rubika_dashboard_data', 10, 2);
    });
    if (is_wp_error($result)) { update_option('hamnoor_rubika_addon_last_error', $result->get_error_message(), false); }
}
add_action('hamnoor_register_addons', 'hamnoor_rubika_register_addon', 40, 2);

function hamnoor_rubika_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $channels['rubika'] = array(
        'installed' => true,
        'available' => Hamnoor_Rubika::instance()->is_available(),
        'configured' => Hamnoor_Rubika::configured(),
        'receive_mode' => 'getUpdates',
        'connected_users' => Hamnoor_Rubika::connected_users_count(),
    );
    return $channels;
}

function hamnoor_rubika_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array(); $inserted = false;
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('bale' === $key || (!$inserted && 'deliveries' === $key && !isset($tabs['bale']))) {
            $ordered['rubika'] = __('روبیکا', 'hamnoor'); $inserted = true;
        }
    }
    if (!$inserted) { $ordered['rubika'] = __('روبیکا', 'hamnoor'); }
    return $ordered;
}

function hamnoor_rubika_render_admin_tab() { Hamnoor_Rubika::render_admin_section(); }

function hamnoor_rubika_dashboard_data($data, $user_id) {
    return array(
        'available' => true,
        'connected' => Hamnoor_Rubika::instance()->is_connected((int)$user_id),
        'url' => Hamnoor_Rubika::bot_url(),
    );
}

function hamnoor_rubika_core_activate() {
    if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
    require_once HAMNOOR_RUBIKA_PATH . 'includes/class-hamnoor-rubika-stable.php';
    Hamnoor_Rubika::ensure_worker_schedule(true);
}
add_action('hamnoor_activate_addons', 'hamnoor_rubika_core_activate');

function hamnoor_rubika_activate() {
    try {
        if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '4.0.0', '<')) {
            update_option('hamnoor_rubika_addon_last_error', 'نسخه سازگار هسته هم‌نور نصب یا فعال نیست.', false); throw new RuntimeException('نسخه سازگار هسته هم‌نور نصب یا فعال نیست.');
        }
        if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
        require_once HAMNOOR_RUBIKA_PATH . 'includes/class-hamnoor-rubika-stable.php';
        Hamnoor_Rubika::ensure_worker_schedule(true);
        flush_rewrite_rules(false);
        delete_option('hamnoor_rubika_addon_last_error');
    } catch (Throwable $error) {
        update_option('hamnoor_rubika_addon_last_error', substr($error->getMessage(), 0, 500), false);
        error_log('Hamnoor Rubika activation error: ' . $error->getMessage());
        throw $error;
    }
}
register_activation_hook(__FILE__, 'hamnoor_rubika_activate');

function hamnoor_rubika_deactivate() {
    wp_clear_scheduled_hook('hamnoor_rubika_poll_updates');
    wp_clear_scheduled_hook('hamnoor_rubika_getupdates_worker');
    flush_rewrite_rules(false);
}
register_deactivation_hook(__FILE__, 'hamnoor_rubika_deactivate');

function hamnoor_rubika_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('افزونه روبیکا هم‌نور غیرفعال است.', 'hamnoor-rubika') . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-rubika') . '</p></div>';
}
add_action('admin_notices', 'hamnoor_rubika_dependency_notice');
