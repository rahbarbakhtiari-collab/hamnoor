<?php
/**
 * Plugin Name: Hamnoor | هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: افزونه مدیریت پویش ختم قرآن با لینک اختصاصی، انتخاب سهم، پیگیری مشارکت، خروجی گزارش و ساختار آماده توسعه.
 * Version: 4.0.19
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAMNOOR_VERSION', '4.0.19');
define('HAMNOOR_ARCHITECTURE_VERSION', '3.5.0');
define('HAMNOOR_CORE_API_VERSION', '2.1.0');
define('HAMNOOR_DB_VERSION', '4.6.0');
define('HAMNOOR_FILE', __FILE__);
define('HAMNOOR_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_URL', plugin_dir_url(__FILE__));
define('HAMNOOR_BASENAME', plugin_basename(__FILE__));

require_once HAMNOOR_PATH . 'includes/class-hamnoor-helpers.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-texts.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-validator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-jalali.php';

// Foundation 3.x domain contracts are loaded before persistence so schema
// migrations and repositories share one canonical vocabulary.
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-campaign-domain.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-campaign-state-machine.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-schema-manifest.php';
require_once HAMNOOR_PATH . 'includes/core/contracts/interface-hamnoor-participation-strategy.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-participation-strategy-registry.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-content-repository.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-mafatih-importer.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-dua-id3-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-dua-audio-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-campaign-repository.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-personal-quran-progress-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-reminder-schedule-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-campaign-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-command-contracts.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-contribution-campaign-edit-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-contribution-service.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-access-policy.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-wp-user-bridge.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-claim-policy.php';
require_once HAMNOOR_PATH . 'includes/core/migrations/class-hamnoor-migrations.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-db.php';
require_once HAMNOOR_PATH . 'includes/core/domain/class-hamnoor-retention-service.php';

// Hamnoor 4.x modular architecture kernel. These files define stable
// contracts and loading boundaries; channel implementations are installed as
// separate first-party add-ons.
require_once HAMNOOR_PATH . 'includes/core/interface-hamnoor-addon.php';
require_once HAMNOOR_PATH . 'includes/core/contracts/interface-hamnoor-notification-provider.php';
require_once HAMNOOR_PATH . 'includes/core/contracts/interface-hamnoor-notification-channel.php';
require_once HAMNOOR_PATH . 'includes/core/contracts/interface-hamnoor-notification-engine.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-notification-channel-registry.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-service-container.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-domain-event-store.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-event-bus.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-domain-events.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-addon-manager.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-core-api.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-notifications.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-builtin-addon-loader.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-legacy-event-bridge.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-core-health.php';
require_once HAMNOOR_PATH . 'includes/core/class-hamnoor-core.php';
require_once HAMNOOR_PATH . 'includes/core/functions-hamnoor-core.php';

require_once HAMNOOR_PATH . 'includes/class-hamnoor-featured-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-khatm-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-part-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-participant-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-khatm-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-part-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-participant-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-rest-api.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-rest-api-v2.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-addons.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-mobile-access.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-permissions.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-i18n.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-export.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-seo.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-router.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-reading.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-personal-quran.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-personal-reminders.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-engagement.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-frontend.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-contribution-frontend.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-participant-contribution-history.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-contribution-router-adapter.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-creator-content-adapter.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-contribution-owner-edit-adapter.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-content-admin.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-admin-contribution-review-adapter.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-admin.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-activator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-deactivator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-plugin.php';

register_activation_hook(__FILE__, array('Hamnoor_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('Hamnoor_Deactivator', 'deactivate'));

function hamnoor_record_runtime_error($error, $context = 'boot') {
    if (!($error instanceof Throwable)) {
        return;
    }
    $context = sanitize_key((string) $context);
    $message = sprintf(
        'Hamnoor %s error: %s in %s:%d',
        $context ?: 'runtime',
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    );
    error_log($message);
    if (function_exists('update_option')) {
        update_option('hamnoor_last_boot_error', $message, false);
    }
}

function hamnoor_boot_plugin() {
    try {
        Hamnoor_Plugin::instance()->init();
        Hamnoor_Contribution_Frontend::init();
        Hamnoor_Participant_Contribution_History::init();
        Hamnoor_Contribution_Router_Adapter::init();
        Hamnoor_Content_Admin::init();
    } catch (Throwable $error) {
        hamnoor_record_runtime_error($error, 'boot');
    }
}

function hamnoor_boot_error_notice() {
    if (!current_user_can('manage_options')) { return; }
    $message = (string) get_option('hamnoor_last_boot_error', '');
    if (!$message) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('هم‌نور با خطای فنی متوقف شد، اما از ایجاد خطای سراسری سایت جلوگیری شد.', 'hamnoor') . '</strong></p><p><code>' . esc_html($message) . '</code></p></div>';
}

add_action('admin_notices', 'hamnoor_boot_error_notice');
add_action('plugins_loaded', 'hamnoor_boot_plugin');
