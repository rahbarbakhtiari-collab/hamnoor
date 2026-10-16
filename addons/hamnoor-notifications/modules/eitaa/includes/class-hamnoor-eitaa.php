<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Eitaa notification adapter.
 *
 * Two APIs are intentionally kept separate:
 * - Public EitaaYar API: https://eitaayar.ir/api/{BOT_TOKEN}/{method}
 * - Eitaa Program API:   https://eitaayar.ir/api/app/{method}
 *
 * Private user reminders always use the official Program API. Program tokens
 * are opaque secrets and are sent in the POST body, never embedded in URLs.
 */
class Hamnoor_Eitaa implements Hamnoor_Notification_Channel_Interface {
    const OPTION = 'hamnoor_eitaa_settings';
    const PUBLIC_API_BASE = 'https://eitaayar.ir/api/';
    const APP_API_BASE = 'https://eitaayar.ir/api/app/';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function init() {
        Hamnoor_Notification_Channel_Registry::register('eitaa', self::instance());
        add_action('admin_post_hamnoor_eitaa_save', array(__CLASS__, 'admin_save'));
        add_action('admin_post_hamnoor_eitaa_test_connection', array(__CLASS__, 'admin_test_connection'));
        add_action('admin_post_hamnoor_eitaa_test_message', array(__CLASS__, 'admin_test_message'));
    }

    public function key() { return 'eitaa'; }

    public function is_available() {
        $s = self::settings();
        return !empty($s['enabled']) && self::program_configured();
    }

    public function is_connected($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table || !(int)$user_id) { return false; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT enabled,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='eitaa' LIMIT 1",
            (int)$user_id
        ));
        if (!$row || 1 !== (int)$row->enabled || 'connected' !== (string)$row->status || empty($row->external_id)) { return false; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$user_id, 'eitaa', $row)) { return false; }
        $meta = json_decode((string)$row->metadata, true);
        return !is_array($meta) || !array_key_exists('write_access', $meta) || !empty($meta['write_access']);
    }

    public function send_to_user($user_id, $notification, $context = array()) {
        if (!$this->is_available()) {
            return new WP_Error('hamnoor_eitaa_not_configured', __('کانال ایتا هنوز پیکربندی نشده است.', 'hamnoor'));
        }
        $chat_id = self::user_chat_id((int)$user_id);
        if (!$chat_id) {
            return new WP_Error('hamnoor_eitaa_not_connected', __('حساب ایتای این کاربر هنوز به هم‌نور متصل نشده است.', 'hamnoor'));
        }
        $message = self::notification_text($notification, $context);
        $result = self::app_send_message($chat_id, $message);
        return is_wp_error($result) ? $result : true;
    }

    public static function settings() {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        $s = wp_parse_args($raw, array(
            'enabled' => '0',
            'token_enc' => '',
            'public_token_enc' => '',
            'program_token_enc' => '',
            'test_chat_id' => '',
            'last_getme_at' => '',
            'last_getme_name' => '',
            'last_getme_username' => '',
            'last_test_at' => '',
            'last_app_send_at' => '',
            'program_username' => '',
            'applet_shortname' => '',
            'miniapp_direct_url' => '',
        ));
        // <=3.7.5 stored the public token in token_enc.
        if (empty($s['public_token_enc']) && !empty($s['token_enc'])) {
            $s['public_token_enc'] = $s['token_enc'];
        }
        return $s;
    }

    public static function public_token() {
        $s = self::settings();
        return empty($s['public_token_enc']) ? '' : self::decrypt_secret((string)$s['public_token_enc']);
    }

    public static function program_token() {
        $s = self::settings();
        return empty($s['program_token_enc']) ? '' : self::decrypt_secret((string)$s['program_token_enc']);
    }

    public static function token() { return self::program_token(); }
    public static function public_configured() { return '' !== self::public_token(); }
    public static function program_configured() { return '' !== self::program_token(); }
    public static function configured() { return self::program_configured(); }

    public static function test_chat_id() {
        $s = self::settings();
        return isset($s['test_chat_id']) ? self::sanitize_chat_id($s['test_chat_id']) : '';
    }

    public static function admin_save() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_eitaa_save');

        $old = self::settings();
        $data = $old;
        $data['enabled'] = !empty($_POST['eitaa_enabled']) ? '1' : '0';
        $data['test_chat_id'] = isset($_POST['eitaa_test_chat_id']) ? self::sanitize_chat_id(wp_unslash($_POST['eitaa_test_chat_id'])) : '';
        $program_username = isset($_POST['eitaa_program_username']) ? sanitize_key(ltrim((string)wp_unslash($_POST['eitaa_program_username']), '@')) : '';
        $applet_raw = isset($_POST['eitaa_applet_shortname']) ? trim((string)wp_unslash($_POST['eitaa_applet_shortname'])) : '';
        if ($program_username) { $data['program_username'] = $program_username; }

        $miniapp_direct_url = isset($_POST['eitaa_miniapp_direct_url']) ? trim((string)wp_unslash($_POST['eitaa_miniapp_direct_url'])) : '';
        // The Web App destination and the Eitaa Mini App direct link are two
        // different values in EitaaYar. Never accept our own /hamnoor-eitaa/
        // endpoint as the notification deep link: opening it directly launches
        // the normal browser and there is no signed Eitaa WebApp context.
        if (preg_match('#^https://#i', $applet_raw)) {
            if ('' === $miniapp_direct_url) { $miniapp_direct_url = $applet_raw; }
            $applet_raw = '';
        }
        $applet_shortname = $applet_raw ? sanitize_key($applet_raw) : '';
        if ($applet_shortname) { $data['applet_shortname'] = $applet_shortname; }
        if ('' !== $miniapp_direct_url) {
            $candidate = esc_url_raw($miniapp_direct_url, array('https'));
            $candidate_host = strtolower((string)wp_parse_url($candidate, PHP_URL_HOST));
            $home_host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ($candidate_host && $home_host && $candidate_host === $home_host) {
                Hamnoor_Helpers::redirect_with_notice(
                    self::admin_url(),
                    sprintf(
                        __('این مقدار «آدرس تارنما» است، نه «پیوند برنامک». در پنل ایتایار، آدرس تارنما را %s قرار دهید و در این کادر «پیوند برنامک» با دامنه eitaa.com را عیناً کپی کنید.', 'hamnoor'),
                        Hamnoor_Eitaa_Bridge::url()
                    ),
                    'error'
                );
            }
            $miniapp_direct_url = self::sanitize_miniapp_direct_url($miniapp_direct_url);
            if (!$miniapp_direct_url) {
                Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('پیوند برنامک معتبر نیست. مقدار «پیوند برنامک» را عیناً از صفحه ویرایش برنامک در پنل ایتایار کپی کنید؛ این لینک باید HTTPS و روی دامنه eitaa.com باشد.', 'hamnoor'), 'error');
            }
        }
        $data['miniapp_direct_url'] = $miniapp_direct_url;

        $public_token_input = isset($_POST['eitaa_public_token']) ? trim((string)wp_unslash($_POST['eitaa_public_token'])) : '';
        if ('' !== $public_token_input) {
            $valid = self::validate_public_token($public_token_input);
            if (is_wp_error($valid)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $valid->get_error_message(), 'error'); }
            $encrypted = self::encrypt_secret($public_token_input);
            if (is_wp_error($encrypted)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $encrypted->get_error_message(), 'error'); }
            $data['public_token_enc'] = $encrypted;
            $data['token_enc'] = $encrypted;
            $data['last_getme_at'] = '';
            $data['last_getme_name'] = '';
            $data['last_getme_username'] = '';
        }

        $program_token_input = isset($_POST['eitaa_program_token']) ? trim((string)wp_unslash($_POST['eitaa_program_token'])) : '';
        if ('' !== $program_token_input) {
            $valid = self::validate_program_token($program_token_input);
            if (is_wp_error($valid)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $valid->get_error_message(), 'error'); }
            $encrypted = self::encrypt_secret($program_token_input);
            if (is_wp_error($encrypted)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $encrypted->get_error_message(), 'error'); }
            $data['program_token_enc'] = $encrypted;
            delete_option('hamnoor_eitaa_bridge_last_probe');
            delete_option('hamnoor_eitaa_bridge_last_status');
        }

        update_option(self::OPTION, $data, false);
        Hamnoor_Notifications::reset_channels_cache();
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('تنظیمات ایتا ذخیره شد.', 'hamnoor'));
    }

    public static function admin_test_connection() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_eitaa_test_connection');
        if (!self::public_configured()) {
            Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('ابتدا توکن عمومی API ایتایار را ذخیره کنید.', 'hamnoor'), 'error');
        }
        $result = self::public_api_call('getMe');
        if (is_wp_error($result)) {
            Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('اتصال API عمومی ایتایار ناموفق بود: ', 'hamnoor') . $result->get_error_message(), 'error');
        }
        $item = isset($result['result']) && is_array($result['result']) ? $result['result'] : array();
        $s = self::settings();
        $s['last_getme_at'] = current_time('mysql');
        $s['last_getme_name'] = sanitize_text_field(trim((string)($item['first_name'] ?? '') . ' ' . (string)($item['last_name'] ?? '')));
        $s['last_getme_username'] = sanitize_text_field((string)($item['username'] ?? ''));
        update_option(self::OPTION, $s, false);
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('اتصال به API عمومی ایتایار موفق بود.', 'hamnoor'));
    }

    public static function admin_test_message() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_eitaa_test_message');
        $chat_id = isset($_POST['eitaa_test_chat_id']) ? self::sanitize_chat_id(wp_unslash($_POST['eitaa_test_chat_id'])) : self::test_chat_id();
        if (!$chat_id) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('برای تست ارسال عمومی، شناسه مقصد ایتا را وارد کنید.', 'hamnoor'), 'error'); }
        if (!self::public_configured()) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('ابتدا توکن عمومی API ایتایار را ذخیره کنید.', 'hamnoor'), 'error'); }
        $message = sprintf("✅ تست اتصال هم‌نور به ایتا\n\nاین پیام از سایت %s و افزونه مستقل ایتا نسخه %s ارسال شده است.\nزمان: %s", home_url('/'), HAMNOOR_EITAA_VERSION, current_time('Y-m-d H:i:s'));
        $result = self::public_api_call('sendMessage', array('chat_id'=>$chat_id, 'text'=>$message));
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('ارسال آزمایشی عمومی ایتا ناموفق بود: ', 'hamnoor') . $result->get_error_message(), 'error'); }
        $s = self::settings();
        $s['test_chat_id'] = $chat_id;
        $s['last_test_at'] = current_time('mysql');
        update_option(self::OPTION, $s, false);
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('پیام آزمایشی عمومی با موفقیت به ایتا تحویل API شد.', 'hamnoor'));
    }

    /** Public EitaaYar API for channels/groups. */
    public static function public_api_call($method, $params = array()) {
        $token = self::public_token();
        if (!$token) { return new WP_Error('hamnoor_eitaa_missing_public_token', __('توکن عمومی API ایتایار تنظیم نشده است.', 'hamnoor')); }
        $method = preg_replace('/[^A-Za-z0-9_]/', '', (string)$method);
        if (!$method) { return new WP_Error('hamnoor_eitaa_bad_method', __('متد API ایتا معتبر نیست.', 'hamnoor')); }
        $token_path = rawurlencode($token);
        $token_path = str_replace('%3A', ':', $token_path);
        $url = self::PUBLIC_API_BASE . $token_path . '/' . $method;
        return self::remote_json($url, $params, 'public', empty($params) ? 'GET' : 'POST');
    }

    /**
     * Official Eitaa Program API. Only documented app methods are accepted.
     * Token is posted as a body field according to developer.eitaa.com.
     */
    public static function program_api_call($method, $params = array()) {
        $method = preg_replace('/[^A-Za-z0-9_]/', '', (string)$method);
        if (!in_array($method, array('sendMessage', 'verify'), true)) {
            return new WP_Error('hamnoor_eitaa_app_method_unsupported', __('این متد در API رسمی برنامه ایتا برای هم‌نور پشتیبانی نشده است.', 'hamnoor'));
        }
        $token = self::program_token();
        if (!$token) { return new WP_Error('hamnoor_eitaa_missing_program_token', __('توکن اختصاصی برنامه ایتا تنظیم نشده است.', 'hamnoor')); }
        $body = is_array($params) ? $params : array();
        $body['token'] = $token;
        return self::remote_json(self::APP_API_BASE . $method, $body, 'program', 'POST');
    }

    public static function api_call($method, $params = array()) { return self::program_api_call($method, $params); }

    public static function app_send_message($chat_id, $text) {
        $chat_id = self::sanitize_chat_id($chat_id);
        if (!$chat_id || !preg_match('/^[0-9]{1,20}$/', $chat_id)) {
            return new WP_Error('hamnoor_eitaa_bad_chat_id', __('شناسه کاربر ایتا معتبر نیست.', 'hamnoor'));
        }
        $text = trim(wp_strip_all_tags((string)$text));
        if ('' === $text) { return new WP_Error('hamnoor_eitaa_empty_message', __('متن پیام ایتا خالی است.', 'hamnoor')); }
        $result = self::program_api_call('sendMessage', array('chat_id'=>$chat_id, 'text'=>Hamnoor_Validator::trim_len($text, 3900)));
        if (!is_wp_error($result)) {
            $s = self::settings();
            $s['last_app_send_at'] = current_time('mysql');
            update_option(self::OPTION, $s, false);
        }
        return $result;
    }

    public static function app_verify_hash($hash, $ip = '') {
        $hash = strtolower(trim((string)$hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) { return new WP_Error('hamnoor_eitaa_bad_hash', __('هش ایتا معتبر نیست.', 'hamnoor')); }
        $params = array('hash'=>$hash);
        if ($ip) { $params['ip'] = sanitize_text_field((string)$ip); }
        return self::program_api_call('verify', $params);
    }

    /**
     * Validate Eitaa initData/contact response using the documented HMAC flow.
     * Returns decoded fields only after signature and freshness checks pass.
     */
    public static function validate_signed_data($raw, $max_age = 900, $require_auth_date = true) {
        $raw = is_string($raw) ? $raw : '';
        if ('' === $raw || strlen($raw) > 65535) { return new WP_Error('hamnoor_eitaa_data_missing', __('داده امضاشده ایتا دریافت نشد.', 'hamnoor')); }
        $token = self::program_token();
        if (!$token) { return new WP_Error('hamnoor_eitaa_missing_program_token', __('توکن اختصاصی برنامه ایتا تنظیم نشده است.', 'hamnoor')); }

        $params = array();
        wp_parse_str($raw, $params);
        if (!is_array($params) || empty($params['hash'])) { return new WP_Error('hamnoor_eitaa_hash_missing', __('امضای داده ایتا پیدا نشد.', 'hamnoor')); }
        $received_hash = strtolower(trim((string)$params['hash']));
        if (!preg_match('/^[a-f0-9]{64}$/', $received_hash)) { return new WP_Error('hamnoor_eitaa_hash_invalid', __('ساختار امضای ایتا معتبر نیست.', 'hamnoor')); }

        unset($params['hash']);
        ksort($params, SORT_STRING);
        $pairs = array();
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) { return new WP_Error('hamnoor_eitaa_nested_init', __('ساختار داده ایتا قابل اعتبارسنجی نیست.', 'hamnoor')); }
            $pairs[] = (string)$key . '=' . (string)$value;
        }
        $data_check = implode("\n", $pairs);
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $data_check, $secret, false);
        if (!hash_equals($calculated, $received_hash)) { return new WP_Error('hamnoor_eitaa_signature_invalid', __('امضای اطلاعات ایتا معتبر نیست.', 'hamnoor')); }

        $age = null;
        if (isset($params['auth_date']) && ctype_digit((string)$params['auth_date'])) {
            $age = time() - (int)$params['auth_date'];
            if ($age < -60 || ($max_age > 0 && $age > (int)$max_age)) { return new WP_Error('hamnoor_eitaa_data_stale', __('اطلاعات ایتا منقضی شده است؛ برنامک را دوباره باز کنید.', 'hamnoor')); }
        } elseif ($require_auth_date) {
            return new WP_Error('hamnoor_eitaa_auth_date_missing', __('زمان اعتبار اطلاعات ایتا پیدا نشد.', 'hamnoor'));
        }

        return array('params'=>$params, 'hash'=>$received_hash, 'auth_age_seconds'=>$age);
    }

    public static function validate_init_data($raw, $max_age = 900) {
        $validated = self::validate_signed_data($raw, $max_age, true);
        if (is_wp_error($validated)) { return $validated; }
        $params = $validated['params'];
        if (empty($params['user'])) { return new WP_Error('hamnoor_eitaa_user_missing', __('اطلاعات کاربر ایتا در initData وجود ندارد.', 'hamnoor')); }
        $user = json_decode((string)$params['user'], true);
        if (!is_array($user) || !isset($user['id'])) { return new WP_Error('hamnoor_eitaa_user_invalid', __('اطلاعات کاربر ایتا معتبر نیست.', 'hamnoor')); }
        $id = self::sanitize_chat_id($user['id']);
        if (!$id || !preg_match('/^[0-9]{1,20}$/', $id)) { return new WP_Error('hamnoor_eitaa_user_id_invalid', __('شناسه کاربر ایتا معتبر نیست.', 'hamnoor')); }
        $validated['user'] = $user;
        $validated['user']['id'] = $id;
        return $validated;
    }

    public static function validate_contact_data($raw, $expected_eitaa_id = '') {
        // Eitaa docs say requestContact response can be verified like initData.
        // Some SDK versions may omit auth_date, so freshness is additionally
        // enforced by the short-lived server-side bridge token.
        $validated = self::validate_signed_data($raw, 900, false);
        if (is_wp_error($validated)) { return $validated; }
        $phone = self::extract_phone($validated['params']);
        if (!$phone) { return new WP_Error('hamnoor_eitaa_contact_phone_missing', __('شماره موبایل در پاسخ تأییدشده ایتا پیدا نشد.', 'hamnoor')); }
        $expected_eitaa_id = self::sanitize_chat_id($expected_eitaa_id);
        $contact_id = self::extract_contact_user_id($validated['params']);
        if ($expected_eitaa_id && $contact_id && !hash_equals($expected_eitaa_id, $contact_id)) {
            return new WP_Error('hamnoor_eitaa_contact_owner_mismatch', __('شماره دریافت‌شده متعلق به حساب ایتای دیگری است.', 'hamnoor'));
        }
        $validated['phone'] = $phone;
        $validated['contact_user_id'] = $contact_id;
        return $validated;
    }

    private static function extract_phone($value, $depth = 0) {
        if ($depth > 4) { return ''; }
        if (is_array($value)) {
            foreach (array('phone_number','phone','mobile','mobile_number') as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    $phone = Hamnoor_Validator::normalize_phone((string)$value[$key]);
                    if (Hamnoor_Validator::valid_iran_mobile($phone)) { return $phone; }
                }
            }
            foreach ($value as $item) {
                $phone = self::extract_phone($item, $depth + 1);
                if ($phone) { return $phone; }
            }
            return '';
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) { return self::extract_phone($decoded, $depth + 1); }
            }
            $phone = Hamnoor_Validator::normalize_phone($trim);
            return Hamnoor_Validator::valid_iran_mobile($phone) ? $phone : '';
        }
        return '';
    }

    private static function extract_contact_user_id($value, $depth = 0) {
        if ($depth > 4) { return ''; }
        if (is_array($value)) {
            foreach (array('user_id','eitaa_id') as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    $id = self::sanitize_chat_id($value[$key]);
                    if (preg_match('/^[0-9]{1,20}$/', $id)) { return $id; }
                }
            }
            if (isset($value['user'])) {
                $user = is_string($value['user']) ? json_decode($value['user'], true) : $value['user'];
                if (is_array($user) && isset($user['id'])) {
                    $id = self::sanitize_chat_id($user['id']);
                    if (preg_match('/^[0-9]{1,20}$/', $id)) { return $id; }
                }
            }
            foreach ($value as $item) {
                $id = self::extract_contact_user_id($item, $depth + 1);
                if ($id) { return $id; }
            }
        } elseif (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) { return self::extract_contact_user_id($decoded, $depth + 1); }
            }
        }
        return '';
    }

    private static function remote_json($url, $body, $scope, $method = 'POST') {
        $args = array(
            'timeout'=>20,
            'redirection'=>2,
            'headers'=>array('Accept'=>'application/json'),
            'user-agent'=>'Hamnoor-Eitaa/' . HAMNOOR_EITAA_VERSION . '; Core/' . HAMNOOR_VERSION . '; ' . home_url('/'),
        );
        $response = ('GET' === strtoupper($method)) ? wp_remote_get($url, $args) : wp_remote_post($url, array_merge($args, array('body'=>$body)));
        if (is_wp_error($response)) { return new WP_Error('hamnoor_eitaa_http', $response->get_error_message(), array('scope'=>$scope)); }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) { return new WP_Error('hamnoor_eitaa_http_status', sprintf(__('پاسخ HTTP %d از API ایتا دریافت شد.', 'hamnoor'), $code), array('http_code'=>$code,'scope'=>$scope)); }
        if (!is_array($json)) { return new WP_Error('hamnoor_eitaa_invalid_json', __('پاسخ API ایتا JSON معتبر نیست.', 'hamnoor'), array('http_code'=>$code,'scope'=>$scope)); }
        if (isset($json['ok']) && !$json['ok']) {
            $description = '';
            foreach (array('description','message','error') as $key) { if (!empty($json[$key]) && is_scalar($json[$key])) { $description = sanitize_text_field((string)$json[$key]); break; } }
            return new WP_Error('hamnoor_eitaa_api_error', $description ?: __('API ایتا درخواست را نپذیرفت.', 'hamnoor'), array('http_code'=>$code,'scope'=>$scope));
        }
        return $json;
    }

    public static function render_admin_section() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $status = get_option('hamnoor_eitaa_bridge_last_status', array());
        $status = is_array($status) ? $status : array();

        echo '<hr><h2>' . esc_html__('ایتا — اتصال رسمی برنامه و برنامک', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('هم‌نور برای کاربران از SDK رسمی ایتا، اعتبارسنجی HMAC روی initData و API رسمی برنامه در مسیر /api/app/sendMessage استفاده می‌کند. API عمومی ایتایار فقط برای کانال/گروه نگه داشته شده است.', 'hamnoor') . '</p>';

        echo '<table class="widefat striped" style="max-width:950px"><tbody>';
        echo '<tr><th>' . esc_html__('توکن برنامه', 'hamnoor') . '</th><td><strong>' . esc_html(self::program_configured() ? __('ذخیره شده', 'hamnoor') : __('تنظیم نشده', 'hamnoor')) . '</strong></td></tr>';
        $direct_link = self::miniapp_url();
        echo '<tr><th>' . esc_html__('پیوند رسمی برنامک', 'hamnoor') . '</th><td><strong>' . esc_html($direct_link ? __('تنظیم شده', 'hamnoor') : __('تنظیم نشده', 'hamnoor')) . '</strong>';
        if ($direct_link) { echo ' — <a href="' . esc_url($direct_link) . '" target="_blank" rel="noopener">' . esc_html__('بازکردن برنامک', 'hamnoor') . '</a>'; }
        else { echo '<br><span style="color:#b32d2e">' . esc_html__('تا وقتی این پیوند ثبت نشود، اعلان ایتا به لینک وب هم‌نور برمی‌گردد تا لینک نادرست ایتا ساخته نشود.', 'hamnoor') . '</span>'; }
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('کاربران متصل و دارای اجازه پیام', 'hamnoor') . '</th><td><strong>' . (int)self::connected_users_count() . '</strong></td></tr>';
        $consent = Hamnoor_Notification_Dispatcher::channel_consent_counts('eitaa');
        echo '<tr><th>' . esc_html__('کاربران منصرف‌شده', 'hamnoor') . '</th><td><strong>' . (int)$consent['unsubscribed'] . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__('آخرین ارسال از API برنامه', 'hamnoor') . '</th><td>' . esc_html(!empty($s['last_app_send_at']) ? $s['last_app_send_at'] : __('هنوز ارسال موفق ثبت نشده', 'hamnoor')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('آخرین اعتبارسنجی برنامک', 'hamnoor') . '</th><td>' . esc_html(!empty($status['validated_at']) ? $status['validated_at'] : __('هنوز اجرا نشده', 'hamnoor')) . '</td></tr>';
        if (!empty($status)) {
            echo '<tr><th>' . esc_html__('وضعیت initData', 'hamnoor') . '</th><td><strong>' . esc_html(!empty($status['valid']) ? __('معتبر', 'hamnoor') : __('نامعتبر', 'hamnoor')) . '</strong></td></tr>';
            if (isset($status['allows_write_to_pm'])) { echo '<tr><th>' . esc_html__('اجازه ارسال پیام در آخرین اجرا', 'hamnoor') . '</th><td>' . esc_html($status['allows_write_to_pm'] ? __('دارد', 'hamnoor') : __('ندارد', 'hamnoor')) . '</td></tr>'; }
            if (!empty($status['error'])) { echo '<tr><th>' . esc_html__('آخرین خطا', 'hamnoor') . '</th><td><code>' . esc_html($status['error']) . '</code></td></tr>'; }
        }
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:18px;max-width:950px">';
        wp_nonce_field('hamnoor_eitaa_save');
        echo '<input type="hidden" name="action" value="hamnoor_eitaa_save">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('فعال‌سازی ایتا', 'hamnoor') . '</th><td><label><input type="checkbox" name="eitaa_enabled" value="1" ' . checked('1', (string)$s['enabled'], false) . '> ' . esc_html__('ارسال اعلان‌های هم‌نور از طریق ایتا فعال باشد', 'hamnoor') . '</label></td></tr>';
        echo '<tr><th><label for="eitaa_program_token">' . esc_html__('توکن اختصاصی برنامه ایتا', 'hamnoor') . '</label></th><td><input id="eitaa_program_token" name="eitaa_program_token" type="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr(self::program_configured() ? __('برای حفظ توکن فعلی خالی بگذارید', 'hamnoor') : __('توکن برنامه', 'hamnoor')) . '"><p class="description">' . esc_html__('این توکن برای اعتبارسنجی initData و ارسال خصوصی از API رسمی برنامه استفاده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('آدرس تارنما / مقصد', 'hamnoor') . '</th><td><input type="url" class="regular-text code" dir="ltr" value="' . esc_attr(Hamnoor_Eitaa_Bridge::url()) . '" readonly><p class="description">' . esc_html__('این آدرس را در فیلد «آدرس تارنما»ی برنامک در پنل ایتایار ثبت کنید. این آدرس برای کلیک اعلان نیست.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="eitaa_miniapp_direct_url">' . esc_html__('پیوند برنامک ایتا', 'hamnoor') . '</label></th><td><input id="eitaa_miniapp_direct_url" name="eitaa_miniapp_direct_url" type="url" class="regular-text code" dir="ltr" value="' . esc_attr((string)($s['miniapp_direct_url'] ?? '')) . '" placeholder="https://eitaa.com/.../..."><p class="description"><strong>' . esc_html__('مهم:', 'hamnoor') . '</strong> ' . esc_html__('این مقدار را از «پیوند برنامک» در صفحه ویرایش برنامک پنل ایتایار عیناً کپی کنید. هم‌نور startapp اعلان را فقط به همین پیوند اضافه می‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="eitaa_program_username">' . esc_html__('نام کاربری برنامه', 'hamnoor') . '</label></th><td><input id="eitaa_program_username" name="eitaa_program_username" type="text" class="regular-text" value="' . esc_attr((string)$s['program_username']) . '"><p class="description">' . esc_html__('فقط برای شناسایی/مدیریت اتصال نگه‌داری می‌شود و مبنای ساخت لینک اعلان نیست.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="eitaa_applet_shortname">' . esc_html__('نام کوتاه برنامک (اختیاری)', 'hamnoor') . '</label></th><td><input id="eitaa_applet_shortname" name="eitaa_applet_shortname" type="text" class="regular-text" value="' . esc_attr((string)$s['applet_shortname']) . '" placeholder="hamnoor"><p class="description">' . esc_html__('اینجا فقط نام کوتاه وارد می‌شود، نه آدرس https://. اگر URL کامل را اشتباهاً اینجا بچسبانید، هم‌نور آن را به فیلد آدرس برنامک منتقل می‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="eitaa_public_token">' . esc_html__('توکن عمومی ایتایار (اختیاری)', 'hamnoor') . '</label></th><td><input id="eitaa_public_token" name="eitaa_public_token" type="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr(self::public_configured() ? __('برای حفظ توکن فعلی خالی بگذارید', 'hamnoor') : __('فقط برای API عمومی کانال/گروه', 'hamnoor')) . '"></td></tr>';
        echo '<tr><th><label for="eitaa_test_chat_id">' . esc_html__('شناسه دریافت‌کننده بررسی', 'hamnoor') . '</label></th><td><input id="eitaa_test_chat_id" name="eitaa_test_chat_id" type="text" class="regular-text" value="' . esc_attr((string)$s['test_chat_id']) . '"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('ذخیره تنظیمات ایتا', 'hamnoor'));
        echo '</form>';

        if (self::public_configured()) {
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_eitaa_test_connection'); echo '<input type="hidden" name="action" value="hamnoor_eitaa_test_connection">'; submit_button(__('بررسی اتصال ایتا', 'hamnoor'), 'secondary', 'submit', false); echo '</form>';
            if (self::test_chat_id()) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_eitaa_test_message'); echo '<input type="hidden" name="action" value="hamnoor_eitaa_test_message"><input type="hidden" name="eitaa_test_chat_id" value="' . esc_attr(self::test_chat_id()) . '">'; submit_button(__('ارسال پیام بررسی', 'hamnoor'), 'secondary', 'submit', false); echo '</form>'; }
            echo '</div>';
        }

        echo '<p class="description" style="margin-top:14px">' . esc_html__('برای بررسی اتصال خصوصی برنامه، هم‌نور را از داخل ایتا باز کنید. پس از تأیید دسترسی، یک پیام تأیید برای کاربر ارسال می‌شود.', 'hamnoor') . '</p>';
    }

    /** Return the bound owner only when the messenger phone still matches the current Hamnoor phone. */
    public static function get_user_id_by_eitaa($chat_id) {
        global $wpdb;
        $chat_id = self::sanitize_chat_id($chat_id);
        if (!$chat_id) { return 0; }
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,channel,status,external_id,metadata FROM $table WHERE channel='eitaa' AND external_id=%s ORDER BY id DESC LIMIT 1",
            $chat_id
        ));
        if (!$row || 'needs_reconnect' === (string)$row->status || 'disconnected' === (string)$row->status) { return 0; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'eitaa', $row)) { return 0; }
        return (int)$row->user_id;
    }

    /** Return the historical owner even when the phone binding is stale; used only to reconnect the same identity safely. */
    public static function get_bound_user_id_by_eitaa($chat_id) {
        global $wpdb;
        $chat_id = self::sanitize_chat_id($chat_id);
        if (!$chat_id) { return 0; }
        $table = Hamnoor_DB::table('notification_channels');
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $table WHERE channel='eitaa' AND external_id=%s ORDER BY id DESC LIMIT 1",
            $chat_id
        ));
    }

    public static function verified_phone_hash_for_user($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT metadata FROM $table WHERE user_id=%d AND channel='eitaa' ORDER BY id DESC LIMIT 1", (int)$user_id));
        if (!$row) { return ''; }
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $hash = strtolower(trim((string)($meta['verified_phone_hash'] ?? '')));
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    public static function set_write_access($user_id, $allowed, $explicit = false) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND channel='eitaa' LIMIT 1", (int)$user_id));
        if (!$row) { return false; }
        $meta = json_decode((string)$row->metadata, true); if (!is_array($meta)) { $meta = array(); }
        if ($allowed && !$explicit && !empty($meta['user_unsubscribed'])) { return true; }
        if ($allowed && $explicit) { unset($meta['user_unsubscribed'], $meta['unsubscribed_at'], $meta['unsubscribed_by']); }
        $meta['write_access'] = (bool)$allowed;
        $meta['write_access_checked_at'] = current_time('mysql');
        return false !== $wpdb->update($table, array(
            'enabled'=>$allowed ? 1 : 0,
            'status'=>$allowed ? 'connected' : 'permission_required',
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
    }

    public static function unsubscribe_user($user_id, $source = 'user') {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,metadata FROM $table WHERE user_id=%d AND channel='eitaa' LIMIT 1", (int)$user_id));
        if (!$row) { return false; }
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $meta['user_unsubscribed'] = 1;
        $meta['unsubscribed_at'] = current_time('mysql');
        $meta['unsubscribed_by'] = sanitize_key((string)$source);
        $meta['write_access'] = false;
        $ok = $wpdb->update($table, array(
            'enabled'=>0, 'status'=>'disabled',
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
        Hamnoor_Notifications::reset_channels_cache();
        return false !== $ok;
    }

    public static function connect_user($user_id, $chat_id, $metadata = array()) {
        global $wpdb;
        $user_id = (int)$user_id;
        $chat_id = self::sanitize_chat_id($chat_id);
        if (!$user_id || !$chat_id || !preg_match('/^[0-9]{1,20}$/', $chat_id)) { return new WP_Error('hamnoor_eitaa_bad_user_id', __('شناسه کاربر ایتا برای اتصال معتبر نیست.', 'hamnoor')); }
        $table = Hamnoor_DB::table('notification_channels');
        $other_owner = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE channel='eitaa' AND external_id=%s AND user_id<>%d LIMIT 1", $chat_id, $user_id));
        if ($other_owner) { return new WP_Error('hamnoor_eitaa_already_linked', __('این حساب ایتا قبلاً به پنل دیگری در هم‌نور متصل شده است.', 'hamnoor')); }
        $now = current_time('mysql');
        $meta = is_array($metadata) ? $metadata : array();
        $write_access = !empty($meta['write_access']);
        $meta['chat_id'] = $chat_id;
        $meta['verification'] = 'official_initdata_and_contact';
        $meta['verified_at'] = $now;
        $meta['source'] = 'eitaa_official_miniapp';
        if (!empty($meta['verified_phone_hash'])) {
            $meta['phone_binding_status'] = 'matched';
            unset($meta['phone_binding_reason'], $meta['phone_binding_invalidated_at']);
        }
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND channel='eitaa' LIMIT 1", $user_id));
        $data = array(
            'user_id'=>$user_id,
            'channel'=>'eitaa',
            'enabled'=>$write_access ? 1 : 0,
            'status'=>$write_access ? 'connected' : 'permission_required',
            'external_id'=>$chat_id,
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'connected_at'=>$now,
            'updated_at'=>$now,
        );
        if ($existing) {
            $ok = $wpdb->update($table, $data, array('id'=>$existing), array('%d','%s','%d','%s','%s','%s','%s','%s'), array('%d'));
            if (false === $ok) { return new WP_Error('hamnoor_eitaa_link_db', __('ذخیره اتصال ایتا انجام نشد.', 'hamnoor')); }
        } else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($table, $data, array('%d','%s','%d','%s','%s','%s','%s','%s','%s'));
            if (!$ok) { return new WP_Error('hamnoor_eitaa_link_db', __('ذخیره اتصال ایتا انجام نشد.', 'hamnoor')); }
        }
        Hamnoor_Notifications::reset_channels_cache();
        return true;
    }

    public static function connected_users_count() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return 0; }
        $rows = $wpdb->get_results("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE channel='eitaa' AND enabled=1 AND status='connected' AND external_id IS NOT NULL AND external_id<>''");
        if (!function_exists('hamnoor_notifications_channel_identity_valid')) { return count((array)$rows); }
        $count = 0;
        foreach ((array)$rows as $row) {
            if (hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'eitaa', $row)) { $count++; }
        }
        return $count;
    }

    private static function user_chat_id($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table || !$user_id) { return ''; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT user_id,channel,external_id,metadata,status FROM $table WHERE user_id=%d AND channel='eitaa' AND enabled=1 AND status='connected' LIMIT 1", (int)$user_id));
        if (!$row) { return ''; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$user_id, 'eitaa', $row)) { return ''; }
        return self::sanitize_chat_id((string)$row->external_id);
    }

    /**
     * Return the exact Mini App deep link issued by EitaaYar, optionally with
     * startapp data. Do not synthesize this URL from program/applet names: the
     * Eitaa management panel is the source of truth for the direct link.
     */
    public static function miniapp_url($start_param = '') {
        $s = self::settings();
        $url = self::sanitize_miniapp_direct_url((string)($s['miniapp_direct_url'] ?? ''));
        if (!$url) { return ''; }
        $start_param = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$start_param);
        if ($start_param !== '') { $url = add_query_arg('startapp', $start_param, $url); }
        return esc_url_raw($url);
    }

    private static function sanitize_miniapp_direct_url($url) {
        $url = trim((string)$url);
        if ('' === $url) { return ''; }
        $url = esc_url_raw($url, array('https'));
        if (!$url) { return ''; }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower((string)($parts['scheme'] ?? ''))) { return ''; }
        $host = strtolower((string)($parts['host'] ?? ''));
        // Eitaa's management panel issues the Mini App direct link. Our own
        // WordPress bridge URL is only the Web App destination and must never
        // be used as the notification deep link.
        if (!in_array($host, array('eitaa.com', 'www.eitaa.com'), true)) { return ''; }
        $path = trim((string)($parts['path'] ?? ''), '/');
        if ('' === $path) { return ''; }
        // startapp is generated per notification; never persist a stale value.
        return esc_url_raw(remove_query_arg(array('startapp', 'tgWebAppStartParam'), $url));
    }

    private static function notification_text($notification, $context = array()) {
        $title = !empty($notification->title) ? wp_strip_all_tags((string)$notification->title) : __('یادآوری هم‌نور', 'hamnoor');
        $body = !empty($notification->message) ? wp_strip_all_tags((string)$notification->message) : '';

        // Admin broadcasts are editorial messages, not Hamnoor navigation
        // prompts. Never append the Mini App/dashboard URL. If the admin
        // supplied a URL, show only that exact HTTP(S) destination in text.
        if (!empty($notification->type) && 'admin_broadcast' === (string)$notification->type) {
            $custom_url = !empty($context['url']) ? esc_url_raw((string)$context['url'], array('http','https')) : '';
            if ($custom_url && !wp_http_validate_url($custom_url)) { $custom_url = ''; }
            $parts = array('📣 ' . $title);
            if ($body) { $parts[] = $body; }
            if ($custom_url) { $parts[] = $custom_url; }
            return implode("\n\n", $parts);
        }

        $url = '';
        if (!empty($notification->related_type) && 'part' === (string)$notification->related_type && !empty($notification->related_id)) {
            $url = self::miniapp_url('part_' . (int)$notification->related_id);
        }
        if (!$url) { $url = self::miniapp_url('dashboard'); }
        if (!$url) { $url = !empty($context['url']) ? esc_url_raw((string)$context['url']) : Hamnoor_Helpers::page_url('dashboard'); }
        $parts = array('📖 ' . $title);
        if ($body) { $parts[] = $body; }
        // Eitaa App sendMessage officially supports Markdown links. Keep the
        // full URL out of the visible notification without changing its target.
        if ($url) { $parts[] = '[' . __('ادامه در هم‌نور', 'hamnoor') . '](' . str_replace(array('(', ')'), array('%28', '%29'), $url) . ')'; }
        return implode("\n\n", $parts);
    }

    private static function sanitize_chat_id($value) {
        $value = Hamnoor_Validator::normalize_digits(trim((string)$value));
        $value = preg_replace('/[^0-9]/', '', $value);
        return substr($value, 0, 20);
    }

    private static function validate_public_token($token) {
        $token = trim((string)$token);
        if (strlen($token) < 10 || strlen($token) > 255 || !preg_match('/^[A-Za-z0-9:_\-]+$/', $token)) { return new WP_Error('hamnoor_eitaa_bad_public_token', __('ساختار توکن عمومی ایتایار معتبر به نظر نمی‌رسد.', 'hamnoor')); }
        return true;
    }

    private static function validate_program_token($token) {
        $token = (string)$token; $len = strlen($token);
        if ($len < 16 || $len > 2048) { return new WP_Error('hamnoor_eitaa_bad_program_token_length', __('طول توکن اختصاصی برنامه ایتا معتبر به نظر نمی‌رسد.', 'hamnoor')); }
        if (preg_match('/[\x00-\x20\x7F]/', $token)) { return new WP_Error('hamnoor_eitaa_bad_program_token_chars', __('توکن اختصاصی برنامه ایتا نباید فاصله، خط جدید یا کاراکتر کنترلی داشته باشد.', 'hamnoor')); }
        return true;
    }

    private static function encryption_key() {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . '|hamnoor-eitaa';
        return hash('sha256', $material, true);
    }

    private static function encrypt_secret($plain) {
        if (!function_exists('openssl_encrypt')) { return new WP_Error('hamnoor_eitaa_no_openssl', __('OpenSSL برای ذخیره امن توکن ایتا روی این هاست در دسترس نیست.', 'hamnoor')); }
        try { $iv = random_bytes(12); } catch (Throwable $e) { return new WP_Error('hamnoor_eitaa_random', __('تولید مقدار تصادفی امن برای توکن ایتا ناموفق بود.', 'hamnoor')); }
        $tag = '';
        $cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'hamnoor-eitaa-v1', 16);
        if (false === $cipher || 16 !== strlen($tag)) { return new WP_Error('hamnoor_eitaa_encrypt', __('رمزگذاری توکن ایتا ناموفق بود.', 'hamnoor')); }
        return 'v1:' . self::b64url($iv . $tag . $cipher);
    }

    private static function decrypt_secret($stored) {
        $stored = (string)$stored;
        if (0 !== strpos($stored, 'v1:') || !function_exists('openssl_decrypt')) { return ''; }
        $raw = self::b64url_decode(substr($stored, 3));
        if (false === $raw || strlen($raw) < 29) { return ''; }
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'hamnoor-eitaa-v1');
        return false === $plain ? '' : (string)$plain;
    }

    private static function b64url($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    private static function b64url_decode($data) { $data = strtr((string)$data, '-_', '+/'); $pad = strlen($data) % 4; if ($pad) { $data .= str_repeat('=', 4 - $pad); } return base64_decode($data, true); }
    private static function admin_url() { return add_query_arg('channel', 'eitaa', admin_url('admin.php?page=hamnoor-notifications')); }
}
