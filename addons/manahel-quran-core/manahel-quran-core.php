<?php
/**
 * Plugin Name: Manahel Quran Core
 * Plugin URI: https://www.manahel.ir
 * Description: هسته داخلی و مشترک داده‌های قرآن مناهل؛ شامل متن عربی، ترجمه آیت‌الله مکارم، ساختار سوره‌ها و تقسیم‌بندی‌های قرآن و API داخلی.
 * Version: 0.1.0
 * Author: Manahel Al-Huda
 * Text Domain: manahel-quran-core
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('MQC_VERSION', '0.1.0');
define('MQC_DB_VERSION', '1');
define('MQC_FILE', __FILE__);
define('MQC_DIR', plugin_dir_path(__FILE__));
define('MQC_URL', plugin_dir_url(__FILE__));

require_once MQC_DIR . 'includes/class-mqc-installer.php';
require_once MQC_DIR . 'includes/class-mqc-repository.php';
require_once MQC_DIR . 'includes/class-mqc-service.php';
require_once MQC_DIR . 'includes/class-mqc-rest.php';
require_once MQC_DIR . 'admin/class-mqc-admin.php';

register_activation_hook(__FILE__, array('MQC_Installer', 'activate'));

add_action('plugins_loaded', static function () {
    MQC_REST::register();
    if (is_admin()) {
        MQC_Admin::register();
    }
});

/**
 * دسترسی ساده برای افزونه‌های دیگر.
 * نمونه: manahel_quran_core()->get_verse(2, 255)
 */
function manahel_quran_core() {
    return MQC_Service::instance();
}
