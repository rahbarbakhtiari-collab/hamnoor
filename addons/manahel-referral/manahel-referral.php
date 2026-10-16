<?php
/**
 * Plugin Name: Manahel Referral
 * Description: زیرساخت مستقل دعوت، انتساب و جذب مشارکت‌کننده برای اکوسیستم مناهل.
 * Version: 0.3.2
 * Author: Manahel
 * Text Domain: manahel-referral
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('MANAHEL_REFERRAL_VERSION', '0.3.2');
define('MANAHEL_REFERRAL_DB_VERSION', '1.0.0');
define('MANAHEL_REFERRAL_FILE', __FILE__);
define('MANAHEL_REFERRAL_DIR', plugin_dir_path(__FILE__));
define('MANAHEL_REFERRAL_URL', plugin_dir_url(__FILE__));
define('MANAHEL_REFERRAL_CAP', 'manage_manahel_referrals');

require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-db.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-installer.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-code-service.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-event-store.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-attribution-service.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-user-summary-service.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-hamnoor-adapter.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-hamnoor-dashboard-integration.php';
require_once MANAHEL_REFERRAL_DIR . 'frontend/class-manahel-referral-frontend.php';
require_once MANAHEL_REFERRAL_DIR . 'admin/class-manahel-referral-admin.php';
require_once MANAHEL_REFERRAL_DIR . 'includes/class-manahel-referral-plugin.php';

function manahel_referral_get_invite_url($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Referral_Frontend::viewer_wp_user_id();
    return Manahel_Referral_Code_Service::invite_url($wp_user_id);
}


function manahel_referral_get_user_summary($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Referral_Frontend::viewer_wp_user_id();
    return Manahel_Referral_User_Summary_Service::get_for_wp_user($wp_user_id);
}

function manahel_referral_get_compact_user_summary($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Referral_Frontend::viewer_wp_user_id();
    return Manahel_Referral_User_Summary_Service::get_compact_for_wp_user($wp_user_id);
}

function manahel_referral_get_my_referrals_url() {
    return Manahel_Referral_Frontend::my_referrals_url();
}

function manahel_referral_get_qualified_events_after($after_id = 0, $limit = 50) {
    return Manahel_Referral_Event_Store::get_after((int)$after_id, (int)$limit, 'referral.qualified');
}

function manahel_referral_get_campaign_participant_events_after($after_id = 0, $limit = 50) {
    return Manahel_Referral_Event_Store::get_after((int)$after_id, (int)$limit, 'referral.campaign_participant');
}

register_activation_hook(__FILE__, array('Manahel_Referral_Installer', 'activate'));

add_action('plugins_loaded', static function () {
    Manahel_Referral_Plugin::instance()->init();
}, 25);
