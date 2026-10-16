<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/class-hamnoor-p256-keygen.php';

/**
 * Native Web Push channel (RFC 8291/8292) with no external PHP dependency.
 */
class Hamnoor_Web_Push implements Hamnoor_Notification_Channel_Interface {
    private static $instance = null;
    const SW_QUERY_VAR = 'hamnoor_sw';
    const SW_PATH = 'hamnoor-sw.js';

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function init() {
        $self = self::instance();
        Hamnoor_Notification_Channel_Registry::register('web_push', $self);
        add_action('hamnoor_enqueue_frontend_integrations', array($self, 'enqueue_frontend_assets'));
        add_action('init', array($self, 'register_rewrite_rule'), 2);
        add_filter('query_vars', array($self, 'query_vars'));
        add_action('template_redirect', array($self, 'maybe_serve_service_worker'), 0);
        add_action('init', array($self, 'maybe_flush_rewrite'), 99);

        foreach (array('status','subscribe','unsubscribe','test') as $action) {
            add_action('wp_ajax_hamnoor_webpush_' . $action, array($self, 'ajax_' . $action));
            add_action('wp_ajax_nopriv_hamnoor_webpush_' . $action, array($self, 'ajax_' . $action));
        }
    }

    public function key() { return 'web_push'; }

    public function enqueue_frontend_assets($context = array()) {
        $context = is_array($context) ? $context : array();
        if (empty($context['mobile_user_id'])) { return; }
        $frontend_handle = !empty($context['frontend_handle']) ? sanitize_key($context['frontend_handle']) : 'hamnoor-frontend';
        wp_enqueue_script(
            'hamnoor-web-push',
            HAMNOOR_WEB_PUSH_URL . 'assets/js/hamnoor-web-push.js',
            array($frontend_handle),
            HAMNOOR_WEB_PUSH_VERSION,
            true
        );
        wp_localize_script('hamnoor-web-push', 'HamnoorWebPush', array(
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'=>!empty($context['nonce']) ? (string)$context['nonce'] : wp_create_nonce('hamnoor_frontend'),
            'public_key'=>self::public_key(),
            'service_worker_url'=>self::service_worker_url(),
            'texts'=>array(
                'active'=>__('Web Push فعال است', 'hamnoor'),
                'inactive'=>__('Web Push غیرفعال است', 'hamnoor'),
                'unsupported'=>__('مرورگر شما Web Push را پشتیبانی نمی‌کند.', 'hamnoor'),
                'permission_denied'=>__('مجوز اعلان مرورگر داده نشد. از تنظیمات مرورگر اجازه اعلان را فعال کنید.', 'hamnoor'),
                'connecting'=>__('در حال فعال‌سازی اعلان مرورگر...', 'hamnoor'),
                'reconnecting'=>__('اشتراک قبلی با کلید فعلی هم‌نور هماهنگ نیست؛ در حال ساخت اشتراک تازه...', 'hamnoor'),
                'stale'=>__('Web Push نیاز به فعال‌سازی مجدد دارد', 'hamnoor'),
                'reconnect_required'=>__('اشتراک مرورگر با کلید فعلی Web Push هماهنگ نیست. دکمه «فعال‌سازی Web Push» را بزنید تا اشتراک تازه ساخته شود.', 'hamnoor'),
                'disconnecting'=>__('در حال غیرفعال‌سازی...', 'hamnoor'),
                'testing'=>__('در حال ارسال اعلان آزمایشی...', 'hamnoor'),
                'sent'=>__('اعلان آزمایشی ارسال شد.', 'hamnoor'),
                'failed'=>__('عملیات Web Push انجام نشد.', 'hamnoor'),
            ),
        ));
    }

    public function is_available() {
        return is_ssl() && extension_loaded('openssl') && function_exists('openssl_pkey_get_private') && function_exists('openssl_pkey_get_details') && function_exists('openssl_pkey_derive') && function_exists('random_bytes');
    }

    public function is_connected($user_id) {
        return self::active_subscription_count((int)$user_id) > 0;
    }

    public static function service_worker_url() {
        return home_url('/' . self::SW_PATH);
    }

    public function register_rewrite_rule() {
        add_rewrite_rule('^' . preg_quote(self::SW_PATH, '#') . '$', 'index.php?' . self::SW_QUERY_VAR . '=1', 'top');
    }

    public function query_vars($vars) {
        $vars[] = self::SW_QUERY_VAR;
        return $vars;
    }

    public function maybe_flush_rewrite() {
        if ((string)get_option('hamnoor_webpush_rewrite_version', '') === HAMNOOR_WEB_PUSH_VERSION) { return; }
        flush_rewrite_rules(false);
        update_option('hamnoor_webpush_rewrite_version', HAMNOOR_WEB_PUSH_VERSION, false);
    }

    public function maybe_serve_service_worker() {
        if ('1' !== (string)get_query_var(self::SW_QUERY_VAR)) { return; }
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('X-Content-Type-Options: nosniff');
        echo self::service_worker_source(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function service_worker_source() {
        return <<<'JS'
'use strict';
self.addEventListener('install', function(event){ self.skipWaiting(); });
self.addEventListener('activate', function(event){ event.waitUntil(self.clients.claim()); });
self.addEventListener('push', function(event){
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = {body: event.data ? event.data.text() : ''}; }
    var title = data.title || 'هم‌نور';
    var options = {
        body: data.body || '',
        icon: data.icon || '',
        badge: data.badge || data.icon || '',
        tag: data.tag || ('hamnoor-' + (data.notification_id || Date.now())),
        renotify: false,
        dir: 'rtl',
        lang: 'fa',
        data: {url: data.url || '', notification_id: data.notification_id || 0},
        actions: data.url ? [{action: 'open', title: data.action_title || 'ادامه قرائت'}] : []
    };
    event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', function(event){
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '';
    if (!target) { return; }
    event.waitUntil(self.clients.matchAll({type:'window', includeUncontrolled:true}).then(function(list){
        for (var i=0; i<list.length; i++) {
            var c = list[i];
            try {
                if (new URL(c.url).origin === new URL(target, self.location.origin).origin) {
                    if ('navigate' in c) { c.navigate(target); }
                    return c.focus();
                }
            } catch (e) {}
        }
        return self.clients.openWindow ? self.clients.openWindow(target) : null;
    }));
});
JS;
    }

    private static function generate_p256_key() {
        $generated = Hamnoor_P256_Keygen::generate();
        if (!is_array($generated) || empty($generated['ok']) || empty($generated['key']) || empty($generated['private_pem']) || empty($generated['details'])) {
            $message = is_array($generated) && !empty($generated['error']) ? (string)$generated['error'] : 'P-256 key generation failed.';
            return new WP_Error('hamnoor_webpush_p256_keygen', $message);
        }
        return $generated;
    }

    private static function openssl_failure($code, $fallback) {
        $errors = array();
        if (function_exists('openssl_error_string')) {
            while (count($errors) < 3 && ($error = openssl_error_string())) { $errors[] = trim((string)$error); }
        }
        $message = (string)$fallback;
        if ($errors) { $message .= ' [' . implode(' | ', $errors) . ']'; }
        return new WP_Error($code, $message, array('openssl_errors'=>$errors));
    }

    public static function ensure_vapid_keys() {
        $stored = get_option('hamnoor_webpush_vapid', array());
        if (is_array($stored) && !empty($stored['private_pem']) && !empty($stored['public_key'])) { return $stored; }
        if (!extension_loaded('openssl')) { return new WP_Error('hamnoor_webpush_openssl', __('OpenSSL برای Web Push در دسترس نیست.', 'hamnoor')); }
        $generated = self::generate_p256_key();
        if (is_wp_error($generated)) { return $generated; }
        $key = $generated['key'];
        $private_pem = (string)$generated['private_pem'];
        $details = $generated['details'];
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) { return new WP_Error('hamnoor_webpush_keydetails', __('کلید عمومی Web Push معتبر نیست.', 'hamnoor')); }
        $public_raw = "\x04" . self::left_pad($details['ec']['x'], 32) . self::left_pad($details['ec']['y'], 32);
        $stored = array(
            'private_pem'=>$private_pem,
            'public_key'=>self::b64url_encode($public_raw),
            'created_at'=>current_time('mysql'),
        );
        update_option('hamnoor_webpush_vapid', $stored, false);
        $persisted = get_option('hamnoor_webpush_vapid', array());
        if (!is_array($persisted) || empty($persisted['private_pem']) || empty($persisted['public_key'])) {
            return new WP_Error('hamnoor_webpush_keypersist', __('ذخیره کلید Web Push در پایگاه داده ناموفق بود.', 'hamnoor'));
        }
        return $persisted;
    }

    public static function public_key() {
        $keys = self::ensure_vapid_keys();
        return is_wp_error($keys) ? '' : (string)$keys['public_key'];
    }

    public function ajax_status() {
        $user_id = $this->require_user();
        if (!$user_id) { return; }
        $keys = self::ensure_vapid_keys();
        if (is_wp_error($keys)) { wp_send_json_error(array('message'=>$keys->get_error_message())); }
        wp_send_json_success(array(
            'available'=>$this->is_available(),
            'public_key'=>$keys['public_key'],
            'service_worker_url'=>self::service_worker_url(),
            'subscriptions'=>self::active_subscription_count($user_id),
            'enabled'=>Hamnoor_Notifications::user_channel_enabled($user_id, 'web_push'),
        ));
    }

    public function ajax_subscribe() {
        $user_id = $this->require_user();
        if (!$user_id) { return; }
        $raw = isset($_POST['subscription']) ? wp_unslash($_POST['subscription']) : '';
        $subscription = is_string($raw) ? json_decode($raw, true) : $raw;
        $result = self::save_subscription($user_id, $subscription);
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message())); }
        Hamnoor_Notifications::set_user_channel($user_id, 'web_push', true, 'connected', array('subscriptions'=>self::active_subscription_count($user_id)));
        wp_send_json_success(array('message'=>__('اعلان مرورگر فعال شد.', 'hamnoor'),'subscriptions'=>self::active_subscription_count($user_id)));
    }

    public function ajax_unsubscribe() {
        $user_id = $this->require_user();
        if (!$user_id) { return; }
        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint'])) : '';
        if ($endpoint) { self::revoke_subscription($user_id, $endpoint); }
        if (0 === self::active_subscription_count($user_id)) {
            Hamnoor_Notifications::set_user_channel($user_id, 'web_push', false, 'disabled');
        }
        wp_send_json_success(array('message'=>__('اعلان مرورگر در این دستگاه غیرفعال شد.', 'hamnoor')));
    }

    public function ajax_test() {
        $user_id = $this->require_user();
        if (!$user_id) { return; }
        $notification = (object)array(
            'id'=>0,
            'user_id'=>$user_id,
            'type'=>'web_push_test',
            'title'=>__('آزمایش اعلان هم‌نور', 'hamnoor'),
            'message'=>__('Web Push با موفقیت به مرورگر شما متصل شده است.', 'hamnoor'),
            'related_type'=>'',
            'related_id'=>0,
        );
        $result = $this->send_to_user($user_id, $notification, array('url'=>Hamnoor_Helpers::page_url('dashboard')));
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message())); }
        wp_send_json_success(array('message'=>__('تست Web Push این مرورگر با موفقیت ارسال شد.', 'hamnoor')));
    }

    private function require_user() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) {
            wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor')), 403);
            return 0;
        }
        return (int)Hamnoor_Mobile_Access::current_user_id();
    }

    /**
     * Validate a browser Push endpoint as an outbound server destination.
     * esc_url_raw() sanitizes syntax; wp_http_validate_url()/safe HTTP APIs
     * enforce the SSRF boundary. Web Push endpoints must use HTTPS and the
     * standard TLS port unless an administrator explicitly extends the policy.
     */
    private static function validate_endpoint($endpoint) {
        $endpoint = esc_url_raw(trim((string)$endpoint), array('https'));
        if (!$endpoint || !function_exists('wp_http_validate_url') || false === wp_http_validate_url($endpoint)) {
            return new WP_Error('hamnoor_webpush_endpoint_unsafe', __('نشانی سرویس Push از نظر امنیتی معتبر نیست.', 'hamnoor'));
        }
        $parts = wp_parse_url($endpoint);
        if (!is_array($parts) || empty($parts['host']) || 'https' !== strtolower((string)($parts['scheme'] ?? ''))) {
            return new WP_Error('hamnoor_webpush_endpoint_https', __('نشانی سرویس Push باید یک HTTPS معتبر باشد.', 'hamnoor'));
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return new WP_Error('hamnoor_webpush_endpoint_credentials', __('نشانی سرویس Push نباید شامل اطلاعات ورود باشد.', 'hamnoor'));
        }
        $port = !empty($parts['port']) ? (int)$parts['port'] : 443;
        $allowed_ports = apply_filters('hamnoor_webpush_allowed_ports', array(443));
        $allowed_ports = array_values(array_unique(array_filter(array_map('intval', (array)$allowed_ports))));
        if (!in_array($port, $allowed_ports, true)) {
            return new WP_Error('hamnoor_webpush_endpoint_port', __('درگاه سرویس Push مجاز نیست.', 'hamnoor'));
        }
        return $endpoint;
    }

    public static function save_subscription($user_id, $subscription) {
        global $wpdb;
        if (!is_array($subscription)) { return new WP_Error('hamnoor_push_bad_subscription', __('اطلاعات اشتراک مرورگر معتبر نیست.', 'hamnoor')); }
        $endpoint = self::validate_endpoint(!empty($subscription['endpoint']) ? $subscription['endpoint'] : '');
        if (is_wp_error($endpoint)) { return $endpoint; }
        $keys = !empty($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : array();
        $p256dh = isset($keys['p256dh']) ? sanitize_text_field($keys['p256dh']) : '';
        $auth = isset($keys['auth']) ? sanitize_text_field($keys['auth']) : '';
        if (!$p256dh || !$auth || 65 !== strlen(self::b64url_decode($p256dh)) || strlen(self::b64url_decode($auth)) < 16) {
            return new WP_Error('hamnoor_push_bad_subscription', __('کلیدهای اشتراک Web Push معتبر نیستند.', 'hamnoor'));
        }
        $table = Hamnoor_DB::table('push_subscriptions');
        $hash = hash('sha256', $endpoint);
        $now = current_time('mysql');
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE endpoint_hash=%s LIMIT 1", $hash));
        $data = array(
            'user_id'=>(int)$user_id,
            'endpoint_hash'=>$hash,
            'endpoint'=>$endpoint,
            'p256dh'=>$p256dh,
            'auth'=>$auth,
            'content_encoding'=>'aes128gcm',
            'user_agent'=>isset($_SERVER['HTTP_USER_AGENT']) ? Hamnoor_Validator::trim_len(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 500) : '',
            'updated_at'=>$now,
            'revoked_at'=>null,
            'last_error_code'=>null,
        );
        if ($id) {
            return false !== $wpdb->update($table, $data, array('id'=>(int)$id), array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'), array('%d'));
        }
        $data['created_at'] = $now;
        return (bool)$wpdb->insert($table, $data, array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
    }

    public static function revoke_subscription($user_id, $endpoint) {
        global $wpdb;
        return false !== $wpdb->update(Hamnoor_DB::table('push_subscriptions'), array('revoked_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('user_id'=>(int)$user_id,'endpoint_hash'=>hash('sha256',(string)$endpoint)), array('%s','%s'), array('%d','%s'));
    }

    public static function active_subscription_count($user_id = 0) {
        global $wpdb;
        $table = Hamnoor_DB::table('push_subscriptions');
        if ($user_id) { return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d AND revoked_at IS NULL", (int)$user_id)); }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE revoked_at IS NULL");
    }

    /**
     * Safe admin diagnostics. Never exposes endpoints or subscription keys.
     */
    public static function diagnostics() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT endpoint,last_success_at,last_error_at,last_error_code,updated_at FROM " . Hamnoor_DB::table('push_subscriptions') . " WHERE revoked_at IS NULL ORDER BY updated_at DESC");
        $out = array(
            'active'=>0,
            'ever_succeeded'=>0,
            'never_succeeded'=>0,
            'http_401_403'=>0,
            'other_errors'=>0,
            'hosts'=>array(),
            'latest_error_code'=>'',
            'latest_error_at'=>'',
        );
        foreach ((array)$rows as $row) {
            $out['active']++;
            if (!empty($row->last_success_at)) { $out['ever_succeeded']++; } else { $out['never_succeeded']++; }
            $code = sanitize_key((string)$row->last_error_code);
            if ($code) {
                if (in_array($code, array('http_401','http_403','auth_401','auth_403'), true)) { $out['http_401_403']++; }
                else { $out['other_errors']++; }
                if (!$out['latest_error_code']) {
                    $out['latest_error_code'] = $code;
                    $out['latest_error_at'] = (string)$row->last_error_at;
                }
            }
            $host = wp_parse_url((string)$row->endpoint, PHP_URL_HOST);
            if ($host) { $out['hosts'][strtolower((string)$host)] = 1; }
        }
        $out['hosts'] = array_keys($out['hosts']);
        return $out;
    }

    private static function active_subscriptions($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Hamnoor_DB::table('push_subscriptions') . " WHERE user_id=%d AND revoked_at IS NULL ORDER BY id ASC", (int)$user_id));
    }

    public function send_to_user($user_id, $notification, $context = array()) {
        if (!$this->is_available()) { return new WP_Error('hamnoor_webpush_unavailable', __('Web Push روی این سرور در دسترس نیست.', 'hamnoor')); }
        $subscriptions = self::active_subscriptions((int)$user_id);
        if (!$subscriptions) { return new WP_Error('hamnoor_webpush_not_connected', __('این کاربر Web Push را فعال نکرده است.', 'hamnoor')); }
        $payload = self::notification_payload($notification, $context);
        $success = 0;
        $last_error = null;
        foreach ($subscriptions as $subscription) {
            $result = $this->send_subscription($subscription, $payload);
            if (is_wp_error($result)) { $last_error = $result; continue; }
            $success++;
        }
        return $success ? true : ($last_error ?: new WP_Error('hamnoor_webpush_failed', __('ارسال Web Push ناموفق بود.', 'hamnoor')));
    }

    private static function notification_payload($notification, $context) {
        $is_broadcast = !empty($notification->type) && 'admin_broadcast' === (string)$notification->type;
        $url = !empty($context['url']) ? esc_url_raw((string)$context['url'], array('http','https')) : '';
        if ($url && !wp_http_validate_url($url)) { $url = ''; }
        if (!$is_broadcast) {
            if (!$url && 'part' === (string)$notification->related_type && !empty($notification->related_id) && class_exists('Hamnoor_Reading')) {
                $url = Hamnoor_Reading::reading_url((int)$notification->related_id);
            }
            if (!$url) { $url = Hamnoor_Helpers::page_url('dashboard'); }
        }
        $icon = get_site_icon_url(192);
        $body = (string)$notification->message;
        if ($is_broadcast && $url) { $body = trim($body . "\n\n" . $url); }
        return wp_json_encode(array(
            'notification_id'=>(int)$notification->id,
            'title'=>Hamnoor_Validator::trim_len((string)$notification->title, 100),
            'body'=>Hamnoor_Validator::trim_len($body, 360),
            'url'=>$url,
            'icon'=>$icon ? esc_url_raw($icon) : '',
            'badge'=>$icon ? esc_url_raw($icon) : '',
            'tag'=>'hamnoor-' . sanitize_key((string)$notification->type) . '-' . (int)$notification->related_id,
            'action_title'=>$is_broadcast ? __('مشاهده لینک', 'hamnoor') : __('ادامه قرائت', 'hamnoor'),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function send_subscription($subscription, $payload) {
        global $wpdb;
        $endpoint = self::validate_endpoint((string)$subscription->endpoint);
        if (is_wp_error($endpoint)) {
            $now = current_time('mysql');
            $wpdb->update(
                Hamnoor_DB::table('push_subscriptions'),
                array('revoked_at'=>$now,'last_error_at'=>$now,'last_error_code'=>'unsafe_endpoint','updated_at'=>$now),
                array('id'=>(int)$subscription->id),
                array('%s','%s','%s','%s'),
                array('%d')
            );
            return $endpoint;
        }
        $encrypted = self::encrypt_payload($payload, (string)$subscription->p256dh, (string)$subscription->auth);
        if (is_wp_error($encrypted)) { return $encrypted; }
        $vapid = self::vapid_headers($endpoint);
        if (is_wp_error($vapid)) { return $vapid; }
        $headers = array_merge(array(
            'Content-Type'=>'application/octet-stream',
            'Content-Encoding'=>'aes128gcm',
            'TTL'=>'86400',
            'Urgency'=>'normal',
        ), $vapid);
        $response = wp_safe_remote_post($endpoint, array('timeout'=>12,'redirection'=>0,'headers'=>$headers,'body'=>$encrypted,'reject_unsafe_urls'=>true));
        $now = current_time('mysql');
        if (is_wp_error($response)) {
            $wpdb->update(Hamnoor_DB::table('push_subscriptions'), array('last_error_at'=>$now,'last_error_code'=>sanitize_key($response->get_error_code()),'updated_at'=>$now), array('id'=>(int)$subscription->id), array('%s','%s','%s'), array('%d'));
            return $response;
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $wpdb->update(Hamnoor_DB::table('push_subscriptions'), array('last_success_at'=>$now,'last_error_code'=>null,'updated_at'=>$now), array('id'=>(int)$subscription->id), array('%s','%s','%s'), array('%d'));
            return true;
        }
        $endpoint_host = (string)wp_parse_url($endpoint, PHP_URL_HOST);
        $body = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string)wp_remote_retrieve_body($response))));
        $body_hint = $body ? Hamnoor_Validator::trim_len($body, 260) : '';
        if (in_array($code, array(404,410), true)) {
            $wpdb->update(Hamnoor_DB::table('push_subscriptions'), array('revoked_at'=>$now,'last_error_at'=>$now,'last_error_code'=>'expired_subscription','updated_at'=>$now), array('id'=>(int)$subscription->id), array('%s','%s','%s','%s'), array('%d'));
        } else {
            $stored_error = in_array($code, array(401,403), true) ? 'auth_' . $code : 'http_' . $code;
            $wpdb->update(Hamnoor_DB::table('push_subscriptions'), array('last_error_at'=>$now,'last_error_code'=>$stored_error,'updated_at'=>$now), array('id'=>(int)$subscription->id), array('%s','%s','%s'), array('%d'));
        }
        if (in_array($code, array(401,403), true)) {
            $message = sprintf(__('سرویس Push پاسخ HTTP %d داد. اشتراک مرورگر احتمالاً با کلید VAPID فعلی هم‌نور هماهنگ نیست؛ در همان دستگاه Web Push را یک‌بار دوباره فعال کنید.', 'hamnoor'), $code);
        } elseif (in_array($code, array(404,410), true)) {
            $message = sprintf(__('اشتراک Web Push دیگر معتبر نیست (HTTP %d) و از چرخه ارسال خارج شد. Web Push را در همان دستگاه دوباره فعال کنید.', 'hamnoor'), $code);
        } else {
            $message = sprintf(__('سرویس Push پاسخ HTTP %d داد.', 'hamnoor'), $code);
        }
        return new WP_Error('hamnoor_webpush_http_' . $code, $message, array(
            'http_code'=>$code,
            'endpoint_host'=>$endpoint_host,
            'response_hint'=>$body_hint,
        ));
    }

    private static function vapid_headers($endpoint) {
        $keys = self::ensure_vapid_keys();
        if (is_wp_error($keys)) { return $keys; }
        $parts = wp_parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) { return new WP_Error('hamnoor_webpush_endpoint', __('نشانی سرویس Push معتبر نیست.', 'hamnoor')); }
        $aud = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) { $aud .= ':' . (int)$parts['port']; }
        $header = self::b64url_encode(wp_json_encode(array('typ'=>'JWT','alg'=>'ES256')));
        $claims = self::b64url_encode(wp_json_encode(array('aud'=>$aud,'exp'=>time()+43200,'sub'=>home_url('/'))));
        $input = $header . '.' . $claims;
        $private = openssl_pkey_get_private($keys['private_pem']);
        $der = '';
        if (!$private || !openssl_sign($input, $der, $private, OPENSSL_ALGO_SHA256)) { return new WP_Error('hamnoor_webpush_vapid_sign', __('امضای VAPID ناموفق بود.', 'hamnoor')); }
        $raw = self::ecdsa_der_to_raw($der, 32);
        if (is_wp_error($raw)) { return $raw; }
        $jwt = $input . '.' . self::b64url_encode($raw);
        return array('Authorization'=>'vapid t=' . $jwt . ', k=' . $keys['public_key']);
    }

    private static function encrypt_payload($payload, $receiver_public_b64, $auth_b64) {
        $receiver_public = self::b64url_decode($receiver_public_b64);
        $auth = self::b64url_decode($auth_b64);
        if (65 !== strlen($receiver_public) || "\x04" !== $receiver_public[0] || strlen($auth) < 16) {
            return new WP_Error('hamnoor_webpush_keys', __('کلیدهای رمزنگاری Web Push معتبر نیستند.', 'hamnoor'));
        }
        if (strlen($payload) > 3000) { $payload = substr($payload, 0, 3000); }
        $generated = self::generate_p256_key();
        if (is_wp_error($generated)) { return new WP_Error('hamnoor_webpush_ephemeral', __('ساخت کلید موقت Push ناموفق بود.', 'hamnoor'), array('cause'=>$generated->get_error_message())); }
        $server_key = $generated['key'];
        $details = $generated['details'];
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) { return new WP_Error('hamnoor_webpush_ephemeral_details', __('کلید موقت Push معتبر نیست.', 'hamnoor')); }
        $server_public = "\x04" . self::left_pad($details['ec']['x'], 32) . self::left_pad($details['ec']['y'], 32);
        $receiver_pem = self::ec_public_raw_to_pem($receiver_public);
        $receiver_key = openssl_pkey_get_public($receiver_pem);
        if (!$receiver_key) { return new WP_Error('hamnoor_webpush_receiver_key', __('کلید مرورگر قابل خواندن نیست.', 'hamnoor')); }
        $shared = openssl_pkey_derive($receiver_key, $server_key, 32);
        if (false === $shared || !strlen($shared)) { return new WP_Error('hamnoor_webpush_ecdh', __('تبادل کلید Web Push ناموفق بود.', 'hamnoor')); }

        $prk_key = self::hkdf_extract($auth, $shared);
        $key_info = "WebPush: info\x00" . $receiver_public . $server_public;
        $ikm = self::hkdf_expand($prk_key, $key_info, 32);
        $salt = random_bytes(16);
        $prk = self::hkdf_extract($salt, $ikm);
        $cek = self::hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);
        $plaintext = $payload . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (false === $ciphertext || 16 !== strlen($tag)) { return new WP_Error('hamnoor_webpush_encrypt', __('رمزنگاری پیام Web Push ناموفق بود.', 'hamnoor')); }
        $record_size = 4096;
        return $salt . pack('N', $record_size) . chr(strlen($server_public)) . $server_public . $ciphertext . $tag;
    }

    private static function hkdf_extract($salt, $ikm) { return hash_hmac('sha256', $ikm, $salt, true); }
    private static function hkdf_expand($prk, $info, $length) {
        $out = ''; $prev = ''; $i = 1;
        while (strlen($out) < $length) {
            $prev = hash_hmac('sha256', $prev . $info . chr($i), $prk, true);
            $out .= $prev; $i++;
        }
        return substr($out, 0, $length);
    }

    private static function ec_public_raw_to_pem($raw) {
        // ASN.1 SubjectPublicKeyInfo prefix for id-ecPublicKey + prime256v1.
        $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
        $der = $prefix . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function ecdsa_der_to_raw($der, $part_length) {
        $offset = 0;
        if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x30) { return new WP_Error('hamnoor_webpush_der', __('امضای VAPID معتبر نیست.', 'hamnoor')); }
        self::asn1_read_length($der, $offset);
        if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x02) { return new WP_Error('hamnoor_webpush_der_r', __('امضای VAPID معتبر نیست.', 'hamnoor')); }
        $rlen = self::asn1_read_length($der, $offset); $r = substr($der, $offset, $rlen); $offset += $rlen;
        if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x02) { return new WP_Error('hamnoor_webpush_der_s', __('امضای VAPID معتبر نیست.', 'hamnoor')); }
        $slen = self::asn1_read_length($der, $offset); $s = substr($der, $offset, $slen);
        $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
        if (strlen($r) > $part_length || strlen($s) > $part_length) { return new WP_Error('hamnoor_webpush_der_size', __('اندازه امضای VAPID معتبر نیست.', 'hamnoor')); }
        return str_pad($r, $part_length, "\x00", STR_PAD_LEFT) . str_pad($s, $part_length, "\x00", STR_PAD_LEFT);
    }

    private static function asn1_read_length($data, &$offset) {
        $len = ord($data[$offset++]);
        if (($len & 0x80) === 0) { return $len; }
        $count = $len & 0x7f; $len = 0;
        for ($i=0; $i<$count; $i++) { $len = ($len << 8) | ord($data[$offset++]); }
        return $len;
    }

    private static function left_pad($value, $length) { return str_pad((string)$value, $length, "\x00", STR_PAD_LEFT); }
    private static function b64url_encode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    private static function b64url_decode($data) {
        $data = strtr((string)$data, '-_', '+/');
        $pad = strlen($data) % 4; if ($pad) { $data .= str_repeat('=', 4-$pad); }
        $decoded = base64_decode($data, true); return false === $decoded ? '' : $decoded;
    }
}
