<?php
/**
 * Plugin Name: Hamnoor Eitaa | ایتای هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: اتصال مستقل هم‌نور به ایتا؛ شامل کانال اعلان، Mini App رسمی، ورود یکپارچه و اتصال شماره موبایل.
 * Version: 4.0.1
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-eitaa
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_EITAA_VERSION', '4.0.1');
define('HAMNOOR_EITAA_FILE', __FILE__);
define('HAMNOOR_EITAA_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_EITAA_URL', plugin_dir_url(__FILE__));

function hamnoor_eitaa_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('eitaa')) { return; }

    $result = $manager->register(array(
        'id' => 'eitaa',
        'name' => 'Hamnoor Eitaa',
        'version' => HAMNOOR_EITAA_VERSION,
        'requires_core' => '4.0.4',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa.php';
        require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa-bridge.php';
        require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa-app-shell.php';
        Hamnoor_Eitaa::init();
        Hamnoor_Eitaa_App_Shell::init();
        Hamnoor_Eitaa_Bridge::init();
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_eitaa_admin_diagnostics');
        add_filter('hamnoor_notification_admin_tabs', 'hamnoor_eitaa_admin_tab');
        add_action('hamnoor_render_notification_admin_tab_eitaa', 'hamnoor_eitaa_render_admin_tab');
        add_filter('hamnoor_eitaa_dashboard_data', 'hamnoor_eitaa_dashboard_data', 10, 2);
        add_action('hamnoor_register_integration_rewrites', array('Hamnoor_Eitaa_Bridge', 'register_rewrite'));
    });

    if (is_wp_error($result)) {
        update_option('hamnoor_eitaa_addon_last_error', $result->get_error_message(), false);
    }
}
add_action('hamnoor_register_addons', 'hamnoor_eitaa_register_addon', 25, 2);

function hamnoor_eitaa_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $channels['eitaa'] = array(
        'installed' => true,
        'available' => Hamnoor_Eitaa::instance()->is_available(),
        'public_configured' => Hamnoor_Eitaa::public_configured(),
        'program_configured' => Hamnoor_Eitaa::program_configured(),
        'bridge_url' => Hamnoor_Eitaa_Bridge::url(),
        'bridge_fallback_url' => Hamnoor_Eitaa_Bridge::fallback_url(),
        'connected_users' => Hamnoor_Eitaa::connected_users_count(),
    );
    return $channels;
}

function hamnoor_eitaa_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array();
    $inserted = false;
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('web_push' === $key || (!$inserted && 'deliveries' === $key && !isset($tabs['web_push']))) {
            $ordered['eitaa'] = __('ایتا', 'hamnoor');
            $inserted = true;
        }
    }
    if (!$inserted) { $ordered['eitaa'] = __('ایتا', 'hamnoor'); }
    return $ordered;
}

function hamnoor_eitaa_render_admin_tab() {
    Hamnoor_Eitaa::render_admin_section();
}

function hamnoor_eitaa_dashboard_data($data, $user_id) {
    $state = function_exists('hamnoor_notifications_channel_dashboard_state') ? hamnoor_notifications_channel_dashboard_state((int)$user_id, 'eitaa') : array();
    $consent = !empty($state['notification_consent']);
    return array(
        'available' => true,
        'connected' => $consent,
        'identity_connected' => !empty($state['identity_connected']),
        'notification_consent' => $consent,
        'needs_reconnect' => !empty($state['needs_reconnect']),
        'phone_binding_valid' => !empty($state['phone_binding_valid']),
        'phone_last4' => !empty($state['phone_last4']) ? (string)$state['phone_last4'] : '',
        'url' => Hamnoor_Eitaa::miniapp_url('dashboard'),
    );
}

function hamnoor_eitaa_register_rewrite_on_core_activation() {
    if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
    require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa.php';
    require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa-bridge.php';
    Hamnoor_Eitaa_Bridge::register_rewrite();
}
add_action('hamnoor_activate_addons', 'hamnoor_eitaa_register_rewrite_on_core_activation');

function hamnoor_eitaa_activate() {
    try {
        if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '4.0.4', '<')) {
            update_option('hamnoor_eitaa_addon_last_error', 'نسخه سازگار هسته هم‌نور نصب یا فعال نیست.', false);
            throw new RuntimeException('نسخه سازگار هسته هم‌نور نصب یا فعال نیست.');
        }
        if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
        require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa.php';
        require_once HAMNOOR_EITAA_PATH . 'includes/class-hamnoor-eitaa-bridge.php';
        Hamnoor_Eitaa_Bridge::register_rewrite();
        flush_rewrite_rules(false);
        delete_option('hamnoor_eitaa_addon_last_error');
    } catch (Throwable $error) {
        update_option('hamnoor_eitaa_addon_last_error', substr($error->getMessage(), 0, 500), false);
        error_log('Hamnoor Eitaa activation error: ' . $error->getMessage());
        throw $error;
    }
}
register_activation_hook(__FILE__, 'hamnoor_eitaa_activate');

function hamnoor_eitaa_deactivate() { flush_rewrite_rules(false); }
register_deactivation_hook(__FILE__, 'hamnoor_eitaa_deactivate');

function hamnoor_eitaa_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.4', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('افزونه ایتای هم‌نور غیرفعال است.', 'hamnoor-eitaa')
        . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-eitaa')
        . '</p></div>';
}
add_action('admin_notices', 'hamnoor_eitaa_dependency_notice');
