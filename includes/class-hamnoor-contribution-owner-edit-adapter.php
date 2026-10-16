<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Owner UI adapter for canonical Dua/Dhikr campaign editing.
 *
 * The legacy edit form is retained as the page shell, but contribution-ledger
 * writes are intercepted before Hamnoor_DB::update_khatm(). Quran Part/Claim
 * settings are presentation-only legacy controls and are never persisted here.
 */
final class Hamnoor_Contribution_Owner_Edit_Adapter {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 45);
        add_action('admin_post_hamnoor_update_khatm', array(__CLASS__, 'maybe_handle_update'), 0);
        add_action('admin_post_nopriv_hamnoor_update_khatm', array(__CLASS__, 'maybe_handle_update'), 0);
        add_action('wp_ajax_hamnoor_validate_create_step', array(__CLASS__, 'maybe_validate_step'), 0);
        add_action('wp_ajax_nopriv_hamnoor_validate_create_step', array(__CLASS__, 'maybe_validate_step'), 0);
        add_action('wp_ajax_hamnoor_save_khatm_draft', array(__CLASS__, 'maybe_save_draft'), 0);
        add_action('wp_ajax_nopriv_hamnoor_save_khatm_draft', array(__CLASS__, 'maybe_save_draft'), 0);
    }

    private static function campaign_id_from_request($source = null) {
        $source = is_array($source) ? $source : $_REQUEST;
        if (!empty($source['khatm_id'])) { return absint($source['khatm_id']); }
        if (!empty($source['scope'])) { return absint($source['scope']); }
        return 0;
    }

    private static function is_supported_campaign($campaign) {
        return $campaign && in_array((string)($campaign->participation_strategy ?? ''), array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true);
    }

    private static function get_supported_campaign($campaign_id) {
        $campaign = $campaign_id ? Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id) : null;
        return self::is_supported_campaign($campaign) ? $campaign : null;
    }

    private static function current_content($campaign) {
        if (!$campaign || empty($campaign->content_item_id)) { return null; }
        return Hamnoor_Content_Repository::get((int)$campaign->content_item_id);
    }

    private static function edit_url($campaign_id) {
        return add_query_arg('edit_khatm', (int)$campaign_id, Hamnoor_Helpers::page_url('create'));
    }

    private static function dashboard_url() {
        return add_query_arg('tab', 'my-khatms', Hamnoor_Helpers::page_url('dashboard'));
    }

    private static function error_fields($error) {
        if (!is_wp_error($error)) { return array(); }
        $fallback = array(
            'hamnoor_campaign_title_required'=>'title',
            'hamnoor_command_content_reference_invalid'=>'content_id',
            'hamnoor_campaign_content_reference_required'=>'content_id',
            'hamnoor_campaign_content_not_found'=>'content_id',
            'hamnoor_campaign_content_disabled'=>'content_id',
            'hamnoor_command_target_invalid'=>'target',
            'hamnoor_campaign_target_required'=>'target',
            'hamnoor_campaign_date_invalid'=>'starts_at',
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

    private static function safe_form_payload($source) {
        $source = is_array($source) ? $source : array();
        $safe = Hamnoor_DB::sanitize_khatm_data($source);
        foreach (array('content_id','target','content_type') as $key) {
            if (!array_key_exists($key, $source)) { continue; }
            if ('target' === $key) { $safe[$key] = (string)max(0, (int)Hamnoor_Validator::normalize_digits(wp_unslash($source[$key]))); }
            elseif ('content_type' === $key) { $safe[$key] = sanitize_key(wp_unslash($source[$key])); }
            else { $safe[$key] = strtoupper(sanitize_text_field(wp_unslash($source[$key]))); }
        }
        return $safe;
    }

    private static function redirect_form_error($campaign_id, $error, $source) {
        Hamnoor_Helpers::redirect_with_form(
            self::edit_url($campaign_id),
            is_wp_error($error) ? $error->get_error_message() : __('ویرایش پویش انجام نشد.', 'hamnoor'),
            'error',
            'khatm_edit',
            self::safe_form_payload($source),
            self::error_fields($error)
        );
    }

    private static function parse_datetime($source, $field, $end_of_day = false) {
        $raw = isset($source[$field]) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source[$field]))) : '';
        if ('' === $raw) { return null; }
        $normalized = Hamnoor_Validator::normalize_datetime($raw, $end_of_day);
        if (!$normalized) {
            return new WP_Error('hamnoor_campaign_date_invalid', __('فرمت زمان‌بندی معتبر نیست.', 'hamnoor'), array('field'=>$field));
        }
        return $normalized;
    }

    /** Browser payload -> Command Contract payload. Structural keys are included only when changed. */
    private static function command_payload($campaign, $source) {
        $source = is_array($source) ? $source : array();
        $title = isset($source['title']) ? sanitize_text_field(wp_unslash($source['title'])) : (string)$campaign->title;
        if ('' === trim($title)) {
            return new WP_Error('hamnoor_campaign_title_required', __('عنوان پویش الزامی است.', 'hamnoor'), array('field'=>'title'));
        }

        $starts_at = self::parse_datetime($source, 'starts_at_jalali', false);
        if (is_wp_error($starts_at)) { return $starts_at; }
        $ends_at = self::parse_datetime($source, 'ends_at_jalali', true);
        if (is_wp_error($ends_at)) { return $ends_at; }
        $date_check = Hamnoor_Validator::validate_dates($starts_at ?: '', $ends_at ?: '');
        if (is_wp_error($date_check)) { return $date_check; }

        $privacy = isset($source['privacy']) && 'private' === sanitize_key(wp_unslash($source['privacy'])) ? 'private' : 'public';
        $payload = array(
            'title'=>$title,
            'intention'=>isset($source['intention']) ? wp_kses_post(wp_unslash($source['intention'])) : (string)$campaign->intention,
            'description'=>isset($source['description']) ? wp_kses_post(wp_unslash($source['description'])) : (string)$campaign->description,
            'access_mode'=>'private' === $privacy ? Hamnoor_Campaign_Domain::ACCESS_UNLISTED : Hamnoor_Campaign_Domain::ACCESS_PUBLIC,
            'indexable'=>'public' === $privacy && !empty($source['indexable']) ? 1 : 0,
            'starts_at'=>$starts_at,
            'ends_at'=>$ends_at,
        );

        $current_item = self::current_content($campaign);
        $current_public_id = $current_item ? strtoupper((string)$current_item->public_id) : '';
        if (array_key_exists('content_id', $source)) {
            $requested_public_id = strtoupper(sanitize_text_field(wp_unslash($source['content_id'])));
            if ($requested_public_id !== $current_public_id) { $payload['content_id'] = $requested_public_id; }
        }
        if (array_key_exists('target', $source)) {
            $requested_target = (int)Hamnoor_Validator::normalize_digits(wp_unslash($source['target']));
            if ($requested_target !== (int)$campaign->target_quantity) { $payload['target'] = $requested_target; }
        }
        return $payload;
    }

    private static function cover_update($campaign, $source) {
        $source = is_array($source) ? $source : array();
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { return $upload; }

        $remove = !empty($source['remove_cover']);
        $next_url = $remove ? '' : (string)($campaign->cover_url ?? '');
        $next_id = $remove ? 0 : (int)($campaign->cover_id ?? 0);
        if (is_array($upload) && !empty($upload['cover_url'])) {
            $next_url = esc_url_raw((string)$upload['cover_url']);
            $next_id = !empty($upload['cover_id']) ? (int)$upload['cover_id'] : 0;
        }

        $settings = Hamnoor_Helpers::settings();
        $mode = isset($settings['cover_required_mode']) ? (string)$settings['cover_required_mode'] : 'optional';
        $privacy = isset($source['privacy']) && 'private' === sanitize_key(wp_unslash($source['privacy'])) ? 'private' : 'public';
        $required = 'always' === $mode || ('public' === $mode && 'public' === $privacy);
        if ($required && !$next_url) {
            return new WP_Error('hamnoor_cover_required', __('تصویر پویش برای این حالت الزامی است.', 'hamnoor'), array('field'=>'cover_file'));
        }

        $changed = $next_url !== (string)($campaign->cover_url ?? '') || $next_id !== (int)($campaign->cover_id ?? 0);
        return $changed ? array('cover_url'=>$next_url, 'cover_id'=>$next_id) : array();
    }

    public static function enqueue_assets() {
        $campaign_id = isset($_GET['edit_khatm']) ? absint($_GET['edit_khatm']) : 0;
        $campaign = self::get_supported_campaign($campaign_id);
        if (!$campaign || !Hamnoor_DB::user_can_manage_khatm((int)$campaign->id)) { return; }

        $item = self::current_content($campaign);
        $structure_locked = !empty($campaign->first_participation_at);
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $draft = $user_id ? get_transient('hamnoor_khatm_draft_' . $user_id . '_' . (int)$campaign->id) : false;
        $draft_data = is_array($draft) && !empty($draft['data']) && is_array($draft['data']) ? $draft['data'] : array();
        $target = (int)$campaign->target_quantity;
        if (!$structure_locked) {
            $draft_content_id = !empty($draft_data['content_id']) ? strtoupper(sanitize_text_field((string)$draft_data['content_id'])) : '';
            $draft_item = $draft_content_id ? Hamnoor_Content_Repository::get_by_public_id($draft_content_id) : null;
            if ($draft_item && (string)$draft_item->content_type === (string)$campaign->content_type) { $item = $draft_item; }
            if (array_key_exists('target', $draft_data)) { $target = max(1, (int)$draft_data['target']); }
        }

        wp_enqueue_style(
            'hamnoor-contribution-owner-edit',
            HAMNOOR_URL . 'assets/css/hamnoor-contribution-owner-edit.css',
            array('hamnoor-ui-v4'),
            Hamnoor_Helpers::asset_version('assets/css/hamnoor-contribution-owner-edit.css')
        );
        wp_enqueue_script(
            'hamnoor-contribution-owner-edit',
            HAMNOOR_URL . 'assets/js/hamnoor-contribution-owner-edit.js',
            array('hamnoor-frontend'),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-contribution-owner-edit.js'),
            true
        );
        wp_localize_script('hamnoor-contribution-owner-edit', 'HamnoorContributionEdit', array(
            'campaign_id'=>(int)$campaign->id,
            'content_type'=>(string)$campaign->content_type,
            'strategy'=>(string)$campaign->participation_strategy,
            'content_id'=>$item ? (string)$item->public_id : '',
            'content_title'=>$item ? (string)$item->title : '',
            'target'=>max(1, $target),
            'structure_locked'=>$structure_locked ? 1 : 0,
            'first_participation_at'=>!empty($campaign->first_participation_at) ? (string)$campaign->first_participation_at : '',
        ));
    }

    public static function maybe_handle_update() {
        $campaign_id = self::campaign_id_from_request($_POST);
        $campaign = self::get_supported_campaign($campaign_id);
        if (!$campaign) { return; }

        check_admin_referer('hamnoor_update_khatm_' . $campaign_id);
        if (!Hamnoor_DB::user_can_manage_khatm($campaign_id)) {
            self::redirect_form_error($campaign_id, new WP_Error('hamnoor_campaign_edit_denied', __('دسترسی کافی برای ویرایش این پویش ندارید.', 'hamnoor')), $_POST);
        }
        $is_admin = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        if (!$is_admin && !Hamnoor_Mobile_Access::has_sensitive_access()) {
            self::redirect_form_error($campaign_id, new WP_Error('hamnoor_campaign_step_up_required', __('برای ویرایش پویش، هویت این نشست باید به‌تازگی تأیید شده باشد.', 'hamnoor')), $_POST);
        }

        $payload = self::command_payload($campaign, $_POST);
        if (is_wp_error($payload)) { self::redirect_form_error($campaign_id, $payload, $_POST); }
        $fields = Hamnoor_Command_Contracts::campaign_update($payload, $campaign);
        if (is_wp_error($fields)) { self::redirect_form_error($campaign_id, $fields, $_POST); }

        $validated = Hamnoor_Contribution_Campaign_Edit_Service::validate_update($campaign, $fields);
        if (is_wp_error($validated)) { self::redirect_form_error($campaign_id, $validated, $_POST); }
        $cover_fields = self::cover_update($campaign, $_POST);
        if (is_wp_error($cover_fields)) { self::redirect_form_error($campaign_id, $cover_fields, $_POST); }

        $result = Hamnoor_Contribution_Campaign_Edit_Service::update($campaign_id, $fields);
        if (is_wp_error($result)) { self::redirect_form_error($campaign_id, $result, $_POST); }
        if ($cover_fields && !Hamnoor_Campaign_Repository::update_fields($campaign_id, $cover_fields)) {
            self::redirect_form_error($campaign_id, new WP_Error('hamnoor_cover_update_failed', __('ویرایش پویش ذخیره شد اما ذخیره تصویر انجام نشد. لطفاً دوباره تلاش کنید.', 'hamnoor'), array('field'=>'cover_file')), $_POST);
        }

        $owner_resubmission = !$is_admin && 'needs_revision' === (string)($campaign->status ?? '');
        if ($owner_resubmission) {
            $state = Hamnoor_Khatm_Service::change_status($campaign_id, 'pending_review', '', 'owner');
            if (is_wp_error($state)) { self::redirect_form_error($campaign_id, $state, $_POST); }
        }

        delete_transient('hamnoor_khatm_draft_' . (int)Hamnoor_Mobile_Access::current_user_id() . '_' . $campaign_id);
        Hamnoor_Helpers::redirect_with_notice(
            self::dashboard_url(),
            $owner_resubmission ? __('تغییرات ذخیره شد و پویش دوباره برای بررسی مدیر سایت ارسال گردید.', 'hamnoor') : __('تغییرات پویش دعا/ذکر ذخیره شد.', 'hamnoor'),
            'success'
        );
    }

    public static function maybe_validate_step() {
        $campaign_id = self::campaign_id_from_request($_POST);
        $campaign = self::get_supported_campaign($campaign_id);
        if (!$campaign) { return; }
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_DB::user_can_manage_khatm($campaign_id)) {
            wp_send_json_error(array('message'=>__('دسترسی کافی برای ویرایش این پویش ندارید.', 'hamnoor')), 403);
        }

        $payload = self::command_payload($campaign, $_POST);
        if (is_wp_error($payload)) { wp_send_json_success(array('errors'=>self::error_fields($payload), 'valid'=>false)); }
        $fields = Hamnoor_Command_Contracts::campaign_update($payload, $campaign);
        if (is_wp_error($fields)) { wp_send_json_success(array('errors'=>self::error_fields($fields), 'valid'=>false)); }
        $validated = Hamnoor_Contribution_Campaign_Edit_Service::validate_update($campaign, $fields);
        $errors = is_wp_error($validated) ? self::error_fields($validated) : array();
        wp_send_json_success(array('errors'=>$errors, 'valid'=>empty($errors)));
    }

    public static function maybe_save_draft() {
        $scope = isset($_POST['scope']) ? absint($_POST['scope']) : 0;
        $campaign = self::get_supported_campaign($scope);
        if (!$campaign) { return; }
        check_ajax_referer('hamnoor_frontend', 'nonce');

        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id || !Hamnoor_DB::user_can_manage_khatm((int)$campaign->id, $user_id)) {
            wp_send_json_error(array('message'=>__('برای ذخیره پیش‌نویس ویرایش، نشست معتبر مالک لازم است.', 'hamnoor')), 403);
        }
        $raw_json = isset($_POST['draft']) ? wp_unslash((string)$_POST['draft']) : '';
        $raw = json_decode($raw_json, true);
        if (!is_array($raw)) { wp_send_json_error(array('message'=>__('پیش‌نویس ویرایش معتبر نیست.', 'hamnoor')), 422); }

        $data = Hamnoor_DB::sanitize_khatm_data($raw);
        $data['content_type'] = (string)$campaign->content_type;
        if (!empty($campaign->first_participation_at)) {
            // A draft created before the first contribution must never revive
            // stale structural values after the campaign becomes locked.
            $current_item = self::current_content($campaign);
            $data['content_id'] = $current_item ? (string)$current_item->public_id : '';
            $data['target'] = (string)max(1, (int)$campaign->target_quantity);
        } else {
            if (isset($raw['content_id'])) { $data['content_id'] = strtoupper(sanitize_text_field((string)$raw['content_id'])); }
            if (isset($raw['target'])) { $data['target'] = (string)max(0, (int)Hamnoor_Validator::normalize_digits((string)$raw['target'])); }
        }
        foreach (array('starts_at_jalali','ends_at_jalali') as $field) {
            if (isset($raw[$field])) { $data[$field] = sanitize_text_field(Hamnoor_Validator::normalize_digits((string)$raw[$field])); }
        }
        $step = isset($_POST['step']) ? max(1, min(5, absint($_POST['step']))) : 1;
        $draft = array('data'=>$data, 'step'=>$step, 'updated_at'=>current_time('mysql'));
        set_transient('hamnoor_khatm_draft_' . $user_id . '_' . (int)$campaign->id, $draft, 14 * DAY_IN_SECONDS);
        wp_send_json_success(array('saved'=>1, 'updated_at'=>$draft['updated_at']));
    }
}

add_action('plugins_loaded', array('Hamnoor_Contribution_Owner_Edit_Adapter', 'init'), 5);
