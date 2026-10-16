<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Featured_Service {
    public static function normalize_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, array('normal', 'featured', 'pinned'), true) ? $status : 'normal';
    }

    public static function normalize_request_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, array('none', 'pending', 'approved', 'rejected'), true) ? $status : 'none';
    }

    public static function is_configured($khatm) {
        return $khatm && in_array(self::normalize_status(isset($khatm->featured_status) ? $khatm->featured_status : 'normal'), array('featured', 'pinned'), true);
    }

    public static function is_active_featured($khatm) {
        if (!$khatm || !in_array((string) $khatm->status, array('active', 'completed'), true) || !self::is_configured($khatm)) { return false; }
        $now = current_time('mysql');
        $starts_at = !empty($khatm->featured_starts_at) ? (string) $khatm->featured_starts_at : '';
        $ends_at = !empty($khatm->featured_until) ? (string) $khatm->featured_until : '';
        if ($starts_at && '0000-00-00 00:00:00' !== $starts_at && $starts_at > $now) { return false; }
        if ($ends_at && '0000-00-00 00:00:00' !== $ends_at && $ends_at <= $now) { return false; }
        return true;
    }

    public static function display_state($khatm) {
        if (!self::is_configured($khatm)) { return 'normal'; }
        if (self::is_active_featured($khatm)) { return self::normalize_status($khatm->featured_status); }
        $now = current_time('mysql');
        if (!empty($khatm->featured_starts_at) && (string) $khatm->featured_starts_at > $now) { return 'scheduled'; }
        if (!empty($khatm->featured_until) && (string) $khatm->featured_until <= $now) { return 'expired'; }
        return 'inactive';
    }

    public static function status_label($khatm) {
        $labels = array(
            'normal' => __('عادی', 'hamnoor'),
            'featured' => __('ویژه', 'hamnoor'),
            'pinned' => __('پین‌شده', 'hamnoor'),
            'scheduled' => __('زمان‌بندی‌شده', 'hamnoor'),
            'expired' => __('ویژه منقضی‌شده', 'hamnoor'),
            'inactive' => __('ویژه غیرفعال', 'hamnoor'),
        );
        $state = self::display_state($khatm);
        return isset($labels[$state]) ? $labels[$state] : $labels['normal'];
    }

    public static function request_status_label($status) {
        $labels = array(
            'none' => __('بدون درخواست', 'hamnoor'),
            'pending' => __('در انتظار بررسی', 'hamnoor'),
            'approved' => __('تأییدشده', 'hamnoor'),
            'rejected' => __('ردشده', 'hamnoor'),
        );
        $status = self::normalize_request_status($status);
        return $labels[$status];
    }

    public static function badge_data($khatm) {
        if (!self::is_active_featured($khatm)) { return array(); }
        $status = self::normalize_status($khatm->featured_status);
        $default = 'pinned' === $status ? Hamnoor_Texts::get('pinned_badge_default') : Hamnoor_Texts::get('featured_badge_default');
        $text = !empty($khatm->featured_label) ? (string) $khatm->featured_label : $default;
        $text = apply_filters('hamnoor_featured_badge_text', $text, $khatm, $status);
        return array(
            'status' => $status,
            'text' => Hamnoor_Validator::trim_len(sanitize_text_field($text), 100),
            'class' => 'hamnoor-khatm-card--' . $status,
        );
    }

    public static function sanitize_admin_input($source) {
        $source = is_array($source) ? $source : array();
        $starts_raw = isset($source['featured_starts_at_jalali']) ? wp_unslash($source['featured_starts_at_jalali']) : (isset($source['featured_starts_at']) ? wp_unslash($source['featured_starts_at']) : '');
        $ends_raw = isset($source['featured_ends_at_jalali']) ? wp_unslash($source['featured_ends_at_jalali']) : (isset($source['featured_until']) ? wp_unslash($source['featured_until']) : '');
        return array(
            'featured_status' => self::normalize_status(isset($source['featured_status']) ? wp_unslash($source['featured_status']) : 'normal'),
            'featured_label' => Hamnoor_Validator::trim_len(sanitize_text_field(isset($source['featured_label']) ? wp_unslash($source['featured_label']) : ''), 100),
            'featured_starts_at_raw' => sanitize_text_field(Hamnoor_Validator::normalize_digits($starts_raw)),
            'featured_starts_at' => '' !== trim((string) $starts_raw) ? Hamnoor_Validator::normalize_datetime($starts_raw, false) : '',
            'featured_ends_at_raw' => sanitize_text_field(Hamnoor_Validator::normalize_digits($ends_raw)),
            'featured_until' => '' !== trim((string) $ends_raw) ? Hamnoor_Validator::normalize_datetime($ends_raw, true) : '',
            'featured_weight' => max(-100000, min(100000, (int) Hamnoor_Validator::normalize_digits(isset($source['featured_weight']) ? wp_unslash($source['featured_weight']) : 0))),
            'featured_reason' => Hamnoor_Validator::trim_len(sanitize_textarea_field(isset($source['featured_reason']) ? wp_unslash($source['featured_reason']) : ''), 1000),
        );
    }

    public static function validate_admin_data($data) {
        $errors = new WP_Error();
        if (!empty($data['featured_starts_at_raw']) && empty($data['featured_starts_at'])) {
            $errors->add('hamnoor_feature_start_invalid', __('تاریخ شروع ویژه بودن معتبر نیست.', 'hamnoor'), array('field' => 'featured_starts_at'));
        }
        if (!empty($data['featured_ends_at_raw']) && empty($data['featured_until'])) {
            $errors->add('hamnoor_feature_end_invalid', __('تاریخ پایان ویژه بودن معتبر نیست.', 'hamnoor'), array('field' => 'featured_until'));
        }
        if (!empty($data['featured_starts_at']) && !empty($data['featured_until']) && (string) $data['featured_until'] <= (string) $data['featured_starts_at']) {
            $errors->add('hamnoor_feature_date_order', __('تاریخ پایان ویژه بودن باید بعد از تاریخ شروع باشد.', 'hamnoor'), array('field' => 'featured_until'));
        }
        if (empty($data['featured_starts_at']) && !empty($data['featured_until']) && (string) $data['featured_until'] <= current_time('mysql')) {
            $errors->add('hamnoor_feature_end_past', __('وقتی تاریخ شروع خالی است، تاریخ پایان ویژه بودن باید بعد از زمان فعلی باشد.', 'hamnoor'), array('field' => 'featured_until'));
        }
        return $errors->has_errors() ? $errors : true;
    }

    public static function save_admin_settings($khatm_id, $data, $user_id = 0) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!current_user_can('manage_options')) { return new WP_Error('hamnoor_feature_denied', __('فقط مدیر سایت می‌تواند وضعیت ویژه بودن را تغییر دهد.', 'hamnoor')); }
        $khatm = Hamnoor_DB::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        $data = self::sanitize_admin_input($data);
        $valid = self::validate_admin_data($data);
        if (is_wp_error($valid)) { return $valid; }

        $old_status = self::normalize_status(isset($khatm->featured_status) ? $khatm->featured_status : (!empty($khatm->is_featured) ? 'featured' : 'normal'));
        $new_status = $data['featured_status'];
        $request_was_pending = 'pending' === self::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
        $will_approve_request = $request_was_pending && 'normal' !== $new_status;
        $feature_changed = $old_status !== $new_status
            || (string) (isset($khatm->featured_label) ? $khatm->featured_label : '') !== (string) $data['featured_label']
            || (string) (isset($khatm->featured_starts_at) ? $khatm->featured_starts_at : '') !== (string) $data['featured_starts_at']
            || (string) (isset($khatm->featured_until) ? $khatm->featured_until : '') !== (string) $data['featured_until']
            || (int) (isset($khatm->featured_weight) ? $khatm->featured_weight : 0) !== (int) $data['featured_weight']
            || (string) (isset($khatm->featured_reason) ? $khatm->featured_reason : '') !== (string) $data['featured_reason'];
        if (!$feature_changed && !$will_approve_request) { return true; }
        if ('normal' !== $new_status) { do_action('hamnoor_before_feature_khatm', (int) $khatm_id, $new_status, $data, $khatm); }

        $now = current_time('mysql');
        $fields = array(
            'featured_status' => $new_status,
            'is_featured' => 'normal' === $new_status ? 0 : 1,
            'featured_label' => $data['featured_label'],
            'featured_starts_at' => $data['featured_starts_at'] ? $data['featured_starts_at'] : null,
            'featured_until' => $data['featured_until'] ? $data['featured_until'] : null,
            'featured_weight' => (int) $data['featured_weight'],
            'featured_reason' => $data['featured_reason'],
            'featured_by_user_id' => $user_id,
            'featured_updated_at' => $now,
        );
        $formats = array('%s','%d','%s','%s','%s','%d','%s','%d','%s');
        if ($will_approve_request) {
            $fields['featured_request_status'] = 'approved';
            $fields['featured_request_reviewed_at'] = $now;
            $fields['featured_request_reviewed_by'] = $user_id;
            array_push($formats, '%s', '%s', '%d');
        }
        $updated = $wpdb->update(Hamnoor_DB::table('khatms'), $fields, array('id' => (int) $khatm_id), $formats, array('%d'));
        if (false === $updated) { return new WP_Error('hamnoor_feature_save_failed', __('ذخیره تنظیمات ویژه بودن انجام نشد.', 'hamnoor')); }

        $current = Hamnoor_DB::get_khatm((int) $khatm_id);
        Hamnoor_DB::log((int) $khatm_id, 0, 0, $user_id, 'khatm_feature_' . $new_status, sprintf(__('وضعیت ویژه بودن پویش به %s تغییر کرد.', 'hamnoor'), self::status_label($current)));
        if ('normal' === $new_status && 'normal' !== $old_status) {
            do_action('hamnoor_after_unfeature_khatm', (int) $khatm_id, $current, $khatm);
        } elseif ('normal' !== $new_status) {
            do_action('hamnoor_after_feature_khatm', (int) $khatm_id, $current, $khatm, $data);
        }
        if ($will_approve_request) {
            do_action('hamnoor_after_feature_request_approved', (int) $khatm_id, $current, $user_id);
        }
        return true;
    }

    public static function can_request($khatm, $user_id = 0) {
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!$khatm || !$user_id || (int) $khatm->owner_user_id !== $user_id) { return false; }
        if ('active' !== (string) $khatm->status || self::is_configured($khatm)) { return false; }
        return 'pending' !== self::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
    }

    public static function create_request($khatm_id, $message, $user_id = 0) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        $khatm = Hamnoor_DB::get_khatm((int) $khatm_id);
        if (!$khatm || !$user_id || (int) $khatm->owner_user_id !== $user_id) {
            return new WP_Error('hamnoor_feature_request_denied', __('فقط ایجادکننده پویش می‌تواند برای همان پویش درخواست ویژه شدن ثبت کند.', 'hamnoor'));
        }
        if ('pending' === self::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none')) {
            return new WP_Error('hamnoor_feature_request_pending', Hamnoor_Texts::get('feature_request_pending'));
        }
        if (!self::can_request($khatm, $user_id)) {
            return new WP_Error('hamnoor_feature_request_unavailable', __('برای این پویش در وضعیت فعلی امکان ثبت درخواست ویژه شدن وجود ندارد.', 'hamnoor'));
        }
        $message = Hamnoor_Validator::trim_len(sanitize_textarea_field($message), 500);
        $now = current_time('mysql');
        $updated = $wpdb->update(Hamnoor_DB::table('khatms'), array(
            'featured_request_status' => 'pending',
            'featured_request_message' => $message,
            'featured_requested_at' => $now,
            'featured_request_reviewed_at' => null,
            'featured_request_reviewed_by' => null,
        ), array('id' => (int) $khatm_id, 'owner_user_id' => $user_id), array('%s','%s','%s','%s','%d'), array('%d','%d'));
        if (!$updated) { return new WP_Error('hamnoor_feature_request_failed', __('ثبت درخواست ویژه شدن انجام نشد.', 'hamnoor')); }
        Hamnoor_DB::log((int) $khatm_id, 0, 0, $user_id, 'feature_request_created', __('درخواست ویژه شدن پویش ثبت شد.', 'hamnoor'));
        do_action('hamnoor_after_feature_request_created', (int) $khatm_id, $user_id, $message);
        return true;
    }

    public static function approve_request($khatm_id, $user_id = 0) {
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!current_user_can('manage_options')) { return new WP_Error('hamnoor_feature_denied', __('فقط مدیر سایت می‌تواند درخواست را تأیید کند.', 'hamnoor')); }
        $khatm = Hamnoor_DB::get_khatm((int) $khatm_id);
        if (!$khatm || 'pending' !== self::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none')) {
            return new WP_Error('hamnoor_feature_request_not_pending', __('درخواست در انتظار بررسی برای این پویش وجود ندارد.', 'hamnoor'));
        }
        return self::save_admin_settings((int) $khatm_id, array(
            'featured_status' => 'featured',
            'featured_label' => isset($khatm->featured_label) ? $khatm->featured_label : '',
            'featured_starts_at' => '',
            'featured_until' => '',
            'featured_weight' => isset($khatm->featured_weight) ? $khatm->featured_weight : 0,
            'featured_reason' => isset($khatm->featured_reason) ? $khatm->featured_reason : '',
        ), $user_id);
    }

    public static function reject_request($khatm_id, $user_id = 0) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!current_user_can('manage_options')) { return new WP_Error('hamnoor_feature_denied', __('فقط مدیر سایت می‌تواند درخواست را رد کند.', 'hamnoor')); }
        $khatm = Hamnoor_DB::get_khatm((int) $khatm_id);
        if (!$khatm || 'pending' !== self::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none')) {
            return new WP_Error('hamnoor_feature_request_not_pending', __('درخواست در انتظار بررسی برای این پویش وجود ندارد.', 'hamnoor'));
        }
        $now = current_time('mysql');
        $updated = $wpdb->update(Hamnoor_DB::table('khatms'), array(
            'featured_request_status' => 'rejected',
            'featured_request_reviewed_at' => $now,
            'featured_request_reviewed_by' => $user_id,
        ), array('id' => (int) $khatm_id, 'featured_request_status' => 'pending'), array('%s','%s','%d'), array('%d','%s'));
        if (!$updated) { return new WP_Error('hamnoor_feature_request_reject_failed', __('رد درخواست ویژه شدن انجام نشد.', 'hamnoor')); }
        Hamnoor_DB::log((int) $khatm_id, 0, 0, $user_id, 'feature_request_rejected', __('درخواست ویژه شدن پویش رد شد.', 'hamnoor'));
        do_action('hamnoor_after_feature_request_rejected', (int) $khatm_id, Hamnoor_DB::get_khatm((int) $khatm_id), $user_id);
        return true;
    }

    public static function order_clauses($args = array()) {
        $now = esc_sql(current_time('mysql'));
        $active_window = "(featured_starts_at IS NULL OR featured_starts_at = '0000-00-00 00:00:00' OR featured_starts_at <= '$now') AND (featured_until IS NULL OR featured_until = '0000-00-00 00:00:00' OR featured_until > '$now')";
        $clauses = array(
            "CASE WHEN status = 'active' THEN 0 WHEN status = 'completed' THEN 1 ELSE 2 END",
            "CASE WHEN status = 'active' AND featured_status = 'pinned' AND $active_window THEN 0 WHEN status = 'active' AND featured_status = 'featured' AND $active_window THEN 1 ELSE 2 END",
            "CASE WHEN status = 'active' AND featured_status IN ('featured','pinned') AND $active_window THEN featured_weight ELSE 0 END DESC",
        );
        $clauses = apply_filters('hamnoor_featured_khatm_order_args', $clauses, $args, $now);
        if (!is_array($clauses)) { return array(); }
        return array_values(array_filter($clauses, function($clause) { return is_string($clause) && '' !== trim($clause); }));
    }
}
