<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bale (بله) Bot API notification adapter.
 *
 * Official API base: https://tapi.bale.ai/bot<TOKEN>/METHOD_NAME
 * Users connect by starting the bot and explicitly sharing their own contact
 * through a request_contact keyboard button. The received phone is matched to
 * the existing Hamnoor mobile account; no SMS OTP is required for this channel.
 */
class Hamnoor_Bale implements Hamnoor_Notification_Channel_Interface {
    const OPTION = 'hamnoor_bale_settings';
    const API_BASE = 'https://tapi.bale.ai/bot';
    const REST_NAMESPACE = 'hamnoor/v1';
    const MINIAPP_PATH = 'hamnoor-bale';
    const HANDOFF_PATH = 'hamnoor-bale-login';
    const MINIAPP_QUERY_VAR = 'hamnoor_bale_miniapp';
    const HANDOFF_QUERY_VAR = 'hamnoor_bale_handoff';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function init() {
        Hamnoor_Notification_Channel_Registry::register('bale', self::instance());
        // 3.9.40: serve the clean Main Mini App URL before WordPress parses
        // rewrite rules. This makes /hamnoor-bale/ a real HTTP 200 endpoint
        // even when pretty-permalink rules are stale or LiteSpeed has cached a 404.
        add_action('init', array(__CLASS__, 'maybe_serve_clean_miniapp_early'), 0);
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_action('init', array(__CLASS__, 'register_rewrite_rules'), 2);
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        // Bale Web renders Mini Apps in a cross-site iframe. Security plugins or
        // the web server may add X-Frame-Options/CSP headers that silently block
        // the launch even though the web_app button itself is valid. Adjust only
        // Bale Mini App / Bale-session responses; ordinary Hamnoor pages keep
        // their existing framing policy.
        add_filter('wp_headers', array(__CLASS__, 'filter_bale_frame_headers'), PHP_INT_MAX, 2);
        add_action('template_redirect', array(__CLASS__, 'maybe_render_miniapp'), 0);
        add_action('template_redirect', array(__CLASS__, 'maybe_allow_bale_embedded_page'), PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'render_legacy_miniapp_bridge'), -100);
        add_action('admin_post_hamnoor_bale_save', array(__CLASS__, 'admin_save'));
        add_action('admin_post_hamnoor_bale_test_connection', array(__CLASS__, 'admin_test_connection'));
        add_action('admin_post_hamnoor_bale_set_webhook', array(__CLASS__, 'admin_set_webhook'));
        add_action('admin_post_hamnoor_bale_webhook_info', array(__CLASS__, 'admin_webhook_info'));
        add_action('admin_post_hamnoor_bale_test_message', array(__CLASS__, 'admin_test_message'));
        add_action('admin_post_hamnoor_bale_disconnect', array(__CLASS__, 'frontend_disconnect'));
        add_action('admin_post_nopriv_hamnoor_bale_disconnect', array(__CLASS__, 'frontend_disconnect'));
    }

    public static function register_rewrite_rules() {
        // Keep the clean URL inside WordPress. The actual shell is served even
        // earlier by maybe_serve_clean_miniapp_early(), so this rule is only a
        // compatibility fallback and never rewrites to a physical plugin path.
        add_rewrite_rule('^' . preg_quote(self::MINIAPP_PATH, '#') . '/?$', 'index.php?' . self::MINIAPP_QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^' . preg_quote(self::HANDOFF_PATH, '#') . '/?$', 'index.php?' . self::HANDOFF_QUERY_VAR . '=1', 'top');
    }

    /**
     * Serve /hamnoor-bale/ before WP_Query / canonical / 404 handling.
     *
     * This is intentionally REQUEST_URI-based and therefore does not require a
     * WordPress page, permalink flush, server rewrite to a plugin file, or a
     * template. It is the stable URL that should be saved in Bale BotFather.
     */
    public static function maybe_serve_clean_miniapp_early() {
        if (is_admin() || empty($_SERVER['REQUEST_URI'])) { return; }

        $request_path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $request_path = trim($request_path, '/');
        $home_path = trim($home_path, '/');
        if ($home_path) {
            if (0 !== strpos($request_path . '/', $home_path . '/')) { return; }
            $request_path = trim(substr($request_path, strlen($home_path)), '/');
        }
        if (self::MINIAPP_PATH !== $request_path) { return; }

        // Never expose an ordinary Hamnoor page as a fallback when Bale is not
        // configured, because initData cannot be verified without the bot token.
        if (!self::configured()) {
            status_header(503);
            self::allow_bale_frame_headers();
            header('Content-Type: text/html; charset=utf-8', true);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>هم‌نور</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:28px;line-height:2;text-align:center"><p>اتصال بله در هم‌نور هنوز پیکربندی نشده است.</p></body></html>';
            exit;
        }

        $file = HAMNOOR_BALE_PATH . 'assets/bale-miniapp/index.html';
        if (!is_readable($file)) {
            status_header(500);
            self::allow_bale_frame_headers();
            header('Content-Type: text/plain; charset=utf-8', true);
            header('Cache-Control: no-store', true);
            echo 'Hamnoor Bale Mini App shell is missing.';
            exit;
        }

        status_header(200);
        self::allow_bale_frame_headers();
        header('Content-Type: text/html; charset=utf-8', true);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        header('X-Hamnoor-Bale-Entry: early-200-' . HAMNOOR_BALE_VERSION, true);
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        do_action('litespeed_control_set_nocache', 'Hamnoor Bale clean Mini App endpoint');
        readfile($file);
        exit;
    }

    public static function register_query_vars($vars) {
        $vars[] = self::MINIAPP_QUERY_VAR;
        $vars[] = self::HANDOFF_QUERY_VAR;
        return $vars;
    }

    /**
     * Whether this HTTP response belongs to the Bale Mini App flow.
     *
     * The dedicated hamnoor_bale_session cookie is intentionally used as a
     * signal here: in Bale Web it is a SameSite=None + Partitioned cookie, so
     * allowing framing for subsequent dashboard/reading navigations remains
     * scoped to the Bale top-level site rather than weakening the public site.
     */
    private static function is_bale_embed_request() {
        if (isset($_GET[self::MINIAPP_QUERY_VAR]) || isset($_GET[self::HANDOFF_QUERY_VAR]) || isset($_GET['hm_bale_login'])) {
            return true;
        }
        if (class_exists('Hamnoor_Mobile_Access') && isset($_COOKIE[Hamnoor_Mobile_Access::BALE_COOKIE_NAME])) {
            return true;
        }
        $request_path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string)parse_url($request_path, PHP_URL_PATH), '/');
        $home_path = trim((string)parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path && 0 === strpos($path, $home_path . '/')) { $path = substr($path, strlen($home_path) + 1); }
        elseif ($home_path === $path) { $path = ''; }
        $path = trim($path, '/');
        return self::MINIAPP_PATH === $path || self::HANDOFF_PATH === $path;
    }

    private static function bale_frame_csp() {
        // frame-ancestors is the standards-based control for who may embed this
        // response. frame-src is also included because Bale's official Mini App
        // troubleshooting guide explicitly asks for it on the web client.
        return "frame-ancestors 'self' https://bale.ai https://*.bale.ai https://ble.ir https://*.ble.ir; frame-src 'self' https://bale.ai https://*.bale.ai https://ble.ir https://*.ble.ir";
    }

    public static function filter_bale_frame_headers($headers, $wp = null) {
        if (!self::is_bale_embed_request() || !is_array($headers)) { return $headers; }
        foreach (array_keys($headers) as $key) {
            $lower = strtolower((string)$key);
            if ('x-frame-options' === $lower || 'content-security-policy' === $lower) {
                unset($headers[$key]);
            }
        }
        $headers['Content-Security-Policy'] = self::bale_frame_csp();
        $headers['X-Hamnoor-Bale-Frame'] = 'allowed';
        return $headers;
    }

    private static function allow_bale_frame_headers() {
        if (headers_sent()) { return; }
        // X-Frame-Options has no reliable ALLOW-FROM support in modern browsers;
        // removing it and using CSP frame-ancestors is the correct cross-origin
        // iframe approach for Bale Web.
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header('Content-Security-Policy: ' . self::bale_frame_csp(), true);
        header('X-Hamnoor-Bale-Frame: allowed', true);
    }

    public static function maybe_allow_bale_embedded_page() {
        if (!self::is_bale_embed_request()) { return; }
        self::allow_bale_frame_headers();
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        do_action('litespeed_control_set_nocache', 'Hamnoor Bale iframe response');
    }

    public function key() { return 'bale'; }

    public function is_available() {
        $s = self::settings();
        return !empty($s['enabled']) && self::configured();
    }

    public function is_connected($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table || !(int)$user_id) { return false; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,channel,enabled,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1",
            (int)$user_id
        ));
        if (!$row || 1 !== (int)$row->enabled || 'connected' !== (string)$row->status || '' === trim((string)$row->external_id)) { return false; }
        return !function_exists('hamnoor_notifications_channel_identity_valid') || hamnoor_notifications_channel_identity_valid((int)$user_id, 'bale', $row);
    }

    public function send_to_user($user_id, $notification, $context = array()) {
        if (!$this->is_available()) {
            return new WP_Error('hamnoor_bale_not_configured', __('کانال بله هنوز پیکربندی نشده است.', 'hamnoor'));
        }
        $chat_id = self::user_chat_id((int)$user_id);
        if (!$chat_id) {
            return new WP_Error('hamnoor_bale_not_connected', __('حساب بله این کاربر هنوز به هم‌نور متصل نشده است.', 'hamnoor'));
        }
        // A custom admin broadcast must never acquire an automatic Hamnoor
        // dashboard/handoff link. Send plain text and append only the URL the
        // administrator explicitly supplied. An empty URL means no link/button.
        if (!empty($notification->type) && 'admin_broadcast' === (string)$notification->type) {
            $text = self::notification_text($notification);
            $custom_url = !empty($context['url']) ? esc_url_raw((string)$context['url'], array('http','https')) : '';
            if ($custom_url && !wp_http_validate_url($custom_url)) { $custom_url = ''; }
            if ($custom_url) { $text .= "\n\n" . $custom_url; }
            $result = self::api_call('sendMessage', array('chat_id'=>$chat_id, 'text'=>$text));
            if (is_wp_error($result)) {
                self::maybe_mark_send_failure($user_id, $result);
                return $result;
            }
            self::mark_channel_healthy((int)$user_id);
            return true;
        }

        $target = self::notification_target($notification, $context);
        $params = array(
            'chat_id' => $chat_id,
            'text' => self::notification_text($notification),
        );
        $miniapp_url = self::notification_handoff_url((int)$user_id, $target);
        if ($miniapp_url) {
            // Use a normal HTTPS button. Bale opens it in its internal browser;
            // the signed handoff logs the intended Hamnoor user in directly.
            $button = array(
                'text' => __('ادامه در هم‌نور', 'hamnoor'),
                'url' => $miniapp_url,
            );
            $params['reply_markup'] = array('inline_keyboard' => array(array($button)));
        }
        $result = self::api_call('sendMessage', $params);
        if (is_wp_error($result)) {
            self::maybe_mark_send_failure($user_id, $result);
            return $result;
        }
        self::mark_channel_healthy((int)$user_id);
        return true;
    }

    public static function settings() {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        return wp_parse_args($raw, array(
            'enabled' => '0',
            'token_enc' => '',
            'bot_username' => '',
            'bot_name' => '',
            'test_chat_id' => '',
            'webhook_secret' => '',
            'webhook_set_at' => '',
            'webhook_url' => '',
            'last_getme_at' => '',
            'last_test_at' => '',
            'last_webhook_check_at' => '',
            'last_webhook_status' => '',
            'last_webhook_hit_at' => '',
            'last_webhook_update_id' => '',
            'last_webhook_message_text' => '',
        ));
    }

    public static function configured() { return '' !== self::token(); }

    public static function token() {
        $s = self::settings();
        if (empty($s['token_enc'])) { return ''; }
        $token = self::decrypt_secret((string)$s['token_enc']);
        return is_string($token) ? $token : '';
    }

    public static function bot_username() {
        $s = self::settings();
        return sanitize_key(ltrim((string)$s['bot_username'], '@'));
    }

    public static function bot_url() {
        $username = self::bot_username();
        return $username ? esc_url_raw('https://ble.ir/' . rawurlencode($username)) : '';
    }

    public static function connected_users_count() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return 0; }
        $rows = $wpdb->get_results("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE channel='bale' AND enabled=1 AND status='connected' AND external_id IS NOT NULL AND external_id<>''");
        if (!function_exists('hamnoor_notifications_channel_identity_valid')) { return count((array)$rows); }
        $count = 0;
        foreach ((array)$rows as $row) {
            if (hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'bale', $row)) { $count++; }
        }
        return $count;
    }

    public static function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, '/bale/(?P<secret>[A-Za-z0-9_-]{24,96})', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'webhook_callback'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/bale-miniapp/auth', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'miniapp_auth_callback'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/bale-miniapp/connect', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'miniapp_connect_callback'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function webhook_url() {
        $s = self::settings();
        $secret = isset($s['webhook_secret']) ? (string)$s['webhook_secret'] : '';
        if (!$secret) { return ''; }
        return rest_url(self::REST_NAMESPACE . '/bale/' . rawurlencode($secret));
    }

    public static function ensure_webhook_secret() {
        $s = self::settings();
        if (!empty($s['webhook_secret']) && preg_match('/^[A-Za-z0-9_-]{24,96}$/', (string)$s['webhook_secret'])) {
            return (string)$s['webhook_secret'];
        }
        $s['webhook_secret'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        update_option(self::OPTION, $s, false);
        return (string)$s['webhook_secret'];
    }

    public static function webhook_callback(WP_REST_Request $request) {
        $s = self::settings();
        $expected = isset($s['webhook_secret']) ? (string)$s['webhook_secret'] : '';
        $provided = (string)$request->get_param('secret');
        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return new WP_REST_Response(array('ok'=>false), 404);
        }
        $update = $request->get_json_params();
        if (!is_array($update)) {
            return new WP_REST_Response(array('ok'=>false), 400);
        }

        // Persist a tiny inbound diagnostic so the administrator can distinguish
        // "Bot API can send" from "Bale is actually delivering Updates to WP".
        // This is intentionally recorded before command processing/deduplication.
        $diag = self::settings();
        $diag['last_webhook_hit_at'] = current_time('mysql');
        $diag['last_webhook_update_id'] = isset($update['update_id']) ? Hamnoor_Validator::trim_len((string)$update['update_id'], 64) : '';
        $diag_text = '';
        if (!empty($update['message']) && is_array($update['message']) && isset($update['message']['text'])) {
            $diag_text = Hamnoor_Validator::trim_len(wp_strip_all_tags((string)$update['message']['text']), 160);
        }
        $diag['last_webhook_message_text'] = $diag_text;
        update_option(self::OPTION, $diag, false);

        $update_id = isset($update['update_id']) ? (string)$update['update_id'] : '';
        if ($update_id !== '') {
            $dedupe_key = 'hamnoor_bale_u_' . substr(hash('sha256', $update_id), 0, 32);
            if (get_transient($dedupe_key)) { return new WP_REST_Response(array('ok'=>true), 200); }
            set_transient($dedupe_key, '1', DAY_IN_SECONDS);
        }
        try {
            self::process_update($update);
        } catch (Throwable $e) {
            error_log('Hamnoor Bale webhook error: ' . $e->getMessage());
        }
        return new WP_REST_Response(array('ok'=>true), 200);
    }

    /** Normalize Persian/Arabic text returned by Bale reply keyboards. */
    private static function normalize_menu_text($text) {
        $text = trim(wp_strip_all_tags((string)$text));
        if ('' === $text) { return ''; }
        $text = strtr($text, array(
            'ي'=>'ی', 'ى'=>'ی', 'ك'=>'ک', 'ة'=>'ه', 'ۀ'=>'ه', '‌'=>' ',
            "\xE2\x80\x8F"=>'', "\xE2\x80\x8E"=>'',
        ));
        $text = preg_replace('/[\x{200c}\x{200d}\x{200e}\x{200f}]/u', ' ', $text);
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
    }

    /** Resolve slash commands and visible Persian menu text to one action. */
    private static function resolve_menu_action($text) {
        $raw = trim((string)$text);
        $command = '';
        if ($raw !== '' && preg_match('~^/([a-z0-9_]+)(?:@[^\s]+)?\b~iu', $raw, $m)) {
            $command = strtolower((string)$m[1]);
        }
        $command_map = array(
            'start'=>'menu', 'menu'=>'menu', 'home'=>'dashboard',
            'continue'=>'continue', 'reading'=>'continue',
            'parts'=>'parts', 'public'=>'public', 'list'=>'public',
            'mykhatms'=>'my_khatms', 'khatms'=>'my_khatms',
            'create'=>'create', 'dashboard'=>'dashboard',
            'notifications'=>'notifications', 'reminders'=>'reminders',
            'profile'=>'profile', 'progress'=>'progress',
            'help'=>'help', 'stop'=>'disconnect', 'disconnect'=>'disconnect', 'enable'=>'enable_notifications',
        );
        if ($command && isset($command_map[$command])) { return $command_map[$command]; }

        $label = self::normalize_menu_text($raw);
        $labels = array(
            'ادامه قرائت'=>'continue', 'ادامه خواندن'=>'continue', 'شروع قرائت'=>'continue',
            'سهم های من'=>'parts', 'مشارکت های من'=>'parts', 'مشارکت ها'=>'parts',
            'پویش های عمومی'=>'public', 'پویش عمومی'=>'public',
            'پویش های من'=>'my_khatms', 'مدیریت پویش های من'=>'my_khatms',
            'ایجاد پویش'=>'create', 'ایجاد پویش جدید'=>'create',
            'داشبورد هم نور'=>'dashboard', 'داشبورد'=>'dashboard', 'خانه'=>'dashboard',
            'اعلان ها'=>'notifications', 'اعلان های من'=>'notifications',
            'یادآوری ها'=>'reminders', 'یادآوری'=>'reminders',
            'مشخصات من'=>'profile', 'پروفایل'=>'profile',
            'مرکز پیشرفت'=>'progress', 'پیشرفت'=>'progress',
            'راهنما'=>'help', 'کمک'=>'help', 'منو'=>'menu', 'منوی هم نور'=>'menu',
            'قطع اتصال'=>'disconnect', 'خروج'=>'disconnect', 'لغو اعلان'=>'disconnect', 'انصراف از اعلان'=>'disconnect', 'فعال سازی اعلان'=>'enable_notifications', 'فعال کردن اعلان'=>'enable_notifications',
        );
        return isset($labels[$label]) ? $labels[$label] : '';
    }

    private static function process_update($update) {
        if (empty($update['message']) || !is_array($update['message'])) { return; }
        $message = $update['message'];
        $chat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : array();
        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : array();
        if (isset($chat['type']) && 'private' !== (string)$chat['type']) { return; }
        $chat_id = self::sanitize_chat_id(isset($chat['id']) ? $chat['id'] : '');
        $from_id = self::sanitize_chat_id(isset($from['id']) ? $from['id'] : '');
        if (!$chat_id || !$from_id) { return; }

        $text = isset($message['text']) ? trim((string)$message['text']) : '';

        if (!empty($message['contact']) && is_array($message['contact'])) {
            self::handle_contact($chat_id, $from_id, $from, $message['contact']);
            return;
        }

        $linked_user = self::get_user_id_by_identity($chat_id, $from_id);
        $action = self::resolve_menu_action($text);
        if (!$linked_user) {
            // A person can begin entirely inside Bale; sharing the account's own
            // contact creates/fetches the same Hamnoor mobile panel without OTP.
            self::send_contact_request($chat_id);
            return;
        }

        if ('disconnect' === $action) {
            self::disconnect_user($linked_user);
            self::send_message($chat_id, __('ارسال اعلان‌های هم‌نور در بله متوقف شد. حساب، اتصال بله و سوابق شما باقی مانده است و هر زمان می‌توانید اعلان را دوباره فعال کنید.', 'hamnoor'), self::connected_keyboard($linked_user));
            return;
        }
        if ('enable_notifications' === $action) {
            $enabled = self::enable_notifications($linked_user);
            self::send_message($chat_id, $enabled ? __('اعلان‌های بله دوباره فعال شد.', 'hamnoor') : __('فعال‌سازی اعلان‌های بله انجام نشد؛ دوباره تلاش کنید.', 'hamnoor'), self::connected_keyboard($linked_user));
            return;
        }
        if ('help' === $action) {
            self::send_help_message($chat_id, $linked_user);
            return;
        }
        if ('menu' === $action || '' === $action) {
            self::send_connected_message($chat_id, $linked_user);
            return;
        }
        self::send_target_message($chat_id, $linked_user, $action);
    }

    private static function handle_contact($chat_id, $from_id, $from, $contact) {
        $contact_user_id = self::sanitize_chat_id(isset($contact['user_id']) ? $contact['user_id'] : '');
        if (!$contact_user_id || !hash_equals($from_id, $contact_user_id)) {
            self::send_message($chat_id, __('برای اتصال امن، فقط از دکمه «ارسال شماره موبایل من» استفاده کنید؛ ارسال مخاطب شخص دیگری پذیرفته نمی‌شود.', 'hamnoor'));
            self::send_contact_request($chat_id);
            return;
        }
        $phone = Hamnoor_Validator::normalize_phone(isset($contact['phone_number']) ? (string)$contact['phone_number'] : '');
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            self::send_message($chat_id, __('شماره موبایل دریافت‌شده از بله با قالب شماره موبایل ایران سازگار نیست.', 'hamnoor'), array('remove_keyboard'=>true));
            return;
        }
        $bound_user_id = self::find_bound_user_id_by_bale_user_id($from_id);
        if ($bound_user_id) {
            $bound_user = Hamnoor_Mobile_Access::get_user((int)$bound_user_id);
            $bound_phone = $bound_user ? Hamnoor_Validator::normalize_phone((string)$bound_user->phone) : '';
            if (!$bound_user || !$bound_phone || !hash_equals($bound_phone, $phone)) {
                self::send_message($chat_id, __('شماره این حساب بله با شماره فعلی پنل هم‌نور یکسان نیست. اتصال خودکار برای امنیت غیرفعال می‌ماند؛ از حساب بله‌ای با همان شماره فعلی هم‌نور استفاده کنید.', 'hamnoor'), array('remove_keyboard'=>true));
                return;
            }
            $user = $bound_user;
        } else {
            $user = Hamnoor_Mobile_Access::find_user($phone);
        }
        if (!$user) {
            // A Bale contact is already bound to the sender because contact.user_id
            // was matched to message.from.id above. Hamnoor does not require SMS
            // verification merely to own/use a participant dashboard, so create
            // the SAME Hamnoor account here instead of sending the user to a
            // separate guest/login flow on the website. OTP remains required by
            // Hamnoor_Mobile_Access only for privileged actions such as creating
            // a new khatm.
            $name_parts = array();
            if (!empty($from['first_name'])) { $name_parts[] = sanitize_text_field((string)$from['first_name']); }
            if (!empty($from['last_name'])) { $name_parts[] = sanitize_text_field((string)$from['last_name']); }
            $display_name = trim(implode(' ', array_filter($name_parts)));
            if (!$display_name) { $display_name = __('همراه هم‌نور', 'hamnoor'); }
            $user = Hamnoor_Mobile_Access::ensure_user($phone, $display_name);
            if (is_wp_error($user) || !$user) {
                $message = is_wp_error($user) ? $user->get_error_message() : __('ساخت پنل هم‌نور با شماره تأییدشده بله انجام نشد.', 'hamnoor');
                self::send_message($chat_id, $message, array('remove_keyboard'=>true));
                return;
            }
            Hamnoor_DB::log(0, 0, 0, (int)$user->id, 'bale_user_created', __('پنل هم‌نور از شماره تأییدشده حساب بله ساخته شد.', 'hamnoor'));
        }
        $result = self::connect_user((int)$user->id, $chat_id, array(
            'bale_user_id' => $from_id,
            'phone_verified_by_bale_contact' => true,
            'phone_last4' => substr($phone, -4),
            'first_name' => isset($from['first_name']) ? sanitize_text_field((string)$from['first_name']) : '',
            'last_name' => isset($from['last_name']) ? sanitize_text_field((string)$from['last_name']) : '',
            'username' => isset($from['username']) ? sanitize_key((string)$from['username']) : '',
            'verification' => 'bale_request_contact',
        ), $phone);
        if (is_wp_error($result)) {
            self::send_message($chat_id, $result->get_error_message(), array('remove_keyboard'=>true));
            return;
        }
        self::send_connected_message($chat_id, (int)$user->id, true);
    }

    private static function web_app_button($label, $target) {
        $url = self::miniapp_url($target);
        return array(
            'text' => (string)$label,
            'web_app' => array('url' => $url),
        );
    }

    /** One canonical launcher; identity and notification consent stay independent. */
    private static function connected_keyboard($user_id = 0) {
        $notification_label = self::notifications_enabled((int)$user_id)
            ? __('انصراف از اعلان', 'hamnoor')
            : __('فعال‌سازی اعلان', 'hamnoor');
        return array(
            'keyboard' => array(
                array(self::web_app_button(__('ورود به هم‌نور', 'hamnoor'), 'dashboard')),
                array(array('text'=>$notification_label)),
            ),
            'resize_keyboard' => true,
        );
    }

    private static function reply_miniapp_keyboard($include_contact = false) {
        if (!$include_contact) { return self::connected_keyboard(); }
        return array(
            'keyboard' => array(
                array(self::web_app_button(__('ورود به هم‌نور', 'hamnoor'), 'dashboard')),
            ),
            'resize_keyboard' => true,
        );
    }

    private static function send_contact_request($chat_id) {
        $markup = self::reply_miniapp_keyboard(true);
        return self::send_message(
            $chat_id,
            __("سلام؛ به هم‌نور خوش آمدید 🌱\n\nهم‌نور برای برگزاری و شرکت در پویش‌های ختم قرآن است. می‌توانید سهم قرائت بگیرید، مطالعه را ادامه دهید، پویش بسازید و اعلان‌های مهم را دریافت کنید.\n\nبا دکمه «ورود به هم‌نور» داشبورد کامل داخل بله باز می‌شود. اگر اتصال شما تازه باشد، بله در همان مسیر یک‌بار اجازه دسترسی به شماره همین حساب را درخواست می‌کند؛ کد پیامکی لازم نیست.", 'hamnoor'),
            $markup
        );
    }

    private static function send_connected_message($chat_id, $user_id, $new_connection = false) {
        $text = $new_connection
            ? __('✅ حساب بله شما به هم‌نور متصل شد. با دکمه زیر وارد داشبورد کامل شوید.', 'hamnoor')
            : __('هم‌نور آماده است. با دکمه زیر وارد داشبورد کامل شوید.', 'hamnoor');
        return self::send_message($chat_id, $text, self::connected_keyboard((int)$user_id));
    }

    private static function send_help_message($chat_id, $user_id = 0) {
        if (!$user_id) { return self::send_contact_request($chat_id); }
        $text = __('همه امکانات هم‌نور در داشبورد یکپارچه قرار دارد. دکمه «ورود به هم‌نور» داشبورد کامل را داخل بله باز می‌کند و اعلان‌ها نیز مستقیماً به مقصد مرتبط هدایت می‌شوند.', 'hamnoor');
        return self::send_message($chat_id, $text, self::connected_keyboard((int)$user_id));
    }

    private static function send_target_message($chat_id, $user_id, $target) {
        $target = self::sanitize_miniapp_target($target);
        $labels = array(
            'continue'=>__('ادامه قرائت', 'hamnoor'),
            'parts'=>__('سهم‌های من', 'hamnoor'),
            'public'=>__('پویش‌های عمومی', 'hamnoor'),
            'my_khatms'=>__('پویش‌های من', 'hamnoor'),
            'create'=>__('ایجاد پویش جدید', 'hamnoor'),
            'dashboard'=>__('داشبورد هم‌نور', 'hamnoor'),
            'notifications'=>__('اعلان‌ها', 'hamnoor'),
            'reminders'=>__('یادآوری‌ها', 'hamnoor'),
            'profile'=>__('مشخصات من', 'hamnoor'),
            'progress'=>__('مرکز پیشرفت', 'hamnoor'),
        );
        $label = isset($labels[$target]) ? $labels[$target] : __('باز کردن هم‌نور', 'hamnoor');
        return self::send_message($chat_id, __('برای ورود مستقیم از بله، دکمه زیر را بزنید.', 'hamnoor'), array(
            'inline_keyboard'=>array(array(array(
                'text'=>$label,
                'web_app'=>array('url'=>self::miniapp_url($target)),
            ))),
        ));
    }

    public static function connect_user($user_id, $chat_id, $metadata = array(), $verified_phone = '') {
        global $wpdb;
        $user_id = (int)$user_id;
        $chat_id = self::sanitize_chat_id($chat_id);
        if (!$user_id || !$chat_id) { return new WP_Error('hamnoor_bale_bad_identity', __('شناسه بله برای اتصال معتبر نیست.', 'hamnoor')); }
        $verification = isset($metadata['verification']) ? (string)$metadata['verification'] : '';
        $trusted_source = '';
        if ('bale_request_contact' === $verification) { $trusted_source = 'bale_request_contact'; }
        elseif ('bale_initdata_plus_requestContact' === $verification) { $trusted_source = 'bale_initdata_request_contact'; }
        $verified_phone = Hamnoor_Validator::normalize_phone($verified_phone);
        if ($trusted_source) {
            $verified_user = Hamnoor_Mobile_Access::verify_by_trusted_channel($user_id, $trusted_source, $verified_phone);
            if (is_wp_error($verified_user)) { return $verified_user; }
        }
        $table = Hamnoor_DB::table('notification_channels');
        $other_owner = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE channel='bale' AND external_id=%s AND user_id<>%d LIMIT 1", $chat_id, $user_id));
        if ($other_owner) { return new WP_Error('hamnoor_bale_already_linked', __('این گفت‌وگوی بله قبلاً به پنل دیگری در هم‌نور متصل شده است.', 'hamnoor')); }
        $now = current_time('mysql');
        $meta = is_array($metadata) ? $metadata : array();
        $meta['chat_id'] = $chat_id;
        $meta['verified_at'] = $now;
        if ($verified_phone && Hamnoor_Validator::valid_iran_mobile($verified_phone)) {
            $meta['verified_phone_hash'] = Hamnoor_Mobile_Access::trusted_phone_fingerprint($verified_phone);
            $meta['phone_last4'] = substr($verified_phone, -4);
            $meta['phone_binding_status'] = 'matched';
            unset($meta['phone_binding_reason'], $meta['phone_binding_invalidated_at']);
        }
        if (empty($meta['source'])) { $meta['source'] = 'bale_bot_request_contact'; }
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1", $user_id));
        $data = array(
            'user_id'=>$user_id,
            'channel'=>'bale',
            'enabled'=>1,
            'status'=>'connected',
            'external_id'=>$chat_id,
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'connected_at'=>$now,
            'updated_at'=>$now,
        );
        if ($existing) {
            $ok = $wpdb->update($table, $data, array('id'=>$existing), array('%d','%s','%d','%s','%s','%s','%s','%s'), array('%d'));
            if (false === $ok) { return new WP_Error('hamnoor_bale_link_db', __('ذخیره اتصال بله انجام نشد.', 'hamnoor')); }
        } else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($table, $data, array('%d','%s','%d','%s','%s','%s','%s','%s','%s'));
            if (!$ok) { return new WP_Error('hamnoor_bale_link_db', __('ذخیره اتصال بله انجام نشد.', 'hamnoor')); }
        }
        Hamnoor_Notifications::reset_channels_cache();
        return true;
    }

    private static function notifications_enabled($user_id) {
        global $wpdb;
        $user_id = (int)$user_id;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$user_id || !$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT user_id,channel,enabled,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1", $user_id));
        if (!$row) { return false; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid($user_id, 'bale', $row)) { return false; }
        $meta = json_decode((string)$row->metadata, true);
        if (is_array($meta) && !empty($meta['user_unsubscribed'])) { return false; }
        return 1 === (int)$row->enabled && 'connected' === (string)$row->status;
    }

    public static function enable_notifications($user_id) {
        global $wpdb;
        $user_id = (int)$user_id;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$user_id || !$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,user_id,channel,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1", $user_id));
        if (!$row || empty($row->external_id)) { return false; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid($user_id, 'bale', $row)) { return false; }
        $meta = json_decode((string)$row->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        unset($meta['user_unsubscribed'], $meta['disconnected_at']);
        $meta['notifications_reenabled_at'] = current_time('mysql');
        $ok = $wpdb->update($table, array(
            'enabled'=>1,
            'status'=>'connected',
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
        if (false !== $ok) { Hamnoor_Notifications::reset_channels_cache(); }
        return false !== $ok;
    }

    public static function disconnect_user($user_id) {
        global $wpdb;
        $user_id = (int)$user_id;
        if (!$user_id) { return false; }
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,metadata FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1", $user_id));
        if (!$row) { return true; }
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $meta['disconnected_at'] = current_time('mysql');
        $meta['user_unsubscribed'] = 1;
        return false !== $wpdb->update($table, array(
            'enabled'=>0,
            'status'=>'disabled',
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
    }

    public static function frontend_disconnect() {
        check_admin_referer('hamnoor_bale_disconnect');
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { wp_die(esc_html__('نشست هم‌نور فعال نیست.', 'hamnoor')); }
        self::disconnect_user((int)$user_id);
        $url = add_query_arg('tab', 'reminders', Hamnoor_Helpers::page_url('dashboard'));
        Hamnoor_Helpers::redirect_with_notice($url, __('اعلان‌های بله غیرفعال شد؛ اتصال حساب و سوابق شما حفظ شده است.', 'hamnoor'));
    }

    public static function get_user_id_by_chat($chat_id) {
        return self::get_user_id_by_identity($chat_id, '');
    }

    private static function get_user_id_by_identity($chat_id, $bale_user_id = '') {
        global $wpdb;
        $chat_id = self::sanitize_chat_id($chat_id);
        $bale_user_id = self::sanitize_chat_id($bale_user_id);
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return 0; }

        $row = null;
        if ($chat_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id,user_id,channel,enabled,status,external_id,metadata FROM $table WHERE channel='bale' AND external_id=%s AND status IN ('connected','needs_reconnect','disabled') LIMIT 1",
                $chat_id
            ));
        }
        if (!$row && $bale_user_id) { $row = self::find_channel_row_by_bale_user_id($bale_user_id); }
        if (!$row || 'needs_reconnect' === (string)$row->status) { return 0; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'bale', $row)) { return 0; }

        $meta = json_decode((string)$row->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        $user_unsubscribed = !empty($meta['user_unsubscribed']);
        $updates = array(); $formats = array();
        if (!$user_unsubscribed && ('connected' !== (string)$row->status || 1 !== (int)$row->enabled)) {
            $updates['status']='connected'; $formats[]='%s';
            $updates['enabled']=1; $formats[]='%d';
        }
        if ($chat_id && (string)$row->external_id !== $chat_id) {
            $updates['external_id']=$chat_id; $formats[]='%s';
        }
        if ($updates) {
            $updates['updated_at']=current_time('mysql'); $formats[]='%s';
            $wpdb->update($table,$updates,array('id'=>(int)$row->id),$formats,array('%d'));
            Hamnoor_Notifications::reset_channels_cache();
        }
        return (int)$row->user_id;
    }

    private static function find_channel_row_by_bale_user_id($bale_user_id) {
        global $wpdb;
        $bale_user_id = self::sanitize_chat_id($bale_user_id);
        $table = Hamnoor_DB::table('notification_channels');
        if (!$bale_user_id || !$table) { return null; }

        // In private chats Bale often uses the same numeric id for user/chat.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,user_id,enabled,status,external_id,metadata FROM $table WHERE channel='bale' AND external_id=%s AND status IN ('connected','needs_reconnect','disabled') LIMIT 1",
            $bale_user_id
        ));
        if ($row) { return $row; }

        // Do not rely on the JSON number/string representation. Decode candidate
        // metadata and compare the stored Bale user id in PHP.
        $rows = $wpdb->get_results(
            "SELECT id,user_id,enabled,status,external_id,metadata FROM $table WHERE channel='bale' AND status IN ('connected','needs_reconnect','disabled') AND metadata LIKE '%bale_user_id%' ORDER BY id DESC LIMIT 5000"
        );
        foreach ((array)$rows as $candidate) {
            $meta = json_decode((string)$candidate->metadata, true);
            if (!is_array($meta) || !isset($meta['bale_user_id'])) { continue; }
            if (hash_equals($bale_user_id, self::sanitize_chat_id($meta['bale_user_id']))) { return $candidate; }
        }
        return null;
    }

    private static function find_user_id_by_bale_user_id($bale_user_id) {
        $row = self::find_channel_row_by_bale_user_id($bale_user_id);
        if (!$row || 'needs_reconnect' === (string)$row->status) { return 0; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'bale', $row)) { return 0; }
        $meta = json_decode((string)$row->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        if (empty($meta['user_unsubscribed']) && ('connected' !== (string)$row->status || 1 !== (int)$row->enabled)) {
            global $wpdb;
            $table = Hamnoor_DB::table('notification_channels');
            $wpdb->update($table,array('status'=>'connected','enabled'=>1,'updated_at'=>current_time('mysql')),array('id'=>(int)$row->id),array('%s','%d','%s'),array('%d'));
            Hamnoor_Notifications::reset_channels_cache();
        }
        return (int)$row->user_id;
    }

    private static function find_bound_user_id_by_bale_user_id($bale_user_id) {
        $row = self::find_channel_row_by_bale_user_id($bale_user_id);
        return $row ? (int)$row->user_id : 0;
    }

    private static function verified_phone_hash_for_user($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT metadata FROM $table WHERE user_id=%d AND channel='bale' ORDER BY id DESC LIMIT 1", (int)$user_id));
        if (!$row) { return ''; }
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $hash = strtolower(trim((string)($meta['verified_phone_hash'] ?? '')));
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    private static function user_chat_id($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table || !$user_id) { return ''; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='bale' AND enabled=1 AND status='connected' AND external_id IS NOT NULL AND external_id<>'' LIMIT 1", (int)$user_id));
        if (!$row) { return ''; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$user_id, 'bale', $row)) { return ''; }
        return self::sanitize_chat_id((string)$row->external_id);
    }

    public static function api_call($method, $params = array()) {
        $token = self::token();
        if (!$token) { return new WP_Error('hamnoor_bale_missing_token', __('توکن بازوی بله تنظیم نشده است.', 'hamnoor')); }
        $method = preg_replace('/[^A-Za-z0-9_]/', '', (string)$method);
        if (!$method) { return new WP_Error('hamnoor_bale_bad_method', __('متد API بله معتبر نیست.', 'hamnoor')); }
        $url = self::API_BASE . $token . '/' . $method;
        $args = array(
            'timeout'=>15,
            'redirection'=>0,
            'headers'=>array('Content-Type'=>'application/json; charset=utf-8','Accept'=>'application/json'),
            'body'=>wp_json_encode(is_array($params) ? $params : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'user-agent'=>'Hamnoor-Bale/' . HAMNOOR_BALE_VERSION . '; Core/' . HAMNOOR_VERSION . '; ' . home_url('/'),
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) { return $response; }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok'])) {
            $message = is_array($json) && !empty($json['description']) ? sanitize_text_field((string)$json['description']) : sprintf(__('پاسخ ناموفق از API بله (HTTP %d).', 'hamnoor'), $code);
            return new WP_Error('hamnoor_bale_api_error', $message, array('http_code'=>$code,'error_code'=>is_array($json)&&isset($json['error_code'])?(int)$json['error_code']:0));
        }
        return isset($json['result']) ? $json['result'] : true;
    }

    private static function send_message($chat_id, $text, $reply_markup = array()) {
        $params = array('chat_id'=>self::sanitize_chat_id($chat_id),'text'=>Hamnoor_Validator::trim_len(wp_strip_all_tags((string)$text), 3900));
        if (!empty($reply_markup)) { $params['reply_markup'] = $reply_markup; }
        return self::api_call('sendMessage', $params);
    }

    /**
     * Make the inbound side of Bale operational and verify that Bale itself
     * reports the exact Hamnoor webhook URL. Outbound sendMessage/getMe success
     * alone does not mean /start can ever reach WordPress.
     */
    private static function sync_webhook() {
        self::ensure_webhook_secret();
        $url = self::webhook_url();
        if (!$url || 0 !== strpos($url, 'https://')) {
            return new WP_Error('hamnoor_bale_webhook_https', __('وب‌هوک بله به یک آدرس HTTPS معتبر نیاز دارد.', 'hamnoor'));
        }

        $set = self::api_call('setWebhook', array('url'=>$url));
        if (is_wp_error($set)) {
            $s = self::settings();
            $s['last_webhook_check_at'] = current_time('mysql');
            $s['last_webhook_status'] = 'set_error';
            update_option(self::OPTION, $s, false);
            return $set;
        }

        $info = self::api_call('getWebhookInfo');
        if (is_wp_error($info)) {
            $s = self::settings();
            $s['webhook_set_at'] = current_time('mysql');
            $s['webhook_url'] = $url;
            $s['last_webhook_check_at'] = current_time('mysql');
            $s['last_webhook_status'] = 'verify_error';
            update_option(self::OPTION, $s, false);
            return $info;
        }

        $remote_url = is_array($info) && isset($info['url']) ? esc_url_raw((string)$info['url']) : '';
        $status = $remote_url && hash_equals($url, $remote_url) ? 'ok' : ($remote_url ? 'other' : 'empty');
        $s = self::settings();
        $s['webhook_set_at'] = current_time('mysql');
        $s['webhook_url'] = $url;
        $s['last_webhook_check_at'] = current_time('mysql');
        $s['last_webhook_status'] = $status;
        update_option(self::OPTION, $s, false);

        if ('ok' !== $status) {
            return new WP_Error('hamnoor_bale_webhook_not_confirmed', __('بله ثبت وب‌هوک را تأیید نکرد یا وب‌هوک این بازو به آدرس دیگری اشاره می‌کند.', 'hamnoor'));
        }
        return true;
    }

    public static function admin_save() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bale_save');
        $s = self::settings();
        $s['enabled'] = !empty($_POST['bale_enabled']) ? '1' : '0';
        $s['test_chat_id'] = isset($_POST['bale_test_chat_id']) ? self::sanitize_chat_id(wp_unslash($_POST['bale_test_chat_id'])) : '';
        $username = isset($_POST['bale_bot_username']) ? sanitize_key(ltrim((string)wp_unslash($_POST['bale_bot_username']), '@')) : '';
        if ($username) { $s['bot_username'] = $username; }
        $token_input = isset($_POST['bale_token']) ? trim((string)wp_unslash($_POST['bale_token'])) : '';
        if ($token_input !== '') {
            $valid = self::validate_token($token_input);
            if (is_wp_error($valid)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $valid->get_error_message(), 'error'); }
            $encrypted = self::encrypt_secret($token_input);
            if (is_wp_error($encrypted)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $encrypted->get_error_message(), 'error'); }
            $s['token_enc'] = $encrypted;
            $s['last_getme_at'] = '';
            $s['last_webhook_status'] = '';
        }
        if (empty($s['webhook_secret'])) { $s['webhook_secret'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
        update_option(self::OPTION, $s, false);
        Hamnoor_Notifications::reset_channels_cache();

        // A configured Bale channel is not operational until BOTH directions
        // work. Validate the real token, learn the bot identity, then register
        // and verify the webhook automatically.
        if (self::configured()) {
            $me = self::api_call('getMe');
            if (is_wp_error($me)) {
                Hamnoor_Helpers::redirect_with_notice(self::admin_url(), sprintf(__('تنظیمات ذخیره شد، اما اعتبارسنجی واقعی توکن بله ناموفق بود: %s', 'hamnoor'), $me->get_error_message()), 'error');
            }
            $s = self::settings();
            $s['last_getme_at'] = current_time('mysql');
            $s['bot_name'] = is_array($me) && !empty($me['first_name']) ? sanitize_text_field((string)$me['first_name']) : '';
            if (is_array($me) && !empty($me['username'])) { $s['bot_username'] = sanitize_key((string)$me['username']); }
            update_option(self::OPTION, $s, false);

            $sync = self::sync_webhook();
            if (is_wp_error($sync)) {
                Hamnoor_Helpers::redirect_with_notice(self::admin_url(), sprintf(__('توکن بله معتبر است، اما مسیر دریافت پیام‌ها (/start) آماده نشد: %s', 'hamnoor'), $sync->get_error_message()), 'error');
            }
            Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('تنظیمات بله ذخیره شد؛ توکن و وب‌هوک هر دو تأیید شدند و مسیر دریافت /start فعال است.', 'hamnoor'));
        }

        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('تنظیمات بله ذخیره شد، اما تا زمان ثبت توکن، دریافت /start فعال نخواهد بود.', 'hamnoor'), 'warning');
    }

    public static function admin_test_connection() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bale_test_connection');
        $result = self::api_call('getMe');
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $result->get_error_message(), 'error'); }
        $s = self::settings();
        $s['last_getme_at'] = current_time('mysql');
        $s['bot_name'] = is_array($result) && !empty($result['first_name']) ? sanitize_text_field((string)$result['first_name']) : '';
        if (is_array($result) && !empty($result['username'])) { $s['bot_username'] = sanitize_key((string)$result['username']); }
        update_option(self::OPTION, $s, false);

        $sync = self::sync_webhook();
        if (is_wp_error($sync)) {
            Hamnoor_Helpers::redirect_with_notice(self::admin_url(), sprintf(__('توکن بله معتبر است، اما وب‌هوک دریافت پیام فعال نشد: %s', 'hamnoor'), $sync->get_error_message()), 'error');
        }
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('اتصال API و وب‌هوک بله هر دو موفق و تأیید شدند.', 'hamnoor'));
    }

    public static function admin_set_webhook() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bale_set_webhook');
        $result = self::sync_webhook();
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('وب‌هوک بله ثبت شد و getWebhookInfo همان آدرس هم‌نور را تأیید کرد.', 'hamnoor'));
    }

    public static function admin_webhook_info() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bale_webhook_info');
        $result = self::api_call('getWebhookInfo');
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $result->get_error_message(), 'error'); }
        $s = self::settings();
        $s['last_webhook_check_at'] = current_time('mysql');
        $remote_url = is_array($result) && isset($result['url']) ? esc_url_raw((string)$result['url']) : '';
        $s['last_webhook_status'] = $remote_url && self::webhook_url() && hash_equals(self::webhook_url(), $remote_url) ? 'ok' : ($remote_url ? 'other' : 'empty');
        update_option(self::OPTION, $s, false);
        $message = 'ok' === $s['last_webhook_status'] ? __('وب‌هوک فعال است و به همین سایت اشاره می‌کند.', 'hamnoor') : ($remote_url ? __('وب‌هوک فعال است اما به آدرس دیگری اشاره می‌کند.', 'hamnoor') : __('برای بازوی بله وب‌هوکی ثبت نشده است.', 'hamnoor'));
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $message, 'ok' === $s['last_webhook_status'] ? 'success' : 'warning');
    }

    public static function admin_test_message() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bale_test_message');
        $s = self::settings();
        $chat_id = self::sanitize_chat_id(isset($_POST['bale_test_chat_id']) ? wp_unslash($_POST['bale_test_chat_id']) : $s['test_chat_id']);
        if (!$chat_id) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('شناسه مقصد آزمایشی بله وارد نشده است.', 'hamnoor'), 'error'); }
        $miniapp = self::miniapp_url('dashboard');
        $markup = $miniapp ? array('inline_keyboard'=>array(array(array(
            'text'=>__('تست ورود به هم‌نور', 'hamnoor'),
            'web_app'=>array('url'=>$miniapp),
        )))) : array();
        $result = self::send_message($chat_id, sprintf(__('✅ تست اتصال هم‌نور به بله\nنسخه افزونه مستقل بله: %s\nاین پیام تازه است؛ دکمه زیر مسیر Mini App همین نسخه را آزمایش می‌کند.', 'hamnoor'), HAMNOOR_BALE_VERSION), $markup);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(self::admin_url(), $result->get_error_message(), 'error'); }
        $s['last_test_at'] = current_time('mysql'); update_option(self::OPTION, $s, false);
        Hamnoor_Helpers::redirect_with_notice(self::admin_url(), __('پیام آزمایشی بله ارسال شد.', 'hamnoor'));
    }

    public static function render_admin_section() {
        $s = self::settings();
        $configured = self::configured();
        $webhook_url = self::webhook_url();
        echo '<hr><h2>' . esc_html__('اعلان‌های بله', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('کاربر شماره تأییدشده خود را مستقیماً از بله در اختیار هم‌نور می‌گذارد؛ بنابراین برای اتصال اولیه به دریافت پیامک جداگانه نیاز ندارد.', 'hamnoor') . '</p>';
        if ($configured && 'ok' !== (string)$s['last_webhook_status']) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('دریافت پیام‌های بله هنوز آماده نیست.', 'hamnoor') . '</strong> ' . esc_html__('تنظیمات را دوباره ذخیره کنید یا دکمه «بررسی اتصال بله» را بزنید تا مسیر دریافت پیام به‌صورت خودکار تنظیم و بررسی شود.', 'hamnoor') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_bale_save');
        echo '<input type="hidden" name="action" value="hamnoor_bale_save"><table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('فعال‌سازی بله', 'hamnoor') . '</th><td><label><input type="checkbox" name="bale_enabled" value="1" ' . checked(!empty($s['enabled']), true, false) . '> ' . esc_html__('ارسال اعلان از طریق بله فعال باشد', 'hamnoor') . '</label></td></tr>';
        echo '<tr><th><label for="bale_token">' . esc_html__('توکن بازوی بله', 'hamnoor') . '</label></th><td><input id="bale_token" name="bale_token" type="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($configured ? __('برای حفظ توکن فعلی خالی بگذارید', 'hamnoor') : '123456789:...') . '"><p class="description">' . esc_html__('توکن از @botfather بله دریافت می‌شود و به‌صورت رمز‌شده ذخیره خواهد شد.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="bale_bot_username">' . esc_html__('نام کاربری بازو', 'hamnoor') . '</label></th><td><input id="bale_bot_username" name="bale_bot_username" type="text" class="regular-text" value="' . esc_attr((string)$s['bot_username']) . '" placeholder="hamnoor_bot"><p class="description">' . esc_html__('پس از بررسی اتصال، در صورت وجود به‌صورت خودکار ثبت می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="bale_test_chat_id">' . esc_html__('شناسه دریافت‌کننده بررسی', 'hamnoor') . '</label></th><td><input id="bale_test_chat_id" name="bale_test_chat_id" type="text" class="regular-text" value="' . esc_attr((string)$s['test_chat_id']) . '"><p class="description">' . esc_html__('فقط برای بررسی دستی ارسال پیام استفاده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('ذخیره تنظیمات بله', 'hamnoor'));
        echo '</form>';
        if ($configured) {
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_bale_test_connection'); echo '<input type="hidden" name="action" value="hamnoor_bale_test_connection">'; submit_button(__('بررسی اتصال بله', 'hamnoor'), 'secondary', 'submit', false); echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('تنظیم وب‌هوک، وب‌هوک فعلی این بازو را به هم‌نور تغییر می‌دهد. ادامه؟', 'hamnoor')) . '\');">'; wp_nonce_field('hamnoor_bale_set_webhook'); echo '<input type="hidden" name="action" value="hamnoor_bale_set_webhook">'; submit_button(__('تنظیم وب‌هوک بله', 'hamnoor'), 'secondary', 'submit', false); echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_bale_webhook_info'); echo '<input type="hidden" name="action" value="hamnoor_bale_webhook_info">'; submit_button(__('بررسی وضعیت وب‌هوک', 'hamnoor'), 'secondary', 'submit', false); echo '</form>';
            if (!empty($s['test_chat_id'])) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_bale_test_message'); echo '<input type="hidden" name="action" value="hamnoor_bale_test_message"><input type="hidden" name="bale_test_chat_id" value="' . esc_attr((string)$s['test_chat_id']) . '">'; submit_button(__('ارسال پیام بررسی', 'hamnoor'), 'secondary', 'submit', false); echo '</form>'; }
            echo '</div>';
        }
        echo '<table class="widefat striped" style="margin-top:14px"><tbody>';
        echo '<tr><th>' . esc_html__('وضعیت توکن', 'hamnoor') . '</th><td>' . esc_html($configured ? __('ذخیره شده', 'hamnoor') : __('تنظیم نشده', 'hamnoor')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('بازو', 'hamnoor') . '</th><td><code>' . esc_html(self::bot_username() ? '@' . self::bot_username() : __('نامشخص', 'hamnoor')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('آدرس وب‌هوک هم‌نور', 'hamnoor') . '</th><td><code>' . esc_html($webhook_url ?: __('پس از ذخیره تنظیمات ساخته می‌شود', 'hamnoor')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('آدرس رسمی Main Mini App برای BotFather', 'hamnoor') . '</th><td><code>' . esc_html(self::miniapp_base_url()) . '</code><p class="description"><strong>' . esc_html__('همین URL را برای «دکمه مینی‌اپ اصلی» و «دکمه مینی‌اپ» در BotFather ثبت کنید.', 'hamnoor') . '</strong> ' . esc_html__('این نشانی عمومی و ثابت است و با ارتقای افزونه تغییر نمی‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('نوع ورودی Mini App', 'hamnoor') . '</th><td><strong>' . esc_html__('مسیر عمومی پایدار با پوسته اختصاصی بله', 'hamnoor') . '</strong><p class="description">' . esc_html__('پوسته در ابتدای اجرای وردپرس با هدرهای سازگار با iframe و بدون کش ارائه می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('فایل مستقیم (فقط عیب‌یابی)', 'hamnoor') . '</th><td><code>' . esc_html(self::miniapp_static_shell_url()) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('ورودی پویای قدیمی (فقط عیب‌یابی)', 'hamnoor') . '</th><td><code>' . esc_html(self::miniapp_dynamic_url()) . '</code></td></tr>';
        $main_test = self::main_miniapp_link('dashboard');
        echo '<tr><th>' . esc_html__('لینک تست startapp', 'hamnoor') . '</th><td>' . ($main_test ? '<a href="' . esc_url($main_test) . '" target="_blank" rel="noopener"><code>' . esc_html($main_test) . '</code></a>' : esc_html__('پس از مشخص شدن نام کاربری بازو ساخته می‌شود.', 'hamnoor')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('آخرین بررسی اتصال', 'hamnoor') . '</th><td>' . esc_html((string)($s['last_getme_at'] ?: __('انجام نشده', 'hamnoor'))) . '</td></tr>';
        $webhook_status_label = array(
            'ok'=>__('فعال و تأییدشده', 'hamnoor'),
            'set'=>__('ثبت شده؛ هنوز تأیید نهایی نشده', 'hamnoor'),
            'other'=>__('وب‌هوک به آدرس دیگری اشاره می‌کند', 'hamnoor'),
            'empty'=>__('وب‌هوک ثبت نشده است', 'hamnoor'),
            'set_error'=>__('خطا هنگام ثبت وب‌هوک', 'hamnoor'),
            'verify_error'=>__('ثبت شد؛ خطا هنگام بررسی نهایی', 'hamnoor'),
        );
        $status_key = (string)($s['last_webhook_status'] ?: '');
        $status_text = isset($webhook_status_label[$status_key]) ? $webhook_status_label[$status_key] : ($status_key ?: '—');
        echo '<tr><th>' . esc_html__('وضعیت آخرین بررسی وب‌هوک', 'hamnoor') . '</th><td><strong>' . esc_html($status_text) . '</strong> <code>' . esc_html($status_key ?: '—') . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('آخرین Update دریافتی از بله', 'hamnoor') . '</th><td>' . esc_html((string)($s['last_webhook_hit_at'] ?: __('هنوز هیچ Updateای به هم‌نور نرسیده است', 'hamnoor'))) . '</td></tr>';
        echo '<tr><th>' . esc_html__('آخرین update_id / پیام', 'hamnoor') . '</th><td><code>' . esc_html((string)($s['last_webhook_update_id'] ?: '—')) . '</code>' . (!empty($s['last_webhook_message_text']) ? ' — <code>' . esc_html((string)$s['last_webhook_message_text']) . '</code>' : '') . '</td></tr>';
        echo '<tr><th>' . esc_html__('کاربران متصل', 'hamnoor') . '</th><td>' . (int)self::connected_users_count() . '</td></tr>';
        $consent = Hamnoor_Notification_Dispatcher::channel_consent_counts('bale');
        echo '<tr><th>' . esc_html__('کاربران منصرف‌شده', 'hamnoor') . '</th><td>' . (int)$consent['unsubscribed'] . '</td></tr>';
        echo '</tbody></table>';
    }

    private static function notification_text($notification) {
        $title = !empty($notification->title) ? wp_strip_all_tags((string)$notification->title) : __('یادآوری هم‌نور', 'hamnoor');
        $body = !empty($notification->message) ? wp_strip_all_tags((string)$notification->message) : '';
        return trim('📖 ' . $title . ($body ? "\n\n" . $body : ''));
    }

    private static function notification_target($notification, $context = array()) {
        if (!empty($notification->related_type) && 'part' === (string)$notification->related_type && !empty($notification->related_id)) {
            return 'part_' . (int)$notification->related_id;
        }
        return 'dashboard';
    }

    public static function miniapp_pretty_url() {
        return esc_url_raw(home_url('/' . self::MINIAPP_PATH . '/'));
    }

    public static function miniapp_dynamic_url() {
        // Legacy/dynamic WordPress entry kept as a fallback and for diagnostics.
        // Some hosts attach framing headers after PHP has finished; in Bale Web
        // that can block the iframe before this PHP page ever becomes visible.
        return esc_url_raw(add_query_arg(self::MINIAPP_QUERY_VAR, '1', home_url('/')));
    }

    private static function miniapp_static_shell_url() {
        return esc_url_raw(HAMNOOR_BALE_URL . 'assets/bale-miniapp/index.html');
    }

    public static function miniapp_base_url() {
        // Use the stable public endpoint rather than a physical plugin asset.
        // Plugin-directory URLs can become stale in Bale's saved Main Mini App
        // button after an upgrade/rename and surface only as a generic loading
        // failure. The clean endpoint is served at init priority 0 with explicit
        // iframe/cache headers and remains unchanged across plugin versions.
        return self::miniapp_pretty_url();
    }

    public static function miniapp_url($target = 'dashboard') {
        $target = self::sanitize_miniapp_target($target);
        return esc_url_raw(add_query_arg(array(
            'target' => $target,
            'hmv'    => HAMNOOR_BALE_VERSION,
        ), self::miniapp_base_url()));
    }

    public static function main_miniapp_link($target = 'dashboard') {
        $username = self::bot_username();
        if (!$username) { return ''; }
        $target = self::sanitize_miniapp_target($target);
        return esc_url_raw('https://ble.ir/' . rawurlencode($username) . '?startapp=' . rawurlencode($target));
    }

    private static function sanitize_miniapp_target($target) {
        $target = sanitize_key((string)$target);
        if (preg_match('/^part_([0-9]{1,20})$/', $target, $m)) { return 'part_' . (int)$m[1]; }
        $allowed = array('dashboard','continue','parts','public','my_khatms','create','notifications','reminders','profile','progress','help');
        return in_array($target, $allowed, true) ? $target : 'dashboard';
    }


    /**
     * Compatibility bridge for Bale Main Mini Apps/menu buttons that were
     * previously configured to open the ordinary Hamnoor dashboard URL.
     *
     * Bale can launch any fixed URL as a Mini App. Old bot settings therefore
     * bypassed the canonical hamnoor_bale_miniapp route and left the dashboard
     * behaving like a separate website. On Hamnoor dashboard/create pages we
     * load the official SDK, detect a real Bale Mini App by its non-empty
     * initData, then run the exact same server-side HMAC validation + one-time
     * handoff used by the canonical Mini App route.
     *
     * Ordinary browser visits are untouched because Bale.WebApp.initData is
     * absent there. If the bot token is not configured the auth endpoint fails
     * closed and the legacy direct dashboard is covered by an explicit error.
     */
    public static function render_legacy_miniapp_bridge() {
        if (is_admin() || wp_doing_ajax() || wp_is_json_request()) { return; }

        // The canonical Mini App and one-time handoff already implement their
        // own full-page authentication flow.
        $request_path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string)parse_url($request_path, PHP_URL_PATH), '/');
        $home_path = trim((string)parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path && 0 === strpos($path, $home_path . '/')) { $path = substr($path, strlen($home_path) + 1); }
        elseif ($home_path === $path) { $path = ''; }
        $normalized_path = trim($path, '/');
        if (self::MINIAPP_PATH === $normalized_path || self::HANDOFF_PATH === $normalized_path) { return; }
        if (!empty($_GET[self::MINIAPP_QUERY_VAR]) || !empty($_GET[self::HANDOFF_QUERY_VAR])) { return; }

        // Only protect ordinary Hamnoor account surfaces. Public khatm/list
        // links sent in chats must keep behaving as normal public pages.
        $eligible = false;
        if (is_singular()) {
            global $post;
            if ($post && is_string($post->post_content)) {
                $eligible = has_shortcode($post->post_content, 'hamnoor_dashboard')
                    || has_shortcode($post->post_content, 'hamnoor_create_khatm');
            }
        }
        if (!$eligible) { return; }

        // Once the dedicated Bale session is valid, re-authenticating on every
        // internal navigation would cause a handoff loop. A stale/invalid Bale
        // cookie does not pass is_logged_in(), so it will still be repaired.
        if (!empty($_COOKIE[Hamnoor_Mobile_Access::BALE_COOKIE_NAME]) && Hamnoor_Mobile_Access::is_logged_in()) {
            return;
        }

        $auth_url = rest_url(self::REST_NAMESPACE . '/bale-miniapp/auth');
        $connect_url = rest_url(self::REST_NAMESPACE . '/bale-miniapp/connect');
        ?>
<script src="https://tapi.bale.ai/miniapp.js?3"></script>
<style id="hm-bale-legacy-bridge-style">
html.hm-bale-legacy-auth body>*:not(#hm-bale-legacy-overlay){visibility:hidden!important}
#hm-bale-legacy-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;background:#f7f8fb;color:#172033;font-family:Tahoma,Arial,sans-serif;visibility:visible!important}
#hm-bale-legacy-overlay .hm-card{width:min(520px,100%);background:#fff;border:1px solid #e2e6ef;border-radius:18px;padding:24px;box-sizing:border-box;box-shadow:0 8px 30px rgba(0,0,0,.06);text-align:center}
#hm-bale-legacy-overlay p{line-height:1.9;margin:8px 0}
#hm-bale-legacy-overlay .hm-error{color:#9e2b2b;font-weight:700}
#hm-bale-legacy-overlay .hm-btn{display:inline-block;margin-top:14px;padding:11px 18px;border:0;border-radius:10px;background:#2355a6;color:#fff;cursor:pointer;font:inherit}
#hm-bale-legacy-overlay .hm-spin{width:34px;height:34px;border:3px solid #dce2ec;border-top-color:#2355a6;border-radius:50%;animation:hmBaleSpin .8s linear infinite;margin:18px auto}
@keyframes hmBaleSpin{to{transform:rotate(360deg)}}
</style>
<script>
(function(){
    if (window.__hamnoorBaleLegacyBridgeStarted) { return; }
    var app = window.Bale && window.Bale.WebApp ? window.Bale.WebApp : null;
    if (!app || !app.initData) { return; } // ordinary website visit

    window.__hamnoorBaleLegacyBridgeStarted = true;
    document.documentElement.classList.add('hm-bale-legacy-auth');

    var params = new URLSearchParams(window.location.search || '');
    var target = params.get('tgWebAppStartParam') || params.get('target') || 'dashboard';
    var authUrl = <?php echo wp_json_encode($auth_url); ?>;
    var connectUrl = <?php echo wp_json_encode($connect_url); ?>;

    function esc(v) {
        return String(v || '').replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function ensureOverlay() {
        var box = document.getElementById('hm-bale-legacy-overlay');
        if (box) { return box; }
        if (!document.body) { return null; }
        box = document.createElement('div');
        box.id = 'hm-bale-legacy-overlay';
        box.innerHTML = '<main class="hm-card"><h2>پویش ختم قرآن هم‌نور</h2><div data-hm-status><div class="hm-spin"></div><p>در حال شناسایی امن حساب بله…</p></div><div data-hm-connect hidden><p>در پویش‌های عمومی سهم بگیرید، قرائت را ادامه دهید و پویش تازه بسازید.</p><p>برای ساخت یا بازیابی پنل شخصی، بله فقط یک‌بار شماره همین حساب را در اختیار هم‌نور می‌گذارد.</p><button type="button" class="hm-btn" data-hm-contact>شروع استفاده از هم‌نور</button></div></main>';
        document.body.appendChild(box);
        return box;
    }
    function parts() {
        var box = ensureOverlay();
        return {
            box: box,
            status: box ? box.querySelector('[data-hm-status]') : null,
            connect: box ? box.querySelector('[data-hm-connect]') : null,
            button: box ? box.querySelector('[data-hm-contact]') : null
        };
    }
    function showError(message, allowConnect) {
        var p = parts();
        if (p.status) { p.status.innerHTML = '<p class="hm-error">' + esc(message || 'ورود از بله انجام نشد.') + '</p>'; }
        if (p.connect) { p.connect.hidden = !allowConnect; }
        bindConnect();
    }
    function showLoading(message) {
        var p = parts();
        if (p.status) { p.status.innerHTML = '<div class="hm-spin"></div><p>' + esc(message || 'در حال بررسی…') + '</p>'; }
        if (p.connect) { p.connect.hidden = true; }
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body)
        }).then(function(r){
            return r.json().catch(function(){ return {}; }).then(function(j){
                return {ok:r.ok, data:j};
            });
        });
    }
    function handoff(url) {
        if (!url) { showError('مسیر امن ورود به هم‌نور ساخته نشد.', false); return; }
        showLoading('هویت بله تأیید شد؛ در حال ورود به همان پنل هم‌نور…');
        try { sessionStorage.setItem('hamnoor_bale_authenticated', '1'); } catch(e) {}
        window.location.replace(url);
    }
    function connectWithPhone() {
        if (!app || typeof app.requestContact !== 'function') {
            showError('نسخه بله شما امکان دریافت شماره از Mini App را ندارد. بله را به‌روزرسانی کنید.', false);
            return;
        }
        showLoading('در انتظار تأیید شماره توسط بله…');
        app.requestContact(function(ok, phone){
            if (!ok || !phone) { showError('اتصال انجام نشد؛ اجازه دسترسی به شماره داده نشد.', true); return; }
            var value = (typeof phone === 'string' || typeof phone === 'number')
                ? String(phone)
                : String((phone && (phone.phone_number || phone.phone)) || '');
            if (!value) { showError('شماره موبایل از بله دریافت نشد.', true); return; }
            post(connectUrl, {init_data:app.initData, phone:value, target:target})
                .then(function(x){
                    if (!x.ok || !x.data || !x.data.ok) {
                        showError((x.data && x.data.message) || 'اتصال حساب بله به هم‌نور انجام نشد.', true);
                        return;
                    }
                    handoff(x.data.redirect);
                })
                .catch(function(){ showError('ارتباط با سرور هم‌نور برقرار نشد.', true); });
        });
    }
    function bindConnect() {
        var p = parts();
        if (!p.button || p.button.__hmBound) { return; }
        p.button.__hmBound = true;
        p.button.addEventListener('click', connectWithPhone);
    }
    function boot() {
        ensureOverlay();
        bindConnect();
        try {
            if (app.ready) { app.ready(); }
            if (app.expand) { app.expand(); }
        } catch(e) {}
        showLoading('در حال شناسایی امن حساب بله و ورود به هم‌نور…');
        post(authUrl, {init_data:app.initData, target:target})
            .then(function(x){
                if (x.ok && x.data && x.data.ok && x.data.redirect) { handoff(x.data.redirect); return; }
                var d = x.data || {};
                if (d.needs_connection) {
                    var p = parts();
                    if (p.status) { p.status.innerHTML = '<p>برای اولین استفاده، اتصال امن حساب را تکمیل کنید.</p>'; }
                    if (p.connect) { p.connect.hidden = false; }
                    bindConnect();
                    setTimeout(connectWithPhone, 300);
                    return;
                }
                // This includes the no-token case: never reveal/use the ordinary
                // dashboard as a fallback when the request is a real Bale Mini App.
                showError(d.message || 'اتصال بله در هم‌نور هنوز پیکربندی نشده است.', false);
            })
            .catch(function(){ showError('ارتباط با سرور هم‌نور برقرار نشد.', false); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once:true});
    } else {
        boot();
    }
})();
</script>
        <?php
    }

    public static function maybe_render_miniapp() {
        if (is_admin()) { return; }
        if (isset($_GET['hamnoor_bale_notification']) && '1' === (string)wp_unslash($_GET['hamnoor_bale_notification'])) {
            self::consume_notification_handoff();
        }
        $request_path = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string)parse_url($request_path, PHP_URL_PATH), '/');
        $home_path = trim((string)parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path && 0 === strpos($path, $home_path . '/')) { $path = substr($path, strlen($home_path) + 1); }
        elseif ($home_path === $path) { $path = ''; }
        $normalized_path = trim($path, '/');
        $is_handoff = self::HANDOFF_PATH === $normalized_path
            || '1' === (string)get_query_var(self::HANDOFF_QUERY_VAR)
            || (isset($_GET[self::HANDOFF_QUERY_VAR]) && '1' === (string)wp_unslash($_GET[self::HANDOFF_QUERY_VAR]));
        if ($is_handoff) { self::consume_login_handoff(); }
        $is_route = self::MINIAPP_PATH === $normalized_path
            || '1' === (string)get_query_var(self::MINIAPP_QUERY_VAR);
        $is_fallback = isset($_GET[self::MINIAPP_QUERY_VAR]) && '1' === (string)wp_unslash($_GET[self::MINIAPP_QUERY_VAR]);
        if (!$is_route && !$is_fallback) { return; }

        // 3.9.39 fallback only. On LiteSpeed/Apache the clean URL is handled
        // earlier by the non-WP internal rewrite registered above, so there is
        // no HTTP redirect at all. If the host does not honor external rewrite
        // rules (for example a custom nginx setup), serve the exact same static
        // shell directly from PHP while keeping /hamnoor-bale/ in the address bar.
        if (self::MINIAPP_PATH === $normalized_path) {
            if (!self::configured()) {
                self::render_miniapp_configuration_error();
            }
            $file = HAMNOOR_BALE_PATH . 'assets/bale-miniapp/index.html';
            if (!is_readable($file)) {
                status_header(500);
                self::allow_bale_frame_headers();
                header('Content-Type: text/plain; charset=utf-8', true);
                echo 'Hamnoor Bale Mini App shell is missing.';
                exit;
            }
            nocache_headers();
            self::allow_bale_frame_headers();
            header('Content-Type: text/html; charset=utf-8', true);
            header('X-Hamnoor-Bale-Static: php-fallback-' . HAMNOOR_BALE_VERSION, true);
            readfile($file);
            exit;
        }

        // Fail closed: the Mini App may always be opened by an old message URL,
        // but without the bot token Hamnoor cannot validate Bale.WebApp.initData
        // and therefore must never fall through to a guest dashboard.
        if (!self::configured()) {
            self::render_miniapp_configuration_error();
        }

        nocache_headers(); status_header(200);
        self::allow_bale_frame_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        $auth_url = rest_url(self::REST_NAMESPACE . '/bale-miniapp/auth');
        $connect_url = rest_url(self::REST_NAMESPACE . '/bale-miniapp/connect');
        $bot_url = self::bot_url();
        $target_raw = isset($_GET['tgWebAppStartParam']) ? wp_unslash($_GET['tgWebAppStartParam']) : (isset($_GET['target']) ? wp_unslash($_GET['target']) : 'dashboard');
        $target = self::sanitize_miniapp_target($target_raw);
        ?>
<!doctype html><html lang="fa" dir="rtl"><head>
<meta charset="<?php echo esc_attr(get_option('blog_charset', 'UTF-8')); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?php echo esc_html__('هم‌نور در بله', 'hamnoor'); ?></title>
<script src="https://tapi.bale.ai/miniapp.js?3"></script>
<style>html,body{margin:0;padding:0;background:#f7f8fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.hm-bale-app{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}.hm-bale-card{width:min(520px,100%);background:#fff;border:1px solid #e2e6ef;border-radius:18px;padding:24px;box-shadow:0 8px 30px rgba(0,0,0,.06);text-align:center}.hm-bale-card h1{font-size:22px;margin:0 0 12px}.hm-bale-card p{line-height:1.9;margin:8px 0}.hm-bale-spinner{width:34px;height:34px;border:3px solid #dce2ec;border-top-color:#2355a6;border-radius:50%;animation:hmspin .8s linear infinite;margin:18px auto}.hm-bale-btn{display:inline-block;margin-top:14px;padding:11px 18px;border-radius:10px;background:#2355a6;color:#fff;text-decoration:none}.hm-bale-error{color:#9e2b2b}@keyframes hmspin{to{transform:rotate(360deg)}}</style>
</head><body><div class="hm-bale-app"><main class="hm-bale-card"><h1><?php echo esc_html__('پویش ختم قرآن هم‌نور', 'hamnoor'); ?></h1>
<div id="hm-bale-status"><div class="hm-bale-spinner"></div><p><?php echo esc_html__('در حال شناسایی امن حساب بله و ورود به هم‌نور…', 'hamnoor'); ?></p></div>
<div id="hm-bale-connect" hidden><p><?php echo esc_html__('در پویش‌های عمومی سهم بگیرید، قرائت را ادامه دهید و پویش تازه بسازید. برای ساخت یا بازیابی پنل شخصی، بله فقط یک‌بار اجازه دسترسی به شماره موبایل همین حساب را درخواست می‌کند.', 'hamnoor'); ?></p><button id="hm-bale-contact-btn" class="hm-bale-btn" type="button" style="border:0;cursor:pointer"><?php echo esc_html__('شروع استفاده از هم‌نور', 'hamnoor'); ?></button></div>
</main></div>
<script>(function(){
var status=document.getElementById('hm-bale-status'),connect=document.getElementById('hm-bale-connect'),contactBtn=document.getElementById('hm-bale-contact-btn'),app=null;
function text(v){return String(v||'');}
function fail(msg,need){if(status){status.innerHTML='<p class="hm-bale-error">'+text(msg||'ورود انجام نشد.')+'</p>';}if(connect){connect.hidden=!need;}}
function jsonPost(url,body){return fetch(url,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json().catch(function(){return {};}).then(function(j){return{ok:r.ok,data:j};});});}
function openHandoff(url){if(!url){fail('مقصد هم‌نور مشخص نشد.',false);return;}if(status){status.innerHTML='<div class="hm-bale-spinner"></div><p>اتصال تأیید شد؛ در حال ورود به همان پنل هم‌نور…</p>';}if(connect){connect.hidden=true;}/* Keep the authenticated navigation inside the Mini App itself. Bale Web may send WebApp.openLink() to the system browser, which creates a different browser session. */window.location.replace(url);}
function connectWithPhone(){
 if(!app||typeof app.requestContact!=='function'){fail('نسخه بله شما امکان دریافت شماره از Mini App را ندارد. بله را به‌روزرسانی کنید.',false);return;}
 if(status){status.innerHTML='<div class="hm-bale-spinner"></div><p>در انتظار تأیید شماره توسط بله…</p>';}if(connect){connect.hidden=true;}
 app.requestContact(function(ok,phone){
   if(!ok||!phone){fail('اتصال انجام نشد؛ اجازه دسترسی به شماره داده نشد.',true);return;}
   var p=(typeof phone==='string'||typeof phone==='number')?String(phone):(phone.phone_number||phone.phone||'');
   if(!p){fail('شماره موبایل از بله دریافت نشد.',true);return;}
   jsonPost(<?php echo wp_json_encode($connect_url); ?>,{init_data:app.initData,phone:p,target:<?php echo wp_json_encode($target); ?>}).then(function(x){
     if(!x.ok||!x.data||!x.data.ok){
       fail((x.data&&x.data.message)||'اتصال حساب بله به هم‌نور انجام نشد.',true);
       if(x.data&&x.data.login_url&&status){var a=document.createElement('a');a.className='hm-bale-btn';a.href=String(x.data.login_url);a.textContent='ورود با شماره فعلی هم‌نور';status.appendChild(a);}
       return;
     }
     openHandoff(x.data.redirect);
   }).catch(function(){fail('ارتباط با سرور هم‌نور برقرار نشد.',true);});
 });
}
try{
 if(!window.Bale||!window.Bale.WebApp||!window.Bale.WebApp.initData){fail('این صفحه باید از داخل Mini App رسمی بله باز شود.',false);return;}
 app=window.Bale.WebApp;if(app.ready){app.ready();}if(app.expand){app.expand();}
 if(contactBtn){contactBtn.addEventListener('click',connectWithPhone);}
 jsonPost(<?php echo wp_json_encode($auth_url); ?>,{init_data:app.initData,target:<?php echo wp_json_encode($target); ?>}).then(function(x){
   if(x.ok&&x.data&&x.data.ok&&x.data.redirect){openHandoff(x.data.redirect);return;}
   var d=x.data||{};
   if(d.needs_connection){if(status){status.innerHTML='<p>برای اولین استفاده، اتصال امن حساب را تکمیل کنید.</p>';}if(connect){connect.hidden=false;}setTimeout(connectWithPhone,300);return;}
   fail(d.message||'ورود از بله انجام نشد.',false);
 }).catch(function(){fail('ارتباط با سرور هم‌نور برقرار نشد.',false);});
}catch(e){fail('راه‌اندازی Mini App بله انجام نشد.',false);}
})();</script>
</body></html>
        <?php exit;
    }

    public static function miniapp_auth_callback(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $init_data = is_array($payload) && isset($payload['init_data']) ? (string)$payload['init_data'] : '';
        $target = is_array($payload) && isset($payload['target']) ? self::sanitize_miniapp_target($payload['target']) : 'dashboard';
        $verified = self::validate_miniapp_init_data($init_data);
        if (is_wp_error($verified)) { return new WP_REST_Response(array('ok'=>false,'message'=>$verified->get_error_message()), 403); }
        $bale_user_id = isset($verified['user']['id']) ? self::sanitize_chat_id($verified['user']['id']) : '';
        if (!$bale_user_id) { return new WP_REST_Response(array('ok'=>false,'message'=>__('شناسه کاربر بله در داده معتبر وجود ندارد.', 'hamnoor')), 403); }
        $user_id = self::find_user_id_by_bale_user_id($bale_user_id);
        if (!$user_id) { return new WP_REST_Response(array('ok'=>false,'needs_connection'=>true,'message'=>__('برای این حساب بله هنوز اتصال تأییدشده‌ای در هم‌نور ثبت نشده است.', 'hamnoor')), 404); }
        $user = Hamnoor_Mobile_Access::get_user($user_id);
        if (!$user) { return new WP_REST_Response(array('ok'=>false,'message'=>__('پنل هم‌نور این حساب در دسترس نیست.', 'hamnoor')), 404); }
        // Do not set the Hamnoor cookie inside fetch(). Some Bale WebViews do
        // not persist Set-Cookie from a background request. Instead return a
        // short-lived, one-time handoff URL. The top-level GET consumes it,
        // creates the session cookie, then redirects to the actual destination.
        $handoff = self::create_login_handoff(
            (int)$user_id,
            $target,
            'bale_signed_initdata_handoff',
            300,
            'bale_cross_site',
            self::verified_phone_hash_for_user((int)$user_id)
        );
        if (is_wp_error($handoff)) { return new WP_REST_Response(array('ok'=>false,'message'=>$handoff->get_error_message()), 500); }
        return new WP_REST_Response(array('ok'=>true,'redirect'=>$handoff), 200);
    }

    public static function miniapp_connect_callback(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $init_data = is_array($payload) && isset($payload['init_data']) ? (string)$payload['init_data'] : '';
        $phone_raw = is_array($payload) && isset($payload['phone']) ? (string)$payload['phone'] : '';
        $target = is_array($payload) && isset($payload['target']) ? self::sanitize_miniapp_target($payload['target']) : 'dashboard';

        // The Bale identity is trusted only after the documented initData HMAC
        // validation. The phone is requested by the official Bale WebApp SDK.
        $verified = self::validate_miniapp_init_data($init_data);
        if (is_wp_error($verified)) {
            return new WP_REST_Response(array('ok'=>false,'message'=>$verified->get_error_message()), 403);
        }
        $bale_user_id = isset($verified['user']['id']) ? self::sanitize_chat_id($verified['user']['id']) : '';
        if (!$bale_user_id) {
            return new WP_REST_Response(array('ok'=>false,'message'=>__('شناسه کاربر بله در داده معتبر وجود ندارد.', 'hamnoor')), 403);
        }
        $phone = Hamnoor_Validator::normalize_phone($phone_raw);
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            return new WP_REST_Response(array('ok'=>false,'message'=>__('شماره دریافت‌شده از بله با قالب موبایل ایران سازگار نیست.', 'hamnoor')), 422);
        }

        // A previously bound Bale identity may only reconnect to the SAME
        // Hamnoor account, and only when Bale's freshly returned phone matches
        // that account's current phone. Never transfer ownership silently.
        $bound_user_id = self::find_bound_user_id_by_bale_user_id($bale_user_id);
        if ($bound_user_id) {
            $user = Hamnoor_Mobile_Access::get_user((int)$bound_user_id);
            $current_phone = $user ? Hamnoor_Validator::normalize_phone((string)$user->phone) : '';
            if (!$user || !$current_phone || !hash_equals($current_phone, $phone)) {
                return new WP_REST_Response(array(
                    'ok'=>false,
                    'message'=>__('شماره این حساب بله با شماره فعلی پنل هم‌نور یکسان نیست. ورود خودکار بله برای امنیت غیرفعال می‌ماند؛ با شماره فعلی هم‌نور وارد شوید یا از حساب بله‌ای با همان شماره استفاده کنید.', 'hamnoor'),
                    'login_url'=>Hamnoor_Helpers::page_url('dashboard'),
                ), 409);
            }
            $user_id = (int)$bound_user_id;
            $chat_id = self::user_chat_id($user_id);
            if (!$chat_id) { $chat_id = $bale_user_id; }
            $wu = isset($verified['user']) && is_array($verified['user']) ? $verified['user'] : array();
            $linked = self::connect_user($user_id, $chat_id, array(
                'bale_user_id'=>$bale_user_id,
                'source'=>'bale_miniapp_requestContact',
                'phone_from_bale_miniapp'=>true,
                'phone_last4'=>substr($phone, -4),
                'first_name'=>isset($wu['first_name']) ? sanitize_text_field((string)$wu['first_name']) : '',
                'last_name'=>isset($wu['last_name']) ? sanitize_text_field((string)$wu['last_name']) : '',
                'username'=>isset($wu['username']) ? sanitize_key((string)$wu['username']) : '',
                'verification'=>'bale_initdata_plus_requestContact',
            ), $phone);
            if (is_wp_error($linked)) { return new WP_REST_Response(array('ok'=>false,'message'=>$linked->get_error_message()), 409); }
        } else {
            $wu = isset($verified['user']) && is_array($verified['user']) ? $verified['user'] : array();
            $name_parts = array();
            if (!empty($wu['first_name'])) { $name_parts[] = sanitize_text_field((string)$wu['first_name']); }
            if (!empty($wu['last_name'])) { $name_parts[] = sanitize_text_field((string)$wu['last_name']); }
            $display_name = trim(implode(' ', array_filter($name_parts)));
            if (!$display_name) { $display_name = __('همراه هم‌نور', 'hamnoor'); }
            $user = Hamnoor_Mobile_Access::ensure_user($phone, $display_name);
            if (is_wp_error($user)) { return new WP_REST_Response(array('ok'=>false,'message'=>$user->get_error_message()), 422); }
            if (!$user) { return new WP_REST_Response(array('ok'=>false,'message'=>__('پنل هم‌نور ایجاد یا پیدا نشد.', 'hamnoor')), 500); }
            $user_id = (int)$user->id;

            $chat_id = self::user_chat_id($user_id);
            if (!$chat_id) { $chat_id = $bale_user_id; }
            $linked = self::connect_user($user_id, $chat_id, array(
                'bale_user_id'=>$bale_user_id,
                'source'=>'bale_miniapp_requestContact',
                'phone_from_bale_miniapp'=>true,
                'phone_last4'=>substr($phone, -4),
                'first_name'=>isset($wu['first_name']) ? sanitize_text_field((string)$wu['first_name']) : '',
                'last_name'=>isset($wu['last_name']) ? sanitize_text_field((string)$wu['last_name']) : '',
                'username'=>isset($wu['username']) ? sanitize_key((string)$wu['username']) : '',
                'verification'=>'bale_initdata_plus_requestContact',
            ), $phone);
            if (is_wp_error($linked)) { return new WP_REST_Response(array('ok'=>false,'message'=>$linked->get_error_message()), 409); }
            Hamnoor_DB::log(0,0,0,$user_id,'bale_miniapp_connected',__('حساب بله از Mini App رسمی و با تطبیق شماره فعلی هم‌نور متصل شد.', 'hamnoor'));
        }

        // This request has both a valid Bale initData signature and the phone
        // returned by Bale's official requestContact API. Existing connections
        // created before this policy are upgraded here as well.
        $verified_user = Hamnoor_Mobile_Access::verify_by_trusted_channel($user_id, 'bale_initdata_request_contact', $phone);
        if (is_wp_error($verified_user)) { return new WP_REST_Response(array('ok'=>false,'message'=>$verified_user->get_error_message()), 500); }

        $handoff = self::create_login_handoff(
            $user_id,
            $target,
            'bale_initdata_request_contact',
            300,
            'bale_cross_site',
            Hamnoor_Mobile_Access::trusted_phone_fingerprint($phone)
        );
        if (is_wp_error($handoff)) { return new WP_REST_Response(array('ok'=>false,'message'=>$handoff->get_error_message()), 500); }
        return new WP_REST_Response(array('ok'=>true,'redirect'=>$handoff), 200);
    }

    private static function create_login_handoff($user_id, $target, $sensitive_source = '', $ttl = 300, $cookie_context = 'bale_cross_site', $sensitive_phone_hash = '') {
        $user_id = (int)$user_id;
        if (!$user_id) { return new WP_Error('hamnoor_bale_handoff_user', __('کاربر هم‌نور برای ورود از بله معتبر نیست.', 'hamnoor')); }
        try { $ticket = bin2hex(random_bytes(32)); }
        catch (Throwable $e) { $ticket = hash('sha256', wp_generate_password(64,true,true) . microtime(true) . wp_rand()); }
        $key = 'hamnoor_bale_handoff_' . hash('sha256', $ticket);
        $ttl = max(MINUTE_IN_SECONDS, min(7 * DAY_IN_SECONDS, (int)$ttl));
        $sensitive_phone_hash = strtolower(trim((string)$sensitive_phone_hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $sensitive_phone_hash)) { $sensitive_phone_hash = ''; }
        $payload = array(
            'user_id'=>$user_id,
            'target'=>self::sanitize_miniapp_target($target),
            'created'=>time(),
            'sensitive_source'=>sanitize_key((string)$sensitive_source),
            'sensitive_phone_hash'=>$sensitive_phone_hash,
            'cookie_context'=>sanitize_key((string)$cookie_context),
        );
        if (!set_transient($key, $payload, $ttl)) {
            return new WP_Error('hamnoor_bale_handoff_store', __('ساخت مسیر امن ورود از بله انجام نشد.', 'hamnoor'));
        }
        return esc_url_raw(add_query_arg(array(self::HANDOFF_QUERY_VAR=>'1','ticket'=>rawurlencode($ticket)), home_url('/')));
    }

    /**
     * Durable signed link for notification buttons.
     *
     * Unlike a Mini App launch it does not depend on Bale's configured Main
     * Mini App URL. Bale opens this ordinary HTTPS link in its internal browser,
     * where Hamnoor establishes a normal first-party session and redirects to
     * the intended screen.
     */
    private static function notification_handoff_url($user_id, $target) {
        // Notification links are authentication credentials, so new messages use
        // a one-time, expiring handoff rather than a replayable permanent HMAC URL.
        $url = self::create_login_handoff((int)$user_id, self::sanitize_miniapp_target($target), '', 7 * DAY_IN_SECONDS, 'default');
        return is_wp_error($url) ? '' : $url;
    }

    private static function consume_notification_handoff() {
        nocache_headers();
        $user_id = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
        $target = isset($_GET['target']) ? self::sanitize_miniapp_target(wp_unslash($_GET['target'])) : 'dashboard';
        $provided = isset($_GET['sig']) ? strtolower(trim((string)wp_unslash($_GET['sig']))) : '';
        $expected = hash_hmac('sha256', $user_id . '|' . $target, wp_salt('auth'));
        if (!$user_id || !preg_match('/^[a-f0-9]{64}$/', $provided) || !hash_equals($expected, $provided)) {
            self::render_handoff_error(__('لینک ورود بله معتبر نیست.', 'hamnoor'));
        }
        // Legacy permanent links remain routable but are no longer bearer-login
        // credentials. Existing matching sessions can continue directly; a new
        // browser must recover the dashboard normally with its phone number.
        if (Hamnoor_Mobile_Access::current_user_id() === (int)$user_id) {
            wp_safe_redirect(self::target_redirect_url($user_id, $target), 302, 'Hamnoor Bale legacy notification');
            exit;
        }
        Hamnoor_Helpers::redirect_with_notice(
            Hamnoor_Helpers::page_url('dashboard'),
            __('برای امنیت، لینک قدیمی اعلان دیگر به‌تنهایی وارد حساب نمی‌شود. شماره موبایل همان پنل را وارد کنید؛ سپس سهم‌ها و ادامه قرائت در داشبورد در دسترس است.', 'hamnoor'),
            'warning'
        );
    }

    private static function consume_login_handoff() {
        nocache_headers();
        self::allow_bale_frame_headers();
        $ticket = isset($_GET['ticket']) ? trim((string)wp_unslash($_GET['ticket'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/i', $ticket)) { self::render_handoff_error(__('لینک ورود بله معتبر نیست.', 'hamnoor')); }
        $key = 'hamnoor_bale_handoff_' . hash('sha256', $ticket);
        $payload = get_transient($key);
        delete_transient($key); // strictly one-time, including failed attempts
        if (!is_array($payload) || empty($payload['user_id'])) { self::render_handoff_error(__('این لینک ورود بله منقضی شده یا قبلاً استفاده شده است. دوباره از پیام بله وارد شوید.', 'hamnoor')); }
        $user_id=(int)$payload['user_id']; $target=isset($payload['target'])?self::sanitize_miniapp_target($payload['target']):'dashboard';
        $user=Hamnoor_Mobile_Access::get_user($user_id);
        if(!$user){ self::render_handoff_error(__('پنل هم‌نور این حساب در دسترس نیست.', 'hamnoor')); }
        // The handoff intentionally stays inside the Bale Mini App. On Bale Web the
        // Mini App is an iframe, so use a dedicated cross-site/partitioned
        // Hamnoor session cookie instead of moving the user to another browser.
        $cookie_context = !empty($payload['cookie_context']) && 'default' === (string)$payload['cookie_context'] ? 'default' : 'bale_cross_site';
        $logged=Hamnoor_Mobile_Access::login($user,true,$cookie_context);
        if(is_wp_error($logged)){ self::render_handoff_error($logged->get_error_message()); }
        if (!empty($payload['sensitive_source']) && !empty($payload['sensitive_phone_hash'])) {
            Hamnoor_Mobile_Access::grant_sensitive_access_from_linked_channel(
                $user_id,
                (string)$payload['sensitive_source'],
                (string)$payload['sensitive_phone_hash']
            );
        }
        Hamnoor_Mobile_Access::protect_session_from_page_cache();
        $redirect=self::target_redirect_url($user_id,$target);
        // Force the very first authenticated dashboard request to bypass any
        // stale public/guest cache that may predate the Hamnoor vary-cookie rule.
        try { $cache_buster = bin2hex(random_bytes(6)); }
        catch (Throwable $e) { $cache_buster = substr(hash('sha256', microtime(true) . wp_rand()), 0, 12); }
        $redirect=add_query_arg(array('hm_bale_login'=>'1','hm_nocache'=>$cache_buster),$redirect);
        Hamnoor_DB::log(0,0,0,$user_id,'bale_miniapp_login',__('ورود امن از مینی‌اپ بله با handoff یک‌بارمصرف و نشست اختصاصی Mini App انجام شد.', 'hamnoor'));
        self::render_handoff_success($redirect);
    }

    private static function latest_reading_url_for_user($user_id) {
        global $wpdb;
        $user_id = (int)$user_id;
        if (!$user_id || !class_exists('Hamnoor_Reading') || !Hamnoor_Reading::available()) { return ''; }
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $khatms = Hamnoor_DB::table('khatms');
        $progress = Hamnoor_DB::table('reading_progress');
        if (!$parts || !$participants || !$khatms || !$progress) { return ''; }
        $part_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT p.id FROM $parts p
             INNER JOIN $participants pa ON pa.id=p.participant_id
             INNER JOIN $khatms k ON k.id=p.khatm_id
             LEFT JOIN $progress rp ON rp.part_id=p.id
             WHERE p.status='claimed' AND pa.user_id=%d AND k.status IN ('active','published','completed')
             ORDER BY COALESCE(rp.last_read_at,p.claimed_at) DESC, p.id DESC LIMIT 1",
            $user_id
        ));
        return $part_id ? Hamnoor_Reading::reading_url($part_id) : '';
    }

    private static function target_redirect_url($user_id, $target) {
        $target = self::sanitize_miniapp_target($target);
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        $redirect = $dashboard;
        if (preg_match('/^part_([0-9]+)$/', $target, $m)) {
            $part_id=(int)$m[1]; $part=Hamnoor_DB::get_part($part_id);
            if($part && !empty($part->participant_user_id) && (int)$part->participant_user_id===(int)$user_id){
                return Hamnoor_Reading::reading_url($part_id);
            }
            return add_query_arg('tab','my-parts',$dashboard);
        }
        switch ($target) {
            case 'continue':
                $reading = self::latest_reading_url_for_user((int)$user_id);
                return $reading ?: Hamnoor_Helpers::page_url('list');
            case 'parts': return add_query_arg('tab','my-parts',$dashboard);
            case 'public': return Hamnoor_Helpers::page_url('list');
            case 'my_khatms': return add_query_arg('tab','my-khatms',$dashboard);
            case 'create': return Hamnoor_Helpers::page_url('create');
            case 'notifications': return add_query_arg('tab','notifications',$dashboard);
            case 'reminders': return add_query_arg('tab','reminders',$dashboard);
            case 'profile': return add_query_arg('tab','profile',$dashboard);
            case 'progress': return add_query_arg('tab','progress',$dashboard);
            case 'help': return add_query_arg('tab','help',$dashboard);
            case 'dashboard':
            default: return $dashboard;
        }
    }

    private static function render_handoff_success($redirect) {
        nocache_headers();
        self::allow_bale_frame_headers();
        status_header(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_option('blog_charset','UTF-8'));
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }
        do_action('litespeed_control_set_nocache', 'Hamnoor Bale login handoff');
        $redirect = Hamnoor_Helpers::safe_redirect_url($redirect, Hamnoor_Helpers::page_url('dashboard'));
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>هم‌نور</title><style>body{font-family:Tahoma,Arial,sans-serif;background:#f7f8fb;color:#172033;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.c{background:#fff;border:1px solid #e2e6ef;border-radius:16px;padding:24px;text-align:center;max-width:520px}.s{width:34px;height:34px;border:3px solid #dce2ec;border-top-color:#2355a6;border-radius:50%;animation:r .8s linear infinite;margin:14px auto}@keyframes r{to{transform:rotate(360deg)}}a{display:inline-block;margin-top:12px}</style></head><body><main class="c"><div class="s"></div><h2>ورود به هم‌نور انجام شد</h2><p>در حال انتقال به داشبورد شما…</p><a href="' . esc_url($redirect) . '">اگر منتقل نشدید، اینجا بزنید</a></main><script>window.setTimeout(function(){window.location.replace(' . wp_json_encode($redirect) . ');},120);</script></body></html>';
        exit;
    }

    private static function render_miniapp_configuration_error() {
        nocache_headers();
        self::allow_bale_frame_headers();
        status_header(503);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_option('blog_charset','UTF-8'));
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }
        do_action('litespeed_control_set_nocache', 'Hamnoor Bale Mini App is not configured');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>هم‌نور</title><style>body{font-family:Tahoma,Arial,sans-serif;background:#f7f8fb;color:#172033;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.c{background:#fff;border:1px solid #e2e6ef;border-radius:16px;padding:24px;text-align:center;max-width:560px}.e{color:#9e2b2b;font-weight:700}</style></head><body><main class="c"><h2>هم‌نور در بله</h2><p class="e">اتصال بله هنوز در هم‌نور پیکربندی نشده است.</p><p>تا زمانی که توکن همان بازوی بله در تنظیمات هم‌نور ثبت و اعتبارسنجی نشود، هویت بله قابل اعتماد نیست و ورود خودکار به داشبورد انجام نمی‌شود.</p></main></body></html>';
        exit;
    }

    private static function render_handoff_error($message) {
        nocache_headers(); self::allow_bale_frame_headers(); status_header(403); header('Content-Type: text/html; charset=' . get_option('blog_charset','UTF-8'));
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>هم‌نور</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:28px;line-height:2"><h2>هم‌نور</h2><p>' . esc_html((string)$message) . '</p><p><a href="' . esc_url(self::bot_url()) . '">بازگشت به بله</a></p></body></html>'; exit;
    }

    private static function mark_channel_healthy($user_id) {
        global $wpdb; $table=Hamnoor_DB::table('notification_channels');
        if(!$table || !(int)$user_id){return;}
        $row=$wpdb->get_row($wpdb->prepare("SELECT metadata,status FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1",(int)$user_id));
        $meta=$row ? json_decode((string)$row->metadata,true) : array();
        if (($row && 'disabled' === (string)$row->status) || (is_array($meta) && !empty($meta['user_unsubscribed']))) { return; }
        $wpdb->update($table,array('status'=>'connected','enabled'=>1,'updated_at'=>current_time('mysql')),array('user_id'=>(int)$user_id,'channel'=>'bale'),array('%s','%d','%s'),array('%d','%s'));
        Hamnoor_Notifications::reset_channels_cache();
    }

    private static function validate_miniapp_init_data($init_data) {
        $init_data = trim((string)$init_data); $token = self::token();
        if (!$init_data || !$token) { return new WP_Error('hamnoor_bale_miniapp_missing', __('داده ورود بله یا توکن بازو در دسترس نیست.', 'hamnoor')); }
        parse_str($init_data, $data);
        if (!is_array($data) || empty($data['hash']) || empty($data['auth_date']) || empty($data['user'])) { return new WP_Error('hamnoor_bale_miniapp_invalid', __('ساختار داده ورود مینی‌اپ بله معتبر نیست.', 'hamnoor')); }
        $received_hash=strtolower((string)$data['hash']); unset($data['hash']); ksort($data,SORT_STRING); $pairs=array();
        foreach($data as $key=>$value){ if(is_array($value)){$value=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} $pairs[]=(string)$key.'='.(string)$value; }
        $data_check_string=implode("\n",$pairs); $secret_key=hash_hmac('sha256',$token,'WebAppData',true); $calculated=hash_hmac('sha256',$data_check_string,$secret_key);
        if(!preg_match('/^[a-f0-9]{64}$/i',$received_hash)||!hash_equals($received_hash,strtolower($calculated))){ return new WP_Error('hamnoor_bale_miniapp_hash', __('امضای داده مینی‌اپ بله معتبر نیست.', 'hamnoor')); }
        $auth_date=(int)$data['auth_date']; $age=time()-$auth_date; if($auth_date<=0||$age < -300||$age > HOUR_IN_SECONDS){ return new WP_Error('hamnoor_bale_miniapp_expired', __('داده ورود مینی‌اپ بله منقضی شده است؛ صفحه را دوباره از بله باز کنید.', 'hamnoor')); }
        $user=json_decode((string)$data['user'],true); if(!is_array($user)||empty($user['id'])){ return new WP_Error('hamnoor_bale_miniapp_user', __('اطلاعات کاربر بله معتبر نیست.', 'hamnoor')); }
        return array('user'=>$user,'auth_date'=>$auth_date,'query_id'=>isset($data['query_id'])?(string)$data['query_id']:'');
    }

    private static function maybe_mark_send_failure($user_id, WP_Error $error) {
        $data = $error->get_error_data();
        $http = is_array($data) && isset($data['http_code']) ? (int)$data['http_code'] : 0;
        $message_lc = strtolower((string)$error->get_error_message());
        // A transient 404/chat-not-found must not force the user to share
        // their phone again. The mapping is only disabled for explicit
        // permission/blocked failures; /stop remains the user-controlled way
        // to disconnect.
        $terminal = (403 === $http)
            || false !== strpos($message_lc, 'blocked')
            || false !== strpos($message_lc, 'forbidden');
        if (!$terminal) { return; }
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,metadata FROM $table WHERE user_id=%d AND channel='bale' LIMIT 1", (int)$user_id));
        if (!$row) { return; }
        $meta = json_decode((string)$row->metadata, true); if (!is_array($meta)) { $meta = array(); }
        $meta['last_error'] = Hamnoor_Validator::trim_len($error->get_error_message(), 500);
        $meta['last_error_at'] = current_time('mysql');
        $meta['provider_reconnect_required'] = 1;
        $meta['provider_reconnect_reason'] = 'permission_or_blocked';
        $wpdb->update($table, array('status'=>'needs_reconnect','enabled'=>0,'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')), array('id'=>(int)$row->id), array('%s','%d','%s','%s'), array('%d'));
    }

    private static function sanitize_chat_id($value) {
        $value = Hamnoor_Validator::normalize_digits(trim((string)$value));
        return preg_match('/^-?[0-9]{1,20}$/', $value) ? $value : '';
    }

    private static function validate_token($token) {
        $token = trim((string)$token);
        if (strlen($token) < 20 || strlen($token) > 512 || !preg_match('/^[0-9]{4,20}:[A-Za-z0-9_-]{10,480}$/', $token)) {
            return new WP_Error('hamnoor_bale_bad_token', __('ساختار توکن بازوی بله معتبر به نظر نمی‌رسد.', 'hamnoor'));
        }
        return true;
    }

    private static function encryption_key() {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . '|hamnoor-bale';
        return hash('sha256', $material, true);
    }

    private static function encrypt_secret($plain) {
        if (!extension_loaded('openssl')) { return new WP_Error('hamnoor_bale_openssl', __('OpenSSL برای ذخیره امن توکن بله لازم است.', 'hamnoor')); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'hamnoor-bale', 16);
        if (false === $cipher) { return new WP_Error('hamnoor_bale_encrypt', __('رمزنگاری توکن بله انجام نشد.', 'hamnoor')); }
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt_secret($encoded) {
        if (!extension_loaded('openssl') || !$encoded) { return ''; }
        $raw = base64_decode((string)$encoded, true);
        if (false === $raw || strlen($raw) < 29) { return ''; }
        $iv = substr($raw,0,12); $tag = substr($raw,12,16); $cipher = substr($raw,28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'hamnoor-bale');
        return is_string($plain) ? $plain : '';
    }

    private static function admin_url() { return add_query_arg('channel', 'bale', admin_url('admin.php?page=hamnoor-notifications')); }
}
