<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Product/UI adapter for one-owner, one-complete-Quran reading.
 *
 * This layer deliberately contains no Part/Claim semantics. Persistence and
 * authorization remain in the canonical campaign/progress domain services.
 */
final class Hamnoor_Personal_Quran {
    const TOTAL_PAGES = 604;

    public static function init() {
        add_action('admin_post_hamnoor_start_personal_quran', array(__CLASS__, 'handle_start'));
        add_action('admin_post_nopriv_hamnoor_start_personal_quran', array(__CLASS__, 'handle_start'));
        add_action('admin_post_hamnoor_personal_quran_lifecycle', array(__CLASS__, 'handle_lifecycle'));
        add_action('admin_post_nopriv_hamnoor_personal_quran_lifecycle', array(__CLASS__, 'handle_lifecycle'));
        add_action('wp_ajax_hamnoor_save_personal_quran_progress', array(__CLASS__, 'ajax_save_progress'));
        add_action('wp_ajax_nopriv_hamnoor_save_personal_quran_progress', array(__CLASS__, 'ajax_save_progress'));
        add_action('wp_ajax_hamnoor_finish_personal_quran', array(__CLASS__, 'ajax_finish'));
        add_action('wp_ajax_nopriv_hamnoor_finish_personal_quran', array(__CLASS__, 'ajax_finish'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 21);
    }

    public static function is_reader_request() {
        return !empty($_GET['hamnoor_personal_quran']);
    }

    public static function reader_url($campaign_id, $page = 0) {
        $args = array('hamnoor_personal_quran' => (int)$campaign_id);
        if ((int)$page > 0) { $args['hamnoor_quran_page'] = max(1, min(self::TOTAL_PAGES, (int)$page)); }
        return add_query_arg($args, Hamnoor_Helpers::page_url('dashboard'));
    }

    public static function dashboard_state($campaign) {
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) { return null; }
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id || (int)$campaign->owner_user_id !== (int)$user_id) { return null; }
        $summary = Hamnoor_Personal_Quran_Progress_Service::progress($campaign);
        if (is_wp_error($summary)) { return null; }
        $resume_page = self::page_for_global_index((int)$summary['resume_global_index']);
        return array(
            'campaign' => $campaign,
            'progress' => $summary,
            'percent' => (float)$summary['percentage'],
            'resume_page' => $resume_page,
            'url' => self::reader_url((int)$campaign->id, $resume_page),
            'started' => (int)$summary['last_global_index'] >= Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX,
            'ready' => Hamnoor_Personal_Quran_Progress_Service::STATUS_READY === (string)$summary['reading_status'],
            'paused' => Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED === (string)$campaign->lifecycle_status,
        );
    }

    public static function handle_start() {
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            Hamnoor_Helpers::redirect_with_notice($dashboard, __('برای شروع ختم شخصی، ابتدا پنل هم‌نور خود را باز کنید.', 'hamnoor'), 'error');
        }
        check_admin_referer('hamnoor_start_personal_quran');
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $rate = Hamnoor_Helpers::rate_limit('personal_quran_start', 'u:' . $user_id, 8, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice($dashboard, $rate->get_error_message(), 'error'); }

        $existing = Hamnoor_Campaign_Repository::find_open_personal_quran_for_owner($user_id);
        if ($existing) { wp_safe_redirect(self::reader_url((int)$existing->id)); exit; }

        $created = Hamnoor_Campaign_Service::create_personal_quran(array(), $user_id);
        if (is_wp_error($created)) {
            if ('hamnoor_personal_quran_active_exists' === $created->get_error_code()) {
                $existing = Hamnoor_Campaign_Repository::find_open_personal_quran_for_owner($user_id);
                if ($existing) { wp_safe_redirect(self::reader_url((int)$existing->id)); exit; }
            }
            Hamnoor_Helpers::redirect_with_notice($dashboard, $created->get_error_message(), 'error');
        }
        Hamnoor_Helpers::redirect_with_notice(self::reader_url((int)$created->id), __('ختم شخصی شما آغاز شد. از ابتدای قرآن شروع کنید.', 'hamnoor'), 'success');
    }

    public static function handle_lifecycle() {
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            Hamnoor_Helpers::redirect_with_notice($dashboard, __('برای مدیریت ختم شخصی، پنل هم‌نور خود را باز کنید.', 'hamnoor'), 'error');
        }
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        $target = isset($_POST['target_status']) ? sanitize_key(wp_unslash($_POST['target_status'])) : '';
        if (!$campaign_id || !in_array($target, array(Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE, Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED, Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED), true)) {
            Hamnoor_Helpers::redirect_with_notice($dashboard, __('درخواست مدیریت ختم شخصی معتبر نیست.', 'hamnoor'), 'error');
        }
        check_admin_referer('hamnoor_personal_quran_lifecycle_' . $campaign_id);
        $campaign = Hamnoor_Campaign_Repository::get_by_id($campaign_id);
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign) || (int)$campaign->owner_user_id !== $user_id) {
            Hamnoor_Helpers::redirect_with_notice($dashboard, __('به این ختم شخصی دسترسی ندارید.', 'hamnoor'), 'error');
        }
        $result = Hamnoor_Campaign_Service::transition_lifecycle($campaign_id, $target);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($dashboard, $result->get_error_message(), 'error'); }
        $messages = array(
            Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE => __('ختم شخصی از سر گرفته شد.', 'hamnoor'),
            Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED => __('ختم شخصی موقتاً متوقف شد.', 'hamnoor'),
            Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED => __('ختم شخصی لغو شد. هر زمان بخواهید می‌توانید ختم تازه‌ای آغاز کنید.', 'hamnoor'),
        );
        $redirect = Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED === $target ? $dashboard : self::reader_url($campaign_id);
        Hamnoor_Helpers::redirect_with_notice($redirect, $messages[$target], 'success');
    }

    public static function enqueue_assets() {
        if (!self::is_reader_request()) { return; }
        wp_enqueue_style('hamnoor-frontend', HAMNOOR_URL . 'assets/css/hamnoor-frontend.css', array(), Hamnoor_Helpers::asset_version('assets/css/hamnoor-frontend.css'));
        wp_add_inline_style('hamnoor-frontend', Hamnoor_Helpers::dynamic_css());
        wp_enqueue_style('hamnoor-reading', HAMNOOR_URL . 'assets/css/hamnoor-reading.css', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-reading.css'));
        wp_enqueue_style('hamnoor-personal-quran', HAMNOOR_URL . 'assets/css/hamnoor-personal-quran.css', array('hamnoor-reading'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-personal-quran.css'));
        wp_enqueue_style('hamnoor-reading-desktop', HAMNOOR_URL . 'assets/css/hamnoor-reading-desktop.css', array('hamnoor-reading'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-reading-desktop.css'), '(min-width: 783px)');
        wp_enqueue_script('hamnoor-reading', HAMNOOR_URL . 'assets/js/hamnoor-reading.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-reading.js'), true);
        wp_localize_script('hamnoor-reading', 'HamnoorReading', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hamnoor_personal_quran_reading'),
            'mode' => 'personal_quran',
            'id_field' => 'campaign_id',
            'actions' => array(
                'save' => 'hamnoor_save_personal_quran_progress',
                'finish' => 'hamnoor_finish_personal_quran',
                'audio' => '',
            ),
            'access' => '',
            'dashboard_url' => Hamnoor_Helpers::page_url('dashboard'),
            'texts' => array(
                'saving' => __('در حال ذخیره...', 'hamnoor'),
                'saved' => __('محل قرائت در ختم شخصی ذخیره شد.', 'hamnoor'),
                'error' => __('ذخیره پیشرفت انجام نشد. دوباره تلاش کنید.', 'hamnoor'),
                'finishing' => __('در حال ثبت پایان ختم...', 'hamnoor'),
                'finish_confirm' => __('به پایان قرآن رسیده‌اید و می‌خواهید این ختم شخصی تکمیل شود؟', 'hamnoor'),
                'finish_title' => __('ثبت پایان ختم شخصی', 'hamnoor'),
                'finish_button' => __('بله، ختم تکمیل شود', 'hamnoor'),
                'audio_error' => __('پخش صوت این آیه ممکن نشد.', 'hamnoor'),
                'audio_end_message' => __('صوت این صفحه به پایان رسید.', 'hamnoor'),
            ),
        ));
    }

    public static function render($campaign_id) {
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('برای مشاهده ختم شخصی، پنل هم‌نور خود را باز کنید.', 'hamnoor') . '</div>';
        }
        if (!Hamnoor_Reading::available()) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('هسته قرآن مناهل فعال یا آماده نیست.', 'hamnoor') . '</div>';
        }
        $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id);
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign) || (int)$campaign->owner_user_id !== $user_id) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('به این ختم شخصی دسترسی ندارید.', 'hamnoor') . '</div>';
        }
        $progress_row = Hamnoor_Personal_Quran_Progress_Service::get((int)$campaign->id);
        if (!$progress_row) {
            $progress_row = Hamnoor_Personal_Quran_Progress_Service::initialize((int)$campaign->id, $user_id);
            if (is_wp_error($progress_row)) { return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html($progress_row->get_error_message()) . '</div>'; }
        }
        $summary = Hamnoor_Personal_Quran_Progress_Service::progress($campaign);
        if (is_wp_error($summary)) { return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html($summary->get_error_message()) . '</div>'; }

        $requested_page = isset($_GET['hamnoor_quran_page']) ? absint(Hamnoor_Validator::normalize_digits(wp_unslash($_GET['hamnoor_quran_page']))) : 0;
        $resume_page = self::page_for_global_index((int)$summary['resume_global_index']);
        $page = $requested_page ? max(1, min(self::TOTAL_PAGES, $requested_page)) : $resume_page;
        $range = self::page_range($page);
        if (is_wp_error($range)) { return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html($range->get_error_message()) . '</div>'; }
        $verses = self::load_page_verses($range);
        if (!$verses) { return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('آیات این صفحه در هسته قرآن پیدا نشد.', 'hamnoor') . '</div>'; }

        $last = (int)$summary['last_global_index'];
        $resume = (int)$summary['resume_global_index'];
        $percent = (float)$summary['percentage'];
        $is_done = !empty($summary['is_completed']) || Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED === (string)$campaign->lifecycle_status;
        $ready = !$is_done && $last >= Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX;
        $is_paused = Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED === (string)$campaign->lifecycle_status;
        $previous_page_url = $page > 1 ? self::reader_url((int)$campaign->id, $page - 1) : '';
        $next_page_url = $page < self::TOTAL_PAGES ? self::reader_url((int)$campaign->id, $page + 1) : '';
        $resume_url = self::reader_url((int)$campaign->id, $resume_page) . '#hamnoor-verse-' . $resume;
        $reading_settings = Hamnoor_Helpers::settings();
        $default_arabic_size = max(28, min(56, (int)($reading_settings['reading_arabic_size'] ?? 38)));
        $default_translation_size = max(14, min(28, (int)($reading_settings['reading_translation_size'] ?? 18)));

        ob_start();
        include Hamnoor_Helpers::locate_template('frontend/personal-quran-reading.php');
        return ob_get_clean();
    }

    private static function page_range($page) {
        $range = manahel_quran_core()->get_division('page', (int)$page);
        if (!$range || empty($range['start_global_index']) || empty($range['end_global_index'])) {
            return new WP_Error('hamnoor_personal_quran_page_missing', __('محدوده این صفحه قرآن پیدا نشد.', 'hamnoor'));
        }
        return array(
            'page' => (int)$page,
            'start_global_index' => (int)$range['start_global_index'],
            'end_global_index' => (int)$range['end_global_index'],
        );
    }

    private static function load_page_verses($range) {
        $start = (int)$range['start_global_index'];
        $end = (int)$range['end_global_index'];
        $verses = manahel_quran_core()->get_range_by_index($start, $end, max(20, $end - $start + 1));
        return is_array($verses) ? $verses : array();
    }

    public static function page_for_global_index($global_index) {
        if (!Hamnoor_Reading::available()) { return 1; }
        $target = max(Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX, min(Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX, (int)$global_index));
        $lo = 1; $hi = self::TOTAL_PAGES;
        while ($lo <= $hi) {
            $mid = (int)floor(($lo + $hi) / 2);
            $range = manahel_quran_core()->get_division('page', $mid);
            if (!$range) { break; }
            $start = (int)$range['start_global_index'];
            $end = (int)$range['end_global_index'];
            if ($target < $start) { $hi = $mid - 1; }
            elseif ($target > $end) { $lo = $mid + 1; }
            else { return $mid; }
        }
        return max(1, min(self::TOTAL_PAGES, $lo));
    }

    public static function ajax_save_progress() {
        check_ajax_referer('hamnoor_personal_quran_reading', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_send_json_error(array('message'=>__('نشست هم‌نور شما در دسترس نیست.', 'hamnoor'))); }
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        $global_index = isset($_POST['global_index']) ? absint(Hamnoor_Validator::normalize_digits(wp_unslash($_POST['global_index']))) : 0;
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $rate = Hamnoor_Helpers::rate_limit('personal_quran_progress', $campaign_id . '|u:' . $user_id, 120, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message())); }
        $saved = Hamnoor_Personal_Quran_Progress_Service::save_position($campaign_id, $user_id, $global_index);
        if (is_wp_error($saved)) { wp_send_json_error(array('message'=>$saved->get_error_message())); }
        $campaign = Hamnoor_Campaign_Repository::get_by_id($campaign_id);
        $summary = Hamnoor_Personal_Quran_Progress_Service::progress($campaign);
        if (is_wp_error($summary)) { wp_send_json_error(array('message'=>$summary->get_error_message())); }
        wp_send_json_success(array(
            'message'=>__('پیشرفت ختم شخصی ذخیره شد.', 'hamnoor'),
            'global_index'=>(int)$summary['last_global_index'],
            'percent'=>(float)$summary['percentage'],
            'ready'=>Hamnoor_Personal_Quran_Progress_Service::STATUS_READY === (string)$summary['reading_status'],
        ));
    }

    public static function ajax_finish() {
        check_ajax_referer('hamnoor_personal_quran_reading', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_send_json_error(array('message'=>__('نشست هم‌نور شما در دسترس نیست.', 'hamnoor'))); }
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $done = Hamnoor_Personal_Quran_Progress_Service::complete($campaign_id, $user_id);
        if (is_wp_error($done)) { wp_send_json_error(array('message'=>$done->get_error_message())); }
        $campaign = Hamnoor_Campaign_Repository::get_by_id($campaign_id);
        $extension_feedback = $campaign ? Hamnoor_Domain_Events::completion_extension_feedback(
            'personal_quran',
            Hamnoor_Domain_Events::stable_entity_id(isset($campaign->campaign_public_id) ? $campaign->campaign_public_id : '', 'campaign', (int)$campaign->id)
        ) : array();
        wp_send_json_success(array(
            'message'=>__('ختم شخصی قرآن با موفقیت تکمیل شد.', 'hamnoor'),
            'redirect'=>add_query_arg('personal_quran_completed', 1, Hamnoor_Helpers::page_url('dashboard')),
            'extension_feedback'=>$extension_feedback,
        ));
    }
}
