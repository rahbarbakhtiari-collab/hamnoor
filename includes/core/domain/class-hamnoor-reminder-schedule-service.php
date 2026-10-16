<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Generic one-rule-per-scope reminder scheduler.
 *
 * The first consumer is Personal Quran. The schedule is deliberately kept
 * separate from reading progress so reminder preferences can evolve without
 * mutating the canonical reading facts.
 */
final class Hamnoor_Reminder_Schedule_Service {
    const SCOPE_PERSONAL_QURAN = 'personal_quran';
    const SCOPE_PENDING_DUA = 'pending_dua';
    const DEFAULT_TIME = '20:00';
    const ALL_DAYS_MASK = 127;

    public static function get($scope_type, $scope_id, $user_id = 0) {
        global $wpdb;
        $table = Hamnoor_DB::table('reminder_schedules');
        if (!$table) { return null; }
        $scope_type = sanitize_key((string)$scope_type);
        $scope_id = (int)$scope_id;
        $user_id = (int)$user_id;
        if (!$scope_type || !$scope_id) { return null; }
        if ($user_id) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE scope_type=%s AND scope_id=%d AND user_id=%d LIMIT 1", $scope_type, $scope_id, $user_id));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE scope_type=%s AND scope_id=%d LIMIT 1", $scope_type, $scope_id));
    }

    public static function default_timezone() {
        $timezone = function_exists('wp_timezone_string') ? (string)wp_timezone_string() : 'UTC';
        return self::sanitize_timezone($timezone ?: 'UTC');
    }

    public static function sanitize_timezone($timezone) {
        $timezone = trim((string)$timezone);
        if ('' === $timezone) { return 'UTC'; }
        try {
            new DateTimeZone($timezone);
            return substr($timezone, 0, 64);
        } catch (Throwable $e) {
            return 'UTC';
        }
    }

    public static function sanitize_local_time($time) {
        $time = trim(Hamnoor_Validator::normalize_digits((string)$time));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) { return self::DEFAULT_TIME; }
        return $time;
    }

    public static function days_mask_from_values($days) {
        $mask = 0;
        foreach ((array)$days as $day) {
            $day = (int)Hamnoor_Validator::normalize_digits((string)$day);
            if ($day >= 1 && $day <= 7) { $mask |= (1 << ($day - 1)); }
        }
        return $mask;
    }

    public static function day_enabled($mask, $iso_day) {
        $iso_day = (int)$iso_day;
        return $iso_day >= 1 && $iso_day <= 7 && (((int)$mask & (1 << ($iso_day - 1))) !== 0);
    }

    /** Return a UTC MySQL datetime strictly after $after_utc. */
    public static function next_due_at_utc($local_time, $days_mask, $timezone, $after_utc = null) {
        $local_time = self::sanitize_local_time($local_time);
        $days_mask = max(1, min(self::ALL_DAYS_MASK, (int)$days_mask));
        $timezone = self::sanitize_timezone($timezone);
        $after_ts = null === $after_utc ? time() : (is_numeric($after_utc) ? (int)$after_utc : strtotime((string)$after_utc . ' UTC'));
        if (!$after_ts) { $after_ts = time(); }
        try {
            $tz = new DateTimeZone($timezone);
            $after_local = (new DateTimeImmutable('@' . $after_ts))->setTimezone($tz);
            list($hour, $minute) = array_map('intval', explode(':', $local_time));
            for ($offset = 0; $offset <= 14; $offset++) {
                $candidate_day = 0 === $offset ? $after_local : $after_local->modify('+' . $offset . ' day');
                $candidate = $candidate_day->setTime($hour, $minute, 0);
                $iso_day = (int)$candidate->format('N');
                if (!self::day_enabled($days_mask, $iso_day)) { continue; }
                if ($candidate->getTimestamp() <= $after_ts) { continue; }
                return gmdate('Y-m-d H:i:s', $candidate->getTimestamp());
            }
        } catch (Throwable $e) {
            return gmdate('Y-m-d H:i:s', $after_ts + DAY_IN_SECONDS);
        }
        return gmdate('Y-m-d H:i:s', $after_ts + DAY_IN_SECONDS);
    }

    private static function assert_personal_quran_owner($campaign, $user_id) {
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) {
            return new WP_Error('hamnoor_personal_reminder_invalid_scope', __('این یادآوری به یک ختم شخصی معتبر متصل نیست.', 'hamnoor'));
        }
        if (!(int)$user_id || (int)$campaign->owner_user_id !== (int)$user_id) {
            return new WP_Error('hamnoor_personal_reminder_owner_required', __('فقط مالک ختم شخصی می‌تواند یادآوری آن را تنظیم کند.', 'hamnoor'));
        }
        return true;
    }

    public static function personal_quran_state($campaign, $user_id) {
        $allowed = self::assert_personal_quran_owner($campaign, $user_id);
        if (is_wp_error($allowed)) { return $allowed; }
        $row = self::get(self::SCOPE_PERSONAL_QURAN, (int)$campaign->id, (int)$user_id);
        $timezone = $row ? (string)$row->timezone : self::default_timezone();
        $mask = $row ? (int)$row->days_mask : self::ALL_DAYS_MASK;
        return array(
            'exists'=>(bool)$row,
            'enabled'=>$row ? (bool)$row->enabled : false,
            'local_time'=>$row ? (string)$row->local_time : self::DEFAULT_TIME,
            'timezone'=>$timezone,
            'days_mask'=>$mask,
            'snoozed_until_utc'=>$row && !empty($row->snoozed_until_utc) ? (string)$row->snoozed_until_utc : '',
            'next_due_at_utc'=>$row && !empty($row->next_due_at_utc) ? (string)$row->next_due_at_utc : '',
            'last_sent_at_utc'=>$row && !empty($row->last_sent_at_utc) ? (string)$row->last_sent_at_utc : '',
            'send_count'=>$row ? (int)$row->send_count : 0,
            'disabled_reason'=>$row && !empty($row->disabled_reason) ? (string)$row->disabled_reason : '',
        );
    }

    public static function save_personal_quran($campaign, $user_id, $input) {
        global $wpdb;
        $allowed = self::assert_personal_quran_owner($campaign, $user_id);
        if (is_wp_error($allowed)) { return $allowed; }
        if (in_array((string)$campaign->lifecycle_status, array(Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED, Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED, Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED), true)) {
            return new WP_Error('hamnoor_personal_reminder_closed', __('برای ختم پایان‌یافته یا لغوشده نمی‌توان یادآوری فعال کرد.', 'hamnoor'));
        }
        $input = is_array($input) ? $input : array();
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $time = self::sanitize_local_time($input['local_time'] ?? self::DEFAULT_TIME);
        $timezone = self::sanitize_timezone($input['timezone'] ?? self::default_timezone());
        $mask = self::days_mask_from_values($input['days'] ?? array());
        if ($enabled && !$mask) { return new WP_Error('hamnoor_personal_reminder_days_required', __('حداقل یک روز برای یادآوری انتخاب کنید.', 'hamnoor')); }
        if (!$mask) { $mask = self::ALL_DAYS_MASK; }
        $now = gmdate('Y-m-d H:i:s');
        $is_active = Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE === (string)$campaign->lifecycle_status;
        $next = ($enabled && $is_active) ? self::next_due_at_utc($time, $mask, $timezone, time()) : null;
        $table = Hamnoor_DB::table('reminder_schedules');
        $existing = self::get(self::SCOPE_PERSONAL_QURAN, (int)$campaign->id, (int)$user_id);
        $values = array(
            'enabled'=>$enabled,
            'local_time'=>$time,
            'timezone'=>$timezone,
            'days_mask'=>$mask,
            'snoozed_until_utc'=>null,
            'next_due_at_utc'=>$next,
            'disabled_reason'=>$enabled ? null : 'user',
            'updated_at_utc'=>$now,
        );
        if ($existing) {
            $ok = $wpdb->update($table, $values, array('id'=>(int)$existing->id), array('%d','%s','%s','%d','%s','%s','%s','%s'), array('%d'));
            if (false === $ok) { return new WP_Error('hamnoor_personal_reminder_save_failed', __('ذخیره تنظیم یادآوری انجام نشد.', 'hamnoor')); }
        } else {
            $values = array_merge(array(
                'public_id'=>Hamnoor_DB::generate_public_id('RMD'),
                'user_id'=>(int)$user_id,
                'scope_type'=>self::SCOPE_PERSONAL_QURAN,
                'scope_id'=>(int)$campaign->id,
                'last_occurrence_key'=>null,
                'send_count'=>0,
                'metadata_json'=>null,
                'created_at_utc'=>$now,
            ), $values);
            $ok = $wpdb->insert($table, $values, array('%s','%d','%s','%d','%s','%d','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s'));
            if (!$ok) {
                $retry = self::get(self::SCOPE_PERSONAL_QURAN, (int)$campaign->id, (int)$user_id);
                if (!$retry) { return new WP_Error('hamnoor_personal_reminder_save_failed', __('ذخیره تنظیم یادآوری انجام نشد.', 'hamnoor')); }
            }
        }
        return self::personal_quran_state($campaign, $user_id);
    }

    public static function snooze_personal_quran($campaign, $user_id, $days = 1) {
        global $wpdb;
        $allowed = self::assert_personal_quran_owner($campaign, $user_id);
        if (is_wp_error($allowed)) { return $allowed; }
        $row = self::get(self::SCOPE_PERSONAL_QURAN, (int)$campaign->id, (int)$user_id);
        if (!$row || empty($row->enabled)) { return new WP_Error('hamnoor_personal_reminder_not_enabled', __('ابتدا یادآوری ختم شخصی را فعال کنید.', 'hamnoor')); }
        if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status) { return new WP_Error('hamnoor_personal_reminder_not_active', __('در زمان توقف ختم، یادآوری نیز متوقف است.', 'hamnoor')); }
        $days = max(1, min(30, (int)$days));
        $until_ts = time() + ($days * DAY_IN_SECONDS);
        $until = gmdate('Y-m-d H:i:s', $until_ts);
        $next = self::next_due_at_utc((string)$row->local_time, (int)$row->days_mask, (string)$row->timezone, $until_ts);
        $ok = $wpdb->update(Hamnoor_DB::table('reminder_schedules'), array(
            'snoozed_until_utc'=>$until,
            'next_due_at_utc'=>$next,
            'updated_at_utc'=>gmdate('Y-m-d H:i:s'),
        ), array('id'=>(int)$row->id), array('%s','%s','%s'), array('%d'));
        if (false === $ok) { return new WP_Error('hamnoor_personal_reminder_snooze_failed', __('تعویق یادآوری انجام نشد.', 'hamnoor')); }
        return self::personal_quran_state($campaign, $user_id);
    }

    public static function lifecycle_changed($campaign_id, $from, $to, $user_id) {
        global $wpdb;
        $row = self::get(self::SCOPE_PERSONAL_QURAN, (int)$campaign_id, (int)$user_id);
        if (!$row) { return; }
        $table = Hamnoor_DB::table('reminder_schedules');
        $fields = array('updated_at_utc'=>gmdate('Y-m-d H:i:s'));
        $formats = array('%s');
        if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE === (string)$to && !empty($row->enabled)) {
            $after = time();
            if (!empty($row->snoozed_until_utc)) {
                $snooze = strtotime((string)$row->snoozed_until_utc . ' UTC');
                if ($snooze && $snooze > $after) { $after = $snooze; }
            }
            $fields['next_due_at_utc'] = self::next_due_at_utc((string)$row->local_time, (int)$row->days_mask, (string)$row->timezone, $after);
            $formats[] = '%s';
        } elseif (Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED === (string)$to) {
            $fields['next_due_at_utc'] = null;
            $formats[] = '%s';
        } elseif (in_array((string)$to, array(Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED, Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED, Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED), true)) {
            $fields['enabled'] = 0; $formats[] = '%d';
            $fields['next_due_at_utc'] = null; $formats[] = '%s';
            $fields['snoozed_until_utc'] = null; $formats[] = '%s';
            $fields['disabled_reason'] = sanitize_key((string)$to); $formats[] = '%s';
        }
        $wpdb->update($table, $fields, array('id'=>(int)$row->id), $formats, array('%d'));
    }

    public static function completed($campaign_id, $user_id) {
        self::lifecycle_changed((int)$campaign_id, Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE, Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED, (int)$user_id);
    }

    public static function schedule_pending_dua($contribution, $user_id) {
        global $wpdb;
        if (!$contribution || 'dua_commitment' !== (string)$contribution->kind || 'pending' !== (string)$contribution->status || (int)$contribution->user_id !== (int)$user_id) { return false; }
        $table = Hamnoor_DB::table('reminder_schedules');
        $existing = self::get(self::SCOPE_PENDING_DUA, (int)$contribution->id, (int)$user_id);
        if ($existing) { return $existing; }
        $now = gmdate('Y-m-d H:i:s'); $timezone = self::default_timezone();
        $ok = $wpdb->insert($table, array('public_id'=>Hamnoor_DB::generate_public_id('RMD'),'user_id'=>(int)$user_id,'scope_type'=>self::SCOPE_PENDING_DUA,'scope_id'=>(int)$contribution->id,'enabled'=>1,'local_time'=>self::DEFAULT_TIME,'timezone'=>$timezone,'days_mask'=>self::ALL_DAYS_MASK,'next_due_at_utc'=>self::next_due_at_utc(self::DEFAULT_TIME,self::ALL_DAYS_MASK,$timezone,time()),'send_count'=>0,'created_at_utc'=>$now,'updated_at_utc'=>$now), array('%s','%d','%s','%d','%d','%s','%s','%d','%s','%d','%s','%s'));
        return $ok ? self::get(self::SCOPE_PENDING_DUA, (int)$contribution->id, (int)$user_id) : false;
    }

    public static function close_pending_dua($contribution_id, $reason = 'completed') {
        global $wpdb;
        return false !== $wpdb->update(Hamnoor_DB::table('reminder_schedules'), array('enabled'=>0,'next_due_at_utc'=>null,'disabled_reason'=>sanitize_key($reason),'updated_at_utc'=>gmdate('Y-m-d H:i:s')), array('scope_type'=>self::SCOPE_PENDING_DUA,'scope_id'=>(int)$contribution_id), array('%d','%s','%s','%s'), array('%s','%d'));
    }

    public static function process_due($limit = 100) {
        global $wpdb;
        $limit = max(1, min(500, (int)$limit));
        $table = Hamnoor_DB::table('reminder_schedules');
        if (!$table) { return array('scanned'=>0,'sent'=>0,'skipped'=>0,'failed'=>0); }
        $now = gmdate('Y-m-d H:i:s');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE enabled=1 AND next_due_at_utc IS NOT NULL AND next_due_at_utc<=%s ORDER BY next_due_at_utc ASC,id ASC LIMIT %d",
            $now,
            $limit
        ));
        $stats = array('at'=>$now,'scanned'=>0,'sent'=>0,'skipped'=>0,'failed'=>0,'internal_only'=>0,'external_sent'=>0,'retrying'=>0);
        foreach ((array)$ids as $id) {
            $stats['scanned']++;
            $result = self::fire((int)$id);
            if (is_wp_error($result)) { $stats['failed']++; continue; }
            if (empty($result['sent'])) { $stats['skipped']++; continue; }
            $stats['sent']++;
            $status = isset($result['delivery']['status']) ? (string)$result['delivery']['status'] : 'internal_only';
            if ('sent' === $status) { $stats['external_sent']++; }
            elseif ('retrying' === $status) { $stats['retrying']++; }
            else { $stats['internal_only']++; }
        }
        update_option('hamnoor_personal_reminder_last_run', $stats, false);
        return $stats;
    }

    private static function fire($schedule_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('reminder_schedules');
        $campaigns = Hamnoor_DB::table('khatms');
        $schedule_id = (int)$schedule_id;
        $now_ts = time();
        $now = gmdate('Y-m-d H:i:s', $now_ts);
        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1 FOR UPDATE", $schedule_id));
            if (!$row || empty($row->enabled) || empty($row->next_due_at_utc) || (string)$row->next_due_at_utc > $now) {
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'not_due');
            }
            if (self::SCOPE_PENDING_DUA === (string)$row->scope_type) {
                $contribution = $wpdb->get_row($wpdb->prepare("SELECT c.*,k.title AS campaign_title,k.token AS campaign_token,k.lifecycle_status FROM " . Hamnoor_DB::table('contributions') . " c INNER JOIN $campaigns k ON k.id=c.campaign_id WHERE c.id=%d LIMIT 1", (int)$row->scope_id));
                if (!$contribution || 'pending' !== (string)$contribution->status || 'dua_commitment' !== (string)$contribution->kind || (int)$contribution->user_id !== (int)$row->user_id || in_array((string)$contribution->lifecycle_status, array(Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED,Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED), true)) {
                    $wpdb->update($table,array('enabled'=>0,'next_due_at_utc'=>null,'disabled_reason'=>'closed','updated_at_utc'=>$now),array('id'=>$schedule_id)); $wpdb->query('COMMIT'); return array('sent'=>false,'reason'=>'closed');
                }
                $occurrence_key = hash('sha256', (string)$row->public_id . '|' . (string)$row->next_due_at_utc);
                $previous = array(
                    'last_occurrence_key'=>!empty($row->last_occurrence_key) ? (string)$row->last_occurrence_key : null,
                    'last_sent_at_utc'=>!empty($row->last_sent_at_utc) ? (string)$row->last_sent_at_utc : null,
                    'send_count'=>(int)$row->send_count,
                    'next_due_at_utc'=>(string)$row->next_due_at_utc,
                );
                $next = self::next_due_at_utc((string)$row->local_time,(int)$row->days_mask,(string)$row->timezone,$now_ts);
                $updated = $wpdb->update(
                    $table,
                    array('last_occurrence_key'=>$occurrence_key,'last_sent_at_utc'=>$now,'send_count'=>min(4294967295,(int)$row->send_count+1),'next_due_at_utc'=>$next,'updated_at_utc'=>$now),
                    array('id'=>$schedule_id),
                    array('%s','%s','%d','%s','%s'),
                    array('%d')
                );
                if (1 !== (int)$updated) {
                    throw new RuntimeException(false === $updated ? ($wpdb->last_error ?: 'dua_reminder_schedule_claim_failed') : 'dua_reminder_schedule_claim_conflict');
                }
                $wpdb->query('COMMIT');
                $notification_id=(int)Hamnoor_DB::add_notification((int)$row->user_id,'dua_reminder',__('یادآوری قرائت دعا','hamnoor'),sprintf(__('دعای «%s» هنوز در انتظار قرائت شماست.','hamnoor'),(string)$contribution->campaign_title),'contribution',(int)$contribution->id);
                if (!$notification_id) {
                    // Release only our occurrence so the reminder can be retried safely.
                    $wpdb->update($table, array(
                        'last_occurrence_key'=>$previous['last_occurrence_key'],
                        'last_sent_at_utc'=>$previous['last_sent_at_utc'],
                        'send_count'=>$previous['send_count'],
                        'next_due_at_utc'=>$previous['next_due_at_utc'],
                        'updated_at_utc'=>gmdate('Y-m-d H:i:s'),
                    ), array('id'=>$schedule_id,'last_occurrence_key'=>$occurrence_key), array('%s','%s','%d','%s','%s'), array('%d','%s'));
                    return new WP_Error('hamnoor_dua_reminder_notification_failed', __('ثبت اعلان یادآوری دعا انجام نشد.', 'hamnoor'));
                }
                return array('sent'=>true,'notification_id'=>$notification_id,'delivery'=>Hamnoor_Notifications::available()?Hamnoor_Notifications::delivery_summary($notification_id):array('status'=>'internal_only'));
            }
            if (self::SCOPE_PERSONAL_QURAN !== (string)$row->scope_type) {
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'unsupported_scope');
            }
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d LIMIT 1", (int)$row->scope_id));
            if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign) || (int)$campaign->owner_user_id !== (int)$row->user_id) {
                $wpdb->update($table, array('enabled'=>0,'next_due_at_utc'=>null,'disabled_reason'=>'invalid_scope','updated_at_utc'=>$now), array('id'=>$schedule_id), array('%d','%s','%s','%s'), array('%d'));
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'invalid_scope');
            }
            if (Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED === (string)$campaign->lifecycle_status) {
                $wpdb->update($table, array('next_due_at_utc'=>null,'updated_at_utc'=>$now), array('id'=>$schedule_id), array('%s','%s'), array('%d'));
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'paused');
            }
            if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status) {
                $wpdb->update($table, array('enabled'=>0,'next_due_at_utc'=>null,'disabled_reason'=>sanitize_key((string)$campaign->lifecycle_status),'updated_at_utc'=>$now), array('id'=>$schedule_id), array('%d','%s','%s','%s'), array('%d'));
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'closed');
            }
            if (!empty($row->snoozed_until_utc) && (string)$row->snoozed_until_utc > $now) {
                $snooze_ts = strtotime((string)$row->snoozed_until_utc . ' UTC');
                $next = self::next_due_at_utc((string)$row->local_time, (int)$row->days_mask, (string)$row->timezone, $snooze_ts ?: $now_ts);
                $wpdb->update($table, array('next_due_at_utc'=>$next,'updated_at_utc'=>$now), array('id'=>$schedule_id), array('%s','%s'), array('%d'));
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'snoozed');
            }
            $occurrence_key = hash('sha256', (string)$row->public_id . '|' . (string)$row->next_due_at_utc);
            if (!empty($row->last_occurrence_key) && hash_equals((string)$row->last_occurrence_key, $occurrence_key)) {
                $next = self::next_due_at_utc((string)$row->local_time, (int)$row->days_mask, (string)$row->timezone, $now_ts);
                $wpdb->update($table, array('next_due_at_utc'=>$next,'updated_at_utc'=>$now), array('id'=>$schedule_id), array('%s','%s'), array('%d'));
                $wpdb->query('COMMIT');
                return array('sent'=>false,'reason'=>'already_fired');
            }
            $previous = array(
                'last_occurrence_key'=>!empty($row->last_occurrence_key) ? (string)$row->last_occurrence_key : null,
                'last_sent_at_utc'=>!empty($row->last_sent_at_utc) ? (string)$row->last_sent_at_utc : null,
                'send_count'=>(int)$row->send_count,
                'next_due_at_utc'=>(string)$row->next_due_at_utc,
                'snoozed_until_utc'=>!empty($row->snoozed_until_utc) ? (string)$row->snoozed_until_utc : null,
            );
            $next = self::next_due_at_utc((string)$row->local_time, (int)$row->days_mask, (string)$row->timezone, $now_ts);
            $updated = $wpdb->update($table, array(
                'last_occurrence_key'=>$occurrence_key,
                'last_sent_at_utc'=>$now,
                'send_count'=>min(4294967295, (int)$row->send_count + 1),
                'next_due_at_utc'=>$next,
                'snoozed_until_utc'=>null,
                'updated_at_utc'=>$now,
            ), array('id'=>$schedule_id), array('%s','%s','%d','%s','%s','%s'), array('%d'));
            if (false === $updated) { throw new RuntimeException($wpdb->last_error ?: 'reminder_schedule_claim_failed'); }
            $wpdb->query('COMMIT');

            $progress = Hamnoor_Personal_Quran_Progress_Service::progress($campaign);
            $percent = is_wp_error($progress) ? 0 : (int)round((float)$progress['percentage']);
            $resume_index = is_wp_error($progress) ? Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX : (int)$progress['resume_global_index'];
            $page = class_exists('Hamnoor_Personal_Quran') ? Hamnoor_Personal_Quran::page_for_global_index($resume_index) : 1;
            $title = __('زمان ادامه ختم شخصی قرآن', 'hamnoor');
            $message = 0 < $percent
                ? sprintf(__('یادآوری شما رسید. تا اینجا %1$d٪ از قرآن را خوانده‌اید؛ می‌توانید از صفحه %2$d ادامه دهید.', 'hamnoor'), $percent, $page)
                : __('یادآوری شما رسید. هر زمان آماده‌اید، ختم شخصی قرآن را ادامه دهید.', 'hamnoor');
            $notification_id = (int)Hamnoor_DB::add_notification((int)$row->user_id, 'personal_quran_reminder', $title, $message, self::SCOPE_PERSONAL_QURAN, (int)$campaign->id);
            if (!$notification_id) {
                // Release this occurrence only if nobody changed the row after us.
                $wpdb->update($table, array(
                    'last_occurrence_key'=>$previous['last_occurrence_key'],
                    'last_sent_at_utc'=>$previous['last_sent_at_utc'],
                    'send_count'=>$previous['send_count'],
                    'next_due_at_utc'=>$previous['next_due_at_utc'],
                    'snoozed_until_utc'=>$previous['snoozed_until_utc'],
                    'updated_at_utc'=>gmdate('Y-m-d H:i:s'),
                ), array('id'=>$schedule_id,'last_occurrence_key'=>$occurrence_key), array('%s','%s','%d','%s','%s','%s'), array('%d','%s'));
                return new WP_Error('hamnoor_personal_reminder_notification_failed', __('ثبت اعلان یادآوری ختم شخصی انجام نشد.', 'hamnoor'));
            }
            $delivery = Hamnoor_Notifications::available()
                ? Hamnoor_Notifications::delivery_summary($notification_id)
                : array('notification_id'=>$notification_id,'status'=>'internal_only','channel'=>'internal','attempts'=>0,'rows'=>array());
            return array('sent'=>true,'notification_id'=>$notification_id,'delivery'=>$delivery);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[Hamnoor] Reminder schedule fire failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_personal_reminder_fire_failed', __('اجرای یادآوری ختم شخصی انجام نشد.', 'hamnoor'));
        }
    }
}
