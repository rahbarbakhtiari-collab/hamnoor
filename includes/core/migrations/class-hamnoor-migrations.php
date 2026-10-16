<?php
if (!defined('ABSPATH')) { exit; }

/** Ordered, resumable and additive schema/data migrations. */
final class Hamnoor_Migrations {
    const FOUNDATION_410 = 'foundation_4_1_0';
    const FOUNDATION_420 = 'foundation_4_2_0_live_content';
    const FOUNDATION_430 = 'foundation_4_3_0_personal_quran_linear';
    const FOUNDATION_440 = 'foundation_4_4_0_reminder_schedules';
    const FOUNDATION_450 = 'foundation_4_5_0_retention_scale';
    const FOUNDATION_460 = 'foundation_4_6_0_durable_domain_events';

    public static function run_pending($from_version = '') {
        $from_version = $from_version ?: (string)get_option('hamnoor_db_version', '0');
        $lock = self::acquire_lock();
        if (is_wp_error($lock)) { return $lock; }
        try {
            $first = self::run(
                self::FOUNDATION_410,
                $from_version,
                '4.1.0',
                array(__CLASS__, 'foundation_410_transactional'),
                array(__CLASS__, 'foundation_410_post_commit')
            );
            if (is_wp_error($first) || false === $first) { return $first; }
            $second = self::run(
                self::FOUNDATION_420,
                version_compare($from_version, '4.1.0', '<') ? '4.1.0' : $from_version,
                '4.2.0',
                array(__CLASS__, 'foundation_420_transactional')
            );
            if (is_wp_error($second) || false === $second) { return $second; }
            $third = self::run(
                self::FOUNDATION_430,
                version_compare($from_version, '4.2.0', '<') ? '4.2.0' : $from_version,
                '4.3.0',
                array(__CLASS__, 'foundation_430_transactional')
            );
            if (is_wp_error($third) || false === $third) { return $third; }
            $fourth = self::run(
                self::FOUNDATION_440,
                version_compare($from_version, '4.3.0', '<') ? '4.3.0' : $from_version,
                '4.4.0',
                array(__CLASS__, 'foundation_440_transactional')
            );
            if (is_wp_error($fourth) || false === $fourth) { return $fourth; }
            $fifth = self::run(
                self::FOUNDATION_450,
                version_compare($from_version, '4.4.0', '<') ? '4.4.0' : $from_version,
                '4.5.0',
                array(__CLASS__, 'foundation_450_transactional')
            );
            if (is_wp_error($fifth) || false === $fifth) { return $fifth; }
            return self::run(
                self::FOUNDATION_460,
                version_compare($from_version, '4.5.0', '<') ? '4.5.0' : $from_version,
                '4.6.0',
                array(__CLASS__, 'foundation_460_transactional')
            );
        } finally {
            self::release_lock($lock);
        }
    }

    private static function acquire_lock() {
        $key = 'hamnoor_migration_lock';
        $token = wp_generate_uuid4();
        $payload = array('token'=>$token,'expires_at'=>time()+300);
        if (add_option($key, $payload, '', false)) { return $token; }
        $existing = get_option($key, array());
        if (is_array($existing) && !empty($existing['expires_at']) && (int)$existing['expires_at'] < time()) {
            delete_option($key);
            if (add_option($key, $payload, '', false)) { return $token; }
        }
        return new WP_Error('hamnoor_migration_busy', __('به‌روزرسانی پایگاه داده هم‌نور هم‌اکنون در درخواست دیگری در حال اجراست.', 'hamnoor'));
    }

    private static function release_lock($token) {
        $key = 'hamnoor_migration_lock';
        $existing = get_option($key, array());
        if (is_array($existing) && !empty($existing['token']) && hash_equals((string)$existing['token'], (string)$token)) { delete_option($key); }
    }

    /**
     * Database-table mutations run inside a SQL transaction. WordPress API work
     * (wp_insert_user/update_user_meta) deliberately runs after COMMIT because
     * WordPress user writes are not part of the same rollback boundary.
     * Both phases are idempotent, so a failed post-commit phase can be retried.
     */
    private static function run($key, $from, $to, $transactional_callback, $post_commit_callback = null) {
        global $wpdb;
        $table = Hamnoor_DB::table('migrations');
        $done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE migration_key=%s AND status='applied' LIMIT 1", $key));
        if ($done) { return true; }

        $snapshot = array(
            'migration'=>$key,
            'from'=>$from,
            'to'=>$to,
            'created_at_utc'=>gmdate('Y-m-d H:i:s'),
            'settings'=>get_option('hamnoor_settings', array()),
            'db_version_marker'=>(string)get_option('hamnoor_db_version', '0'),
        );
        update_option('hamnoor_migration_snapshot_' . sanitize_key($key), $snapshot, false);

        $wpdb->query('START TRANSACTION');
        try {
            $result = call_user_func($transactional_callback);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            if (false === $result) { throw new RuntimeException('Hamnoor transactional migration phase failed.'); }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            self::record_failure($key, $from, $to, 'transaction', $e);
            throw $e;
        }

        try {
            if ($post_commit_callback) {
                $result = call_user_func($post_commit_callback);
                if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
                if (false === $result) { throw new RuntimeException('Hamnoor post-commit migration phase failed.'); }
            }
            $ok = $wpdb->insert($table, array(
                'migration_key'=>$key,
                'from_version'=>(string)$from,
                'to_version'=>(string)$to,
                'status'=>'applied',
                'applied_at_utc'=>gmdate('Y-m-d H:i:s'),
            ), array('%s','%s','%s','%s','%s'));
            if (!$ok) {
                // A concurrent request may have completed the same migration.
                $done = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE migration_key=%s AND status='applied' LIMIT 1", $key));
                if (!$done) { throw new RuntimeException('Could not persist Hamnoor migration marker.'); }
            }
            return true;
        } catch (Throwable $e) {
            self::record_failure($key, $from, $to, 'post_commit', $e);
            throw $e;
        }
    }

    private static function record_failure($key, $from, $to, $phase, Throwable $e) {
        global $wpdb;
        $table = Hamnoor_DB::table('migrations');
        $wpdb->insert($table, array(
            'migration_key'=>$key . '_failed_' . sanitize_key($phase) . '_' . time(),
            'from_version'=>(string)$from,
            'to_version'=>(string)$to,
            'status'=>'failed',
            'error_message'=>Hamnoor_Validator::trim_len($phase . ': ' . $e->getMessage(), 1000),
            'applied_at_utc'=>gmdate('Y-m-d H:i:s'),
        ), array('%s','%s','%s','%s','%s','%s'));
    }

    /** Durable canonical domain facts for optional-addon recovery. */
    public static function foundation_460_transactional() {
        $contract = Hamnoor_Schema_Manifest::for_table('domain_events');
        $required = array('event_key','event_name','schema_version','payload_json','occurred_at_utc');
        foreach ($required as $column) {
            if (empty($contract['columns']) || !in_array($column, $contract['columns'], true)) {
                return new WP_Error('hamnoor_domain_event_contract_missing', 'Durable domain event contract is missing: ' . $column);
            }
        }
        return true;
    }

    /** Retention/scale foundation: schema-only, additive, no destructive data migration. */
    public static function foundation_450_transactional() {
        $contract = Hamnoor_Schema_Manifest::for_table('retention_rollups');
        if (empty($contract['columns']) || !in_array('payload_json', $contract['columns'], true)) {
            return new WP_Error('hamnoor_retention_contract_missing', 'Retention rollup schema contract is missing.');
        }
        return true;
    }

    public static function foundation_410_transactional() {
        global $wpdb;
        $khatms = Hamnoor_DB::table('khatms');
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');

        $rows = $wpdb->get_results("SELECT id,token,privacy,status,content_type,participation_strategy,access_mode,participation_policy,lifecycle_status,review_status,campaign_public_id FROM $khatms ORDER BY id ASC");
        foreach ((array)$rows as $row) {
            list($lifecycle, $review) = Hamnoor_Campaign_Domain::legacy_status_to_domain($row->status);
            $updates = array(
                'campaign_public_id'=>!empty($row->campaign_public_id) ? (string)$row->campaign_public_id : Hamnoor_DB::generate_public_id('CMP'),
                'content_type'=>in_array((string)$row->content_type, Hamnoor_Campaign_Domain::content_types(), true) ? (string)$row->content_type : Hamnoor_Campaign_Domain::CONTENT_QURAN,
                'participation_strategy'=>in_array((string)$row->participation_strategy, Hamnoor_Campaign_Domain::strategies(), true) ? (string)$row->participation_strategy : Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED,
                'access_mode'=>in_array((string)$row->access_mode, Hamnoor_Campaign_Domain::access_modes(), true) ? (string)$row->access_mode : Hamnoor_Campaign_Domain::legacy_privacy_to_access($row->privacy),
                'participation_policy'=>in_array((string)$row->participation_policy, Hamnoor_Campaign_Domain::participation_policies(), true) ? (string)$row->participation_policy : Hamnoor_Campaign_Domain::PARTICIPATION_ANYONE,
                'lifecycle_status'=>in_array((string)$row->lifecycle_status, Hamnoor_Campaign_Domain::lifecycle_statuses(), true) ? (string)$row->lifecycle_status : $lifecycle,
                'review_status'=>in_array((string)$row->review_status, Hamnoor_Campaign_Domain::review_statuses(), true) ? (string)$row->review_status : $review,
            );
            if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $updates['access_mode']) { $updates['participation_policy'] = Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY; }
            $ok = $wpdb->update($khatms, $updates, array('id'=>(int)$row->id), array('%s','%s','%s','%s','%s','%s','%s'), array('%d'));
            if (false === $ok) { return new WP_Error('hamnoor_migration_campaign_backfill_failed', $wpdb->last_error ?: 'Campaign backfill failed.'); }
        }

        foreach (array($parts=>'PRT', $participants=>'PAR') as $table=>$prefix) {
            $id_rows = $wpdb->get_results("SELECT id FROM $table WHERE public_id IS NULL OR public_id='' ORDER BY id ASC");
            foreach ((array)$id_rows as $id_row) {
                $ok = $wpdb->update($table, array('public_id'=>Hamnoor_DB::generate_public_id($prefix)), array('id'=>(int)$id_row->id), array('%s'), array('%d'));
                if (false === $ok) { return new WP_Error('hamnoor_migration_public_id_failed', $wpdb->last_error ?: 'Public ID backfill failed.'); }
            }
        }

        // The immutable-structure gate depends on this marker. Existing campaigns
        // with historical claims must not look unstarted after the migration.
        $backfill_started = $wpdb->query(
            "UPDATE $khatms k INNER JOIN (SELECT khatm_id, MIN(claimed_at) first_claimed_at FROM $parts WHERE claimed_at IS NOT NULL GROUP BY khatm_id) p ON p.khatm_id=k.id SET k.first_participation_at=COALESCE(k.first_participation_at,p.first_claimed_at) WHERE k.first_participation_at IS NULL"
        );
        if (false === $backfill_started) { return new WP_Error('hamnoor_migration_first_participation_failed', $wpdb->last_error ?: 'First participation backfill failed.'); }

        // Backfill durable successful claim events. Future mutations write the
        // structured ledger directly; the generic audit log remains independent.
        $events = Hamnoor_DB::table('claim_events');
        $logs = Hamnoor_DB::table('logs');
        $claim_rows = $wpdb->get_results("SELECT id,khatm_id,part_id,participant_id,user_id,action,created_at FROM $logs WHERE action IN ('part_claimed','part_claimed_done') ORDER BY id ASC LIMIT 50000");
        foreach ((array)$claim_rows as $log) {
            $key = hash('sha256', 'legacy-claim-log|' . (int)$log->id);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $events WHERE idempotency_key=%s LIMIT 1", $key));
            if ($exists) { continue; }
            $occurred = strtotime((string)$log->created_at);
            $ok = $wpdb->insert($events, array(
                'public_id'=>Hamnoor_DB::generate_public_id('CEV'),
                'campaign_id'=>(int)$log->khatm_id,
                'part_id'=>(int)$log->part_id ?: null,
                'participant_id'=>(int)$log->participant_id ?: null,
                'user_id'=>(int)$log->user_id ?: null,
                'event_type'=>'claim_created',
                'quota_delta'=>1,
                'idempotency_key'=>$key,
                'occurred_at_utc'=>gmdate('Y-m-d H:i:s', $occurred ?: time()),
            ), array('%s','%d','%d','%d','%d','%s','%d','%s','%s'));
            if (!$ok) { return new WP_Error('hamnoor_migration_claim_event_failed', $wpdb->last_error ?: 'Claim ledger backfill failed.'); }
        }
        return true;
    }

    public static function foundation_410_post_commit() {
        $result = Hamnoor_WP_User_Bridge::sync_all(5000);
        return false === $result ? new WP_Error('hamnoor_wp_user_bridge_failed', __('همگام‌سازی کاربران هم‌نور با وردپرس کامل نشد.', 'hamnoor')) : true;
    }


    /**
     * 4.2 finalizes the live editable content registry.
     *
     * There is intentionally no content-version compatibility model here: Hamnoor
     * has no production installations and the product decision is one live admin-
     * managed ContentItem per selectable Dua/Dhikr. Schema creation is handled by
     * dbDelta before this ordered marker runs.
     */
    public static function foundation_420_transactional() {
        $contract = Hamnoor_Schema_Manifest::for_table('content_items');
        return !empty($contract['columns']) && in_array('content_payload', $contract['columns'], true)
            ? true
            : new WP_Error('hamnoor_live_content_contract_missing', 'Live content registry contract is missing.');
    }


    /**
     * 4.3 corrects the experimental personal-Quran model.
     *
     * A personal Quran is one linear completion, never a collection of Parts.
     * Historical experimental Part rows are intentionally left untouched for
     * rollback/audit, but become inert because claim paths reject non-segmented
     * campaigns. We cannot infer a valid whole-Quran reading position from the
     * old arbitrary Part model, so the canonical linear progress starts at zero.
     */
    public static function foundation_430_transactional() {
        global $wpdb;
        $campaigns = Hamnoor_DB::table('khatms');
        $progress = Hamnoor_DB::table('personal_quran_progress');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,owner_user_id FROM $campaigns WHERE content_type=%s AND access_mode=%s ORDER BY id ASC",
            Hamnoor_Campaign_Domain::CONTENT_QURAN,
            Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY
        ));
        foreach ((array)$rows as $row) {
            $campaign_id = (int)$row->id;
            $owner_user_id = (int)$row->owner_user_id;
            if (!$owner_user_id) { return new WP_Error('hamnoor_personal_quran_migration_owner_missing', 'Personal Quran owner is missing.'); }
            $updated = $wpdb->update($campaigns, array(
                'participation_strategy'=>Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR,
                'participation_policy'=>Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY,
                'review_status'=>Hamnoor_Campaign_Domain::REVIEW_NOT_REQUIRED,
                'indexable'=>0,
                'target_quantity'=>Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX,
                'khatm_type'=>'personal',
                'khatm_count'=>1,
                'parts_per_khatm'=>0,
                'total_parts'=>0,
                'domain_version'=>2,
                'updated_at'=>current_time('mysql'),
            ), array('id'=>$campaign_id), array('%s','%s','%s','%d','%d','%s','%d','%d','%d','%d','%s'), array('%d'));
            if (false === $updated) { return new WP_Error('hamnoor_personal_quran_migration_campaign_failed', $wpdb->last_error ?: 'Personal Quran campaign migration failed.'); }

            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $progress WHERE campaign_id=%d LIMIT 1", $campaign_id));
            if (!$exists) {
                $now = gmdate('Y-m-d H:i:s');
                $inserted = $wpdb->insert($progress, array(
                    'campaign_id'=>$campaign_id,
                    'user_id'=>$owner_user_id,
                    'start_global_index'=>Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX,
                    'end_global_index'=>Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX,
                    'last_global_index'=>0,
                    'reading_status'=>Hamnoor_Personal_Quran_Progress_Service::STATUS_NOT_STARTED,
                    'created_at_utc'=>$now,
                    'updated_at_utc'=>$now,
                ), array('%d','%d','%d','%d','%d','%s','%s','%s'));
                if (!$inserted) { return new WP_Error('hamnoor_personal_quran_migration_progress_failed', $wpdb->last_error ?: 'Personal Quran progress migration failed.'); }
            }
        }
        return true;
    }


    /**
     * 4.4 introduces a generic reminder scheduling boundary.
     *
     * No reminder is auto-enabled during migration. Existing reading progress
     * remains untouched; reminder_schedules rows are created only by explicit
     * user opt-in. dbDelta creates the table before this marker is applied.
     */
    public static function foundation_440_transactional() {
        $contract = Hamnoor_Schema_Manifest::for_table('reminder_schedules');
        $required = array('public_id','user_id','scope_type','scope_id','enabled','local_time','timezone','days_mask','next_due_at_utc','last_occurrence_key');
        foreach ($required as $column) {
            if (empty($contract['columns']) || !in_array($column, $contract['columns'], true)) {
                return new WP_Error('hamnoor_reminder_schedule_contract_missing', 'Reminder schedule contract is missing: ' . $column);
            }
        }
        return true;
    }

}
