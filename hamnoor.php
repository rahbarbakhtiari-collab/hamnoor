<?php
/**
 * Plugin Name: Hamnoor | هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: افزونه مدیریت ختم جمعی قرآن با لینک اختصاصی، انتخاب سهم، پیگیری مشارکت، خروجی گزارش و ساختار آماده توسعه.
 * Version: 2.8.0
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

define('HAMNOOR_VERSION', '2.8.0');
define('HAMNOOR_DB_VERSION', '2.7.3');
define('HAMNOOR_FILE', __FILE__);
define('HAMNOOR_PATH', plugin_dir_path(__FILE__));
define('HAMNOOR_URL', plugin_dir_url(__FILE__));
define('HAMNOOR_BASENAME', plugin_basename(__FILE__));

require_once HAMNOOR_PATH . 'includes/class-hamnoor-helpers.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-texts.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-validator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-jalali.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-db.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-featured-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-khatm-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-part-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-participant-repository.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-khatm-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-part-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-participant-service.php';
require_once HAMNOOR_PATH . 'includes/interface-hamnoor-notification-provider.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-notification-service.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-rest-api.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-addons.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-permissions.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-i18n.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-export.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-seo.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-router.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-frontend.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-admin.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-activator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-deactivator.php';
require_once HAMNOOR_PATH . 'includes/class-hamnoor-plugin.php';

register_activation_hook(__FILE__, array('Hamnoor_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('Hamnoor_Deactivator', 'deactivate'));

add_action('plugins_loaded', function () {
    Hamnoor_Plugin::instance()->init();
});
