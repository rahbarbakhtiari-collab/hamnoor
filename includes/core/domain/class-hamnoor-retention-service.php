<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Retention / scale foundation.
 *
 * Principles:
 * - durable product truth is never deleted by this service;
 * - automatic housekeeping is limited to low-risk ephemeral rows;
 * - destructive maintenance requires two independent opt-ins;
 * - campaign ledgers may be deleted only after an idempotent rollup is current;
 * - all deletes are bounded batches and therefore resumable.
 */
final class Hamnoor_Retention_Service {
    const POLICY_VERSION = 1;
    const ROLLUP_KEY = 'campaign_summary_v1';
    const MAX_BATCH = 500;

    public static function policies() {
        return array(
            'users' => array('class'=>'durable','retention_days'=>null,'execution'=>'never'),
            'khatms' => array('class'=>'durable','retention_days'=>null,'execution'=>'never'),
            'content_items' => array('class'=>'durable','retention_days'=>null,'execution'=>'never'),
            'migrations' => array('class'=>'durable','retention_days'=>null,'execution'=>'never'),
            'domain_events' => array('class'=>'durable','retention_days'=>null,'execution'=>'never'),
            'retention_rollups' => array('class'=>'derived','retention_days'=>null,'execution'=>'never'),

            'personal_quran_progress' => array('class'=>'active_state','retention_days'=>null,'execution'=>'never'),
            'reading_progress' => array('class'=>'active_state','retention_days'=>null,'execution'=>'plan_only'),
            'reminder_schedules' => array('class'=>'active_state','retention_days'=>null,'execution'=>'never'),
            'notification_channels' => array('class'=>'active_state','retention_days'=>null,'execution'=>'never'),
            'push_subscriptions' => array('class'=>'active_state','retention_days'=>null,'execution'=>'never'),
            'rubika_links' => array('class'=>'active_state','retention_days'=>null,'execution'=>'never'),

            'sessions' => array('class'=>'ephemeral','retention_days'=>30,'execution'=>'bounded'),
            'otps' => array('class'=>'ephemeral','retention_days'=>7,'execution'=>'bounded'),
            'notifications' => array('class'=>'archivable','retention_days'=>180,'execution'=>'bounded_read_only'),
            'notification_deliveries' => array('class'=>'archivable','retention_days'=>90,'execution'=>'bounded_terminal'),

            'logs' => array('class'=>'archivable','retention_days'=>180,'execution'=>'rollup_guarded'),
            'claim_events' => array('class'=>'archivable','retention_days'=>365,'execution'=>'rollup_guarded'),
            'contributions' => array('class'=>'archivable','retention_days'=>365,'execution'=>'rollup_guarded'),
            'parts' => array('class'=>'archivable','retention_days'=>365,'execution'=>'plan_only'),
            'participants' => array('class'=>'archivable','retention_days'=>365,'execution'=>'plan_only'),
        );
    }

    public static function policy($dataset) {
        $all = self::policies();
        $dataset = sanitize_key((string)$dataset);
        return isset($all[$dataset]) ? $all[$dataset] : null;
    }

    public static function destructive_enabled() {
        $option = '1' === (string)get_option('hamnoor_retention_destructive_enabled', '0');
        $filter = (bool)apply_filters('hamnoor_retention_destructive_enabled', false);
        return $option && $filter;
    }

    public static function rollup_table() {
        return Hamnoor_DB::table('retention_rollups');
    }

    private static function clamp_limit($limit) {
        return max(1, min(self::MAX_BATCH, (int)$limit));
    }

    private static function cutoff($days) {
        return gmdate('Y-m-d H:i:s', time() - (max(0, (int)$days) * DAY_IN_SECONDS));
    }

    private static function ids_for_dataset($dataset, $limit) {
        global $wpdb;
        $policy = self::policy($dataset);
        if (!$policy || empty($policy['retention_days'])) { return array(); }
        $cutoff = self::cutoff((int)$policy['retention_days']);
        $limit = self::clamp_limit($limit);
        $table = Hamnoor_DB::table($dataset);
        if (!$table) { return array(); }

        if ('sessions' === $dataset) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE expires_at < %s OR (revoked_at IS NOT NULL AND revoked_at < %s) ORDER BY id ASC LIMIT %d",
                $cutoff,
                $cutoff,
                $limit
            ));
        }
        if ('otps' === $dataset) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s) ORDER BY id ASC LIMIT %d",
                $cutoff,
                $cutoff,
                $limit
            ));
        }
        if ('notifications' === $dataset) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE read_at IS NOT NULL AND read_at < %s ORDER BY id ASC LIMIT %d",
                $cutoff,
                $limit
            ));
        }
        if ('notification_deliveries' === $dataset) {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE status IN ('sent','failed','cancelled','dead') AND updated_at < %s ORDER BY id ASC LIMIT %d",
                $cutoff,
                $limit
            ));
        }
        return array();
    }

    private static function delete_ids($table, $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$table || !$ids) { return 0; }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = call_user_func_array(array($wpdb, 'prepare'), array_merge(array("DELETE FROM $table WHERE id IN ($placeholders)"), $ids));
        $deleted = $wpdb->query($sql);
        return false === $deleted ? new WP_Error('hamnoor_retention_delete_failed', $wpdb->last_error ?: 'Retention delete failed.') : (int)$deleted;
    }

    /** Dry-run by default. Explicit execute still requires the two-key guard. */
    public static function run_batch($dataset, $limit = 250, $execute = false) {
        $dataset = sanitize_key((string)$dataset);
        $allowed = array('sessions','otps','notifications','notification_deliveries');
        if (!in_array($dataset, $allowed, true)) {
            return new WP_Error('hamnoor_retention_dataset_plan_only', __('این مجموعه داده در نسخه فعلی فقط قابل ارزیابی/برنامه‌ریزی است و حذف اجرایی ندارد.', 'hamnoor'));
        }
        $limit = self::clamp_limit($limit);
        $ids = self::ids_for_dataset($dataset, $limit);
        $result = array(
            'dataset'=>$dataset,
            'policy_version'=>self::POLICY_VERSION,
            'limit'=>$limit,
            'candidates'=>count($ids),
            'executed'=>false,
            'deleted'=>0,
        );
        if (!$execute || !$ids) { return $result; }
        if (!self::destructive_enabled()) {
            return new WP_Error('hamnoor_retention_destructive_disabled', __('حذف نگهداری داده‌ها به‌صورت پیش‌فرض قفل است و برای اجرا به تأیید صریح نیاز دارد.', 'hamnoor'));
        }
        $deleted = self::delete_ids(Hamnoor_DB::table($dataset), $ids);
        if (is_wp_error($deleted)) { return $deleted; }
        $result['executed'] = true;
        $result['deleted'] = (int)$deleted;
        return $result;
    }

    /**
     * Safe automatic housekeeping used by upgrade/runtime maintenance.
     * It is intentionally restricted to expired sessions/OTPs, bounded, and
     * never touches logs, notifications, campaign ledgers, parts or participants.
     */
    public static function run_safe_housekeeping($limit = 250) {
        $limit = self::clamp_limit($limit);
        $out = array('sessions'=>0,'otps'=>0);
        foreach (array('sessions','otps') as $dataset) {
            $ids = self::ids_for_dataset($dataset, $limit);
            $deleted = self::delete_ids(Hamnoor_DB::table($dataset), $ids);
            if (is_wp_error($deleted)) { return $deleted; }
            $out[$dataset] = (int)$deleted;
        }
        return $out;
    }

    public static function plan($limit = 250) {
        $limit = self::clamp_limit($limit);
        $datasets = array('sessions','otps','notifications','notification_deliveries');
        $result = array(
            'policy_version'=>self::POLICY_VERSION,
            'generated_at_utc'=>gmdate('Y-m-d H:i:s'),
            'destructive_enabled'=>self::destructive_enabled(),
            'datasets'=>array(),
            'campaign_detail_policy'=>array(
                'claim_events'=>'rollup_guarded',
                'contributions'=>'rollup_guarded',
                'logs'=>'rollup_guarded',
                'parts'=>'plan_only',
                'participants'=>'plan_only',
            ),
        );
        foreach ($datasets as $dataset) {
            $ids = self::ids_for_dataset($dataset, $limit);
            $result['datasets'][$dataset] = array(
                'policy'=>self::policy($dataset),
                'sample_candidates'=>count($ids),
                'sample_limit'=>$limit,
            );
        }
        return $result;
    }

    private static function grouped_counts($table, $campaign_column, $campaign_id, $group_column, $sum_column = '') {
        global $wpdb;
        $sum = $sum_column ? ", SUM($sum_column) AS quantity" : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $group_column AS bucket, COUNT(*) AS records$sum FROM $table WHERE $campaign_column=%d GROUP BY $group_column ORDER BY $group_column ASC",
            (int)$campaign_id
        ));
        $out = array();
        foreach ((array)$rows as $row) {
            $out[(string)$row->bucket] = array('records'=>(int)$row->records);
            if ($sum_column) { $out[(string)$row->bucket]['quantity'] = (int)$row->quantity; }
        }
        return $out;
    }

    /** Idempotent campaign summary with ledger watermarks. */
    public static function rollup_campaign($campaign_id) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $campaign = Hamnoor_DB::get_khatm($campaign_id);
        if (!$campaign) { return new WP_Error('hamnoor_retention_campaign_missing', __('پویش برای خلاصه‌سازی پیدا نشد.', 'hamnoor')); }

        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $events = Hamnoor_DB::table('claim_events');
        $contributions = Hamnoor_DB::table('contributions');
        $logs = Hamnoor_DB::table('logs');

        $part_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total, SUM(status='available') available, SUM(status='claimed') claimed, SUM(status='done') done, MAX(id) max_id FROM $parts WHERE khatm_id=%d",
            $campaign_id
        ));
        $participant_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total, MAX(id) max_id FROM $participants WHERE khatm_id=%d",
            $campaign_id
        ));
        $event_max = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $events WHERE campaign_id=%d", $campaign_id));
        $contribution_max = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $contributions WHERE campaign_id=%d", $campaign_id));
        $log_max = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $logs WHERE khatm_id=%d", $campaign_id));

        $payload = array(
            'version'=>1,
            'campaign_id'=>$campaign_id,
            'status'=>(string)$campaign->status,
            'lifecycle_status'=>(string)$campaign->lifecycle_status,
            'content_type'=>(string)$campaign->content_type,
            'participation_strategy'=>(string)$campaign->participation_strategy,
            'parts'=>array(
                'total'=>$part_row ? (int)$part_row->total : 0,
                'available'=>$part_row ? (int)$part_row->available : 0,
                'claimed'=>$part_row ? (int)$part_row->claimed : 0,
                'done'=>$part_row ? (int)$part_row->done : 0,
            ),
            'participants'=>array('total'=>$participant_row ? (int)$participant_row->total : 0),
            'claim_events'=>self::grouped_counts($events, 'campaign_id', $campaign_id, 'event_type', 'quota_delta'),
            'contributions'=>self::grouped_counts($contributions, 'campaign_id', $campaign_id, 'kind', 'quantity'),
            'generated_at_utc'=>gmdate('Y-m-d H:i:s'),
        );

        $table = self::rollup_table();
        $now = gmdate('Y-m-d H:i:s');
        $sql = $wpdb->prepare(
            "INSERT INTO $table (scope_type,scope_id,rollup_key,payload_json,source_max_part_id,source_max_participant_id,source_max_claim_event_id,source_max_contribution_id,source_max_log_id,generated_at_utc,updated_at_utc) VALUES ('campaign',%d,%s,%s,%d,%d,%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),source_max_part_id=VALUES(source_max_part_id),source_max_participant_id=VALUES(source_max_participant_id),source_max_claim_event_id=VALUES(source_max_claim_event_id),source_max_contribution_id=VALUES(source_max_contribution_id),source_max_log_id=VALUES(source_max_log_id),generated_at_utc=VALUES(generated_at_utc),updated_at_utc=VALUES(updated_at_utc)",
            $campaign_id,
            self::ROLLUP_KEY,
            wp_json_encode($payload),
            $part_row ? (int)$part_row->max_id : 0,
            $participant_row ? (int)$participant_row->max_id : 0,
            $event_max,
            $contribution_max,
            $log_max,
            $now,
            $now
        );
        if (false === $wpdb->query($sql)) { return new WP_Error('hamnoor_retention_rollup_failed', $wpdb->last_error ?: 'Retention rollup failed.'); }
        return self::get_campaign_rollup($campaign_id);
    }

    public static function get_campaign_rollup($campaign_id) {
        global $wpdb;
        $table = self::rollup_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE scope_type='campaign' AND scope_id=%d AND rollup_key=%s LIMIT 1",
            (int)$campaign_id,
            self::ROLLUP_KEY
        ));
    }

    public static function campaign_rollup_ready($campaign_id) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $rollup = self::get_campaign_rollup($campaign_id);
        if (!$rollup) { return false; }
        $checks = array(
            array('table'=>'parts','column'=>'khatm_id','watermark'=>'source_max_part_id'),
            array('table'=>'participants','column'=>'khatm_id','watermark'=>'source_max_participant_id'),
            array('table'=>'claim_events','column'=>'campaign_id','watermark'=>'source_max_claim_event_id'),
            array('table'=>'contributions','column'=>'campaign_id','watermark'=>'source_max_contribution_id'),
            array('table'=>'logs','column'=>'khatm_id','watermark'=>'source_max_log_id'),
        );
        foreach ($checks as $check) {
            $table = Hamnoor_DB::table($check['table']);
            $max = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $table WHERE {$check['column']}=%d", $campaign_id));
            if ($max > (int)$rollup->{$check['watermark']}) { return false; }
        }
        return true;
    }

    private static function terminal_campaign_old_enough($campaign_id, $days) {
        $campaign = Hamnoor_DB::get_khatm((int)$campaign_id);
        if (!$campaign) { return false; }
        if (!in_array((string)$campaign->status, array('completed','cancelled','archived','failed','rejected'), true)) { return false; }
        $basis = !empty($campaign->completed_at) ? (string)$campaign->completed_at : (string)$campaign->updated_at;
        $ts = $basis ? strtotime($basis . ' UTC') : 0;
        return $ts > 0 && $ts < (time() - ((int)$days * DAY_IN_SECONDS));
    }

    /**
     * Ledger-only archival. Parts/participants remain plan-only until the UI and
     * reporting layer have a tombstone/summary projection and are never deleted here.
     */
    public static function run_campaign_ledger_batch($dataset, $campaign_id, $limit = 250, $execute = false) {
        global $wpdb;
        $dataset = sanitize_key((string)$dataset);
        if (!in_array($dataset, array('claim_events','contributions','logs'), true)) {
            return new WP_Error('hamnoor_retention_dataset_plan_only', __('این مجموعه داده هنوز حذف اجرایی ندارد.', 'hamnoor'));
        }
        $policy = self::policy($dataset);
        $days = (int)$policy['retention_days'];
        if (!self::terminal_campaign_old_enough((int)$campaign_id, $days)) {
            return new WP_Error('hamnoor_retention_campaign_not_eligible', __('پویش هنوز برای آرشیو جزئیات واجد شرایط نیست.', 'hamnoor'));
        }
        if (!self::campaign_rollup_ready((int)$campaign_id)) {
            return new WP_Error('hamnoor_retention_rollup_stale', __('پیش از حذف جزئیات، خلاصه آماری پویش باید به‌روز باشد.', 'hamnoor'));
        }

        $rollup = self::get_campaign_rollup((int)$campaign_id);
        $column = 'logs' === $dataset ? 'khatm_id' : 'campaign_id';
        $time_column = 'logs' === $dataset ? 'created_at' : 'occurred_at_utc';
        $watermark = 'logs' === $dataset ? 'source_max_log_id' : ('claim_events' === $dataset ? 'source_max_claim_event_id' : 'source_max_contribution_id');
        $cutoff = self::cutoff($days);
        $limit = self::clamp_limit($limit);
        $table = Hamnoor_DB::table($dataset);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE $column=%d AND $time_column < %s AND id <= %d ORDER BY id ASC LIMIT %d",
            (int)$campaign_id,
            $cutoff,
            (int)$rollup->$watermark,
            $limit
        ));
        $result = array('dataset'=>$dataset,'campaign_id'=>(int)$campaign_id,'candidates'=>count($ids),'limit'=>$limit,'executed'=>false,'deleted'=>0);
        if (!$execute || !$ids) { return $result; }
        if (!self::destructive_enabled()) { return new WP_Error('hamnoor_retention_destructive_disabled', __('حذف نگهداری داده‌ها قفل است.', 'hamnoor')); }
        $deleted = self::delete_ids($table, $ids);
        if (is_wp_error($deleted)) { return $deleted; }
        $result['executed'] = true;
        $result['deleted'] = (int)$deleted;
        return $result;
    }
}
