<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Plugin {
    private static $instance = null;
    private $initialized = false;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) { return; }
        $this->initialized = true;
        Manahel_Referral_Installer::maybe_upgrade();
        add_action('init', array('Manahel_Referral_Attribution_Service', 'capture_first_touch'), 1);
        add_action('init', array('Manahel_Referral_Hamnoor_Adapter', 'reconcile_current_identity'), 5);
        add_action('hamnoor_core_ready', array('Manahel_Referral_Hamnoor_Adapter', 'on_core_ready'), 10, 0);
        Manahel_Referral_Hamnoor_Adapter::init();
        Manahel_Referral_Hamnoor_Dashboard_Integration::init();
        Manahel_Referral_Frontend::init();
        if (is_admin()) { Manahel_Referral_Admin::init(); }
    }
}
