<?php
/**
 * Plugin Name: Hamnoor Web Push | وب‌پوش هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: کانال مستقل Web Push برای افزونه هم‌نور؛ شامل عضویت مرورگر، Service Worker، VAPID و ارسال اعلان.
 * Version: 4.0.0
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-web-push
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_WEB_PUSH_VERSION', '4.0.0');
define('HAMNOOR_WEB_PUSH_FILE', __FILE__);
define('HAMNOOR_WEB_PUSH_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_WEB_PUSH_URL', plugin_dir_url(__FILE__));

function hamnoor_web_push_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('web_push')) { return; }

    $result = $manager->register(array(
        'id' => 'web_push',
        'name' => 'Hamnoor Web Push',
        'version' => HAMNOOR_WEB_PUSH_VERSION,
        'requires_core' => '4.0.0',
        'requires_api' => '1.1.0',
        'dependencies' => array('notifications'),
        'default_enabled' => true,
    ), function() {
        require_once HAMNOOR_WEB_PUSH_PATH . 'includes/class-hamnoor-web-push.php';
        Hamnoor_Web_Push::init();
        add_filter('hamnoor_web_push_ui_available', '__return_true');
        add_filter('hamnoor_channel_admin_diagnostics', 'hamnoor_web_push_admin_diagnostics');
        add_filter('hamnoor_notification_admin_tabs', 'hamnoor_web_push_admin_tab');
        add_action('hamnoor_render_notification_admin_tab_web_push', 'hamnoor_web_push_render_admin_tab');
        add_action('hamnoor_register_integration_rewrites', array(Hamnoor_Web_Push::instance(), 'register_rewrite_rule'));
    });

    if (is_wp_error($result)) {
        update_option('hamnoor_web_push_last_error', $result->get_error_message(), false);
    }
}
add_action('hamnoor_register_addons', 'hamnoor_web_push_register_addon', 20, 2);

function hamnoor_web_push_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('وب‌پوش هم‌نور غیرفعال است.', 'hamnoor-web-push')
        . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-web-push')
        . '</p></div>';
}
add_action('admin_notices', 'hamnoor_web_push_dependency_notice');

function hamnoor_web_push_activate() {
    try {
        if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '4.0.0', '<')) {
            update_option('hamnoor_web_push_last_error', 'نسخه سازگار هسته هم‌نور نصب یا فعال نیست.', false);
            throw new RuntimeException('نسخه سازگار هسته هم‌نور نصب یا فعال نیست.');
        }
        if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
        require_once HAMNOOR_WEB_PUSH_PATH . 'includes/class-hamnoor-web-push.php';
        Hamnoor_Web_Push::instance()->register_rewrite_rule();
        $keys = Hamnoor_Web_Push::ensure_vapid_keys();
        if (is_wp_error($keys)) { throw new RuntimeException($keys->get_error_message()); }
        update_option('hamnoor_webpush_rewrite_version', HAMNOOR_WEB_PUSH_VERSION, false);
        flush_rewrite_rules(false);
        delete_option('hamnoor_web_push_last_error');
    } catch (Throwable $error) {
        update_option('hamnoor_web_push_last_error', substr($error->getMessage(), 0, 500), false);
        error_log('Hamnoor Web Push activation error: ' . $error->getMessage());
        throw $error;
    }
}
register_activation_hook(__FILE__, 'hamnoor_web_push_activate');

function hamnoor_web_push_admin_diagnostics($channels) {
    $channels = is_array($channels) ? $channels : array();
    $keys = Hamnoor_Web_Push::ensure_vapid_keys();
    $channels['web_push'] = array(
        'installed' => true,
        'available' => Hamnoor_Web_Push::instance()->is_available() && !is_wp_error($keys),
        'subscriptions' => Hamnoor_Web_Push::active_subscription_count(),
        'service_worker_url' => Hamnoor_Web_Push::service_worker_url(),
        'public_key_hint' => !is_wp_error($keys) && !empty($keys['public_key']) ? substr($keys['public_key'], 0, 24) . '…' : '',
        'diagnostics' => Hamnoor_Web_Push::diagnostics(),
    );
    return $channels;
}

function hamnoor_web_push_admin_tab($tabs) {
    $tabs = is_array($tabs) ? $tabs : array();
    $ordered = array();
    foreach ($tabs as $key => $label) {
        $ordered[$key] = $label;
        if ('deliveries' === $key) { $ordered['web_push'] = 'Web Push'; }
    }
    if (!isset($ordered['web_push'])) { $ordered['web_push'] = 'Web Push'; }
    return $ordered;
}

function hamnoor_web_push_render_admin_tab($admin_data = array()) {
    $admin_data = is_array($admin_data) ? $admin_data : array();
    $diagnostics = !empty($admin_data['diagnostics']) && is_array($admin_data['diagnostics']) ? $admin_data['diagnostics'] : Hamnoor_Web_Push::diagnostics();
    $ready = !empty($admin_data['available']);
    $count = isset($admin_data['subscriptions']) ? (int)$admin_data['subscriptions'] : Hamnoor_Web_Push::active_subscription_count();
    echo '<h2>Web Push</h2><table class="widefat striped"><tbody>';
    echo '<tr><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><td><strong>' . esc_html($ready ? __('آماده', 'hamnoor') : __('نیازمند بررسی', 'hamnoor')) . '</strong></td></tr>';
    echo '<tr><th>' . esc_html__('HTTPS', 'hamnoor') . '</th><td><code>' . esc_html(is_ssl() ? 'OK' : 'Required') . '</code></td></tr>';
    echo '<tr><th>OpenSSL</th><td><code>' . esc_html(extension_loaded('openssl') ? 'OK' : 'Missing') . '</code></td></tr>';
    echo '<tr><th>' . esc_html__('افزونه مستقل Web Push', 'hamnoor') . '</th><td><code>Installed</code></td></tr>';
    echo '<tr><th>' . esc_html__('Service Worker', 'hamnoor') . '</th><td><code style="word-break:break-all">' . esc_html(isset($admin_data['service_worker_url']) ? (string)$admin_data['service_worker_url'] : Hamnoor_Web_Push::service_worker_url()) . '</code></td></tr>';
    echo '<tr><th>' . esc_html__('اشتراک‌های فعال مرورگر', 'hamnoor') . '</th><td>' . $count . '</td></tr>';
    echo '<tr><th>' . esc_html__('ارسال موفق ثبت‌شده', 'hamnoor') . '</th><td>' . (int)($diagnostics['ever_succeeded'] ?? 0) . '</td></tr>';
    echo '<tr><th>' . esc_html__('اشتراک بدون ارسال موفق', 'hamnoor') . '</th><td>' . (int)($diagnostics['never_succeeded'] ?? 0) . '</td></tr>';
    echo '<tr><th>' . esc_html__('خطای احراز Push (401/403)', 'hamnoor') . '</th><td><strong>' . (int)($diagnostics['http_401_403'] ?? 0) . '</strong></td></tr>';
    if (!empty($diagnostics['latest_error_code'])) {
        echo '<tr><th>' . esc_html__('آخرین خطای اشتراک', 'hamnoor') . '</th><td><code>' . esc_html((string)$diagnostics['latest_error_code']) . '</code> — <code>' . esc_html((string)($diagnostics['latest_error_at'] ?? '')) . '</code></td></tr>';
    }
    if (!empty($diagnostics['hosts'])) {
        echo '<tr><th>' . esc_html__('سرویس‌های Push مرورگر', 'hamnoor') . '</th><td><code>' . esc_html(implode(', ', (array)$diagnostics['hosts'])) . '</code></td></tr>';
    }
    echo '<tr><th>' . esc_html__('کلید VAPID', 'hamnoor') . '</th><td><code>' . esc_html(!empty($admin_data['public_key_hint']) ? (string)$admin_data['public_key_hint'] : __('ایجاد نشده', 'hamnoor')) . '</code></td></tr>';
    echo '</tbody></table>';
    if (!empty($diagnostics['http_401_403'])) {
        echo '<div class="notice notice-warning inline" style="margin:16px 0 0"><p><strong>' . esc_html__('راهنمای رفع 403:', 'hamnoor') . '</strong> ' . esc_html__('این خطا معمولاً وقتی رخ می‌دهد که اشتراک مرورگر با کلید VAPID فعلی هم‌نور هماهنگ نباشد. در همان مرورگر یا دستگاه، وارد «یادآوری‌ها» شوید و Web Push را دوباره فعال کنید.', 'hamnoor') . '</p></div>';
    }
}

function hamnoor_web_push_core_activate() {
    if (!interface_exists('Hamnoor_Notification_Channel_Interface')) { throw new RuntimeException('Hamnoor notification channel contract is unavailable.'); }
    require_once HAMNOOR_WEB_PUSH_PATH . 'includes/class-hamnoor-web-push.php';
    Hamnoor_Web_Push::instance()->register_rewrite_rule();
    $keys = Hamnoor_Web_Push::ensure_vapid_keys();
    if (is_wp_error($keys)) { throw new RuntimeException($keys->get_error_message()); }
}
add_action('hamnoor_activate_addons', 'hamnoor_web_push_core_activate');

function hamnoor_web_push_deactivate() {
    flush_rewrite_rules(false);
}
register_deactivation_hook(__FILE__, 'hamnoor_web_push_deactivate');
