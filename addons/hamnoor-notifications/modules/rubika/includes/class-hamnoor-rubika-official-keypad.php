<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Rubika adapter rebuilt from the official Bot API contract.
 *
 * Receive path: official getUpdates only.
 * Connection path: StartedBot/NewMessage -> AskMyPhoneNumber -> ContactMessage.
 * There is deliberately no operational Webhook or hybrid mode.
 */
final class Hamnoor_Rubika implements Hamnoor_Notification_Channel_Interface {
    const OPTION = 'hamnoor_rubika_settings';
    const API_BASE = 'https://botapi.rubika.ir/v3/';
    const PHONE_BUTTON_ID = 'hamnoor_share_phone';
    const DASHBOARD_BUTTON_ID = 'hamnoor_open_dashboard';
    const UNSUBSCRIBE_BUTTON_ID = 'hamnoor_unsubscribe';
    const RESUBSCRIBE_BUTTON_ID = 'hamnoor_resubscribe';
    const LEGACY_POLL_HOOK = 'hamnoor_rubika_poll_updates';
    const WORKER_HOOK = 'hamnoor_rubika_getupdates_worker';
    const WORKER_LOCK = 'hamnoor_rubika_getupdates_lock';
    const CLI_LOCK_OPTION = 'hamnoor_rubika_cli_worker_lock';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function init() {
        Hamnoor_Notification_Channel_Registry::register('rubika', self::instance());
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'ensure_worker_schedule'), 20);
        add_action('template_redirect', array(__CLASS__, 'maybe_handle_handoff'), -100);
        add_action(self::WORKER_HOOK, array(__CLASS__, 'run_worker'));
        add_action('admin_post_hamnoor_rubika_save', array(__CLASS__, 'admin_save'));
        add_action('admin_post_hamnoor_rubika_test_connection', array(__CLASS__, 'admin_test_connection'));
        add_action('admin_post_hamnoor_rubika_run_worker', array(__CLASS__, 'admin_run_worker'));
        add_action('admin_post_hamnoor_rubika_reset_worker', array(__CLASS__, 'admin_reset_worker'));
        add_action('admin_post_hamnoor_rubika_sync_keypads', array(__CLASS__, 'admin_sync_keypads'));
        add_action('admin_post_hamnoor_rubika_test_message', array(__CLASS__, 'admin_test_message'));
        self::ensure_worker_schedule();
    }

    public static function remove_legacy_schedules() {
        if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(self::LEGACY_POLL_HOOK); }
    }

    public static function cron_schedules($schedules) {
        $schedules['hamnoor_rubika_minute'] = array('interval'=>60, 'display'=>__('هر یک دقیقه — Worker روبیکا', 'hamnoor'));
        return $schedules;
    }

    public static function ensure_worker_schedule($fail_hard = false) {
        self::remove_legacy_schedules();
        if (!has_filter('cron_schedules', array(__CLASS__, 'cron_schedules'))) {
            add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        }
        if (wp_next_scheduled(self::WORKER_HOOK)) { return true; }
        $result = wp_schedule_event(time() + 10, 'hamnoor_rubika_minute', self::WORKER_HOOK, array(), true);
        if (is_wp_error($result) || true !== $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown scheduling failure';
            update_option('hamnoor_rubika_worker_last_error', substr($message, 0, 500), false);
            if ($fail_hard) { throw new RuntimeException('Hamnoor Rubika worker schedule failed: ' . $message); }
            return false;
        }
        delete_option('hamnoor_rubika_worker_last_error');
        return true;
    }

    public function key() { return 'rubika'; }

    public function is_available() {
        $s = self::settings();
        return '1' === (string)$s['enabled'] && self::configured();
    }

    public function is_connected($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table || !(int)$user_id) { return false; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,channel,enabled,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='rubika' LIMIT 1",
            (int)$user_id
        ));
        if (!$row || 1 !== (int)$row->enabled || 'connected' !== (string)$row->status || '' === trim((string)$row->external_id)) { return false; }
        return !function_exists('hamnoor_notifications_channel_identity_valid') || hamnoor_notifications_channel_identity_valid((int)$user_id, 'rubika', $row);
    }

    public function send_to_user($user_id, $notification, $context = array()) {
        if (!$this->is_available()) {
            return new WP_Error('hamnoor_rubika_not_configured', __('کانال روبیکا پیکربندی نشده است.', 'hamnoor'));
        }
        $chat_id = self::user_chat_id((int)$user_id);
        if (!$chat_id) {
            return new WP_Error('hamnoor_rubika_not_connected', __('حساب روبیکای کاربر متصل نیست.', 'hamnoor'));
        }
        $title = isset($notification->title) ? trim(wp_strip_all_tags((string)$notification->title)) : '';
        $body = isset($notification->message) ? trim(wp_strip_all_tags((string)$notification->message)) : (isset($notification->body) ? trim(wp_strip_all_tags((string)$notification->body)) : '');
        $text = trim($title . ($title && $body ? "\n\n" : '') . $body);
        if (!$text) { $text = __('اعلان جدیدی در هم‌نور دارید.', 'hamnoor'); }

        // Broadcasts are sent exactly as editorial text. Do not manufacture a
        // Hamnoor dashboard/handoff link. Append only the explicit admin URL.
        if (!empty($notification->type) && 'admin_broadcast' === (string)$notification->type) {
            $custom_url = !empty($context['url']) ? esc_url_raw((string)$context['url'], array('http','https')) : '';
            if ($custom_url && !wp_http_validate_url($custom_url)) { $custom_url = ''; }
            if ($custom_url) { $text .= "\n\n" . $custom_url; }
            $result = self::send_message($chat_id, $text);
            return is_wp_error($result) ? $result : true;
        }

        $target = Hamnoor_Helpers::page_url('dashboard');
        if (!empty($notification->related_type) && 'part' === (string)$notification->related_type && !empty($notification->related_id) && class_exists('Hamnoor_Reading')) {
            $target = Hamnoor_Reading::reading_url((int)$notification->related_id);
        } elseif (!empty($context['url'])) {
            $candidate = wp_validate_redirect(esc_url_raw((string)$context['url']), '');
            $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $candidate_host = wp_parse_url($candidate, PHP_URL_HOST);
            if ($candidate && $home_host && $candidate_host && strtolower((string)$home_host) === strtolower((string)$candidate_host)) { $target = $candidate; }
        }
        $url = self::handoff_url((int)$user_id, $target);
        if (is_wp_error($url)) { return $url; }
        $result = self::send_link_message($chat_id, __('مشاهده در هم‌نور', 'hamnoor'), $url, $text);
        return is_wp_error($result) ? $result : true;
    }

    public static function settings() {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        return wp_parse_args($raw, array(
            'enabled'=>'0', 'token_enc'=>'', 'bot_id'=>'', 'bot_title'=>'',
            'bot_username'=>'', 'share_url'=>'', 'test_chat_id'=>'',
            'last_getme_at'=>'', 'last_test_at'=>'', 'last_update_at'=>'', 'last_update_type'=>'',
            'last_update_chat_id'=>'', 'last_update_result'=>'', 'last_update_error'=>'',
            'last_update_message_kind'=>'', 'last_update_button_id'=>'',
            'last_send_at'=>'', 'last_send_status'=>'', 'last_send_error'=>'',
            'last_keypad_remove_at'=>'', 'last_keypad_remove_status'=>'', 'last_keypad_remove_error'=>'',
            'worker_enabled'=>'1', 'poll_offset_id'=>'', 'worker_bootstrap'=>'1',
            'worker_bootstrap_started_ts'=>0,
            'last_poll_at'=>'', 'last_poll_count'=>0, 'last_poll_result'=>'',
            'last_poll_error'=>'', 'last_poll_next_offset'=>'',
            'worker_ready_at'=>'', 'worker_discarded'=>0,
            'last_keypad_sync_at'=>'', 'last_keypad_sync_started_at'=>'',
            'last_keypad_sync_status'=>'', 'last_keypad_sync_scanned'=>0,
            'last_keypad_sync_valid'=>0, 'last_keypad_sync_stale'=>0,
            'last_keypad_sync_success'=>0, 'last_keypad_sync_failed'=>0,
            'last_keypad_sync_stage'=>'', 'last_keypad_sync_error'=>'',
            'last_rebind_at'=>'', 'last_rebind_status'=>'',
            'last_rebind_source'=>'', 'last_rebind_error'=>'',
            'last_cli_worker_at'=>'', 'last_cli_worker_ts'=>0,
            'last_cli_worker_cycles'=>0, 'last_cli_worker_error'=>'',
            'architecture'=>'official_getupdates_v1',
        ));
    }

    public static function configured() { return '' !== self::token(); }

    /** One official getUpdates request; never runs concurrently. */
    public static function run_worker($source='cron') {
        $s=self::settings();
        if ('1' !== (string)$s['enabled'] || '1' !== (string)$s['worker_enabled'] || !self::configured()) { return 'disabled'; }
        if ('cli' !== $source && !empty($s['last_cli_worker_ts']) && time()-(int)$s['last_cli_worker_ts']<90) { return 'cli_active'; }
        if (get_transient(self::WORKER_LOCK)) { return 'locked'; }
        set_transient(self::WORKER_LOCK,1,55);
        $params=array('limit'=>50);
        if (!empty($s['poll_offset_id'])) { $params['offset_id']=(string)$s['poll_offset_id']; }
        $result=self::api_call('getUpdates',$params);
        if (is_wp_error($result)) {
            $s=self::settings(); $s['last_poll_at']=current_time('mysql'); $s['last_poll_result']='error'; $s['last_poll_error']=$result->get_error_message();
            update_option(self::OPTION,$s,false); delete_transient(self::WORKER_LOCK); return $result;
        }
        $data=!empty($result['data'])&&is_array($result['data'])?$result['data']:$result;
        $updates=!empty($data['updates'])&&is_array($data['updates'])?array_values($data['updates']):array();
        $next=self::sanitize_external_id(isset($data['next_offset_id'])?$data['next_offset_id']:'');
        $s=self::settings();
        // Cursor is committed before any reply: at-most-once processing.
        if ($next) { $s['poll_offset_id']=$next; $s['last_poll_next_offset']=$next; }
        $s['last_poll_at']=current_time('mysql'); $s['last_poll_count']=count($updates); $s['last_poll_error']='';
        if ('1' === (string)$s['worker_bootstrap']) {
            $cutoff=(int)$s['worker_bootstrap_started_ts'];
            if ($cutoff<=0) { $cutoff=time(); $s['worker_bootstrap_started_ts']=$cutoff; }
            $fresh=array(); $discarded=0;
            foreach ($updates as $entry) {
                $update=!empty($entry['update'])&&is_array($entry['update'])?$entry['update']:$entry;
                if (!is_array($update)) { $discarded++; continue; }
                $event_time=self::update_timestamp($update);
                // Messages created after backlog cleanup began are real-time
                // user actions and must never be discarded with old history.
                if ($event_time>0 && $event_time>=($cutoff-5)) { $fresh[]=$entry; }
                else { $discarded++; }
            }
            $s['worker_discarded']=(int)$s['worker_discarded']+$discarded;
            if (!$updates || $fresh) {
                $s['worker_bootstrap']='0'; $s['worker_ready_at']=current_time('mysql');
                $s['last_poll_result']=$fresh?'ready_with_new_messages':'ready';
            } else {
                $s['last_poll_result']='discarding_backlog';
            }
            update_option(self::OPTION,$s,false);
            if (!$fresh) { delete_transient(self::WORKER_LOCK); return $s['last_poll_result']; }
            $updates=$fresh;
        }
        $s['last_poll_result']=$updates?'processing':'empty'; update_option(self::OPTION,$s,false);
        $processed=self::process_entries($updates);
        $s=self::settings(); $s['last_poll_result']='processed_'.$processed; update_option(self::OPTION,$s,false);
        delete_transient(self::WORKER_LOCK); return $s['last_poll_result'];
    }

    /** Long-running CLI worker: one cron launch, many five-second polls. */
    public static function run_cli_worker($runtime=55,$interval=5) {
        $runtime=max(10,min(58,(int)$runtime)); $interval=max(3,min(15,(int)$interval));
        $token=wp_generate_password(24,false,false); $lock=get_option(self::CLI_LOCK_OPTION,array());
        if (is_array($lock)&&!empty($lock['expires'])&&(int)$lock['expires']>=time()) { return array('status'=>'locked','cycles'=>0); }
        if ($lock) { delete_option(self::CLI_LOCK_OPTION); }
        if (!add_option(self::CLI_LOCK_OPTION,array('token'=>$token,'expires'=>time()+$runtime+15),'','no')) { return array('status'=>'locked','cycles'=>0); }
        @set_time_limit($runtime+10); ignore_user_abort(true); $deadline=microtime(true)+$runtime; $cycles=0; $error='';
        try {
            while (microtime(true)<$deadline) {
                $s=self::settings(); $s['last_cli_worker_at']=current_time('mysql'); $s['last_cli_worker_ts']=time(); update_option(self::OPTION,$s,false);
                $result=self::run_worker('cli'); $cycles++;
                if (is_wp_error($result)) { $error=$result->get_error_message(); if (false!==stripos($error,'TOO_REQUESTS')) { break; } }
                $remaining=$deadline-microtime(true); if ($remaining<$interval) { break; } sleep($interval);
            }
        } catch (Throwable $e) { $error=$e->getMessage(); }
        $s=self::settings(); $s['last_cli_worker_at']=current_time('mysql'); $s['last_cli_worker_ts']=time(); $s['last_cli_worker_cycles']=$cycles; $s['last_cli_worker_error']=sanitize_text_field($error); update_option(self::OPTION,$s,false);
        $current=get_option(self::CLI_LOCK_OPTION,array()); if (is_array($current)&&!empty($current['token'])&&hash_equals($token,(string)$current['token'])) { delete_option(self::CLI_LOCK_OPTION); }
        return array('status'=>$error?'error':'completed','cycles'=>$cycles,'error'=>$error);
    }

    public static function worker_cron_command() {
        $script=HAMNOOR_RUBIKA_PATH.'cli/rubika-getupdates-worker.php';
        return '/usr/local/bin/php '.$script.' >/dev/null 2>&1';
    }

    public static function token() {
        $s = self::settings();
        if (empty($s['token_enc'])) { return ''; }
        return self::normalize_token(self::decrypt_secret((string)$s['token_enc']));
    }

    public static function bot_url() {
        $s = self::settings();
        if (!empty($s['share_url']) && 0 === strpos((string)$s['share_url'], 'https://')) {
            return esc_url_raw((string)$s['share_url']);
        }
        $username = self::sanitize_username($s['bot_username']);
        return $username ? 'https://rubika.ir/' . rawurlencode($username) : '';
    }

    public static function connected_users_count() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return 0; }
        $rows = $wpdb->get_results("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE channel='rubika' AND enabled=1 AND status='connected' AND external_id IS NOT NULL AND external_id<>''");
        if (!function_exists('hamnoor_notifications_channel_identity_valid')) { return count((array)$rows); }
        $count = 0;
        foreach ((array)$rows as $row) {
            if (hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'rubika', $row)) { $count++; }
        }
        return $count;
    }

    public static function known_users_count() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return 0; }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE channel='rubika' AND external_id IS NOT NULL AND external_id<>''");
    }

    private static function process_update($update) {
        $type = isset($update['type']) ? (string)$update['type'] : '';
        $chat_id = self::sanitize_external_id(isset($update['chat_id']) ? $update['chat_id'] : '');
        if (!$chat_id) { return 'ignored_no_chat'; }
        if ('StoppedBot' === $type) {
            self::disconnect_chat($chat_id);
            return 'disconnected';
        }
        if ('StartedBot' === $type) {
            // Starting a known Rubika chat must restore its menu without asking
            // for the already verified phone number again. Notification consent
            // remains independent and is not silently re-enabled here.
            $user_id = self::known_user_id_by_chat($chat_id);
            if (!$user_id) {
                $get_chat_error = '';
                $rubika_user_id = self::get_chat_rubika_user_id($chat_id, $get_chat_error);
                if ($rubika_user_id) {
                    $user_id = self::maybe_rebind_chat_by_sender($chat_id, $rubika_user_id, 'StartedBot_getChat');
                } elseif ($get_chat_error) {
                    self::record_rebind_status('getchat_error', 'StartedBot_getChat', $get_chat_error);
                }
            }
            return $user_id ? self::send_connected_message($chat_id) : self::send_contact_request($chat_id);
        }
        if ('NewMessage' !== $type || empty($update['new_message']) || !is_array($update['new_message'])) {
            return 'ignored_type';
        }

        $message = $update['new_message'];
        $sender_id = self::sanitize_external_id(isset($message['sender_id']) ? $message['sender_id'] : '');
        $aux = !empty($message['aux_data']) && is_array($message['aux_data']) ? $message['aux_data'] : array();
        $button_id = isset($aux['button_id']) ? sanitize_key((string)$aux['button_id']) : '';
        $user_id = self::known_user_id_by_chat($chat_id);
        if (!$user_id && $sender_id) {
            $user_id = self::maybe_rebind_chat_by_sender($chat_id, $sender_id, 'NewMessage_sender_id');
        }
        if (!empty($message['contact_message']) && is_array($message['contact_message'])) {
            // The official AuxData contract says button_id is returned only
            // "if present". Some Rubika clients therefore return the official
            // ContactMessage without it. A live server-side request for this
            // exact chat is the fallback proof that the contact belongs to our
            // AskMyPhoneNumber flow; arbitrary contacts remain rejected.
            if (self::PHONE_BUTTON_ID !== $button_id && !self::has_pending_phone_request($chat_id)) {
                return self::send_contact_request($chat_id, __('برای اتصال امن، شماره را فقط با دکمهٔ «ارسال شماره من» بفرستید.', 'hamnoor'));
            }
            return self::connect_contact($chat_id, $sender_id, $message['contact_message']);
        }

        $text = isset($message['text']) ? trim((string)$message['text']) : '';
        // Rubika Android currently returns AskMyPhoneNumber as the normalized
        // phone in `text` plus the official keypad `button_id`, although the
        // Bot API model documents a `contact_message` object. Accept this real
        // payload only when it came from our exact button; a manually typed
        // phone remains untrusted and cannot connect an account.
        if (self::PHONE_BUTTON_ID === $button_id && Hamnoor_Validator::valid_iran_mobile($text)) {
            return self::connect_contact($chat_id, $sender_id, array(
                'phone_number' => $text,
                'first_name'   => '',
                'last_name'    => '',
            ));
        }
        // Compatibility for Rubika clients/getUpdates responses which render
        // AskMyPhoneNumber as plain phone text but omit both contact_message and
        // aux_data.button_id. A number is accepted only while this exact chat
        // has a short-lived server-side phone request created by Hamnoor.
        if (Hamnoor_Validator::valid_iran_mobile($text) && self::has_pending_phone_request($chat_id)) {
            return self::connect_contact($chat_id, $sender_id, array(
                'phone_number' => $text,
                'first_name'   => '',
                'last_name'    => '',
            ));
        }
        $normalized = strtolower(ltrim((string)strtok($text, " \t\r\n"), '/'));
        if ($user_id && (self::DASHBOARD_BUTTON_ID === $button_id || __('ورود به داشبورد هم‌نور', 'hamnoor') === $text)) {
            $url = self::handoff_url($user_id, Hamnoor_Helpers::page_url('dashboard'));
            if (is_wp_error($url)) { return $url; }
            $sent = self::send_link_message($chat_id, __('باز کردن داشبورد هم‌نور', 'hamnoor'), $url, __('برای ورود، پیوند زیر را لمس کنید:', 'hamnoor'));
            return is_wp_error($sent) ? $sent : 'dashboard_link_sent';
        }
        if ($user_id && (self::RESUBSCRIBE_BUTTON_ID === $button_id || 'subscribe' === $normalized || in_array(trim((string)$text), array('فعال‌سازی اعلان','فعال سازی اعلان','دریافت اعلان'), true))) {
            $restored = self::reactivate_chat($chat_id);
            if (is_wp_error($restored)) { return $restored; }
            return self::send_connected_message($chat_id, __('✅ دریافت اعلان‌های هم‌نور در روبیکا دوباره فعال شد.', 'hamnoor'));
        }
        if ($user_id && (self::UNSUBSCRIBE_BUTTON_ID === $button_id || 'disconnect' === $normalized || in_array(trim((string)$text), array('لغو اعلان','انصراف از اعلان','قطع اعلان'), true))) {
            self::disconnect_chat($chat_id);
            $sent = self::send_connected_message($chat_id, __('ارسال اعلان‌های هم‌نور در روبیکا متوقف شد. حساب، سوابق و دسترسی شما به داشبورد باقی مانده است.', 'hamnoor'));
            return is_wp_error($sent) ? $sent : 'unsubscribed';
        }
        if (!$user_id) { return self::send_contact_request($chat_id); }
        if (in_array($normalized, array('start','connect'), true)) { return self::send_connected_message($chat_id); }
        if ('help' === $normalized) { return self::send_help($chat_id); }
        if ('menu' === $normalized) { return self::send_connected_message($chat_id); }
        return self::send_connected_message($chat_id);
    }

    private static function connect_contact($chat_id, $sender_id, $contact) {
        $phone = Hamnoor_Validator::normalize_phone(isset($contact['phone_number']) ? (string)$contact['phone_number'] : '');
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            return self::send_contact_request($chat_id, __('شماره دریافت‌شده موبایل معتبر ایرانی نیست.', 'hamnoor'));
        }
        $name = trim((isset($contact['first_name']) ? sanitize_text_field((string)$contact['first_name']) : '') . ' ' . (isset($contact['last_name']) ? sanitize_text_field((string)$contact['last_name']) : ''));
        if (!$name) { $name = __('همراه هم‌نور', 'hamnoor'); }

        // If this exact Rubika identity was already bound, a new phone proof may
        // only restore the SAME Hamnoor account. Never move a chat/sender to a
        // different account after a mobile-number change.
        $bound_user_id = self::bound_user_id_by_identity($chat_id, $sender_id);
        if ($bound_user_id) {
            $bound_user = Hamnoor_Mobile_Access::get_user((int)$bound_user_id);
            $bound_phone = $bound_user ? Hamnoor_Validator::normalize_phone((string)$bound_user->phone) : '';
            if (!$bound_user || !$bound_phone || !hash_equals($bound_phone, $phone)) {
                return self::send_contact_request($chat_id, __('شماره این حساب روبیکا با شماره فعلی پنل هم‌نور یکسان نیست. ورود/اتصال خودکار برای امنیت غیرفعال می‌ماند؛ از حساب روبیکایی با همان شماره فعلی هم‌نور استفاده کنید.', 'hamnoor'));
            }
            $user = $bound_user;
        } else {
            $user = Hamnoor_Mobile_Access::find_user($phone);
            if (!$user) { $user = Hamnoor_Mobile_Access::ensure_user($phone, $name); }
        }
        if (is_wp_error($user) || !$user) {
            return is_wp_error($user) ? $user : new WP_Error('hamnoor_rubika_user_failed', __('ساخت پنل هم‌نور انجام نشد.', 'hamnoor'));
        }
        $user = Hamnoor_Mobile_Access::verify_by_trusted_channel((int)$user->id, 'rubika_ask_my_phone_number', $phone);
        if (is_wp_error($user)) { return $user; }
        $bound = self::bind_user((int)$user->id, $chat_id, array(
            'rubika_sender_id'=>$sender_id,
            'verification'=>'rubika_AskMyPhoneNumber',
            'phone_last4'=>substr($phone, -4),
            'verified_phone_hash'=>Hamnoor_Mobile_Access::trusted_phone_fingerprint($phone),
            'phone_binding_status'=>'matched',
        ));
        if (is_wp_error($bound)) { return $bound; }
        delete_transient(self::pending_phone_key($chat_id));
        $sent = self::send_connected_message($chat_id, __('✅ حساب روبیکای شما با شماره فعلی هم‌نور تطبیق داده شد و برنامه آماده استفاده است.', 'hamnoor'));
        return is_wp_error($sent) ? $sent : 'connected';
    }

    private static function send_contact_request($chat_id, $prefix = '') {
        // StartedBot and /start can arrive as two adjacent official updates.
        // Suppress only that short duplicate; the one-day pending proof below
        // remains independent and does not block later retries.
        if (!$prefix && get_transient(self::contact_request_guard_key($chat_id))) {
            return 'phone_request_already_sent';
        }
        $intro = __("سلام؛ به هم‌نور خوش آمدید 🌱\n\nهم‌نور یک برنامه برای برگزاری و شرکت در پویش‌های ختم قرآن است. با آن می‌توانید:\n\n• از پویش‌های عمومی سهم قرائت بگیرید\n• قرائت خود را ادامه دهید و پیشرفتتان را نگه دارید\n• پویش تازه بسازید و آن را مدیریت کنید\n• یادآوری‌ها و اعلان‌های مهم را در روبیکا دریافت کنید\n\nبرای ساخت یا بازیابی پنل شخصی و نگهداری سهم‌ها، شماره موبایل خودتان را با دکمهٔ زیر ارسال کنید. شماره فقط از مسیر رسمی روبیکا دریافت می‌شود.", 'hamnoor');
        $text = trim($prefix . ($prefix ? "\n\n" : '') . $intro);
        $sent = self::send_message($chat_id, $text, array(
            'chat_keypad_type'=>'New',
            'chat_keypad'=>array(
                'rows'=>array(array('buttons'=>array(array(
                    'id'=>self::PHONE_BUTTON_ID,
                    'type'=>'AskMyPhoneNumber',
                    'button_text'=>__('ارسال شماره من', 'hamnoor'),
                )))),
                'resize_keyboard'=>true,
                'one_time_keyboard'=>true,
            ),
        ));
        if (!is_wp_error($sent)) {
            set_transient(self::pending_phone_key($chat_id), 1, DAY_IN_SECONDS);
            set_transient(self::contact_request_guard_key($chat_id), 1, 15);
        }
        return $sent;
    }

    private static function pending_phone_key($chat_id) {
        return 'hamnoor_rubika_phone_' . substr(hash_hmac('sha256', (string)$chat_id, wp_salt('auth')), 0, 32);
    }

    private static function has_pending_phone_request($chat_id) {
        return (bool)get_transient(self::pending_phone_key($chat_id));
    }

    private static function contact_request_guard_key($chat_id) {
        return 'hamnoor_rubika_prompt_' . substr(hash_hmac('sha256', (string)$chat_id, wp_salt('auth')), 0, 32);
    }

    private static function send_connected_message($chat_id, $prefix = '') {
        $user_id = self::known_user_id_by_chat($chat_id);
        if (!$user_id) { return self::send_contact_request($chat_id); }
        delete_transient(self::pending_phone_key($chat_id));
        $notifications_active = (bool)self::user_id_by_chat($chat_id);
        $state_message = $notifications_active
            ? __('هم‌نور آماده است. همه امکانات در داشبورد یکپارچه در دسترس شماست.', 'hamnoor')
            : __('حساب هم‌نور شما آماده است و داشبورد در دسترس شماست. دریافت اعلان‌های روبیکا فعلاً متوقف است.', 'hamnoor');
        $text = trim($prefix . ($prefix ? "\n\n" : '') . $state_message);

        // The official Button model does not define a URL payload for a
        // persistent ChatKeypad button. Use only documented Simple buttons;
        // clicking the dashboard button returns the signed, clickable link.
        $keypad=self::connected_keypad($chat_id,$notifications_active);
        if (is_wp_error($keypad)) { return $keypad; }
        $sent = self::send_message($chat_id, $text, array(
            'chat_keypad_type'=>'New',
            'chat_keypad'=>$keypad,
        ));
        $settings = self::settings();
        $settings['last_keypad_remove_at'] = current_time('mysql');
        if (is_wp_error($sent)) {
            $settings['last_keypad_remove_status'] = 'error';
            $settings['last_keypad_remove_error'] = $sent->get_error_message();
            update_option(self::OPTION, $settings, false);
            return $sent;
        } else {
            $settings['last_keypad_remove_status'] = 'official_simple_ok';
            $settings['last_keypad_remove_error'] = '';
        }
        update_option(self::OPTION, $settings, false);
        return 'already_connected';
    }

    private static function connected_keypad($chat_id,$notifications_active=null) {
        $user_id=self::known_user_id_by_chat($chat_id);
        if (!$user_id) {
            return new WP_Error('hamnoor_rubika_unknown_chat',__('حساب روبیکای متصل پیدا نشد.','hamnoor'));
        }
        if (null===$notifications_active) { $notifications_active=(bool)self::user_id_by_chat($chat_id); }
        $dashboard_button=array(
            'id'=>self::DASHBOARD_BUTTON_ID,
            'type'=>'Simple',
            'button_text'=>__('ورود به داشبورد هم‌نور','hamnoor'),
        );
        return array(
            'rows'=>array(
                array('buttons'=>array($dashboard_button)),
                array('buttons'=>array(array(
                    'id'=>$notifications_active?self::UNSUBSCRIBE_BUTTON_ID:self::RESUBSCRIBE_BUTTON_ID,
                    'type'=>'Simple',
                    'button_text'=>$notifications_active?__('انصراف از اعلان','hamnoor'):__('فعال‌سازی اعلان','hamnoor'),
                ))),
            ),
            'resize_keyboard'=>true,
            'one_time_keyboard'=>false,
        );
    }

    /** Rebuild the persistent menu with the exact documented editChatKeypad shape. */
    private static function sync_chat_keypad($chat_id) {
        $keypad=self::connected_keypad($chat_id);
        if (is_wp_error($keypad)) { return $keypad; }
        return self::api_call('editChatKeypad',array(
            'chat_id'=>$chat_id,
            'chat_keypad_type'=>'New',
            'chat_keypad'=>$keypad,
        ));
    }

    private static function send_link_message($chat_id,$label,$url,$prefix='') {
        $label=trim((string)$label,": \t\r\n");
        $prefix=trim((string)$prefix);
        $text=$prefix ? $prefix."\n\n".$label : $label;
        $from=self::utf16_length($prefix ? $prefix."\n\n" : '');
        $length=self::utf16_length($label);
        $result=self::send_message($chat_id,$text,array('metadata'=>array('meta_data_parts'=>array(array(
            'type'=>'Link','from_index'=>(int)$from,'length'=>(int)$length,'link_url'=>$url,
        )))));
        return is_wp_error($result)?self::send_message($chat_id,$text."\n".$url):$result;
    }

    /** Official metadata offsets are UTF-16 code units, not UTF-8 characters. */
    private static function utf16_length($text) {
        $text=(string)$text;
        if (''===$text) { return 0; }
        if (function_exists('mb_convert_encoding')) {
            $utf16=mb_convert_encoding($text,'UTF-16LE','UTF-8');
            if (is_string($utf16)) { return (int)(strlen($utf16)/2); }
        }
        $matched=preg_match_all('/./us',$text,$characters);
        if (false===$matched || empty($characters[0])) { return strlen($text); }
        $length=0;
        foreach ($characters[0] as $character) {
            // A valid four-byte UTF-8 character is represented by a surrogate
            // pair in UTF-16; every other Unicode scalar uses one code unit.
            $length+=strlen($character)>=4?2:1;
        }
        return $length;
    }

    private static function send_help($chat_id) {
        return self::send_connected_message($chat_id, __('همه امکانات هم‌نور در داشبورد یکپارچه قرار دارد. اعلان‌های روبیکا نیز شما را مستقیماً به مقصد مرتبط هدایت می‌کنند.', 'hamnoor'));
    }

    private static function handoff_url($user_id,$target_url) {
        global $wpdb;
        $user_id=(int)$user_id;
        if (!$user_id) { return new WP_Error('hamnoor_rubika_no_user',__('حساب هم‌نور متصل پیدا نشد.','hamnoor')); }
        $target_url=wp_validate_redirect(esc_url_raw($target_url),'');
        $home_host=wp_parse_url(home_url('/'),PHP_URL_HOST); $target_host=wp_parse_url($target_url,PHP_URL_HOST);
        if (!$target_url || !$home_host || strtolower((string)$home_host)!==strtolower((string)$target_host)) { return new WP_Error('hamnoor_rubika_bad_target',__('مقصد لینک معتبر نیست.','hamnoor')); }
        $table=Hamnoor_DB::table('rubika_links'); $target_hash=hash('sha256',$target_url);
        $row=$wpdb->get_row($wpdb->prepare("SELECT code FROM $table WHERE user_id=%d AND target_hash=%s LIMIT 1",$user_id,$target_hash));
        $code=$row?sanitize_key((string)$row->code):self::new_link_code();
        if (!preg_match('/^[a-z0-9]{20,32}$/',$code)) { $code=self::new_link_code(); }
        $secret=hash_hmac('sha256',$code.'|'.$user_id.'|'.$target_hash,wp_salt('auth'));
        $now=current_time('mysql');
        if ($row) {
            $wpdb->update($table,array('target_url'=>$target_url,'secret_hash'=>hash('sha256',$secret),'updated_at'=>$now),array('user_id'=>$user_id,'target_hash'=>$target_hash),array('%s','%s','%s'),array('%d','%s'));
        } else {
            $ok=$wpdb->insert($table,array('user_id'=>$user_id,'code'=>$code,'target_hash'=>$target_hash,'target_url'=>$target_url,'secret_hash'=>hash('sha256',$secret),'use_count'=>0,'created_at'=>$now,'updated_at'=>$now),array('%d','%s','%s','%s','%s','%d','%s','%s'));
            if (!$ok) { return new WP_Error('hamnoor_rubika_link_failed',__('ساخت لینک هم‌نور انجام نشد.','hamnoor')); }
        }
        return home_url('/hamnoor-go/'.rawurlencode($code).'/'.rawurlencode($secret).'/');
    }

    private static function new_link_code() {
        try { return bin2hex(random_bytes(12)); }
        catch (Throwable $e) { return substr(hash('sha256',wp_generate_password(64,true,true).microtime(true).wp_rand()),0,24); }
    }

    public static function maybe_handle_handoff() {
        if (empty($_SERVER['REQUEST_URI'])) { return; }
        $path=wp_parse_url((string)wp_unslash($_SERVER['REQUEST_URI']),PHP_URL_PATH);
        if (!is_string($path)||!preg_match('#/hamnoor-go/([a-z0-9]{20,32})/([a-f0-9]{64})/?$#',$path,$match)) { return; }
        global $wpdb; $table=Hamnoor_DB::table('rubika_links');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code=%s LIMIT 1",$match[1]));
        if (!$row || !hash_equals((string)$row->secret_hash,hash('sha256',(string)$match[2]))) { status_header(404); exit; }
        $expected=hash_hmac('sha256',(string)$row->code.'|'.(int)$row->user_id.'|'.(string)$row->target_hash,wp_salt('auth'));
        if (!hash_equals($expected,(string)$match[2])) { status_header(404); exit; }
        $target=wp_validate_redirect((string)$row->target_url,Hamnoor_Helpers::page_url('dashboard'));
        // The Rubika link remains reusable as requested, but it is now a routing
        // link rather than a permanent bearer credential. A matching existing
        // Hamnoor session can continue directly; a fresh browser must identify
        // itself normally with the panel phone number.
        if (Hamnoor_Mobile_Access::current_user_id() !== (int)$row->user_id) {
            Hamnoor_Helpers::redirect_with_notice(
                Hamnoor_Helpers::page_url('dashboard'),
                __('این لینک همچنان معتبر است، اما برای امنیت به‌تنهایی وارد حساب نمی‌شود. شماره موبایل همان پنل را وارد کنید؛ سپس سهم‌ها و ادامه قرائت در داشبورد در دسترس است.', 'hamnoor'),
                'warning'
            );
        }
        $now=current_time('mysql');
        $wpdb->query($wpdb->prepare("UPDATE $table SET use_count=use_count+1,last_used_at=%s,updated_at=%s WHERE id=%d",$now,$now,(int)$row->id));
        wp_safe_redirect($target); exit;
    }

    private static function bind_user($user_id, $chat_id, $metadata) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $other = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE channel='rubika' AND external_id=%s AND user_id<>%d LIMIT 1", $chat_id, $user_id));
        if ($other) {
            return new WP_Error('hamnoor_rubika_chat_bound', __('این حساب روبیکا قبلاً به پنل دیگری متصل شده است.', 'hamnoor'));
        }
        $now = current_time('mysql');
        $metadata = is_array($metadata) ? $metadata : array();
        if (!empty($metadata['verified_phone_hash'])) {
            $metadata['phone_binding_status'] = 'matched';
            unset($metadata['phone_binding_reason'], $metadata['phone_binding_invalidated_at']);
        }
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND channel='rubika' LIMIT 1", $user_id));
        $data = array('user_id'=>$user_id,'channel'=>'rubika','enabled'=>1,'status'=>'connected','external_id'=>$chat_id,'metadata'=>wp_json_encode($metadata, JSON_UNESCAPED_UNICODE),'updated_at'=>$now);
        if ($existing) {
            $ok = $wpdb->update($table, $data, array('id'=>$existing), array('%d','%s','%d','%s','%s','%s','%s'), array('%d'));
        } else {
            $data['connected_at']=$now; $data['created_at']=$now;
            $ok = $wpdb->insert($table, $data, array('%d','%s','%d','%s','%s','%s','%s','%s'));
        }
        if (false === $ok) { return new WP_Error('hamnoor_rubika_bind_failed', __('ذخیره اتصال روبیکا انجام نشد.', 'hamnoor')); }
        Hamnoor_Notifications::reset_channels_cache();
        return true;
    }

    private static function disconnect_chat($chat_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,metadata FROM $table WHERE channel='rubika' AND external_id=%s LIMIT 1", $chat_id));
        if ($row) {
            $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
            $meta['user_unsubscribed'] = 1; $meta['unsubscribed_at'] = current_time('mysql'); $meta['unsubscribed_by'] = 'rubika_chat';
            $wpdb->update($table, array('enabled'=>0,'status'=>'disabled','metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
        }
        Hamnoor_Notifications::reset_channels_cache();
    }

    /** Re-enable delivery for a previously verified Rubika chat. */
    private static function reactivate_chat($chat_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,user_id,channel,status,external_id,metadata FROM $table WHERE channel='rubika' AND external_id=%s ORDER BY enabled DESC,id DESC LIMIT 1",
            $chat_id
        ));
        if (!$row) {
            return new WP_Error('hamnoor_rubika_unknown_chat', __('اتصال قبلی روبیکا پیدا نشد؛ شماره خودتان را یک‌بار از مسیر رسمی روبیکا ارسال کنید.', 'hamnoor'));
        }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'rubika', $row)) {
            return new WP_Error('hamnoor_rubika_phone_reconnect_required', __('شماره اتصال روبیکا با شماره فعلی هم‌نور تطبیق ندارد؛ ابتدا شماره خودتان را دوباره از مسیر رسمی روبیکا ارسال کنید.', 'hamnoor'));
        }
        $meta = json_decode((string)$row->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        unset($meta['user_unsubscribed'], $meta['unsubscribed_at'], $meta['unsubscribed_by']);
        $meta['resubscribed_at'] = current_time('mysql');
        $meta['resubscribed_by'] = 'rubika_chat';
        $ok = $wpdb->update(
            $table,
            array('enabled'=>1,'status'=>'connected','metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')),
            array('id'=>(int)$row->id),
            array('%d','%s','%s','%s'),
            array('%d')
        );
        if (false === $ok) { return new WP_Error('hamnoor_rubika_reactivate_failed', __('فعال‌سازی دوباره اعلان‌های روبیکا انجام نشد.', 'hamnoor')); }
        Hamnoor_Notifications::reset_channels_cache();
        return true;
    }

    /** Account identity survives notification opt-out and bot stop/start. */
    /** Account identity survives notification opt-out, but not a changed Hamnoor phone. */
    private static function known_user_id_by_chat($chat_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,user_id,channel,status,external_id,metadata FROM $table WHERE channel='rubika' AND external_id=%s ORDER BY enabled DESC,id DESC LIMIT 1",
            $chat_id
        ));
        if (!$row || 'needs_reconnect' === (string)$row->status) { return 0; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'rubika', $row)) { return 0; }
        return (int)$row->user_id;
    }

    private static function bound_user_id_by_identity($chat_id, $sender_id = '') {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $chat_id = self::sanitize_external_id($chat_id);
        $sender_id = self::sanitize_external_id($sender_id);
        if ($chat_id) {
            $uid = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE channel='rubika' AND external_id=%s ORDER BY id DESC LIMIT 1", $chat_id));
            if ($uid) { return $uid; }
        }
        if (!$sender_id) { return 0; }
        $needle='%' . $wpdb->esc_like($sender_id) . '%';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT user_id,metadata FROM $table WHERE channel='rubika' AND metadata LIKE %s ORDER BY updated_at DESC,id DESC", $needle));
        foreach ((array)$rows as $row) {
            $meta=json_decode((string)$row->metadata,true); $meta=is_array($meta)?$meta:array();
            foreach (array('rubika_sender_id','rubika_user_id') as $key) {
                $stored=!empty($meta[$key])?self::sanitize_external_id($meta[$key]):'';
                if ($stored && hash_equals($stored,$sender_id)) { return (int)$row->user_id; }
            }
        }
        return 0;
    }

    /** Extract the private-chat Rubika user id from any documented response envelope. */
    private static function extract_chat_rubika_user_id($response) {
        if (!is_array($response)) { return ''; }
        $candidates=array($response);
        if (!empty($response['data']) && is_array($response['data'])) { $candidates[]=$response['data']; }
        if (!empty($response['chat']) && is_array($response['chat'])) { $candidates[]=$response['chat']; }
        if (!empty($response['data']['chat']) && is_array($response['data']['chat'])) { $candidates[]=$response['data']['chat']; }
        foreach ($candidates as $candidate) {
            if (!empty($candidate['user_id'])) {
                $user_id=self::sanitize_external_id($candidate['user_id']);
                if ($user_id) { return $user_id; }
            }
        }
        return '';
    }

    /** Validate one chat using the official getChat method. */
    private static function get_chat_rubika_user_id($chat_id, &$error) {
        $error='';
        $result=self::api_call('getChat',array('chat_id'=>$chat_id));
        if (is_wp_error($result)) { $error=$result->get_error_message(); return ''; }
        $user_id=self::extract_chat_rubika_user_id($result);
        if (!$user_id) { $error='getChat: private user_id missing'; }
        return $user_id;
    }

    /** Record chat-id repair without exposing the Rubika identifier in admin. */
    private static function record_rebind_status($status,$source,$error='') {
        $s=self::settings();
        $s['last_rebind_at']=current_time('mysql');
        $s['last_rebind_status']=sanitize_key((string)$status);
        $s['last_rebind_source']=sanitize_key((string)$source);
        $s['last_rebind_error']=sanitize_text_field((string)$error);
        update_option(self::OPTION,$s,false);
    }

    /**
     * Restore a binding after BotFather recreation. Rubika's private-chat
     * user_id/sender_id is matched against the proof retained at the original
     * AskMyPhoneNumber connection, so the phone never needs to be requested
     * again merely because the bot's chat_id changed.
     */
    private static function maybe_rebind_chat_by_sender($chat_id,$sender_id,$source) {
        global $wpdb;
        $chat_id=self::sanitize_external_id($chat_id);
        $sender_id=self::sanitize_external_id($sender_id);
        if (!$chat_id || !$sender_id) { return 0; }
        $known=self::known_user_id_by_chat($chat_id);
        if ($known) { return $known; }
        $table=Hamnoor_DB::table('notification_channels');
        if (!$table) { self::record_rebind_status('missing_table',$source,'notification_channels missing'); return 0; }
        $needle='%' . $wpdb->esc_like($sender_id) . '%';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,user_id,external_id,metadata FROM $table WHERE channel='rubika' AND metadata LIKE %s ORDER BY updated_at DESC,id DESC",
            $needle
        ));
        $matches=array();
        foreach ((array)$rows as $row) {
            $meta=json_decode((string)$row->metadata,true);
            $meta=is_array($meta)?$meta:array();
            $stored='';
            foreach (array('rubika_sender_id','rubika_user_id') as $key) {
                if (!empty($meta[$key])) { $stored=self::sanitize_external_id($meta[$key]); if ($stored) { break; } }
            }
            if ($stored && hash_equals($stored,$sender_id)) { $matches[]=$row; }
        }
        if (1!==count($matches)) {
            self::record_rebind_status($matches?'ambiguous':'not_found',$source,$matches?'more than one identity match':'stored sender_id not found');
            return 0;
        }
        $row=$matches[0];
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'rubika', $row)) {
            self::record_rebind_status('needs_phone_reconnect',$source,'stored phone proof does not match current Hamnoor phone');
            return 0;
        }
        $conflict=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $table WHERE channel='rubika' AND external_id=%s AND id<>%d LIMIT 1",
            $chat_id,(int)$row->id
        ));
        if ($conflict && $conflict!==(int)$row->user_id) {
            self::record_rebind_status('conflict',$source,'new chat_id belongs to another account');
            return 0;
        }
        $meta=json_decode((string)$row->metadata,true); $meta=is_array($meta)?$meta:array();
        $old_chat=self::sanitize_external_id((string)$row->external_id);
        if ($old_chat && !hash_equals($old_chat,$chat_id)) { $meta['previous_chat_id']=$old_chat; }
        $meta['rubika_sender_id']=$sender_id;
        $meta['chat_rebound_at']=current_time('mysql');
        $meta['chat_rebound_by']=sanitize_key((string)$source);
        $ok=$wpdb->update(
            $table,
            array('external_id'=>$chat_id,'metadata'=>wp_json_encode($meta,JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')),
            array('id'=>(int)$row->id),
            array('%s','%s','%s'),array('%d')
        );
        if (false===$ok) { self::record_rebind_status('db_error',$source,(string)$wpdb->last_error); return 0; }
        delete_transient(self::pending_phone_key($chat_id));
        Hamnoor_Notifications::reset_channels_cache();
        self::record_rebind_status('success',$source,'');
        return (int)$row->user_id;
    }

    private static function user_id_by_chat($chat_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row=$wpdb->get_row($wpdb->prepare("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE channel='rubika' AND external_id=%s AND enabled=1 AND status='connected' LIMIT 1", $chat_id));
        if (!$row) { return 0; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$row->user_id, 'rubika', $row)) { return 0; }
        return (int)$row->user_id;
    }

    private static function user_chat_id($user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $row=$wpdb->get_row($wpdb->prepare("SELECT user_id,channel,status,external_id,metadata FROM $table WHERE user_id=%d AND channel='rubika' AND enabled=1 AND status='connected' LIMIT 1", $user_id));
        if (!$row) { return ''; }
        if (function_exists('hamnoor_notifications_channel_identity_valid') && !hamnoor_notifications_channel_identity_valid((int)$user_id, 'rubika', $row)) { return ''; }
        return self::sanitize_external_id((string)$row->external_id);
    }

    private static function process_entries($entries) {
        $processed=0;
        foreach ((array)$entries as $entry) {
            $update=!empty($entry['update'])&&is_array($entry['update'])?$entry['update']:$entry;
            if (!is_array($update)) { continue; }
            $dedupe=self::dedupe_key($update);
            if ($dedupe && get_transient($dedupe)) { continue; }
            if ($dedupe) { set_transient($dedupe,1,7*DAY_IN_SECONDS); }
            try { $result=self::process_update($update); }
            catch (Throwable $error) { $result=new WP_Error('hamnoor_rubika_event_exception',sanitize_text_field($error->getMessage())); }
            $s=self::settings();
            $s['last_update_at']=current_time('mysql');
            $s['last_update_type']=sanitize_text_field(isset($update['type'])?(string)$update['type']:'unknown');
            $s['last_update_chat_id']=self::sanitize_external_id(isset($update['chat_id'])?$update['chat_id']:'');
            $s['last_update_result']=is_wp_error($result)?'error':sanitize_text_field((string)$result);
            $s['last_update_error']=is_wp_error($result)?$result->get_error_message():'';
            $message=!empty($update['new_message'])&&is_array($update['new_message'])?$update['new_message']:array();
            $aux=!empty($message['aux_data'])&&is_array($message['aux_data'])?$message['aux_data']:array();
            $s['last_update_message_kind']=!empty($message['contact_message'])&&is_array($message['contact_message'])?'ContactMessage':($message?'Message':'—');
            $s['last_update_button_id']=isset($aux['button_id'])?sanitize_key((string)$aux['button_id']):'';
            update_option(self::OPTION,$s,false); $processed++;
        }
        return $processed;
    }

    private static function update_timestamp($update) {
        foreach (array('new_message','updated_message') as $key) {
            if (!empty($update[$key]) && is_array($update[$key]) && isset($update[$key]['time']) && is_numeric($update[$key]['time'])) {
                $timestamp=(int)$update[$key]['time'];
                if ($timestamp>0) { return $timestamp; }
            }
        }
        return 0;
    }

    private static function dedupe_key($update) {
        $type = isset($update['type']) ? (string)$update['type'] : '';
        $chat = isset($update['chat_id']) ? (string)$update['chat_id'] : '';
        $message_id = '';
        if (!empty($update['new_message']['message_id'])) { $message_id = (string)$update['new_message']['message_id']; }
        elseif (!empty($update['updated_message']['message_id'])) { $message_id = (string)$update['updated_message']['message_id']; }
        elseif (!empty($update['removed_message_id'])) { $message_id = (string)$update['removed_message_id']; }
        if (!$type || !$chat || !$message_id) { return ''; }
        return 'hamnoor_rubika_event_' . substr(hash('sha256', $type . '|' . $chat . '|' . $message_id), 0, 40);
    }

    public static function api_call($method, $params = array()) {
        $token = self::token();
        if (!$token) { return new WP_Error('hamnoor_rubika_no_token', __('توکن روبیکا تنظیم نشده است.', 'hamnoor')); }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', (string)$method)) { return new WP_Error('hamnoor_rubika_bad_method', __('متد API نامعتبر است.', 'hamnoor')); }
        $args = array('method'=>'POST','timeout'=>20,'redirection'=>0,'headers'=>array('Content-Type'=>'application/json; charset=utf-8'));
        if ($params) { $args['body'] = wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        $response = wp_remote_request(self::API_BASE . $token . '/' . $method, $args);
        if (is_wp_error($response)) { return $response; }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('hamnoor_rubika_http_' . $code, sprintf(__('API روبیکا پاسخ HTTP %d داد.', 'hamnoor'), $code), array('http_code'=>$code));
        }
        if (!is_array($json)) { return new WP_Error('hamnoor_rubika_invalid_json', __('پاسخ API روبیکا JSON معتبر نبود.', 'hamnoor')); }
        if (!empty($json['status']) && !in_array(strtolower((string)$json['status']), array('ok','success'), true)) {
            $message = !empty($json['status_det']) ? (string)$json['status_det'] : (!empty($json['message']) ? (string)$json['message'] : (string)$json['status']);
            return new WP_Error('hamnoor_rubika_api_error', sanitize_text_field($message), array('http_code'=>$code));
        }
        return $json;
    }

    private static function send_message($chat_id, $text, $options = array()) {
        $params = array_merge(array('chat_id'=>$chat_id,'text'=>$text), $options);
        $result = self::api_call('sendMessage', $params);
        $s = self::settings();
        $s['last_send_at'] = current_time('mysql');
        $s['last_send_status'] = is_wp_error($result) ? 'error' : 'success';
        $s['last_send_error'] = is_wp_error($result) ? $result->get_error_message() : '';
        update_option(self::OPTION, $s, false);
        return $result;
    }

    public static function admin_save() {
        self::admin_guard('hamnoor_rubika_save');
        $s = self::settings();
        $s['enabled'] = !empty($_POST['rubika_enabled']) ? '1' : '0';
        $s['worker_enabled'] = !empty($_POST['rubika_worker_enabled']) ? '1' : '0';
        $s['test_chat_id'] = self::sanitize_external_id(isset($_POST['rubika_test_chat_id']) ? wp_unslash($_POST['rubika_test_chat_id']) : '');
        $username = self::sanitize_username(isset($_POST['rubika_bot_username']) ? wp_unslash($_POST['rubika_bot_username']) : '');
        if ($username) { $s['bot_username'] = $username; }
        $token = self::normalize_token(isset($_POST['rubika_token']) ? wp_unslash($_POST['rubika_token']) : '');
        if ($token) {
            $valid = self::validate_token($token);
            if (is_wp_error($valid)) { self::redirect($valid->get_error_message(), 'error'); }
            $encrypted = self::encrypt_secret($token);
            if (is_wp_error($encrypted)) { self::redirect($encrypted->get_error_message(), 'error'); }
            $s['token_enc'] = $encrypted;
        }
        $s['architecture'] = 'official_getupdates_v1';
        update_option(self::OPTION, $s, false);
        self::ensure_worker_schedule();
        Hamnoor_Notifications::reset_channels_cache();
        self::redirect(__('تنظیمات روبیکا ذخیره شد.', 'hamnoor'));
    }

    public static function admin_test_connection() {
        self::admin_guard('hamnoor_rubika_test_connection');
        $result = self::api_call('getMe');
        if (is_wp_error($result)) { self::redirect($result->get_error_message(), 'error'); }
        $bot = !empty($result['bot']) && is_array($result['bot']) ? $result['bot'] : $result;
        $s = self::settings(); $s['last_getme_at'] = current_time('mysql');
        foreach (array('bot_id','bot_title','share_url') as $key) { if (!empty($bot[$key])) { $s[$key] = sanitize_text_field((string)$bot[$key]); } }
        if (!empty($bot['username'])) { $s['bot_username'] = self::sanitize_username($bot['username']); }
        update_option(self::OPTION, $s, false);
        self::redirect(__('اتصال رسمی getMe روبیکا موفق بود.', 'hamnoor'));
    }

    public static function admin_run_worker() {
        self::admin_guard('hamnoor_rubika_run_worker');
        delete_transient(self::WORKER_LOCK);
        $result=self::run_worker();
        if (is_wp_error($result)) { self::redirect($result->get_error_message(),'error'); }
        self::redirect(sprintf(__('Worker روبیکا اجرا شد: %s', 'hamnoor'),(string)$result));
    }

    public static function admin_reset_worker() {
        self::admin_guard('hamnoor_rubika_reset_worker');
        $s=self::settings();
        if ('1' !== (string)$s['worker_bootstrap']) {
            self::redirect(__('دریافت روبیکا آماده است و نیازی به عبور از پیام‌های قدیمی ندارد.', 'hamnoor'));
        }
        // Never clear a valid cursor: doing so replays the entire retained
        // history and can discard messages sent by the user during recovery.
        $s['worker_bootstrap_started_ts']=time();
        $s['worker_ready_at']=''; $s['last_poll_result']='backlog_skip_requested'; $s['last_poll_error']='';
        update_option(self::OPTION,$s,false); self::ensure_worker_schedule();
        self::redirect(__('عبور ایمن از پیام‌های انباشته آغاز شد. پیام‌های تازه‌ای که از این لحظه ارسال شوند پردازش خواهند شد.', 'hamnoor'));
    }

    public static function admin_sync_keypads() {
        self::admin_guard('hamnoor_rubika_sync_keypads');
        if (!self::configured()) {
            self::redirect(__('برای بازسازی منوی روبیکا، ابتدا توکن معتبر بات را ذخیره کنید.', 'hamnoor'), 'error');
        }
        global $wpdb;
        $table=Hamnoor_DB::table('notification_channels');
        $started=current_time('mysql');
        self::store_keypad_sync_progress(array(
            'last_keypad_sync_at'=>$started,
            'last_keypad_sync_started_at'=>$started,
            'last_keypad_sync_status'=>'running',
            'last_keypad_sync_scanned'=>0,
            'last_keypad_sync_valid'=>0,
            'last_keypad_sync_stale'=>0,
            'last_keypad_sync_success'=>0,
            'last_keypad_sync_failed'=>0,
            'last_keypad_sync_stage'=>'starting',
            'last_keypad_sync_error'=>'',
        ));
        $rows=$wpdb->get_results("SELECT id,user_id,external_id,metadata FROM $table WHERE channel='rubika' AND external_id IS NOT NULL AND external_id<>'' ORDER BY updated_at DESC,id DESC LIMIT 25");
        $scanned=0; $valid=0; $stale=0; $success=0; $failed=0; $last_error=''; $stage='starting';
        foreach ((array)$rows as $row) {
            $scanned++;
            $chat_id=self::sanitize_external_id((string)$row->external_id);
            if (!$chat_id) {
                $stale++; $failed++; $stage='sanitize_chat_id'; $last_error='شناسه گفت‌وگو نامعتبر است.';
                self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array('last_error'=>$last_error));
                continue;
            }
            $get_chat_error='';
            $rubika_user_id=self::get_chat_rubika_user_id($chat_id,$get_chat_error);
            if (!$rubika_user_id) {
                $stale++; $failed++; $stage='getChat'; $last_error='getChat: '.$get_chat_error;
                self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array('last_error'=>$last_error));
                if (false!==stripos($last_error,'TOO_REQUESTS')) { break; }
                continue;
            }
            $meta=json_decode((string)$row->metadata,true); $meta=is_array($meta)?$meta:array();
            $stored_sender='';
            foreach (array('rubika_sender_id','rubika_user_id') as $key) {
                if (!empty($meta[$key])) { $stored_sender=self::sanitize_external_id($meta[$key]); if ($stored_sender) { break; } }
            }
            if ($stored_sender && !hash_equals($stored_sender,$rubika_user_id)) {
                $stale++; $failed++; $stage='identity_check'; $last_error='getChat user_id با هویت ذخیره‌شده یکسان نیست.';
                self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array('last_error'=>$last_error));
                continue;
            }
            $valid++;
            $result=self::sync_chat_keypad($chat_id);
            if (is_wp_error($result)) {
                $failed++; $stage='editChatKeypad'; $last_error='editChatKeypad: '.$result->get_error_message();
                self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array('last_error'=>$last_error));
                if (false!==stripos($last_error,'TOO_REQUESTS')) { break; }
                continue;
            }
            delete_transient(self::pending_phone_key($chat_id)); $success++; $stage='editChatKeypad';
            self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array('last_error'=>$last_error));
        }
        $status=!$rows?'empty':(!$success?'failed':($failed?'partial':'completed'));
        self::store_keypad_sync_progress(compact('scanned','valid','stale','success','failed','stage')+array(
            'status'=>$status,
            'last_error'=>$last_error,
            'completed_at'=>current_time('mysql'),
        ));
        if (!$rows) { self::redirect(__('هنوز هیچ اتصال شناخته‌شده‌ای برای بازسازی منوی روبیکا وجود ندارد.', 'hamnoor'),'error'); }
        if (!$success) {
            $message=$stale
                ? sprintf(__('هیچ شناسه گفت‌وگوی معتبری برای بات فعلی پیدا نشد (%d مورد قدیمی یا نامعتبر). در روبیکا یک‌بار «شروع» یا /start را بزنید تا اتصال قبلی بدون دریافت دوباره شماره بازیابی شود.','hamnoor'),$stale)
                : ($last_error?:__('بازسازی منوی روبیکا انجام نشد.','hamnoor'));
            self::redirect($message,'error');
        }
        self::redirect(sprintf(__('منوی روبیکا برای %1$d اتصال بازسازی شد%2$s.','hamnoor'),$success,$failed?sprintf(__('؛ %d مورد ناموفق بود','hamnoor'),$failed):''),$failed?'warning':'success');
    }

    /** Merge progress with concurrent send/worker diagnostics instead of overwriting them. */
    private static function store_keypad_sync_progress($progress) {
        $s=self::settings();
        $map=array(
            'scanned'=>'last_keypad_sync_scanned', 'valid'=>'last_keypad_sync_valid',
            'stale'=>'last_keypad_sync_stale', 'success'=>'last_keypad_sync_success',
            'failed'=>'last_keypad_sync_failed', 'stage'=>'last_keypad_sync_stage',
            'last_error'=>'last_keypad_sync_error', 'status'=>'last_keypad_sync_status',
            'completed_at'=>'last_keypad_sync_at',
        );
        foreach ($progress as $key=>$value) {
            $target=isset($map[$key])?$map[$key]:$key;
            if (in_array($target,array('last_keypad_sync_scanned','last_keypad_sync_valid','last_keypad_sync_stale','last_keypad_sync_success','last_keypad_sync_failed'),true)) {
                $s[$target]=(int)$value;
            } else {
                $s[$target]=sanitize_text_field((string)$value);
            }
        }
        update_option(self::OPTION,$s,false);
    }

    public static function admin_test_message() {
        self::admin_guard('hamnoor_rubika_test_message');
        $s = self::settings();
        $chat_id = self::sanitize_external_id(isset($_POST['rubika_test_chat_id']) ? wp_unslash($_POST['rubika_test_chat_id']) : $s['test_chat_id']);
        if (!$chat_id) { self::redirect(__('شناسه مقصد آزمایشی وارد نشده است.', 'hamnoor'), 'error'); }
        $result = self::send_message($chat_id, __('✅ پیام آزمایشی هم‌نور از Bot API رسمی روبیکا', 'hamnoor'));
        if (is_wp_error($result)) { self::redirect($result->get_error_message(), 'error'); }
        $s = self::settings(); $s['last_test_at']=current_time('mysql'); update_option(self::OPTION, $s, false);
        self::redirect(__('پیام آزمایشی ارسال شد.', 'hamnoor'));
    }

    public static function render_admin_section() {
        $s=self::settings(); $configured=self::configured();
        echo '<hr><h2>' . esc_html__('اعلان‌های روبیکا', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('اتصال روبیکا برای دریافت درخواست‌های کاربران و ارسال اعلان‌های هم‌نور استفاده می‌شود.', 'hamnoor') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_rubika_save');
        echo '<input type="hidden" name="action" value="hamnoor_rubika_save"><table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('فعال‌سازی روبیکا', 'hamnoor') . '</th><td><label><input type="checkbox" name="rubika_enabled" value="1" ' . checked('1',(string)$s['enabled'],false) . '> ' . esc_html__('ارسال اعلان از طریق روبیکا فعال باشد', 'hamnoor') . '</label></td></tr>';
        echo '<tr><th>' . esc_html__('دریافت پیام‌ها', 'hamnoor') . '</th><td><label><input type="checkbox" name="rubika_worker_enabled" value="1" ' . checked('1',(string)$s['worker_enabled'],false) . '> ' . esc_html__('دریافت خودکار پیام‌های روبیکا فعال باشد', 'hamnoor') . '</label></td></tr>';
        echo '<tr><th>' . esc_html__('توکن بات', 'hamnoor') . '</th><td><input name="rubika_token" type="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($configured ? __('برای حفظ توکن فعلی خالی بگذارید', 'hamnoor') : __('توکن BotFather', 'hamnoor')) . '"></td></tr>';
        echo '<tr><th>' . esc_html__('نام کاربری بات', 'hamnoor') . '</th><td><input name="rubika_bot_username" type="text" class="regular-text" value="' . esc_attr((string)$s['bot_username']) . '"></td></tr>';
        echo '<tr><th>' . esc_html__('شناسه دریافت‌کننده بررسی', 'hamnoor') . '</th><td><input name="rubika_test_chat_id" type="text" class="regular-text" value="' . esc_attr((string)$s['test_chat_id']) . '"><p class="description">' . esc_html__('فقط برای بررسی دستی ارسال پیام استفاده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '</tbody></table>'; submit_button(__('ذخیره تنظیمات روبیکا', 'hamnoor')); echo '</form>';
        echo '<details style="margin-top:18px"><summary><strong>' . esc_html__('ابزارها و جزئیات فنی روبیکا', 'hamnoor') . '</strong></summary>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        if ($configured) {
            self::admin_button('hamnoor_rubika_test_connection', __('بررسی اتصال روبیکا', 'hamnoor'));
            self::admin_button('hamnoor_rubika_run_worker', __('بررسی دریافت پیام‌ها', 'hamnoor'), 'primary');
            if ('1' === (string)$s['worker_bootstrap']) { self::admin_button('hamnoor_rubika_reset_worker', __('عبور ایمن از پیام‌های قدیمی', 'hamnoor')); }
            if (!empty($s['test_chat_id'])) { self::admin_button('hamnoor_rubika_test_message', __('ارسال پیام بررسی', 'hamnoor'), 'secondary', array('rubika_test_chat_id'=>$s['test_chat_id'])); }
        }
        self::admin_action_link('hamnoor_rubika_sync_keypads', __('بازسازی و اعتبارسنجی منوی روبیکا', 'hamnoor'));
        echo '</div>';
        echo '<p class="description">' . esc_html__('این ابزار ابتدا هر شناسه گفت‌وگو را با getChat رسمی اعتبارسنجی می‌کند و فقط برای شناسه‌های معتبر، منوی مستند Simple را نصب می‌کند.', 'hamnoor') . '</p>';
        if ($configured && '1' === (string)$s['worker_bootstrap']) { echo '<p class="description">' . esc_html__('در زمان عبور از سوابق، پیام‌هایی که بعد از شروع این عملیات ارسال شوند حفظ و پردازش می‌شوند. پس از آماده‌شدن Worker، این دکمه خودکار پنهان می‌شود.', 'hamnoor') . '</p>'; }
        echo '<table class="widefat striped" style="margin-top:14px"><tbody>';
        self::status_row(__('شناسه ساخت فعال', 'hamnoor'), defined('HAMNOOR_NOTIFICATIONS_BUILD') ? HAMNOOR_NOTIFICATIONS_BUILD : 'legacy');
        self::status_row(__('معماری', 'hamnoor'), 'official_getupdates_v1');
        self::status_row(__('وضعیت آماده‌سازی Worker', 'hamnoor'), '1' === (string)$s['worker_bootstrap'] ? 'در حال ردکردن پیام‌های انباشته' : 'آماده دریافت پیام جدید');
        self::status_row(__('شروع عبور ایمن از سوابق', 'hamnoor'), !empty($s['worker_bootstrap_started_ts']) ? wp_date('Y-m-d H:i:s',(int)$s['worker_bootstrap_started_ts']) : '—');
        self::status_row(__('زمان آماده‌شدن Worker', 'hamnoor'), $s['worker_ready_at'] ?: '—');
        self::status_row(__('Cursor فعلی', 'hamnoor'), $s['poll_offset_id'] ?: '—');
        self::status_row(__('آخرین Poll', 'hamnoor'), ($s['last_poll_at'] ?: '—') . ' — ' . ($s['last_poll_result'] ?: '—') . ' — ' . (int)$s['last_poll_count'] . ' رویداد');
        self::status_row(__('آخرین خطای Poll', 'hamnoor'), $s['last_poll_error'] ?: '—');
        self::status_row(__('پیام‌های انباشته ردشده', 'hamnoor'), (string)(int)$s['worker_discarded']);
        self::status_row(__('زمان‌بندی DirectAdmin', 'hamnoor'), '* * * * *');
        self::status_row(__('دستور Cron دایرکت‌ادمین', 'hamnoor'), self::worker_cron_command());
        self::status_row(__('آخرین Heartbeat Worker واقعی', 'hamnoor'), ($s['last_cli_worker_at'] ?: 'هنوز اجرا نشده') . ' — ' . (int)$s['last_cli_worker_cycles'] . ' چرخه' . ($s['last_cli_worker_error'] ? ' — ' . $s['last_cli_worker_error'] : ''));
        $cli_live=!empty($s['last_cli_worker_ts'])&&time()-(int)$s['last_cli_worker_ts']<90;
        self::status_row(__('مسیر دریافت فعال', 'hamnoor'), $cli_live ? 'Worker واقعی DirectAdmin' : 'WP-Cron پشتیبان');
        self::status_row(__('زمان‌بندی WP-Cron پشتیبان', 'hamnoor'), wp_next_scheduled(self::WORKER_HOOK) ? 'فعال — هنگام نبود Worker واقعی' : 'ثبت نشده');
        self::status_row(__('آخرین رویداد', 'hamnoor'), ($s['last_update_at'] ?: '—') . ' — ' . ($s['last_update_type'] ?: '—') . ' — ' . ($s['last_update_result'] ?: '—'));
        self::status_row(__('ساختار آخرین پیام دریافتی', 'hamnoor'), ($s['last_update_message_kind'] ?: '—') . ' — button_id: ' . ($s['last_update_button_id'] ?: '—'));
        self::status_row(__('آخرین خطای رویداد', 'hamnoor'), $s['last_update_error'] ?: '—');
        self::status_row(__('آخرین ارسال', 'hamnoor'), ($s['last_send_at'] ?: '—') . ' — ' . ($s['last_send_status'] ?: '—') . ($s['last_send_error'] ? ' — ' . $s['last_send_error'] : ''));
        self::status_row(__('آخرین جایگزینی دکمه شماره با داشبورد', 'hamnoor'), ($s['last_keypad_remove_at'] ?: '—') . ' — ' . ($s['last_keypad_remove_status'] ?: '—') . ($s['last_keypad_remove_error'] ? ' — ' . $s['last_keypad_remove_error'] : ''));
        self::status_row(__('وضعیت آخرین بازسازی منوی روبیکا', 'hamnoor'), ($s['last_keypad_sync_at'] ?: '—') . ' — ' . ($s['last_keypad_sync_status'] ?: '—') . ' — مرحله: ' . ($s['last_keypad_sync_stage'] ?: '—'));
        self::status_row(__('نتیجه اعتبارسنجی شناسه‌های روبیکا', 'hamnoor'), (int)$s['last_keypad_sync_scanned'] . ' بررسی — ' . (int)$s['last_keypad_sync_valid'] . ' معتبر — ' . (int)$s['last_keypad_sync_stale'] . ' قدیمی/نامعتبر');
        self::status_row(__('نتیجه نصب منوی رسمی روبیکا', 'hamnoor'), (int)$s['last_keypad_sync_success'] . ' موفق — ' . (int)$s['last_keypad_sync_failed'] . ' ناموفق' . ($s['last_keypad_sync_error'] ? ' — ' . $s['last_keypad_sync_error'] : ''));
        self::status_row(__('آخرین بازیابی خودکار اتصال روبیکا', 'hamnoor'), ($s['last_rebind_at'] ?: '—') . ' — ' . ($s['last_rebind_status'] ?: '—') . ' — ' . ($s['last_rebind_source'] ?: '—') . ($s['last_rebind_error'] ? ' — ' . $s['last_rebind_error'] : ''));
        self::status_row(__('کاربران متصل', 'hamnoor'), (string)self::connected_users_count());
        self::status_row(__('حساب‌های روبیکای شناخته‌شده', 'hamnoor'), (string)self::known_users_count());
        $consent=Hamnoor_Notification_Dispatcher::channel_consent_counts('rubika');
        self::status_row(__('کاربران منصرف‌شده', 'hamnoor'), (string)(int)$consent['unsubscribed']);
        echo '</tbody></table></details>';
    }

    private static function admin_button($action,$label,$class='secondary',$fields=array()) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        foreach ($fields as $key=>$value) { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">'; }
        submit_button($label,$class,'submit',false); echo '</form>';
    }

    /** A nonce-protected admin action link avoids form nesting/theme issues. */
    private static function admin_action_link($action,$label,$class='secondary') {
        $url=wp_nonce_url(add_query_arg('action',$action,admin_url('admin-post.php')),$action);
        echo '<a class="button button-' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private static function status_row($label,$value) { echo '<tr><th>' . esc_html($label) . '</th><td><code>' . esc_html((string)$value) . '</code></td></tr>'; }
    private static function admin_guard($nonce) { if (!current_user_can('manage_options')) { wp_die('forbidden'); } check_admin_referer($nonce); }
    private static function redirect($message,$type='success') { Hamnoor_Helpers::redirect_with_notice(self::admin_url(),$message,$type); exit; }
    private static function admin_url() { return add_query_arg('channel','rubika',admin_url('admin.php?page=hamnoor-notifications')); }

    private static function sanitize_external_id($value) {
        $value=trim(sanitize_text_field((string)$value));
        return ($value && strlen($value)<=191 && preg_match('/^[A-Za-z0-9_.:\-]+$/',$value)) ? $value : '';
    }
    private static function sanitize_username($value) { $value=ltrim(trim((string)$value),'@'); return preg_match('/^[A-Za-z0-9_.\-]{2,100}$/',$value)?$value:''; }
    private static function normalize_token($token) { return trim(preg_replace('/(?:\x{FEFF}|\x{200B}|\x{200C}|\x{200D}|\x{200E}|\x{200F})/u','',(string)$token)); }
    private static function validate_token($token) {
        $token=self::normalize_token($token); $len=strlen($token);
        return ($len>=16 && $len<=1024 && preg_match('/^[\x21-\x7E]+$/',$token) && false===strpbrk($token,"/\\?#%")) ? true : new WP_Error('hamnoor_rubika_bad_token',__('ساختار توکن روبیکا معتبر نیست.', 'hamnoor'));
    }
    private static function encryption_key() { return hash('sha256',(defined('AUTH_KEY')?AUTH_KEY:'').'|'.(defined('SECURE_AUTH_KEY')?SECURE_AUTH_KEY:'').'|hamnoor-rubika',true); }
    private static function encrypt_secret($plain) {
        if (!extension_loaded('openssl')) { return new WP_Error('hamnoor_rubika_openssl',__('OpenSSL برای ذخیره امن توکن لازم است.','hamnoor')); }
        try { $iv=random_bytes(12); } catch (Throwable $e) { return new WP_Error('hamnoor_rubika_random',__('تولید داده امن انجام نشد.','hamnoor')); }
        $tag=''; $cipher=openssl_encrypt((string)$plain,'aes-256-gcm',self::encryption_key(),OPENSSL_RAW_DATA,$iv,$tag,'hamnoor-rubika',16);
        return false===$cipher ? new WP_Error('hamnoor_rubika_encrypt',__('رمزنگاری توکن انجام نشد.','hamnoor')) : base64_encode($iv.$tag.$cipher);
    }
    private static function decrypt_secret($encoded) {
        if (!extension_loaded('openssl')||!$encoded) { return ''; } $raw=base64_decode((string)$encoded,true);
        if (false===$raw||strlen($raw)<29) { return ''; }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::encryption_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'hamnoor-rubika');
        return is_string($plain)?$plain:'';
    }
}
