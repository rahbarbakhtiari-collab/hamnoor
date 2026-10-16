<?php
/**
 * Plugin Name: Hamnoor Bale | بله هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: اتصال مستقل هم‌نور به بله؛ شامل کانال اعلان، بازو، Webhook، Mini App و ورود یکپارچه.
 * Version: 4.0.0
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-bale
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_BALE_VERSION', '4.0.0');
define('HAMNOOR_BALE_FILE', __FILE__);
define('HAMNOOR_BALE_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_BALE_URL', plugin_dir_url(__FILE__));

function hamnoor_bale_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('bale')) { return; }
    $result = $manager->register(array(
        'id' => 'bale',
        'name' => 'Hamnoor Bale',
        'version' => HAMNOOR_BALE_VERSION,
        'requires_core' => '4.0.0',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        require_once HAMNOOR_BALE_PATH . 'includes/class-hamnoor-bale.php';
        Hamnoor_Bale::init();
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_bale_admin_diagnostics');
        add_filter('hamnoor_notification_admin_tabs', 'hamnoor_bale_admin_tab');
        add_action('hamnoor_render_notification_admin_tab_bale', 'hamnoor_bale_render_admin_tab');
        add_filter('hamnoor_bale_dashboard_data', 'hamnoor_bale_dashboard_data', 10, 2);
        add_action('hamnoor_register_integration_rewrites', array('Hamnoor_Bale', 'register_rewrite_rules'));
    });
    if (is_wp_error($result)) { update_option('hamnoor_bale_addon_last_error', $result->get_error_message(), false); }
}
add_action('hamnoor_register_addons', 'hamnoor_bale_register_addon', 30, 2);

function hamnoor_bale_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $channels['bale'] = array(
        'installed' => true,
        'available' => Hamnoor_Bale::instance()->is_available(),
        'configured' => Hamnoor_Bale::configured(),
        'webhook_url' => Hamnoor_Bale::webhook_url(),
        'connected_users' => Hamnoor_Bale::connected_users_count(),
    );
    return $channels;
}

function hamnoor_bale_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array(); $inserted = false;
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('eitaa' === $key || (!$inserted && 'deliveries' === $key && !isset($tabs['eitaa']))) {
            $ordered['bale'] = __('بله', 'hamnoor'); $inserted = true;
        }
    }
    if (!$inserted) { $ordered['bale'] = __('بله', 'hamnoor'); }
    return $ordered;
}

function hamnoor_bale_render_admin_tab() { Hamnoor_Bale::render_admin_section(); }

function hamnoor_bale_dashboard_data($data, $user_id) {
    $state = function_exists('hamnoor_notifications_channel_dashboard_state') ? hamnoor_notifications_channel_dashboard_state((int)$user_id, 'bale') : array();
    $consent = !empty($state['notification_consent']);
    return array(
        'available' => true,
        'connected' => $consent,
        'identity_connected' => !empty($state['identity_connected']),
        'notification_consent' => $consent,
        'needs_reconnect' => !empty($state['needs_reconnect']),
        'phone_binding_valid' => !empty($state['phone_binding_valid']),
        'phone_last4' => !empty($state['phone_last4']) ? (string)$state['phone_last4'] : '',
        'url' => Hamnoor_Bale::bot_url(),
    );
}

function hamnoor_bale_core_activate() {
    if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
    require_once HAMNOOR_BALE_PATH . 'includes/class-hamnoor-bale.php';
    Hamnoor_Bale::register_rewrite_rules();
}
add_action('hamnoor_activate_addons', 'hamnoor_bale_core_activate');

function hamnoor_bale_activate() {
    try {
        if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '4.0.0', '<')) {
            update_option('hamnoor_bale_addon_last_error', 'نسخه سازگار هسته هم‌نور نصب یا فعال نیست.', false); throw new RuntimeException('نسخه سازگار هسته هم‌نور نصب یا فعال نیست.');
        }
        if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
        require_once HAMNOOR_BALE_PATH . 'includes/class-hamnoor-bale.php';
        Hamnoor_Bale::register_rewrite_rules();
        flush_rewrite_rules(false);
        delete_option('hamnoor_bale_addon_last_error');
    } catch (Throwable $error) {
        update_option('hamnoor_bale_addon_last_error', substr($error->getMessage(), 0, 500), false);
        error_log('Hamnoor Bale activation error: ' . $error->getMessage());
        throw $error;
    }
}
register_activation_hook(__FILE__, 'hamnoor_bale_activate');
function hamnoor_bale_deactivate() { flush_rewrite_rules(false); }
register_deactivation_hook(__FILE__, 'hamnoor_bale_deactivate');

function hamnoor_bale_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('افزونه بله هم‌نور غیرفعال است.', 'hamnoor-bale') . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-bale') . '</p></div>';
}
add_action('admin_notices', 'hamnoor_bale_dependency_notice');
