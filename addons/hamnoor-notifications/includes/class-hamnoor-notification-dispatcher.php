<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Notification Engine 2.0
 *
 * Rules:
 * - Web Push is supplemental and never blocks a durable messenger.
 * - Durable messengers use first-success fallback semantics.
 * - Failed transient deliveries are persisted and retried by WP-Cron.
 * - A later durable success cancels pending durable retries to avoid duplicates.
 */
class Hamnoor_Notification_Dispatcher {
    const CRON_HOOK = 'hamnoor_notification_queue_worker';
    const CRON_SCHEDULE = 'hamnoor_5min';
    const MAX_ATTEMPTS = 4;

    private static $channels = null;

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_action('hamnoor_notification_created', array(__CLASS__, 'handle_created_notification'), 10, 2);
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'process_queue'));
        self::ensure_schedule();
    }

    public static function activate() {
        $flags = get_option('hamnoor_addon_states', array());
        if (is_array($flags) && isset($flags['notifications']) && '0' === (string)$flags['notifications']) { return; }
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        self::ensure_schedule(true);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function ensure_schedule($fail_hard = false) {
        if (wp_next_scheduled(self::CRON_HOOK)) { return true; }
        $result = wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK, array(), true);
        if (is_wp_error($result) || true !== $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown scheduling failure';
            update_option('hamnoor_notifications_cron_last_error', substr($message, 0, 500), false);
            if ($fail_hard) {
                throw new RuntimeException('Hamnoor Notifications queue schedule failed: ' . $message);
            }
            return false;
        }
        delete_option('hamnoor_notifications_cron_last_error');
        return true;
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('هر ۵ دقیقه (هم‌نور)', 'hamnoor'),
            );
        }
        return $schedules;
    }

    public static function priority() {
        $priority = array('web_push', 'eitaa', 'bale', 'rubika', 'whatsapp', 'telegram', 'app_push');
        $priority = apply_filters('hamnoor_notification_channel_priority', $priority);
        $clean = array();
        foreach ((array)$priority as $key) {
            $key = sanitize_key($key);
            if ($key && !in_array($key, $clean, true)) { $clean[] = $key; }
        }
        return $clean;
    }

    public static function channels() {
        if (null !== self::$channels) { return self::$channels; }
        self::$channels = class_exists('Hamnoor_Notification_Channel_Registry')
            ? Hamnoor_Notification_Channel_Registry::all()
            : array();
        return self::$channels;
    }

    public static function reset_channels_cache() { self::$channels = null; }

    public static function handle_created_notification($notification_id, $user_id) {
        $notification = self::get_notification((int)$notification_id);
        if (!$notification || (int)$notification->user_id !== (int)$user_id) { return; }
        if (!self::should_dispatch($notification)) { return; }
        self::dispatch($notification);
    }

    public static function should_dispatch($notification) {
        $default_types = array('part_reminder','part_release_warning','part_released','khatm_published','khatm_needs_revision','khatm_rejected','khatm_cancelled','khatm_expired','khatm_completed','khatm_updated','khatm_thanks','security_notice');
        $types = apply_filters('hamnoor_external_notification_types', $default_types, $notification);
        return in_array((string)$notification->type, array_map('sanitize_key', (array)$types), true);
    }

    /**
     * Queue the supplemental browser push + first eligible durable channel, then
     * process due jobs immediately. WP-Cron owns subsequent retries.
     */
    public static function dispatch($notification, $context = array()) {
        if (!$notification || empty($notification->user_id)) {
            return new WP_Error('hamnoor_dispatch_invalid_notification', __('اعلان برای ارسال معتبر نیست.', 'hamnoor'));
        }
        $user_id = (int)$notification->user_id;
        $queued = 0;

        if (self::channel_eligible($user_id, 'web_push')) {
            $queued += self::enqueue_delivery($notification, 'web_push', $context) ? 1 : 0;
        }
        $durable = self::first_eligible_durable($user_id);
        if ($durable) {
            $queued += self::enqueue_delivery($notification, $durable, $context) ? 1 : 0;
        }

        if (!$queued && !self::notification_has_external_delivery((int)$notification->id)) {
            self::record_internal_only($notification, 'no_external_channel');
            return new WP_Error('hamnoor_no_external_channel', __('هیچ کانال بیرونی فعالی برای این کاربر وجود ندارد.', 'hamnoor'));
        }

        self::process_notification_queue((int)$notification->id, 12);
        $summary = self::delivery_summary((int)$notification->id);
        if ('failed' === (string)$summary['status']) {
            return new WP_Error('hamnoor_dispatch_failed', $summary['error'] ?: __('ارسال اعلان در کانال‌های بیرونی ناموفق بود.', 'hamnoor'));
        }
        return true;
    }

    /** Diagnostic test: every eligible channel is attempted immediately. */
    public static function dispatch_all($notification, $context = array()) {
        if (!$notification || empty($notification->user_id)) {
            return new WP_Error('hamnoor_dispatch_invalid_notification', __('اعلان برای ارسال معتبر نیست.', 'hamnoor'));
        }
        $user_id = (int)$notification->user_id;
        $results = array();
        $attempted = 0; $sent = 0; $failed = 0;
        foreach (self::priority() as $key) {
            if (!self::channel_eligible($user_id, $key)) { continue; }
            $attempted++;
            $delivery_id = self::enqueue_delivery($notification, $key, $context, true);
            if (!$delivery_id) { continue; }
            self::process_delivery_by_id($delivery_id, true);
            $row = self::get_delivery($delivery_id);
            $status = $row ? sanitize_key((string)$row->status) : 'failed';
            if ('sent' === $status) { $sent++; } else { $failed++; }
            $results[] = array(
                'channel'=>sanitize_key($key),
                'status'=>$status,
                'delivery_id'=>(int)$delivery_id,
                'error'=>$row && !empty($row->error_message) ? (string)$row->error_message : '',
            );
        }
        if (!$attempted) { self::record_internal_only($notification, 'no_external_channel'); }
        return array(
            'status'=>!$attempted ? 'internal_only' : ($failed ? ($sent ? 'partial' : 'failed') : 'sent'),
            'attempted'=>$attempted,'sent'=>$sent,'failed'=>$failed,'rows'=>$results,
        );
    }

    public static function dispatch_all_by_id($notification_id, $context = array()) {
        $notification = self::get_notification((int)$notification_id);
        if (!$notification) { return new WP_Error('hamnoor_dispatch_notification_missing', __('اعلان موردنظر برای تست پیدا نشد.', 'hamnoor')); }
        return self::dispatch_all($notification, $context);
    }

    /** Process due queue jobs. Safe to call from cron or admin. */
    public static function process_queue($limit = 40) {
        global $wpdb;
        self::recover_stale_sending();
        $limit = max(1, min(100, (int)$limit));
        $table = Hamnoor_DB::table('notification_deliveries');
        $now = current_time('mysql');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table
             WHERE status IN ('queued','retry_wait')
               AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
             ORDER BY COALESCE(next_attempt_at, created_at) ASC, id ASC
             LIMIT %d",
            $now, $limit
        ));
        $processed = 0;
        foreach ((array)$ids as $id) {
            if (self::process_delivery_by_id((int)$id, false)) { $processed++; }
            $row = self::get_delivery((int)$id);
            if ($row && !empty($row->notification_id)) {
                // A failure can enqueue the next durable fallback. Process it
                // immediately instead of waiting five minutes.
                self::process_notification_queue((int)$row->notification_id, 8);
            }
        }
        update_option('hamnoor_notification_queue_last_run', array(
            'at'=>current_time('mysql'),
            'processed'=>$processed,
            'remaining'=>self::queue_stats()['pending'],
        ), false);
        return $processed;
    }

    public static function process_notification_queue($notification_id, $limit = 12) {
        global $wpdb;
        $notification_id = (int)$notification_id;
        if (!$notification_id) { return 0; }
        $table = Hamnoor_DB::table('notification_deliveries');
        $processed = 0;
        for ($i = 0; $i < max(1, (int)$limit); $i++) {
            $now = current_time('mysql');
            $id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                 WHERE notification_id=%d AND status IN ('queued','retry_wait')
                   AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                 ORDER BY id ASC LIMIT 1",
                $notification_id, $now
            ));
            if (!$id) { break; }
            self::process_delivery_by_id($id, false);
            $processed++;
        }
        return $processed;
    }

    private static function process_delivery_by_id($delivery_id, $diagnostic = false) {
        global $wpdb;
        $delivery = self::get_delivery((int)$delivery_id);
        if (!$delivery || !in_array((string)$delivery->status, array('queued','retry_wait'), true)) { return false; }

        $table = Hamnoor_DB::table('notification_deliveries');
        $now = current_time('mysql');
        $locked = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='sending', updated_at=%s WHERE id=%d AND status IN ('queued','retry_wait')",
            $now, (int)$delivery->id
        ));
        if (1 !== (int)$locked) { return false; }

        $delivery = self::get_delivery((int)$delivery->id);
        $notification = self::get_notification((int)$delivery->notification_id);
        if (!$notification) {
            self::finish_delivery((int)$delivery->id, 'failed', new WP_Error('hamnoor_notification_missing', __('اعلان اصلی این ارسال پیدا نشد.', 'hamnoor')), true);
            return true;
        }

        $channel_key = sanitize_key((string)$delivery->channel);
        if (!$diagnostic && 'web_push' !== $channel_key && self::durable_already_sent((int)$notification->id, $channel_key)) {
            self::finish_delivery((int)$delivery->id, 'cancelled', null, true);
            return true;
        }

        if (!self::channel_eligible((int)$notification->user_id, $channel_key)) {
            $error = new WP_Error('hamnoor_channel_not_eligible', __('این کانال دیگر برای کاربر متصل/فعال نیست.', 'hamnoor'));
            self::finish_delivery((int)$delivery->id, 'failed', $error, true);
            if (!$diagnostic && 'web_push' !== $channel_key) { self::enqueue_next_durable($notification, $channel_key, self::decode_context($delivery)); }
            return true;
        }

        $channels = self::channels();
        $attempt = max(0, (int)$delivery->attempt_count) + 1;
        $wpdb->update($table, array(
            'attempt_count'=>$attempt,
            'last_attempt_at'=>$now,
            'updated_at'=>$now,
        ), array('id'=>(int)$delivery->id), array('%d','%s','%s'), array('%d'));

        $result = $channels[$channel_key]->send_to_user((int)$notification->user_id, $notification, self::decode_context($delivery));
        if (!is_wp_error($result)) {
            self::finish_delivery((int)$delivery->id, 'sent', null, true);
            do_action('hamnoor_notification_dispatched', $notification, $channel_key, (int)$delivery->id);
            if (!$diagnostic && 'web_push' !== $channel_key) { self::cancel_pending_durable((int)$notification->id, $channel_key); }
            return true;
        }

        $retryable = !$diagnostic && self::is_retryable_error($result) && $attempt < max(1, (int)$delivery->max_attempts);
        if ($retryable) {
            self::schedule_retry((int)$delivery->id, $attempt, $result);
        } else {
            self::finish_delivery((int)$delivery->id, 'failed', $result, true);
        }

        // Fallback is immediate. If a later durable channel succeeds, the retry
        // above is cancelled so the user never receives a duplicate durable message.
        if (!$diagnostic && 'web_push' !== $channel_key) {
            self::enqueue_next_durable($notification, $channel_key, self::decode_context($delivery));
        }
        return true;
    }

    private static function schedule_retry($delivery_id, $attempt, $error) {
        global $wpdb;
        $delay = self::retry_delay($attempt);
        $edata = $error->get_error_data();
        if (is_array($edata) && !empty($edata['retry_after']) && is_numeric($edata['retry_after'])) {
            $delay = max(60, (int)$edata['retry_after']);
        }
        $data = array(
            'status'=>'retry_wait',
            'next_attempt_at'=>wp_date('Y-m-d H:i:s', time() + $delay, wp_timezone()),
            'updated_at'=>current_time('mysql'),
            'error_code'=>sanitize_key($error->get_error_code()),
            'error_message'=>Hamnoor_Validator::trim_len($error->get_error_message(), 1000),
        );
        $formats = array('%s','%s','%s','%s','%s');
        if (is_array($edata) && isset($edata['http_code'])) { $data['response_code'] = (int)$edata['http_code']; $formats[] = '%d'; }
        $wpdb->update(Hamnoor_DB::table('notification_deliveries'), $data, array('id'=>(int)$delivery_id), $formats, array('%d'));
    }

    private static function retry_delay($attempt) {
        $delays = array(1 => 15 * MINUTE_IN_SECONDS, 2 => HOUR_IN_SECONDS, 3 => 6 * HOUR_IN_SECONDS);
        $delay = isset($delays[(int)$attempt]) ? $delays[(int)$attempt] : 6 * HOUR_IN_SECONDS;
        return max(60, (int)apply_filters('hamnoor_notification_retry_delay', $delay, (int)$attempt));
    }

    private static function is_retryable_error($error) {
        if (!is_wp_error($error)) { return false; }
        $data = $error->get_error_data();
        $http = is_array($data) && isset($data['http_code']) ? (int)$data['http_code'] : 0;
        if (in_array($http, array(408,425,429), true) || $http >= 500) { return true; }
        if (in_array($http, array(400,401,403,404,410,422), true)) { return false; }
        $code = strtolower((string)$error->get_error_code());
        foreach (array('invalid','not_connected','not_configured','unauthorized','forbidden','bad_token','missing_token','bad_subscription','openssl','keygen','keyexport','keydetails','endpoint','ephemeral','receiver_key','ecdh','encrypt','der','vapid') as $needle) {
            if (false !== strpos($code, $needle)) { return false; }
        }
        foreach (array('timeout','timed_out','network','transport','http_request_failed','_http','rate','temporary','connection','curl') as $needle) {
            if (false !== strpos($code, $needle)) { return true; }
        }
        // Unknown provider failures get retries; terminal successes on another
        // durable channel will cancel these retries automatically.
        return 0 === $http;
    }

    private static function channel_eligible($user_id, $key) {
        $state = self::channel_state((int)$user_id, $key);
        return !empty($state['eligible']);
    }

    /**
     * Return a reasoned state for one channel. The interface contract says
     * is_connected() means an active user connection, so Dispatcher does not
     * apply a second, potentially contradictory DB gate after the adapter.
     */
    private static function channel_state($user_id, $key) {
        $channels = self::channels();
        $key = sanitize_key($key);
        $state = array(
            'channel'=>$key, 'registered'=>0, 'available'=>0, 'connected'=>0,
            'enabled'=>0, 'eligible'=>0, 'reason'=>'not_registered', 'exception'=>'',
        );
        if (empty($channels[$key])) { return $state; }
        $state['registered'] = 1;
        try {
            $state['available'] = $channels[$key]->is_available() ? 1 : 0;
            if (!$state['available']) { $state['reason'] = 'not_available'; return $state; }
            $state['enabled'] = self::user_channel_enabled((int)$user_id, $key) ? 1 : 0;
            $state['connected'] = $channels[$key]->is_connected((int)$user_id) ? 1 : 0;
            if (!$state['connected']) {
                $state['reason'] = $state['enabled'] ? 'not_connected' : 'not_active_for_user';
                return $state;
            }
            $state['eligible'] = 1;
            $state['reason'] = 'eligible';
            return $state;
        } catch (Throwable $e) {
            $state['reason'] = 'provider_exception';
            $state['exception'] = sanitize_text_field($e->getMessage());
            return $state;
        }
    }

    private static function first_eligible_durable($user_id) {
        foreach (self::priority() as $key) {
            if ('web_push' === $key) { continue; }
            if (self::channel_eligible((int)$user_id, $key)) { return $key; }
        }
        return '';
    }

    private static function enqueue_next_durable($notification, $after_channel, $context = array()) {
        $seen = false;
        foreach (self::priority() as $key) {
            if ('web_push' === $key) { continue; }
            if (!$seen) {
                if ($key === $after_channel) { $seen = true; }
                continue;
            }
            if (!self::channel_eligible((int)$notification->user_id, $key)) { continue; }
            if (self::notification_has_channel_delivery((int)$notification->id, $key)) { continue; }
            return self::enqueue_delivery($notification, $key, $context);
        }
        return 0;
    }

    private static function enqueue_delivery($notification, $channel, $context = array(), $force = false) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        $channel = sanitize_key($channel);
        if (!$table || !$channel || !$notification || empty($notification->id)) { return 0; }
        if (!$force && self::notification_has_channel_delivery((int)$notification->id, $channel)) { return 0; }
        $now = current_time('mysql');
        $context_json = wp_json_encode(is_array($context) ? $context : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = $wpdb->insert($table, array(
            'notification_id'=>(int)$notification->id,
            'user_id'=>(int)$notification->user_id,
            'channel'=>$channel,
            'provider'=>$channel,
            'status'=>'queued',
            'attempt_count'=>0,
            'max_attempts'=>self::MAX_ATTEMPTS,
            'context_json'=>$context_json,
            'next_attempt_at'=>$now,
            'created_at'=>$now,
            'updated_at'=>$now,
        ), array('%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%s'));
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private static function notification_has_channel_delivery($notification_id, $channel) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE notification_id=%d AND channel=%s AND status <> 'cancelled' LIMIT 1",
            (int)$notification_id, sanitize_key($channel)
        ));
    }

    private static function notification_has_external_delivery($notification_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE notification_id=%d AND channel <> 'internal' LIMIT 1",
            (int)$notification_id
        ));
    }

    private static function durable_already_sent($notification_id, $except = '') {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE notification_id=%d AND status='sent' AND channel NOT IN ('web_push','internal') AND channel <> %s LIMIT 1",
            (int)$notification_id, sanitize_key($except)
        ));
    }

    private static function cancel_pending_durable($notification_id, $winner) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='cancelled', error_code='superseded', error_message=%s, next_attempt_at=NULL, updated_at=%s
             WHERE notification_id=%d AND channel NOT IN ('web_push','internal',%s) AND status IN ('queued','retry_wait')",
            __('ارسال ماندگار از کانال دیگری موفق شد؛ این تلاش برای جلوگیری از پیام تکراری لغو شد.', 'hamnoor'),
            current_time('mysql'), (int)$notification_id, sanitize_key($winner)
        ));
    }

    private static function recover_stale_sending() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        $cutoff = wp_date('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS, wp_timezone());
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='retry_wait', next_attempt_at=%s, error_code='stale_lock', error_message=%s, updated_at=%s
             WHERE status='sending' AND updated_at < %s",
            current_time('mysql'), __('ارسال قبلی ناتمام ماند و برای تلاش مجدد به صف بازگردانده شد.', 'hamnoor'), current_time('mysql'), $cutoff
        ));
    }

    private static function decode_context($delivery) {
        if (!$delivery || empty($delivery->context_json)) { return array(); }
        $decoded = json_decode((string)$delivery->context_json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function record_internal_only($notification, $reason = 'no_external_channel') {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        if (!$table || !$notification || empty($notification->id) || empty($notification->user_id)) { return 0; }
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE notification_id=%d AND channel='internal' LIMIT 1", (int)$notification->id));
        if ($existing) { return $existing; }
        $wpdb->insert($table, array(
            'notification_id'=>(int)$notification->id,'user_id'=>(int)$notification->user_id,
            'channel'=>'internal','provider'=>'dispatcher','status'=>'internal_only','attempt_count'=>0,'max_attempts'=>0,
            'error_code'=>sanitize_key($reason),
            'error_message'=>__('اعلان در مرکز اعلان‌های هم‌نور ثبت شد، اما کانال بیرونی فعالی برای کاربر وجود نداشت.', 'hamnoor'),
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
        ), array('%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%s'));
        return (int)$wpdb->insert_id;
    }

    public static function delivery_summary($notification_id) {
        global $wpdb;
        $notification_id = (int)$notification_id;
        $table = Hamnoor_DB::table('notification_deliveries');
        $rows = ($notification_id && $table) ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE notification_id=%d ORDER BY id ASC", $notification_id)) : array();
        $summary = array('notification_id'=>$notification_id,'status'=>'pending','channel'=>'','error'=>'','attempts'=>0,'rows'=>is_array($rows)?$rows:array());
        foreach ((array)$rows as $row) { $summary['attempts'] += (int)$row->attempt_count; }
        foreach ((array)$rows as $row) {
            if ('sent' === (string)$row->status && 'web_push' !== (string)$row->channel) {
                $summary['status']='sent'; $summary['channel']=(string)$row->channel; return $summary;
            }
        }
        $has_pending = false;
        foreach ((array)$rows as $row) {
            if (in_array((string)$row->status, array('queued','retry_wait','sending'), true)) { $has_pending = true; }
        }
        if ($has_pending) { $summary['status']='retrying'; $summary['channel']='queue'; return $summary; }
        foreach ((array)$rows as $row) {
            if ('sent' === (string)$row->status) { $summary['status']='sent'; $summary['channel']=(string)$row->channel; return $summary; }
        }
        foreach ((array)$rows as $row) {
            if ('failed' === (string)$row->status) { $summary['status']='failed'; $summary['channel']=(string)$row->channel; $summary['error']=(string)$row->error_message; }
            elseif ('internal_only' === (string)$row->status && 'failed' !== $summary['status']) { $summary['status']='internal_only'; $summary['channel']='internal'; $summary['error']=(string)$row->error_message; }
        }
        return $summary;
    }

    public static function recent_deliveries($limit = 50) {
        global $wpdb;
        $limit = max(1, min(200, (int)$limit));
        $d = Hamnoor_DB::table('notification_deliveries'); $n = Hamnoor_DB::table('notifications'); $u = Hamnoor_DB::table('users');
        if (!$d || !$n || !$u) { return array(); }
        return $wpdb->get_results(
            "SELECT d.*, n.type AS notification_type, n.title AS notification_title, n.related_type, n.related_id,
                    u.display_name, u.phone
             FROM $d d LEFT JOIN $n n ON n.id=d.notification_id LEFT JOIN $u u ON u.id=d.user_id
             ORDER BY d.id DESC LIMIT " . (int)$limit
        );
    }

    public static function delivery_stats($days = 7) {
        global $wpdb;
        $days = max(1, min(90, (int)$days));
        $table = Hamnoor_DB::table('notification_deliveries');
        $since = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS), wp_timezone());
        $base = array('sent'=>0,'failed'=>0,'internal_only'=>0,'sending'=>0,'queued'=>0,'retry_wait'=>0,'cancelled'=>0,'total'=>0);
        if (!$table) { return $base; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS total FROM $table WHERE created_at >= %s GROUP BY status", $since));
        foreach ((array)$rows as $row) {
            $status=sanitize_key((string)$row->status); $count=(int)$row->total; $base['total'] += $count;
            if (array_key_exists($status,$base)) { $base[$status]=$count; }
        }
        return $base;
    }

    public static function queue_stats() {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_deliveries');
        $base = array('pending'=>0,'queued'=>0,'retry_wait'=>0,'sending'=>0,'next_attempt'=>'');
        if (!$table) { return $base; }
        foreach (array('queued','retry_wait','sending') as $status) {
            $base[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status=%s", $status));
            $base['pending'] += $base[$status];
        }
        $base['next_attempt'] = (string)$wpdb->get_var("SELECT MIN(next_attempt_at) FROM $table WHERE status IN ('queued','retry_wait') AND next_attempt_at IS NOT NULL");
        return $base;
    }

    public static function retry_delivery($delivery_id) {
        global $wpdb;
        $delivery = self::get_delivery((int)$delivery_id);
        if (!$delivery || in_array((string)$delivery->status, array('sent','internal_only','sending','queued'), true)) {
            return new WP_Error('hamnoor_retry_invalid', __('این ردیف برای تلاش مجدد مناسب نیست.', 'hamnoor'));
        }
        if ('web_push' !== (string)$delivery->channel && self::durable_already_sent((int)$delivery->notification_id, (string)$delivery->channel)) {
            return new WP_Error('hamnoor_retry_duplicate_guard', __('یک پیام‌رسان ماندگار دیگر این اعلان را با موفقیت تحویل داده است؛ برای جلوگیری از پیام تکراری، Retry این ردیف مجاز نیست.', 'hamnoor'));
        }
        $ok = $wpdb->update(Hamnoor_DB::table('notification_deliveries'), array(
            'status'=>'queued','attempt_count'=>0,'max_attempts'=>self::MAX_ATTEMPTS,'next_attempt_at'=>current_time('mysql'),
            'error_code'=>null,'error_message'=>null,'response_code'=>null,'sent_at'=>null,'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$delivery_id), array('%s','%d','%d','%s','%s','%s','%d','%s','%s'), array('%d'));
        if (false === $ok) { return new WP_Error('hamnoor_retry_update_failed', __('بازگرداندن ارسال به صف ناموفق بود.', 'hamnoor')); }
        self::process_delivery_by_id((int)$delivery_id, false);
        return self::get_delivery((int)$delivery_id);
    }

    public static function user_channel_enabled($user_id, $channel) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT enabled,status FROM $table WHERE user_id=%d AND channel=%s LIMIT 1", (int)$user_id, sanitize_key($channel)));
        if (!$row) { return 'web_push' === $channel; }
        return 1 === (int)$row->enabled && 'disabled' !== (string)$row->status;
    }

    public static function channel_consent_counts($channel) {
        global $wpdb;
        $table = Hamnoor_DB::table('notification_channels');
        $channel = sanitize_key((string)$channel);
        if (!$table || !$channel) { return array('active'=>0,'unsubscribed'=>0); }
        $active = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE channel=%s AND enabled=1 AND status='connected'", $channel));
        $unsubscribed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE channel=%s AND enabled=0 AND status='disabled' AND metadata LIKE %s", $channel, '%\"user_unsubscribed\":1%'));
        return array('active'=>$active,'unsubscribed'=>$unsubscribed);
    }

    public static function route_diagnostics($user_id) {
        $user_id=(int)$user_id; $rows=array(); $winner=''; $supplemental=array();
        foreach (self::priority() as $key) {
            $state = self::channel_state($user_id, $key);
            if (!empty($state['eligible']) && 'web_push'===$key) { $supplemental[]=$key; }
            elseif (!$winner && !empty($state['eligible'])) { $winner=$key; }
            $rows[]=$state;
        }
        return array('winner'=>$winner,'supplemental'=>$supplemental,'rows'=>$rows);
    }

    public static function set_user_channel($user_id, $channel, $enabled, $status='connected', $metadata=array()) {
        global $wpdb;
        $user_id=(int)$user_id; $channel=sanitize_key($channel); if (!$user_id || !$channel) { return false; }
        $table=Hamnoor_DB::table('notification_channels'); $now=current_time('mysql');
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND channel=%s LIMIT 1",$user_id,$channel));
        $data=array('user_id'=>$user_id,'channel'=>$channel,'enabled'=>$enabled?1:0,'status'=>sanitize_key($status?:($enabled?'connected':'disabled')),'metadata'=>wp_json_encode(is_array($metadata)?$metadata:array(),JSON_UNESCAPED_UNICODE),'updated_at'=>$now);
        if ($existing) { return false !== $wpdb->update($table,$data,array('id'=>(int)$existing),array('%d','%s','%d','%s','%s','%s'),array('%d')); }
        $data['connected_at']=$enabled?$now:null; $data['created_at']=$now;
        return (bool)$wpdb->insert($table,$data,array('%d','%s','%d','%s','%s','%s','%s','%s'));
    }

    private static function get_notification($id) {
        global $wpdb; $table=Hamnoor_DB::table('notifications');
        return $id && $table ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id)) : null;
    }

    private static function get_delivery($id) {
        global $wpdb; $table=Hamnoor_DB::table('notification_deliveries');
        return $id && $table ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id)) : null;
    }

    private static function finish_delivery($delivery_id, $status, $error=null, $clear_next=false) {
        global $wpdb;
        if (!$delivery_id) { return; }
        $data=array('status'=>sanitize_key($status),'updated_at'=>current_time('mysql')); $formats=array('%s','%s');
        if ($clear_next) { $data['next_attempt_at']=null; $formats[]='%s'; }
        if ('sent'===$status) {
            $data['sent_at']=current_time('mysql'); $formats[]='%s';
            $data['error_code']=null; $formats[]='%s';
            $data['error_message']=null; $formats[]='%s';
            $data['response_code']=null; $formats[]='%d';
        }
        if (is_wp_error($error)) {
            $data['error_code']=sanitize_key($error->get_error_code()); $formats[]='%s';
            $data['error_message']=Hamnoor_Validator::trim_len($error->get_error_message(),1000); $formats[]='%s';
            $edata=$error->get_error_data(); if (is_array($edata) && isset($edata['http_code'])) { $data['response_code']=(int)$edata['http_code']; $formats[]='%d'; }
        }
        $wpdb->update(Hamnoor_DB::table('notification_deliveries'),$data,array('id'=>(int)$delivery_id),$formats,array('%d'));
    }
}
