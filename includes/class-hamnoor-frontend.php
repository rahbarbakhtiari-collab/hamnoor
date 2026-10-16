<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Frontend {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_shortcode('hamnoor_khatm_list', array($this, 'shortcode_list'));
        add_shortcode('hamnoor_dashboard', array($this, 'shortcode_dashboard'));
        add_shortcode('hamnoor_create_khatm', array($this, 'shortcode_create_khatm'));
        add_shortcode('hamnoor', array($this, 'shortcode_khatm'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_hamnoor_create_khatm', array($this, 'handle_create_khatm'));
        add_action('admin_post_nopriv_hamnoor_create_khatm', array($this, 'handle_create_khatm'));
        add_action('admin_post_hamnoor_update_khatm', array($this, 'handle_update_khatm'));
        add_action('admin_post_nopriv_hamnoor_update_khatm', array($this, 'handle_update_khatm'));
        add_action('admin_post_hamnoor_owner_khatm_status', array($this, 'handle_owner_khatm_status'));
        add_action('admin_post_nopriv_hamnoor_owner_khatm_status', array($this, 'handle_owner_khatm_status'));
        add_action('admin_post_hamnoor_thank_participants', array($this, 'handle_thank_participants'));
        add_action('admin_post_nopriv_hamnoor_thank_participants', array($this, 'handle_thank_participants'));
        add_action('admin_post_hamnoor_owner_participant_action', array($this, 'handle_owner_participant_action'));
        add_action('admin_post_nopriv_hamnoor_owner_participant_action', array($this, 'handle_owner_participant_action'));
        add_action('admin_post_hamnoor_claim_part', array($this, 'handle_claim_part'));
        add_action('admin_post_nopriv_hamnoor_claim_part', array($this, 'handle_claim_part'));
        add_action('admin_post_hamnoor_complete_part', array($this, 'handle_complete_part'));
        add_action('admin_post_nopriv_hamnoor_complete_part', array($this, 'handle_complete_part'));
        add_action('admin_post_hamnoor_complete_contact_part', array($this, 'handle_complete_contact_part'));
        add_action('admin_post_nopriv_hamnoor_complete_contact_part', array($this, 'handle_complete_contact_part'));
        add_action('admin_post_hamnoor_update_profile', array($this, 'handle_update_profile'));
        add_action('admin_post_nopriv_hamnoor_update_profile', array($this, 'handle_update_profile'));
        add_action('admin_post_hamnoor_request_feature', array($this, 'handle_feature_request'));
        add_action('admin_post_nopriv_hamnoor_request_feature', array($this, 'handle_feature_request'));

        add_action('wp_ajax_hamnoor_claim_identity', array($this, 'ajax_claim_identity'));
        add_action('wp_ajax_nopriv_hamnoor_claim_identity', array($this, 'ajax_claim_identity'));
        add_action('wp_ajax_hamnoor_claim_part', array($this, 'ajax_claim_part'));
        add_action('wp_ajax_nopriv_hamnoor_claim_part', array($this, 'ajax_claim_part'));
        add_action('wp_ajax_hamnoor_complete_part', array($this, 'ajax_complete_part'));
        add_action('wp_ajax_nopriv_hamnoor_complete_part', array($this, 'ajax_complete_part'));
        add_action('wp_ajax_hamnoor_release_part', array($this, 'ajax_release_part'));
        add_action('wp_ajax_nopriv_hamnoor_release_part', array($this, 'ajax_release_part'));
        add_action('wp_ajax_hamnoor_update_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_nopriv_hamnoor_update_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_hamnoor_validate_create_step', array($this, 'ajax_validate_create_step'));
        add_action('wp_ajax_nopriv_hamnoor_validate_create_step', array($this, 'ajax_validate_create_step'));
        add_action('wp_ajax_hamnoor_save_khatm_draft', array($this, 'ajax_save_khatm_draft'));
        add_action('wp_ajax_nopriv_hamnoor_save_khatm_draft', array($this, 'ajax_save_khatm_draft'));
        add_action('wp_ajax_hamnoor_get_khatm_draft', array($this, 'ajax_get_khatm_draft'));
        add_action('wp_ajax_nopriv_hamnoor_get_khatm_draft', array($this, 'ajax_get_khatm_draft'));
        add_action('wp_ajax_hamnoor_delete_khatm_draft', array($this, 'ajax_delete_khatm_draft'));
        add_action('wp_ajax_nopriv_hamnoor_delete_khatm_draft', array($this, 'ajax_delete_khatm_draft'));
        add_action('wp_ajax_hamnoor_mark_notifications_read', array($this, 'ajax_mark_notifications_read'));
        add_action('wp_ajax_nopriv_hamnoor_mark_notifications_read', array($this, 'ajax_mark_notifications_read'));
    }

    public function is_shortcode_context() {
        if (!is_singular()) { return false; }
        global $post;
        if (!$post) { return false; }
        foreach (array('hamnoor_khatm_list','hamnoor_dashboard','hamnoor_create_khatm','hamnoor') as $tag) {
            if (has_shortcode($post->post_content, $tag)) { return true; }
        }
        return false;
    }

    /**
     * Keep standalone public khatm pages independent from theme layout CSS while
     * still reusing a local Persian UI font when the active theme ships one.
     * No font is bundled or copied by Hamnoor; the generated @font-face points
     * at the existing theme asset.
     */
    private function standalone_font_css() {
        $settings = Hamnoor_Helpers::settings();
        $configured = isset($settings['font_family']) ? trim((string) $settings['font_family']) : '';
        $configured = preg_replace('/[^a-zA-Z0-9_\-\s,\"\']/', '', $configured);
        $configured_stack = ($configured && 'inherit' !== strtolower($configured)) ? $configured . ',' : '';
        $fallback = '"IRANSansXFaNum","IRANSansX","Vazirmatn","Vazir",Tahoma,Arial,sans-serif';
        $css = 'body.hamnoor-standalone-page{--hamnoor-standalone-font:' . $configured_stack . $fallback . ';}';

        $roots = array();
        $stylesheet_dir = get_stylesheet_directory();
        $stylesheet_uri = get_stylesheet_directory_uri();
        $template_dir = get_template_directory();
        $template_uri = get_template_directory_uri();
        if ($stylesheet_dir && $stylesheet_uri) { $roots[] = array($stylesheet_dir, $stylesheet_uri); }
        if ($template_dir && $template_uri && $template_dir !== $stylesheet_dir) { $roots[] = array($template_dir, $template_uri); }

        $subdirs = array('/assets/fonts', '/assets/font', '/fonts', '/assets/webfonts', '/webfonts');
        $patterns = array(
            '*IRANSansXFaNum*.woff2',
            '*IRANSansX*FaNum*.woff2',
            '*IRANSansX*.woff2',
            '*Vazirmatn*.woff2',
            '*Vazir*.woff2',
        );
        $found = '';
        $found_url = '';
        foreach ($roots as $root) {
            list($base_dir, $base_uri) = $root;
            foreach ($subdirs as $subdir) {
                $dir = rtrim($base_dir, '/\\') . $subdir;
                if (!is_dir($dir)) { continue; }
                foreach ($patterns as $pattern) {
                    $matches = glob($dir . '/' . $pattern, GLOB_NOSORT);
                    if (!$matches) { continue; }
                    usort($matches, function($a, $b) {
                        $av = preg_match('/(?:variable|\bvf\b)/i', basename($a)) ? 0 : 1;
                        $bv = preg_match('/(?:variable|\bvf\b)/i', basename($b)) ? 0 : 1;
                        return $av <=> $bv;
                    });
                    $found = $matches[0];
                    $relative = ltrim(str_replace('\\', '/', substr($found, strlen(rtrim($base_dir, '/\\')))), '/');
                    $found_url = trailingslashit($base_uri) . $relative;
                    break 3;
                }
            }
        }
        if (!$found || !$found_url) { return $css; }

        $is_variable = (bool) preg_match('/(?:variable|\bvf\b)/i', basename($found));
        $weight = $is_variable ? '100 900' : '400';
        $css = '@font-face{font-family:"HamnoorLocalUI";src:url("' . esc_url_raw($found_url) . '") format("woff2");font-style:normal;font-weight:' . $weight . ';font-display:swap;}'
             . 'body.hamnoor-standalone-page{--hamnoor-standalone-font:"HamnoorLocalUI",' . $configured_stack . $fallback . ';}';
        return $css;
    }

    public function enqueue_assets() {
        if (!$this->is_shortcode_context() && !Hamnoor_Router::instance()->is_hamnoor_request()) { return; }
        wp_enqueue_style('hamnoor-frontend', HAMNOOR_URL . 'assets/css/hamnoor-frontend.css', array(), Hamnoor_Helpers::asset_version('assets/css/hamnoor-frontend.css'));
        wp_enqueue_style('hamnoor-desktop', HAMNOOR_URL . 'assets/css/hamnoor-desktop.css', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-desktop.css'), '(min-width: 783px)');
        wp_add_inline_style('hamnoor-frontend', Hamnoor_Helpers::dynamic_css());
        // Mobile appearance is intentionally isolated from desktop. Same data and actions, separate presentation layer.
        wp_enqueue_style('hamnoor-mobile', HAMNOOR_URL . 'assets/css/hamnoor-mobile.css', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-mobile.css'), '(max-width: 782px)');
        if (Hamnoor_Router::instance()->is_hamnoor_request() && 'plugin' === Hamnoor_Router::instance()->render_mode()) {
            wp_enqueue_style('hamnoor-standalone', HAMNOOR_URL . 'assets/css/hamnoor-standalone.css', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-standalone.css'));
            $standalone_font_css = $this->standalone_font_css();
            if ($standalone_font_css) {
                wp_add_inline_style('hamnoor-standalone', $standalone_font_css);
            }
        }
        wp_enqueue_style('hamnoor-ui-v4', HAMNOOR_URL . 'assets/css/hamnoor-ui-v4.css', array('hamnoor-frontend','hamnoor-desktop','hamnoor-mobile'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-ui-v4.css'));
        wp_enqueue_script('hamnoor-frontend', HAMNOOR_URL . 'assets/js/hamnoor-frontend.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-frontend.js'), true);
        wp_enqueue_script('hamnoor-mobile', HAMNOOR_URL . 'assets/js/hamnoor-mobile.js', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/js/hamnoor-mobile.js'), true);
        $settings = Hamnoor_Helpers::settings();
        wp_localize_script('hamnoor-frontend', 'HamnoorFrontend', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hamnoor_frontend'),
            'dashboard_url' => Hamnoor_Helpers::page_url('dashboard'),
            'user_id' => Hamnoor_Mobile_Access::is_logged_in() ? (int) Hamnoor_Mobile_Access::current_user_id() : 0,
            'debug' => (current_user_can('manage_options') && !empty($settings['ajax_debug_admin']) && '1' === (string) $settings['ajax_debug_admin']) ? 1 : 0,
            'texts' => array(
                'loading' => __('در حال ثبت...', 'hamnoor'),
                'checking' => __('در حال بررسی...', 'hamnoor'),
                'form_incomplete' => Hamnoor_Texts::get('form_incomplete'),
                'claim_next_desc' => Hamnoor_Texts::get('claim_next_desc'),
                'claim_random_desc' => Hamnoor_Texts::get('claim_random_desc'),
                'claim_manual_desc' => Hamnoor_Texts::get('claim_manual_desc', array('PART_TITLE' => '{PART_TITLE}')),
                'complete_success' => Hamnoor_Texts::get('complete_success_full'),
                'complete_inline_success' => Hamnoor_Texts::get('complete_inline_success'),
                'mobile_invalid' => Hamnoor_Texts::get('mobile_invalid'),
                'release_success' => __('سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.', 'hamnoor'),
                'release_keep_until_manual' => Hamnoor_Texts::get('release_keep_until_manual'),
                'release_after_days_option' => Hamnoor_Texts::get('release_after_days_option'),
            ),
        ));
        // Optional integrations own their assets. Core only exposes this
        // stable boundary and remains functional when an integration is absent.
        do_action('hamnoor_enqueue_frontend_integrations', array(
            'frontend_handle' => 'hamnoor-frontend',
            'nonce' => wp_create_nonce('hamnoor_frontend'),
            'mobile_user_id' => Hamnoor_Mobile_Access::is_logged_in() ? (int)Hamnoor_Mobile_Access::current_user_id() : 0,
        ));
    }


    public function render_mobile_navigation($active = '') {
        $active = sanitize_key((string) $active);
        $dashboard_url = Hamnoor_Helpers::page_url('dashboard');
        $create_url = Hamnoor_Helpers::page_url('create');
        $mobile_nav = array(
            'home_url' => $dashboard_url,
            'parts_url' => $dashboard_url ? add_query_arg('tab', 'my-parts', $dashboard_url) : '',
            'create_url' => $create_url,
            'notifications_url' => $dashboard_url ? add_query_arg('tab', 'notifications', $dashboard_url) : '',
            'more_url' => $dashboard_url ? add_query_arg('mobile_more', '1', $dashboard_url) : '',
            'active' => $active,
            'unread' => Hamnoor_Mobile_Access::is_logged_in() ? Hamnoor_DB::count_unread_notifications(Hamnoor_Mobile_Access::current_user_id()) : 0,
        );
        ob_start();
        $this->render_template('frontend/mobile-bottom-nav.php', array('mobile_nav' => $mobile_nav));
        return ob_get_clean();
    }

    private function render_desktop_page_header($title, $subtitle = '', $active = '') {
        $dashboard_url = Hamnoor_Helpers::page_url('dashboard');
        $list_url = Hamnoor_Helpers::page_url('list');
        $create_url = Hamnoor_Helpers::page_url('create');
        $links = array(
            'dashboard' => array('url' => $dashboard_url, 'label' => __('پنل من', 'hamnoor')),
            'list'      => array('url' => $list_url, 'label' => __('پویش‌های عمومی', 'hamnoor')),
            'create'    => array('url' => $create_url, 'label' => __('ایجاد پویش', 'hamnoor')),
        );
        ob_start(); ?>
        <header class="hamnoor-desktop-ui hamnoor-desktop-page-head hamnoor-desktop-app-head">
            <a class="hamnoor-desktop-page-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>">
                <span><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></span>
                <div><strong><?php echo esc_html($title); ?></strong><?php if ($subtitle) : ?><small><?php echo esc_html($subtitle); ?></small><?php endif; ?></div>
            </a>
            <nav class="hamnoor-desktop-page-actions hamnoor-desktop-primary-nav" aria-label="<?php echo esc_attr__('ناوبری هم‌نور', 'hamnoor'); ?>">
                <?php foreach ($links as $key => $item) : if (empty($item['url'])) { continue; } ?>
                    <a class="<?php echo esc_attr($active === $key ? 'is-active' : ($key === 'create' ? 'is-primary' : '')); ?>" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['label']); ?></a>
                <?php endforeach; ?>
            </nav>
        </header>
        <?php return ob_get_clean();
    }

    public function shortcode_khatm($atts) {
        $atts = shortcode_atts(array('token' => '', 'id' => ''), $atts, 'hamnoor');
        $key = !empty($atts['id']) ? (int) $atts['id'] : Hamnoor_Helpers::sanitize_token($atts['token']);
        $khatm = $key ? Hamnoor_DB::get_khatm($key) : null;
        if (!$khatm || !Hamnoor_Permissions::can_view_khatm($khatm)) { return $this->wrap($this->notice(__('پویش پیدا نشد.', 'hamnoor'), 'error')); }
        if (!Hamnoor_Permissions::can_preview_unpublished_khatm($khatm)) { return $this->wrap($this->notice(__('این پویش هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor'), 'warning')); }
        return $this->wrap($this->render_khatm($khatm) . $this->render_mobile_navigation(''));
    }

    public function shortcode_list() {
        $khatms = Hamnoor_DB::get_khatms(array('privacy' => 'public', 'status__in' => array('active', 'paused', 'expired', 'completed'), 'limit' => 80, 'orderby' => 'status_priority'));
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        $this->render_template('frontend/khatm-list.php', array('khatms' => $khatms));
        echo $this->render_mobile_navigation('more'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return $this->wrap(ob_get_clean());
    }

    public function shortcode_create_khatm() {
        $html = Hamnoor_Helpers::current_notice();
        $create_url = Hamnoor_Helpers::page_url('create');

        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            $desktop_head = $this->render_desktop_page_header(__('ایجاد پویش جدید', 'hamnoor'), __('تأیید هویت و آغاز ساخت پویش', 'hamnoor'), 'create');
            return $this->wrap($html . $desktop_head . Hamnoor_Mobile_Access::begin_create_form() . $this->render_mobile_navigation('create'));
        }

        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $is_privileged_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        if (!$is_privileged_admin && !Hamnoor_Mobile_Access::has_create_access($user_id) && empty($_GET['edit_khatm'])) {
            $desktop_head = $this->render_desktop_page_header(__('ایجاد پویش جدید', 'hamnoor'), __('تأیید موبایل برای دسترسی به فرم ساخت پویش', 'hamnoor'), 'create');
            return $this->wrap($html . $desktop_head . Hamnoor_Mobile_Access::create_verification_form($create_url) . $this->render_mobile_navigation('create'));
        }

        if (!empty($_GET['edit_khatm'])) {
            $khatm = Hamnoor_DB::get_khatm((int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['edit_khatm'])));
            if (!$khatm || !Hamnoor_DB::user_can_manage_khatm((int) $khatm->id, $user_id)) {
                return $this->wrap($html . $this->notice(__('شما به ویرایش این پویش دسترسی ندارید.', 'hamnoor'), 'error') . $this->render_mobile_navigation('create'));
            }
            if (!$is_privileged_admin && !Hamnoor_Mobile_Access::has_sensitive_access($user_id)) {
                $edit_url = add_query_arg('edit_khatm', (int) $khatm->id, $create_url);
                return $this->wrap($html . Hamnoor_Mobile_Access::verification_box('required', $edit_url) . $this->render_mobile_navigation('create'));
            }
            return $this->wrap($html . $this->render_create_form($khatm) . $this->render_mobile_navigation('create'));
        }

        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) {
            return $this->wrap($html . $this->notice(__('ایجاد پویش جدید در حال حاضر موقتاً غیرفعال است.', 'hamnoor'), 'warning') . $this->render_mobile_navigation('create'));
        }

        return $this->wrap($html . $this->render_create_form() . $this->render_mobile_navigation('create'));
    }

    public function shortcode_dashboard() {
        if (!empty($_GET['hamnoor_personal_quran'])) {
            $campaign_id = (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['hamnoor_personal_quran']));
            return $this->wrap(Hamnoor_Helpers::current_notice() . Hamnoor_Personal_Quran::render($campaign_id));
        }
        if (!empty($_GET['hamnoor_reading'])) {
            $part_id = (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['hamnoor_reading']));
            $reading_access = isset($_GET['hamnoor_reading_access']) ? Hamnoor_Helpers::sanitize_tracking_token(wp_unslash($_GET['hamnoor_reading_access'])) : '';
            return $this->wrap(Hamnoor_Helpers::current_notice() . Hamnoor_Reading::render($part_id, $reading_access));
        }
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            $guest_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
            $guest_tabs = array('summary','my-parts','contributions','notifications','my-khatms','progress','reminders','profile','help');
            if (!in_array($guest_tab, $guest_tabs, true)) { $guest_tab = 'summary'; }
            ob_start();
            echo Hamnoor_Helpers::current_notice();
            $this->render_template('frontend/dashboard-guest.php', array(
                'list_url' => Hamnoor_Helpers::page_url('list'),
                'create_url' => Hamnoor_Helpers::page_url('create'),
                'active_tab' => $guest_tab,
            ));
            return $this->wrap(ob_get_clean());
        }
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $settings = Hamnoor_Helpers::settings();
        $owned_per_page = max(5, min(200, (int) $settings['dashboard_my_khatms_per_page']));
        $parts_per_page = max(5, min(200, (int) $settings['dashboard_my_parts_per_page']));
        $owned_page = max(1, isset($_GET['my_khatms_page']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['my_khatms_page'])) : 1);
        $parts_page = max(1, isset($_GET['my_parts_page']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['my_parts_page'])) : 1);
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        $participants_khatm_id = !empty($_GET['participants_khatm']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['participants_khatm'])) : 0;
        $participants_selected_id = !empty($_GET['participant_id']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['participant_id'])) : 0;
        $progress_khatm_id = !empty($_GET['progress_khatm']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['progress_khatm'])) : 0;
        if ($participants_khatm_id) { $active_tab = 'my-khatms'; }
        if ($progress_khatm_id) { $active_tab = 'progress'; }
        $tabs = array('summary','my-khatms','progress','my-parts','contributions','notifications','reminders','profile','help');
        $tabs = (array) apply_filters('hamnoor_dashboard_tabs', $tabs, $user_id);
        $tabs = array_values(array_unique(array_filter(array_map('sanitize_key', $tabs))));
        if (!in_array($active_tab, $tabs, true)) { $active_tab = 'summary'; }
        $owned_args = array('owner_user_id' => $user_id, 'participation_strategy__not'=>Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR, 'limit' => $owned_per_page, 'offset' => ($owned_page - 1) * $owned_per_page);
        $owned = Hamnoor_DB::get_khatms($owned_args);
        $owned_total = Hamnoor_DB::count_khatms(array('owner_user_id' => $user_id, 'participation_strategy__not'=>Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR));
        $personal_quran = Hamnoor_Campaign_Repository::find_open_personal_quran_for_owner($user_id);
        $personal_quran_state = $personal_quran ? Hamnoor_Personal_Quran::dashboard_state($personal_quran) : null;
        $personal_quran_reminder_state = $personal_quran ? Hamnoor_Personal_Reminders::state($personal_quran) : null;
        $participants_khatm = null;
        $participants_rows = array();
        $participants_error = '';
        $participants_selected = null;
        $participants_selected_parts = array();
        $progress_owned = Hamnoor_DB::get_khatms(array('owner_user_id'=>$user_id,'participation_strategy__not'=>Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR,'limit'=>200,'offset'=>0,'orderby'=>'id','order'=>'DESC'));
        $progress_khatm = null;
        $progress_rows = array();
        $progress_summary = array('total'=>0,'available'=>0,'not_started'=>0,'reading'=>0,'ready'=>0,'done'=>0);
        $progress_page = max(1, isset($_GET['progress_page']) ? (int)Hamnoor_Validator::normalize_digits(wp_unslash($_GET['progress_page'])) : 1);
        $progress_per_page = 25;
        $progress_pages = 1;
        if ('progress' === $active_tab && !$progress_khatm_id && $progress_owned) { $progress_khatm_id = (int) $progress_owned[0]->id; }
        if ($progress_khatm_id) {
            $candidate_progress_khatm = Hamnoor_DB::get_khatm($progress_khatm_id);
            if ($candidate_progress_khatm && !Hamnoor_Campaign_Domain::is_personal_quran($candidate_progress_khatm) && Hamnoor_DB::user_can_manage_khatm((int)$candidate_progress_khatm->id,$user_id)) {
                $progress_khatm = $candidate_progress_khatm;
                $progress_summary = Hamnoor_Engagement::progress_summary_for_khatm((int)$progress_khatm->id);
                $progress_pages = max(1, (int)ceil(max(0,(int)$progress_summary['total']) / $progress_per_page));
                if ($progress_page > $progress_pages) { $progress_page = $progress_pages; }
                $progress_rows = Hamnoor_Engagement::progress_rows((int)$progress_khatm->id, $progress_per_page, ($progress_page - 1) * $progress_per_page);
            }
        }
        if ($participants_khatm_id) {
            $participants_khatm = Hamnoor_DB::get_khatm($participants_khatm_id);
            if (!$participants_khatm) { $participants_error = __('پویش مورد نظر پیدا نشد.', 'hamnoor'); }
            elseif (Hamnoor_Campaign_Domain::is_personal_quran($participants_khatm)) { $participants_error = __('ختم شخصی بخش شرکت‌کنندگان ندارد.', 'hamnoor'); }
            elseif (!Hamnoor_DB::user_can_manage_khatm((int) $participants_khatm->id, $user_id)) { $participants_error = __('شما اجازه مشاهده شرکت‌کنندگان این پویش را ندارید.', 'hamnoor'); }
            else {
                $participants_rows = Hamnoor_DB::get_participants((int) $participants_khatm->id, array('limit' => 200));
                if ($participants_selected_id) {
                    $candidate_participant = Hamnoor_DB::get_participant($participants_selected_id);
                    if ($candidate_participant && (int)$candidate_participant->khatm_id === (int)$participants_khatm->id) {
                        $participants_selected = $candidate_participant;
                        $participants_selected_parts = Hamnoor_DB::get_participant_parts($participants_selected_id, 200, (int)$participants_khatm->id);
                    }
                }
            }
        }
        $parts = Hamnoor_DB::get_parts_by_user($user_id, $parts_per_page, ($parts_page - 1) * $parts_per_page);
        $parts_total = Hamnoor_DB::count_parts_by_user($user_id);
        $completed_archive = $this->completed_parts_archive($user_id);
        $parts = array_values(array_filter($parts, function($part) {
            $khatm = !empty($part->khatm_token) ? Hamnoor_DB::get_khatm($part->khatm_token) : null;
            return !$khatm || 'completed' !== (string) $khatm->status;
        }));
        usort($parts, function($a, $b) {
            $rank = function($part) {
                if ('done' === (string) $part->status) { return 3; }
                $state = class_exists('Hamnoor_Reading') ? Hamnoor_Reading::card_state($part) : null;
                if ($state && !empty($state['ready'])) { return 0; }
                if ($state && !empty($state['started'])) { return 1; }
                return 2;
            };
            $rank_a = $rank($a); $rank_b = $rank($b);
            if ($rank_a !== $rank_b) { return $rank_a <=> $rank_b; }
            return (int) $b->id <=> (int) $a->id;
        });
        $open_parts = 0; foreach ($parts as $part) { if ('done' !== (string) $part->status) { $open_parts++; } }
        $unread_notifications = Hamnoor_DB::count_unread_notifications($user_id);
        if ('notifications' === $active_tab && $unread_notifications) { Hamnoor_DB::mark_notifications_read($user_id); $unread_notifications = 0; }
        $notifications = Hamnoor_DB::get_notifications($user_id, 100);
        $html = Hamnoor_Helpers::current_notice();
        if (!empty($_GET['created_khatm'])) {
            $khatm = Hamnoor_DB::get_khatm((int) $_GET['created_khatm']);
            if ($khatm && Hamnoor_DB::user_can_manage_khatm((int) $khatm->id)) { $html .= $this->success_panel($khatm); }
        }
        ob_start();
        echo $html;
        if (!Hamnoor_Mobile_Access::is_verified($user_id)) { echo Hamnoor_Mobile_Access::verification_prompt(Hamnoor_Helpers::page_url('dashboard')); }
        $this->render_template('frontend/dashboard.php', array(
            'user' => Hamnoor_Mobile_Access::current_user(), 'active_tab' => $active_tab,
            'personal_quran'=>$personal_quran, 'personal_quran_state'=>$personal_quran_state, 'personal_quran_reminder_state'=>$personal_quran_reminder_state,
            'owned' => $owned, 'owned_total' => $owned_total, 'owned_page' => $owned_page, 'owned_per_page' => $owned_per_page,
            'parts' => $parts, 'parts_total' => $parts_total, 'parts_page' => $parts_page, 'parts_per_page' => $parts_per_page, 'open_parts' => $open_parts, 'completed_archive' => $completed_archive,
            'participants_khatm' => $participants_khatm, 'participants_rows' => $participants_rows, 'participants_error' => $participants_error, 'participants_selected'=>$participants_selected, 'participants_selected_parts'=>$participants_selected_parts,
            'progress_owned' => $progress_owned, 'progress_khatm' => $progress_khatm, 'progress_rows' => $progress_rows, 'progress_summary' => $progress_summary, 'progress_page'=>$progress_page, 'progress_pages'=>$progress_pages,
            'notifications' => $notifications, 'unread_notifications' => $unread_notifications,
        ));
        return $this->wrap(ob_get_clean());
    }

    private function completed_parts_archive($user_id) {
        $all_parts = Hamnoor_DB::get_parts_by_user((int) $user_id, 500, 0);
        $archive = array();
        foreach ($all_parts as $part) {
            $khatm = Hamnoor_DB::get_khatm($part->khatm_token);
            if (!$khatm || 'completed' !== (string) $khatm->status) { continue; }
            $key = (int) $khatm->id;
            if (!isset($archive[$key])) {
                $archive[$key] = array('khatm' => $khatm, 'parts' => array());
            }
            $archive[$key]['parts'][] = $part;
        }
        return $archive;
    }

    private function parse_contact($raw) {
        $raw = sanitize_text_field((string) $raw);
        if ('' === trim($raw)) { return array('raw' => $raw, 'contact' => '', 'error' => '', 'type' => ''); }
        $phone = Hamnoor_Validator::normalize_phone($raw);
        return array('raw' => $raw, 'contact' => Hamnoor_Validator::valid_iran_mobile($phone) ? $phone : '', 'error' => Hamnoor_Validator::valid_iran_mobile($phone) ? '' : Hamnoor_Texts::get('mobile_invalid'), 'type' => 'phone');
    }

    private function profile_identity_errors($user_id, $phone) {
        $errors = array();
        $phone = Hamnoor_Validator::normalize_phone($phone);
        if ($phone) {
            $owner = Hamnoor_Mobile_Access::find_user($phone);
            if ($owner && (int) $owner->id !== (int) $user_id) {
                $errors['phone'] = __('این شماره موبایل قبلاً به پنل دیگری متصل شده است.', 'hamnoor');
            }
        }
        return $errors;
    }

    private function claim_rate_limit($khatm, $data) {
        $identity = !empty($data['phone']) ? $data['phone'] : '';
        return Hamnoor_Helpers::rate_limit('claim_part', (int) $khatm->id . '|' . $identity, 30, 10 * MINUTE_IN_SECONDS);
    }

    private function maybe_store_current_user_phone($phone) {
        // شماره موبایل از پروفایل اختصاصی هم‌نور خوانده می‌شود و در wp_users ذخیره نمی‌شود.
        return;
    }


    public function render_khatm($khatm) {
        $progress = Hamnoor_DB::get_progress((int) $khatm->id);
        $round_filter = !empty($_GET['hamnoor_round']) ? max(1, (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['hamnoor_round']))) : 0;
        $status_filter = !empty($_GET['hamnoor_free_only']) ? 'available' : '';
        $search_filter = !empty($_GET['hamnoor_part_search']) ? sanitize_text_field(wp_unslash($_GET['hamnoor_part_search'])) : '';
        $page = !empty($_GET['hamnoor_parts_page']) ? max(1, (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['hamnoor_parts_page']))) : 1;
        $settings = Hamnoor_Helpers::settings();
        $featured_badge = Hamnoor_Featured_Service::badge_data($khatm);
        $per_page = max(5, min(200, (int) $settings['admin_parts_per_page']));
        $parts_total = Hamnoor_DB::count_parts((int) $khatm->id, $status_filter, array('round_number'=>$round_filter, 'search'=>$search_filter));
        $parts_pages = max(1, (int) ceil($parts_total / $per_page));
        if ($page > $parts_pages) { $page = $parts_pages; }
        $parts_offset = ($page - 1) * $per_page;
        $parts = Hamnoor_DB::get_parts((int) $khatm->id, $status_filter, array('round_number'=>$round_filter, 'search'=>$search_filter, 'limit'=>$per_page, 'offset'=>$parts_offset));
        $parts_from = $parts_total ? ($parts_offset + 1) : 0;
        $parts_to = $parts_total ? min($parts_offset + count($parts), $parts_total) : 0;
        $current_page_available = 0;
        foreach ($parts as $displayed_part) { if ('available' === (string) $displayed_part->status) { $current_page_available++; } }
        $access_part = !empty($_GET['part']) ? (int) $_GET['part'] : 0;
        $access = !empty($_GET['access']) ? sanitize_text_field(wp_unslash($_GET['access'])) : '';
        $claimable_for_ui = Hamnoor_DB::khatm_accepts_claims($khatm);
        $claim_verify_prompt = (!empty($_GET['hamnoor_verify_claim']) && Hamnoor_Mobile_Access::is_logged_in() && !Hamnoor_Mobile_Access::is_verified()) ? Hamnoor_Mobile_Access::verification_prompt(Hamnoor_Helpers::invite_url($khatm)) : '';
        $khatm_url = Hamnoor_Helpers::invite_url($khatm);
        $share_url = Hamnoor_Helpers::share_invite_url($khatm, 'web');
        $share_title = Hamnoor_Helpers::share_title($khatm);
        $share_text = Hamnoor_Helpers::share_text($khatm, $progress, 'web');
        $share_clipboard = Hamnoor_Helpers::share_text($khatm, $progress, 'clipboard');
        $share_image = Hamnoor_Helpers::share_image_data($khatm);
        $is_published = in_array((string) $khatm->status, array('active','paused','expired','completed'), true);
        $is_personal_campaign = Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)($khatm->access_mode ?? '');
        $can_public_share = !$is_personal_campaign && $is_published && ('public' === $khatm->privacy || '1' === $settings['allow_private_share']);
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        if ($claim_verify_prompt) { echo $claim_verify_prompt; }
        echo '<section class="hamnoor-khatm-header">';
        $cover_url = Hamnoor_Helpers::cover_url($khatm);
        if (!empty($cover_url)) { echo '<img class="hamnoor-cover" src="' . esc_url($cover_url) . '" alt="' . esc_attr($khatm->title) . '">'; }
        else { echo '<div class="hamnoor-cover hamnoor-cover-placeholder" aria-hidden="true"></div>'; }
        echo '<div class="hamnoor-khatm-header-body"><div class="hamnoor-status-row"><span class="hamnoor-badge hamnoor-status-' . esc_attr($khatm->status) . '">' . esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')) . '</span>' . ('private' === $khatm->privacy ? ' <span class="hamnoor-badge hamnoor-status-private">' . esc_html__('خصوصی', 'hamnoor') . '</span>' : '') . '</div><h1>' . esc_html($khatm->title) . '</h1>';
        if ($khatm->intention) { echo '<div class="hamnoor-intention">' . wp_kses_post($khatm->intention) . '</div>'; }
        echo '</div></section>';
        if ($featured_badge) {
            $featured_notice = 'pinned' === $featured_badge['status'] ? Hamnoor_Texts::get('pinned_notice_single') : Hamnoor_Texts::get('featured_notice_single');
            echo '<div class="hamnoor-featured-notice hamnoor-featured-notice--' . esc_attr($featured_badge['status']) . '"><span class="hamnoor-featured-badge">' . esc_html($featured_badge['text']) . '</span><span>' . esc_html($featured_notice) . '</span></div>';
        }
        if (!empty($khatm->starts_at) || !empty($khatm->ends_at)) {
            echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-participation-window">';
            if (!empty($khatm->starts_at)) { echo '<div><strong>' . esc_html(Hamnoor_Texts::get('participation_start_label')) . ':</strong> ' . esc_html(Hamnoor_Jalali::to_jalali($khatm->starts_at, true)) . '</div>'; }
            if (!empty($khatm->ends_at)) { echo '<div><strong>' . esc_html(Hamnoor_Texts::get('participation_end_label')) . ':</strong> ' . esc_html(Hamnoor_Jalali::to_jalali($khatm->ends_at, true)) . '</div>'; }
            echo '</div>';
        }
        echo '<section class="hamnoor-progress-card" aria-label="' . esc_attr__('پیشرفت پویش', 'hamnoor') . '" data-khatm-id="' . (int) $khatm->id . '"><div class="hamnoor-progress-number"><strong data-progress-percent>' . esc_html($progress['percent']) . '%</strong><span>' . esc_html__('پیشرفت', 'hamnoor') . '</span></div><div class="hamnoor-progress-body"><div class="hamnoor-progress"><span data-progress-bar style="width:' . esc_attr($progress['percent']) . '%"></span></div><div class="hamnoor-progress-meta"><span data-progress-text>' . esc_html(sprintf(__('%1$d از %2$d سهم خوانده شده است', 'hamnoor'), (int)$progress['done'], (int)$progress['total'])) . '</span><span data-progress-available>' . esc_html(sprintf(__('سهم آزاد: %d', 'hamnoor'), (int)$progress['available'])) . '</span></div></div></section>';
        if ('completed' === (string) $khatm->status) { echo '<div class="hamnoor-notice hamnoor-notice-success hamnoor-completed-message">' . esc_html(Hamnoor_Texts::get('khatm_completed_message')) . '</div>'; }
        elseif (in_array((string) $khatm->status, array('paused'), true)) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('دریافت سهم جدید در این پویش موقتاً متوقف است. اگر قبلاً سهم گرفته‌اید، می‌توانید قرائت همان سهم را ادامه دهید.', 'hamnoor') . '</div>'; }
        elseif ('expired' === (string) $khatm->status) {
            $completion_note = __('اگر قبلاً سهم گرفته‌اید، می‌توانید همان سهم را تکمیل کنید.', 'hamnoor');
            if (isset($khatm->completion_grace_days) && null !== $khatm->completion_grace_days && '' !== (string)$khatm->completion_grace_days && !empty($khatm->ends_at)) {
                $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$khatm->ends_at, wp_timezone());
                if ($end) {
                    $deadline_mysql = $end->modify('+' . (int)$khatm->completion_grace_days . ' days')->format('Y-m-d H:i:s');
                    $completion_note = sprintf(__('اگر قبلاً سهم گرفته‌اید، تا %s برای تکمیل آن فرصت دارید.', 'hamnoor'), Hamnoor_Jalali::to_jalali($deadline_mysql, true));
                }
            }
            echo '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('پذیرش سهم جدید در این پویش پایان یافته است.', 'hamnoor') . ' ' . esc_html($completion_note) . '</div>';
        }
        elseif ('cancelled' === (string) $khatm->status) { echo '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این پویش لغو شده است و سهم جدید نمی‌پذیرد.', 'hamnoor') . '</div>'; }
        elseif ('archived' === (string) $khatm->status) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('این پویش بایگانی شده و فقط برای مشاهده سابقه در دسترس است.', 'hamnoor') . '</div>'; }
        echo '<section class="hamnoor-primary-actions" aria-label="' . esc_attr__('انتخاب سهم', 'hamnoor') . '"><div class="hamnoor-primary-actions-copy"><h2>' . esc_html__('در این پویش همراه شوید', 'hamnoor') . '</h2><p>' . esc_html__('هم‌نور یک سهم آزاد را برای شما انتخاب می‌کند. اگر سهم مشخصی مدنظر دارید، می‌توانید از روش‌های دیگر استفاده کنید.', 'hamnoor') . '</p></div><div class="hamnoor-action-buttons hamnoor-action-buttons--claim">';
        if (!is_wp_error($claimable_for_ui) && !empty($progress['available'])) {
            echo '<button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-claim-primary" data-hamnoor-next="1">' . esc_html__('یک سهم آزاد برای من انتخاب کن', 'hamnoor') . '</button>';
            echo '<details class="hamnoor-claim-more"><summary>' . esc_html__('روش‌های دیگر انتخاب سهم', 'hamnoor') . '</summary><div class="hamnoor-claim-more-actions">';
            if ('repeated_surah' !== (string) $khatm->khatm_type) { echo '<button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-hamnoor-random="1">' . esc_html__('یک سهم تصادفی انتخاب کن', 'hamnoor') . '</button>'; }
            echo '<a class="hamnoor-btn hamnoor-btn-secondary hamnoor-manual-claim-link" href="#hamnoor-parts">' . esc_html__('خودم سهم را انتخاب می‌کنم', 'hamnoor') . '</a>';
            echo '</div></details>';
        } elseif (!is_wp_error($claimable_for_ui) && empty($progress['available'])) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html(Hamnoor_Texts::get('no_free_part')) . '</div>'; }
        else { echo $this->notice($claimable_for_ui->get_error_message(), 'warning'); }
        echo '</div></section>';
        if ($is_published && !$is_personal_campaign) {
            echo '<section class="hamnoor-share-panel"><h2>' . esc_html__('اشتراک‌گذاری پویش', 'hamnoor') . '</h2><div class="hamnoor-share-box"><label>' . esc_html__('لینک دعوت', 'hamnoor') . '</label><input readonly value="' . esc_attr($share_url) . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy">' . esc_html__('کپی لینک', 'hamnoor') . '</button></div>';
            if ($can_public_share) { echo '<div class="hamnoor-share-actions" data-hamnoor-title="' . esc_attr($share_title) . '" data-hamnoor-url="' . esc_url($share_url) . '" data-hamnoor-text="' . esc_attr($share_text) . '" data-hamnoor-clipboard="' . esc_attr($share_clipboard) . '" data-hamnoor-image="' . (!empty($share_image['url']) ? esc_url($share_image['url']) : '') . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy-invite">' . esc_html__('کپی متن دعوت', 'hamnoor') . '</button><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-web-share">' . esc_html__('اشتراک‌گذاری', 'hamnoor') . '</button></div>'; }
            echo '</section>';
        } elseif (in_array((string) $khatm->status, array('pending_review','needs_revision','rejected'), true)) {
            echo $this->notice(__('این پویش هنوز برای دعوت عمومی فعال نیست.', 'hamnoor'), 'warning');
        }
        echo '<section class="hamnoor-part-filter-panel"><h2>' . esc_html__('جستجو و فیلتر سهم‌ها', 'hamnoor') . '</h2><form class="hamnoor-part-filters" method="get">';
        if (!empty($khatm->khatm_count) && (int) $khatm->khatm_count > 1) {
            echo '<label>' . esc_html__('شماره پویش', 'hamnoor') . '<select name="hamnoor_round"><option value="0">' . esc_html__('همه', 'hamnoor') . '</option>';
            for ($r=1; $r <= (int) $khatm->khatm_count; $r++) { echo '<option value="' . (int) $r . '"' . selected($round_filter, $r, false) . '>' . esc_html(sprintf(__('پویش %d', 'hamnoor'), $r)) . '</option>'; }
            echo '</select></label>';
        }
        echo '<label class="hamnoor-check"><input type="checkbox" name="hamnoor_free_only" value="1"' . checked((bool)$status_filter, true, false) . '> ' . esc_html__('فقط سهم‌های آزاد', 'hamnoor') . '</label><label>' . esc_html__('جستجو', 'hamnoor') . '<input name="hamnoor_part_search" value="' . esc_attr($search_filter) . '" type="search" placeholder="' . esc_attr__('شماره صفحه، جزء یا سوره', 'hamnoor') . '"></label><button class="hamnoor-btn hamnoor-btn-secondary" type="submit">' . esc_html__('اعمال فیلتر', 'hamnoor') . '</button></form></section>';
        if ('completed' !== (string) $khatm->status && !is_wp_error($claimable_for_ui)) { echo $this->claim_form($khatm); }
        if ($access_part) {
            $part = Hamnoor_DB::get_part($access_part);
            if ($part && (int) $part->khatm_id === (int) $khatm->id) {
                $access_ok = Hamnoor_DB::can_complete_part($part, $access, false);
                if ($access_ok) { echo '<section class="hamnoor-card hamnoor-selected"><h2>' . esc_html__('سهم انتخاب‌شده شما', 'hamnoor') . '</h2>'; $this->render_template('frontend/part-card.php', array('part' => $part, 'khatm' => $khatm, 'context' => 'dashboard', 'access' => $access)); echo '</section>'; }
                else { echo $this->notice(__('لینک اختصاصی سهم معتبر نیست یا به این سهم دسترسی ندارید.', 'hamnoor'), 'error'); }
            }
        }
        echo '<section id="hamnoor-parts" class="hamnoor-parts-section" tabindex="-1"><h2>' . esc_html__('سهم‌های پویش', 'hamnoor') . '</h2>';
        if ($parts_total > $per_page) {
            echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-parts-page-info">' . esc_html(sprintf(__('صفحه %1$d از %2$d — نمایش %3$d تا %4$d از %5$d سهم. برای مشاهده سهم‌های بیشتر از صفحه‌بندی استفاده کنید.', 'hamnoor'), (int) $page, (int) $parts_pages, (int) $parts_from, (int) $parts_to, (int) $parts_total)) . '</div>';
        }
        if (!empty($progress['available']) && 0 === $current_page_available && $parts_total > 0) {
            echo '<div class="hamnoor-notice hamnoor-notice-warning hamnoor-parts-page-info">' . esc_html__('در این صفحه سهم آزادی دیده نمی‌شود، اما در صفحه‌های دیگر هنوز سهم آزاد وجود دارد.', 'hamnoor') . '</div>';
        } elseif (!empty($progress['available']) && 0 === $parts_total && ($round_filter || $search_filter || $status_filter)) {
            echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-parts-page-info">' . esc_html__('با این فیلتر سهم آزادی دیده نمی‌شود، اما در کل پویش هنوز سهم آزاد وجود دارد. فیلترها را تغییر دهید یا از دکمه‌های انتخاب سهم استفاده کنید.', 'hamnoor') . '</div>';
        }
        echo '<div class="hamnoor-public-parts-row-head hamnoor-desktop-ui" aria-hidden="true"><span>' . esc_html__('سهم', 'hamnoor') . '</span><span>' . esc_html__('وضعیت', 'hamnoor') . '</span><span>' . esc_html__('عملیات', 'hamnoor') . '</span></div>';
        echo '<div class="hamnoor-parts-grid">';
        foreach ($parts as $part) { $this->render_template('frontend/part-card.php', array('part' => $part, 'khatm' => $khatm, 'context' => 'single', 'claimable' => !is_wp_error($claimable_for_ui))); }
        if (!$parts) { echo '<p class="hamnoor-empty">' . esc_html__('سهمی با این فیلترها پیدا نشد.', 'hamnoor') . '</p>'; }
        echo '</div>';
        if ($parts_pages > 1) {
            $pagination_args = array();
            if ($round_filter) { $pagination_args['hamnoor_round'] = $round_filter; }
            if ($status_filter) { $pagination_args['hamnoor_free_only'] = 1; }
            if ($search_filter) { $pagination_args['hamnoor_part_search'] = $search_filter; }
            $pagination_args['hamnoor_parts_page'] = '%#%';
            $pagination = paginate_links(array(
                'base' => str_replace('%25%23%25', '%#%', add_query_arg($pagination_args, Hamnoor_Helpers::invite_url($khatm))),
                'format' => '',
                'current' => $page,
                'total' => $parts_pages,
                'type' => 'list',
                'prev_text' => __('قبلی', 'hamnoor'),
                'next_text' => __('بعدی', 'hamnoor'),
            ));
            if ($pagination) { echo '<nav class="hamnoor-parts-pagination" aria-label="' . esc_attr__('صفحه‌بندی سهم‌ها', 'hamnoor') . '">' . wp_kses_post($pagination) . '</nav>'; }
        }
        echo '</section>';
        return ob_get_clean();
    }

    private function claim_form($khatm) {
        $state = Hamnoor_Helpers::get_form_state('claim_' . $khatm->token);
        $errors = isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : array();
        ob_start();
        $settings = Hamnoor_Helpers::settings();
        $active_limit = !empty($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : (int)($settings['default_active_claim_limit'] ?? 3);
        $window_enabled = !empty($settings['temporal_claim_quota_enabled']) && '1' === (string)$settings['temporal_claim_quota_enabled'] && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY !== (string)($khatm->access_mode ?? '');
        echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-claim-quota-summary" data-hamnoor-quota-state><strong>' . esc_html__('پیش از انتخاب سهم', 'hamnoor') . '</strong><p>' . esc_html($window_enabled ? sprintf(__('هم‌زمان حداکثر %1$d سهم ناتمام و در هر %2$d ساعت حداکثر %3$d انتخاب جدید مجاز است.', 'hamnoor'), $active_limit, (int)($settings['temporal_claim_window_hours'] ?? 24), (int)($settings['temporal_claim_limit'] ?? 3)) : sprintf(__('هم‌زمان حداکثر %d سهم ناتمام مجاز است.', 'hamnoor'), $active_limit)) . '</p><p data-hamnoor-personal-quota aria-live="polite"></p></div>';
        /*
         * This modal intentionally contains no user-specific name or phone.
         * The live identity is fetched over no-cache AJAX when the modal opens,
         * preventing full-page caches from leaking or reusing another session's data.
         */
        $this->render_template('frontend/claim-modal.php', array(
            'khatm' => $khatm,
            'errors' => $errors,
        ));
        echo '<noscript><div class="hamnoor-card hamnoor-noscript-claim"><div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('برای انتخاب امن سهم و شناسایی نشست فعال، جاوااسکریپت مرورگر را فعال کنید.', 'hamnoor') . '</div></div></noscript>';
        return ob_get_clean();
    }

    private function render_create_form($khatm = null) {
        $editing = is_object($khatm);
        $requires_review = !$editing && !current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms');
        $user = Hamnoor_Mobile_Access::current_user();
        $state = Hamnoor_Helpers::get_form_state($editing ? 'khatm_edit' : 'khatm_create');
        $old = isset($state['data']) ? $state['data'] : array();
        $errors = isset($state['errors']) ? $state['errors'] : array();
        $getv = function($key, $default = '') use ($old) { return array_key_exists($key, $old) ? $old[$key] : $default; };
        $title = $getv('title', $editing ? $khatm->title : '');
        $intention = $getv('intention', $editing ? $khatm->intention : '');
        $description = $getv('description', $editing ? $khatm->description : '');
        $owner_name = $user ? (string) $user->display_name : ($editing ? (string) $khatm->owner_name : '');
        $owner_phone = $user ? (string) $user->phone : ($editing ? (string) $khatm->owner_phone : '');
        $type = $getv('khatm_type', $editing ? $khatm->khatm_type : 'juz');
        $privacy = $getv('privacy', $editing ? $khatm->privacy : 'public');
        $selected_surah = (int) $getv('selected_surah_number', 1); if ($selected_surah < 1) { $selected_surah = 1; }
        $khatm_count = (int) $getv('khatm_count', $editing && isset($khatm->khatm_count) ? (int) $khatm->khatm_count : 1); if ($khatm_count < 1) { $khatm_count = 1; }
        $starts_j = $getv('starts_at_jalali', $editing ? Hamnoor_Jalali::to_jalali($khatm->starts_at, true) : '');
        $ends_j = $getv('ends_at_jalali', $editing ? Hamnoor_Jalali::to_jalali($khatm->ends_at, true) : '');
        $indexable = $getv('indexable', $editing && isset($khatm->indexable) ? (int) $khatm->indexable : 1);
        $auto_release_mode = $getv('auto_release_mode', $editing && isset($khatm->auto_release_mode) ? $khatm->auto_release_mode : 'none');
        if (!in_array($auto_release_mode, array('none','after_days'), true)) { $auto_release_mode = 'none'; }
        $auto_release_after_days = (int) $getv('auto_release_after_days', $editing && isset($khatm->auto_release_after_days) ? (int) $khatm->auto_release_after_days : 0);
        $completion_grace_default = ($editing && isset($khatm->completion_grace_days) && null !== $khatm->completion_grace_days) ? (string)(int)$khatm->completion_grace_days : '';
        $completion_grace_days = $getv('completion_grace_days', $completion_grace_default);
        $completion_mode = $getv('completion_mode', $editing && isset($khatm->completion_mode) ? $khatm->completion_mode : 'manual_done');
        if (!in_array($completion_mode, array('manual_done','auto_done_on_claim'), true)) { $completion_mode = 'manual_done'; }
        $claim_settings = Hamnoor_Helpers::settings();
        $active_claim_limit_default = isset($claim_settings['default_active_claim_limit']) ? (int)$claim_settings['default_active_claim_limit'] : 3;
        $max_active_claims = (int)$getv('max_active_claims_per_participant', $editing && isset($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : $active_claim_limit_default);
        $max_active_claims = max(1, min(50, $max_active_claims));
        $action = $editing ? 'hamnoor_update_khatm' : 'hamnoor_create_khatm';
        $field_error = function($key) use ($errors) { return isset($errors[$key]) ? '<span class="hamnoor-field-error">' . esc_html($errors[$key]) . '</span>' : ''; };
        $field_class = function($key) use ($errors) { return isset($errors[$key]) ? ' hamnoor-field-has-error' : ''; };
        $settings = Hamnoor_Helpers::settings();
        $max_khatm_count = $editing && !empty($khatm->max_khatm_count) ? (int) $khatm->max_khatm_count : (int) $settings['max_khatm_count'];
        $max_khatm_count = max(1, min(1000, $max_khatm_count));
        $can_manage_limits = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $stored_repeat_override = $editing && !empty($khatm->max_repeat_count) ? (int) $khatm->max_repeat_count : 0;
        $max_repeat_override = $can_manage_limits ? (int) $getv('max_repeat_count', $stored_repeat_override) : $stored_repeat_override;
        $max_repeat_count = Hamnoor_Helpers::effective_max_repeat_count($max_repeat_override ? $max_repeat_override : null);
        $stored_repeat_count = $editing && 'repeated_surah' === $type && isset($khatm->parts_per_khatm) ? (int) $khatm->parts_per_khatm : 0;
        $repeat_default = $stored_repeat_count >= 1 && $stored_repeat_count <= 12000 ? $stored_repeat_count : min(100, $max_repeat_count);
        $repeat_count = $editing ? $repeat_default : (int) $getv('repeat_count', $repeat_default);
        if (!$editing && !array_key_exists('repeat_count', $old) && $repeat_count > $max_repeat_count) { $repeat_count = $max_repeat_count; }
        $parts_count_preview = ('juz' === $type ? 30 : ('surah' === $type ? 114 : ('repeated_surah' === $type ? max(1, $repeat_count) : 604))) * (in_array($type, array('page','juz','surah'), true) ? max(1, $khatm_count) : 1);
        $hard_limit = (int) $settings['max_parts_per_khatm'];
        $max_count_message = Hamnoor_Texts::get('khatm_count_error', array('MAX' => $max_khatm_count));
        $max_repeat_message = sprintf(__('تعداد تکرار سوره باید بین ۱ تا %d مرتبه باشد.', 'hamnoor'), $max_repeat_count);
        $repeat_contact_html = '';
        $repeat_contact_url = !empty($settings['repeat_contact_url']) ? esc_url($settings['repeat_contact_url']) : '';
        if (!empty($settings['repeat_contact_enabled']) && '1' === (string) $settings['repeat_contact_enabled'] && $repeat_contact_url) {
            $repeat_contact_html = '<div class="hamnoor-repeat-contact"><p class="hamnoor-help">' . esc_html($settings['repeat_contact_help']) . '</p><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url($repeat_contact_url) . '" rel="noopener noreferrer">' . esc_html($settings['repeat_contact_button_text']) . '</a></div>';
        }
        $support_html = '';
        if (!$editing && !empty($settings['support_section_enabled']) && '1' === (string) $settings['support_section_enabled']) {
            $support_html = '<aside class="hamnoor-support-section"><h3>' . esc_html($settings['support_section_title']) . '</h3>';
            if (!empty($settings['support_section_description'])) { $support_html .= '<div>' . wp_kses_post(wpautop($settings['support_section_description'])) . '</div>'; }
            if (!empty($settings['support_payment_info'])) { $support_html .= '<div class="hamnoor-support-payment">' . wp_kses_post(wpautop($settings['support_payment_info'])) . '</div>'; }
            $support_url = !empty($settings['support_button_url']) ? esc_url($settings['support_button_url']) : '';
            if ($support_url && !empty($settings['support_button_text'])) { $support_html .= '<a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url($support_url) . '" rel="noopener noreferrer">' . esc_html($settings['support_button_text']) . '</a>'; }
            $support_html .= '</aside>';
        }
        $cover_mode = isset($settings['cover_required_mode']) ? $settings['cover_required_mode'] : 'optional';
        $cover_label = __('تصویر پویش، اختیاری', 'hamnoor');
        if ('always' === $cover_mode) { $cover_label = __('تصویر پویش، الزامی', 'hamnoor'); }
        elseif ('public' === $cover_mode) { $cover_label = __('تصویر پویش، الزامی برای پویش عمومی', 'hamnoor'); }
        $has_default_cover = !empty($settings['default_khatm_image_url']);
        $cover_help = __('ابعاد پیشنهادی تصویر: ۱۲۰۰ در ۶۳۰ پیکسل. بهتر است تصویر افقی و واضح باشد.', 'hamnoor') . ' ' . ($has_default_cover ? __('اگر تصویری انتخاب نکنید، تصویر پیش‌فرض سایت برای پویش استفاده می‌شود.', 'hamnoor') : __('اگر تصویری انتخاب نکنید، طرح پیش‌فرض افزونه نمایش داده می‌شود.', 'hamnoor'));
        $immutable_note = __('توجه: نوع تقسیم‌بندی و تعداد ختم کامل پس از ایجاد قابل تغییر نیست. لطفاً پیش از رفتن به مرحله بعد، این موارد را با دقت انتخاب کنید.', 'hamnoor');
        $immutable_edit_note = __('نوع تقسیم‌بندی و تعداد ختم کامل پس از ایجاد قابل تغییر نیستند.', 'hamnoor');
        $edit_locked_attr = $editing ? ' readonly aria-readonly="true"' : '';
        $edit_disabled_attr = $editing ? ' disabled aria-disabled="true"' : '';
        ob_start();
        echo '<div class="hamnoor-card hamnoor-create-card"><h2>' . esc_html($editing ? __('ویرایش پویش', 'hamnoor') : Hamnoor_Texts::get('create_form_title')) . '</h2>';
        if ($editing) {
            $impact_progress = Hamnoor_DB::get_progress((int) $khatm->id);
            $impact_count = (int) $impact_progress['claimed'] + (int) $impact_progress['done'];
            if ($impact_count > 0) {
                echo '<div class="hamnoor-notice hamnoor-notice-warning"><strong>' . esc_html(sprintf(__('این پویش %d سهم انتخاب‌شده یا تکمیل‌شده دارد.', 'hamnoor'), $impact_count)) . '</strong> ' . esc_html__('تغییر مهلت، شیوه آزادسازی، شیوه تکمیل یا حریم خصوصی می‌تواند بر مشارکت‌کنندگان موجود اثر بگذارد؛ در صورت تغییر این موارد، هم‌نور به کاربران متصل اطلاع می‌دهد.', 'hamnoor') . '</div>';
            }
        }
        $draft_scope = $editing ? (int) $khatm->id : 0;
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-form hamnoor-create-form hamnoor-wizard" novalidate data-current-step="1" data-draft-user="' . (int) Hamnoor_Mobile_Access::current_user_id() . '" data-draft-scope="' . (int) $draft_scope . '" data-max-khatm-count="' . (int) $max_khatm_count . '" data-default-active-claim-limit="' . (int) $active_claim_limit_default . '" data-max-repeat-count="' . (int) $max_repeat_count . '" data-hard-limit="' . (int) $hard_limit . '" data-max-khatm-message="' . esc_attr($max_count_message) . '" data-max-repeat-message="' . esc_attr($max_repeat_message) . '">';
        wp_nonce_field($action . ($editing ? '_' . (int) $khatm->id : ''));
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">'; if ($editing) { echo '<input type="hidden" name="khatm_id" value="' . (int) $khatm->id . '">'; }
        echo '<input type="hidden" name="owner_name" value="' . esc_attr($owner_name) . '"><input type="hidden" name="owner_phone" value="' . esc_attr($owner_phone) . '">';
        echo '<div class="hamnoor-wizard-steps" aria-label="' . esc_attr__('مراحل ایجاد پویش', 'hamnoor') . '"><span data-step-title="' . esc_attr__('اطلاعات', 'hamnoor') . '">۱ <b>' . esc_html__('اطلاعات', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('نوع پویش', 'hamnoor') . '">۲ <b>' . esc_html__('نوع پویش', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('انتشار', 'hamnoor') . '">۳ <b>' . esc_html__('انتشار', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('تنظیمات قرائت', 'hamnoor') . '">۴ <b>' . esc_html__('تنظیمات قرائت', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('تأیید', 'hamnoor') . '">۵ <b>' . esc_html__('تأیید', 'hamnoor') . '</b></span></div><div class="hamnoor-current-step-title" aria-live="polite"></div>';
        echo '<div class="hamnoor-form-summary" aria-live="polite" hidden></div>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="1"><legend>' . esc_html__('۱. اطلاعات اصلی پویش', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('در این بخش عنوان، نیت و توضیح پویش را وارد می‌کنید. این اطلاعات به شرکت‌کنندگان کمک می‌کند بدانند پویش برای چه نیتی برگزار می‌شود.', 'hamnoor') . '</p>' . $this->examples_box(Hamnoor_Texts::get('wizard_basic_examples'));
        echo '<label class="' . esc_attr($field_class('title')) . '">' . esc_html__('عنوان پویش', 'hamnoor') . '<input required maxlength="191" type="text" name="title" value="' . esc_attr($title) . '">' . $field_error('title') . '</label>';
        echo '<label>' . esc_html__('نیت پویش', 'hamnoor') . '<textarea name="intention">' . esc_textarea($intention) . '</textarea></label><label>' . esc_html__('توضیحات', 'hamnoor') . '<textarea name="description">' . esc_textarea($description) . '</textarea></label>';
        if ($editing && !empty($khatm->cover_url)) { echo '<div class="hamnoor-current-cover"><img class="hamnoor-cover" src="' . esc_url($khatm->cover_url) . '" alt="' . esc_attr($khatm->title) . '"><p class="hamnoor-help">' . esc_html__('اگر تصویر جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.', 'hamnoor') . '</p><label class="hamnoor-choice"><input type="checkbox" name="remove_cover" value="1"><span class="hamnoor-choice-text">' . esc_html__('حذف تصویر فعلی', 'hamnoor') . '</span></label></div>'; }
        echo '<label class="' . esc_attr($field_class('cover_file')) . '">' . esc_html($cover_label) . '<input type="file" name="cover_file" accept="image/jpeg,image/png,image/webp,image/gif"><span class="hamnoor-help">' . esc_html($cover_help) . '</span>' . $field_error('cover_file') . '</label></fieldset>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="2"><legend>' . esc_html__('۲. نوع پویش و تقسیم‌بندی', 'hamnoor') . '</legend>';
        if ($editing) { echo '<label>' . esc_html__('نوع پویش', 'hamnoor') . '<input readonly type="text" value="' . esc_attr(Hamnoor_Helpers::status_label($type, 'type')) . '"><input type="hidden" name="khatm_type" value="' . esc_attr($type) . '"></label>'; }
        else {
            echo '<div class="hamnoor-type-picker ' . esc_attr($field_class('khatm_type')) . '" role="radiogroup" aria-labelledby="hamnoor-type-picker-title">';
            echo '<div class="hamnoor-type-picker-head"><strong id="hamnoor-type-picker-title">' . esc_html__('پویش را چگونه تقسیم کنیم؟', 'hamnoor') . '</strong><span>' . esc_html__('یکی را انتخاب کنید؛ برای بیشتر پویش‌ها «صفحه‌ای» ساده‌ترین انتخاب است.', 'hamnoor') . '</span></div>';
            $type_cards = array(
                'juz' => array(__('جزء', 'hamnoor'), __('ساده و مناسب گروه‌های کوچک و خانوادگی', 'hamnoor'), __('هر نفر یک جزء می‌خواند؛ یک ختم کامل ۳۰ سهم دارد.', 'hamnoor'), '۳۰'),
                'page' => array(__('صفحه‌ای', 'hamnoor'), __('پیشنهادی برای بیشتر پویش‌ها', 'hamnoor'), __('هر نفر یک صفحه می‌خواند؛ یک ختم کامل ۶۰۴ سهم دارد.', 'hamnoor'), '۶۰۴'),
                'repeated_surah' => array(__('تکرار یک سوره', 'hamnoor'), __('برای پویش‌های ذکر یا سوره خاص', 'hamnoor'), __('یک سوره را انتخاب می‌کنید و تعداد تکرار را خودتان تعیین می‌کنید.', 'hamnoor'), __('دلخواه', 'hamnoor')),
                'surah' => array(__('سوره', 'hamnoor'), __('انتخاب بر اساس سوره‌ها', 'hamnoor'), __('هر نفر یک سوره می‌خواند؛ یک ختم کامل ۱۱۴ سهم دارد.', 'hamnoor'), '۱۱۴'),
            );
            foreach ($type_cards as $type_value => $type_card) {
                $recommended = 'juz' === $type_value ? '<span class="hamnoor-type-recommended">' . esc_html__('پیشنهاد هم‌نور', 'hamnoor') . '</span>' : '';
                echo '<label class="hamnoor-type-card"><input type="radio" name="khatm_type" value="' . esc_attr($type_value) . '"' . checked($type, $type_value, false) . '><span class="hamnoor-type-card-body"><span class="hamnoor-type-card-top"><strong>' . esc_html($type_card[0]) . '</strong>' . $recommended . '</span><small>' . esc_html($type_card[1]) . '</small><span class="hamnoor-type-card-desc">' . esc_html($type_card[2]) . '</span><span class="hamnoor-type-card-meta"><b>' . esc_html($type_card[3]) . '</b> ' . esc_html__('سهم پایه', 'hamnoor') . '</span></span></label>';
            }
            echo $field_error('khatm_type') . '</div>';
        }
        echo '<p class="hamnoor-help hamnoor-type-help">' . esc_html__('نوع تقسیم‌بندی فقط مشخص می‌کند هر «سهم» چه اندازه‌ای دارد؛ بعداً تعداد ختم یا تکرار را تعیین می‌کنید.', 'hamnoor') . '</p>';
        echo $this->examples_box(Hamnoor_Texts::get('wizard_type_examples')) . '<div class="hamnoor-notice hamnoor-notice-warning hamnoor-immutable-warning">' . esc_html($editing ? $immutable_edit_note : $immutable_note) . '</div>';
        if ($editing) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('نوع تقسیم‌بندی و تعداد ختم کامل پس از ایجاد قابل تغییر نیستند.', 'hamnoor') . '</div>'; }
        echo '<div class="hamnoor-khatm-count-fields"><label class="' . esc_attr($field_class('khatm_count')) . '">' . esc_html__('چند ختم کامل برگزار می‌کنید؟', 'hamnoor') . '<input type="number" min="1" max="' . (int) $max_khatm_count . '" name="khatm_count" value="' . esc_attr($khatm_count) . '"' . ($editing ? ' readonly' : '') . '><span class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('khatm_count_help', array('MAX' => $max_khatm_count))) . '</span>' . $field_error('khatm_count') . '</label></div>';
        echo '<div class="hamnoor-repeated-surah-fields"><label class="hamnoor-surah-picker ' . esc_attr($field_class('selected_surah_number')) . '">' . esc_html__('انتخاب سوره', 'hamnoor') . '<input type="search" class="hamnoor-surah-search" data-hamnoor-surah-select="hamnoor-selected-surah" aria-controls="hamnoor-selected-surah" autocomplete="off" placeholder="' . esc_attr__('جستجو با نام فارسی، عربی یا انگلیسی سوره', 'hamnoor') . '"' . $edit_disabled_attr . '><select id="hamnoor-selected-surah" name="selected_surah_number"' . $edit_disabled_attr . '>';
        foreach (Hamnoor_Helpers::surahs() as $num => $surah) {
            $search_terms = implode(' ', array($surah['name_fa'], $surah['name_ar'], $surah['slug'], 'سوره ' . $surah['name_fa']));
            echo '<option value="' . (int) $num . '" data-search="' . esc_attr($search_terms) . '"' . selected($selected_surah, $num, false) . '>' . esc_html($num . ' - سوره ' . $surah['name_fa']) . '</option>';
        }
        echo '</select><span class="hamnoor-help">' . esc_html__('نام سوره را تایپ کنید، سپس سوره موردنظر را از فهرست انتخاب کنید.', 'hamnoor') . '</span>' . $field_error('selected_surah_number') . '</label><label class="' . esc_attr($field_class('repeat_count')) . '">' . esc_html__('تعداد تکرار', 'hamnoor') . '<input type="number" min="1" max="' . (int) $max_repeat_count . '" name="repeat_count" value="' . esc_attr($repeat_count) . '"' . $edit_locked_attr . '><span class="hamnoor-help">' . esc_html(sprintf(__('حداکثر تعداد مجاز برای این پویش: %d مرتبه', 'hamnoor'), $max_repeat_count)) . '</span>' . $field_error('repeat_count') . '</label>';
        if ($can_manage_limits) { echo '<label>' . esc_html__('حد اختصاصی تکرار برای این پویش، اختیاری', 'hamnoor') . '<input type="number" min="1" max="12000" name="max_repeat_count" value="' . esc_attr($max_repeat_override ? $max_repeat_override : '') . '"><span class="hamnoor-help">' . esc_html__('اگر خالی باشد، مقدار عمومی تنظیمات استفاده می‌شود. این گزینه فقط برای مدیر سایت قابل تغییر است.', 'hamnoor') . '</span></label>'; }
        echo $repeat_contact_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped settings above.
        echo '</div><div class="hamnoor-live-summary" aria-live="polite"><strong>' . esc_html__('تعداد تقریبی سهم‌ها:', 'hamnoor') . '</strong> <span data-summary="total_parts">' . esc_html($parts_count_preview) . '</span></div></fieldset>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="3"><legend>' . esc_html__('۳. انتشار', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('wizard_publish_desc')) . '</p><label>' . esc_html__('نوع دسترسی', 'hamnoor') . '<select name="privacy"><option value="public"' . selected($privacy, 'public', false) . '>' . esc_html__('عمومی', 'hamnoor') . '</option><option value="private"' . selected($privacy, 'private', false) . '>' . esc_html__('خصوصی؛ فقط با لینک دعوت', 'hamnoor') . '</option></select></label><div class="hamnoor-google-visibility' . ('private' === $privacy ? ' is-private' : '') . '" data-google-visibility><input type="hidden" name="indexable" value="0"><label class="hamnoor-choice"><input type="checkbox" name="indexable" value="1"' . checked(('private' === $privacy ? 0 : (int)$indexable), 1, false) . disabled('private' === $privacy, true, false) . '><span class="hamnoor-choice-text">' . esc_html__('نمایش در نتایج گوگل', 'hamnoor') . '</span></label><p class="hamnoor-help" data-google-visibility-help>' . esc_html('private' === $privacy ? __('پویش خصوصی در گوگل نمایش داده نمی‌شود و فقط با لینک دعوت قابل دسترسی است.', 'hamnoor') : Hamnoor_Texts::get('google_visibility_desc')) . '</p></div><details class="hamnoor-schedule-options"' . (($starts_j || $ends_j) ? ' open' : '') . '><summary>' . esc_html(Hamnoor_Texts::get('schedule_section_title')) . '</summary><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('schedule_section_help')) . '</p><label class="' . esc_attr($field_class('starts_at')) . '">' . esc_html(Hamnoor_Texts::get('participation_start_label')) . '<input id="hamnoor-starts-at" class="hamnoor-date-input hamnoor-jalali-input" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" name="starts_at_jalali" value="' . esc_attr($starts_j) . '" placeholder="1405/01/01 08:00"><span class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('participation_start_help')) . '</span><span class="hamnoor-schedule-field-actions"><button type="button" class="hamnoor-link-button" data-schedule-clear="starts_at_jalali">' . esc_html__('از زمان انتشار', 'hamnoor') . '</button></span>' . $field_error('starts_at') . '</label><label class="' . esc_attr($field_class('ends_at')) . '">' . esc_html(Hamnoor_Texts::get('participation_end_label')) . '<input id="hamnoor-ends-at" class="hamnoor-date-input hamnoor-jalali-input" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" name="ends_at_jalali" value="' . esc_attr($ends_j) . '" placeholder="1405/01/01 08:00"><span class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('participation_end_help')) . '</span><span class="hamnoor-schedule-field-actions"><button type="button" class="hamnoor-link-button" data-schedule-clear="ends_at_jalali">' . esc_html__('بدون پایان', 'hamnoor') . '</button></span>' . $field_error('ends_at') . '</label><label class="hamnoor-completion-grace ' . esc_attr($field_class('completion_grace_days')) . '">' . esc_html(Hamnoor_Texts::get('completion_grace_label')) . '<input type="number" min="0" max="3650" name="completion_grace_days" value="' . esc_attr($completion_grace_days) . '" placeholder="' . esc_attr__('خالی = بدون محدودیت', 'hamnoor') . '"><span class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('completion_grace_help')) . '</span>' . $field_error('completion_grace_days') . '</label></details></fieldset>';
        $reading_advanced_open = ($max_active_claims !== $active_claim_limit_default || 'none' !== $auto_release_mode || $auto_release_after_days > 0);
        echo '<fieldset class="hamnoor-wizard-step" data-step="4"><legend>' . esc_html__('۴. تنظیمات قرائت', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('برای بیشتر پویش‌ها تنظیم پیشنهادی هم‌نور مناسب است: شرکت‌کننده پس از خواندن سهم، پایان قرائت را ثبت می‌کند.', 'hamnoor') . '</p><div class="hamnoor-completion-options"><h3>' . esc_html__('ثبت پایان قرائت', 'hamnoor') . '</h3><label class="hamnoor-choice hamnoor-choice-recommended"><input type="radio" name="completion_mode" value="manual_done"' . checked($completion_mode, 'manual_done', false) . '><span class="hamnoor-choice-text"><strong>' . esc_html__('بعد از خواندن، شرکت‌کننده تأیید کند', 'hamnoor') . '</strong><small>' . esc_html__('پیشنهادی؛ پیشرفت پویش فقط بعد از قرائت واقعی ثبت می‌شود.', 'hamnoor') . '</small></span><span class="hamnoor-choice-badge">' . esc_html__('پیشنهادی', 'hamnoor') . '</span></label><label class="hamnoor-choice"><input type="radio" name="completion_mode" value="auto_done_on_claim"' . checked($completion_mode, 'auto_done_on_claim', false) . '><span class="hamnoor-choice-text"><strong>' . esc_html__('همان انتخاب سهم، انجام‌شده محسوب شود', 'hamnoor') . '</strong><small>' . esc_html__('برای پویش‌هایی که فقط تقسیم سهم مهم است و ثبت پایان جداگانه لازم نیست.', 'hamnoor') . '</small></span></label></div><details class="hamnoor-reading-advanced"' . ($reading_advanced_open ? ' open' : '') . '><summary><span><strong>' . esc_html__('تنظیمات پیشرفته', 'hamnoor') . '</strong><small>' . esc_html__('اختیاری؛ سقف سهم‌های ناتمام و آزادسازی خودکار', 'hamnoor') . '</small></span><span class="hamnoor-advanced-status" data-advanced-status>' . esc_html($reading_advanced_open ? __('تنظیم سفارشی', 'hamnoor') : __('پیش‌فرض هم‌نور', 'hamnoor')) . '</span></summary><div class="hamnoor-reading-advanced-body"><div class="hamnoor-claim-limit-options"><h3>' . esc_html(Hamnoor_Texts::get('active_claim_limit_title')) . '</h3><label>' . esc_html(Hamnoor_Texts::get('active_claim_limit_label')) . '<input type="number" min="1" max="50" name="max_active_claims_per_participant" value="' . esc_attr($max_active_claims) . '"></label><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('active_claim_limit_help')) . '</p></div><div class="hamnoor-release-options"><h3>' . esc_html(Hamnoor_Texts::get('release_policy_title')) . '</h3><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('release_policy_help')) . '</p><label>' . esc_html(Hamnoor_Texts::get('release_policy_title')) . '<select name="auto_release_mode"><option value="none"' . selected($auto_release_mode, 'none', false) . '>' . esc_html(Hamnoor_Texts::get('release_keep_until_manual')) . '</option><option value="after_days"' . selected($auto_release_mode, 'after_days', false) . '>' . esc_html(Hamnoor_Texts::get('release_after_days_option')) . '</option></select></label><label class="hamnoor-release-after-days ' . esc_attr($field_class('auto_release_after_days')) . '">' . esc_html(Hamnoor_Texts::get('release_after_days_label')) . '<input type="number" min="1" max="3650" name="auto_release_after_days" value="' . esc_attr($auto_release_after_days) . '">' . $field_error('auto_release_after_days') . '</label></div></div></details></fieldset>';
        echo '<fieldset class="hamnoor-wizard-step" data-step="5"><legend>' . esc_html__('۵. تأیید و ایجاد', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('پیش از ثبت، خلاصه تنظیمات را بررسی کنید. برای اصلاح هر بخش، همان‌جا روی «ویرایش» بزنید.', 'hamnoor') . '</p><div class="hamnoor-summary-card hamnoor-summary-groups">';
        echo '<section class="hamnoor-summary-group"><header><div><span class="hamnoor-summary-step">' . esc_html__('مرحله ۱', 'hamnoor') . '</span><h3>' . esc_html__('اطلاعات اصلی', 'hamnoor') . '</h3></div><button type="button" class="hamnoor-link-button" data-go-step="1">' . esc_html__('ویرایش', 'hamnoor') . '</button></header><dl><div><dt>' . esc_html__('عنوان', 'hamnoor') . '</dt><dd data-summary="title">' . esc_html($title) . '</dd></div><div><dt>' . esc_html__('ایجادکننده', 'hamnoor') . '</dt><dd data-summary="owner_name">' . esc_html($owner_name) . '</dd></div></dl></section>';
        echo '<section class="hamnoor-summary-group"><header><div><span class="hamnoor-summary-step">' . esc_html__('مرحله ۲', 'hamnoor') . '</span><h3>' . esc_html__('نوع و تقسیم‌بندی', 'hamnoor') . '</h3></div><button type="button" class="hamnoor-link-button" data-go-step="2">' . esc_html__('ویرایش', 'hamnoor') . '</button></header><dl><div><dt>' . esc_html__('نوع پویش', 'hamnoor') . '</dt><dd><span data-summary="type">' . esc_html(Hamnoor_Helpers::status_label($type, 'type')) . '</span><small class="hamnoor-immutable-reminder">' . esc_html__('پس از ایجاد قابل تغییر نیست.', 'hamnoor') . '</small></dd></div><div><dt>' . esc_html__('تعداد ختم کامل', 'hamnoor') . '</dt><dd><span data-summary="khatm_count">' . esc_html($khatm_count) . '</span><small class="hamnoor-immutable-reminder">' . esc_html__('پس از ایجاد قابل تغییر نیست.', 'hamnoor') . '</small></dd></div><div><dt>' . esc_html__('تعداد کل سهم‌ها', 'hamnoor') . '</dt><dd data-summary="total_parts">' . esc_html($parts_count_preview) . '</dd></div></dl></section>';
        echo '<section class="hamnoor-summary-group"><header><div><span class="hamnoor-summary-step">' . esc_html__('مرحله ۳', 'hamnoor') . '</span><h3>' . esc_html__('انتشار و دسترسی', 'hamnoor') . '</h3></div><button type="button" class="hamnoor-link-button" data-go-step="3">' . esc_html__('ویرایش', 'hamnoor') . '</button></header><dl><div><dt>' . esc_html__('دسترسی', 'hamnoor') . '</dt><dd data-summary="privacy">' . esc_html('public' === $privacy ? __('عمومی', 'hamnoor') : __('خصوصی', 'hamnoor')) . '</dd></div><div><dt>' . esc_html(Hamnoor_Texts::get('participation_start_label')) . '</dt><dd data-summary="starts_at">' . esc_html($starts_j ? $starts_j : __('از زمان انتشار', 'hamnoor')) . '</dd></div><div><dt>' . esc_html(Hamnoor_Texts::get('participation_end_label')) . '</dt><dd data-summary="ends_at">' . esc_html($ends_j ? $ends_j : __('بدون محدودیت', 'hamnoor')) . '</dd></div><div><dt>' . esc_html(Hamnoor_Texts::get('completion_grace_label')) . '</dt><dd data-summary="completion_grace_days">' . esc_html($ends_j ? ('' === (string)$completion_grace_days ? __('بدون محدودیت', 'hamnoor') : sprintf(__('%d روز', 'hamnoor'), (int)$completion_grace_days)) : __('نامرتبط؛ پایان پذیرش تعیین نشده', 'hamnoor')) . '</dd></div><div><dt>' . esc_html__('نمایش در گوگل', 'hamnoor') . '</dt><dd data-summary="indexable">' . esc_html((int)$indexable ? __('بله', 'hamnoor') : __('خیر', 'hamnoor')) . '</dd></div></dl></section>';
        echo '<section class="hamnoor-summary-group"><header><div><span class="hamnoor-summary-step">' . esc_html__('مرحله ۴', 'hamnoor') . '</span><h3>' . esc_html__('تنظیمات قرائت', 'hamnoor') . '</h3></div><button type="button" class="hamnoor-link-button" data-go-step="4">' . esc_html__('ویرایش', 'hamnoor') . '</button></header><dl><div><dt>' . esc_html__('ثبت پایان قرائت', 'hamnoor') . '</dt><dd data-summary="completion_mode">' . esc_html('auto_done_on_claim' === $completion_mode ? __('خیر؛ با انتخاب سهم انجام‌شده محسوب می‌شود', 'hamnoor') : __('بله؛ پس از قرائت باید ثبت شود', 'hamnoor')) . '</dd></div><div><dt>' . esc_html(Hamnoor_Texts::get('active_claim_limit_label')) . '</dt><dd data-summary="max_active_claims_per_participant">' . esc_html($max_active_claims) . '</dd></div><div><dt>' . esc_html(Hamnoor_Texts::get('release_policy_title')) . '</dt><dd data-summary="auto_release_mode">' . esc_html('none' === $auto_release_mode ? Hamnoor_Texts::get('release_keep_until_manual') : Hamnoor_Texts::get('release_after_days_option')) . '</dd></div></dl></section>';
        echo '</div>' . $support_html . '</fieldset>';
        if ($requires_review) { echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-review-note">' . esc_html__('پس از ثبت پویش، درخواست شما برای بررسی مدیر سایت ارسال می‌شود. مدیر سایت می‌تواند پیش از انتشار، تصویر یا اطلاعات لازم پویش را اصلاح کند. پس از انتشار، از طریق داشبورد به شما اطلاع‌رسانی خواهد شد.', 'hamnoor') . '</div>'; }
        echo '<div class="hamnoor-wizard-nav"><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-wizard-prev>' . esc_html__('مرحله قبل', 'hamnoor') . '</button><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-wizard-next>' . esc_html__('مرحله بعد', 'hamnoor') . '</button><button type="submit" class="hamnoor-btn hamnoor-btn-primary" data-wizard-submit>' . esc_html($editing ? __('ذخیره تغییرات', 'hamnoor') : ($requires_review ? __('ثبت و ارسال برای بررسی مدیر', 'hamnoor') : __('ایجاد و انتشار پویش', 'hamnoor'))) . '</button></div>';
        echo '</form></div>';
        $form_html = ob_get_clean();
        ob_start(); $this->render_template('frontend/create-khatm.php', array('form_html' => $form_html, 'editing' => $editing)); return ob_get_clean();
    }

    private function examples_box($text) {
        $text = trim((string) $text);
        if ('' === $text) { return ''; }
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)));
        $html = '<details class="hamnoor-examples"><summary>' . esc_html__('مثال‌ها و راهنمای بیشتر', 'hamnoor') . '</summary><ul>';
        foreach ($lines as $line) { $html .= '<li>' . esc_html($line) . '</li>'; }
        return $html . '</ul></details>';
    }

    private function render_front_participants($khatm_id) {
        $rows = Hamnoor_DB::get_participants($khatm_id);
        $html = '<div class="hamnoor-card"><h2>' . esc_html__('شرکت‌کنندگان پویش', 'hamnoor') . '</h2>';
        if (!$rows) { return $html . '<p class="hamnoor-empty">' . esc_html__('هنوز شرکت‌کننده‌ای ثبت نشده است.', 'hamnoor') . '</p></div>'; }
        $html .= '<div class="hamnoor-mobile-cards">';
        foreach ($rows as $row) { $html .= '<div class="hamnoor-row-card"><strong>' . esc_html($row->name) . '</strong><span>' . esc_html(Hamnoor_Helpers::mask_phone($row->phone)) . '</span><small>' . esc_html(sprintf(__('سهم‌ها: %d / خوانده‌شده: %d', 'hamnoor'), (int)$row->total_parts, (int)$row->done_parts)) . '</small></div>'; }
        return $html . '</div></div>';
    }

    private function success_panel($khatm) {
        $url = Hamnoor_Helpers::invite_url($khatm);
        $share_url = Hamnoor_Helpers::share_invite_url($khatm, 'clipboard');
        $dashboard_url = Hamnoor_Helpers::page_url('dashboard');
        if ('pending_review' === (string) $khatm->status) {
            return '<div class="hamnoor-card hamnoor-success-panel hamnoor-success-panel-guided"><div class="hamnoor-success-hero"><span class="hamnoor-success-mark" aria-hidden="true">✓</span><div><h2>' . esc_html__('درخواست پویش ثبت شد', 'hamnoor') . '</h2><p>' . esc_html__('اطلاعات شما محفوظ است و اکنون پویش وارد مرحله بررسی مدیر شده است.', 'hamnoor') . '</p></div></div><ol class="hamnoor-success-journey" aria-label="' . esc_attr__('مراحل بعد از ثبت پویش', 'hamnoor') . '"><li class="is-done"><span>۱</span><div><strong>' . esc_html__('ثبت درخواست', 'hamnoor') . '</strong><small>' . esc_html__('انجام شد', 'hamnoor') . '</small></div></li><li class="is-current"><span>۲</span><div><strong>' . esc_html__('بررسی مدیر', 'hamnoor') . '</strong><small>' . esc_html__('در حال انتظار؛ نتیجه در داشبورد نمایش داده می‌شود.', 'hamnoor') . '</small></div></li><li><span>۳</span><div><strong>' . esc_html__('انتشار و دعوت', 'hamnoor') . '</strong><small>' . esc_html__('پس از انتشار، لینک دعوت فعال می‌شود.', 'hamnoor') . '</small></div></li></ol><div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('فعلاً کاری لازم نیست. اگر مدیر درخواست اصلاح داشته باشد، همان‌جا در داشبورد مشخص می‌شود.', 'hamnoor') . '</div><div class="hamnoor-action-buttons"><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url($dashboard_url) . '">' . esc_html__('پیگیری در داشبورد', 'hamnoor') . '</a><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url($url) . '">' . esc_html__('پیش‌نمایش پویش', 'hamnoor') . '</a></div></div>';
        }
        $share = Hamnoor_Helpers::share_text($khatm, null, 'clipboard');
        return '<div class="hamnoor-card hamnoor-success-panel hamnoor-success-panel-guided"><div class="hamnoor-success-hero"><span class="hamnoor-success-mark" aria-hidden="true">✓</span><div><h2>' . esc_html__('پویش آماده دعوت است', 'hamnoor') . '</h2><p>' . esc_html__('ساخت پویش تمام شد. حالا لینک را برای اولین همراهان بفرستید.', 'hamnoor') . '</p></div></div><ol class="hamnoor-success-journey" aria-label="' . esc_attr__('مراحل شروع پویش', 'hamnoor') . '"><li class="is-done"><span>۱</span><div><strong>' . esc_html__('پویش ساخته شد', 'hamnoor') . '</strong><small>' . esc_html__('انجام شد', 'hamnoor') . '</small></div></li><li class="is-current"><span>۲</span><div><strong>' . esc_html__('لینک را به دیگران بفرستید', 'hamnoor') . '</strong><small>' . esc_html__('همین حالا بهترین قدم بعدی است.', 'hamnoor') . '</small></div></li><li><span>۳</span><div><strong>' . esc_html__('اولین مشارکت را ببینید', 'hamnoor') . '</strong><small>' . esc_html__('پیشرفت و شرکت‌کنندگان در داشبورد قابل مشاهده‌اند.', 'hamnoor') . '</small></div></li></ol><div class="hamnoor-share-box hamnoor-success-share-box"><input readonly value="' . esc_attr($share_url) . '" aria-label="' . esc_attr__('لینک دعوت پویش', 'hamnoor') . '"><button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-copy">' . esc_html__('کپی لینک دعوت', 'hamnoor') . '</button></div><div class="hamnoor-share-actions hamnoor-success-actions" data-hamnoor-clipboard="' . esc_attr($share) . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy-invite">' . esc_html__('کپی متن دعوت', 'hamnoor') . '</button><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url($url) . '">' . esc_html__('مشاهده صفحه پویش', 'hamnoor') . '</a><a class="hamnoor-link-button" href="' . esc_url($dashboard_url) . '">' . esc_html__('رفتن به داشبورد', 'hamnoor') . '</a></div></div>';
    }

    private function render_template($template, $vars = array()) {
        $file = Hamnoor_Helpers::locate_template($template);
        if (!$file) { return; }
        if (is_array($vars)) { extract($vars, EXTR_SKIP); }
        include $file;
    }

    public function wrap($html, $class = '') { return $this->wrap_public($html, $class); }
    public function wrap_public($html, $class = '', $style = '') { return '<div class="hamnoor-wrap ' . esc_attr($this->class_list(Hamnoor_Helpers::visual_mode_class(), $class)) . '" dir="rtl"' . ($style ? ' style="' . esc_attr($style) . '"' : '') . '>' . $html . '</div>'; }
    private function notice($message, $type = 'info') { return '<div class="hamnoor-notice hamnoor-notice-' . esc_attr($type) . '">' . wp_kses_post($message) . '</div>'; }
    private function class_list($base, $extra = '') { $classes = array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim($base . ' ' . $extra)))); return implode(' ', array_unique($classes)); }
    private function khatm_draft_key($user_id, $scope = 0) {
        return 'hamnoor_khatm_draft_' . absint($user_id) . '_' . absint($scope);
    }

    private function delete_khatm_draft($user_id, $scope = 0) {
        $user_id = absint($user_id);
        if (!$user_id) { return false; }
        return delete_transient($this->khatm_draft_key($user_id, $scope));
    }

    private function validate_draft_scope($user_id, $scope) {
        $scope = absint($scope);
        if (!$scope) { return true; }
        return Hamnoor_DB::user_can_manage_khatm($scope, (int)$user_id);
    }

    public function ajax_save_khatm_draft() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { wp_send_json_error(array('message'=>__('برای ذخیره پیش‌نویس، نشست هم‌نور باید فعال باشد.', 'hamnoor')), 401); }
        $scope = isset($_POST['scope']) ? absint($_POST['scope']) : 0;
        if (!$this->validate_draft_scope($user_id, $scope)) { wp_send_json_error(array('message'=>__('دسترسی به این پیش‌نویس مجاز نیست.', 'hamnoor')), 403); }
        $raw_json = isset($_POST['draft']) ? wp_unslash((string)$_POST['draft']) : '';
        $raw = json_decode($raw_json, true);
        if (!is_array($raw)) { wp_send_json_error(array('message'=>__('داده پیش‌نویس معتبر نیست.', 'hamnoor')), 422); }
        // Sanitize through the same canonical path used for campaign creation.
        $data = Hamnoor_DB::sanitize_khatm_data($raw);
        // Keep harmless UI-only raw Jalali strings and the last wizard step.
        foreach (array('starts_at_jalali','ends_at_jalali') as $field) {
            if (isset($raw[$field])) { $data[$field] = sanitize_text_field(Hamnoor_Validator::normalize_digits((string)$raw[$field])); }
        }
        $step = isset($_POST['step']) ? max(1, min(6, absint($_POST['step']))) : 1;
        $payload = array('data'=>$data, 'step'=>$step, 'updated_at'=>current_time('mysql'));
        set_transient($this->khatm_draft_key($user_id, $scope), $payload, 14 * DAY_IN_SECONDS);
        wp_send_json_success(array('saved'=>1, 'updated_at'=>$payload['updated_at']));
    }

    public function ajax_get_khatm_draft() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { wp_send_json_error(array('message'=>__('نشست هم‌نور فعال نیست.', 'hamnoor')), 401); }
        $scope = isset($_POST['scope']) ? absint($_POST['scope']) : 0;
        if (!$this->validate_draft_scope($user_id, $scope)) { wp_send_json_error(array('message'=>__('دسترسی به این پیش‌نویس مجاز نیست.', 'hamnoor')), 403); }
        $payload = get_transient($this->khatm_draft_key($user_id, $scope));
        wp_send_json_success(array('draft'=>is_array($payload) ? $payload : null));
    }

    public function ajax_delete_khatm_draft() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { wp_send_json_error(array('message'=>__('نشست هم‌نور فعال نیست.', 'hamnoor')), 401); }
        $scope = isset($_POST['scope']) ? absint($_POST['scope']) : 0;
        if (!$this->validate_draft_scope($user_id, $scope)) { wp_send_json_error(array('message'=>__('دسترسی به این پیش‌نویس مجاز نیست.', 'hamnoor')), 403); }
        $this->delete_khatm_draft($user_id, $scope);
        wp_send_json_success(array('deleted'=>1));
    }

    public function ajax_mark_notifications_read() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { wp_send_json_error(array('message' => __('نشست داشبورد معتبر نیست.', 'hamnoor'))); }
        Hamnoor_DB::mark_notifications_read($user_id);
        wp_send_json_success(array('unread' => 0));
    }

    private function form_errors_from_wp_error($error) {
        if (!is_wp_error($error)) { return array(); }
        $map = array('hamnoor_title_required'=>'title','hamnoor_title_long'=>'title','hamnoor_mobile_required'=>'owner_phone','hamnoor_mobile_invalid'=>'owner_phone','hamnoor_date_order'=>'ends_at','hamnoor_date_format_invalid'=>'starts_at','hamnoor_surah_invalid'=>'selected_surah_number','hamnoor_repeat_invalid'=>'repeat_count','hamnoor_type_invalid'=>'khatm_type','hamnoor_khatm_count_invalid'=>'khatm_count','hamnoor_parts_too_many'=>'khatm_count','hamnoor_cover_size'=>'cover_file','hamnoor_cover_type'=>'cover_file','hamnoor_cover_upload'=>'cover_file','hamnoor_cover_required'=>'cover_file','hamnoor_auto_release_days_invalid'=>'auto_release_after_days','hamnoor_completion_grace_invalid'=>'completion_grace_days','hamnoor_active_claim_limit_invalid'=>'max_active_claims_per_participant','hamnoor_date_format_invalid_end'=>'ends_at');
        $errors = array();
        foreach ($error->get_error_codes() as $code) { $data = $error->get_error_data($code); $field = is_array($data) && !empty($data['field']) ? sanitize_key($data['field']) : (isset($map[$code]) ? $map[$code] : sanitize_key($code)); if (!isset($errors[$field])) { $errors[$field] = $error->get_error_message($code); } }
        return $errors;
    }

    private function cover_required_error($data, $existing_khatm = null, $upload = array()) {
        $settings = Hamnoor_Helpers::settings();
        $mode = isset($settings['cover_required_mode']) ? $settings['cover_required_mode'] : 'optional';
        $privacy = isset($data['privacy']) ? (string) $data['privacy'] : 'public';
        $required = ('always' === $mode) || ('public' === $mode && 'public' === $privacy);
        if (!$required) { return false; }
        if (!empty($upload['cover_url'])) { return false; }
        if ($existing_khatm && !empty($existing_khatm->cover_url) && empty($data['remove_cover'])) { return false; }
        return new WP_Error('hamnoor_cover_required', __('تصویر پویش برای این حالت الزامی است.', 'hamnoor'), array('field' => 'cover_file'));
    }

    private function collect_khatm_data_with_cover($existing_khatm = null) {
        $data = Hamnoor_DB::sanitize_khatm_data($_POST);
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { return $upload; }
        $cover_error = $this->cover_required_error($data, $existing_khatm, is_array($upload) ? $upload : array());
        if (is_wp_error($cover_error)) { return $cover_error; }
        if ($upload) { $data = array_merge($data, $upload); }
        return $data;
    }

    public function handle_create_khatm() {
        if (!Hamnoor_Mobile_Access::is_logged_in() || !Hamnoor_Permissions::can_create_khatm()) { wp_die(esc_html__('برای ایجاد پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor')); }
        check_admin_referer('hamnoor_create_khatm');
        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) { wp_die(esc_html__('ایجاد پویش جدید در حال حاضر موقتاً غیرفعال است. لطفاً بعداً مراجعه کنید.', 'hamnoor')); }
        $data = $this->collect_khatm_data_with_cover();
        if (is_wp_error($data)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('create'), $data->get_error_message(), 'error', 'khatm_create', Hamnoor_DB::sanitize_khatm_data($_POST), $this->form_errors_from_wp_error($data)); }
        if (!empty($data['owner_phone'])) { $this->maybe_store_current_user_phone($data['owner_phone']); }
        $result = Hamnoor_DB::create_khatm($data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('create'), $result->get_error_message(), 'error', 'khatm_create', $data, $this->form_errors_from_wp_error($result)); }
        $created = Hamnoor_DB::get_khatm((int) $result);
        $this->delete_khatm_draft(Hamnoor_Mobile_Access::current_user_id(), 0);
        $message = $created && 'pending_review' === (string) $created->status ? __('درخواست پویش ثبت شد و برای بررسی مدیر سایت ارسال گردید.', 'hamnoor') : Hamnoor_Texts::get('create_success');
        Hamnoor_Mobile_Access::consume_create_access();
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('created_khatm', (int) $result, Hamnoor_Helpers::page_url('dashboard')), $message);
    }

    public function handle_update_khatm() {
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        $is_privileged_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        if (!$is_privileged_admin && !Hamnoor_Mobile_Access::has_sensitive_access()) { wp_die(esc_html__('برای مدیریت پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor')); }
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id)) { wp_die(esc_html__('دسترسی کافی ندارید.', 'hamnoor')); }
        check_admin_referer('hamnoor_update_khatm_' . $khatm_id);
        $existing = Hamnoor_DB::get_khatm($khatm_id);
        $owner_resubmission = (!$is_privileged_admin && $existing && 'needs_revision' === (string) $existing->status);
        $edit_url = add_query_arg('edit_khatm', $khatm_id, Hamnoor_Helpers::page_url('create'));
        $data = $this->collect_khatm_data_with_cover($existing);
        if (is_wp_error($data)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: $edit_url, $data->get_error_message(), 'error', 'khatm_edit', Hamnoor_DB::sanitize_khatm_data($_POST), $this->form_errors_from_wp_error($data)); }
        if (!empty($data['owner_phone'])) { $this->maybe_store_current_user_phone($data['owner_phone']); }
        $result = Hamnoor_DB::update_khatm($khatm_id, $data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: $edit_url, $result->get_error_message(), 'error', 'khatm_edit', $data, $this->form_errors_from_wp_error($result)); }

        if ($owner_resubmission) {
            $state_result = Hamnoor_Khatm_Service::change_status($khatm_id, 'pending_review', '', 'owner');
            if (is_wp_error($state_result)) {
                Hamnoor_Helpers::redirect_with_notice($edit_url, $state_result->get_error_message(), 'error');
            }
            $this->delete_khatm_draft(Hamnoor_Mobile_Access::current_user_id(), $khatm_id);
            Hamnoor_Helpers::redirect_with_notice(
                add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard')),
                __('تغییرات ذخیره شد و پویش دوباره برای بررسی مدیر سایت ارسال گردید.', 'hamnoor')
            );
        }

        $this->delete_khatm_draft(Hamnoor_Mobile_Access::current_user_id(), $khatm_id);
        Hamnoor_Helpers::redirect_with_notice(Hamnoor_Helpers::invite_url($existing), __('تغییرات پویش ذخیره شد.', 'hamnoor'));
    }

    private function claim_result($khatm, $data, $mode, $part_id = 0, $round = 0) {
        if ('random' === $mode) { return Hamnoor_Part_Service::claim_random((int) $khatm->id, $data, $round); }
        if ('next' === $mode) { return Hamnoor_Part_Service::claim_next((int) $khatm->id, $data, $round); }
        $part = Hamnoor_Part_Service::get($part_id);
        if (!$part || (int) $part->khatm_id !== (int) $khatm->id) { return new WP_Error('hamnoor_part_not_found', __('لطفاً یک سهم معتبر انتخاب کنید.', 'hamnoor')); }
        $token = Hamnoor_Part_Service::claim($part_id, $data);
        if (is_wp_error($token)) { return $token; }
        return array('part_id' => (int) $part_id, 'tracking_token' => $token);
    }

    public function ajax_claim_identity() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        $user = Hamnoor_Mobile_Access::current_user();
        $claim_khatm = null;
        if (!empty($_POST['khatm_token'])) { $claim_khatm = Hamnoor_Khatm_Service::get(Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token']))); }
        if ($user && Hamnoor_Mobile_Access::is_logged_in()) {
            wp_send_json_success(array(
                'logged_in' => true,
                'display_name' => sanitize_text_field((string) $user->display_name),
                'phone' => Hamnoor_Validator::normalize_phone((string) $user->phone),
                'quota_state' => $claim_khatm ? Hamnoor_Claim_Policy::quota_state((int)$user->id, $claim_khatm) : null,
            ));
        }

        wp_send_json_success(array(
            'logged_in' => false,
            'display_name' => '',
            'phone' => '',
            'quota_state' => null,
        ));
    }

    public function handle_owner_khatm_status() {
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        $target = isset($_POST['target_status']) ? sanitize_key(wp_unslash($_POST['target_status'])) : '';
        $redirect = add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
        if (!$khatm_id || !in_array($target, array('paused','active','cancelled'), true)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('درخواست مدیریت پویش معتبر نیست.', 'hamnoor'), 'error');
        }
        check_admin_referer('hamnoor_owner_khatm_status_' . $khatm_id);
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('دسترسی کافی برای مدیریت این پویش ندارید.', 'hamnoor'), 'error');
        }
        $is_privileged_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $lifecycle_settings = Hamnoor_Helpers::settings();
        if (!$is_privileged_admin) {
            if (in_array($target, array('paused','active'), true) && '1' !== (string)($lifecycle_settings['owner_pause_enabled'] ?? '1')) {
                Hamnoor_Helpers::redirect_with_notice($redirect, __('اختیار توقف موقت و ازسرگیری پویش برای سازندگان توسط مدیر سایت غیرفعال شده است.', 'hamnoor'), 'error');
            }
            if ('cancelled' === $target && '1' !== (string)($lifecycle_settings['owner_cancel_enabled'] ?? '1')) {
                Hamnoor_Helpers::redirect_with_notice($redirect, __('اختیار لغو پویش برای سازندگان توسط مدیر سایت غیرفعال شده است.', 'hamnoor'), 'error');
            }
        }
        if (!$is_privileged_admin && !Hamnoor_Mobile_Access::has_sensitive_access()) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('برای توقف، ازسرگیری یا لغو پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor'), 'error');
        }
        $khatm = Hamnoor_DB::get_khatm($khatm_id);
        if (!$khatm) { Hamnoor_Helpers::redirect_with_notice($redirect, __('پویش پیدا نشد.', 'hamnoor'), 'error'); }
        $status = (string) $khatm->status;
        $pause_source = isset($khatm->pause_source) ? sanitize_key((string) $khatm->pause_source) : '';

        if ('paused' === $target) {
            if ('active' !== $status) { Hamnoor_Helpers::redirect_with_notice($redirect, __('فقط پویش فعال را می‌توان موقتاً متوقف کرد.', 'hamnoor'), 'error'); }
            $result = Hamnoor_Khatm_Service::change_status($khatm_id, 'paused', '', 'owner');
            $message = Hamnoor_Texts::get('owner_pause_success');
        } elseif ('active' === $target) {
            if (!in_array($status, array('paused'), true)) { Hamnoor_Helpers::redirect_with_notice($redirect, __('این پویش در وضعیت توقف موقت نیست.', 'hamnoor'), 'error'); }
            if ('owner' !== $pause_source) { Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('admin_pause_resume_blocked'), 'error'); }
            $result = Hamnoor_Khatm_Service::change_status($khatm_id, 'active', '', 'owner');
            $message = Hamnoor_Texts::get('owner_resume_success');
        } else {
            if (!in_array($status, array('active','paused'), true)) { Hamnoor_Helpers::redirect_with_notice($redirect, __('این پویش در وضعیت قابل لغو توسط سازنده نیست.', 'hamnoor'), 'error'); }
            if ('paused' === $status && 'owner' !== $pause_source) { Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('admin_pause_resume_blocked'), 'error'); }
            $result = Hamnoor_Khatm_Service::change_status($khatm_id, 'cancelled', '', 'owner');
            $message = Hamnoor_Texts::get('owner_cancel_success');
        }

        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, $message, 'success');
    }

    public function handle_owner_participant_action() {
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_die(esc_html__('برای مدیریت مشارکت‌کنندگان، پنل هم‌نور خود را باز کنید.', 'hamnoor')); }
        $khatm_id = isset($_POST['khatm_id']) ? absint($_POST['khatm_id']) : 0;
        $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;
        $part_id = isset($_POST['part_id']) ? absint($_POST['part_id']) : 0;
        $operation = isset($_POST['participant_action']) ? sanitize_key(wp_unslash($_POST['participant_action'])) : '';
        check_admin_referer('hamnoor_owner_participant_' . $khatm_id . '_' . $participant_id);
        $redirect = add_query_arg(array('tab'=>'my-khatms','participants_khatm'=>$khatm_id), Hamnoor_Helpers::page_url('dashboard'));
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$khatm_id || !$participant_id || !Hamnoor_DB::user_can_manage_khatm($khatm_id, $user_id)) { Hamnoor_Helpers::redirect_with_notice($redirect, __('اجازه مدیریت این مشارکت‌کننده را ندارید.', 'hamnoor'), 'error'); }
        $participant = Hamnoor_DB::get_participant($participant_id);
        if (!$participant || (int)$participant->khatm_id !== $khatm_id) { Hamnoor_Helpers::redirect_with_notice($redirect, __('شرکت‌کننده معتبر نیست.', 'hamnoor'), 'error'); }
        if (!Hamnoor_Mobile_Access::has_sensitive_access($user_id)) {
            Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard')), __('برای مدیریت سهم‌های دیگران، ابتدا هویت این نشست را تأیید کنید.', 'hamnoor'), 'error');
        }
        if ('block' === $operation) {
            $result = Hamnoor_DB::set_participant_claim_blocked($participant_id, true, $user_id, __('توسط سازنده پویش', 'hamnoor'));
            $message = Hamnoor_Texts::get('participant_block_success');
        } elseif ('unblock' === $operation) {
            $result = Hamnoor_DB::set_participant_claim_blocked($participant_id, false, $user_id);
            $message = Hamnoor_Texts::get('participant_unblock_success');
        } elseif ('release_open' === $operation) {
            $result = Hamnoor_DB::release_participant_open_parts($participant_id, $user_id);
            $message = is_wp_error($result) ? '' : sprintf(Hamnoor_Texts::get('participant_release_open_success'), (int)$result);
        } elseif ('release_part' === $operation && $part_id) {
            $part = Hamnoor_DB::get_part($part_id);
            if (!$part || (int)$part->participant_id !== $participant_id || (int)$part->khatm_id !== $khatm_id) { $result = new WP_Error('hamnoor_participant_part_invalid', __('این سهم متعلق به شرکت‌کننده انتخاب‌شده نیست.', 'hamnoor')); }
            else { $result = Hamnoor_DB::release_part($part_id, 'owner_manage', $user_id); }
            $message = __('سهم انتخاب‌شده آزاد شد.', 'hamnoor');
        } else {
            $result = new WP_Error('hamnoor_participant_action_invalid', __('عملیات انتخاب‌شده معتبر نیست.', 'hamnoor'));
            $message = '';
        }
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, $message);
    }

    public function handle_thank_participants() {
        $khatm_id = isset($_POST['khatm_id']) ? absint($_POST['khatm_id']) : 0;
        $redirect = add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
        if (!$khatm_id) { Hamnoor_Helpers::redirect_with_notice($redirect, __('پویش معتبر نیست.', 'hamnoor'), 'error'); }
        check_admin_referer('hamnoor_thank_participants_' . $khatm_id);
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id, Hamnoor_Mobile_Access::current_user_id())) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('اجازه ارسال پیام برای این پویش را ندارید.', 'hamnoor'), 'error');
        }
        $is_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        if (!$is_admin && !Hamnoor_Mobile_Access::has_sensitive_access()) {
            Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab', 'profile', $redirect), __('برای ارسال پیام گروهی، ابتدا هویت این نشست را تأیید کنید.', 'hamnoor'), 'error');
        }
        $result = Hamnoor_DB::send_khatm_thanks($khatm_id);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, sprintf(__('پیام تشکر برای %d کاربر ثبت شد.', 'hamnoor'), (int) $result));
    }

    public function handle_claim_part() {
        check_admin_referer('hamnoor_claim_part');
        if (!empty($_POST['website'])) { wp_die(esc_html__('درخواست نامعتبر است.', 'hamnoor')); }
        $token = isset($_POST['khatm_token']) ? Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token'])) : '';
        $khatm = Hamnoor_Khatm_Service::get($token);
        if (!$khatm) { wp_die(esc_html__('پویش معتبر نیست.', 'hamnoor')); }
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $mode = isset($_POST['claim_mode']) ? sanitize_key(wp_unslash($_POST['claim_mode'])) : 'manual';
        $data = array('name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '', 'phone' => isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '', 'idempotency_key'=>isset($_POST['idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['idempotency_key'])) : '');
        $identity = Hamnoor_Mobile_Access::resolve_claim_identity($data['phone'], $data['name']);
        if (is_wp_error($identity)) { Hamnoor_Helpers::redirect_with_form(Hamnoor_Helpers::invite_url($khatm), $identity->get_error_message(), 'error', 'claim_' . $khatm->token, array(), array($identity->get_error_code() => $identity->get_error_message())); }
        $data['phone'] = (string) $identity['phone'];
        $data['name'] = (string) $identity['name'];
        $rate = $this->claim_rate_limit($khatm, $data);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_form(Hamnoor_Helpers::invite_url($khatm), $rate->get_error_message(), 'error', 'claim_' . $khatm->token, $data, array($rate->get_error_code() => $rate->get_error_message())); }
        $access_user = Hamnoor_Mobile_Access::ensure_and_login_for_claim($data['phone'], $data['name']);
        if (is_wp_error($access_user)) { Hamnoor_Helpers::redirect_with_form(Hamnoor_Helpers::invite_url($khatm), $access_user->get_error_message(), 'error', 'claim_' . $khatm->token, $data, array($access_user->get_error_code() => $access_user->get_error_message())); }
        $data['name'] = (string) $access_user->display_name;
        $data['phone'] = (string) $access_user->phone;
        if (!empty($data['phone'])) { $this->maybe_store_current_user_phone($data['phone']); }
        $round = isset($_POST['hamnoor_round']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['hamnoor_round'])) : 0;
        $result = $this->claim_result($khatm, $data, $mode, $part_id, $round);
        if (is_wp_error($result)) {
            $claim_redirect = Hamnoor_Helpers::invite_url($khatm);
            Hamnoor_Helpers::redirect_with_form($claim_redirect, $result->get_error_message(), 'error', 'claim_' . $khatm->token, $data, array($result->get_error_code()=>$result->get_error_message()));
        }
        $claimed_part = Hamnoor_DB::get_part((int) $result['part_id']);
        $claim_message = ($claimed_part && 'done' === (string) $claimed_part->status) ? __('سهم شما ثبت و انجام‌شده محسوب شد. خداوند قبول فرماید.', 'hamnoor') : __('سهم شما ثبت شد و در بخش «سهم‌های من» داشبوردتان قرار گرفت.', 'hamnoor');
        Hamnoor_Helpers::redirect_with_notice(Hamnoor_Helpers::part_access_url($khatm, (int) $result['part_id'], $result['tracking_token']), $claim_message);
    }

    public function handle_complete_part() {
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        check_admin_referer('hamnoor_complete_part_' . $part_id);
        $access = isset($_POST['access']) ? sanitize_text_field(wp_unslash($_POST['access'])) : '';
        $redirect = isset($_POST['redirect']) ? Hamnoor_Helpers::safe_redirect_url(wp_unslash($_POST['redirect']), home_url('/')) : home_url('/');
        $result = Hamnoor_DB::complete_part($part_id, $access, false);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('complete_success_full'));
    }

    public function handle_complete_contact_part() {
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        check_admin_referer('hamnoor_complete_contact_part_' . $part_id);
        $raw_contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $parsed = $this->parse_contact($raw_contact);
        $redirect = add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard'));
        if (!empty($parsed['error'])) { Hamnoor_Helpers::redirect_with_notice($redirect, $parsed['error'], 'error'); }
        $rate = Hamnoor_Helpers::rate_limit('complete_contact_part', $part_id . '|' . $parsed['contact'], 20, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice($redirect, $rate->get_error_message(), 'error'); }
        $result = Hamnoor_DB::complete_part_by_contact($part_id, $parsed['contact']);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('complete_success_full'));
    }

    public function handle_update_profile() {
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_die(esc_html__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor')); }
        check_admin_referer('hamnoor_update_profile');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $current = Hamnoor_Mobile_Access::current_user();
        $phone = $current ? (string) $current->phone : '';
        $result = Hamnoor_Mobile_Access::update_profile($user_id, $display_name, $phone);
        $redirect = add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard'));
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, __('مشخصات شما ذخیره شد.', 'hamnoor'));
    }

    public function handle_feature_request() {
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_die(esc_html__('برای ثبت درخواست، پنل هم‌نور خود را باز کنید.', 'hamnoor')); }
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        check_admin_referer('hamnoor_request_feature_' . $khatm_id);
        $message = isset($_POST['featured_request_message']) ? sanitize_textarea_field(wp_unslash($_POST['featured_request_message'])) : '';
        $result = Hamnoor_Featured_Service::create_request($khatm_id, $message, Hamnoor_Mobile_Access::current_user_id());
        $redirect = add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('feature_request_success'));
    }

    public function ajax_claim_part() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!empty($_POST['website'])) { wp_send_json_error(array('message'=>__('درخواست نامعتبر است.', 'hamnoor'))); }
        $token = isset($_POST['khatm_token']) ? Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token'])) : '';
        $khatm = Hamnoor_Khatm_Service::get($token);
        if (!$khatm) { wp_send_json_error(array('message'=>__('پویش معتبر نیست.', 'hamnoor'))); }
        $data = array('name'=>isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '', 'phone'=>isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '', 'idempotency_key'=>isset($_POST['idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['idempotency_key'])) : '');
        $identity = Hamnoor_Mobile_Access::resolve_claim_identity($data['phone'], $data['name']);
        if (is_wp_error($identity)) { wp_send_json_error(array('message'=>$identity->get_error_message(), 'code'=>$identity->get_error_code(), 'errors'=>array($identity->get_error_code()=>$identity->get_error_message()))); }
        $data['phone'] = (string) $identity['phone'];
        $data['name'] = (string) $identity['name'];
        $rate = $this->claim_rate_limit($khatm, $data);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message(), 'errors'=>array($rate->get_error_code()=>$rate->get_error_message()))); }
        $access_user = Hamnoor_Mobile_Access::ensure_and_login_for_claim($data['phone'], $data['name']);
        if (is_wp_error($access_user)) { wp_send_json_error(array('message'=>$access_user->get_error_message())); }
        $data['name'] = (string) $access_user->display_name;
        $data['phone'] = (string) $access_user->phone;
        $mode = isset($_POST['claim_mode']) ? sanitize_key(wp_unslash($_POST['claim_mode'])) : 'manual';
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0; $round = isset($_POST['hamnoor_round']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['hamnoor_round'])) : 0;
        $result = $this->claim_result($khatm, $data, $mode, $part_id, $round);
        if (is_wp_error($result)) {
            $error_payload = array('message'=>$result->get_error_message(), 'code'=>$result->get_error_code(), 'errors'=>array($result->get_error_code()=>$result->get_error_message()), 'quota_state'=>Hamnoor_Claim_Policy::quota_state(Hamnoor_Mobile_Access::current_user_id(), $khatm));
            if (in_array($result->get_error_code(), array('hamnoor_part_unavailable','hamnoor_claim_failed'), true) && $part_id) { $error_payload['card_html'] = $this->canonical_part_card($part_id, $khatm, 'single'); }
            wp_send_json_error($error_payload);
        }
        $part = Hamnoor_DB::get_part((int) $result['part_id']);
        $extension_feedback = $part ? Hamnoor_Domain_Events::completion_extension_feedback(
            'quran_part',
            Hamnoor_Domain_Events::stable_entity_id(isset($part->public_id) ? $part->public_id : '', 'part', (int)$part->id)
        ) : array();
        ob_start(); $this->render_template('frontend/claim-success.php', array('khatm'=>$khatm,'part'=>$part,'tracking_token'=>$result['tracking_token'])); $html=ob_get_clean();
        $show_name = $part && !empty($part->participant_name) && 'hidden' !== Hamnoor_Helpers::settings()['participant_name_display'];
        $part_status = $part ? (string) $part->status : 'claimed';
        $reading_url = ('claimed' === $part_status && $part && class_exists('Hamnoor_Reading') && Hamnoor_Reading::available()) ? Hamnoor_Reading::reading_url((int) $part->id, (string) $result['tracking_token']) : '';
        $khatm_after_claim = Hamnoor_DB::get_khatm((int) $khatm->id);
        $quota_state = Hamnoor_Claim_Policy::quota_state(Hamnoor_Mobile_Access::current_user_id(), $khatm_after_claim ?: $khatm);
        wp_send_json_success(array(
            'message'=>('done' === $part_status ? __('سهم شما ثبت و انجام‌شده محسوب شد. خداوند قبول فرماید.', 'hamnoor') : Hamnoor_Texts::get('claim_success_title')),
            'html'=>$html,
            'progress'=>Hamnoor_DB::get_progress((int)$khatm->id),
            'khatm_status'=>$khatm_after_claim ? $khatm_after_claim->status : '',
            'part_id'=>(int)$result['part_id'],
            'part_status'=>$part_status,
            'part_status_label'=>Hamnoor_Helpers::status_label($part_status, 'part'),
            'participant_name'=>$show_name ? Hamnoor_Helpers::display_name($part->participant_name) : '',
            'show_participant_name'=>$show_name ? 1 : 0,
            'reading_url'=>$reading_url,
            'reading_button_label'=>$reading_url ? Hamnoor_Texts::get('reading_start_button') : '',
            'share_label'=>$part ? (string)$part->title : '',
            'later_message'=>$part ? sprintf(__('سهم «%s» برای شما ثبت شد. می‌توانید بعداً از بخش «سهم‌های من» ادامه دهید.', 'hamnoor'), (string)$part->title) : '',
            'card_html'=>$this->canonical_part_card((int)$result['part_id'], $khatm_after_claim ?: $khatm, 'single', (string)$result['tracking_token']),
            'quota_state'=>$quota_state,
            'extension_feedback'=>$extension_feedback,
        ));
    }

    public function ajax_complete_part() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $access = isset($_POST['access']) ? sanitize_text_field(wp_unslash($_POST['access'])) : '';
        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        if ($contact) { $parsed = $this->parse_contact($contact); if (!empty($parsed['error'])) { wp_send_json_error(array('message'=>$parsed['error'])); } $rate = Hamnoor_Helpers::rate_limit('complete_contact_part', $part_id . '|' . $parsed['contact'], 20, 10 * MINUTE_IN_SECONDS); if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message())); } $result = Hamnoor_DB::complete_part_by_contact($part_id, $parsed['contact']); }
        else { $result = Hamnoor_DB::complete_part($part_id, $access, false); }
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message())); }
        $part = Hamnoor_DB::get_part($part_id); $progress = $part ? Hamnoor_DB::get_progress((int)$part->khatm_id) : array(); $khatm = $part ? Hamnoor_DB::get_khatm((int)$part->khatm_id) : null;
        $extension_feedback = $part ? Hamnoor_Domain_Events::completion_extension_feedback(
            'quran_part',
            Hamnoor_Domain_Events::stable_entity_id(isset($part->public_id) ? $part->public_id : '', 'part', (int)$part->id)
        ) : array();
        $show_name = $part && 'hidden' !== Hamnoor_Helpers::settings()['participant_name_display'];
        $reader_name = $show_name ? (!empty($part->participant_name) ? Hamnoor_Helpers::display_name($part->participant_name) : __('شرکت‌کننده', 'hamnoor')) : '';
        wp_send_json_success(array('message'=>Hamnoor_Texts::get('complete_success_full'), 'progress'=>$progress, 'khatm_status'=>$khatm ? $khatm->status : '', 'part_id' => (int) $part_id, 'participant_name' => $reader_name, 'show_participant_name' => $show_name ? 1 : 0, 'card_html'=>$this->canonical_part_card($part_id, $khatm, 'single', $access), 'quota_state'=>Hamnoor_Claim_Policy::quota_state(Hamnoor_Mobile_Access::current_user_id(), $khatm), 'extension_feedback'=>$extension_feedback));
    }


    public function ajax_release_part() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $access = isset($_POST['access']) ? sanitize_text_field(wp_unslash($_POST['access'])) : '';
        $reason = ($contact || $access) ? 'participant' : 'manual';
        if ($contact) {
            $parsed = $this->parse_contact($contact);
            if (!empty($parsed['error'])) { wp_send_json_error(array('message' => $parsed['error'])); }
            $contact = $parsed['contact'];
        }
        $rate_identity = $contact ? $contact : $access;
        $rate = Hamnoor_Helpers::rate_limit('release_part', $part_id . '|' . $rate_identity, 20, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message' => $rate->get_error_message())); }
        $result = Hamnoor_DB::release_part($part_id, $reason, Hamnoor_Mobile_Access::current_user_id(), $contact, $access);
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
        $part = Hamnoor_DB::get_part($part_id);
        $progress = $part ? Hamnoor_DB::get_progress((int) $part->khatm_id) : array();
        wp_send_json_success(array(
            'message' => __('سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.', 'hamnoor'),
            'part_id' => (int) $part_id,
            'part_status' => 'available',
            'part_status_label' => Hamnoor_Texts::get('badge_available'),
            'progress' => $progress,
            'card_html' => $this->canonical_part_card($part_id, $part ? Hamnoor_DB::get_khatm((int)$part->khatm_id) : null, 'single'),
            'quota_state' => Hamnoor_Claim_Policy::quota_state(Hamnoor_Mobile_Access::current_user_id(), $part ? Hamnoor_DB::get_khatm((int)$part->khatm_id) : null),
        ));
    }

    private function canonical_part_card($part_id, $khatm, $context = 'single', $access = '') {
        $part = Hamnoor_DB::get_part((int)$part_id);
        if (!$part || !$khatm) { return ''; }
        ob_start();
        $this->render_template('frontend/part-card.php', array('part'=>$part, 'khatm'=>$khatm, 'context'=>$context, 'access'=>$access, 'claimable'=>true));
        return ob_get_clean();
    }


    public function ajax_update_profile() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $current = Hamnoor_Mobile_Access::current_user();
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $phone = $current ? (string) $current->phone : '';
        $result = Hamnoor_Mobile_Access::update_profile($user_id, $display_name, $phone);
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message'=>$result->get_error_message(),
                'code'=>$result->get_error_code(),
                'requires_sensitive_verification'=>'hamnoor_sensitive_verification_required' === $result->get_error_code(),
            ));
        }
        wp_send_json_success(array('message'=>__('مشخصات شما ذخیره شد.', 'hamnoor')));
    }

    public function ajax_validate_create_step() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $data = Hamnoor_DB::sanitize_khatm_data($_POST);
        $result = Hamnoor_Validator::validate_khatm($data);
        $errors = $this->form_errors_from_wp_error($result);
        wp_send_json_success(array('errors'=>$errors, 'valid'=>empty($errors)));
    }
}
