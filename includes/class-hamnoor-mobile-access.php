<?php
if (!defined('ABSPATH')) { exit; }

/**
 * دسترسی کاربران هم‌نور با موبایل و نشست اختصاصی.
 * هویت دامنه هم‌نور از WordPress authentication جداست، اما هر هویت به یک WP_User
 * کم‌اختیار متصل می‌شود تا در مدیریت وردپرس قابل مشاهده باشد. موبایل در URL قرار نمی‌گیرد.
 */
final class Hamnoor_Mobile_Access {
    const COOKIE_NAME = 'hamnoor_session';
    const BALE_COOKIE_NAME = 'hamnoor_bale_session';
    const SESSION_DAYS = 30;

    private static $current_user_loaded = false;
    private static $current_user = null;
    private static $last_session_id = 0;
    const CREATE_ACCESS_TTL = 15 * MINUTE_IN_SECONDS; // backward-compatible alias
    const SENSITIVE_ACCESS_TTL = 15 * MINUTE_IN_SECONDS;

    public static function init() {
        // Hamnoor uses its own HttpOnly session cookie instead of WordPress'
        // logged-in cookie. Tell LiteSpeed to vary on this cookie early, then
        // mark every cookie-bearing request private/no-cache once PHP runs.
        // This prevents a cached guest dashboard from being served to a user
        // whose Hamnoor session is actually valid.
        add_filter('litespeed_vary_cookies', array(__CLASS__, 'litespeed_vary_cookies'));
        add_filter('litespeed_vary_curr_cookies', array(__CLASS__, 'litespeed_vary_cookies'));
        add_action('init', array(__CLASS__, 'protect_session_from_page_cache'), 0);
        add_action('wp', array(__CLASS__, 'protect_session_from_page_cache'), 0);

        add_action('wp_ajax_hamnoor_begin_create_khatm', array(__CLASS__, 'ajax_begin_create'));
        add_action('wp_ajax_nopriv_hamnoor_begin_create_khatm', array(__CLASS__, 'ajax_begin_create'));
        add_action('wp_ajax_hamnoor_verify_create_otp', array(__CLASS__, 'ajax_verify_create_otp'));
        add_action('wp_ajax_nopriv_hamnoor_verify_create_otp', array(__CLASS__, 'ajax_verify_create_otp'));
        add_action('wp_ajax_hamnoor_send_mobile_otp', array(__CLASS__, 'ajax_send_otp'));
        add_action('wp_ajax_nopriv_hamnoor_send_mobile_otp', array(__CLASS__, 'ajax_send_otp'));
        add_action('wp_ajax_hamnoor_verify_mobile_otp', array(__CLASS__, 'ajax_verify_otp'));
        add_action('wp_ajax_nopriv_hamnoor_verify_mobile_otp', array(__CLASS__, 'ajax_verify_otp'));
        add_action('wp_ajax_hamnoor_send_change_mobile_otp', array(__CLASS__, 'ajax_send_change_mobile_otp'));
        add_action('wp_ajax_nopriv_hamnoor_send_change_mobile_otp', array(__CLASS__, 'ajax_send_change_mobile_otp'));
        add_action('wp_ajax_hamnoor_verify_change_mobile_otp', array(__CLASS__, 'ajax_verify_change_mobile_otp'));
        add_action('wp_ajax_nopriv_hamnoor_verify_change_mobile_otp', array(__CLASS__, 'ajax_verify_change_mobile_otp'));
        add_action('wp_ajax_hamnoor_begin_recovery', array(__CLASS__, 'ajax_begin_recovery'));
        add_action('wp_ajax_nopriv_hamnoor_begin_recovery', array(__CLASS__, 'ajax_begin_recovery'));

        // مسیر POST فقط پیام راهنما می‌دهد؛ فرایند امن OTP به JavaScript و AJAX نیاز دارد.
        add_action('admin_post_hamnoor_begin_create_access', array(__CLASS__, 'post_begin_create'));
        add_action('admin_post_nopriv_hamnoor_begin_create_access', array(__CLASS__, 'post_begin_create'));
        add_action('admin_post_hamnoor_mobile_logout', array(__CLASS__, 'post_logout'));
        add_action('admin_post_nopriv_hamnoor_mobile_logout', array(__CLASS__, 'post_logout'));
        add_action('admin_post_hamnoor_revoke_session', array(__CLASS__, 'post_revoke_session'));
        add_action('admin_post_nopriv_hamnoor_revoke_session', array(__CLASS__, 'post_revoke_session'));
    }

    public static function current_user_id() {
        $user = self::current_user();
        return $user ? (int) $user->id : 0;
    }

    public static function actor_id() {
        return self::current_user_id();
    }

    public static function is_logged_in() {
        return self::current_user_id() > 0;
    }

    /**
     * Session cookie names are extensible so first-party messenger add-ons can
     * keep an identity-bound session inside third-party WebViews/iframes
     * without teaching Core about every provider. Embedded cookies are checked
     * before the ordinary website cookie to avoid stale same-origin identity.
     */
    private static function session_cookie_names() {
        $names = array(self::BALE_COOKIE_NAME, self::COOKIE_NAME);
        $names = apply_filters('hamnoor_mobile_session_cookie_names', $names);
        $names = array_values(array_unique(array_filter(array_map('strval', (array)$names), static function($name) {
            return (bool) preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $name);
        })));
        if (!in_array(self::COOKIE_NAME, $names, true)) { $names[] = self::COOKIE_NAME; }
        return $names;
    }

    /** Cookies that must be cleared with SameSite=None + Partitioned semantics. */
    private static function cross_site_cookie_names() {
        $names = array(self::BALE_COOKIE_NAME);
        $names = apply_filters('hamnoor_mobile_cross_site_cookie_names', $names);
        return array_values(array_unique(array_filter(array_map('strval', (array)$names), static function($name) {
            return (bool) preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $name);
        })));
    }

    public static function litespeed_vary_cookies($cookies) {
        if (!is_array($cookies)) {
            $cookies = $cookies ? preg_split('/[\s,]+/', (string)$cookies, -1, PREG_SPLIT_NO_EMPTY) : array();
        }
        foreach (self::session_cookie_names() as $cookie_name) {
            if (!in_array($cookie_name, $cookies, true)) { $cookies[] = $cookie_name; }
        }
        return array_values(array_unique($cookies));
    }

    public static function protect_session_from_page_cache() {
        $has_session_cookie = false;
        foreach (self::session_cookie_names() as $cookie_name) {
            if (!empty($_COOKIE[$cookie_name])) { $has_session_cookie = true; break; }
        }
        if (!$has_session_cookie) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        do_action('litespeed_control_set_nocache', 'Hamnoor private mobile session');
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
        }
    }

    public static function current_user() {
        if (self::$current_user_loaded) { return self::$current_user; }
        self::$current_user_loaded = true;
        self::$current_user = null;

        $tokens = array();
        // Embedded messenger sessions are intentionally inspected before the
        // ordinary website cookie. The provider add-on controls ordering via
        // hamnoor_mobile_session_cookie_names and can therefore prevent an old
        // manual browser session from shadowing a freshly validated Mini App.
        foreach (self::session_cookie_names() as $cookie_name) {
            if (isset($_COOKIE[$cookie_name])) {
                $tokens[] = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            }
        }
        $tokens = array_values(array_unique(array_filter($tokens, static function($token) {
            return is_string($token) && (bool)preg_match('/^[a-f0-9]{64}$/', $token);
        })));
        if (!$tokens) { return null; }

        global $wpdb;
        $sessions = Hamnoor_DB::table('sessions');
        $users = Hamnoor_DB::table('users');
        if (!$sessions || !$users) { return null; }
        $now = current_time('mysql', true);
        $row = null;
        foreach ($tokens as $raw) {
            $hash = self::token_hash($raw);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT u.*, s.id AS session_id, s.expires_at AS session_expires_at, s.last_seen_at AS session_last_seen_at, s.assurance_level AS session_assurance_level, s.trusted_until AS session_trusted_until, s.step_up_until AS session_step_up_until, s.assurance_source AS session_assurance_source
                 FROM $sessions s INNER JOIN $users u ON u.id = s.user_id
                 WHERE s.token_hash = %s AND s.revoked_at IS NULL AND s.expires_at > %s AND u.status = 'active' LIMIT 1",
                $hash,
                $now
            ));
            if ($row) { break; }
        }
        if (!$row) {
            self::clear_cookie();
            return null;
        }

        self::$current_user = $row;
        self::$last_session_id = isset($row->session_id) ? (int) $row->session_id : 0;
        $last_seen = !empty($row->session_last_seen_at) ? strtotime($row->session_last_seen_at . ' UTC') : 0;
        if (!$last_seen || (time() - $last_seen) > 10 * MINUTE_IN_SECONDS) {
            $utc_now = gmdate('Y-m-d H:i:s');
            $wpdb->update($sessions, array('last_seen_at' => $utc_now), array('id' => (int) $row->session_id), array('%s'), array('%d'));
            $wpdb->update($users, array('last_seen_at' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => (int) $row->id), array('%s','%s'), array('%d'));
        }
        return self::$current_user;
    }

    public static function find_user($phone) {
        global $wpdb;
        $phone = Hamnoor_Validator::normalize_phone($phone);
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) { return false; }
        $table = Hamnoor_DB::table('users');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE phone = %s AND status = 'active' LIMIT 1", $phone));
    }

    public static function get_user($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('users');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d LIMIT 1", (int) $user_id));
    }


    /**
     * Return a privacy-preserving fingerprint for a phone that was proven by
     * an authoritative external platform. The raw phone is never persisted in
     * channel metadata; this fingerprint is only useful inside this WordPress
     * installation because it is keyed with the site auth salt.
     */
    public static function trusted_phone_fingerprint($phone) {
        $phone = Hamnoor_Validator::normalize_phone($phone);
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) { return ''; }
        return hash_hmac('sha256', 'hamnoor-trusted-phone|' . $phone, wp_salt('auth'));
    }

    /** Whether a stored trusted-platform phone proof still matches the user's current phone. */
    public static function trusted_phone_matches_user($user_id, $fingerprint) {
        $user_id = (int)$user_id;
        $fingerprint = strtolower(trim((string)$fingerprint));
        if (!$user_id || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) { return false; }
        $user = self::get_user($user_id);
        if (!$user) { return false; }
        $current = self::trusted_phone_fingerprint((string)$user->phone);
        return $current && hash_equals($current, $fingerprint);
    }

    /**
     * Mark a phone as verified only after an authoritative messaging-platform
     * identity flow. Callers cannot invent arbitrary source names: the allowlist
     * is the product's single trust policy for SMS-equivalent verification.
     */
    public static function verify_by_trusted_channel($user_id, $source, $verified_phone = '') {
        global $wpdb;
        $user_id = (int)$user_id;
        $source = sanitize_key((string)$source);
        $trusted = array(
            'eitaa_initdata_request_contact',
            'bale_request_contact',
            'bale_initdata_request_contact',
            'rubika_ask_my_phone_number',
            'telegram_request_contact',
            'whatsapp_sender_number',
        );
        if (!$user_id || !in_array($source, $trusted, true)) {
            return new WP_Error('hamnoor_untrusted_verification_source', __('منبع تأیید شماره معتبر نیست.', 'hamnoor'));
        }
        $user = self::get_user($user_id);
        if (!$user || !Hamnoor_Validator::valid_iran_mobile((string)$user->phone)) {
            return new WP_Error('hamnoor_verified_user_invalid', __('کاربر یا شماره موبایل برای تأیید معتبر نیست.', 'hamnoor'));
        }
        $verified_phone = Hamnoor_Validator::normalize_phone($verified_phone);
        $current_phone = Hamnoor_Validator::normalize_phone((string)$user->phone);
        if (!Hamnoor_Validator::valid_iran_mobile($verified_phone)) {
            return new WP_Error('hamnoor_trusted_phone_missing', __('پیام‌رسان شمارهٔ تأییدشدهٔ معتبر در اختیار هم‌نور قرار نداد.', 'hamnoor'));
        }
        if (!$current_phone || !hash_equals($current_phone, $verified_phone)) {
            return new WP_Error('hamnoor_trusted_phone_mismatch', __('شمارهٔ تأییدشدهٔ این پیام‌رسان با شمارهٔ فعلی پنل هم‌نور یکسان نیست.', 'hamnoor'));
        }
        if (1 !== (int)$user->mobile_verified) {
            $now = current_time('mysql');
            $updated = $wpdb->update(
                Hamnoor_DB::table('users'),
                array('mobile_verified'=>1, 'verified_at'=>$now, 'updated_at'=>$now),
                array('id'=>$user_id),
                array('%d','%s','%s'),
                array('%d')
            );
            if (false === $updated) {
                return new WP_Error('hamnoor_channel_verification_failed', __('ثبت تأیید شماره توسط پیام‌رسان انجام نشد.', 'hamnoor'));
            }
            Hamnoor_DB::log(0, 0, 0, $user_id, 'mobile_verified_by_channel', sprintf(__('شماره موبایل توسط مسیر معتبر %s تأیید شد.', 'hamnoor'), $source));
        }
        // A live authoritative phone proof may also satisfy fresh verification,
        // but only when a Hamnoor session for the same user already exists.
        self::grant_sensitive_access_from_trusted_channel($user_id, $source, $verified_phone);
        return self::get_user($user_id);
    }

    public static function ensure_user($phone, $name = '') {
        global $wpdb;
        $phone = Hamnoor_Validator::normalize_phone($phone);
        $name = trim(sanitize_text_field($name));
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            return new WP_Error('hamnoor_mobile_invalid', Hamnoor_Texts::get('mobile_invalid'));
        }
        $user = self::find_user($phone);
        if ($user) {
            // Merely knowing a mobile number must not allow changing the profile name.
            // Name changes are handled explicitly from the profile tab.
            $current_name = trim((string) $user->display_name);
            if ($name && (!$current_name || $current_name === (string) $user->phone || __('همراه هم‌نور', 'hamnoor') === $current_name)) {
                $wpdb->update(Hamnoor_DB::table('users'), array('display_name'=>$name,'updated_at'=>current_time('mysql')), array('id'=>(int)$user->id), array('%s','%s'), array('%d'));
                $user = self::get_user((int) $user->id);
            }
            $wp_link = Hamnoor_WP_User_Bridge::ensure_link($user);
            if (is_wp_error($wp_link)) { return $wp_link; }
            return self::get_user((int)$user->id);
        }
        if (!$name) {
            return new WP_Error('hamnoor_name_required', __('نام و نام خانوادگی الزامی است.', 'hamnoor'));
        }
        $now = current_time('mysql');
        $inserted = $wpdb->insert(Hamnoor_DB::table('users'), array(
            'public_id' => 'pending-' . wp_generate_password(12, false, false),
            'display_name' => Hamnoor_Validator::trim_len($name, 191),
            'phone' => $phone,
            'mobile_verified' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            'last_seen_at' => $now,
        ), array('%s','%s','%s','%d','%s','%s','%s','%s'));
        if (!$inserted) {
            // در درخواست‌های هم‌زمان ممکن است رکورد دیگری همان شماره را ساخته باشد.
            $user = self::find_user($phone);
            return $user ? $user : new WP_Error('hamnoor_user_create_failed', __('ساخت پنل انجام نشد. لطفاً دوباره تلاش کنید.', 'hamnoor'));
        }
        $id = (int) $wpdb->insert_id;
        $public_id = 'HM-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
        $wpdb->update(Hamnoor_DB::table('users'), array('public_id'=>$public_id), array('id'=>$id), array('%s'), array('%d'));
        Hamnoor_DB::log(0, 0, 0, $id, 'user_created', __('پنل هم‌نور به‌صورت خودکار ساخته شد.', 'hamnoor'));
        Hamnoor_DB::add_notification($id, 'panel_created', __('پنل هم‌نور ساخته شد', 'hamnoor'), __('از این پس سهم‌ها، سوابق و پویش‌های شما در همین داشبورد نمایش داده می‌شود.', 'hamnoor'));
        $created_user = self::get_user($id);
        if ($created_user) {
            $wp_link = Hamnoor_WP_User_Bridge::ensure_link($created_user);
            if (is_wp_error($wp_link)) { return $wp_link; }
            $created_user = self::get_user($id);
            if ($created_user && class_exists('Hamnoor_Domain_Events')) {
                Hamnoor_Domain_Events::identity_created($created_user);
            }
        }
        return $created_user;
    }

    public static function login($user, $remember = true, $cookie_context = 'default') {
        if (!$user || empty($user->id)) { return new WP_Error('hamnoor_user_invalid', __('کاربر معتبر نیست.', 'hamnoor')); }
        global $wpdb;
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = hash('sha256', wp_generate_password(64, true, true) . microtime(true) . wp_rand());
        }
        $now_utc = gmdate('Y-m-d H:i:s');
        $ttl = $remember ? self::SESSION_DAYS * DAY_IN_SECONDS : DAY_IN_SECONDS;
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $inserted = $wpdb->insert(Hamnoor_DB::table('sessions'), array(
            'user_id' => (int) $user->id,
            'token_hash' => self::token_hash($token),
            'expires_at' => $expires,
            'last_seen_at' => $now_utc,
            'ip_hash' => self::hash_optional(self::client_ip()),
            'user_agent_hash' => self::hash_optional(isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''),
            'created_at' => $now_utc,
            'assurance_level' => Hamnoor_Access_Policy::LEVEL_LOW,
        ), array('%d','%s','%s','%s','%s','%s','%s','%d'));
        if (!$inserted) { return new WP_Error('hamnoor_session_failed', __('ایجاد نشست پنل انجام نشد.', 'hamnoor')); }
        self::$last_session_id = (int) $wpdb->insert_id;
        if (!self::set_cookie($token, time() + $ttl, $cookie_context)) {
            $wpdb->update(Hamnoor_DB::table('sessions'), array('revoked_at'=>gmdate('Y-m-d H:i:s')), array('token_hash'=>self::token_hash($token)), array('%s'), array('%s'));
            return new WP_Error('hamnoor_cookie_failed', __('مرورگر اجازه ایجاد نشست پنل را نداد. لطفاً کوکی‌ها را فعال و دوباره تلاش کنید.', 'hamnoor'));
        }
        self::$current_user_loaded = false;
        self::$current_user = null;
        return true;
    }

    private static function current_session_id() {
        if (self::$last_session_id) { return (int) self::$last_session_id; }
        $user = self::current_user();
        return ($user && !empty($user->session_id)) ? (int) $user->session_id : 0;
    }

    public static function active_sessions($user_id = 0) {
        global $wpdb;
        $user_id = $user_id ? (int)$user_id : self::current_user_id();
        if (!$user_id) { return array(); }
        $current = self::current_session_id();
        $table = Hamnoor_DB::table('sessions');
        $now = gmdate('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,expires_at,last_seen_at,created_at FROM $table WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s ORDER BY last_seen_at DESC LIMIT 20",
            $user_id, $now
        ));
        foreach ((array)$rows as $row) { $row->is_current = ((int)$row->id === (int)$current); }
        return (array)$rows;
    }

    public static function post_revoke_session() {
        $redirect = add_query_arg('tab', 'profile', Hamnoor_Helpers::page_url('dashboard'));
        $user_id = self::current_user_id();
        if (!$user_id) { Hamnoor_Helpers::redirect_with_notice($redirect, __('نشست هم‌نور فعال نیست.', 'hamnoor'), 'error'); }
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        check_admin_referer('hamnoor_revoke_session_' . $session_id);
        if (!self::has_sensitive_access($user_id)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('برای بستن نشست دستگاه دیگر، ابتدا هویت این نشست را تأیید کنید.', 'hamnoor'), 'error');
        }
        $current = self::current_session_id();
        if (!$session_id || $session_id === $current) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('برای خروج از همین دستگاه از دکمه «خروج از پنل» استفاده کنید.', 'hamnoor'), 'warning');
        }
        global $wpdb;
        $table = Hamnoor_DB::table('sessions');
        $updated = $wpdb->update($table, array('revoked_at'=>gmdate('Y-m-d H:i:s')), array('id'=>$session_id,'user_id'=>$user_id,'revoked_at'=>null), array('%s'), array('%d','%d','%s'));
        if (false === $updated) { Hamnoor_Helpers::redirect_with_notice($redirect, __('بستن نشست انجام نشد.', 'hamnoor'), 'error'); }
        Hamnoor_DB::log(0, 0, 0, $user_id, 'session_revoked', __('یک نشست دیگر کاربر بسته شد.', 'hamnoor'));
        Hamnoor_Helpers::redirect_with_notice($redirect, __('نشست دستگاه دیگر بسته شد.', 'hamnoor'));
    }

    public static function has_assurance($required_level = Hamnoor_Access_Policy::LEVEL_LOW, $user_id = 0) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $required_level = max(0, min(2, (int)$required_level));
        $user_id = $user_id ? (int)$user_id : self::current_user_id();
        $current = self::current_user();
        if (!$user_id || !$current || (int)$current->id !== $user_id) { return false; }
        if (Hamnoor_Access_Policy::LEVEL_LOW === $required_level) { return true; }
        $now = time();
        $level = isset($current->session_assurance_level) ? (int)$current->session_assurance_level : 0;
        $trusted_until = !empty($current->session_trusted_until) ? strtotime($current->session_trusted_until . ' UTC') : 0;
        $step_up_until = !empty($current->session_step_up_until) ? strtotime($current->session_step_up_until . ' UTC') : 0;
        if (Hamnoor_Access_Policy::LEVEL_TRUSTED === $required_level) { return $level >= 1 && $trusted_until > $now; }
        return $level >= 2 && $step_up_until > $now;
    }

    /**
     * OTP/trusted-platform proof upgrades the exact current Hamnoor session.
     * L1 survives for the configured trusted-device period; L2 is deliberately
     * short and is used for identity changes such as name/mobile.
     */
    public static function grant_sensitive_access($user_id = 0, $source = 'sms_otp') {
        global $wpdb;
        $user_id = $user_id ? (int)$user_id : self::current_user_id();
        $session_id = self::current_session_id();
        $current = self::current_user();
        if (!$user_id || !$session_id || !$current || (int)$current->id !== $user_id) { return false; }
        $settings = Hamnoor_Helpers::settings();
        $trusted_days = max(1, min(3650, (int)($settings['trusted_device_days'] ?? 180)));
        $step_minutes = max(1, min(120, (int)($settings['recent_step_up_minutes'] ?? 10)));
        $updated = $wpdb->update(
            Hamnoor_DB::table('sessions'),
            array(
                'assurance_level'=>Hamnoor_Access_Policy::LEVEL_RECENT_OTP,
                'trusted_until'=>gmdate('Y-m-d H:i:s', time() + ($trusted_days * DAY_IN_SECONDS)),
                'step_up_until'=>gmdate('Y-m-d H:i:s', time() + ($step_minutes * MINUTE_IN_SECONDS)),
                'assurance_source'=>sanitize_key((string)$source),
            ),
            array('id'=>$session_id,'user_id'=>$user_id,'revoked_at'=>null),
            array('%d','%s','%s','%s'),
            array('%d','%d','%s')
        );
        if (false === $updated) { return false; }
        self::$current_user_loaded = false;
        self::$current_user = null;
        return true;
    }

    public static function has_sensitive_access($user_id = 0) {
        return self::has_assurance(Hamnoor_Access_Policy::LEVEL_TRUSTED, $user_id);
    }

    public static function has_recent_step_up($user_id = 0) {
        return self::has_assurance(Hamnoor_Access_Policy::LEVEL_RECENT_OTP, $user_id);
    }

    public static function grant_sensitive_access_from_trusted_channel($user_id, $source, $verified_phone = '') {
        $source = sanitize_key((string)$source);
        $trusted = array(
            'eitaa_initdata_request_contact',
            'bale_request_contact',
            'bale_initdata_request_contact',
            'rubika_ask_my_phone_number',
            'telegram_request_contact',
            'whatsapp_sender_number',
        );
        if (!in_array($source, $trusted, true)) { return false; }
        $verified_phone = Hamnoor_Validator::normalize_phone($verified_phone);
        $user = self::get_user((int)$user_id);
        $current_phone = $user ? Hamnoor_Validator::normalize_phone((string)$user->phone) : '';
        if (!$user || !Hamnoor_Validator::valid_iran_mobile($verified_phone) || !$current_phone || !hash_equals($current_phone, $verified_phone)) {
            return false;
        }
        return self::grant_sensitive_access((int)$user_id, $source);
    }

    /**
     * A signed platform identity can renew fresh-session access only when that
     * platform connection still carries a phone proof for the CURRENT Hamnoor
     * phone. This prevents an old Eitaa/Bale account from verifying a new phone
     * after the user changes the mobile number in Hamnoor.
     */
    public static function grant_sensitive_access_from_linked_channel($user_id, $source, $verified_phone_fingerprint) {
        $source = sanitize_key((string)$source);
        $trusted = array(
            'eitaa_signed_initdata',
            'bale_signed_initdata_handoff',
            'bale_initdata_request_contact',
        );
        if (!in_array($source, $trusted, true)) { return false; }
        if (!self::trusted_phone_matches_user((int)$user_id, $verified_phone_fingerprint)) { return false; }
        return self::grant_sensitive_access((int)$user_id, $source);
    }

    // Backward-compatible names used by the existing creation flow.
    public static function grant_create_access($user_id = 0) {
        return self::grant_sensitive_access($user_id, 'create_sms_otp');
    }

    public static function has_create_access($user_id = 0) {
        return self::has_sensitive_access($user_id);
    }

    public static function consume_create_access() {
        // Sensitive verification is intentionally session-scoped for a short
        // window rather than one-action-only, so a user is not asked for OTP
        // repeatedly while completing related owner actions.
        return;
    }

    public static function logout() {
        global $wpdb;
        $tokens = array();
        foreach (self::session_cookie_names() as $cookie_name) {
            $raw = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
            if ($raw && preg_match('/^[a-f0-9]{64}$/', $raw)) { $tokens[] = $raw; }
        }
        foreach (array_values(array_unique($tokens)) as $raw) {
            $wpdb->update(Hamnoor_DB::table('sessions'), array('revoked_at'=>gmdate('Y-m-d H:i:s')), array('token_hash'=>self::token_hash($raw)), array('%s'), array('%s'));
        }
        self::clear_cookie();
        self::$current_user_loaded = true;
        self::$current_user = null;
    }

    /**
     * هویت معتبر برای رزرو سهم را تعیین می‌کند.
     * اگر نشست فعال باشد، شماره و نام فقط از رکورد همان کاربر خوانده می‌شود و
     * ارسال شماره متفاوت، حتی با دستکاری فرم، رد خواهد شد.
     */
    public static function resolve_claim_identity($phone, $name = '') {
        $submitted_phone = Hamnoor_Validator::normalize_phone($phone);
        $submitted_name = trim(sanitize_text_field($name));

        $current = self::current_user();
        if ($current) {
            $session_phone = Hamnoor_Validator::normalize_phone((string) $current->phone);
            if ($submitted_phone && !hash_equals($session_phone, $submitted_phone)) {
                return new WP_Error(
                    'hamnoor_session_mobile_mismatch',
                    __('شماره موبایل این پنل قابل تغییر نیست. برای تغییر شماره از بخش مشخصات و تأیید شماره جدید استفاده کنید.', 'hamnoor')
                );
            }
            return array(
                'user' => $current,
                'phone' => $session_phone,
                'name' => (string) $current->display_name,
                'locked' => true,
            );
        }

        return array(
            'user' => null,
            'phone' => $submitted_phone,
            'name' => $submitted_name,
            'locked' => false,
        );
    }

    public static function ensure_and_login_for_claim($phone, $name) {
        $identity = self::resolve_claim_identity($phone, $name);
        if (is_wp_error($identity)) { return $identity; }
        if (!empty($identity['user'])) { return $identity['user']; }

        $phone = Hamnoor_Validator::normalize_phone($identity['phone']);
        $name = trim(sanitize_text_field($identity['name']));
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            return new WP_Error('hamnoor_mobile_invalid', Hamnoor_Texts::get('mobile_invalid'));
        }
        if (!$name) { return new WP_Error('hamnoor_name_required', __('نام و نام خانوادگی الزامی است.', 'hamnoor')); }

        // Product decision: knowing the phone is sufficient for LOW-assurance
        // Hamnoor access because ordinary shares/dashboard data are low risk.
        // This never creates a WordPress auth cookie or elevated capability.
        $user = self::ensure_user($phone, $name);
        if (is_wp_error($user)) { return $user; }
        $logged = self::login($user, true);
        if (is_wp_error($logged)) { return $logged; }
        self::$current_user_loaded = false;
        self::$current_user = null;
        $current = self::current_user();
        return $current ?: $user;
    }

    public static function is_verified($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : self::current_user_id();
        if (!$user_id) { return false; }
        $user = self::get_user($user_id);
        $verified = $user && 1 === (int) $user->mobile_verified;
        // Notification add-ons may repair an older trusted-channel connection
        // whose authoritative phone proof predates the central verified flag.
        return (bool) apply_filters('hamnoor_mobile_is_verified', $verified, $user_id, $user);
    }

    public static function update_profile($user_id, $name, $phone = '') {
        global $wpdb;
        $user = self::get_user((int) $user_id);
        $name = trim(sanitize_text_field($name));
        $submitted_phone = Hamnoor_Validator::normalize_phone($phone);
        if (!$user || !$name) {
            return new WP_Error('hamnoor_profile_invalid', Hamnoor_Texts::get('form_incomplete'));
        }
        if (!self::has_recent_step_up((int) $user_id)) {
            return new WP_Error(
                'hamnoor_sensitive_verification_required',
                __('برای تغییر نام، یک تأیید پیامکی تازه لازم است.', 'hamnoor')
            );
        }
        if ($submitted_phone && !hash_equals((string) $user->phone, $submitted_phone)) {
            return new WP_Error(
                'hamnoor_mobile_change_requires_otp',
                __('تغییر شماره موبایل فقط از مسیر «تغییر شماره» و پس از تأیید کد پیامکی امکان‌پذیر است.', 'hamnoor')
            );
        }
        $ok = $wpdb->update(
            Hamnoor_DB::table('users'),
            array('display_name'=>$name,'updated_at'=>current_time('mysql')),
            array('id'=>(int)$user_id),
            array('%s','%s'),
            array('%d')
        );
        if (false === $ok) { return new WP_Error('hamnoor_profile_failed', __('ذخیره مشخصات انجام نشد.', 'hamnoor')); }
        $updated_user = self::get_user((int)$user_id);
        if ($updated_user && !empty($updated_user->wp_user_id)) { Hamnoor_WP_User_Bridge::sync_profile((int)$updated_user->wp_user_id, $updated_user); }
        self::$current_user_loaded = false;
        return true;
    }

    public static function ajax_begin_create() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $phone = isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '';
        $prepared = self::begin_create_request($name, $phone);
        if (is_wp_error($prepared)) { wp_send_json_error(array('message'=>$prepared->get_error_message())); }

        $current = self::current_user();
        if ($current && self::has_sensitive_access((int)$current->id) && hash_equals((string)$current->phone, (string)$prepared['phone'])) {
            wp_send_json_success(array(
                'message'=>__('هویت این نشست به‌تازگی تأیید شده است؛ نیازی به کد تازه نیست.', 'hamnoor'),
                'verified'=>true,
                'redirect'=>Hamnoor_Helpers::page_url('create'),
                'phone'=>$prepared['phone'],
            ));
        }

        $sent = self::send_create_access_otp($prepared['user'], $prepared['phone'], $prepared['name']);
        if (is_wp_error($sent)) { wp_send_json_error(array('message'=>$sent->get_error_message())); }

        wp_send_json_success(array(
            'message' => __('کد تأیید ارسال شد. کد را در همین فرم وارد کنید.', 'hamnoor'),
            'request_token' => $sent['request_token'],
            'resend_in' => $sent['resend_in'],
            'phone' => $prepared['phone'],
        ));
    }

    public static function ajax_verify_create_otp() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        global $wpdb;

        // Preserve the session that initiated the request. In Bale Web this can be
        // the dedicated partitioned hamnoor_bale_session cookie. Replacing it with
        // a normal browser cookie would make the subsequent create-access grant
        // belong to a session that the Mini App iframe may not send back.
        $current_before_verify = self::current_user();

        $settings = self::otp_settings();
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $code = isset($_POST['code']) ? preg_replace('/\D+/', '', Hamnoor_Validator::normalize_digits(wp_unslash($_POST['code']))) : '';
        $request_token = isset($_POST['request_token']) ? sanitize_text_field(wp_unslash($_POST['request_token'])) : '';

        if (!Hamnoor_Validator::valid_iran_mobile($phone) || !$code || !$request_token) {
            wp_send_json_error(array('message'=>__('شماره موبایل یا کد تأیید معتبر نیست.', 'hamnoor')));
        }

        $key = self::create_access_otp_key($phone);
        $payload = get_transient($key);
        if (!is_array($payload) || time() > (int) $payload['expires_at'] || 'create_access' !== (string) ($payload['context'] ?? '')) {
            delete_transient($key);
            wp_send_json_error(array('message'=>__('کد منقضی شده است؛ کد تازه دریافت کنید.', 'hamnoor')));
        }
        if (empty($payload['token_hash']) || !hash_equals((string) $payload['token_hash'], self::hash_key($request_token))) {
            wp_send_json_error(array('message'=>__('درخواست تأیید معتبر نیست؛ کد تازه دریافت کنید.', 'hamnoor')));
        }

        $expected = hash_hmac('sha256', $phone . '|' . $code, wp_salt('auth'));
        if (!hash_equals((string) $payload['hash'], $expected)) {
            $payload['attempts'] = (int) $payload['attempts'] + 1;
            if ($payload['attempts'] >= (int) $settings['verify_max_attempts']) {
                delete_transient($key);
                wp_send_json_error(array('message'=>__('تعداد تلاش‌ها بیش از حد مجاز بود؛ کد تازه دریافت کنید.', 'hamnoor')));
            }
            set_transient($key, $payload, max(60, (int) $payload['expires_at'] - time() + 60));
            wp_send_json_error(array('message'=>__('کد تأیید نادرست است.', 'hamnoor')));
        }

        $user = self::ensure_user($phone, isset($payload['name']) ? (string) $payload['name'] : '');
        if (is_wp_error($user) || !$user || !hash_equals((string) $user->phone, $phone)) {
            delete_transient($key);
            $message = is_wp_error($user) ? $user->get_error_message() : __('اطلاعات حساب پیدا نشد؛ لطفاً دوباره شماره خود را وارد کنید.', 'hamnoor');
            wp_send_json_error(array('message'=>$message));
        }

        $was_verified = 1 === (int) $user->mobile_verified;
        if (!$was_verified) {
            $updated = $wpdb->update(
                Hamnoor_DB::table('users'),
                array('mobile_verified'=>1, 'verified_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')),
                array('id'=>(int) $user->id),
                array('%d','%s','%s'),
                array('%d')
            );
            $user = self::get_user((int) $user->id);
            if (false === $updated || !$user || 1 !== (int) $user->mobile_verified) {
                wp_send_json_error(array('message'=>__('ثبت تأیید موبایل انجام نشد؛ دوباره تلاش کنید.', 'hamnoor')));
            }
        }

        if ($current_before_verify) {
            if ((int) $current_before_verify->id !== (int) $user->id) {
                wp_send_json_error(array('message'=>__('شماره تأییدشده با پنل فعلی تطبیق ندارد. پنل را دوباره باز کنید.', 'hamnoor')));
            }
            // Keep the currently authenticated session (including Bale Mini App
            // sessions) and grant create access to that exact session.
            self::$current_user_loaded = false;
            self::$current_user = null;
            $current_after_verify = self::current_user();
            if (!$current_after_verify || (int) $current_after_verify->id !== (int) $user->id) {
                wp_send_json_error(array('message'=>__('نشست پنل پس از تأیید در دسترس نیست. پنل را دوباره باز کنید.', 'hamnoor')));
            }
        } else {
            $logged = self::login($user, true);
            if (is_wp_error($logged)) { wp_send_json_error(array('message'=>$logged->get_error_message())); }
        }
        if (!self::grant_create_access((int)$user->id)) {
            wp_send_json_error(array('message'=>__('کد تأیید درست بود، اما مجوز موقت ایجاد پویش ساخته نشد. دوباره تلاش کنید.', 'hamnoor')));
        }

        delete_transient($key);
        if (!$was_verified) {
            Hamnoor_DB::log(0, 0, 0, (int) $user->id, 'mobile_verified', __('شماره موبایل هنگام آغاز ایجاد پویش تأیید شد.', 'hamnoor'));
            Hamnoor_DB::add_notification((int) $user->id, 'mobile_verified', __('موبایل تأیید شد', 'hamnoor'), __('اکنون می‌توانید پویش جدید ایجاد و پویش‌های خود را مدیریت کنید.', 'hamnoor'));
        } else {
            Hamnoor_DB::log(0, 0, 0, (int) $user->id, 'session_recovered', __('پنل با کد پیامکی برای ایجاد پویش بازیابی شد.', 'hamnoor'));
        }

        wp_send_json_success(array(
            'message'=>__('شماره موبایل تأیید شد. فرم ایجاد پویش در حال بازشدن است.', 'hamnoor'),
            'redirect'=>Hamnoor_Helpers::page_url('create'),
        ));
    }

    public static function post_begin_create() {
        check_admin_referer('hamnoor_begin_create_access');
        Hamnoor_Helpers::redirect_with_notice(
            Hamnoor_Helpers::page_url('create'),
            __('برای ارسال و تأیید کد موبایل، JavaScript مرورگر را فعال و دوباره تلاش کنید.', 'hamnoor'),
            'warning'
        );
    }

    public static function post_logout() {
        check_admin_referer('hamnoor_mobile_logout');
        self::logout();
        wp_safe_redirect(Hamnoor_Helpers::page_url('dashboard')); exit;
    }

    private static function begin_create_request($name, $phone) {
        $name = trim(sanitize_text_field($name));
        $phone = Hamnoor_Validator::normalize_phone($phone);
        if (!$name) { return new WP_Error('hamnoor_name_required', __('نام و نام خانوادگی الزامی است.', 'hamnoor')); }
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) { return new WP_Error('hamnoor_mobile_invalid', Hamnoor_Texts::get('mobile_invalid')); }
        $rate = Hamnoor_Helpers::rate_limit('begin_create_khatm', $phone, 10, 30 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $user = self::find_user($phone);
        return array('user'=>$user, 'phone'=>$phone, 'name'=>Hamnoor_Validator::trim_len($name, 191));
    }

    private static function create_access_otp_key($phone) {
        return 'hamnoor_create_otp_' . substr(self::hash_key($phone), 0, 30);
    }

    private static function send_create_access_otp($user, $phone, $name) {
        $name = trim(sanitize_text_field($name));
        if (!Hamnoor_Validator::valid_iran_mobile($phone) || !$name || ($user && (!isset($user->id) || !hash_equals((string) $user->phone, (string) $phone)))) {
            return new WP_Error('hamnoor_create_access_invalid', __('اطلاعات آغاز ایجاد پویش معتبر نیست.', 'hamnoor'));
        }

        $settings = self::otp_settings();
        $cooldown_key = 'hamnoor_cd_' . substr(self::hash_key('create|' . $phone), 0, 28);
        if (get_transient($cooldown_key)) {
            return new WP_Error('hamnoor_otp_cooldown', __('برای ارسال دوباره کمی صبر کنید.', 'hamnoor'));
        }

        $phone_key = self::counter_key('phone', $phone);
        $ip_key = self::counter_key('ip', self::client_ip());
        if ((int) get_transient($phone_key) >= (int) $settings['phone_hourly_limit'] || (int) get_transient($ip_key) >= (int) $settings['ip_hourly_limit']) {
            return new WP_Error('hamnoor_otp_rate_limited', __('تعداد درخواست‌های پیامک بیش از حد مجاز است. کمی بعد تلاش کنید.', 'hamnoor'));
        }

        $digits = max(4, min(8, (int) $settings['otp_digits']));
        $code = (string) random_int((int) pow(10, $digits - 1), (int) pow(10, $digits) - 1);
        $e164 = '+98' . substr($phone, 1);
        if (class_exists('MIO_IPPanel')) { $sent = (new MIO_IPPanel())->send_otp($e164, $code); }
        else { $sent = apply_filters('hamnoor_send_otp', new WP_Error('hamnoor_otp_provider_missing', __('افزونه یا ارائه‌دهنده پیامک پیکربندی نشده است.', 'hamnoor')), $e164, $code); }
        if (is_wp_error($sent)) { return $sent; }

        $request_token = wp_generate_password(32, false, false);
        $payload = array(
            'context'=>'create_access',
            'user_id'=>$user ? (int) $user->id : 0,
            'name'=>Hamnoor_Validator::trim_len($name, 191),
            'hash'=>hash_hmac('sha256', $phone . '|' . $code, wp_salt('auth')),
            'expires_at'=>time() + (int) $settings['otp_expiry'],
            'attempts'=>0,
            'token_hash'=>self::hash_key($request_token),
        );
        set_transient(self::create_access_otp_key($phone), $payload, (int) $settings['otp_expiry'] + 60);
        set_transient($cooldown_key, 1, (int) $settings['resend_delay']);
        set_transient($phone_key, (int) get_transient($phone_key) + 1, HOUR_IN_SECONDS);
        set_transient($ip_key, (int) get_transient($ip_key) + 1, HOUR_IN_SECONDS);

        return array('request_token'=>$request_token, 'resend_in'=>(int) $settings['resend_delay']);
    }

    private static function otp_settings() {
        $defaults = array('otp_digits'=>5,'otp_expiry'=>120,'resend_delay'=>60,'phone_hourly_limit'=>5,'ip_hourly_limit'=>60,'verify_max_attempts'=>5);
        if (class_exists('MIO_Settings')) { return wp_parse_args(MIO_Settings::get(), $defaults); }
        return apply_filters('hamnoor_otp_settings', $defaults);
    }

    private static function hash_key($value) { return hash_hmac('sha256', (string)$value, wp_salt('auth')); }
    private static function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; }
    private static function otp_key($phone) { return 'hamnoor_otp_' . substr(self::hash_key($phone), 0, 30); }
    private static function counter_key($type,$value) { return 'hamnoor_rl_' . $type . '_' . substr(self::hash_key($value),0,24); }

    public static function ajax_send_otp() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $current = self::current_user();
        if (!$current) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        if (!Hamnoor_Validator::valid_iran_mobile($phone) || $phone !== (string) $current->phone) { wp_send_json_error(array('message'=>__('شماره موبایل با پنل فعلی تطبیق ندارد.', 'hamnoor'))); }
        $settings = self::otp_settings();
        $cooldown_key = 'hamnoor_cd_' . substr(self::hash_key($phone),0,28);
        if (get_transient($cooldown_key)) { wp_send_json_error(array('message'=>__('برای ارسال دوباره کمی صبر کنید.', 'hamnoor'))); }
        $phone_key = self::counter_key('phone',$phone); $ip_key = self::counter_key('ip',self::client_ip());
        if ((int)get_transient($phone_key) >= (int)$settings['phone_hourly_limit'] || (int)get_transient($ip_key) >= (int)$settings['ip_hourly_limit']) {
            wp_send_json_error(array('message'=>__('تعداد درخواست‌های پیامک بیش از حد مجاز است. کمی بعد تلاش کنید.', 'hamnoor')));
        }
        $digits = max(4,min(8,(int)$settings['otp_digits']));
        $code = (string)random_int((int)pow(10,$digits-1),(int)pow(10,$digits)-1);
        $e164 = '+98' . substr($phone,1);
        if (class_exists('MIO_IPPanel')) { $sent = (new MIO_IPPanel())->send_otp($e164, $code); }
        else { $sent = apply_filters('hamnoor_send_otp', new WP_Error('hamnoor_otp_provider_missing', __('افزونه یا ارائه‌دهنده پیامک پیکربندی نشده است.', 'hamnoor')), $e164, $code); }
        if (is_wp_error($sent)) { wp_send_json_error(array('message'=>$sent->get_error_message())); }
        $request_token = wp_generate_password(32,false,false);
        $payload = array('user_id'=>(int)$current->id,'hash'=>hash_hmac('sha256',$phone.'|'.$code,wp_salt('auth')),'expires_at'=>time()+(int)$settings['otp_expiry'],'attempts'=>0,'token_hash'=>self::hash_key($request_token));
        set_transient(self::otp_key($phone),$payload,(int)$settings['otp_expiry']+60);
        set_transient($cooldown_key,1,(int)$settings['resend_delay']);
        set_transient($phone_key,(int)get_transient($phone_key)+1,HOUR_IN_SECONDS);
        set_transient($ip_key,(int)get_transient($ip_key)+1,HOUR_IN_SECONDS);
        wp_send_json_success(array('message'=>__('کد تأیید ارسال شد.', 'hamnoor'),'request_token'=>$request_token,'resend_in'=>(int)$settings['resend_delay']));
    }

    public static function ajax_verify_otp() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        global $wpdb;
        $current = self::current_user();
        if (!$current) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }
        $settings = self::otp_settings();
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $code = isset($_POST['code']) ? preg_replace('/\D+/','',Hamnoor_Validator::normalize_digits(wp_unslash($_POST['code']))) : '';
        $request_token = isset($_POST['request_token']) ? sanitize_text_field(wp_unslash($_POST['request_token'])) : '';
        if ($phone !== (string) $current->phone) { wp_send_json_error(array('message'=>__('شماره موبایل با پنل فعلی تطبیق ندارد.', 'hamnoor'))); }
        $payload = get_transient(self::otp_key($phone));
        if (!is_array($payload) || time() > (int)$payload['expires_at'] || (int)$payload['user_id'] !== (int)$current->id) { delete_transient(self::otp_key($phone)); wp_send_json_error(array('message'=>__('کد منقضی شده است؛ کد تازه دریافت کنید.', 'hamnoor'))); }
        if (empty($payload['token_hash']) || !hash_equals($payload['token_hash'], self::hash_key($request_token))) { wp_send_json_error(array('message'=>__('نشست تأیید معتبر نیست؛ کد تازه دریافت کنید.', 'hamnoor'))); }
        $expected = hash_hmac('sha256',$phone.'|'.$code,wp_salt('auth'));
        if (!hash_equals($payload['hash'],$expected)) {
            $payload['attempts']++;
            if ($payload['attempts'] >= (int)$settings['verify_max_attempts']) { delete_transient(self::otp_key($phone)); wp_send_json_error(array('message'=>__('تعداد تلاش‌ها بیش از حد مجاز بود؛ کد تازه دریافت کنید.', 'hamnoor'))); }
            set_transient(self::otp_key($phone),$payload,max(60,(int)$payload['expires_at']-time()+60));
            wp_send_json_error(array('message'=>__('کد تأیید نادرست است.', 'hamnoor')));
        }
        $wpdb->update(Hamnoor_DB::table('users'), array('mobile_verified'=>1,'verified_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('id'=>(int)$current->id), array('%d','%s','%s'), array('%d'));
        delete_transient(self::otp_key($phone));
        self::$current_user_loaded = false;
        self::$current_user = null;
        // Reload the same session after verification, then grant short-lived
        // permission for sensitive owner actions.
        self::current_user();
        if (!self::grant_sensitive_access((int)$current->id, 'sms_otp')) {
            wp_send_json_error(array('message'=>__('کد درست بود، اما مجوز امن این نشست ساخته نشد. دوباره تلاش کنید.', 'hamnoor')));
        }
        Hamnoor_DB::log(0, 0, 0, (int)$current->id, 'mobile_verified', __('شماره موبایل تأیید شد.', 'hamnoor'));
        Hamnoor_DB::add_notification((int)$current->id, 'mobile_verified', __('موبایل تأیید شد', 'hamnoor'), __('اکنون می‌توانید پویش جدید ایجاد و پویش‌های خود را مدیریت کنید.', 'hamnoor'));
        $requested_redirect = isset($_POST['redirect']) ? wp_unslash($_POST['redirect']) : '';
        $redirect = Hamnoor_Helpers::safe_redirect_url($requested_redirect, Hamnoor_Helpers::page_url('dashboard'));
        wp_send_json_success(array('message'=>__('شماره موبایل با موفقیت تأیید شد.', 'hamnoor'),'redirect'=>$redirect));
    }

    private static function change_mobile_otp_key($user_id, $phone) {
        return 'hamnoor_change_mobile_' . substr(self::hash_key((int) $user_id . '|' . $phone), 0, 30);
    }

    public static function ajax_send_change_mobile_otp() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $current = self::current_user();
        if (!$current) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }

        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            wp_send_json_error(array('message'=>Hamnoor_Texts::get('mobile_invalid')));
        }
        if (hash_equals((string) $current->phone, $phone)) {
            wp_send_json_error(array('message'=>__('شماره جدید با شماره فعلی یکسان است.', 'hamnoor')));
        }
        $other = self::find_user($phone);
        if ($other && (int) $other->id !== (int) $current->id) {
            wp_send_json_error(array('message'=>__('این شماره موبایل به پنل دیگری متصل است.', 'hamnoor')));
        }

        $settings = self::otp_settings();
        $cooldown_key = 'hamnoor_change_cd_' . substr(self::hash_key((int)$current->id . '|' . $phone), 0, 28);
        if (get_transient($cooldown_key)) {
            wp_send_json_error(array('message'=>__('برای ارسال دوباره کمی صبر کنید.', 'hamnoor')));
        }
        $phone_key = self::counter_key('change_phone', $phone);
        $ip_key = self::counter_key('change_ip', self::client_ip());
        if ((int) get_transient($phone_key) >= (int) $settings['phone_hourly_limit'] || (int) get_transient($ip_key) >= (int) $settings['ip_hourly_limit']) {
            wp_send_json_error(array('message'=>__('تعداد درخواست‌های پیامک بیش از حد مجاز است. کمی بعد تلاش کنید.', 'hamnoor')));
        }

        $digits = max(4, min(8, (int) $settings['otp_digits']));
        $code = (string) random_int((int) pow(10, $digits - 1), (int) pow(10, $digits) - 1);
        $e164 = '+98' . substr($phone, 1);
        if (class_exists('MIO_IPPanel')) {
            $sent = (new MIO_IPPanel())->send_otp($e164, $code);
        } else {
            $sent = apply_filters(
                'hamnoor_send_otp',
                new WP_Error('hamnoor_otp_provider_missing', __('افزونه یا ارائه‌دهنده پیامک پیکربندی نشده است.', 'hamnoor')),
                $e164,
                $code
            );
        }
        if (is_wp_error($sent)) { wp_send_json_error(array('message'=>$sent->get_error_message())); }

        $request_token = wp_generate_password(32, false, false);
        $payload = array(
            'user_id' => (int) $current->id,
            'phone' => $phone,
            'hash' => hash_hmac('sha256', $phone . '|' . $code, wp_salt('auth')),
            'expires_at' => time() + (int) $settings['otp_expiry'],
            'attempts' => 0,
            'token_hash' => self::hash_key($request_token),
        );
        set_transient(self::change_mobile_otp_key((int)$current->id, $phone), $payload, (int)$settings['otp_expiry'] + 60);
        set_transient($cooldown_key, 1, (int)$settings['resend_delay']);
        set_transient($phone_key, (int)get_transient($phone_key) + 1, HOUR_IN_SECONDS);
        set_transient($ip_key, (int)get_transient($ip_key) + 1, HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'message'=>__('کد تأیید به شماره جدید ارسال شد.', 'hamnoor'),
            'request_token'=>$request_token,
            'resend_in'=>(int)$settings['resend_delay'],
        ));
    }

    public static function ajax_verify_change_mobile_otp() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        global $wpdb;
        $current = self::current_user();
        if (!$current) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }

        $settings = self::otp_settings();
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $code = isset($_POST['code']) ? preg_replace('/\D+/', '', Hamnoor_Validator::normalize_digits(wp_unslash($_POST['code']))) : '';
        $request_token = isset($_POST['request_token']) ? sanitize_text_field(wp_unslash($_POST['request_token'])) : '';
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            wp_send_json_error(array('message'=>Hamnoor_Texts::get('mobile_invalid')));
        }

        $key = self::change_mobile_otp_key((int)$current->id, $phone);
        $payload = get_transient($key);
        if (!is_array($payload) || time() > (int)$payload['expires_at'] || (int)$payload['user_id'] !== (int)$current->id || (string)$payload['phone'] !== $phone) {
            delete_transient($key);
            wp_send_json_error(array('message'=>__('کد منقضی شده است؛ کد تازه دریافت کنید.', 'hamnoor')));
        }
        if (empty($payload['token_hash']) || !hash_equals((string)$payload['token_hash'], self::hash_key($request_token))) {
            wp_send_json_error(array('message'=>__('نشست تأیید معتبر نیست؛ کد تازه دریافت کنید.', 'hamnoor')));
        }
        $expected = hash_hmac('sha256', $phone . '|' . $code, wp_salt('auth'));
        if (!hash_equals((string)$payload['hash'], $expected)) {
            $payload['attempts']++;
            if ($payload['attempts'] >= (int)$settings['verify_max_attempts']) {
                delete_transient($key);
                wp_send_json_error(array('message'=>__('تعداد تلاش‌ها بیش از حد مجاز بود؛ کد تازه دریافت کنید.', 'hamnoor')));
            }
            set_transient($key, $payload, max(60, (int)$payload['expires_at'] - time() + 60));
            wp_send_json_error(array('message'=>__('کد تأیید نادرست است.', 'hamnoor')));
        }

        $other = self::find_user($phone);
        if ($other && (int)$other->id !== (int)$current->id) {
            delete_transient($key);
            wp_send_json_error(array('message'=>__('این شماره موبایل اکنون به پنل دیگری متصل است.', 'hamnoor')));
        }

        $now = current_time('mysql');
        $old_phone = Hamnoor_Validator::normalize_phone((string)$current->phone);
        $updated = $wpdb->update(
            Hamnoor_DB::table('users'),
            array('phone'=>$phone,'mobile_verified'=>1,'verified_at'=>$now,'updated_at'=>$now),
            array('id'=>(int)$current->id),
            array('%s','%d','%s','%s'),
            array('%d')
        );
        if (false === $updated) {
            wp_send_json_error(array('message'=>__('تغییر شماره موبایل انجام نشد.', 'hamnoor')));
        }

        $wpdb->update(
            Hamnoor_DB::table('participants'),
            array('phone'=>$phone,'normalized_phone'=>$phone,'updated_at'=>$now),
            array('user_id'=>(int)$current->id),
            array('%s','%s','%s'),
            array('%d')
        );
        $wpdb->update(
            Hamnoor_DB::table('khatms'),
            array('owner_phone'=>$phone,'updated_at'=>$now),
            array('owner_user_id'=>(int)$current->id),
            array('%s','%s'),
            array('%d')
        );

        $fresh_user = self::get_user((int)$current->id);
        if ($fresh_user && !empty($fresh_user->wp_user_id)) { Hamnoor_WP_User_Bridge::sync_profile((int)$fresh_user->wp_user_id, $fresh_user); }

        $sessions = Hamnoor_DB::table('sessions');
        $session_id = !empty($current->session_id) ? (int)$current->session_id : 0;
        if ($session_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $sessions SET revoked_at = %s WHERE user_id = %d AND id <> %d AND revoked_at IS NULL",
                gmdate('Y-m-d H:i:s'),
                (int)$current->id,
                $session_id
            ));
        }

        delete_transient($key);
        self::$current_user_loaded = false;
        self::$current_user = null;
        self::current_user();
        self::grant_sensitive_access((int)$current->id, 'change_mobile_sms_otp');
        /** Notify channel add-ons AFTER the new phone is committed. */
        do_action('hamnoor_mobile_changed', (int)$current->id, $old_phone, $phone);
        Hamnoor_DB::log(0, 0, 0, (int)$current->id, 'mobile_changed', __('شماره موبایل با تأیید کد پیامکی تغییر کرد.', 'hamnoor'));
        Hamnoor_DB::add_notification(
            (int)$current->id,
            'mobile_changed',
            __('شماره موبایل تغییر کرد', 'hamnoor'),
            __('شماره موبایل پنل شما با موفقیت تغییر و تأیید شد. نشست‌های دیگر برای امنیت بیشتر بسته شدند.', 'hamnoor')
        );

        wp_send_json_success(array(
            'message'=>__('شماره موبایل با موفقیت تغییر و تأیید شد.', 'hamnoor'),
            'redirect'=>Hamnoor_Helpers::safe_redirect_url(
                isset($_POST['redirect']) ? wp_unslash($_POST['redirect']) : '',
                add_query_arg('tab', 'profile', Hamnoor_Helpers::page_url('dashboard'))
            ),
        ));
    }

    public static function ajax_begin_recovery() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) { wp_send_json_error(array('message'=>Hamnoor_Texts::get('mobile_invalid'))); }
        $ip = self::client_ip() ?: 'unknown';
        $rate = Hamnoor_Helpers::rate_limit('recover_panel_low_assurance', $ip . '|' . $phone, 20, 30 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message())); }
        $user = self::find_user($phone);
        if (!$user) {
            wp_send_json_error(array('message'=>__('برای این شماره هنوز پنلی وجود ندارد. نخستین پنل هنگام اولین انتخاب سهم یا شروع ایجاد پویش ساخته می‌شود.', 'hamnoor')));
        }
        $logged = self::login($user, true);
        if (is_wp_error($logged)) { wp_send_json_error(array('message'=>$logged->get_error_message())); }
        Hamnoor_DB::sync_guest_history_to_user((int)$user->id);
        $redirect = add_query_arg('tab','my-parts',Hamnoor_Helpers::page_url('dashboard'));
        $requested_part = isset($_POST['part_id']) ? absint($_POST['part_id']) : 0;
        if ($requested_part > 0) {
            $part = Hamnoor_DB::get_part($requested_part);
            if ($part && !empty($part->participant_user_id) && (int)$part->participant_user_id === (int)$user->id) { $redirect = Hamnoor_Reading::reading_url($requested_part); }
        }
        Hamnoor_DB::log(0,0,0,(int)$user->id,'session_low_assurance_started',__('نشست عادی هم‌نور بدون پیامک در دستگاه جدید ایجاد شد.', 'hamnoor'));
        wp_send_json_success(array(
            'message'=>__('ورود انجام شد.', 'hamnoor'),
            'redirect'=>$redirect,
            'requires_otp'=>false,
            'assurance_level'=>Hamnoor_Access_Policy::LEVEL_LOW,
        ));
    }

    public static function recovery_form() {
        ob_start(); ?>
        <div class="hamnoor-card hamnoor-recovery-card" data-hamnoor-recovery="1" aria-labelledby="hamnoor-recovery-title">
            <h2 id="hamnoor-recovery-title"><?php esc_html_e('ورود به هم‌نور', 'hamnoor'); ?></h2>
            <p id="hamnoor-recovery-help"><?php esc_html_e('شماره موبایل پنل خود را وارد کنید. ورود عادی به هم‌نور پیامک نمی‌خواهد.', 'hamnoor'); ?></p>
            <div class="hamnoor-ajax-message" aria-live="polite"></div>
            <div data-recovery-phone-step>
                <label for="hamnoor-recovery-phone"><?php esc_html_e('شماره موبایل', 'hamnoor'); ?></label>
                <input id="hamnoor-recovery-phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="مثلاً 09123456789" dir="ltr" aria-describedby="hamnoor-recovery-help">
                <button type="button" class="hamnoor-btn hamnoor-btn-primary" data-recovery-send><?php esc_html_e('ورود به هم‌نور', 'hamnoor'); ?></button>
            </div>
            <small class="hamnoor-recovery-privacy"><?php esc_html_e('ورود عادی، مشاهده داشبورد، سهم‌ها و قرائت بدون پیامک انجام می‌شود. کد پیامکی فقط هنگام عملیات حساس مانند ایجاد یا ویرایش پویش و تغییر مشخصات لازم است.', 'hamnoor'); ?></small>
        </div>
        <?php return ob_get_clean();
    }

    public static function create_verification_form($redirect = '') {
        $user = self::current_user();
        if (!$user) { return self::begin_create_form(); }
        $redirect = $redirect ?: Hamnoor_Helpers::page_url('create');
        ob_start(); ?>
        <div class="hamnoor-card hamnoor-begin-create hamnoor-create-access-card">
            <h2><?php esc_html_e('تأیید موبایل برای ایجاد پویش', 'hamnoor'); ?></h2>
            <p><?php esc_html_e('انتخاب سهم به پیامک نیاز ندارد؛ اما ورود مستقیم به داشبورد و ایجاد یا ویرایش پویش فقط پس از تأیید شماره با کد پیامکی یا مسیر معتبر پیام‌رسان انجام می‌شود.', 'hamnoor'); ?></p>
            <div class="hamnoor-ajax-message" aria-live="polite"></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-hamnoor-begin-create="1" novalidate>
                <?php wp_nonce_field('hamnoor_begin_create_access'); ?>
                <input type="hidden" name="action" value="hamnoor_begin_create_access">
                <div data-create-identity-step>
                    <input type="hidden" name="name" value="<?php echo esc_attr((string)$user->display_name); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr((string)$user->phone); ?>">
                    <p><?php esc_html_e('کد تأیید به شماره زیر ارسال می‌شود:', 'hamnoor'); ?> <strong><?php echo esc_html((string)$user->phone); ?></strong></p>
                    <button type="submit" class="hamnoor-btn hamnoor-btn-primary" data-create-send><?php esc_html_e('ارسال کد تأیید ایجاد پویش', 'hamnoor'); ?></button>
                </div>
                <div data-create-code-step hidden>
                    <p><?php esc_html_e('کد پیامکی را وارد کنید.', 'hamnoor'); ?> <strong data-create-phone-display></strong></p>
                    <label><?php esc_html_e('کد تأیید', 'hamnoor'); ?><input type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
                    <div class="hamnoor-inline-actions"><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-verify-create-otp><?php esc_html_e('تأیید و ادامه', 'hamnoor'); ?></button><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-resend-create-otp><?php esc_html_e('ارسال دوباره', 'hamnoor'); ?></button></div>
                    <small data-create-resend-countdown></small>
                </div>
            </form>
        </div>
        <?php return ob_get_clean();
    }

    public static function change_mobile_modal() {
        $user = self::current_user();
        if (!$user) { return ''; }
        $modal_id = 'hamnoor-change-mobile-modal';
        return '<div id="'.esc_attr($modal_id).'" class="hamnoor-modal" hidden aria-hidden="true"><div class="hamnoor-modal-backdrop" data-hamnoor-modal-close></div><div class="hamnoor-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="hamnoor-change-mobile-title"><button type="button" class="hamnoor-modal-close" aria-label="'.esc_attr__('بستن','hamnoor').'" data-hamnoor-modal-close>×</button><div class="hamnoor-modal-body" data-hamnoor-change-mobile="1"><h2 id="hamnoor-change-mobile-title">'.esc_html__('تغییر شماره موبایل','hamnoor').'</h2><p>'.esc_html__('شماره جدید باید با کد پیامکی تأیید شود. پس از تغییر، نشست‌های دیگر این پنل بسته می‌شوند.','hamnoor').'</p><div class="hamnoor-ajax-message" aria-live="polite"></div><div data-change-mobile-phone-step><label>'.esc_html__('شماره موبایل جدید','hamnoor').'<input type="tel" inputmode="numeric" autocomplete="tel" placeholder="09123456789"></label><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-send-change-mobile-otp>'.esc_html__('ارسال کد','hamnoor').'</button></div><div data-change-mobile-code-step hidden><label>'.esc_html__('کد تأیید','hamnoor').'<input type="text" inputmode="numeric" autocomplete="one-time-code"></label><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-verify-change-mobile-otp>'.esc_html__('تأیید و تغییر شماره','hamnoor').'</button></div></div></div></div>';
    }

    public static function begin_create_form() {
        $action = admin_url('admin-post.php');
        ob_start();
        ?>
        <div class="hamnoor-card hamnoor-begin-create hamnoor-create-access-card">
            <h2><?php esc_html_e('آغاز ایجاد پویش', 'hamnoor'); ?></h2>
            <p><?php esc_html_e('نام و شماره موبایل خود را وارد کنید. با زدن دکمه ادامه، کد تأیید همان لحظه ارسال و کادر ورود کد در همین فرم باز می‌شود.', 'hamnoor'); ?></p>
            <div class="hamnoor-ajax-message" aria-live="polite"></div>
            <form method="post" action="<?php echo esc_url($action); ?>" data-hamnoor-begin-create="1" novalidate>
                <?php wp_nonce_field('hamnoor_begin_create_access'); ?>
                <input type="hidden" name="action" value="hamnoor_begin_create_access">
                <div data-create-identity-step>
                    <label><?php esc_html_e('نام و نام خانوادگی', 'hamnoor'); ?><input type="text" name="name" maxlength="191" autocomplete="name" required></label>
                    <label><?php esc_html_e('شماره موبایل', 'hamnoor'); ?><input type="tel" name="phone" placeholder="09123456789" inputmode="numeric" autocomplete="tel" required></label>
                    <button class="hamnoor-btn hamnoor-btn-primary" type="submit" data-create-send><?php esc_html_e('ادامه و تأیید موبایل', 'hamnoor'); ?></button>
                </div>
                <div class="hamnoor-create-code-step" data-create-code-step hidden>
                    <p><?php esc_html_e('کد تأیید به شماره', 'hamnoor'); ?> <strong dir="ltr" data-create-phone-display></strong> <?php esc_html_e('ارسال شد.', 'hamnoor'); ?></p>
                    <label><?php esc_html_e('کد تأیید', 'hamnoor'); ?><input type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
                    <div class="hamnoor-create-code-actions">
                        <button class="hamnoor-btn hamnoor-btn-primary" type="button" data-verify-create-otp><?php esc_html_e('تأیید کد و ورود به فرم پویش', 'hamnoor'); ?></button>
                        <button class="hamnoor-btn hamnoor-btn-secondary" type="button" data-resend-create-otp disabled><?php esc_html_e('ارسال دوباره کد', 'hamnoor'); ?></button>
                        <button class="hamnoor-link-button" type="button" data-edit-create-identity><?php esc_html_e('اصلاح نام یا شماره', 'hamnoor'); ?></button>
                    </div>
                    <small data-create-resend-countdown aria-live="polite"></small>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function verification_box($mode = 'optional', $redirect = '') {
        $user = self::current_user();
        if (!$user) { return ''; }
        $required = 'required' === $mode;
        if ($required ? self::has_sensitive_access((int)$user->id) : self::is_verified((int)$user->id)) { return ''; }
        $redirect = Hamnoor_Helpers::safe_redirect_url($redirect, Hamnoor_Helpers::page_url('dashboard'));
        return '<div class="hamnoor-card hamnoor-mobile-verification" data-hamnoor-verify-box="1" data-required="'.($required?'1':'0').'" data-redirect="'.esc_attr($redirect).'" data-current-phone="'.esc_attr($user->phone).'"><h3>'.esc_html($required ? __('تأیید هویت برای عملیات حساس','hamnoor') : __('تأیید موبایل','hamnoor')).'</h3><p>'.esc_html($required ? __('برای ادامهٔ این عملیات حساس، هویت همین نشست را با کد پیامکی تأیید کنید.','hamnoor') : __('شماره فعلی را بررسی کنید؛ در صورت نیاز می‌توانید آن را پیش از دریافت کد اصلاح کنید.','hamnoor')).'</p><div class="hamnoor-ajax-message" aria-live="polite"></div><div data-otp-phone-step><label>'.esc_html__('شماره موبایل','hamnoor').'<input type="tel" value="'.esc_attr($user->phone).'" inputmode="numeric" autocomplete="tel"></label><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-send-otp>'.esc_html__('ارسال کد','hamnoor').'</button></div><div data-otp-code-step hidden><p><span data-otp-target-label></span> <strong dir="ltr" data-otp-target-phone></strong></p><label>'.esc_html__('کد تأیید','hamnoor').'<input type="text" inputmode="numeric" autocomplete="one-time-code"></label><button type="button" class="hamnoor-btn hamnoor-btn-primary" data-verify-otp>'.esc_html__('تأیید کد','hamnoor').'</button></div></div>';
    }

    public static function verification_prompt($redirect = '') {
        $user = self::current_user();
        if (!$user || self::is_verified((int)$user->id)) { return ''; }
        $modal_id = 'hamnoor-verification-modal';
        $box = self::verification_box('optional', $redirect);
        return '<div class="hamnoor-verification-reminder"><span>'.esc_html__('شماره موبایل شما هنوز تأیید نشده است.','hamnoor').'</span> <button type="button" class="hamnoor-link-button" data-hamnoor-open-verify="#'.esc_attr($modal_id).'">'.esc_html__('تأیید موبایل','hamnoor').'</button></div><div id="'.esc_attr($modal_id).'" class="hamnoor-modal" hidden aria-hidden="true"><div class="hamnoor-modal-backdrop" data-hamnoor-modal-close></div><div class="hamnoor-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="hamnoor-verification-title"><button type="button" class="hamnoor-modal-close" aria-label="'.esc_attr__('بستن','hamnoor').'" data-hamnoor-modal-close>×</button><div class="hamnoor-modal-body"><h2 id="hamnoor-verification-title">'.esc_html__('تأیید شماره موبایل','hamnoor').'</h2>'.$box.'</div></div></div>';
    }

    private static function token_hash($token) { return hash_hmac('sha256', (string)$token, wp_salt('auth')); }
    private static function hash_optional($value) { $value = trim((string)$value); return $value ? self::hash_key($value) : ''; }

    private static function cookie_options($expires, $cookie_context = 'default') {
        $cookie_context = (string)$cookie_context;
        $cross_site = ('cross_site' === $cookie_context || (bool)preg_match('/_cross_site$/', $cookie_context));
        $options = array(
            'expires' => (int) $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => ($cross_site && is_ssl()) ? 'None' : 'Lax',
        );
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) { $options['domain'] = COOKIE_DOMAIN; }
        return $options;
    }

    private static function set_cookie($token, $expires, $cookie_context = 'default') {
        if (headers_sent()) { return false; }
        $cookie_context = (string)$cookie_context;
        $cross_site = ('cross_site' === $cookie_context || (bool)preg_match('/_cross_site$/', $cookie_context));
        $name = apply_filters('hamnoor_mobile_cookie_name_for_context', self::COOKIE_NAME, $cookie_context);
        $name = is_string($name) && preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $name) ? $name : self::COOKIE_NAME;

        // Third-party WebViews/iframes need SameSite=None. Add Partitioned
        // (CHIPS) where HTTPS is available so modern Chromium can retain the
        // provider-bound session even when ordinary third-party cookies are
        // restricted. This remains generic; provider add-ons choose the name.
        if ($cross_site && is_ssl()) {
            $parts = array();
            $parts[] = $name . '=' . rawurlencode((string)$token);
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', (int)$expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, (int)$expires - time());
            $parts[] = 'Path=/';
            if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) { $parts[] = 'Domain=' . COOKIE_DOMAIN; }
            $parts[] = 'Secure';
            $parts[] = 'HttpOnly';
            $parts[] = 'SameSite=None';
            $parts[] = 'Partitioned';
            header('Set-Cookie: ' . implode('; ', $parts), false);
            $_COOKIE[$name] = $token;
            return true;
        }

        $ok = setcookie($name, $token, self::cookie_options($expires, $cookie_context));
        if ($ok) { $_COOKIE[$name] = $token; }
        return (bool) $ok;
    }

    private static function clear_cookie() {
        $session_names = self::session_cookie_names();
        $cross_site_names = self::cross_site_cookie_names();
        if (!headers_sent()) {
            foreach ($session_names as $cookie_name) {
                if (in_array($cookie_name, $cross_site_names, true) && is_ssl()) {
                    $parts = array(
                        $cookie_name . '=',
                        'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
                        'Max-Age=0',
                        'Path=/',
                        'Secure',
                        'HttpOnly',
                        'SameSite=None',
                        'Partitioned',
                    );
                    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) { array_splice($parts, 4, 0, 'Domain=' . COOKIE_DOMAIN); }
                    header('Set-Cookie: ' . implode('; ', $parts), false);
                } else {
                    setcookie($cookie_name, '', self::cookie_options(time() - DAY_IN_SECONDS));
                }
            }
        }
        foreach ($session_names as $cookie_name) { unset($_COOKIE[$cookie_name]); }
    }

}
