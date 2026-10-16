<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Engagement helpers: owner progress center and internal reminders.
 */
class Hamnoor_Engagement {
    // Smart Reminder Engine v1 defaults. Values can be changed from the
    // notification overview without changing code.
    const REMINDER_NOT_STARTED_HOURS = 24;
    const REMINDER_STALLED_HOURS = 48;
    const REMINDER_REPEAT_HOURS = 48;
    const REMINDER_MAX_AUTO = 3;

    public static function init() {
        add_action('wp_ajax_hamnoor_toggle_part_reminder', array(__CLASS__, 'ajax_toggle_part_reminder'));
        add_action('wp_ajax_nopriv_hamnoor_toggle_part_reminder', array(__CLASS__, 'ajax_toggle_part_reminder'));
        add_action('wp_ajax_hamnoor_owner_send_reminder', array(__CLASS__, 'ajax_owner_send_reminder'));
        add_action('wp_ajax_nopriv_hamnoor_owner_send_reminder', array(__CLASS__, 'ajax_owner_send_reminder'));
        add_action('hamnoor_internal_reminders', array(__CLASS__, 'run_internal_reminders'));
        add_action('hamnoor_reading_progress_saved', array(__CLASS__, 'reading_activity'), 10, 4);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event('hamnoor_internal_reminders') : null;
        if ($event && isset($event->schedule) && 'hourly' !== $event->schedule) {
            wp_clear_scheduled_hook('hamnoor_internal_reminders');
            $event = null;
        }
        if (!$event && !wp_next_scheduled('hamnoor_internal_reminders')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'hamnoor_internal_reminders');
        }
    }

    public static function reading_activity($part_id, $global_index, $percent, $user_id) {
        // Smart Reminder Engine v1 deliberately keeps reminder_count cumulative
        // for the lifetime of the claimed part. Fresh reading activity already
        // resets the inactivity clock through reading_progress.last_read_at, so
        // clearing the counter here would defeat the per-part automatic cap.
    }

    public static function smart_reminder_defaults() {
        return array(
            'enabled' => 1,
            'not_started_hours' => self::REMINDER_NOT_STARTED_HOURS,
            'stalled_hours' => self::REMINDER_STALLED_HOURS,
            'repeat_hours' => self::REMINDER_REPEAT_HOURS,
            'max_auto' => self::REMINDER_MAX_AUTO,
        );
    }

    public static function smart_reminder_settings() {
        $defaults = self::smart_reminder_defaults();
        $saved = get_option('hamnoor_smart_reminder_settings', array());
        $saved = is_array($saved) ? array_intersect_key($saved, $defaults) : array();
        $settings = wp_parse_args($saved, $defaults);
        $settings['enabled'] = !empty($settings['enabled']) ? 1 : 0;
        $settings['not_started_hours'] = max(1, min(720, (int)$settings['not_started_hours']));
        $settings['stalled_hours'] = max(1, min(720, (int)$settings['stalled_hours']));
        $settings['repeat_hours'] = max(1, min(720, (int)$settings['repeat_hours']));
        $settings['max_auto'] = max(1, min(20, (int)$settings['max_auto']));
        return apply_filters('hamnoor_smart_reminder_settings', $settings);
    }

    public static function sanitize_smart_reminder_settings($input) {
        $input = is_array($input) ? $input : array();
        return array(
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'not_started_hours' => max(1, min(720, (int)($input['not_started_hours'] ?? self::REMINDER_NOT_STARTED_HOURS))),
            'stalled_hours' => max(1, min(720, (int)($input['stalled_hours'] ?? self::REMINDER_STALLED_HOURS))),
            'repeat_hours' => max(1, min(720, (int)($input['repeat_hours'] ?? self::REMINDER_REPEAT_HOURS))),
            'max_auto' => max(1, min(20, (int)($input['max_auto'] ?? self::REMINDER_MAX_AUTO))),
        );
    }

    private static function mysql_local_timestamp($value) {
        $value = trim((string)$value);
        if (!$value) { return 0; }
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
            return $dt ? (int)$dt->getTimestamp() : 0;
        } catch (Throwable $e) {
            return (int)strtotime($value);
        }
    }

    /** One canonical answer for reminder creation and notification CTAs. */
    public static function part_reminder_actionability($part, $khatm = null, $now = null) {
        $result = array('actionable'=>false, 'reason'=>'unknown');
        if (!$part || 'claimed' !== (string)$part->status || empty($part->participant_user_id)) {
            $result['reason'] = 'part_not_actionable';
            return $result;
        }
        if (!$khatm) { $khatm = Hamnoor_DB::get_khatm((int)$part->khatm_id); }
        if (!$khatm) { $result['reason'] = 'campaign_missing'; return $result; }
        $status = sanitize_key((string)$khatm->status);
        if (!in_array($status, array('active','paused','expired'), true)) {
            $result['reason'] = 'campaign_closed';
            return $result;
        }
        $now = null === $now ? time() : (int)$now;
        $timezone = wp_timezone();
        if (!empty($khatm->starts_at)) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$khatm->starts_at, $timezone);
            if ($start && $start->getTimestamp() > $now) {
                $result['reason'] = 'campaign_not_started';
                return $result;
            }
        }
        if (!empty($khatm->ends_at) && isset($khatm->completion_grace_days) && null !== $khatm->completion_grace_days && '' !== (string)$khatm->completion_grace_days) {
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$khatm->ends_at, $timezone);
            if ($end) {
                $deadline = $end->modify('+' . (int)$khatm->completion_grace_days . ' days');
                if ($deadline->getTimestamp() < $now) {
                    $result['reason'] = 'completion_deadline_passed';
                    return $result;
                }
            }
        }
        $result['actionable'] = true;
        $result['reason'] = 'actionable';
        return $result;
    }

    private static function reminder_profile($part, $progress) {
        $start = $progress && isset($progress->start_global_index) ? (int)$progress->start_global_index : 0;
        $end = $progress && isset($progress->end_global_index) ? (int)$progress->end_global_index : 0;
        $last = $progress && isset($progress->last_global_index) ? (int)$progress->last_global_index : 0;
        $percent = 0;
        if ($start && $end >= $start && function_exists('manahel_quran_core')) {
            $percent = (int)manahel_quran_core()->progress_percent($start, $end, $last);
        } elseif ($start && $end >= $start && $last >= $start) {
            $percent = (int)round(100 * max(0, min($end, $last) - $start + 1) / max(1, $end - $start + 1));
        }
        $reading_status = $progress && isset($progress->reading_status) ? sanitize_key((string)$progress->reading_status) : '';
        if ('ready' === $reading_status || ($end && $last >= $end)) { $state = 'ready'; }
        elseif (!$start || $last < $start || 'not_started' === $reading_status) { $state = 'not_started'; }
        elseif ($percent >= 80) { $state = 'near_completion'; }
        else { $state = 'reading'; }
        return array('state'=>$state, 'percent'=>max(0, min(100, $percent)));
    }

    public static function smart_reminder_decision($part, $progress, $now = null, $settings = null, $khatm = null) {
        $actionability = self::part_reminder_actionability($part, $khatm, $now);
        if (empty($actionability['actionable'])) { return array('due'=>false,'reason'=>(string)$actionability['reason'],'state'=>'','percent'=>0,'activity_at'=>0,'due_at'=>0); }
        $settings = is_array($settings) ? $settings : self::smart_reminder_settings();
        $now = null === $now ? current_time('timestamp') : (int)$now;
        $decision = array('due'=>false,'reason'=>'not_due','state'=>'','percent'=>0,'activity_at'=>0,'due_at'=>0);
        if (!$part || 'claimed' !== (string)$part->status || empty($part->participant_user_id)) { $decision['reason']='not_claimed'; return $decision; }
        if (empty($settings['enabled'])) { $decision['reason']='engine_disabled'; return $decision; }
        if (!$progress || is_wp_error($progress)) { $decision['reason']='progress_unavailable'; return $decision; }
        if (isset($progress->reminder_enabled) && 0 === (int)$progress->reminder_enabled) { $decision['reason']='user_disabled'; return $decision; }
        if ((int)$progress->reminder_count >= (int)$settings['max_auto']) { $decision['reason']='max_reached'; return $decision; }
        $profile = self::reminder_profile($part, $progress);
        $decision['state'] = $profile['state'];
        $decision['percent'] = (int)$profile['percent'];
        $delay_hours = 'not_started' === $profile['state'] ? (int)$settings['not_started_hours'] : (int)$settings['stalled_hours'];
        $activity_at = 'not_started' === $profile['state']
            ? self::mysql_local_timestamp(!empty($part->claimed_at) ? $part->claimed_at : '')
            : self::mysql_local_timestamp(!empty($progress->last_read_at) ? $progress->last_read_at : (!empty($part->claimed_at) ? $part->claimed_at : ''));
        if (!$activity_at) { $decision['reason']='activity_unknown'; return $decision; }
        $decision['activity_at'] = $activity_at;
        $decision['due_at'] = $activity_at + $delay_hours * HOUR_IN_SECONDS;
        if ($now < $decision['due_at']) { $decision['reason']='inactivity_not_due'; return $decision; }

        if (!empty($progress->last_reminder_at)) {
            $last_reminder = self::mysql_local_timestamp($progress->last_reminder_at);
            if ($last_reminder && ($now - $last_reminder) < ((int)$settings['repeat_hours'] * HOUR_IN_SECONDS)) {
                $decision['reason']='repeat_not_due';
                $decision['due_at'] = max($decision['due_at'], $last_reminder + (int)$settings['repeat_hours'] * HOUR_IN_SECONDS);
                return $decision;
            }
        }
        $decision['due'] = true;
        $decision['reason'] = 'due_' . $profile['state'];
        return $decision;
    }

    public static function progress_rows($khatm_id, $limit = 100, $offset = 0) {
        global $wpdb;
        $khatm_id = (int) $khatm_id;
        if (!$khatm_id || !Hamnoor_DB::user_can_manage_khatm($khatm_id)) { return array(); }
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $progress = Hamnoor_DB::table('reading_progress');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id AS part_id,p.title,p.part_type,p.part_number,p.status,p.claimed_at,p.done_at,
                    r.name AS participant_name,r.phone AS participant_phone,r.user_id AS participant_user_id,
                    rp.start_global_index,rp.end_global_index,rp.last_global_index,rp.reading_status,rp.started_at,rp.last_read_at,
                    rp.reminder_enabled,rp.last_reminder_at,rp.reminder_count
             FROM $parts p
             LEFT JOIN $participants r ON r.id=p.participant_id
             LEFT JOIN $progress rp ON rp.part_id=p.id
             WHERE p.khatm_id=%d ORDER BY p.id ASC LIMIT %d OFFSET %d",
            $khatm_id,
            max(1, min(200, (int)$limit)),
            max(0, (int)$offset)
        ));
        foreach ($rows as $row) {
            $row->progress_percent = self::row_percent($row);
            $row->progress_status = self::row_status($row);
            $row->progress_status_label = self::status_label($row->progress_status);
            $row->last_activity = !empty($row->last_read_at) ? $row->last_read_at : $row->claimed_at;
        }
        return $rows;
    }

    public static function progress_summary_for_khatm($khatm_id) {
        global $wpdb;
        $khatm_id = (int)$khatm_id;
        $empty = array('total'=>0,'available'=>0,'not_started'=>0,'reading'=>0,'ready'=>0,'done'=>0);
        if (!$khatm_id || !Hamnoor_DB::user_can_manage_khatm($khatm_id)) { return $empty; }
        $parts = Hamnoor_DB::table('parts');
        $progress = Hamnoor_DB::table('reading_progress');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN p.status='available' THEN 1 ELSE 0 END) AS available,
                    SUM(CASE WHEN p.status='done' THEN 1 ELSE 0 END) AS done,
                    SUM(CASE WHEN p.status='claimed' AND rp.reading_status='ready' THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN p.status='claimed' AND COALESCE(rp.reading_status,'')<>'ready' AND rp.start_global_index IS NOT NULL AND rp.last_global_index>=rp.start_global_index THEN 1 ELSE 0 END) AS reading,
                    SUM(CASE WHEN p.status='claimed' AND (rp.id IS NULL OR rp.start_global_index IS NULL OR rp.last_global_index<rp.start_global_index) THEN 1 ELSE 0 END) AS not_started
             FROM $parts p LEFT JOIN $progress rp ON rp.part_id=p.id WHERE p.khatm_id=%d",
            $khatm_id
        ));
        if (!$row) { return $empty; }
        foreach ($empty as $key=>$value) { $empty[$key] = isset($row->$key) ? (int)$row->$key : 0; }
        return $empty;
    }

    public static function progress_summary($rows) {
        $summary = array('total'=>0,'available'=>0,'not_started'=>0,'reading'=>0,'ready'=>0,'done'=>0);
        foreach ((array) $rows as $row) {
            $summary['total']++;
            $key = self::row_status($row);
            if (isset($summary[$key])) { $summary[$key]++; }
        }
        return $summary;
    }

    private static function row_percent($row) {
        if ('done' === (string) $row->status) { return 100; }
        $start = isset($row->start_global_index) ? (int) $row->start_global_index : 0;
        $end = isset($row->end_global_index) ? (int) $row->end_global_index : 0;
        $last = isset($row->last_global_index) ? (int) $row->last_global_index : 0;
        if ($start && $end >= $start && function_exists('manahel_quran_core')) {
            return (int) manahel_quran_core()->progress_percent($start, $end, $last);
        }
        return 0;
    }

    private static function row_status($row) {
        if ('done' === (string) $row->status) { return 'done'; }
        if ('available' === (string) $row->status) { return 'available'; }
        if (!empty($row->reading_status) && 'ready' === (string) $row->reading_status) { return 'ready'; }
        if (!empty($row->last_global_index) && !empty($row->start_global_index) && (int) $row->last_global_index >= (int) $row->start_global_index) { return 'reading'; }
        return 'not_started';
    }

    public static function status_label($status) {
        $labels = array(
            'available' => __('آزاد', 'hamnoor'),
            'not_started' => __('شروع‌نشده', 'hamnoor'),
            'reading' => __('در حال قرائت', 'hamnoor'),
            'ready' => __('آماده ثبت پایان', 'hamnoor'),
            'done' => __('تکمیل‌شده', 'hamnoor'),
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    public static function ajax_toggle_part_reminder() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { wp_send_json_error(array('message'=>__('برای ادامه، دوباره وارد هم‌نور شوید.', 'hamnoor'))); }
        $part_id = isset($_POST['part_id']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['part_id'])) : 0;
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || empty($part->participant_user_id) || (int) $part->participant_user_id !== Hamnoor_Mobile_Access::current_user_id()) {
            wp_send_json_error(array('message'=>__('این سهم در دسترس شما نیست.', 'hamnoor')));
        }
        $progress = Hamnoor_Reading::ensure_progress($part);
        if (is_wp_error($progress)) { wp_send_json_error(array('message'=>$progress->get_error_message())); }
        global $wpdb;
        $wpdb->update(Hamnoor_DB::table('reading_progress'), array('reminder_enabled'=>$enabled,'updated_at'=>current_time('mysql')), array('part_id'=>$part_id), array('%d','%s'), array('%d'));
        wp_send_json_success(array('message'=>$enabled ? __('یادآوری این سهم فعال شد.', 'hamnoor') : __('یادآوری این سهم غیرفعال شد.', 'hamnoor'), 'enabled'=>$enabled));
    }

    public static function ajax_owner_send_reminder() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        $part_id = isset($_POST['part_id']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['part_id'])) : 0;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || !Hamnoor_DB::user_can_manage_khatm((int) $part->khatm_id) || 'claimed' !== (string) $part->status || empty($part->participant_user_id)) {
            wp_send_json_error(array('message'=>__('امکان ارسال یادآوری برای این سهم وجود ندارد.', 'hamnoor')));
        }
        $progress = Hamnoor_Reading::ensure_progress($part);
        if (is_wp_error($progress)) { wp_send_json_error(array('message'=>$progress->get_error_message())); }
        if (isset($progress->reminder_enabled) && 0 === (int) $progress->reminder_enabled) {
            wp_send_json_error(array('message'=>__('مشارکت‌کننده یادآوری این سهم را غیرفعال کرده است.', 'hamnoor')));
        }
        if (!empty($progress->last_reminder_at) && (current_time('timestamp') - strtotime($progress->last_reminder_at)) < DAY_IN_SECONDS) {
            wp_send_json_error(array('message'=>__('برای این سهم در ۲۴ ساعت گذشته یادآوری ثبت شده است.', 'hamnoor')));
        }
        $result = self::send_part_reminder($part, $progress, true);
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message())); }
        $delivery = isset($result['delivery']) && is_array($result['delivery']) ? $result['delivery'] : array();
        $status = isset($delivery['status']) ? (string)$delivery['status'] : 'pending';
        $channel = isset($delivery['channel']) ? (string)$delivery['channel'] : '';
        if ('sent' === $status) {
            $message = sprintf(__('یادآوری ثبت شد و از طریق %s برای مشارکت‌کننده ارسال شد.', 'hamnoor'), self::channel_label($channel));
        } elseif ('retrying' === $status) {
            $message = __('یادآوری ثبت شد؛ ارسال بیرونی فعلاً ناموفق بوده و برای تلاش مجدد در صف اعلان هم‌نور قرار گرفته است.', 'hamnoor');
        } elseif ('failed' === $status) {
            $message = __('یادآوری در مرکز اعلان‌های مشارکت‌کننده ثبت شد، اما ارسال بیرونی پس از تلاش‌ها ناموفق بود. جزئیات در «کانال‌های اعلان ← گزارش ارسال» ثبت شده است.', 'hamnoor');
        } else {
            $message = __('یادآوری در مرکز اعلان‌های مشارکت‌کننده ثبت شد؛ کانال بیرونی فعالی برای این کاربر وجود ندارد.', 'hamnoor');
        }
        wp_send_json_success(array('message'=>$message, 'delivery'=>$delivery));
    }

    private static function send_part_reminder($part, $progress, $manual = false) {
        if (!$part || empty($part->participant_user_id)) { return new WP_Error('hamnoor_reminder_no_user', __('مشارکت‌کننده پنل فعالی ندارد.', 'hamnoor')); }
        $khatm = Hamnoor_DB::get_khatm((int) $part->khatm_id);
        $actionability = self::part_reminder_actionability($part, $khatm);
        if (empty($actionability['actionable'])) { return new WP_Error('hamnoor_reminder_not_actionable', __('این سهم دیگر قابل یادآوری نیست.', 'hamnoor'), $actionability); }
        $percent = 0;
        if ($progress && !empty($progress->start_global_index) && function_exists('manahel_quran_core')) {
            $percent = (int) manahel_quran_core()->progress_percent((int)$progress->start_global_index, (int)$progress->end_global_index, (int)$progress->last_global_index);
        }
        $profile = self::reminder_profile($part, $progress);
        $content = self::part_reminder_content($part, $progress, $khatm, $percent, $profile['state']);
        $notification_id = (int)Hamnoor_DB::add_notification((int)$part->participant_user_id, 'part_reminder', $content['title'], $content['message'], 'part', (int)$part->id);
        if (!$notification_id) { return new WP_Error('hamnoor_reminder_failed', __('ثبت یادآوری انجام نشد.', 'hamnoor')); }
        // The first queue pass runs immediately. Transient failures can remain
        // queued for the background worker, so reporting may return "retrying".
        $delivery = Hamnoor_Notifications::available()
            ? Hamnoor_Notifications::delivery_summary($notification_id)
            : array('notification_id'=>$notification_id,'status'=>'internal_only','channel'=>'internal','attempts'=>0,'rows'=>array());
        global $wpdb;
        $new_count = isset($progress->reminder_count) ? (int)$progress->reminder_count : 0;
        if (!$manual) { $new_count = min(65535, $new_count + 1); }
        $wpdb->update(Hamnoor_DB::table('reading_progress'), array(
            'last_reminder_at'=>current_time('mysql'),
            'reminder_count'=>$new_count,
            'updated_at'=>current_time('mysql'),
        ), array('part_id'=>(int)$part->id), array('%s','%d','%s'), array('%d'));
        $delivery_status = isset($delivery['status']) ? (string)$delivery['status'] : 'pending';
        $delivery_channel = isset($delivery['channel']) ? (string)$delivery['channel'] : '';
        if ('sent' === $delivery_status) {
            $log_message = sprintf(__('یادآوری سهم ثبت و از طریق %s ارسال شد.', 'hamnoor'), self::channel_label($delivery_channel));
        } elseif ('retrying' === $delivery_status) {
            $log_message = __('یادآوری سهم ثبت شد و ارسال بیرونی در صف تلاش مجدد قرار گرفت.', 'hamnoor');
        } elseif ('failed' === $delivery_status) {
            $log_message = __('یادآوری سهم ثبت شد، اما ارسال بیرونی پس از تلاش‌ها ناموفق بود.', 'hamnoor');
        } else {
            $log_message = __('یادآوری سهم در مرکز اعلان‌های هم‌نور ثبت شد؛ کانال بیرونی فعالی در دسترس نبود.', 'hamnoor');
        }
        Hamnoor_DB::log((int)$part->khatm_id,(int)$part->id,(int)$part->participant_id,$manual ? Hamnoor_Mobile_Access::current_user_id() : 0,$manual ? 'owner_part_reminder' : 'auto_part_reminder',$log_message);
        return array('notification_id'=>$notification_id, 'delivery'=>$delivery);
    }

    public static function channel_label_for_admin($channel) { return self::channel_label($channel); }

    private static function channel_label($channel) {
        $labels = array(
            'web_push'=>'Web Push',
            'eitaa'=>__('ایتا', 'hamnoor'),
            'bale'=>__('بله', 'hamnoor'),
            'rubika'=>__('روبیکا', 'hamnoor'),
            'whatsapp'=>__('واتساپ', 'hamnoor'),
            'telegram'=>__('تلگرام', 'hamnoor'),
            'app_push'=>__('Push اپلیکیشن', 'hamnoor'),
            'internal'=>__('مرکز اعلان‌های هم‌نور', 'hamnoor'),
        );
        $channel = sanitize_key((string)$channel);
        return isset($labels[$channel]) ? $labels[$channel] : ($channel ?: __('کانال بیرونی', 'hamnoor'));
    }

    /** Build the exact user-visible reminder content used by automatic/manual/test sends. */
    private static function part_reminder_content($part, $progress = null, $khatm = null, $percent = null, $state = '') {
        if (!$khatm && $part) { $khatm = Hamnoor_DB::get_khatm((int)$part->khatm_id); }
        if (null === $percent || !$state) {
            $profile = self::reminder_profile($part, $progress);
            if (null === $percent) { $percent = (int)$profile['percent']; }
            if (!$state) { $state = (string)$profile['state']; }
        }
        $part_title = $part ? (string)$part->title : __('سهم قرآن', 'hamnoor');
        $khatm_title = $khatm ? (string)$khatm->title : __('پویش ختم قرآن', 'hamnoor');
        switch ($state) {
            case 'not_started':
                return array(
                    'title'=>__('یادآوری شروع قرائت سهم', 'hamnoor'),
                    'message'=>sprintf(__('سهم «%1$s» از پویش «%2$s» هنوز شروع نشده است. هر زمان آماده بودید، با یک لمس قرائت را آغاز کنید.', 'hamnoor'), $part_title, $khatm_title),
                );
            case 'ready':
                return array(
                    'title'=>__('ثبت پایان سهم فراموش نشود', 'hamnoor'),
                    'message'=>sprintf(__('قرائت سهم «%1$s» از پویش «%2$s» به پایان محدوده رسیده است. لطفاً پایان سهم را ثبت کنید تا پیشرفت پویش کامل شود.', 'hamnoor'), $part_title, $khatm_title),
                );
            case 'near_completion':
                return array(
                    'title'=>__('کمی تا پایان سهم باقی مانده', 'hamnoor'),
                    'message'=>sprintf(__('در سهم «%1$s» از پویش «%2$s» به %3$d٪ رسیده‌اید. فقط کمی تا پایان مانده؛ می‌توانید از همان آیه قبلی ادامه دهید.', 'hamnoor'), $part_title, $khatm_title, (int)$percent),
                );
            default:
                return array(
                    'title'=>__('یادآوری ادامه قرائت سهم', 'hamnoor'),
                    'message'=>sprintf(__('مدتی از آخرین قرائت سهم «%1$s» از پویش «%2$s» گذشته است. پیشرفت فعلی شما %3$d٪ است و می‌توانید از همان محل قبلی ادامه دهید.', 'hamnoor'), $part_title, $khatm_title, (int)$percent),
                );
        }
    }

    /**
     * Claimed parts that can safely be used for a real operational reminder
     * test. The user must have a Hamnoor account and must not have disabled
     * reminders for the part.
     */
    public static function operational_test_candidates($limit = 100) {
        global $wpdb;
        $limit = max(1, min(300, (int)$limit));
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $progress = Hamnoor_DB::table('reading_progress');
        $khatms = Hamnoor_DB::table('khatms');
        $users = Hamnoor_DB::table('users');
        if (!$parts || !$participants || !$progress || !$khatms || !$users) { return array(); }
        return $wpdb->get_results(
            "SELECT p.id AS part_id,p.title AS part_title,p.claimed_at,p.khatm_id,
                    r.user_id,r.name AS participant_name,r.phone AS participant_phone,
                    k.title AS khatm_title,k.status AS khatm_status,
                    rp.reminder_enabled,rp.last_reminder_at,rp.reminder_count,rp.reading_status,
                    u.display_name,u.phone AS user_phone
             FROM $parts p
             INNER JOIN $participants r ON r.id=p.participant_id
             INNER JOIN $khatms k ON k.id=p.khatm_id
             LEFT JOIN $progress rp ON rp.part_id=p.id
             LEFT JOIN $users u ON u.id=r.user_id
             WHERE p.status='claimed' AND r.user_id IS NOT NULL AND r.user_id>0
                   AND (rp.reminder_enabled IS NULL OR rp.reminder_enabled<>0)
             ORDER BY p.id DESC LIMIT " . (int)$limit
        );
    }

    /**
     * Send the exact part reminder through the normal notification-created hook
     * and Dispatcher, but deliberately do NOT touch reminder_count,
     * last_reminder_at, claimed_at, or reading progress. This is a real external
     * send and therefore creates normal notification/delivery report rows.
     */
    public static function send_operational_test($part_id) {
        $part_id = (int)$part_id;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || 'claimed' !== (string)$part->status || empty($part->participant_user_id)) {
            return new WP_Error('hamnoor_test_part_invalid', __('این سهم برای تست عملیاتی یادآوری مناسب نیست.', 'hamnoor'));
        }
        $progress = Hamnoor_Reading::ensure_progress($part);
        if (is_wp_error($progress)) { return $progress; }
        if (isset($progress->reminder_enabled) && 0 === (int)$progress->reminder_enabled) {
            return new WP_Error('hamnoor_test_reminder_disabled', __('مشارکت‌کننده یادآوری این سهم را غیرفعال کرده است؛ تست واقعی برای او ارسال نشد.', 'hamnoor'));
        }

        $before = array(
            'last_reminder_at'=>!empty($progress->last_reminder_at) ? (string)$progress->last_reminder_at : '',
            'reminder_count'=>isset($progress->reminder_count) ? (int)$progress->reminder_count : 0,
        );
        $route = Hamnoor_Notifications::available()
            ? Hamnoor_Notifications::route_diagnostics((int)$part->participant_user_id)
            : array('winner'=>'','rows'=>array());
        $content = self::part_reminder_content($part, $progress);
        $notification_id = (int)Hamnoor_DB::add_notification(
            (int)$part->participant_user_id,
            'part_reminder',
            $content['title'],
            $content['message'],
            'part',
            $part_id
        );
        if (!$notification_id) {
            return new WP_Error('hamnoor_test_notification_failed', __('اعلان آزمایشی ثبت نشد.', 'hamnoor'));
        }
        $delivery = Hamnoor_Notifications::available()
            ? Hamnoor_Notifications::delivery_summary($notification_id)
            : array('notification_id'=>$notification_id,'status'=>'internal_only','channel'=>'internal','attempts'=>0,'rows'=>array());

        // Re-read progress only to prove that the schedule fields were untouched.
        $after_progress = Hamnoor_Reading::ensure_progress(Hamnoor_DB::get_part($part_id));
        $after = array(
            'last_reminder_at'=>!is_wp_error($after_progress) && !empty($after_progress->last_reminder_at) ? (string)$after_progress->last_reminder_at : '',
            'reminder_count'=>!is_wp_error($after_progress) && isset($after_progress->reminder_count) ? (int)$after_progress->reminder_count : 0,
        );
        Hamnoor_DB::log((int)$part->khatm_id, $part_id, (int)$part->participant_id, 0, 'admin_operational_reminder_test', __('تست عملیاتی یادآوری از مسیر واقعی Dispatcher اجرا شد؛ زمان‌بندی یادآوری سهم تغییر نکرد.', 'hamnoor'));
        return array(
            'at'=>current_time('mysql'),
            'part_id'=>$part_id,
            'user_id'=>(int)$part->participant_user_id,
            'participant_name'=>!empty($part->participant_name) ? (string)$part->participant_name : '',
            'participant_phone'=>!empty($part->participant_phone) ? (string)$part->participant_phone : '',
            'part_title'=>(string)$part->title,
            'notification_id'=>$notification_id,
            'route'=>$route,
            'delivery'=>$delivery,
            'schedule_before'=>$before,
            'schedule_after'=>$after,
            'schedule_unchanged'=>($before === $after) ? 1 : 0,
        );
    }

    /**
     * Technical channel test: send the reminder to every eligible external
     * channel for the selected user. Unlike the production Dispatcher this does
     * not stop at the first success. Reminder scheduling fields stay untouched.
     */
    public static function send_all_channels_test($part_id) {
        $part_id = (int)$part_id;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || 'claimed' !== (string)$part->status || empty($part->participant_user_id)) {
            return new WP_Error('hamnoor_test_part_invalid', __('این سهم برای تست همه کانال‌ها مناسب نیست.', 'hamnoor'));
        }
        $progress = Hamnoor_Reading::ensure_progress($part);
        if (is_wp_error($progress)) { return $progress; }
        if (isset($progress->reminder_enabled) && 0 === (int)$progress->reminder_enabled) {
            return new WP_Error('hamnoor_test_reminder_disabled', __('مشارکت‌کننده یادآوری این سهم را غیرفعال کرده است؛ تست واقعی برای او ارسال نشد.', 'hamnoor'));
        }

        $before = array(
            'last_reminder_at'=>!empty($progress->last_reminder_at) ? (string)$progress->last_reminder_at : '',
            'reminder_count'=>isset($progress->reminder_count) ? (int)$progress->reminder_count : 0,
        );
        $route = Hamnoor_Notifications::available()
            ? Hamnoor_Notifications::route_diagnostics((int)$part->participant_user_id)
            : array('winner'=>'','rows'=>array());
        $content = self::part_reminder_content($part, $progress);
        $content['title'] = sprintf(__('تست همه کانال‌ها — %s', 'hamnoor'), $content['title']);

        // Store one normal Hamnoor notification but deliberately suppress the
        // automatic first-success dispatch; we immediately dispatch it to all
        // eligible channels below.
        $notification_id = (int)Hamnoor_DB::add_notification(
            (int)$part->participant_user_id,
            'part_reminder',
            $content['title'],
            $content['message'],
            'part',
            $part_id,
            false
        );
        if (!$notification_id) {
            return new WP_Error('hamnoor_test_notification_failed', __('اعلان تست همه کانال‌ها ثبت نشد.', 'hamnoor'));
        }

        $dispatch = Hamnoor_Notifications::available()
            ? Hamnoor_Notifications::dispatch_all_by_id($notification_id, array('test_mode'=>'all_channels'))
            : new WP_Error('hamnoor_dispatcher_missing', __('Dispatcher اعلان در دسترس نیست.', 'hamnoor'));
        if (is_wp_error($dispatch)) { return $dispatch; }

        $after_progress = Hamnoor_Reading::ensure_progress(Hamnoor_DB::get_part($part_id));
        $after = array(
            'last_reminder_at'=>!is_wp_error($after_progress) && !empty($after_progress->last_reminder_at) ? (string)$after_progress->last_reminder_at : '',
            'reminder_count'=>!is_wp_error($after_progress) && isset($after_progress->reminder_count) ? (int)$after_progress->reminder_count : 0,
        );
        Hamnoor_DB::log((int)$part->khatm_id, $part_id, (int)$part->participant_id, 0, 'admin_all_channels_reminder_test', __('تست فنی یادآوری به همه کانال‌های متصل و فعال اجرا شد؛ زمان‌بندی یادآوری سهم تغییر نکرد.', 'hamnoor'));
        return array(
            'at'=>current_time('mysql'),
            'part_id'=>$part_id,
            'user_id'=>(int)$part->participant_user_id,
            'participant_name'=>!empty($part->participant_name) ? (string)$part->participant_name : '',
            'participant_phone'=>!empty($part->participant_phone) ? (string)$part->participant_phone : '',
            'part_title'=>(string)$part->title,
            'notification_id'=>$notification_id,
            'route'=>$route,
            'dispatch'=>$dispatch,
            'schedule_before'=>$before,
            'schedule_after'=>$after,
            'schedule_unchanged'=>($before === $after) ? 1 : 0,
        );
    }

    public static function run_internal_reminders() {
        global $wpdb;
        $settings = self::smart_reminder_settings();
        $now = current_time('timestamp');
        $run_stats = array(
            'at'=>current_time('mysql'),
            'engine'=>'smart_v1',
            'scanned'=>0,
            'reminders_created'=>0,
            'due_not_started'=>0,
            'due_reading'=>0,
            'due_near_completion'=>0,
            'due_ready'=>0,
            'external_sent'=>0,
            'external_failed'=>0,
            'queued_retry'=>0,
            'internal_only'=>0,
            'skipped_disabled'=>0,
            'skipped_max'=>0,
            'skipped_not_due'=>0,
            'skipped_repeat'=>0,
            'settings'=>$settings,
        );
        if (empty($settings['enabled'])) {
            $run_stats['disabled'] = 1;
            update_option('hamnoor_reminder_last_run', $run_stats, false);
            return $run_stats;
        }
        $parts_table = Hamnoor_DB::table('parts');
        $participant_table = Hamnoor_DB::table('participants');
        $cursor = max(0, (int)get_option('hamnoor_reminder_cursor', 0));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.id FROM $parts_table p INNER JOIN $participant_table r ON r.id=p.participant_id WHERE p.status='claimed' AND r.user_id IS NOT NULL AND r.user_id>0 AND p.id>%d ORDER BY p.id ASC LIMIT 500",
            $cursor
        ));
        if (!$ids && $cursor) {
            $cursor = 0;
            $ids = $wpdb->get_col("SELECT p.id FROM $parts_table p INNER JOIN $participant_table r ON r.id=p.participant_id WHERE p.status='claimed' AND r.user_id IS NOT NULL AND r.user_id>0 ORDER BY p.id ASC LIMIT 500");
        }
        if ($ids) {
            $last_id = (int)end($ids);
            update_option('hamnoor_reminder_cursor', count($ids) >= 500 ? $last_id : 0, false);
        } else {
            update_option('hamnoor_reminder_cursor', 0, false);
        }

        foreach ($ids as $id) {
            $run_stats['scanned']++;
            $part = Hamnoor_DB::get_part((int)$id);
            if (!$part) { continue; }
            $progress = Hamnoor_Reading::ensure_progress($part);
            if (is_wp_error($progress)) { continue; }
            $khatm = Hamnoor_DB::get_khatm((int)$part->khatm_id);
            $decision = self::smart_reminder_decision($part, $progress, $now, $settings, $khatm);
            if (empty($decision['due'])) {
                $reason = isset($decision['reason']) ? (string)$decision['reason'] : 'not_due';
                if ('user_disabled' === $reason) { $run_stats['skipped_disabled']++; }
                elseif ('max_reached' === $reason) { $run_stats['skipped_max']++; }
                elseif ('repeat_not_due' === $reason) { $run_stats['skipped_repeat']++; }
                else { $run_stats['skipped_not_due']++; }
                continue;
            }
            $state = isset($decision['state']) ? sanitize_key((string)$decision['state']) : 'reading';
            $stat_key = 'due_' . $state;
            if (isset($run_stats[$stat_key])) { $run_stats[$stat_key]++; }
            $result = self::send_part_reminder($part, $progress, false);
            if (is_wp_error($result)) { continue; }
            $run_stats['reminders_created']++;
            $status = isset($result['delivery']['status']) ? (string)$result['delivery']['status'] : 'internal_only';
            if ('sent' === $status) { $run_stats['external_sent']++; }
            elseif ('retrying' === $status) { $run_stats['queued_retry']++; }
            elseif ('failed' === $status) { $run_stats['external_failed']++; }
            else { $run_stats['internal_only']++; }
        }
        update_option('hamnoor_reminder_last_run', $run_stats, false);
        return $run_stats;
    }

}
