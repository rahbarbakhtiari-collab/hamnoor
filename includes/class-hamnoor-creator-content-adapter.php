<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Creator adapter for canonical Dua/Dhikr campaigns.
 *
 * Quran creation remains on the established segmented-claim frontend. This
 * adapter intercepts only non-Quran creator requests and routes them through
 * Command_Contracts -> Campaign_Service. No internal content-item IDs are
 * exposed to the browser; Creator works with CNT-* public IDs only.
 */
final class Hamnoor_Creator_Content_Adapter {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 40);

        add_action('admin_post_hamnoor_create_khatm', array(__CLASS__, 'maybe_handle_create'), 1);
        add_action('admin_post_nopriv_hamnoor_create_khatm', array(__CLASS__, 'maybe_handle_create'), 1);
        add_action('admin_post_hamnoor_update_khatm', array(__CLASS__, 'guard_legacy_update'), 1);
        add_action('admin_post_nopriv_hamnoor_update_khatm', array(__CLASS__, 'guard_legacy_update'), 1);

        add_action('wp_ajax_hamnoor_creator_content_search', array(__CLASS__, 'ajax_content_search'));
        add_action('wp_ajax_nopriv_hamnoor_creator_content_search', array(__CLASS__, 'ajax_content_search'));

        // Intercept only non-Quran wizard validation/draft writes. Returning for
        // Quran lets the established frontend callbacks continue unchanged.
        add_action('wp_ajax_hamnoor_validate_create_step', array(__CLASS__, 'maybe_validate_step'), 1);
        add_action('wp_ajax_nopriv_hamnoor_validate_create_step', array(__CLASS__, 'maybe_validate_step'), 1);
        add_action('wp_ajax_hamnoor_save_khatm_draft', array(__CLASS__, 'maybe_save_draft'), 1);
        add_action('wp_ajax_nopriv_hamnoor_save_khatm_draft', array(__CLASS__, 'maybe_save_draft'), 1);
    }

    public static function enqueue_assets() {
        $frontend = Hamnoor_Frontend::instance();
        if (!$frontend->is_shortcode_context() && !Hamnoor_Router::instance()->is_hamnoor_request()) { return; }

        wp_enqueue_style(
            'hamnoor-creator-content',
            HAMNOOR_URL . 'assets/css/hamnoor-creator-content.css',
            array('hamnoor-ui-v4'),
            Hamnoor_Helpers::asset_version('assets/css/hamnoor-creator-content.css')
        );
        wp_enqueue_script(
            'hamnoor-creator-content',
            HAMNOOR_URL . 'assets/js/hamnoor-creator-content.js',
            array('hamnoor-frontend'),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-creator-content.js'),
            true
        );
    }

    private static function requested_type($source) {
        $source = is_array($source) ? $source : array();
        return isset($source['content_type']) ? sanitize_key(wp_unslash($source['content_type'])) : Hamnoor_Campaign_Domain::CONTENT_QURAN;
    }

    private static function is_supported_type($type) {
        return in_array((string)$type, array(
            Hamnoor_Campaign_Domain::CONTENT_DUA,
            Hamnoor_Campaign_Domain::CONTENT_DHIKR,
        ), true);
    }

    private static function current_create_url() {
        return wp_get_referer() ?: Hamnoor_Helpers::page_url('create');
    }

    private static function field_error($code, $message, $field) {
        return new WP_Error((string)$code, (string)$message, array('field'=>sanitize_key((string)$field)));
    }

    private static function error_fields($error) {
        if (!is_wp_error($error)) { return array(); }
        $fallback = array(
            'hamnoor_command_title_required'=>'title',
            'hamnoor_campaign_title_required'=>'title',
            'hamnoor_command_content_reference_invalid'=>'content_id',
            'hamnoor_campaign_content_reference_required'=>'content_id',
            'hamnoor_campaign_content_disabled'=>'content_id',
            'hamnoor_campaign_content_not_found'=>'content_id',
            'hamnoor_creator_target_required'=>'target',
            'hamnoor_creator_start_invalid'=>'starts_at',
            'hamnoor_creator_end_invalid'=>'ends_at',
            'hamnoor_date_order'=>'ends_at',
            'hamnoor_cover_size'=>'cover_file',
            'hamnoor_cover_type'=>'cover_file',
            'hamnoor_cover_upload'=>'cover_file',
            'hamnoor_cover_required'=>'cover_file',
        );
        $fields = array();
        foreach ($error->get_error_codes() as $code) {
            $data = $error->get_error_data($code);
            $field = is_array($data) && !empty($data['field']) ? sanitize_key((string)$data['field']) : ($fallback[$code] ?? sanitize_key((string)$code));
            if (!isset($fields[$field])) { $fields[$field] = $error->get_error_message($code); }
        }
        return $fields;
    }

    private static function redirect_form_error($error, $source = array()) {
        $safe = Hamnoor_DB::sanitize_khatm_data(is_array($source) ? $source : array());
        foreach (array('content_type','content_id','target') as $key) {
            if (!isset($source[$key])) { continue; }
            if ('content_type' === $key) { $safe[$key] = sanitize_key(wp_unslash($source[$key])); }
            elseif ('target' === $key) { $safe[$key] = (string)max(0, (int)Hamnoor_Validator::normalize_digits(wp_unslash($source[$key]))); }
            else { $safe[$key] = strtoupper(sanitize_text_field(wp_unslash($source[$key]))); }
        }
        $return_url = add_query_arg(array(
            'hamnoor_creator_type'=>isset($safe['content_type']) ? sanitize_key((string)$safe['content_type']) : '',
            'hamnoor_creator_content'=>isset($safe['content_id']) ? rawurlencode((string)$safe['content_id']) : '',
            'hamnoor_creator_target'=>isset($safe['target']) ? (int)$safe['target'] : 0,
        ), self::current_create_url());
        Hamnoor_Helpers::redirect_with_form(
            $return_url,
            is_wp_error($error) ? $error->get_error_message() : __('ایجاد پویش انجام نشد.', 'hamnoor'),
            'error',
            'khatm_create',
            $safe,
            self::error_fields($error)
        );
    }

    private static function canonical_payload($source) {
        $source = is_array($source) ? $source : array();
        $type = self::requested_type($source);
        if (!self::is_supported_type($type)) {
            return self::field_error('hamnoor_creator_content_type_invalid', __('نوع محتوای انتخاب‌شده معتبر نیست.', 'hamnoor'), 'content_type');
        }

        $target_raw = isset($source['target']) ? Hamnoor_Validator::normalize_digits(wp_unslash($source['target'])) : '';
        $target = (int)$target_raw;
        if ($target < 1) {
            return self::field_error('hamnoor_creator_target_required', __('هدف پویش باید عددی بزرگ‌تر از صفر باشد.', 'hamnoor'), 'target');
        }

        $start_raw = isset($source['starts_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['starts_at_jalali']))) : '';
        $end_raw = isset($source['ends_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['ends_at_jalali']))) : '';
        $starts_at = '' !== $start_raw ? Hamnoor_Validator::normalize_datetime($start_raw, false) : '';
        $ends_at = '' !== $end_raw ? Hamnoor_Validator::normalize_datetime($end_raw, true) : '';
        if ('' !== $start_raw && '' === $starts_at) {
            return self::field_error('hamnoor_creator_start_invalid', __('فرمت زمان شروع معتبر نیست.', 'hamnoor'), 'starts_at');
        }
        if ('' !== $end_raw && '' === $ends_at) {
            return self::field_error('hamnoor_creator_end_invalid', __('فرمت زمان پایان معتبر نیست.', 'hamnoor'), 'ends_at');
        }
        $date_check = Hamnoor_Validator::validate_dates($starts_at, $ends_at);
        if (is_wp_error($date_check)) {
            return self::field_error($date_check->get_error_code(), $date_check->get_error_message(), 'ends_at');
        }

        $privacy = isset($source['privacy']) && 'private' === sanitize_key(wp_unslash($source['privacy'])) ? 'private' : 'public';
        $payload = array(
            'title'=>isset($source['title']) ? sanitize_text_field(wp_unslash($source['title'])) : '',
            'intention'=>isset($source['intention']) ? wp_kses_post(wp_unslash($source['intention'])) : '',
            'description'=>isset($source['description']) ? wp_kses_post(wp_unslash($source['description'])) : '',
            'content_type'=>$type,
            'content_id'=>isset($source['content_id']) ? strtoupper(sanitize_text_field(wp_unslash($source['content_id']))) : '',
            'target'=>$target,
            'access_mode'=>'private' === $privacy ? Hamnoor_Campaign_Domain::ACCESS_UNLISTED : Hamnoor_Campaign_Domain::ACCESS_PUBLIC,
            'indexable'=>'public' === $privacy && !empty($source['indexable']) ? 1 : 0,
            'starts_at'=>$starts_at,
            'ends_at'=>$ends_at,
        );
        return Hamnoor_Command_Contracts::campaign_create($payload);
    }

    private static function cover_upload($source) {
        $source = is_array($source) ? $source : array();
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { return $upload; }
        $settings = Hamnoor_Helpers::settings();
        $mode = isset($settings['cover_required_mode']) ? (string)$settings['cover_required_mode'] : 'optional';
        $privacy = isset($source['privacy']) && 'private' === sanitize_key(wp_unslash($source['privacy'])) ? 'private' : 'public';
        $required = 'always' === $mode || ('public' === $mode && 'public' === $privacy);
        if ($required && empty($upload['cover_url'])) {
            return self::field_error('hamnoor_cover_required', __('تصویر پویش برای این حالت الزامی است.', 'hamnoor'), 'cover_file');
        }
        return is_array($upload) ? $upload : array();
    }

    public static function maybe_handle_create() {
        $type = self::requested_type($_POST);
        if (!self::is_supported_type($type)) { return; }

        if (!Hamnoor_Mobile_Access::is_logged_in() || !Hamnoor_Permissions::can_create_khatm()) {
            wp_die(esc_html__('برای ایجاد پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor'));
        }
        check_admin_referer('hamnoor_create_khatm');
        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) {
            wp_die(esc_html__('ایجاد پویش جدید در حال حاضر موقتاً غیرفعال است. لطفاً بعداً مراجعه کنید.', 'hamnoor'));
        }

        $data = self::canonical_payload($_POST);
        if (is_wp_error($data)) { self::redirect_form_error($data, $_POST); }

        // Presentation metadata remains outside structural campaign definition.
        // The upload helper validates the file; persistence still goes through
        // the canonical Campaign repository rather than direct SQL.
        $cover = self::cover_upload($_POST);
        if (is_wp_error($cover)) { self::redirect_form_error($cover, $_POST); }

        $owner_user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $campaign = Hamnoor_Campaign_Service::create($data, $owner_user_id);
        if (is_wp_error($campaign)) { self::redirect_form_error($campaign, $_POST); }

        if (!empty($cover['cover_id']) || !empty($cover['cover_url'])) {
            $cover_fields = array();
            if (!empty($cover['cover_id'])) { $cover_fields['cover_id'] = (int)$cover['cover_id']; }
            if (!empty($cover['cover_url'])) { $cover_fields['cover_url'] = esc_url_raw((string)$cover['cover_url']); }
            if (!Hamnoor_Campaign_Repository::update_fields((int)$campaign->id, $cover_fields)) {
                error_log('[Hamnoor] Creator content campaign cover metadata could not be persisted for campaign ' . (int)$campaign->id);
            }
        }

        delete_transient('hamnoor_khatm_draft_' . $owner_user_id . '_0');
        Hamnoor_Mobile_Access::consume_create_access();
        Hamnoor_Helpers::redirect_with_notice(
            add_query_arg('created_khatm', (int)$campaign->id, Hamnoor_Helpers::page_url('dashboard')),
            __('درخواست پویش ثبت شد و برای بررسی مدیر سایت ارسال گردید.', 'hamnoor')
        );
    }

    /**
     * Legacy edit UI is Quran/Parts-specific. Until the dedicated canonical
     * contribution editor is enabled, never let a Dua/Dhikr campaign fall
     * through to Hamnoor_DB::update_khatm(), which owns claim/release settings.
     */
    public static function guard_legacy_update() {
        $khatm_id = isset($_POST['khatm_id']) ? absint($_POST['khatm_id']) : 0;
        if (!$khatm_id) { return; }
        $campaign = Hamnoor_Campaign_Repository::get_by_id($khatm_id);
        if (!$campaign || !in_array((string)$campaign->participation_strategy, array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true)) { return; }

        $redirect = add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
        check_admin_referer('hamnoor_update_khatm_' . $khatm_id);
        if (!Hamnoor_DB::user_can_manage_khatm($khatm_id)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('دسترسی کافی برای ویرایش این پویش ندارید.', 'hamnoor'), 'error');
        }
        $is_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        if (!$is_admin && !Hamnoor_Mobile_Access::has_sensitive_access()) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('برای ویرایش پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor'), 'error');
        }
        Hamnoor_Helpers::redirect_with_notice(
            $redirect,
            __('این پویش از مدل مشارکت دعا/ذکر استفاده می‌کند و برای جلوگیری از تغییر ناخواسته ساختار، با ویرایشگر قدیمی قرآن ذخیره نمی‌شود. ویرایش اختصاصی این نوع پویش در مسیر canonical انجام خواهد شد.', 'hamnoor'),
            'warning'
        );
    }

    public static function ajax_content_search() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            wp_send_json_error(array('message'=>__('برای انتخاب محتوا، ابتدا وارد پنل هم‌نور شوید.', 'hamnoor')), 401);
        }
        $type = self::requested_type($_POST);
        if (!self::is_supported_type($type)) {
            wp_send_json_error(array('message'=>__('نوع محتوای انتخاب‌شده معتبر نیست.', 'hamnoor')), 422);
        }
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $rows = Hamnoor_Content_Repository::creator_items($type, $search, 50);
        $items = array();
        foreach ((array)$rows as $row) {
            $items[] = array(
                'id'=>(string)$row->public_id,
                'title'=>(string)$row->title,
                'source_type'=>(string)$row->source_type,
                'max_contribution'=>!empty($row->max_contribution) ? (int)$row->max_contribution : null,
            );
        }
        wp_send_json_success(array('items'=>$items, 'count'=>count($items)));
    }

    public static function maybe_validate_step() {
        $type = self::requested_type($_POST);
        if (!self::is_supported_type($type)) { return; }
        check_ajax_referer('hamnoor_frontend', 'nonce');

        $errors = array();
        $data = self::canonical_payload($_POST);
        if (is_wp_error($data)) { $errors = self::error_fields($data); }
        wp_send_json_success(array('errors'=>$errors, 'valid'=>empty($errors)));
    }

    public static function maybe_save_draft() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $raw_json = isset($_POST['draft']) ? wp_unslash((string)$_POST['draft']) : '';
        $raw = json_decode($raw_json, true);
        if (!is_array($raw)) { return; }
        $type = self::requested_type($raw);
        if (!self::is_supported_type($type)) { return; }

        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message'=>__('برای ذخیره پیش‌نویس، نشست هم‌نور باید فعال باشد.', 'hamnoor')), 401);
        }
        $scope = isset($_POST['scope']) ? absint($_POST['scope']) : 0;
        if ($scope) {
            // Structural edit UI for contribution-ledger campaigns is a later
            // stage; do not take over legacy edit drafts here.
            return;
        }

        $data = Hamnoor_DB::sanitize_khatm_data($raw);
        $data['content_type'] = $type;
        $data['content_id'] = isset($raw['content_id']) ? strtoupper(sanitize_text_field((string)$raw['content_id'])) : '';
        $data['target'] = isset($raw['target']) ? (string)max(0, (int)Hamnoor_Validator::normalize_digits((string)$raw['target'])) : '';
        foreach (array('starts_at_jalali','ends_at_jalali') as $field) {
            if (isset($raw[$field])) { $data[$field] = sanitize_text_field(Hamnoor_Validator::normalize_digits((string)$raw[$field])); }
        }
        $step = isset($_POST['step']) ? max(1, min(5, absint($_POST['step']))) : 1;
        $payload = array('data'=>$data, 'step'=>$step, 'updated_at'=>current_time('mysql'));
        set_transient('hamnoor_khatm_draft_' . $user_id . '_0', $payload, 14 * DAY_IN_SECONDS);
        wp_send_json_success(array('saved'=>1, 'updated_at'=>$payload['updated_at']));
    }
}

add_action('plugins_loaded', array('Hamnoor_Creator_Content_Adapter', 'init'), 5);
