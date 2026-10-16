<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical administrator review/edit adapter for Dua/Dhikr campaigns.
 *
 * Contribution-ledger campaigns have no Quran Parts/Claims. This adapter
 * intercepts the legacy admin save action before Hamnoor_Admin::save_khatm()
 * can route those campaigns through Hamnoor_DB::update_khatm().
 */
final class Hamnoor_Admin_Contribution_Review_Adapter {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 45);
        add_action('admin_post_hamnoor_admin_save_khatm', array(__CLASS__, 'maybe_handle_save'), 0);
        add_action('wp_ajax_hamnoor_admin_contribution_content_search', array(__CLASS__, 'ajax_content_search'));
    }

    private static function is_supported($campaign) {
        return $campaign && in_array((string)($campaign->participation_strategy ?? ''), array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true);
    }

    private static function get_campaign($campaign_id) {
        $campaign = $campaign_id ? Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id) : null;
        return self::is_supported($campaign) ? $campaign : null;
    }

    private static function current_content($campaign) {
        if (!$campaign || empty($campaign->content_item_id)) { return null; }
        return Hamnoor_Content_Repository::get((int)$campaign->content_item_id);
    }

    private static function edit_url($campaign_id) {
        return admin_url('admin.php?page=hamnoor&view=edit&khatm_id=' . (int)$campaign_id);
    }

    private static function parse_datetime($source, $field, $end_of_day = false) {
        $raw = isset($source[$field]) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source[$field]))) : '';
        if ('' === $raw) { return null; }
        $normalized = Hamnoor_Validator::normalize_datetime($raw, $end_of_day);
        if (!$normalized) {
            return new WP_Error('hamnoor_admin_campaign_date_invalid', __('فرمت زمان‌بندی پویش معتبر نیست.', 'hamnoor'), array('field'=>$field));
        }
        return $normalized;
    }

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

    private static function cover_fields() {
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { return $upload; }
        if (!is_array($upload) || empty($upload)) { return array(); }

        $fields = array();
        if (array_key_exists('cover_url', $upload)) { $fields['cover_url'] = esc_url_raw((string)$upload['cover_url']); }
        if (array_key_exists('cover_id', $upload)) { $fields['cover_id'] = (int)$upload['cover_id']; }
        return $fields;
    }

    private static function validate_review_request($campaign, $requested_status, $review_note) {
        $allowed = array('pending_review','needs_revision','rejected','active','paused','expired','completed','cancelled','archived','failed');
        if (!in_array($requested_status, $allowed, true)) {
            return new WP_Error('hamnoor_invalid_status', __('وضعیت انتخاب‌شده معتبر نیست.', 'hamnoor'));
        }
        if ($requested_status !== (string)$campaign->status
            && in_array($requested_status, array('needs_revision','rejected'), true)
            && '' === trim((string)$review_note)) {
            return new WP_Error('hamnoor_review_note_required', __('برای درخواست اصلاح یا رد پویش، دلیل را بنویسید.', 'hamnoor'));
        }
        if ($requested_status === (string)$campaign->status
            && in_array($requested_status, array('needs_revision','rejected'), true)
            && '' === trim((string)$review_note)) {
            return new WP_Error('hamnoor_review_note_required', __('دلیل بررسی برای این وضعیت نباید خالی باشد.', 'hamnoor'));
        }
        return true;
    }

    private static function update_review_metadata($campaign, $requested_status, $review_note, $pause_note) {
        $fields = array();
        $events = array();

        if ('paused' === (string)$campaign->status && 'paused' === $requested_status
            && 'admin' === sanitize_key((string)($campaign->pause_source ?? ''))
            && (string)($campaign->pause_note ?? '') !== (string)$pause_note) {
            $fields['pause_note'] = '' !== trim($pause_note) ? $pause_note : null;
            $events[] = array('admin_pause_note_updated', __('دلیل توقف مدیریتی ویرایش شد.', 'hamnoor'));
        }

        if (in_array($requested_status, array('needs_revision','rejected'), true)
            && $requested_status === (string)$campaign->status
            && (string)($campaign->review_note ?? '') !== (string)$review_note) {
            $fields['review_note'] = $review_note;
            $events[] = array('admin_review_note_updated', __('یادداشت بررسی مدیریتی ویرایش شد.', 'hamnoor'));
        }

        if (!$fields) { return true; }
        if (!Hamnoor_Campaign_Repository::update_fields((int)$campaign->id, $fields)) {
            return new WP_Error('hamnoor_admin_review_metadata_failed', __('ذخیره یادداشت مدیریتی انجام نشد.', 'hamnoor'));
        }
        foreach ($events as $event) {
            Hamnoor_DB::log((int)$campaign->id, 0, 0, Hamnoor_Mobile_Access::actor_id(), $event[0], $event[1]);
        }
        return true;
    }

    public static function maybe_handle_save() {
        $campaign_id = isset($_POST['khatm_id']) ? absint($_POST['khatm_id']) : 0;
        $campaign = self::get_campaign($campaign_id);
        if (!$campaign) { return; }

        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_admin_save_khatm_' . $campaign_id);
        $edit_url = self::edit_url($campaign_id);

        $featured_data = Hamnoor_Featured_Service::sanitize_admin_input($_POST);
        $featured_valid = Hamnoor_Featured_Service::validate_admin_data($featured_data);
        if (is_wp_error($featured_valid)) {
            Hamnoor_Helpers::redirect_with_notice($edit_url, $featured_valid->get_error_message(), 'error');
        }

        $requested_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : (string)$campaign->status;
        $review_note = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';
        $pause_note = isset($_POST['pause_note']) ? sanitize_textarea_field(wp_unslash($_POST['pause_note'])) : '';
        $review_valid = self::validate_review_request($campaign, $requested_status, $review_note);
        if (is_wp_error($review_valid)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $review_valid->get_error_message(), 'error'); }

        // Complete all cheap/non-mutating campaign validation before touching
        // the media library. This prevents invalid content/target/date/lock
        // requests from leaving an orphaned cover attachment behind.
        $payload = self::command_payload($campaign, $_POST);
        if (is_wp_error($payload)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $payload->get_error_message(), 'error'); }
        $fields = Hamnoor_Command_Contracts::campaign_update($payload, $campaign);
        if (is_wp_error($fields)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $fields->get_error_message(), 'error'); }
        $validated_fields = Hamnoor_Contribution_Campaign_Edit_Service::validate_update($campaign, $fields);
        if (is_wp_error($validated_fields)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $validated_fields->get_error_message(), 'error'); }

        $cover = self::cover_fields();
        if (is_wp_error($cover)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $cover->get_error_message(), 'error'); }

        $updated = Hamnoor_Contribution_Campaign_Edit_Service::update($campaign_id, $validated_fields);
        if (is_wp_error($updated)) {
            if (!empty($cover['cover_id']) && function_exists('wp_delete_attachment')) {
                wp_delete_attachment((int)$cover['cover_id'], true);
            }
            Hamnoor_Helpers::redirect_with_notice($edit_url, $updated->get_error_message(), 'error');
        }

        if ($cover && !Hamnoor_Campaign_Repository::update_fields($campaign_id, $cover)) {
            Hamnoor_Helpers::redirect_with_notice($edit_url, __('ذخیره تصویر پویش انجام نشد.', 'hamnoor'), 'error');
        }

        $meta = self::update_review_metadata($updated, $requested_status, $review_note, $pause_note);
        if (is_wp_error($meta)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $meta->get_error_message(), 'error'); }

        if ($requested_status !== (string)$campaign->status) {
            $state_note = 'paused' === $requested_status ? $pause_note : $review_note;
            $state = Hamnoor_Khatm_Service::change_status($campaign_id, $requested_status, $state_note, 'admin');
            if (is_wp_error($state)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $state->get_error_message(), 'error'); }
        }

        $feature = Hamnoor_Featured_Service::save_admin_settings($campaign_id, $featured_data, get_current_user_id());
        if (is_wp_error($feature)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $feature->get_error_message(), 'error'); }

        Hamnoor_Helpers::redirect_with_notice($edit_url, __('پویش دعا/ذکر و وضعیت بررسی آن ذخیره شد.', 'hamnoor'), 'success');
    }

    private static function contribution_rows() {
        // Mirror the exact legacy admin-list page query instead of preloading
        // an arbitrary number of contribution campaigns. The canonical
        // repository intentionally clamps reads to 100 rows, while the admin
        // list itself is configurable up to 200 rows per page.
        $filters = array(
            's'=>isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'privacy'=>isset($_GET['privacy']) ? sanitize_key(wp_unslash($_GET['privacy'])) : '',
            'status'=>isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'khatm_type'=>isset($_GET['khatm_type']) ? sanitize_key(wp_unslash($_GET['khatm_type'])) : '',
            'featured_filter'=>isset($_GET['featured_filter']) ? sanitize_key(wp_unslash($_GET['featured_filter'])) : '',
            'orderby'=>isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'id',
            'order'=>isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'DESC',
        );
        $paged = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $settings = Hamnoor_Helpers::settings();
        $per_page = max(5, min(200, (int)($settings['admin_khatms_per_page'] ?? 20)));
        $items = Hamnoor_DB::get_khatms(array_merge($filters, array(
            'limit'=>$per_page,
            'offset'=>($paged - 1) * $per_page,
        )));

        $rows = array();
        foreach ((array)$items as $campaign) {
            if (!self::is_supported($campaign)) { continue; }
            $rows[(int)$campaign->id] = array(
                'content_type'=>(string)$campaign->content_type,
                'strategy'=>(string)$campaign->participation_strategy,
                'target'=>(int)$campaign->target_quantity,
                'structure_locked'=>!empty($campaign->first_participation_at) ? 1 : 0,
            );
        }
        return $rows;
    }

    public static function enqueue_assets($hook) {
        if (false === strpos((string)$hook, 'hamnoor')) { return; }
        if (!current_user_can('manage_options')) { return; }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('hamnoor' !== $page) { return; }

        $campaign_id = isset($_GET['khatm_id']) ? absint($_GET['khatm_id']) : 0;
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
        $campaign = ('edit' === $view && $campaign_id) ? self::get_campaign($campaign_id) : null;

        wp_enqueue_style(
            'hamnoor-admin-contribution-review',
            HAMNOOR_URL . 'assets/css/hamnoor-admin-contribution-review.css',
            array('hamnoor-admin'),
            Hamnoor_Helpers::asset_version('assets/css/hamnoor-admin-contribution-review.css')
        );
        wp_enqueue_script(
            'hamnoor-admin-contribution-review',
            HAMNOOR_URL . 'assets/js/hamnoor-admin-contribution-review.js',
            array('hamnoor-admin'),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-admin-contribution-review.js'),
            true
        );
        $data = array(
            'nonce'=>wp_create_nonce('hamnoor_admin_contribution_review'),
            'ajax_url'=>admin_url('admin-ajax.php'),
            'rows'=>'list' === $view ? self::contribution_rows() : array(),
            'campaign'=>null,
        );
        if ($campaign) {
            $item = self::current_content($campaign);
            $data['campaign'] = array(
                'id'=>(int)$campaign->id,
                'content_type'=>(string)$campaign->content_type,
                'strategy'=>(string)$campaign->participation_strategy,
                'content_id'=>$item ? (string)$item->public_id : '',
                'content_title'=>$item ? (string)$item->title : '',
                'target'=>(int)$campaign->target_quantity,
                'structure_locked'=>!empty($campaign->first_participation_at) ? 1 : 0,
                'first_participation_at'=>!empty($campaign->first_participation_at) ? (string)$campaign->first_participation_at : '',
            );
        }
        wp_localize_script('hamnoor-admin-contribution-review', 'HamnoorAdminContributionReview', $data);
    }

    public static function ajax_content_search() {
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>__('دسترسی کافی ندارید.', 'hamnoor')), 403); }
        check_ajax_referer('hamnoor_admin_contribution_review', 'nonce');

        $type = isset($_POST['content_type']) ? sanitize_key(wp_unslash($_POST['content_type'])) : '';
        if (!in_array($type, array(Hamnoor_Campaign_Domain::CONTENT_DUA, Hamnoor_Campaign_Domain::CONTENT_DHIKR), true)) {
            wp_send_json_error(array('message'=>__('نوع محتوای پویش معتبر نیست.', 'hamnoor')), 422);
        }
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $items = array();
        foreach ((array)Hamnoor_Content_Repository::creator_items($type, $search, 50) as $row) {
            $items[] = array(
                'id'=>(string)$row->public_id,
                'title'=>(string)$row->title,
                'source_type'=>(string)$row->source_type,
                'max_contribution'=>!empty($row->max_contribution) ? (int)$row->max_contribution : null,
            );
        }
        wp_send_json_success(array('items'=>$items, 'count'=>count($items)));
    }
}

add_action('plugins_loaded', array('Hamnoor_Admin_Contribution_Review_Adapter', 'init'), 5);