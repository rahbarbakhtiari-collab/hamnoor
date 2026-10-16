<?php
/**
 * Plugin Name: Hamnoor Telegram | تلگرام هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: پوسته مستقل کانال تلگرام هم‌نور برای توسعه اتصال عملیاتی در نسخه‌های بعد.
 * Version: 4.0.0
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-telegram
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_TELEGRAM_VERSION', '4.0.0');
define('HAMNOOR_TELEGRAM_FILE', __FILE__);

function hamnoor_telegram_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('telegram')) { return; }
    $result = $manager->register(array(
        'id' => 'telegram',
        'name' => 'Hamnoor Telegram',
        'version' => HAMNOOR_TELEGRAM_VERSION,
        'requires_core' => '4.0.0',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_telegram_admin_diagnostics');
    });
    if (is_wp_error($result)) { update_option('hamnoor_telegram_addon_last_error', $result->get_error_message(), false); }
}
add_action('hamnoor_register_addons', 'hamnoor_telegram_register_addon', 60, 2);

function hamnoor_telegram_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $channels['telegram'] = array(
        'installed' => true,
        'available' => false,
        'configured' => false,
    );
    return $channels;
}

function hamnoor_telegram_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array(); $inserted = false;
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('whatsapp' === $key || (!$inserted && 'deliveries' === $key && !isset($tabs['whatsapp']))) {
            $ordered['telegram'] = __('تلگرام', 'hamnoor'); $inserted = true;
        }
    }
    if (!$inserted) { $ordered['telegram'] = __('تلگرام', 'hamnoor'); }
    return $ordered;
}

function hamnoor_telegram_render_admin_tab() {
    echo '<div class="card" style="max-width:820px"><h2>' . esc_html__('تلگرام', 'hamnoor') . '</h2><p>'
        . esc_html__('زیرساخت این کانال در ترتیب اعلان‌ها رزرو شده است، اما اتصال عملیاتی آن هنوز در این نسخه فعال نشده است.', 'hamnoor')
        . '</p></div>';
}

function hamnoor_telegram_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('افزونه تلگرام هم‌نور غیرفعال است.', 'hamnoor-telegram') . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-telegram') . '</p></div>';
}
add_action('admin_notices', 'hamnoor_telegram_dependency_notice');
