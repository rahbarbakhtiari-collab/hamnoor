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
        // WordPress 6.7+ warns when a plugin text domain is touched before
        // the init lifecycle. Register translation loading for init while
        // keeping Hamnoor's hook wiring on plugins_loaded.
        add_action('init', array('Hamnoor_I18n', 'init'), 0);
        Hamnoor_Core::instance()->init();

        // Core domain path: keep exactly the established behavior and failure
        // semantics for the pieces without which Hamnoor cannot function.
        Hamnoor_Router::instance()->init();
        Hamnoor_Core::instance()->mark_component('router');
        Hamnoor_Participation_Strategy_Registry::boot_defaults();
        Hamnoor_WP_User_Bridge::init();
        Hamnoor_Mobile_Access::init();
        Hamnoor_Core::instance()->mark_component('mobile_access');
        Hamnoor_Reading::init();
        Hamnoor_Core::instance()->mark_component('reading');
        Hamnoor_Personal_Quran::init();
        Hamnoor_Core::instance()->mark_component('personal_quran');
        Hamnoor_Personal_Reminders::init();
        Hamnoor_Core::instance()->mark_component('personal_reminders');

        // Boot manifest-driven add-ons before channel adapters. Notification
        // Engine registers the stable `notifications` service here; adapters
        // then attach themselves without Core knowing their implementation.
        Hamnoor_Addon_Manager::instance()->discover();
        Hamnoor_Addon_Manager::instance()->boot_all();

        // Channel adapters are physically extracted and register through the
        // add-on manager. Core keeps only shared engagement behavior here.
        Hamnoor_Core::instance()->boot_optional_component('engagement', array('Hamnoor_Engagement', 'init'));

        Hamnoor_Frontend::instance()->init();
        Hamnoor_Core::instance()->mark_component('frontend');
        Hamnoor_Core::instance()->boot_optional_component('admin', array(Hamnoor_Admin::instance(), 'init'));
        Hamnoor_Core::instance()->boot_optional_component('seo', array(Hamnoor_SEO::instance(), 'init'));
        Hamnoor_Core::instance()->boot_optional_component('addons', array('Hamnoor_Addons', 'init'));
        Hamnoor_Core::instance()->boot_optional_component('rest_api', array('Hamnoor_REST_API', 'init'));
        Hamnoor_Core::instance()->boot_optional_component('rest_api_v2', array('Hamnoor_REST_API_V2', 'init'));

        // WordPress functionality constants (including WP_POST_REVISIONS) are
        // defined immediately after plugins_loaded. Database migrations may
        // create or update pages, so they must run on init, not during
        // plugins_loaded.
        add_action('init', array($this, 'run_deferred_upgrade'), 1);

        add_filter('plugin_action_links_' . HAMNOOR_BASENAME, array($this, 'action_links'));
        add_filter('show_admin_bar', array($this, 'maybe_hide_admin_bar'));
        add_action('hamnoor_daily_cleanup', array('Hamnoor_DB', 'cleanup_expired_records'));
        add_action('hamnoor_auto_release_parts', array('Hamnoor_DB', 'release_due_parts'));
        add_action('wp', array($this, 'maybe_release_due_parts'));
        add_action('admin_init', array($this, 'maybe_release_due_parts'));
        if (!wp_next_scheduled('hamnoor_daily_cleanup')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'hamnoor_daily_cleanup'); }
        if (!wp_next_scheduled('hamnoor_auto_release_parts')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'hamnoor_auto_release_parts'); }
    }


    public function run_deferred_upgrade() {
        $from_version = (string) get_option('hamnoor_plugin_version', '');
        try {
            $this->prepare_safe_upgrade_snapshot();
            Hamnoor_DB::maybe_upgrade();
            Hamnoor_DB::assert_tables_health('deferred-upgrade-final');
            $this->restore_protected_upgrade_settings();

            update_option('hamnoor_plugin_version', HAMNOOR_VERSION, false);
            if ((string) get_option('hamnoor_plugin_version', '') !== (string) HAMNOOR_VERSION) {
                throw new RuntimeException('Hamnoor plugin version marker could not be persisted.');
            }

            update_option('hamnoor_upgrade_status', array(
                'status' => 'ok',
                'from_version' => $from_version,
                'to_version' => HAMNOOR_VERSION,
                'db_version' => HAMNOOR_DB_VERSION,
                'at' => current_time('mysql'),
            ), false);
            delete_option('hamnoor_last_boot_error');
        } catch (Throwable $error) {
            update_option('hamnoor_upgrade_status', array(
                'status' => 'failed',
                'from_version' => $from_version,
                'to_version' => defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '',
                'db_version' => defined('HAMNOOR_DB_VERSION') ? HAMNOOR_DB_VERSION : '',
                'at' => current_time('mysql'),
                'error' => substr($error->getMessage(), 0, 500),
            ), false);
            if (function_exists('hamnoor_record_runtime_error')) {
                hamnoor_record_runtime_error($error, 'upgrade');
            } else {
                error_log(sprintf('Hamnoor upgrade error: %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine()));
            }
        }
    }


    /**
     * Before a code-version change, keep a compact option snapshot. WordPress
     * normally preserves options during "Replace current with uploaded"; this
     * is an additional guard against accidental migration regressions.
     */
    private function prepare_safe_upgrade_snapshot() {
        $from = (string)get_option('hamnoor_plugin_version', '');
        if ($from === HAMNOOR_VERSION) { return; }
        $names = array(
            'hamnoor_settings',
            'hamnoor_eitaa_settings',
            'hamnoor_bale_settings',
            'hamnoor_rubika_settings',
            'hamnoor_webpush_vapid',
            'hamnoor_pages',
        );
        $options = array();
        foreach ($names as $name) {
            $value = get_option($name, null);
            if (null !== $value) { $options[$name] = $value; }
        }
        update_option('hamnoor_upgrade_backup', array(
            'from_version'=>$from,
            'to_version'=>HAMNOOR_VERSION,
            'created_at'=>current_time('mysql'),
            'options'=>$options,
        ), false);
    }

    /** Restore only missing protected values; never overwrite valid new data. */
    private function restore_protected_upgrade_settings() {
        $backup = get_option('hamnoor_upgrade_backup', array());
        if (!is_array($backup) || empty($backup['options']) || !is_array($backup['options'])) { return; }
        $options = $backup['options'];
        foreach (array('hamnoor_settings','hamnoor_webpush_vapid','hamnoor_pages') as $name) {
            if (array_key_exists($name, $options) && null === get_option($name, null)) {
                update_option($name, $options[$name], false);
            }
        }
        $protected = array(
            'hamnoor_eitaa_settings'=>array('token_enc','public_token_enc','program_token_enc'),
            'hamnoor_bale_settings'=>array('token_enc'),
            'hamnoor_rubika_settings'=>array('token_enc'),
        );
        foreach ($protected as $name=>$keys) {
            if (empty($options[$name]) || !is_array($options[$name])) { continue; }
            $old = $options[$name];
            $current = get_option($name, array());
            $current = is_array($current) ? $current : array();
            $changed = false;
            foreach ($keys as $key) {
                if (empty($current[$key]) && !empty($old[$key])) {
                    $current[$key] = $old[$key];
                    $changed = true;
                }
            }
            if (!$current && $old) { $current = $old; $changed = true; }
            if ($changed) { update_option($name, $current, false); }
        }
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

    public function maybe_hide_admin_bar($show) {
        if (is_admin()) {
            return $show;
        }

        // Hamnoor frontend pages are application views. Keep the WordPress
        // toolbar out of the app shell for every visitor, including admins.
        if (Hamnoor_Router::instance()->is_hamnoor_request() || Hamnoor_Frontend::instance()->is_shortcode_context()) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return $show;
        }

        $settings = Hamnoor_Helpers::settings();
        if (empty($settings['hide_admin_bar']) || '1' !== $settings['hide_admin_bar']) {
            return $show;
        }
        return $show;
    }
}
