<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Official Eitaa Mini-App bridge.
 *
 * Trust model:
 * - window.Eitaa.WebApp.initData is validated server-side with the official
 *   HMAC-SHA256 algorithm and the dedicated Eitaa Program token.
 * - requestContact response is also validated with the same signed-data flow.
 * - requestWriteAccess is practically confirmed by a successful server-side
 *   /api/app/sendMessage call before the channel is enabled.
 * - Raw initData/contact payloads are never persisted.
 */
class Hamnoor_Eitaa_Bridge {
    const QUERY_VAR = 'hamnoor_eitaa_bridge';
    const STATUS_OPTION = 'hamnoor_eitaa_bridge_last_status';
    const FLOW_TTL = 600;

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrite'), 5);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render'), 0);

        add_action('wp_ajax_hamnoor_eitaa_validate_init', array(__CLASS__, 'ajax_validate_init'));
        add_action('wp_ajax_nopriv_hamnoor_eitaa_validate_init', array(__CLASS__, 'ajax_validate_init'));
        add_action('wp_ajax_hamnoor_eitaa_connect_contact', array(__CLASS__, 'ajax_connect_contact'));
        add_action('wp_ajax_nopriv_hamnoor_eitaa_connect_contact', array(__CLASS__, 'ajax_connect_contact'));
        add_action('wp_ajax_hamnoor_eitaa_confirm_write_access', array(__CLASS__, 'ajax_confirm_write_access'));
        add_action('wp_ajax_nopriv_hamnoor_eitaa_confirm_write_access', array(__CLASS__, 'ajax_confirm_write_access'));
        add_action('wp_ajax_hamnoor_eitaa_unsubscribe', array(__CLASS__, 'ajax_unsubscribe'));
        add_action('wp_ajax_nopriv_hamnoor_eitaa_unsubscribe', array(__CLASS__, 'ajax_unsubscribe'));
    }

    public static function register_rewrite() {
        add_rewrite_rule('^hamnoor-eitaa/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    public static function query_vars($vars) { $vars[] = self::QUERY_VAR; return $vars; }
    public static function url() { return home_url('/hamnoor-eitaa/'); }
    public static function fallback_url() { return add_query_arg(self::QUERY_VAR, '1', home_url('/')); }

    private static function is_bridge_request() {
        if ('1' === (string)get_query_var(self::QUERY_VAR)) { return true; }
        if (isset($_GET[self::QUERY_VAR]) && '1' === (string)wp_unslash($_GET[self::QUERY_VAR])) { return true; }
        if (empty($_SERVER['REQUEST_URI'])) { return false; }
        $request_path = (string)wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $home_path = (string)wp_parse_url(home_url('/'), PHP_URL_PATH);
        $request_path = '/' . trim($request_path, '/') . '/';
        $home_path = trim($home_path, '/');
        if ($home_path) {
            $prefix = '/' . $home_path . '/';
            if (0 !== strpos($request_path, $prefix)) { return false; }
            $request_path = '/' . trim(substr($request_path, strlen($prefix)), '/') . '/';
        }
        return '/hamnoor-eitaa/' === $request_path;
    }

    public static function maybe_render() {
        if (!self::is_bridge_request()) { return; }
        nocache_headers();
        if (class_exists('Hamnoor_Eitaa_App_Shell')) { Hamnoor_Eitaa_App_Shell::apply_frame_headers(); }
        status_header(200);
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        self::render_page();
        exit;
    }

    private static function render_page() {
        $nonce = wp_create_nonce('hamnoor_eitaa_bridge');
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        $launch_raw = isset($_GET['tgWebAppStartParam']) ? wp_unslash($_GET['tgWebAppStartParam']) : (isset($_GET['startapp']) ? wp_unslash($_GET['startapp']) : '');
        $launch_param = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$launch_raw);
        $config = array(
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>$nonce,
            'dashboardUrl'=>$dashboard,
            'launchParam'=>$launch_param,
            'programConfigured'=>class_exists('Hamnoor_Eitaa') && Hamnoor_Eitaa::program_configured(),
            'strings'=>array(
                'loading'=>__('در حال اعتبارسنجی ورود ایتا…', 'hamnoor'),
                'outside'=>__('این صفحه را از داخل برنامک هم‌نور در ایتا باز کنید.', 'hamnoor'),
                'connect'=>__('شروع استفاده از هم‌نور', 'hamnoor'),
                'grant'=>__('فعال‌سازی یادآوری ایتا', 'hamnoor'),
                'requesting'=>__('در حال دریافت اجازه از ایتا…', 'hamnoor'),
                'contact'=>__('برای ساخت یا بازیابی پنل شخصی، شماره همین حساب ایتا را تأیید کنید.', 'hamnoor'),
                'denied'=>__('بدون اجازه ارسال پیام، یادآوری ایتا فعال نمی‌شود. هر زمان خواستید دوباره تلاش کنید.', 'hamnoor'),
                'contactDenied'=>__('شماره موبایل به اشتراک گذاشته نشد؛ اتصال انجام نشد.', 'hamnoor'),
                'done'=>__('اتصال ایتا با موفقیت انجام شد؛ در حال بازکردن داشبورد…', 'hamnoor'),
                'retry'=>__('تلاش دوباره', 'hamnoor'),
            ),
        );
        ?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html__('هم‌نور در ایتا', 'hamnoor'); ?></title>
<script src="https://developer.eitaa.com/eitaa-web-app.js"></script>
<script>window.HamnoorEitaaBridge=<?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<?php $hamnoor_eitaa_asset_ver = @filemtime(HAMNOOR_EITAA_PATH . 'assets/js/hamnoor-eitaa-miniapp.js') ?: HAMNOOR_EITAA_VERSION; ?>
<script src="<?php echo esc_url(HAMNOOR_EITAA_URL . 'assets/js/hamnoor-eitaa-miniapp.js?ver=' . rawurlencode((string)$hamnoor_eitaa_asset_ver)); ?>" defer></script>
<style>
:root{color-scheme:light dark}*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Tahoma,Arial,sans-serif;background:var(--tg-theme-bg-color,#f6f7f9);color:var(--tg-theme-text-color,#1f2937)}body{padding:calc(18px + var(--tg-content-safe-area-inset-top,env(safe-area-inset-top))) calc(14px + var(--tg-content-safe-area-inset-right,0px)) calc(24px + var(--tg-content-safe-area-inset-bottom,env(safe-area-inset-bottom))) calc(14px + var(--tg-content-safe-area-inset-left,0px))}.hn-eitaa-shell{max-width:620px;margin:0 auto}.hn-eitaa-card{background:var(--tg-theme-secondary-bg-color,#fff);border:1px solid rgba(127,127,127,.18);border-radius:18px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,.06)}.hn-eitaa-logo{width:54px;height:54px;border-radius:16px;background:#14407f;color:#fff;display:grid;place-items:center;font-size:27px;margin:0 auto 12px}.hn-eitaa-card h1{font-size:20px;text-align:center;margin:0 0 8px}.hn-eitaa-lead{text-align:center;line-height:1.9;color:var(--tg-theme-hint-color,#64748b);font-size:14px;margin:0 0 18px}.hn-eitaa-status{border-radius:12px;padding:12px 13px;line-height:1.8;font-size:14px;background:rgba(20,64,127,.08);margin-bottom:14px}.hn-eitaa-status.is-error{background:rgba(185,28,28,.1);color:#b91c1c}.hn-eitaa-status.is-success{background:rgba(21,128,61,.1);color:#15803d}.hn-eitaa-actions{display:grid;gap:10px}.hn-eitaa-btn{appearance:none;border:0;border-radius:12px;padding:12px 16px;font:inherit;font-weight:700;cursor:pointer;background:var(--tg-theme-button-color,#14407f);color:var(--tg-theme-button-text-color,#fff);width:100%;text-align:center;text-decoration:none}.hn-eitaa-btn.secondary{background:transparent;color:var(--tg-theme-link-color,#14407f);border:1px solid currentColor}.hn-eitaa-btn[disabled]{opacity:.55;cursor:wait}.hn-eitaa-small{text-align:center;color:var(--tg-theme-hint-color,#64748b);font-size:12px;line-height:1.8;margin-top:14px}.hn-eitaa-spinner{display:inline-block;width:15px;height:15px;border:2px solid currentColor;border-left-color:transparent;border-radius:50%;animation:hnspin .7s linear infinite;vertical-align:-3px;margin-left:7px}@keyframes hnspin{to{transform:rotate(360deg)}}
.hn-eitaa-intro{margin:0 0 14px;padding:12px 14px;border-radius:13px;background:rgba(20,64,127,.055);font-size:13px;line-height:1.9}.hn-eitaa-intro p{margin:0 0 7px}.hn-eitaa-intro p:last-child{margin:7px 0 0;color:var(--tg-theme-hint-color,#64748b)}.hn-eitaa-intro ul{margin:0;padding-right:20px}
</style>
</head>
<body>
<div class="hn-eitaa-shell"><main class="hn-eitaa-card">
<div class="hn-eitaa-logo">☾</div>
<h1><?php echo esc_html__('هم‌نور در ایتا', 'hamnoor'); ?></h1>
<p class="hn-eitaa-lead"><?php echo esc_html__('در پویش‌های ختم قرآن شرکت کنید، سهم قرائت بگیرید، مطالعه را ادامه دهید و پویش تازه بسازید؛ همه از داخل ایتا.', 'hamnoor'); ?></p>
<div id="hn-eitaa-intro" class="hn-eitaa-intro"><p><strong><?php echo esc_html__('با هم‌نور چه کارهایی می‌توانم انجام دهم؟','hamnoor'); ?></strong></p><ul><li><?php echo esc_html__('انتخاب سهم از پویش‌های عمومی','hamnoor'); ?></li><li><?php echo esc_html__('ادامه قرائت و نگهداری پیشرفت','hamnoor'); ?></li><li><?php echo esc_html__('ایجاد و مدیریت پویش شخصی','hamnoor'); ?></li><li><?php echo esc_html__('دریافت اختیاری یادآوری‌ها و اعلان‌های مهم','hamnoor'); ?></li></ul><p><?php echo esc_html__('شماره فقط برای ساخت یا بازیابی پنل شخصی استفاده می‌شود؛ مجوز دریافت یادآوری جداگانه و اختیاری است.','hamnoor'); ?></p></div>
<div id="hn-eitaa-status" class="hn-eitaa-status"><span class="hn-eitaa-spinner"></span><?php echo esc_html__('در حال آماده‌سازی…', 'hamnoor'); ?></div>
<div class="hn-eitaa-actions">
<button type="button" id="hn-eitaa-connect" class="hn-eitaa-btn" hidden><?php echo esc_html__('شروع استفاده از هم‌نور', 'hamnoor'); ?></button>
<a id="hn-eitaa-dashboard" class="hn-eitaa-btn" href="<?php echo esc_url($config['dashboardUrl']); ?>" hidden><?php echo esc_html__('ورود به هم‌نور', 'hamnoor'); ?></a>
<button type="button" id="hn-eitaa-unsubscribe" class="hn-eitaa-btn secondary" hidden><?php echo esc_html__('توقف اطلاع‌رسانی ایتا', 'hamnoor'); ?></button>
<button type="button" id="hn-eitaa-enable" class="hn-eitaa-btn secondary" hidden><?php echo esc_html__('فعال‌سازی مجدد اطلاع‌رسانی ایتا', 'hamnoor'); ?></button>
<button type="button" id="hn-eitaa-retry" class="hn-eitaa-btn secondary" hidden><?php echo esc_html__('تلاش دوباره', 'hamnoor'); ?></button>
</div>
<p class="hn-eitaa-small"><?php echo esc_html__('اطلاعات خام ورود یا شماره تماس ایتا در گزارش‌های مدیریتی ذخیره نمی‌شود؛ فقط اتصال تأییدشده ثبت می‌شود.', 'hamnoor'); ?></p>
</main></div>
</body></html><?php
    }

    public static function ajax_validate_init() {
        check_ajax_referer('hamnoor_eitaa_bridge');
        if (!class_exists('Hamnoor_Eitaa') || !Hamnoor_Eitaa::program_configured()) {
            self::record_status(false, false, __('توکن برنامه ایتا تنظیم نشده است.', 'hamnoor'));
            wp_send_json_error(array('message'=>__('توکن اختصاصی برنامه ایتا در مدیریت هم‌نور تنظیم نشده است.', 'hamnoor')));
        }
        $rate = Hamnoor_Helpers::rate_limit('eitaa_init', self::client_ip() ?: 'unknown', 60, HOUR_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message'=>$rate->get_error_message())); }
        $raw = isset($_POST['init_data']) ? (string)wp_unslash($_POST['init_data']) : '';
        $validated = Hamnoor_Eitaa::validate_init_data($raw, 900);
        if (is_wp_error($validated)) {
            self::record_status(false, false, $validated->get_error_message());
            wp_send_json_error(array('message'=>$validated->get_error_message()));
        }
        $user = $validated['user'];
        $eitaa_id = (string)$user['id'];
        $allows = !empty($user['allows_write_to_pm']);
        self::record_status(true, $allows, '');

        $flow = self::new_flow_token();
        $bound_user_id = Hamnoor_Eitaa::get_bound_user_id_by_eitaa($eitaa_id);
        $linked_user_id = Hamnoor_Eitaa::get_user_id_by_eitaa($eitaa_id);
        $payload = array(
            'eitaa_id'=>$eitaa_id,
            'display_name'=>self::eitaa_display_name($user),
            'allows_write_to_pm'=>$allows,
            'linked_user_id'=>$linked_user_id,
            'bound_user_id'=>$bound_user_id,
            'expires_at'=>time() + self::FLOW_TTL,
            'ua_hash'=>self::ua_hash(),
            'created_at'=>time(),
            'start_param'=>isset($validated['params']['start_param']) ? sanitize_text_field((string)$validated['params']['start_param']) : '',
        );
        // When a notification opens the registered HTTPS Mini App endpoint
        // directly, Eitaa may not copy the query value into signed initData.
        // Accept only our tiny allowlisted launch token as a navigation hint;
        // target_url() still enforces ownership for part_* destinations.
        if (empty($payload['start_param']) && isset($_POST['launch_param'])) {
            $payload['start_param'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string)wp_unslash($_POST['launch_param']));
        }
        set_transient(self::flow_key($flow), $payload, self::FLOW_TTL + 60);

        if ($linked_user_id) {
            $hamnoor_user = Hamnoor_Mobile_Access::get_user($linked_user_id);
            if (!$hamnoor_user || 'active' !== (string)$hamnoor_user->status) {
                wp_send_json_error(array('message'=>__('پنل هم‌نور متصل به این حساب ایتا در دسترس نیست.', 'hamnoor')));
            }
            $logged = Hamnoor_Mobile_Access::login($hamnoor_user, true, 'eitaa_cross_site');
            if (is_wp_error($logged)) { wp_send_json_error(array('message'=>$logged->get_error_message())); }
            Hamnoor_Mobile_Access::grant_sensitive_access_from_linked_channel(
                (int)$linked_user_id,
                'eitaa_signed_initdata',
                Hamnoor_Eitaa::verified_phone_hash_for_user((int)$linked_user_id)
            );
            Hamnoor_Eitaa::set_write_access($linked_user_id, $allows);
            $notifications_enabled = Hamnoor_Eitaa::instance()->is_connected((int)$linked_user_id);
            wp_send_json_success(array(
                'flow_token'=>$flow,
                'linked'=>true,
                'write_access'=>$allows,
                'notifications_enabled'=>$notifications_enabled,
                'redirect'=>self::target_url((string)($payload['start_param'] ?? ''), (int)$linked_user_id),
                'message'=>$allows ? __('ورود ایتا تأیید شد و پنل هم‌نور بازیابی شد.', 'hamnoor') : __('پنل هم‌نور شناسایی شد؛ برای دریافت یادآوری‌ها اجازه ارسال پیام را فعال کنید.', 'hamnoor'),
            ));
        }

        if ($bound_user_id) {
            wp_send_json_success(array(
                'flow_token'=>$flow,
                'linked'=>false,
                'reconnect_required'=>true,
                'write_access'=>$allows,
                'message'=>__('اتصال قبلی این حساب ایتا به‌دلیل تغییر شماره یا نبودِ تطبیق معتبر، نیاز به تأیید دوباره شماره همین حساب دارد.', 'hamnoor'),
            ));
        }

        wp_send_json_success(array(
            'flow_token'=>$flow,
            'linked'=>false,
            'write_access'=>$allows,
            'message'=>__('هویت ایتا با امضای رسمی تأیید شد. برای ساخت یا بازیابی پنل شخصی، شماره همین حساب را تأیید کنید.', 'hamnoor'),
        ));
    }

    public static function ajax_connect_contact() {
        check_ajax_referer('hamnoor_eitaa_bridge');
        $flow = isset($_POST['flow_token']) ? sanitize_text_field(wp_unslash($_POST['flow_token'])) : '';
        $payload = self::get_flow($flow);
        if (is_wp_error($payload)) { wp_send_json_error(array('message'=>$payload->get_error_message())); }

        $contact_raw = isset($_POST['contact_data']) ? (string)wp_unslash($_POST['contact_data']) : '';
        $contact = Hamnoor_Eitaa::validate_contact_data($contact_raw, (string)$payload['eitaa_id']);
        if (is_wp_error($contact)) { wp_send_json_error(array('message'=>$contact->get_error_message())); }

        $phone = (string)$contact['phone'];
        $name = !empty($payload['display_name']) ? (string)$payload['display_name'] : __('همراه هم‌نور', 'hamnoor');
        $bound_user_id = !empty($payload['bound_user_id']) ? (int)$payload['bound_user_id'] : 0;
        $user = null;
        if ($bound_user_id) {
            $user = Hamnoor_Mobile_Access::get_user($bound_user_id);
            if (!$user || 'active' !== (string)$user->status) {
                wp_send_json_error(array('message'=>__('پنل هم‌نور متصل به این حساب ایتا در دسترس نیست.', 'hamnoor')));
            }
            $current_phone = Hamnoor_Validator::normalize_phone((string)$user->phone);
            if (!$current_phone || !hash_equals($current_phone, Hamnoor_Validator::normalize_phone($phone))) {
                wp_send_json_error(array(
                    'message'=>__('شماره این حساب ایتا با شماره فعلی پنل هم‌نور یکسان نیست. ورود خودکار ایتا برای امنیت غیرفعال می‌ماند؛ با شماره فعلی هم‌نور وارد داشبورد شوید یا از حساب ایتایی با همان شماره استفاده کنید.', 'hamnoor'),
                    'dashboard_url'=>Hamnoor_Helpers::page_url('dashboard'),
                ));
            }
        } else {
            $user = Hamnoor_Mobile_Access::find_user($phone);
            if (!$user) {
                $user = Hamnoor_Mobile_Access::ensure_user($phone, $name);
                if (is_wp_error($user)) { wp_send_json_error(array('message'=>$user->get_error_message())); }
            }
        }

        $user = Hamnoor_Mobile_Access::verify_by_trusted_channel((int)$user->id, 'eitaa_initdata_request_contact', $phone);
        if (is_wp_error($user)) { wp_send_json_error(array('message'=>$user->get_error_message(),'dashboard_url'=>Hamnoor_Helpers::page_url('dashboard'))); }

        $connected = Hamnoor_Eitaa::connect_user((int)$user->id, (string)$payload['eitaa_id'], array(
            'write_access'=>false,
            'proof'=>'official_initdata_requestContact',
            'phone_verified_by'=>'eitaa_requestContact',
            'phone_last4'=>substr(Hamnoor_Validator::normalize_phone($phone), -4),
            'verified_phone_hash'=>Hamnoor_Mobile_Access::trusted_phone_fingerprint($phone),
        ));
        if (is_wp_error($connected)) { wp_send_json_error(array('message'=>$connected->get_error_message())); }

        $logged = Hamnoor_Mobile_Access::login($user, true, 'eitaa_cross_site');
        if (is_wp_error($logged)) { wp_send_json_error(array('message'=>$logged->get_error_message())); }
        Hamnoor_Mobile_Access::grant_sensitive_access_from_trusted_channel((int)$user->id, 'eitaa_initdata_request_contact', $phone);
        Hamnoor_DB::sync_guest_history_to_user((int)$user->id);
        Hamnoor_DB::add_notification((int)$user->id, 'eitaa_connected', __('ایتا به هم‌نور متصل شد', 'hamnoor'), __('ورود یکپارچه از ایتا فعال شد. دریافت یادآوری پس از اجازه جداگانه کاربر فعال می‌شود.', 'hamnoor'));
        Hamnoor_DB::log(0, 0, 0, (int)$user->id, 'eitaa_connected', __('اتصال ایتا با initData و requestContact رسمی و تطبیق شماره فعلی هم‌نور تأیید شد.', 'hamnoor'));

        $payload['linked_user_id']=(int)$user->id;
        set_transient(self::flow_key($flow),$payload,self::FLOW_TTL+60);

        wp_send_json_success(array(
            'message'=>__('پنل شخصی هم‌نور آماده شد. اکنون می‌توانید از همه بخش‌های برنامه استفاده کنید.', 'hamnoor'),
            'redirect'=>self::target_url((string)($payload['start_param'] ?? ''), (int)$user->id),
            'notifications_enabled'=>Hamnoor_Eitaa::instance()->is_connected((int)$user->id),
        ));
    }

    public static function ajax_confirm_write_access() {
        check_ajax_referer('hamnoor_eitaa_bridge');
        $flow = isset($_POST['flow_token']) ? sanitize_text_field(wp_unslash($_POST['flow_token'])) : '';
        $payload = self::get_flow($flow);
        if (is_wp_error($payload)) { wp_send_json_error(array('message'=>$payload->get_error_message())); }
        $user_id = (int)($payload['linked_user_id'] ?? 0);
        if (!$user_id) { wp_send_json_error(array('message'=>__('اتصال قبلی هم‌نور برای این حساب ایتا پیدا نشد.', 'hamnoor'))); }
        $send = Hamnoor_Eitaa::app_send_message((string)$payload['eitaa_id'], "✅ یادآوری ایتا برای هم‌نور فعال شد\n\nاز این پس پیام‌های مهم هم‌نور می‌تواند از طریق ایتا برای شما ارسال شود.");
        if (is_wp_error($send)) { wp_send_json_error(array('message'=>__('API ایتا هنوز اجازه ارسال پیام به این حساب را تأیید نکرد: ', 'hamnoor') . $send->get_error_message())); }
        Hamnoor_Eitaa::set_write_access($user_id, true, true);
        delete_transient(self::flow_key($flow));
        wp_send_json_success(array(
            'message'=>__('یادآوری ایتا فعال شد.', 'hamnoor'),
            'redirect'=>self::target_url((string)($payload['start_param'] ?? ''), $user_id),
        ));
    }

    public static function ajax_unsubscribe() {
        check_ajax_referer('hamnoor_eitaa_bridge');
        $flow = isset($_POST['flow_token']) ? sanitize_text_field(wp_unslash($_POST['flow_token'])) : '';
        $payload = self::get_flow($flow);
        if (is_wp_error($payload)) { wp_send_json_error(array('message'=>$payload->get_error_message())); }
        $user_id = (int)($payload['linked_user_id'] ?? 0);
        if (!$user_id) { wp_send_json_error(array('message'=>__('حساب متصل هم‌نور پیدا نشد.', 'hamnoor'))); }
        $done = Hamnoor_Eitaa::unsubscribe_user($user_id, 'eitaa_miniapp');
        if (!$done) { wp_send_json_error(array('message'=>__('توقف اطلاع‌رسانی ایتا ثبت نشد؛ دوباره تلاش کنید.', 'hamnoor'))); }
        wp_send_json_success(array(
            'message'=>__('اطلاع‌رسانی هم‌نور در ایتا متوقف شد. حساب، تأیید شماره و سوابق شما باقی مانده است.', 'hamnoor'),
        ));
    }

    private static function target_url($start_param, $user_id) {
        $start_param = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$start_param);
        if (preg_match('/^part_(\d+)$/', $start_param, $m)) {
            $part = Hamnoor_DB::get_part((int)$m[1]);
            if ($part && !empty($part->participant_user_id) && (int)$part->participant_user_id === (int)$user_id) {
                return Hamnoor_Reading::reading_url((int)$part->id);
            }
        }
        $dashboard=Hamnoor_Helpers::page_url('dashboard');
        if ('continue' === $start_param) { return self::latest_reading_url((int)$user_id); }
        if (in_array($start_param,array('parts','my_parts'),true)) { return add_query_arg('tab','my-parts',$dashboard); }
        if ('public' === $start_param) { return Hamnoor_Helpers::page_url('list'); }
        if (in_array($start_param,array('khatms','my_khatms'),true)) { return add_query_arg('tab','my-khatms',$dashboard); }
        if ('create' === $start_param) { return Hamnoor_Helpers::page_url('create'); }
        if (in_array($start_param,array('notifications','reminders','profile','progress','help'),true)) { return add_query_arg('tab',$start_param,$dashboard); }
        return $dashboard;
    }

    private static function latest_reading_url($user_id) {
        if (class_exists('Hamnoor_DB') && class_exists('Hamnoor_Reading')) {
            foreach ((array)Hamnoor_DB::get_parts_by_user((int)$user_id, 50, 0) as $part) {
                if (isset($part->status) && 'claimed' === (string)$part->status) {
                    return Hamnoor_Reading::reading_url((int)$part->id);
                }
            }
        }
        return Hamnoor_Helpers::page_url('list');
    }

    private static function new_flow_token() {
        try { return bin2hex(random_bytes(24)); }
        catch (Throwable $e) { return hash('sha256', wp_generate_password(64, true, true) . microtime(true) . wp_rand()); }
    }

    private static function flow_key($token) {
        return 'hamnoor_eitaa_flow_' . substr(hash_hmac('sha256', (string)$token, wp_salt('auth')), 0, 40);
    }

    private static function get_flow($token) {
        if (!$token || !preg_match('/^[a-f0-9]{48,64}$/', (string)$token)) { return new WP_Error('hamnoor_eitaa_flow_invalid', __('درخواست اتصال ایتا معتبر نیست.', 'hamnoor')); }
        $payload = get_transient(self::flow_key($token));
        if (!is_array($payload) || empty($payload['eitaa_id']) || time() > (int)($payload['expires_at'] ?? 0)) {
            delete_transient(self::flow_key($token));
            return new WP_Error('hamnoor_eitaa_flow_expired', __('مهلت اتصال تمام شده است؛ برنامک را ببندید و دوباره باز کنید.', 'hamnoor'));
        }
        if (empty($payload['ua_hash']) || !hash_equals((string)$payload['ua_hash'], self::ua_hash())) {
            delete_transient(self::flow_key($token));
            return new WP_Error('hamnoor_eitaa_flow_browser', __('این درخواست اتصال متعلق به اجرای دیگری از برنامک است.', 'hamnoor'));
        }
        return $payload;
    }

    private static function eitaa_display_name($user) {
        if (!is_array($user)) { return ''; }
        $name = trim(sanitize_text_field((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')));
        return Hamnoor_Validator::trim_len($name, 191);
    }

    private static function record_status($valid, $allows, $error) {
        update_option(self::STATUS_OPTION, array(
            'validated_at'=>current_time('mysql'),
            'valid'=>(bool)$valid,
            'allows_write_to_pm'=>(bool)$allows,
            'error'=>$error ? Hamnoor_Validator::trim_len(wp_strip_all_tags((string)$error), 500) : '',
        ), false);
    }

    private static function ua_hash() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        return hash_hmac('sha256', $ua, wp_salt('secure_auth'));
    }

    private static function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }
}
