<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Plugin {
    private static $instance = null;
    private $initialized = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        Hamnoor_I18n::init();
        add_action('init', array('Hamnoor_DB', 'maybe_upgrade'), 1);
        Hamnoor_Router::instance()->init();
        Hamnoor_Frontend::instance()->init();
        Hamnoor_Admin::instance()->init();
        Hamnoor_SEO::instance()->init();
        Hamnoor_Addons::init();
        Hamnoor_REST_API::init();

        add_filter('plugin_action_links_' . HAMNOOR_BASENAME, array($this, 'action_links'));
        add_action('wp_login', array($this, 'sync_on_login'), 10, 2);
        add_action('user_register', array($this, 'sync_on_register'), 30, 1);
        add_filter('show_admin_bar', array($this, 'maybe_hide_admin_bar'));
        add_action('hamnoor_daily_cleanup', array('Hamnoor_DB', 'cleanup_expired_records'));
        add_action('hamnoor_auto_release_parts', array('Hamnoor_DB', 'release_due_parts'));
        add_action('wp', array($this, 'maybe_release_due_parts'));
        add_action('admin_init', array($this, 'maybe_release_due_parts'));
        if (!wp_next_scheduled('hamnoor_daily_cleanup')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'hamnoor_daily_cleanup'); }
        if (!wp_next_scheduled('hamnoor_auto_release_parts')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'hamnoor_auto_release_parts'); }
    }


    public function maybe_release_due_parts() {
        if (get_transient('hamnoor_last_release_check')) {
            return;
        }
        set_transient('hamnoor_last_release_check', '1', 15 * MINUTE_IN_SECONDS);
        Hamnoor_DB::release_due_parts();
    }

    public function action_links($links) {
        $url = admin_url('admin.php?page=hamnoor-settings');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('تنظیمات', 'hamnoor') . '</a>');
        return $links;
    }

    public function sync_on_login($user_login, $user) {
        if ($user instanceof WP_User) {
            Hamnoor_DB::sync_guest_history_to_user((int) $user->ID);
        }
    }

    public function sync_on_register($user_id) {
        Hamnoor_DB::sync_guest_history_to_user((int) $user_id);
    }

    public function maybe_hide_admin_bar($show) {
        if (is_admin() || current_user_can('manage_options')) {
            return $show;
        }
        $settings = Hamnoor_Helpers::settings();
        if (empty($settings['hide_admin_bar']) || '1' !== $settings['hide_admin_bar']) {
            return $show;
        }
        if (Hamnoor_Router::instance()->is_hamnoor_request() || Hamnoor_Frontend::instance()->is_shortcode_context()) {
            return false;
        }
        return $show;
    }
}
