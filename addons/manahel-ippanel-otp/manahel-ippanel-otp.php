<?php
/**
 * Plugin Name: ورود پیامکی مناهل با IPPanel
 * Plugin URI:  https://e.manahel.ir/
 * Description: ورود و ثبت‌نام یکپارچه وردپرس و LearnDash با کد یکبار مصرف پیامکی از طریق IPPanel Edge.
 * Version:     1.1.0
 * Author:      Manahel
 * Text Domain: manahel-ippanel-otp
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MIO_VERSION', '1.1.0' );
define( 'MIO_FILE', __FILE__ );
define( 'MIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIO_URL', plugin_dir_url( __FILE__ ) );

require_once MIO_DIR . 'includes/class-mio-utils.php';
require_once MIO_DIR . 'includes/class-mio-settings.php';
require_once MIO_DIR . 'includes/class-mio-ippanel.php';
require_once MIO_DIR . 'includes/class-mio-users.php';
require_once MIO_DIR . 'includes/class-mio-otp.php';
require_once MIO_DIR . 'includes/class-mio-auth.php';
require_once MIO_DIR . 'includes/class-mio-frontend.php';
require_once MIO_DIR . 'includes/class-mio-plugin.php';

register_activation_hook( __FILE__, array( 'MIO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MIO_Plugin', 'deactivate' ) );

MIO_Plugin::instance()->run();
