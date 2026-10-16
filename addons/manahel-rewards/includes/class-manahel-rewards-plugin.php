<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Plugin {
    private static $instance = null;
    private $initialized = false;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) { return; }
        $this->initialized = true;

        Manahel_Rewards_Installer::maybe_upgrade();
        Manahel_Rewards_Hamnoor_Adapter::init();
        Manahel_Rewards_Referral_Adapter::init();
        Manahel_Rewards_Referral_UX_Integration::init();
        Manahel_Rewards_Hamnoor_Dashboard_Integration::init();
        Manahel_Rewards_Hamnoor_Feedback_Integration::init();
        Manahel_Rewards_Notification_Bridge::init();
        Manahel_Rewards_Redemption_Frontend::init();
        Manahel_Rewards_Redemption_Notification_Bridge::init();
        Manahel_Rewards_Frontend::init();

        if (is_admin()) {
            Manahel_Rewards_Admin::init();
        }

        add_action('deleted_user', array($this, 'on_deleted_user'));
    }

    public function on_deleted_user($wp_user_id) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('accounts');
        $wpdb->update(
            $table,
            array('wp_user_id' => null, 'status' => 'orphaned', 'updated_at' => Manahel_Rewards_DB::utc_now_mysql()),
            array('wp_user_id' => (int)$wp_user_id),
            array('%d', '%s', '%s'),
            array('%d')
        );
    }
}
