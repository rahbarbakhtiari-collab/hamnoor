<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Validator {
    public static function normalize_digits($value) {
        $value = (string) $value;
        $persian = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');
        $arabic  = array('٠','١','٢','٣','٤','٥','٦','٧','٨','٩');
        $latin   = array('0','1','2','3','4','5','6','7','8','9');
        return str_replace($arabic, $latin, str_replace($persian, $latin, $value));
    }
    public static function normalize_phone($value) {
        $value = self::normalize_digits($value); $value = trim($value);
        $value = str_replace(array(' ', '-', '_', 'ـ', '–', '—', '.', '(', ')'), '', $value);
        if (0 === strpos($value, '+98')) { $value = '0' . substr($value, 3); }
        elseif (0 === strpos($value, '0098')) { $value = '0' . substr($value, 4); }
        elseif (0 === strpos($value, '98') && 12 === strlen($value)) { $value = '0' . substr($value, 2); }
        elseif (0 === strpos($value, '9') && 10 === strlen($value)) { $value = '0' . $value; }
        return preg_replace('/[^0-9]/', '', $value);
    }
    public static function valid_iran_mobile($phone) { return (bool) preg_match('/^09[0-9]{9}$/', self::normalize_phone($phone)); }
    public static function require_mobile($phone, $label = '') {
        $phone = self::normalize_phone($phone); $label = $label ? $label : __('شماره موبایل', 'hamnoor');
        if ('' === $phone) { return new WP_Error('hamnoor_mobile_required', sprintf(__('%s الزامی است. لطفاً شماره موبایل را برای پیگیری ختم وارد کنید.', 'hamnoor'), $label)); }
        if (!self::valid_iran_mobile($phone)) { return new WP_Error('hamnoor_mobile_invalid', sprintf(__('%s معتبر نیست. شماره موبایل ایران را مانند 09123456789 وارد کنید.', 'hamnoor'), $label)); }
        return $phone;
    }
    public static function normalize_datetime($value, $end_of_day = false) {
        $value = sanitize_text_field(self::normalize_digits((string) $value));
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) { $value .= ':00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return $value; }
        if (class_exists('Hamnoor_Jalali')) { return Hamnoor_Jalali::parse_jalali_datetime($value, $end_of_day); }
        return '';
    }
    public static function validate_dates($starts_at, $ends_at) {
        if ($starts_at && $ends_at && strtotime($ends_at) < strtotime($starts_at)) {
            return new WP_Error('hamnoor_date_order', __('تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد. لطفاً بازه زمانی ختم را اصلاح کنید.', 'hamnoor'));
        }
        return true;
    }
    public static function trim_len($value, $max) { return mb_substr((string)$value, 0, (int)$max); }
    public static function validate_khatm($data) {
        $errors = new WP_Error();
        $add = function($field, $code, $message) use ($errors) {
            $errors->add($code, $message, array('field' => $field));
        };
        $title = isset($data['title']) ? (string) $data['title'] : '';
        if ('' === trim($title)) { $add('title', 'hamnoor_title_required', __('عنوان ختم الزامی است. لطفاً عنوانی روشن و محترمانه وارد کنید.', 'hamnoor')); }
        if (mb_strlen($title) > 191) { $add('title', 'hamnoor_title_long', __('عنوان ختم بیش از حد طولانی است. لطفاً عنوان را کوتاه‌تر کنید.', 'hamnoor')); }
        $phone = self::require_mobile(isset($data['owner_phone']) ? $data['owner_phone'] : '', __('شماره موبایل ایجادکننده ختم', 'hamnoor'));
        if (is_wp_error($phone)) { $add('owner_phone', $phone->get_error_code(), $phone->get_error_message()); }
        if (empty($data['owner_email']) || !is_email($data['owner_email'])) { $add('owner_email', 'hamnoor_email_invalid', __('ایمیل ایجادکننده ختم معتبر نیست. لطفاً یک ایمیل صحیح وارد کنید.', 'hamnoor')); }
        $type = isset($data['khatm_type']) ? sanitize_key($data['khatm_type']) : 'page';
        if (!in_array($type, array('page','juz','surah','repeated_surah'), true)) { $add('khatm_type', 'hamnoor_type_invalid', __('نوع ختم معتبر نیست.', 'hamnoor')); }
        $khatm_count = isset($data['khatm_count']) ? (int) self::normalize_digits($data['khatm_count']) : 1;
        $settings = class_exists('Hamnoor_Helpers') ? Hamnoor_Helpers::settings() : array('max_khatm_count' => 100);
        $max_khatm_count = !empty($data['max_khatm_count']) ? (int) self::normalize_digits($data['max_khatm_count']) : (isset($settings['max_khatm_count']) ? (int) $settings['max_khatm_count'] : 100);
        $max_khatm_count = max(1, min(1000, $max_khatm_count));
        if (in_array($type, array('page','juz','surah'), true) && ($khatm_count < 1 || $khatm_count > $max_khatm_count)) {
            $message = class_exists('Hamnoor_Texts') ? Hamnoor_Texts::get('khatm_count_error', array('MAX' => $max_khatm_count)) : sprintf(__('تعداد ختم کامل قرآن باید بین ۱ تا %d باشد.', 'hamnoor'), $max_khatm_count);
            $add('khatm_count', 'hamnoor_khatm_count_invalid', $message);
        }
        $parts_per = ('juz' === $type ? 30 : ('surah' === $type ? 114 : ('repeated_surah' === $type ? 0 : 604)));
        if ('repeated_surah' === $type) {
            $surah = isset($data['selected_surah_number']) ? (int)$data['selected_surah_number'] : 1;
            $repeat = isset($data['repeat_count']) ? (int)self::normalize_digits($data['repeat_count']) : 0;
            if ($surah < 1 || $surah > 114) { $add('selected_surah_number', 'hamnoor_surah_invalid', __('لطفاً یک سوره معتبر انتخاب کنید.', 'hamnoor')); }
            if ($repeat < 1 || $repeat > 1000) { $add('repeat_count', 'hamnoor_repeat_invalid', __('تعداد تکرار باید بین ۱ تا ۱۰۰۰ مرتبه باشد.', 'hamnoor')); }
            $parts_per = max(0, $repeat);
            $khatm_count = 1;
        }
        $hard_limit = (int) apply_filters('hamnoor_parts_generation_hard_limit', 12000);
        $total_parts = max(0, (int) $parts_per) * max(1, (int) $khatm_count);
        if ($hard_limit > 0 && $total_parts > $hard_limit) {
            $add('khatm_count', 'hamnoor_parts_too_many', sprintf(__('تعداد سهم‌های این ختم از حد مجاز پردازش هم‌زمان بیشتر است. تعداد سهم‌ها: %1$d، حد مجاز فعلی: %2$d.', 'hamnoor'), $total_parts, $hard_limit));
        }
        if (!empty($data['starts_at_jalali']) && empty($data['starts_at'])) {
            $add('starts_at', 'hamnoor_date_format_invalid', __('فرمت تاریخ شروع شمسی معتبر نیست. لطفاً مانند ۱۴۰۵/۰۱/۰۱ ۲۱:۳۰ وارد کنید.', 'hamnoor'));
        }
        if (!empty($data['ends_at_jalali']) && empty($data['ends_at'])) {
            $add('ends_at', 'hamnoor_date_format_invalid_end', __('فرمت تاریخ پایان شمسی معتبر نیست. لطفاً مانند ۱۴۰۵/۰۱/۰۱ ۲۱:۳۰ وارد کنید.', 'hamnoor'));
        }
        $date_check = self::validate_dates(isset($data['starts_at']) ? $data['starts_at'] : '', isset($data['ends_at']) ? $data['ends_at'] : '');
        if (is_wp_error($date_check)) { $add('ends_at', $date_check->get_error_code(), $date_check->get_error_message()); }
        $completion_mode = isset($data['completion_mode']) && 'auto_done_on_claim' === sanitize_key($data['completion_mode']) ? 'auto_done_on_claim' : 'manual_done';
        $release_mode = ('manual_done' === $completion_mode && isset($data['auto_release_mode'])) ? sanitize_key($data['auto_release_mode']) : 'none';
        if ('after_days' === $release_mode) {
            $days = isset($data['auto_release_after_days']) ? (int) self::normalize_digits($data['auto_release_after_days']) : 0;
            if ($days < 1) { $add('auto_release_after_days', 'hamnoor_auto_release_days_invalid', __('تعداد روز آزادسازی باید عددی مثبت باشد.', 'hamnoor')); }
        } elseif ('fixed_date' === $release_mode) {
            if (empty($data['auto_release_at'])) {
                $add('auto_release_at', 'hamnoor_auto_release_date_invalid', __('تاریخ آزادسازی سهم‌های خوانده‌نشده معتبر نیست.', 'hamnoor'));
            } else {
                $release_ts = strtotime((string) $data['auto_release_at']);
                $base = !empty($data['_created_at_for_validation']) ? (string) $data['_created_at_for_validation'] : current_time('mysql');
                $base_ts = strtotime($base);
                if ($release_ts && $base_ts && $release_ts <= $base_ts) {
                    $message = !empty($data['_created_at_for_validation']) ? __('تاریخ آزادسازی باید بعد از تاریخ ایجاد ختم باشد.', 'hamnoor') : __('تاریخ آزادسازی باید بعد از زمان فعلی باشد.', 'hamnoor');
                    $add('auto_release_at', 'hamnoor_auto_release_date_before_create', $message);
                }
            }
        }
        if ($errors->has_errors()) { return $errors; }
        return true;
    }
    public static function validate_claim($data) {
        if (empty($data['name'])) { return new WP_Error('hamnoor_name_required', __('نام شرکت‌کننده الزامی است.', 'hamnoor')); }
        $phone = self::require_mobile(isset($data['phone']) ? $data['phone'] : '', __('شماره موبایل شرکت‌کننده', 'hamnoor')); if (is_wp_error($phone)) { return $phone; }
        if (!empty($data['email']) && !is_email($data['email'])) { return new WP_Error('hamnoor_email_invalid', __('ایمیل شرکت‌کننده معتبر نیست. اگر ایمیل ندارید، این قسمت را خالی بگذارید.', 'hamnoor')); }
        return true;
    }
}
