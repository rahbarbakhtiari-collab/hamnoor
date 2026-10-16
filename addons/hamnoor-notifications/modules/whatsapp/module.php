<?php
/**
 * Plugin Name: Hamnoor WhatsApp | واتساپ هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: پوسته مستقل کانال واتساپ هم‌نور برای توسعه اتصال عملیاتی در نسخه‌های بعد.
 * Version: 4.0.0
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-whatsapp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_WHATSAPP_VERSION', '4.0.0');
define('HAMNOOR_WHATSAPP_FILE', __FILE__);

function hamnoor_whatsapp_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('whatsapp')) { return; }
    $result = $manager->register(array(
        'id' => 'whatsapp',
        'name' => 'Hamnoor WhatsApp',
        'version' => HAMNOOR_WHATSAPP_VERSION,
        'requires_core' => '4.0.0',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_whatsapp_admin_diagnostics');
    });
    if (is_wp_error($result)) { update_option('hamnoor_whatsapp_addon_last_error', $result->get_error_message(), false); }
}
add_action('hamnoor_register_addons', 'hamnoor_whatsapp_register_addon', 50, 2);

function hamnoor_whatsapp_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $channels['whatsapp'] = array(
        'installed' => true,
        'available' => false,
        'configured' => false,
    );
    return $channels;
}

function hamnoor_whatsapp_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array(); $inserted = false;
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('rubika' === $key || (!$inserted && 'deliveries' === $key && !isset($tabs['rubika']))) {
            $ordered['whatsapp'] = __('واتساپ', 'hamnoor'); $inserted = true;
        }
    }
    if (!$inserted) { $ordered['whatsapp'] = __('واتساپ', 'hamnoor'); }
    return $ordered;
}

function hamnoor_whatsapp_render_admin_tab() {
    echo '<div class="card" style="max-width:820px"><h2>' . esc_html__('واتساپ', 'hamnoor') . '</h2><p>'
        . esc_html__('زیرساخت این کانال در ترتیب اعلان‌ها رزرو شده است، اما اتصال عملیاتی آن هنوز در این نسخه فعال نشده است.', 'hamnoor')
        . '</p></div>';
}

function hamnoor_whatsapp_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('افزونه واتساپ هم‌نور غیرفعال است.', 'hamnoor-whatsapp') . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-whatsapp') . '</p></div>';
}
add_action('admin_notices', 'hamnoor_whatsapp_dependency_notice');
