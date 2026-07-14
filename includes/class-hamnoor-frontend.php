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
        add_shortcode('hamnoor_create_khatm', array($this, 'shortcode_create'));
        add_shortcode('hamnoor_khatm_list', array($this, 'shortcode_list'));
        add_shortcode('hamnoor_login', array($this, 'shortcode_login'));
        add_shortcode('hamnoor_register', array($this, 'shortcode_register'));
        add_shortcode('hamnoor_dashboard', array($this, 'shortcode_dashboard'));
        add_shortcode('hamnoor_track_parts', array($this, 'shortcode_track_parts'));
        add_shortcode('hamnoor_account', array($this, 'shortcode_account'));
        add_shortcode('hamnoor', array($this, 'shortcode_khatm'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_hamnoor_create_khatm', array($this, 'handle_create_khatm'));
        add_action('admin_post_hamnoor_update_khatm', array($this, 'handle_update_khatm'));
        add_action('admin_post_hamnoor_claim_part', array($this, 'handle_claim_part'));
        add_action('admin_post_nopriv_hamnoor_claim_part', array($this, 'handle_claim_part'));
        add_action('admin_post_hamnoor_complete_part', array($this, 'handle_complete_part'));
        add_action('admin_post_nopriv_hamnoor_complete_part', array($this, 'handle_complete_part'));
        add_action('admin_post_hamnoor_complete_contact_part', array($this, 'handle_complete_contact_part'));
        add_action('admin_post_nopriv_hamnoor_complete_contact_part', array($this, 'handle_complete_contact_part'));
        add_action('admin_post_nopriv_hamnoor_register_user', array($this, 'handle_register_user'));
        add_action('admin_post_hamnoor_login_user', array($this, 'handle_login_user'));
        add_action('admin_post_nopriv_hamnoor_login_user', array($this, 'handle_login_user'));
        add_action('admin_post_hamnoor_update_profile', array($this, 'handle_update_profile'));
        add_action('admin_post_hamnoor_request_feature', array($this, 'handle_feature_request'));

        add_action('wp_ajax_hamnoor_claim_part', array($this, 'ajax_claim_part'));
        add_action('wp_ajax_nopriv_hamnoor_claim_part', array($this, 'ajax_claim_part'));
        add_action('wp_ajax_hamnoor_complete_part', array($this, 'ajax_complete_part'));
        add_action('wp_ajax_nopriv_hamnoor_complete_part', array($this, 'ajax_complete_part'));
        add_action('wp_ajax_hamnoor_release_part', array($this, 'ajax_release_part'));
        add_action('wp_ajax_nopriv_hamnoor_release_part', array($this, 'ajax_release_part'));
        add_action('wp_ajax_hamnoor_track_parts', array($this, 'ajax_track_parts'));
        add_action('wp_ajax_nopriv_hamnoor_track_parts', array($this, 'ajax_track_parts'));
        add_action('wp_ajax_hamnoor_update_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_hamnoor_validate_create_step', array($this, 'ajax_validate_create_step'));
    }

    public function is_shortcode_context() {
        if (!is_singular()) { return false; }
        global $post;
        if (!$post) { return false; }
        foreach (array('hamnoor_create_khatm','hamnoor_khatm_list','hamnoor_login','hamnoor_register','hamnoor_dashboard','hamnoor_track_parts','hamnoor_account','hamnoor') as $tag) {
            if (has_shortcode($post->post_content, $tag)) { return true; }
        }
        return false;
    }

    public function enqueue_assets() {
        if (!$this->is_shortcode_context() && !Hamnoor_Router::instance()->is_hamnoor_request()) { return; }
        wp_enqueue_style('hamnoor-frontend', HAMNOOR_URL . 'assets/css/hamnoor-frontend.css', array(), HAMNOOR_VERSION);
        wp_add_inline_style('hamnoor-frontend', Hamnoor_Helpers::dynamic_css());
        wp_enqueue_script('hamnoor-frontend', HAMNOOR_URL . 'assets/js/hamnoor-frontend.js', array(), HAMNOOR_VERSION, true);
        $settings = Hamnoor_Helpers::settings();
        wp_localize_script('hamnoor-frontend', 'HamnoorFrontend', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hamnoor_frontend'),
            'track_url' => Hamnoor_Helpers::page_url('track'),
            'dashboard_url' => Hamnoor_Helpers::page_url('dashboard'),
            'debug' => (current_user_can('manage_options') && !empty($settings['ajax_debug_admin']) && '1' === (string) $settings['ajax_debug_admin']) ? 1 : 0,
            'texts' => array(
                'loading' => __('در حال ثبت...', 'hamnoor'),
                'checking' => __('در حال بررسی...', 'hamnoor'),
                'form_incomplete' => Hamnoor_Texts::get('form_incomplete'),
                'claim_next_desc' => Hamnoor_Texts::get('claim_next_desc'),
                'claim_random_desc' => Hamnoor_Texts::get('claim_random_desc'),
                'claim_manual_desc' => Hamnoor_Texts::get('claim_manual_desc', array('PART_TITLE' => '{PART_TITLE}')),
                'complete_success' => Hamnoor_Texts::get('complete_success_full'),
                'mobile_invalid' => Hamnoor_Texts::get('mobile_invalid'),
                'email_invalid' => Hamnoor_Texts::get('email_invalid'),
                'release_success' => __('سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.', 'hamnoor'),
            ),
        ));
    }

    public function shortcode_create() {
        if (!is_user_logged_in()) {
            $target = Hamnoor_Permissions::login_url(Hamnoor_Helpers::page_url('create'));
            if (!headers_sent()) { wp_safe_redirect($target); exit; }
            return $this->wrap($this->notice(__('برای ایجاد ختم، لطفاً ابتدا وارد حساب هم‌نور شوید یا ثبت‌نام کنید.', 'hamnoor'), 'warning'));
        }
        if (!Hamnoor_Permissions::can_create_khatm()) {
            return $this->wrap($this->notice(__('حساب کاربری شما هنوز مجوز ایجاد ختم ندارد. لطفاً با مدیر سایت تماس بگیرید.', 'hamnoor'), 'warning'));
        }
        $html = Hamnoor_Helpers::current_notice();
        if (!empty($_GET['edit_khatm'])) {
            $edit_id = (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['edit_khatm']));
            $khatm = $edit_id ? Hamnoor_DB::get_khatm($edit_id) : null;
            if (!$khatm) { return $this->wrap($html . $this->notice(__('ختم مورد نظر برای ویرایش پیدا نشد.', 'hamnoor'), 'error')); }
            if (!Hamnoor_DB::user_can_manage_khatm((int) $khatm->id)) { return $this->wrap($html . $this->notice(__('شما به ویرایش این ختم دسترسی ندارید.', 'hamnoor'), 'error')); }
            $html .= $this->render_create_form($khatm);
            return $this->wrap($html);
        }
        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) {
            return $this->wrap($html . $this->notice(__('ایجاد ختم جدید در حال حاضر موقتاً غیرفعال است. لطفاً بعداً مراجعه کنید.', 'hamnoor'), 'warning'));
        }
        if (!empty($_GET['created_khatm'])) {
            $khatm = Hamnoor_DB::get_khatm((int) $_GET['created_khatm']);
            if ($khatm && Hamnoor_DB::user_can_manage_khatm((int) $khatm->id)) { $html .= $this->success_panel($khatm); }
        }
        $html .= $this->render_create_form();
        return $this->wrap($html);
    }

    public function shortcode_khatm($atts) {
        $atts = shortcode_atts(array('token' => '', 'id' => ''), $atts, 'hamnoor');
        $key = !empty($atts['id']) ? (int) $atts['id'] : Hamnoor_Helpers::sanitize_token($atts['token']);
        $khatm = $key ? Hamnoor_DB::get_khatm($key) : null;
        if (!$khatm) { return $this->wrap($this->notice(__('ختم پیدا نشد.', 'hamnoor'), 'error')); }
        if (!Hamnoor_Permissions::can_preview_unpublished_khatm($khatm)) { return $this->wrap($this->notice(__('این ختم هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor'), 'warning')); }
        return $this->wrap($this->render_khatm($khatm), $this->design_class($khatm));
    }

    public function shortcode_list() {
        $khatms = Hamnoor_DB::get_khatms(array('privacy' => 'public', 'status__in' => array('active', 'completed'), 'limit' => 80, 'orderby' => 'status_priority'));
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        $this->render_template('frontend/khatm-list.php', array('khatms' => $khatms));
        return $this->wrap(ob_get_clean());
    }

    public function shortcode_account() {
        if (is_user_logged_in()) { return $this->shortcode_dashboard(); }
        $html = Hamnoor_Helpers::current_notice();
        $html .= '<div class="hamnoor-card hamnoor-auth-choice"><h2>' . esc_html__('حساب هم‌نور', 'hamnoor') . '</h2><p>' . esc_html__('برای ایجاد ختم وارد شوید یا حساب جدید بسازید.', 'hamnoor') . '</p><div class="hamnoor-action-buttons"><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url(Hamnoor_Helpers::page_url('login')) . '">' . esc_html__('ورود', 'hamnoor') . '</a><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url(Hamnoor_Helpers::page_url('register')) . '">' . esc_html__('ثبت‌نام', 'hamnoor') . '</a></div></div>';
        return $this->wrap($html);
    }

    public function shortcode_login() {
        $redirect = !empty($_GET['redirect_to']) ? Hamnoor_Helpers::safe_redirect_url(wp_unslash($_GET['redirect_to']), Hamnoor_Helpers::page_url('dashboard')) : Hamnoor_Helpers::page_url('dashboard');
        if (is_user_logged_in()) {
            $msg = '<div class="hamnoor-card"><h2>' . esc_html__('شما وارد حساب خود شده‌اید.', 'hamnoor') . '</h2><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url(Hamnoor_Helpers::page_url('dashboard')) . '">' . esc_html__('رفتن به داشبورد', 'hamnoor') . '</a></div>';
            return $this->wrap($msg);
        }
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        $this->render_template('frontend/login.php', array('redirect' => $redirect));
        return $this->wrap(ob_get_clean());
    }

    public function shortcode_register() {
        $redirect = !empty($_GET['redirect_to']) ? Hamnoor_Helpers::safe_redirect_url(wp_unslash($_GET['redirect_to']), Hamnoor_Helpers::page_url('dashboard')) : Hamnoor_Helpers::page_url('dashboard');
        if (is_user_logged_in()) {
            $msg = '<div class="hamnoor-card"><h2>' . esc_html__('شما وارد حساب خود شده‌اید.', 'hamnoor') . '</h2><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url(Hamnoor_Helpers::page_url('dashboard')) . '">' . esc_html__('رفتن به داشبورد', 'hamnoor') . '</a></div>';
            return $this->wrap($msg);
        }
        $settings = Hamnoor_Helpers::settings();
        if ('1' !== $settings['allow_frontend_registration']) { return $this->wrap($this->notice(__('ثبت‌نام هم‌نور در حال حاضر غیرفعال است.', 'hamnoor'), 'warning')); }
        $state = Hamnoor_Helpers::get_form_state('register');
        $old = isset($state['data']) ? $state['data'] : array();
        $errors = isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : array();
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        $this->render_template('frontend/register.php', array('redirect' => $redirect, 'old' => $old, 'errors' => $errors));
        return $this->wrap(ob_get_clean());
    }

    public function shortcode_dashboard() {
        if (!is_user_logged_in()) { wp_safe_redirect(Hamnoor_Permissions::login_url(Hamnoor_Helpers::page_url('dashboard'))); exit; }
        $user_id = get_current_user_id();
        $settings = Hamnoor_Helpers::settings();
        $owned_per_page = max(5, min(200, (int) $settings['dashboard_my_khatms_per_page']));
        $parts_per_page = max(5, min(200, (int) $settings['dashboard_my_parts_per_page']));
        $owned_page = max(1, isset($_GET['my_khatms_page']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['my_khatms_page'])) : 1);
        $parts_page = max(1, isset($_GET['my_parts_page']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['my_parts_page'])) : 1);
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'summary';
        $participants_khatm_id = !empty($_GET['participants_khatm']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_GET['participants_khatm'])) : 0;
        if ($participants_khatm_id) { $active_tab = 'my-khatms'; }
        $tabs = array('summary','my-khatms','my-parts','profile','help');
        if (!in_array($active_tab, $tabs, true)) { $active_tab = 'summary'; }
        $owned_args = array('owner_user_id' => $user_id, 'limit' => $owned_per_page, 'offset' => ($owned_page - 1) * $owned_per_page);
        $owned = Hamnoor_DB::get_khatms($owned_args);
        $owned_total = Hamnoor_DB::count_khatms(array('owner_user_id' => $user_id));
        $participants_khatm = null;
        $participants_rows = array();
        $participants_error = '';
        if ($participants_khatm_id) {
            $participants_khatm = Hamnoor_DB::get_khatm($participants_khatm_id);
            if (!$participants_khatm) { $participants_error = __('ختم مورد نظر پیدا نشد.', 'hamnoor'); }
            elseif (!Hamnoor_DB::user_can_manage_khatm((int) $participants_khatm->id, $user_id)) { $participants_error = __('شما اجازه مشاهده شرکت‌کنندگان این ختم را ندارید.', 'hamnoor'); }
            else { $participants_rows = Hamnoor_DB::get_participants((int) $participants_khatm->id, array('limit' => 200)); }
        }
        $parts = Hamnoor_DB::get_parts_by_user($user_id, $parts_per_page, ($parts_page - 1) * $parts_per_page);
        $parts_total = Hamnoor_DB::count_parts_by_user($user_id);
        $completed_archive = $this->completed_parts_archive($user_id);
        $parts = array_values(array_filter($parts, function($part) {
            $khatm = !empty($part->khatm_token) ? Hamnoor_DB::get_khatm($part->khatm_token) : null;
            return !$khatm || 'completed' !== (string) $khatm->status;
        }));
        $open_parts = 0; foreach ($parts as $part) { if ('done' !== (string) $part->status) { $open_parts++; } }
        $html = Hamnoor_Helpers::current_notice();
        if (!empty($_GET['created_khatm'])) {
            $khatm = Hamnoor_DB::get_khatm((int) $_GET['created_khatm']);
            if ($khatm && Hamnoor_DB::user_can_manage_khatm((int) $khatm->id)) { $html .= $this->success_panel($khatm); }
        }
        ob_start();
        echo $html;
        $this->render_template('frontend/dashboard.php', array(
            'user' => wp_get_current_user(), 'active_tab' => $active_tab,
            'owned' => $owned, 'owned_total' => $owned_total, 'owned_page' => $owned_page, 'owned_per_page' => $owned_per_page,
            'parts' => $parts, 'parts_total' => $parts_total, 'parts_page' => $parts_page, 'parts_per_page' => $parts_per_page, 'open_parts' => $open_parts, 'completed_archive' => $completed_archive,
            'participants_khatm' => $participants_khatm, 'participants_rows' => $participants_rows, 'participants_error' => $participants_error,
        ));
        return $this->wrap(ob_get_clean());
    }

    private function dashboard_create_html() {
        if (!empty($_GET['edit_khatm'])) {
            $khatm = Hamnoor_DB::get_khatm((int) $_GET['edit_khatm']);
            if ($khatm && Hamnoor_DB::user_can_manage_khatm((int) $khatm->id)) {
                return $this->render_create_form($khatm);
            }
            return $this->notice(__('شما به ویرایش این ختم دسترسی ندارید.', 'hamnoor'), 'error');
        }
        return $this->render_create_form();
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

    public function shortcode_track_parts() {
        $contact_raw = !empty($_GET['hamnoor_contact']) ? sanitize_text_field(wp_unslash($_GET['hamnoor_contact'])) : '';
        return $this->wrap(Hamnoor_Helpers::current_notice() . $this->render_track_parts_html($contact_raw));
    }

    private function parse_contact($raw) {
        $raw = sanitize_text_field((string) $raw);
        if ('' === trim($raw)) { return array('raw' => $raw, 'contact' => '', 'error' => '', 'type' => ''); }
        if (false !== strpos($raw, '@')) {
            $email = sanitize_email($raw);
            return array('raw' => $raw, 'contact' => is_email($email) ? $email : '', 'error' => is_email($email) ? '' : Hamnoor_Texts::get('email_invalid'), 'type' => 'email');
        }
        $phone = Hamnoor_Validator::normalize_phone($raw);
        return array('raw' => $raw, 'contact' => Hamnoor_Validator::valid_iran_mobile($phone) ? $phone : '', 'error' => Hamnoor_Validator::valid_iran_mobile($phone) ? '' : Hamnoor_Texts::get('mobile_invalid'), 'type' => 'phone');
    }

    private function profile_identity_errors($user_id, $email, $phone) {
        $errors = array();
        $email_owner = email_exists($email);
        if ($email_owner && (int) $email_owner !== (int) $user_id) {
            $errors['email'] = __('این ایمیل قبلاً برای حساب دیگری ثبت شده است.', 'hamnoor');
        }
        if ($phone) {
            $phone_owners = get_users(array(
                'meta_key' => 'hamnoor_phone',
                'meta_value' => $phone,
                'exclude' => array((int) $user_id),
                'number' => 1,
                'fields' => 'ID',
            ));
            if (!empty($phone_owners)) {
                $errors['phone'] = __('این شماره موبایل قبلاً برای حساب دیگری ثبت شده است.', 'hamnoor');
            }
        }
        return $errors;
    }

    private function claim_rate_limit($khatm, $data) {
        $identity = !empty($data['phone']) ? $data['phone'] : (isset($data['email']) ? $data['email'] : '');
        return Hamnoor_Helpers::rate_limit('claim_part', (int) $khatm->id . '|' . $identity, 30, 10 * MINUTE_IN_SECONDS);
    }

    private function maybe_store_current_user_phone($phone) {
        if (!is_user_logged_in()) { return; }
        $phone = Hamnoor_Validator::normalize_phone($phone);
        if (!$phone) { return; }
        $owners = get_users(array(
            'meta_key' => 'hamnoor_phone',
            'meta_value' => $phone,
            'exclude' => array(get_current_user_id()),
            'number' => 1,
            'fields' => 'ID',
        ));
        if (empty($owners)) { update_user_meta(get_current_user_id(), 'hamnoor_phone', $phone); }
    }

    private function render_track_parts_html($contact_raw = '') {
        $parsed = $this->parse_contact($contact_raw);
        if (!empty($parsed['contact']) && empty($parsed['error'])) {
            $rate = Hamnoor_Helpers::rate_limit('track_parts', $parsed['contact'], 30, 10 * MINUTE_IN_SECONDS);
            if (is_wp_error($rate)) { $parsed['error'] = $rate->get_error_message(); }
        }
        $parts = (!empty($parsed['contact']) && empty($parsed['error'])) ? Hamnoor_DB::get_parts_by_contact($parsed['contact']) : array();
        ob_start();
        $this->render_template('frontend/track-parts.php', array('contact_raw' => $contact_raw, 'contact' => $parsed['contact'], 'contact_error' => $parsed['error'], 'parts' => $parts));
        return ob_get_clean();
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
        $khatm_url = Hamnoor_Helpers::invite_url($khatm);
        $share_title = Hamnoor_Helpers::share_title($khatm);
        $share_text = Hamnoor_Helpers::share_text($khatm, $progress, 'web');
        $share_clipboard = Hamnoor_Helpers::share_text($khatm, $progress, 'clipboard');
        $share_image = Hamnoor_Helpers::share_image_data($khatm);
        $is_published = in_array((string) $khatm->status, array('active','completed'), true);
        $can_public_share = $is_published && ('public' === $khatm->privacy || '1' === $settings['allow_private_share']);
        ob_start();
        echo Hamnoor_Helpers::current_notice();
        echo '<section class="hamnoor-khatm-header">';
        $cover_url = Hamnoor_Helpers::cover_url($khatm);
        if (!empty($cover_url)) { echo '<img class="hamnoor-cover" src="' . esc_url($cover_url) . '" alt="' . esc_attr($khatm->title) . '">'; }
        else { echo '<div class="hamnoor-cover hamnoor-cover-placeholder" aria-hidden="true"></div>'; }
        echo '<div class="hamnoor-khatm-header-body"><div class="hamnoor-status-row"><span class="hamnoor-badge hamnoor-status-' . esc_attr($khatm->status) . '">' . esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')) . '</span>' . ('private' === $khatm->privacy ? ' <span class="hamnoor-badge hamnoor-status-private">' . esc_html__('خصوصی', 'hamnoor') . '</span>' : '') . '</div><h1>' . esc_html($khatm->title) . '</h1>';
        if ($khatm->intention) { echo '<p class="hamnoor-intention">' . wp_kses_post($khatm->intention) . '</p>'; }
        echo '</div></section>';
        if ($featured_badge) {
            $featured_notice = 'pinned' === $featured_badge['status'] ? Hamnoor_Texts::get('pinned_notice_single') : Hamnoor_Texts::get('featured_notice_single');
            echo '<div class="hamnoor-featured-notice hamnoor-featured-notice--' . esc_attr($featured_badge['status']) . '"><span class="hamnoor-featured-badge">' . esc_html($featured_badge['text']) . '</span><span>' . esc_html($featured_notice) . '</span></div>';
        }
        echo '<section class="hamnoor-progress-card" aria-label="' . esc_attr__('پیشرفت ختم', 'hamnoor') . '" data-khatm-id="' . (int) $khatm->id . '"><div class="hamnoor-progress-number"><strong data-progress-percent>' . esc_html($progress['percent']) . '%</strong><span>' . esc_html__('پیشرفت', 'hamnoor') . '</span></div><div class="hamnoor-progress-body"><div class="hamnoor-progress"><span data-progress-bar style="width:' . esc_attr($progress['percent']) . '%"></span></div><div class="hamnoor-progress-meta"><span data-progress-text>' . esc_html(sprintf(__('%1$d از %2$d سهم خوانده شده است', 'hamnoor'), (int)$progress['done'], (int)$progress['total'])) . '</span><span data-progress-available>' . esc_html(sprintf(__('سهم آزاد: %d', 'hamnoor'), (int)$progress['available'])) . '</span></div></div></section>';
        if ('completed' === (string) $khatm->status) { echo '<div class="hamnoor-notice hamnoor-notice-success hamnoor-completed-message">' . esc_html(Hamnoor_Texts::get('khatm_completed_message')) . '</div>'; }
        echo '<section class="hamnoor-primary-actions" aria-label="' . esc_attr__('انتخاب سهم', 'hamnoor') . '"><div><h2>' . esc_html__('انتخاب سهم', 'hamnoor') . '</h2><p>' . esc_html(Hamnoor_Texts::get('claim_intro')) . '</p></div><div class="hamnoor-action-buttons">';
        if (!is_wp_error($claimable_for_ui) && !empty($progress['available'])) {
            echo '<button type="button" class="hamnoor-btn hamnoor-btn-primary" data-hamnoor-next="1">' . esc_html(Hamnoor_Texts::get('claim_next_button')) . '</button>';
            if ('repeated_surah' !== (string) $khatm->khatm_type) { echo '<button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-hamnoor-random="1">' . esc_html(Hamnoor_Texts::get('claim_random_button')) . '</button>'; }
        } elseif (!is_wp_error($claimable_for_ui) && empty($progress['available'])) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html(Hamnoor_Texts::get('no_free_part')) . '</div>'; }
        else { echo $this->notice($claimable_for_ui->get_error_message(), 'warning'); }
        echo '</div></section>';
        if ($is_published) {
            echo '<section class="hamnoor-share-panel"><h2>' . esc_html__('اشتراک‌گذاری ختم', 'hamnoor') . '</h2><div class="hamnoor-share-box"><label>' . esc_html__('لینک دعوت', 'hamnoor') . '</label><input readonly value="' . esc_attr($khatm_url) . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy">' . esc_html__('کپی لینک', 'hamnoor') . '</button></div>';
            if ($can_public_share) { echo '<div class="hamnoor-share-actions" data-hamnoor-title="' . esc_attr($share_title) . '" data-hamnoor-url="' . esc_url($khatm_url) . '" data-hamnoor-text="' . esc_attr($share_text) . '" data-hamnoor-clipboard="' . esc_attr($share_clipboard) . '" data-hamnoor-image="' . (!empty($share_image['url']) ? esc_url($share_image['url']) : '') . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy-invite">' . esc_html__('کپی متن دعوت', 'hamnoor') . '</button><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-web-share">' . esc_html__('اشتراک‌گذاری', 'hamnoor') . '</button></div>'; }
            echo '</section>';
        } else {
            echo $this->notice(__('این ختم در انتظار بررسی و انتشار مدیر سایت است. لینک دعوت پس از انتشار برای عموم فعال می‌شود.', 'hamnoor'), 'warning');
        }
        echo '<section class="hamnoor-part-filter-panel"><h2>' . esc_html__('جستجو و فیلتر سهم‌ها', 'hamnoor') . '</h2><form class="hamnoor-part-filters" method="get">';
        if (!empty($khatm->khatm_count) && (int) $khatm->khatm_count > 1) {
            echo '<label>' . esc_html__('شماره ختم', 'hamnoor') . '<select name="hamnoor_round"><option value="0">' . esc_html__('همه', 'hamnoor') . '</option>';
            for ($r=1; $r <= (int) $khatm->khatm_count; $r++) { echo '<option value="' . (int) $r . '"' . selected($round_filter, $r, false) . '>' . esc_html(sprintf(__('ختم %d', 'hamnoor'), $r)) . '</option>'; }
            echo '</select></label>';
        }
        echo '<label class="hamnoor-check"><input type="checkbox" name="hamnoor_free_only" value="1"' . checked((bool)$status_filter, true, false) . '> ' . esc_html__('فقط سهم‌های آزاد', 'hamnoor') . '</label><label>' . esc_html__('جستجو', 'hamnoor') . '<input name="hamnoor_part_search" value="' . esc_attr($search_filter) . '" type="search" placeholder="' . esc_attr__('شماره صفحه، جزء یا سوره', 'hamnoor') . '"></label><button class="hamnoor-btn hamnoor-btn-secondary" type="submit">' . esc_html__('اعمال فیلتر', 'hamnoor') . '</button></form></section>';
        if ('completed' !== (string) $khatm->status && !is_wp_error($claimable_for_ui)) { echo $this->claim_form($khatm); }
        if ($access_part) {
            $part = Hamnoor_DB::get_part($access_part);
            if ($part && (int) $part->khatm_id === (int) $khatm->id) {
                $access_ok = Hamnoor_DB::can_complete_part($part, $access, false);
                if ($access_ok) { echo '<section class="hamnoor-card hamnoor-selected"><h2>' . esc_html__('سهم انتخاب‌شده شما', 'hamnoor') . '</h2>'; $this->render_template('frontend/part-card.php', array('part' => $part, 'khatm' => $khatm, 'context' => 'dashboard')); echo '</section>'; }
                else { echo $this->notice(__('لینک اختصاصی سهم معتبر نیست یا به این سهم دسترسی ندارید.', 'hamnoor'), 'error'); }
            }
        }
        echo '<section class="hamnoor-parts-section"><h2>' . esc_html__('سهم‌های ختم', 'hamnoor') . '</h2>';
        if ($parts_total > $per_page) {
            echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-parts-page-info">' . esc_html(sprintf(__('صفحه %1$d از %2$d — نمایش %3$d تا %4$d از %5$d سهم. برای مشاهده سهم‌های بیشتر از صفحه‌بندی استفاده کنید.', 'hamnoor'), (int) $page, (int) $parts_pages, (int) $parts_from, (int) $parts_to, (int) $parts_total)) . '</div>';
        }
        if (!empty($progress['available']) && 0 === $current_page_available && $parts_total > 0) {
            echo '<div class="hamnoor-notice hamnoor-notice-warning hamnoor-parts-page-info">' . esc_html__('در این صفحه سهم آزادی دیده نمی‌شود، اما در صفحه‌های دیگر هنوز سهم آزاد وجود دارد.', 'hamnoor') . '</div>';
        } elseif (!empty($progress['available']) && 0 === $parts_total && ($round_filter || $search_filter || $status_filter)) {
            echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-parts-page-info">' . esc_html__('با این فیلتر سهم آزادی دیده نمی‌شود، اما در کل ختم هنوز سهم آزاد وجود دارد. فیلترها را تغییر دهید یا از دکمه‌های انتخاب سهم استفاده کنید.', 'hamnoor') . '</div>';
        }
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
        $user = wp_get_current_user();
        $state = Hamnoor_Helpers::get_form_state('claim_' . $khatm->token);
        $old = isset($state['data']) ? $state['data'] : array();
        $errors = isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : array();
        $name = isset($old['name']) ? $old['name'] : (is_user_logged_in() ? $user->display_name : '');
        $email = isset($old['email']) ? $old['email'] : (is_user_logged_in() ? $user->user_email : '');
        $phone = isset($old['phone']) ? $old['phone'] : (is_user_logged_in() ? get_user_meta(get_current_user_id(), 'hamnoor_phone', true) : '');
        ob_start();
        $this->render_template('frontend/claim-modal.php', array('khatm' => $khatm, 'name' => $name, 'phone' => $phone, 'email' => $email, 'errors' => $errors));
        echo '<noscript><div class="hamnoor-card hamnoor-noscript-claim"><h2>' . esc_html__('انتخاب سهم', 'hamnoor') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-form">';
        wp_nonce_field('hamnoor_claim_part');
        echo '<input type="hidden" name="action" value="hamnoor_claim_part"><input type="hidden" name="khatm_token" value="' . esc_attr($khatm->token) . '"><input type="hidden" name="redirect" value="' . esc_url(Hamnoor_Helpers::invite_url($khatm)) . '"><p class="hamnoor-hp"><label>Website<input type="text" name="website" autocomplete="off"></label></p>';
        echo '<input type="hidden" name="claim_mode" value="next"><label>' . esc_html__('نام و نام خانوادگی', 'hamnoor') . '<input required type="text" name="name" value="' . esc_attr($name) . '"></label><label>' . esc_html__('شماره موبایل', 'hamnoor') . '<input required type="tel" name="phone" value="' . esc_attr($phone) . '" placeholder="09123456789"></label><label>' . esc_html__('ایمیل اختیاری', 'hamnoor') . '<input type="email" name="email" value="' . esc_attr($email) . '"></label><button class="hamnoor-btn hamnoor-btn-primary" type="submit">' . esc_html(Hamnoor_Texts::get('claim_submit')) . '</button></form></div></noscript>';
        return ob_get_clean();
    }

    private function render_create_form($khatm = null) {
        $editing = is_object($khatm);
        $requires_review = !$editing && !current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms');
        $user = wp_get_current_user();
        $state = Hamnoor_Helpers::get_form_state($editing ? 'khatm_edit' : 'khatm_create');
        $old = isset($state['data']) ? $state['data'] : array();
        $errors = isset($state['errors']) ? $state['errors'] : array();
        $getv = function($key, $default = '') use ($old) { return array_key_exists($key, $old) ? $old[$key] : $default; };
        $title = $getv('title', $editing ? $khatm->title : '');
        $intention = $getv('intention', $editing ? $khatm->intention : '');
        $description = $getv('description', $editing ? $khatm->description : '');
        $owner_name = $getv('owner_name', $editing ? $khatm->owner_name : (is_user_logged_in() ? $user->display_name : ''));
        $owner_email = $getv('owner_email', $editing ? $khatm->owner_email : (is_user_logged_in() ? $user->user_email : ''));
        $owner_phone = $getv('owner_phone', $editing ? $khatm->owner_phone : get_user_meta(get_current_user_id(), 'hamnoor_phone', true));
        $type = $getv('khatm_type', $editing ? $khatm->khatm_type : 'page');
        $privacy = $getv('privacy', $editing ? $khatm->privacy : 'public');
        $selected_surah = (int) $getv('selected_surah_number', 1); if ($selected_surah < 1) { $selected_surah = 1; }
        $khatm_count = (int) $getv('khatm_count', $editing && isset($khatm->khatm_count) ? (int) $khatm->khatm_count : 1); if ($khatm_count < 1) { $khatm_count = 1; }
        $starts_j = $getv('starts_at_jalali', $editing ? Hamnoor_Jalali::to_jalali($khatm->starts_at, true) : '');
        $ends_j = $getv('ends_at_jalali', $editing ? Hamnoor_Jalali::to_jalali($khatm->ends_at, true) : '');
        $design_style = $getv('design_style', $editing && isset($khatm->design_style) ? $khatm->design_style : 'default');
        $indexable = $getv('indexable', $editing && isset($khatm->indexable) ? (int) $khatm->indexable : 1);
        $auto_release_mode = $getv('auto_release_mode', $editing && isset($khatm->auto_release_mode) ? $khatm->auto_release_mode : 'none');
        if (!in_array($auto_release_mode, array('none','after_days','fixed_date'), true)) { $auto_release_mode = 'none'; }
        $auto_release_after_days = (int) $getv('auto_release_after_days', $editing && isset($khatm->auto_release_after_days) ? (int) $khatm->auto_release_after_days : 0);
        $auto_release_at_j = $getv('auto_release_at_jalali', $editing && !empty($khatm->auto_release_at) ? Hamnoor_Jalali::to_jalali($khatm->auto_release_at, true) : '');
        $release_min_ts = (int) current_time('timestamp');
        if ($editing && !empty($khatm->created_at)) {
            $created_at_ts = strtotime((string) $khatm->created_at);
            if ($created_at_ts) { $release_min_ts = (int) $created_at_ts; }
        }
        $completion_mode = $getv('completion_mode', $editing && isset($khatm->completion_mode) ? $khatm->completion_mode : 'manual_done');
        if (!in_array($completion_mode, array('manual_done','auto_done_on_claim'), true)) { $completion_mode = 'manual_done'; }
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
        $cover_label = __('تصویر ختم، اختیاری', 'hamnoor');
        if ('always' === $cover_mode) { $cover_label = __('تصویر ختم، الزامی', 'hamnoor'); }
        elseif ('public' === $cover_mode) { $cover_label = __('تصویر ختم، الزامی برای ختم عمومی', 'hamnoor'); }
        $has_default_cover = !empty($settings['default_khatm_image_url']);
        $cover_help = __('ابعاد پیشنهادی تصویر: ۱۲۰۰ در ۶۳۰ پیکسل. بهتر است تصویر افقی و واضح باشد.', 'hamnoor') . ' ' . ($has_default_cover ? __('اگر تصویری انتخاب نکنید، تصویر پیش‌فرض سایت برای ختم استفاده می‌شود.', 'hamnoor') : __('اگر تصویری انتخاب نکنید، طرح پیش‌فرض افزونه نمایش داده می‌شود.', 'hamnoor'));
        $immutable_note = __('توجه: نوع ختم و تعداد ختم پس از ایجاد قابل تغییر نیست. لطفاً پیش از رفتن به مرحله بعد، این موارد را با دقت انتخاب کنید.', 'hamnoor');
        $immutable_edit_note = __('نوع ختم و تعداد ختم پس از ایجاد قابل تغییر نیستند.', 'hamnoor');
        $edit_locked_attr = $editing ? ' readonly aria-readonly="true"' : '';
        $edit_disabled_attr = $editing ? ' disabled aria-disabled="true"' : '';
        ob_start();
        echo '<div class="hamnoor-card hamnoor-create-card"><h2>' . esc_html($editing ? __('ویرایش ختم', 'hamnoor') : Hamnoor_Texts::get('create_form_title')) . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-form hamnoor-create-form hamnoor-wizard" novalidate data-current-step="1" data-max-khatm-count="' . (int) $max_khatm_count . '" data-max-repeat-count="' . (int) $max_repeat_count . '" data-hard-limit="' . (int) $hard_limit . '" data-max-khatm-message="' . esc_attr($max_count_message) . '" data-max-repeat-message="' . esc_attr($max_repeat_message) . '" data-release-min-ts="' . (int) $release_min_ts . '">';
        wp_nonce_field($action . ($editing ? '_' . (int) $khatm->id : ''));
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">'; if ($editing) { echo '<input type="hidden" name="khatm_id" value="' . (int) $khatm->id . '">'; }
        echo '<div class="hamnoor-wizard-steps" aria-label="' . esc_attr__('مراحل ایجاد ختم', 'hamnoor') . '"><span data-step-title="' . esc_attr__('اطلاعات', 'hamnoor') . '">۱ <b>' . esc_html__('اطلاعات', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('نوع ختم', 'hamnoor') . '">۲ <b>' . esc_html__('نوع ختم', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('ظاهر', 'hamnoor') . '">۳ <b>' . esc_html__('ظاهر', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('ایجادکننده', 'hamnoor') . '">۴ <b>' . esc_html__('ایجادکننده', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('انتشار', 'hamnoor') . '">۵ <b>' . esc_html__('انتشار', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('تنظیمات قرائت', 'hamnoor') . '">۶ <b>' . esc_html__('تنظیمات قرائت', 'hamnoor') . '</b></span><span data-step-title="' . esc_attr__('تأیید', 'hamnoor') . '">۷ <b>' . esc_html__('تأیید', 'hamnoor') . '</b></span></div><div class="hamnoor-current-step-title" aria-live="polite"></div>';
        echo '<div class="hamnoor-form-summary" aria-live="polite" hidden></div>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="1"><legend>' . esc_html__('۱. اطلاعات اصلی ختم', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('در این بخش عنوان، نیت و توضیح ختم را وارد می‌کنید. این اطلاعات به شرکت‌کنندگان کمک می‌کند بدانند ختم برای چه نیتی برگزار می‌شود.', 'hamnoor') . '</p>' . $this->examples_box(Hamnoor_Texts::get('wizard_basic_examples'));
        echo '<label class="' . esc_attr($field_class('title')) . '">' . esc_html__('عنوان ختم', 'hamnoor') . '<input required maxlength="191" type="text" name="title" value="' . esc_attr($title) . '">' . $field_error('title') . '</label>';
        echo '<label>' . esc_html__('نیت ختم', 'hamnoor') . '<textarea name="intention">' . esc_textarea($intention) . '</textarea></label><label>' . esc_html__('توضیحات', 'hamnoor') . '<textarea name="description">' . esc_textarea($description) . '</textarea></label>';
        if ($editing && !empty($khatm->cover_url)) { echo '<div class="hamnoor-current-cover"><img class="hamnoor-cover" src="' . esc_url($khatm->cover_url) . '" alt="' . esc_attr($khatm->title) . '"><p class="hamnoor-help">' . esc_html__('اگر تصویر جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.', 'hamnoor') . '</p><label class="hamnoor-choice"><input type="checkbox" name="remove_cover" value="1"><span class="hamnoor-choice-text">' . esc_html__('حذف تصویر فعلی', 'hamnoor') . '</span></label></div>'; }
        echo '<label class="' . esc_attr($field_class('cover_file')) . '">' . esc_html($cover_label) . '<input type="file" name="cover_file" accept="image/jpeg,image/png,image/webp,image/gif"><span class="hamnoor-help">' . esc_html($cover_help) . '</span>' . $field_error('cover_file') . '</label></fieldset>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="2"><legend>' . esc_html__('۲. نوع ختم و تقسیم‌بندی', 'hamnoor') . '</legend>';
        if ($editing) { echo '<label>' . esc_html__('نوع ختم', 'hamnoor') . '<input readonly type="text" value="' . esc_attr(Hamnoor_Helpers::status_label($type, 'type')) . '"><input type="hidden" name="khatm_type" value="' . esc_attr($type) . '"></label>'; }
        else { echo '<label class="' . esc_attr($field_class('khatm_type')) . '">' . esc_html__('نوع ختم', 'hamnoor') . '<select name="khatm_type" id="hamnoor-khatm-type"><option value="page"' . selected($type, 'page', false) . '>' . esc_html__('تقسیم بر اساس صفحه', 'hamnoor') . '</option><option value="juz"' . selected($type, 'juz', false) . '>' . esc_html__('تقسیم بر اساس جزء', 'hamnoor') . '</option><option value="surah"' . selected($type, 'surah', false) . '>' . esc_html__('تقسیم بر اساس سوره', 'hamnoor') . '</option><option value="repeated_surah"' . selected($type, 'repeated_surah', false) . '>' . esc_html__('تکرار یک سوره', 'hamnoor') . '</option></select>' . $field_error('khatm_type') . '</label>'; }
        echo '<p class="hamnoor-help hamnoor-type-help">' . esc_html__('اینجا مشخص می‌کنید قرآن چگونه بین شرکت‌کنندگان تقسیم شود.', 'hamnoor') . '</p>';
        echo $this->examples_box(Hamnoor_Texts::get('wizard_type_examples')) . '<div class="hamnoor-notice hamnoor-notice-warning hamnoor-immutable-warning">' . esc_html($editing ? $immutable_edit_note : $immutable_note) . '</div>';
        if ($editing) { echo '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('نوع ختم و تعداد ختم پس از ایجاد قابل تغییر نیستند.', 'hamnoor') . '</div>'; }
        echo '<div class="hamnoor-khatm-count-fields"><label class="' . esc_attr($field_class('khatm_count')) . '">' . esc_html__('تعداد ختم کامل قرآن', 'hamnoor') . '<input type="number" min="1" max="' . (int) $max_khatm_count . '" name="khatm_count" value="' . esc_attr($khatm_count) . '"' . ($editing ? ' readonly' : '') . '><span class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('khatm_count_help', array('MAX' => $max_khatm_count))) . '</span>' . $field_error('khatm_count') . '</label></div>';
        echo '<div class="hamnoor-repeated-surah-fields"><label class="hamnoor-surah-picker ' . esc_attr($field_class('selected_surah_number')) . '">' . esc_html__('انتخاب سوره', 'hamnoor') . '<input type="search" class="hamnoor-surah-search" data-hamnoor-surah-select="hamnoor-selected-surah" aria-controls="hamnoor-selected-surah" autocomplete="off" placeholder="' . esc_attr__('جستجو با نام فارسی، عربی یا انگلیسی سوره', 'hamnoor') . '"' . $edit_disabled_attr . '><select id="hamnoor-selected-surah" name="selected_surah_number"' . $edit_disabled_attr . '>';
        foreach (Hamnoor_Helpers::surahs() as $num => $surah) {
            $search_terms = implode(' ', array($surah['name_fa'], $surah['name_ar'], $surah['slug'], 'سوره ' . $surah['name_fa']));
            echo '<option value="' . (int) $num . '" data-search="' . esc_attr($search_terms) . '"' . selected($selected_surah, $num, false) . '>' . esc_html($num . ' - سوره ' . $surah['name_fa']) . '</option>';
        }
        echo '</select><span class="hamnoor-help">' . esc_html__('نام سوره را تایپ کنید، سپس سوره موردنظر را از فهرست انتخاب کنید.', 'hamnoor') . '</span>' . $field_error('selected_surah_number') . '</label><label class="' . esc_attr($field_class('repeat_count')) . '">' . esc_html__('تعداد تکرار', 'hamnoor') . '<input type="number" min="1" max="' . (int) $max_repeat_count . '" name="repeat_count" value="' . esc_attr($repeat_count) . '"' . $edit_locked_attr . '><span class="hamnoor-help">' . esc_html(sprintf(__('حداکثر تعداد مجاز برای این ختم: %d مرتبه', 'hamnoor'), $max_repeat_count)) . '</span>' . $field_error('repeat_count') . '</label>';
        if ($can_manage_limits) { echo '<label>' . esc_html__('حد اختصاصی تکرار برای این ختم، اختیاری', 'hamnoor') . '<input type="number" min="1" max="12000" name="max_repeat_count" value="' . esc_attr($max_repeat_override ? $max_repeat_override : '') . '"><span class="hamnoor-help">' . esc_html__('اگر خالی باشد، مقدار عمومی تنظیمات استفاده می‌شود. این گزینه فقط برای مدیر سایت قابل تغییر است.', 'hamnoor') . '</span></label>'; }
        echo $repeat_contact_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped settings above.
        echo '</div><div class="hamnoor-live-summary" aria-live="polite"><strong>' . esc_html__('تعداد تقریبی سهم‌ها:', 'hamnoor') . '</strong> <span data-summary="total_parts">' . esc_html($parts_count_preview) . '</span></div></fieldset>';

        echo '<fieldset class="hamnoor-wizard-step" data-step="3"><legend>' . esc_html__('۳. ظاهر ختم', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('ظاهر ختم را متناسب با مناسبت انتخاب کنید.', 'hamnoor') . '</p>' . $this->examples_box(Hamnoor_Texts::get('wizard_style_examples')) . '<label>' . esc_html__('سبک طراحی ختم', 'hamnoor') . '<select name="design_style" id="hamnoor-design-style"><option value="default"' . selected($design_style, 'default', false) . '>ساده و رسمی</option><option value="blue-quran"' . selected($design_style, 'blue-quran', false) . '>قرآنی آبی</option><option value="golden"' . selected($design_style, 'golden', false) . '>طلایی</option><option value="memorial"' . selected($design_style, 'memorial', false) . '>ختم اموات</option><option value="ramadan"' . selected($design_style, 'ramadan', false) . '>ماه رمضان</option><option value="heyati"' . selected($design_style, 'heyati', false) . '>هیئتی/مسجدی</option></select></label><div class="hamnoor-preview-card hamnoor-style-' . esc_attr($design_style) . '"><strong data-summary="title">' . esc_html($title ? $title : __('عنوان ختم شما', 'hamnoor')) . '</strong><p>' . esc_html__('پیش‌نمایش کارت ختم با سبک انتخاب‌شده', 'hamnoor') . '</p></div></fieldset>';
        echo '<fieldset class="hamnoor-wizard-step" data-step="4"><legend>' . esc_html__('۴. اطلاعات ایجادکننده ختم', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('wizard_owner_desc')) . '</p><label class="' . esc_attr($field_class('owner_name')) . '">' . esc_html__('نام ایجادکننده', 'hamnoor') . '<input required maxlength="191" type="text" name="owner_name" value="' . esc_attr($owner_name) . '">' . $field_error('owner_name') . '</label><label class="' . esc_attr($field_class('owner_phone')) . '">' . esc_html__('موبایل ایجادکننده', 'hamnoor') . '<input required type="tel" name="owner_phone" value="' . esc_attr($owner_phone) . '" placeholder="09123456789">' . $field_error('owner_phone') . '</label><label class="' . esc_attr($field_class('owner_email')) . '">' . esc_html__('ایمیل ایجادکننده', 'hamnoor') . '<input required maxlength="191" type="email" name="owner_email" value="' . esc_attr($owner_email) . '">' . $field_error('owner_email') . '</label></fieldset>';
        echo '<fieldset class="hamnoor-wizard-step" data-step="5"><legend>' . esc_html__('۵. انتشار', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('wizard_publish_desc')) . '</p><label>' . esc_html__('نوع دسترسی', 'hamnoor') . '<select name="privacy"><option value="public"' . selected($privacy, 'public', false) . '>' . esc_html__('عمومی', 'hamnoor') . '</option><option value="private"' . selected($privacy, 'private', false) . '>' . esc_html__('خصوصی؛ فقط با لینک دعوت', 'hamnoor') . '</option></select></label><input type="hidden" name="indexable" value="0"><label class="hamnoor-choice"><input type="checkbox" name="indexable" value="1"' . checked((int)$indexable, 1, false) . '><span class="hamnoor-choice-text">' . esc_html__('نمایش در نتایج گوگل', 'hamnoor') . '</span></label><p class="hamnoor-help">' . esc_html(Hamnoor_Texts::get('google_visibility_desc')) . '</p><label>' . esc_html__('تاریخ شروع', 'hamnoor') . '<input class="hamnoor-date-input hamnoor-jalali-input" type="text" name="starts_at_jalali" value="' . esc_attr($starts_j) . '" placeholder="1405/01/01 08:00"></label><label class="' . esc_attr($field_class('ends_at')) . '">' . esc_html__('تاریخ پایان', 'hamnoor') . '<input class="hamnoor-date-input hamnoor-jalali-input" type="text" name="ends_at_jalali" value="' . esc_attr($ends_j) . '" placeholder="1405/01/01 08:00">' . $field_error('ends_at') . '</label></fieldset>';
        echo '<fieldset class="hamnoor-wizard-step" data-step="6"><legend>' . esc_html__('۶. تنظیمات قرائت', 'hamnoor') . '</legend><p class="hamnoor-help">' . esc_html__('در این بخش مشخص می‌کنید آیا شرکت‌کنندگان باید پس از خواندن سهم، آن را ثبت کنند یا انتخاب سهم به‌تنهایی کافی است.', 'hamnoor') . '</p><div class="hamnoor-completion-options"><h3>' . esc_html__('نیاز به ثبت قرائت', 'hamnoor') . '</h3><label class="hamnoor-choice"><input type="radio" name="completion_mode" value="manual_done"' . checked($completion_mode, 'manual_done', false) . '><span class="hamnoor-choice-text">' . esc_html__('بله، شرکت‌کننده پس از قرائت باید دکمه «قرائت انجام شد» را بزند.', 'hamnoor') . '</span></label><label class="hamnoor-choice"><input type="radio" name="completion_mode" value="auto_done_on_claim"' . checked($completion_mode, 'auto_done_on_claim', false) . '><span class="hamnoor-choice-text">' . esc_html__('خیر، با انتخاب سهم، قرائت انجام‌شده تلقی شود.', 'hamnoor') . '</span></label><p class="hamnoor-help">' . esc_html__('اگر گزینه دوم را انتخاب کنید، سهم‌ها به‌محض انتخاب شدن، خوانده‌شده محسوب می‌شوند و نیازی به ثبت جداگانه قرائت نیست. تغییر این گزینه فقط بر انتخاب‌های بعدی اثر می‌گذارد.', 'hamnoor') . '</p></div><div class="hamnoor-release-options"><h3>' . esc_html__('آزادسازی سهم‌های خوانده‌نشده', 'hamnoor') . '</h3><p class="hamnoor-help">' . esc_html__('اگر شرکت‌کننده سهم را انتخاب کند اما قرائت آن را ثبت نکند، می‌توانید تعیین کنید سهم بعد از مدتی دوباره آزاد شود.', 'hamnoor') . '</p><label>' . esc_html__('روش آزادسازی', 'hamnoor') . '<select name="auto_release_mode"><option value="none"' . selected($auto_release_mode, 'none', false) . '>' . esc_html__('آزادسازی خودکار انجام نشود', 'hamnoor') . '</option><option value="after_days"' . selected($auto_release_mode, 'after_days', false) . '>' . esc_html__('آزادسازی پس از چند روز از زمان انتخاب سهم', 'hamnoor') . '</option><option value="fixed_date"' . selected($auto_release_mode, 'fixed_date', false) . '>' . esc_html__('آزادسازی در یک تاریخ مشخص', 'hamnoor') . '</option></select></label><label class="hamnoor-release-after-days ' . esc_attr($field_class('auto_release_after_days')) . '">' . esc_html__('تعداد روز پس از انتخاب سهم', 'hamnoor') . '<input type="number" min="1" name="auto_release_after_days" value="' . esc_attr($auto_release_after_days) . '">' . $field_error('auto_release_after_days') . '</label><label class="hamnoor-release-fixed-date ' . esc_attr($field_class('auto_release_at')) . '">' . esc_html__('تاریخ آزادسازی سهم‌های خوانده‌نشده', 'hamnoor') . '<input class="hamnoor-date-input hamnoor-jalali-input" type="text" name="auto_release_at_jalali" value="' . esc_attr($auto_release_at_j) . '" placeholder="1405/01/01 08:00">' . $field_error('auto_release_at') . '</label></div></fieldset>';
        echo '<fieldset class="hamnoor-wizard-step" data-step="7"><legend>' . esc_html__('۷. تأیید و ایجاد', 'hamnoor') . '</legend><div class="hamnoor-summary-card"><p><strong>' . esc_html__('عنوان:', 'hamnoor') . '</strong> <span data-summary="title">' . esc_html($title) . '</span> <button type="button" class="hamnoor-link-button" data-go-step="1">' . esc_html__('ویرایش', 'hamnoor') . '</button></p><p><strong>' . esc_html__('نوع ختم:', 'hamnoor') . '</strong> <span data-summary="type">' . esc_html(Hamnoor_Helpers::status_label($type, 'type')) . '</span> <button type="button" class="hamnoor-link-button" data-go-step="2">' . esc_html__('ویرایش', 'hamnoor') . '</button> <small class="hamnoor-immutable-reminder">' . esc_html__('این مورد پس از ایجاد ختم قابل تغییر نیست.', 'hamnoor') . '</small></p><p><strong>' . esc_html__('تعداد ختم:', 'hamnoor') . '</strong> <span data-summary="khatm_count">' . esc_html($khatm_count) . '</span> <small class="hamnoor-immutable-reminder">' . esc_html__('این مورد پس از ایجاد ختم قابل تغییر نیست.', 'hamnoor') . '</small></p><p><strong>' . esc_html__('تعداد کل سهم‌ها:', 'hamnoor') . '</strong> <span data-summary="total_parts">' . esc_html($parts_count_preview) . '</span></p><p><strong>' . esc_html__('ایجادکننده:', 'hamnoor') . '</strong> <span data-summary="owner_name">' . esc_html($owner_name) . '</span></p><p><strong>' . esc_html__('دسترسی:', 'hamnoor') . '</strong> <span data-summary="privacy">' . esc_html('public' === $privacy ? __('عمومی', 'hamnoor') : __('خصوصی', 'hamnoor')) . '</span></p><p><strong>' . esc_html__('سبک:', 'hamnoor') . '</strong> <span data-summary="design_style">' . esc_html($design_style) . '</span></p><p><strong>' . esc_html__('نیاز به ثبت قرائت:', 'hamnoor') . '</strong> <span data-summary="completion_mode">' . esc_html('auto_done_on_claim' === $completion_mode ? __('خیر؛ با انتخاب سهم انجام‌شده محسوب می‌شود', 'hamnoor') : __('بله؛ پس از قرائت باید ثبت شود', 'hamnoor')) . '</span></p><p><strong>' . esc_html__('روش آزادسازی:', 'hamnoor') . '</strong> <span data-summary="auto_release_mode">' . esc_html('none' === $auto_release_mode ? __('آزادسازی خودکار انجام نشود', 'hamnoor') : ('after_days' === $auto_release_mode ? __('پس از چند روز', 'hamnoor') : __('در تاریخ مشخص', 'hamnoor'))) . '</span></p><p><strong>' . esc_html__('نمایش در گوگل:', 'hamnoor') . '</strong> <span data-summary="indexable">' . esc_html((int) $indexable ? __('بله', 'hamnoor') : __('خیر', 'hamnoor')) . '</span></p></div>' . $support_html . '</fieldset>';
        if ($requires_review) { echo '<div class="hamnoor-notice hamnoor-notice-info hamnoor-review-note">' . esc_html__('پس از ثبت ختم، درخواست شما برای بررسی مدیر سایت ارسال می‌شود. مدیر سایت می‌تواند پیش از انتشار، تصویر و برخی تنظیمات ظاهری ختم را اصلاح کند. پس از انتشار، از طریق داشبورد و در صورت امکان ایمیل یا پیامک به شما اطلاع‌رسانی خواهد شد.', 'hamnoor') . '</div>'; }
        echo '<div class="hamnoor-wizard-nav"><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-wizard-prev>' . esc_html__('مرحله قبل', 'hamnoor') . '</button><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-wizard-next>' . esc_html__('مرحله بعد', 'hamnoor') . '</button><button type="submit" class="hamnoor-btn hamnoor-btn-primary" data-wizard-submit>' . esc_html($editing ? __('ذخیره تغییرات', 'hamnoor') : ($requires_review ? __('ثبت و ارسال برای بررسی مدیر', 'hamnoor') : __('ایجاد و انتشار ختم', 'hamnoor'))) . '</button></div>';
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
        $html = '<div class="hamnoor-card"><h2>' . esc_html__('شرکت‌کنندگان ختم', 'hamnoor') . '</h2>';
        if (!$rows) { return $html . '<p class="hamnoor-empty">' . esc_html__('هنوز شرکت‌کننده‌ای ثبت نشده است.', 'hamnoor') . '</p></div>'; }
        $html .= '<div class="hamnoor-mobile-cards">';
        foreach ($rows as $row) { $html .= '<div class="hamnoor-row-card"><strong>' . esc_html($row->name) . '</strong><span>' . esc_html($row->phone) . ' - ' . esc_html($row->email) . '</span><small>' . esc_html(sprintf(__('سهم‌ها: %d / خوانده‌شده: %d', 'hamnoor'), (int)$row->total_parts, (int)$row->done_parts)) . '</small></div>'; }
        return $html . '</div></div>';
    }

    private function success_panel($khatm) {
        $url = Hamnoor_Helpers::invite_url($khatm);
        if ('pending_review' === (string) $khatm->status) {
            return '<div class="hamnoor-card hamnoor-success-panel"><h2>' . esc_html__('درخواست ختم ثبت شد', 'hamnoor') . '</h2><div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('ختم شما در انتظار بررسی و انتشار مدیر سایت است. پس از انتشار، در داشبورد و در صورت امکان با ایمیل به شما اطلاع داده می‌شود.', 'hamnoor') . '</div><div class="hamnoor-action-buttons"><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url($url) . '">' . esc_html__('پیش‌نمایش ختم', 'hamnoor') . '</a><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url(Hamnoor_Helpers::page_url('dashboard')) . '">' . esc_html__('رفتن به داشبورد', 'hamnoor') . '</a></div></div>';
        }
        $share = Hamnoor_Helpers::share_text($khatm, null, 'clipboard');
        return '<div class="hamnoor-card hamnoor-success-panel"><h2>' . esc_html(Hamnoor_Texts::get('create_success')) . '</h2><p>' . esc_html__('لینک دعوت کوتاه آماده است:', 'hamnoor') . '</p><div class="hamnoor-share-box"><input readonly value="' . esc_attr($url) . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy">' . esc_html__('کپی لینک دعوت', 'hamnoor') . '</button></div><div class="hamnoor-share-actions" data-hamnoor-clipboard="' . esc_attr($share) . '"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy-invite">' . esc_html__('کپی متن دعوت', 'hamnoor') . '</button><a class="hamnoor-btn hamnoor-btn-primary" href="' . esc_url($url) . '">' . esc_html__('مشاهده صفحه ختم', 'hamnoor') . '</a><a class="hamnoor-btn hamnoor-btn-secondary" href="' . esc_url(Hamnoor_Helpers::page_url('dashboard')) . '">' . esc_html__('رفتن به داشبورد', 'hamnoor') . '</a></div></div>';
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
    private function design_class($khatm) { return Hamnoor_Helpers::design_class($khatm); }

    private function form_errors_from_wp_error($error) {
        if (!is_wp_error($error)) { return array(); }
        $map = array('hamnoor_title_required'=>'title','hamnoor_title_long'=>'title','hamnoor_mobile_required'=>'owner_phone','hamnoor_mobile_invalid'=>'owner_phone','hamnoor_email_invalid'=>'owner_email','hamnoor_date_order'=>'ends_at','hamnoor_date_format_invalid'=>'starts_at','hamnoor_surah_invalid'=>'selected_surah_number','hamnoor_repeat_invalid'=>'repeat_count','hamnoor_type_invalid'=>'khatm_type','hamnoor_khatm_count_invalid'=>'khatm_count','hamnoor_parts_too_many'=>'khatm_count','hamnoor_cover_size'=>'cover_file','hamnoor_cover_type'=>'cover_file','hamnoor_cover_upload'=>'cover_file','hamnoor_cover_required'=>'cover_file','hamnoor_auto_release_days_invalid'=>'auto_release_after_days','hamnoor_auto_release_date_invalid'=>'auto_release_at');
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
        return new WP_Error('hamnoor_cover_required', __('تصویر ختم برای این حالت الزامی است.', 'hamnoor'), array('field' => 'cover_file'));
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
        if (!is_user_logged_in() || !Hamnoor_Permissions::can_create_khatm()) { wp_die(esc_html__('دسترسی ایجاد ختم ندارید.', 'hamnoor')); }
        check_admin_referer('hamnoor_create_khatm');
        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) { wp_die(esc_html__('ایجاد ختم جدید در حال حاضر موقتاً غیرفعال است. لطفاً بعداً مراجعه کنید.', 'hamnoor')); }
        $data = $this->collect_khatm_data_with_cover();
        if (is_wp_error($data)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('create'), $data->get_error_message(), 'error', 'khatm_create', Hamnoor_DB::sanitize_khatm_data($_POST), $this->form_errors_from_wp_error($data)); }
        if (!empty($data['owner_phone'])) { $this->maybe_store_current_user_phone($data['owner_phone']); }
        $result = Hamnoor_DB::create_khatm($data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('create'), $result->get_error_message(), 'error', 'khatm_create', $data, $this->form_errors_from_wp_error($result)); }
        $created = Hamnoor_DB::get_khatm((int) $result);
        $message = $created && 'pending_review' === (string) $created->status ? __('درخواست ختم ثبت شد و برای بررسی مدیر سایت ارسال گردید.', 'hamnoor') : Hamnoor_Texts::get('create_success');
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('created_khatm', (int) $result, Hamnoor_Helpers::page_url('dashboard')), $message);
    }

    public function handle_update_khatm() {
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id)) { wp_die(esc_html__('دسترسی کافی ندارید.', 'hamnoor')); }
        check_admin_referer('hamnoor_update_khatm_' . $khatm_id);
        $existing = Hamnoor_DB::get_khatm($khatm_id);
        $data = $this->collect_khatm_data_with_cover($existing);
        if (is_wp_error($data)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('dashboard'), $data->get_error_message(), 'error', 'khatm_edit', Hamnoor_DB::sanitize_khatm_data($_POST), $this->form_errors_from_wp_error($data)); }
        if (!empty($data['owner_phone'])) { $this->maybe_store_current_user_phone($data['owner_phone']); }
        $result = Hamnoor_DB::update_khatm($khatm_id, $data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('dashboard'), $result->get_error_message(), 'error', 'khatm_edit', $data, $this->form_errors_from_wp_error($result)); }
        Hamnoor_Helpers::redirect_with_notice(Hamnoor_Helpers::invite_url($existing), __('تغییرات ختم ذخیره شد.', 'hamnoor'));
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

    public function handle_claim_part() {
        check_admin_referer('hamnoor_claim_part');
        if (!empty($_POST['website'])) { wp_die(esc_html__('درخواست نامعتبر است.', 'hamnoor')); }
        $token = isset($_POST['khatm_token']) ? Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token'])) : '';
        $khatm = Hamnoor_Khatm_Service::get($token);
        if (!$khatm) { wp_die(esc_html__('ختم معتبر نیست.', 'hamnoor')); }
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $mode = isset($_POST['claim_mode']) ? sanitize_key(wp_unslash($_POST['claim_mode'])) : 'manual';
        $data = array('name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '', 'phone' => isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '', 'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '');
        $rate = $this->claim_rate_limit($khatm, $data);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_form(Hamnoor_Helpers::invite_url($khatm), $rate->get_error_message(), 'error', 'claim_' . $khatm->token, $data, array($rate->get_error_code() => $rate->get_error_message())); }
        if (!empty($data['phone'])) { $this->maybe_store_current_user_phone($data['phone']); }
        $round = isset($_POST['hamnoor_round']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['hamnoor_round'])) : 0;
        $result = $this->claim_result($khatm, $data, $mode, $part_id, $round);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_form(Hamnoor_Helpers::invite_url($khatm), $result->get_error_message(), 'error', 'claim_' . $khatm->token, $data, array($result->get_error_code()=>$result->get_error_message())); }
        $claimed_part = Hamnoor_DB::get_part((int) $result['part_id']);
        $claim_message = ($claimed_part && 'done' === (string) $claimed_part->status) ? __('سهم شما ثبت و انجام‌شده محسوب شد. خداوند قبول فرماید.', 'hamnoor') : sprintf(__('سهم شما ثبت شد. اگر اکنون قرائت را ثبت نکردید، بعداً از <a href="%s">صفحه پیگیری سهم‌ها</a> با موبایل یا ایمیل خود می‌توانید این سهم را پیدا کنید.', 'hamnoor'), esc_url(Hamnoor_Helpers::page_url('track')));
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
        $redirect = add_query_arg('hamnoor_contact', rawurlencode($raw_contact), Hamnoor_Helpers::page_url('track'));
        if (!empty($parsed['error'])) { Hamnoor_Helpers::redirect_with_notice($redirect, $parsed['error'], 'error'); }
        $rate = Hamnoor_Helpers::rate_limit('complete_contact_part', $part_id . '|' . $parsed['contact'], 20, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice($redirect, $rate->get_error_message(), 'error'); }
        $result = Hamnoor_DB::complete_part_by_contact($part_id, $parsed['contact']);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('complete_success_full'));
    }

    public function handle_login_user() {
        check_admin_referer('hamnoor_login_user');
        $rate = Hamnoor_Helpers::rate_limit('login_user', isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '', 5, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice(wp_get_referer() ?: Hamnoor_Helpers::page_url('login'), $rate->get_error_message(), 'error'); }
        $redirect = isset($_POST['redirect']) ? Hamnoor_Helpers::safe_redirect_url(wp_unslash($_POST['redirect']), Hamnoor_Helpers::page_url('dashboard')) : Hamnoor_Helpers::page_url('dashboard');
        $login = isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '';
        if (false === strpos($login, '@')) { $phone = Hamnoor_Validator::normalize_phone($login); if (Hamnoor_Validator::valid_iran_mobile($phone)) { $users = get_users(array('meta_key'=>'hamnoor_phone','meta_value'=>$phone,'number'=>1)); if (!empty($users[0])) { $login = $users[0]->user_login; } } }
        $creds = array('user_login' => $login, 'user_password' => isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '', 'remember' => !empty($_POST['remember']));
        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) { Hamnoor_Helpers::redirect_with_notice(wp_get_referer() ?: Hamnoor_Helpers::page_url('login'), __('ورود انجام نشد. موبایل/ایمیل یا رمز عبور را بررسی کنید.', 'hamnoor'), 'error'); }
        Hamnoor_DB::sync_guest_history_to_user((int) $user->ID);
        wp_safe_redirect($redirect); exit;
    }

    public function handle_register_user() {
        $settings = Hamnoor_Helpers::settings();
        if ('1' !== $settings['allow_frontend_registration']) { wp_die(esc_html__('ثبت‌نام هم‌نور غیرفعال است.', 'hamnoor')); }
        check_admin_referer('hamnoor_register_user');
        if (!empty($_POST['website'])) { wp_die(esc_html__('درخواست نامعتبر است.', 'hamnoor')); }
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $password2 = isset($_POST['password_confirm']) ? (string) wp_unslash($_POST['password_confirm']) : '';
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $redirect = isset($_POST['redirect']) ? Hamnoor_Helpers::safe_redirect_url(wp_unslash($_POST['redirect']), Hamnoor_Helpers::page_url('dashboard')) : Hamnoor_Helpers::page_url('dashboard');
        $rate = Hamnoor_Helpers::rate_limit('register_user', $phone ? $phone : $email, 5, 30 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice(wp_get_referer() ?: Hamnoor_Helpers::page_url('register'), $rate->get_error_message(), 'error'); }
        $errors = array();
        if (!$display_name) { $errors['display_name'] = __('نام و نام خانوادگی الزامی است.', 'hamnoor'); }
        $phone_valid = Hamnoor_Validator::require_mobile($phone, __('شماره موبایل', 'hamnoor')); if (is_wp_error($phone_valid)) { $errors['phone'] = $phone_valid->get_error_message(); }
        if (!is_email($email)) { $errors['email'] = Hamnoor_Texts::get('email_invalid'); }
        if (strlen($password) < 8) { $errors['password'] = __('رمز عبور باید حداقل ۸ کاراکتر باشد.', 'hamnoor'); }
        if ($password !== $password2) { $errors['password_confirm'] = __('تکرار رمز عبور با رمز عبور یکسان نیست.', 'hamnoor'); }
        if ($errors) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('register'), Hamnoor_Texts::get('form_incomplete'), 'error', 'register', array('display_name'=>$display_name,'phone'=>$phone,'email'=>$email), $errors); }
        if (email_exists($email)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('redirect_to', rawurlencode($redirect), Hamnoor_Helpers::page_url('login')), __('با این ایمیل قبلاً حساب کاربری ساخته شده است. لطفاً وارد شوید.', 'hamnoor'), 'warning'); }
        $phone_users = get_users(array('meta_key' => 'hamnoor_phone', 'meta_value' => $phone, 'number' => 1, 'fields' => 'ID'));
        if (!empty($phone_users)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('redirect_to', rawurlencode($redirect), Hamnoor_Helpers::page_url('login')), __('با این شماره موبایل قبلاً حسابی ثبت شده است. لطفاً وارد شوید.', 'hamnoor'), 'warning'); }
        $base = sanitize_user(current(explode('@', $email)), true); if (!$base) { $base = 'hamnoor_user'; }
        $username = $base; $i = 1; while (username_exists($username)) { $username = $base . $i; $i++; }
        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) { Hamnoor_Helpers::redirect_with_form(wp_get_referer() ?: Hamnoor_Helpers::page_url('register'), $user_id->get_error_message(), 'error', 'register', array('display_name'=>$display_name,'phone'=>$phone,'email'=>$email), array('register'=>$user_id->get_error_message())); }
        wp_update_user(array('ID' => $user_id, 'display_name' => $display_name, 'nickname' => $display_name));
        update_user_meta($user_id, 'hamnoor_phone', $phone);
        $user = new WP_User($user_id);
        if ('1' === (string) $settings['auto_grant_manager']) {
            if (!get_role('hamnoor_manager')) { Hamnoor_DB::create_roles(); }
            if (get_role('hamnoor_manager')) { $user->set_role('hamnoor_manager'); }
        }
        wp_set_current_user($user_id); wp_set_auth_cookie($user_id, true);
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('register_success'));
    }

    public function handle_update_profile() {
        if (!is_user_logged_in()) { wp_die(esc_html__('برای ویرایش مشخصات وارد شوید.', 'hamnoor')); }
        check_admin_referer('hamnoor_update_profile');
        $user_id = get_current_user_id();
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$display_name || !is_email($email) || !Hamnoor_Validator::valid_iran_mobile($phone)) { Hamnoor_Helpers::redirect_with_notice(Hamnoor_Helpers::page_url('dashboard'), Hamnoor_Texts::get('form_incomplete'), 'error'); }
        $identity_errors = $this->profile_identity_errors($user_id, $email, $phone);
        if ($identity_errors) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard')), reset($identity_errors), 'error'); }
        $pass = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ('' !== $pass && strlen($pass) < 8) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard')), __('رمز عبور باید حداقل ۸ کاراکتر باشد.', 'hamnoor'), 'error'); }
        $updated = wp_update_user(array('ID'=>$user_id,'display_name'=>$display_name,'user_email'=>$email));
        if (is_wp_error($updated)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard')), $updated->get_error_message(), 'error'); }
        update_user_meta($user_id,'hamnoor_phone',$phone);
        if (strlen($pass) >= 8) { wp_set_password($pass, $user_id); wp_set_auth_cookie($user_id, true); }
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','profile',Hamnoor_Helpers::page_url('dashboard')), __('مشخصات شما ذخیره شد.', 'hamnoor'));
    }

    public function handle_feature_request() {
        if (!is_user_logged_in()) { wp_die(esc_html__('برای ثبت درخواست وارد حساب شوید.', 'hamnoor')); }
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        check_admin_referer('hamnoor_request_feature_' . $khatm_id);
        $message = isset($_POST['featured_request_message']) ? sanitize_textarea_field(wp_unslash($_POST['featured_request_message'])) : '';
        $result = Hamnoor_Featured_Service::create_request($khatm_id, $message, get_current_user_id());
        $redirect = add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, Hamnoor_Texts::get('feature_request_success'));
    }

    public function ajax_claim_part() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!empty($_POST['website'])) { wp_send_json_error(array('message'=>__('درخواست نامعتبر است.', 'hamnoor'))); }
        $token = isset($_POST['khatm_token']) ? Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token'])) : '';
        $khatm = Hamnoor_Khatm_Service::get($token);
        if (!$khatm) { wp_send_json_error(array('message'=>__('ختم معتبر نیست.', 'hamnoor'))); }
        $data = array('name'=>isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '', 'phone'=>isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '', 'email'=>isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '');
        $rate = $this->claim_rate_limit($khatm, $data);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message(), 'errors'=>array($rate->get_error_code()=>$rate->get_error_message()))); }
        $mode = isset($_POST['claim_mode']) ? sanitize_key(wp_unslash($_POST['claim_mode'])) : 'manual';
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0; $round = isset($_POST['hamnoor_round']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['hamnoor_round'])) : 0;
        $result = $this->claim_result($khatm, $data, $mode, $part_id, $round);
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message(), 'errors'=>array($result->get_error_code()=>$result->get_error_message()))); }
        $part = Hamnoor_DB::get_part((int) $result['part_id']);
        ob_start(); $this->render_template('frontend/claim-success.php', array('khatm'=>$khatm,'part'=>$part,'tracking_token'=>$result['tracking_token'])); $html=ob_get_clean();
        $show_name = $part && !empty($part->participant_name) && 'hidden' !== Hamnoor_Helpers::settings()['participant_name_display'];
        $part_status = $part ? (string) $part->status : 'claimed';
        $khatm_after_claim = Hamnoor_DB::get_khatm((int) $khatm->id);
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
        $show_name = $part && 'hidden' !== Hamnoor_Helpers::settings()['participant_name_display'];
        $reader_name = $show_name ? (!empty($part->participant_name) ? Hamnoor_Helpers::display_name($part->participant_name) : __('شرکت‌کننده', 'hamnoor')) : '';
        wp_send_json_success(array('message'=>Hamnoor_Texts::get('complete_success_full'), 'progress'=>$progress, 'khatm_status'=>$khatm ? $khatm->status : '', 'part_id' => (int) $part_id, 'participant_name' => $reader_name, 'show_participant_name' => $show_name ? 1 : 0));
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
        $result = Hamnoor_DB::release_part($part_id, $reason, get_current_user_id(), $contact, $access);
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
        $part = Hamnoor_DB::get_part($part_id);
        $progress = $part ? Hamnoor_DB::get_progress((int) $part->khatm_id) : array();
        wp_send_json_success(array(
            'message' => __('سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.', 'hamnoor'),
            'part_id' => (int) $part_id,
            'part_status' => 'available',
            'part_status_label' => Hamnoor_Texts::get('badge_available'),
            'progress' => $progress,
        ));
    }

    public function ajax_track_parts() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $raw = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $parsed = $this->parse_contact($raw);
        if (!empty($parsed['error'])) { wp_send_json_error(array('message'=>$parsed['error'], 'errors'=>array('contact'=>$parsed['error']))); }
        $rate = Hamnoor_Helpers::rate_limit('track_parts', $parsed['contact'], 30, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message(), 'errors'=>array('contact'=>$rate->get_error_message()))); }
        $parts = !empty($parsed['contact']) ? Hamnoor_DB::get_parts_by_contact($parsed['contact']) : array();
        ob_start(); $this->render_template('frontend/track-results.php', array('parts'=>$parts, 'contact'=>$parsed['contact'], 'contact_raw'=>$raw)); $html=ob_get_clean();
        wp_send_json_success(array('html'=>$html, 'count'=>count($parts)));
    }

    public function ajax_update_profile() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(array('message'=>__('برای ویرایش مشخصات وارد شوید.', 'hamnoor'))); }
        $user_id = get_current_user_id();
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $errors = array(); if (!$display_name) { $errors['display_name'] = __('نام نمایشی الزامی است.', 'hamnoor'); } if (!Hamnoor_Validator::valid_iran_mobile($phone)) { $errors['phone'] = Hamnoor_Texts::get('mobile_invalid'); } if (!is_email($email)) { $errors['email'] = Hamnoor_Texts::get('email_invalid'); }
        $errors = array_merge($errors, $this->profile_identity_errors($user_id, $email, $phone));
        $pass = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ('' !== $pass && strlen($pass) < 8) { $errors['password'] = __('رمز عبور باید حداقل ۸ کاراکتر باشد.', 'hamnoor'); }
        if ($errors) { wp_send_json_error(array('message'=>Hamnoor_Texts::get('form_incomplete'), 'errors'=>$errors)); }
        $updated = wp_update_user(array('ID'=>$user_id,'display_name'=>$display_name,'user_email'=>$email));
        if (is_wp_error($updated)) { wp_send_json_error(array('message'=>$updated->get_error_message(), 'errors'=>array('profile'=>$updated->get_error_message()))); }
        update_user_meta($user_id,'hamnoor_phone',$phone);
        if (strlen($pass) >= 8) { wp_set_password($pass, $user_id); wp_set_auth_cookie($user_id, true); }
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
