<?php
/**
 * Plugin Name: Manahel Rewards
 * Description: هسته مستقل و قابل حسابرسی امتیازدهی مناهل با Rule Engine، Ledger تغییرناپذیر و اتصال رویدادی به هم‌نور.
 * Version: 0.8.11
 * Author: Manahel
 * Text Domain: manahel-rewards
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('MANAHEL_REWARDS_VERSION', '0.8.11');
define('MANAHEL_REWARDS_DB_VERSION', '1.3.0');
define('MANAHEL_REWARDS_FILE', __FILE__);
define('MANAHEL_REWARDS_DIR', plugin_dir_path(__FILE__));
define('MANAHEL_REWARDS_URL', plugin_dir_url(__FILE__));
define('MANAHEL_REWARDS_CAP', 'manage_manahel_rewards');

require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-db.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-installer.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-normalizer.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-account-service.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-identity-gate.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-rule-engine.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-limit-evaluator.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-user-summary-service.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-ledger-service.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-event-processor.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-hamnoor-adapter.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-referral-adapter.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-referral-ux-integration.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-hamnoor-dashboard-integration.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-hamnoor-feedback-integration.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-notification-bridge.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-redemption-service.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-code-inventory.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-redemption-frontend.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-redemption-notification-bridge.php';
require_once MANAHEL_REWARDS_DIR . 'admin/class-manahel-rewards-admin.php';
require_once MANAHEL_REWARDS_DIR . 'frontend/class-manahel-rewards-frontend.php';
require_once MANAHEL_REWARDS_DIR . 'includes/class-manahel-rewards-plugin.php';



/**
 * Read-only integration API. Never creates a Reward Account.
 */
function manahel_rewards_get_user_summary($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : get_current_user_id();
    return Manahel_Rewards_User_Summary_Service::get_for_wp_user($wp_user_id);
}

function manahel_rewards_get_current_user_balance() {
    $wp_user_id = Manahel_Rewards_Frontend::viewer_wp_user_id();
    if ($wp_user_id <= 0) { return 0; }
    $summary = manahel_rewards_get_user_summary($wp_user_id);
    return is_wp_error($summary) ? 0 : (int)$summary['balance'];
}

function manahel_rewards_get_compact_user_summary($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Rewards_Frontend::viewer_wp_user_id();
    return Manahel_Rewards_User_Summary_Service::get_compact_for_wp_user($wp_user_id);
}

function manahel_rewards_get_my_points_url() {
    return Manahel_Rewards_Frontend::my_points_url();
}


/**
 * Read-only reward catalog for integrations. No account is created.
 */
function manahel_rewards_get_catalog($wp_user_id = 0) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Rewards_Frontend::viewer_wp_user_id();
    return Manahel_Rewards_Redemption_Service::catalog_for_user($wp_user_id);
}

function manahel_rewards_get_my_redemptions($wp_user_id = 0, $limit = 20) {
    $wp_user_id = $wp_user_id ? (int)$wp_user_id : Manahel_Rewards_Frontend::viewer_wp_user_id();
    return Manahel_Rewards_Redemption_Service::user_redemptions($wp_user_id, $limit);
}

register_activation_hook(__FILE__, array('Manahel_Rewards_Installer', 'activate'));

add_action('plugins_loaded', static function () {
    Manahel_Rewards_Plugin::instance()->init();
}, 30);
