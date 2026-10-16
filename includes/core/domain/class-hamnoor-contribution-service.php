<?php
if (!defined('ABSPATH')) { exit; }

/** Transactional ledger for repeatable-completion and numeric campaigns. */
final class Hamnoor_Contribution_Service {
    /**
     * Authoritative acceptance window for a NEW contribution/commitment.
     *
     * Campaign timestamps use WordPress site-local MySQL time, matching Quran
     * claim semantics. Call this only after SELECT ... FOR UPDATE so a delayed
     * scheduler cannot extend the real business acceptance window.
     */
    private static function accepts_new_participation($campaign) {
        if (!$campaign) {
            return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor'));
        }
        if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status) {
            return new WP_Error('hamnoor_campaign_not_active', __('این پویش در وضعیت فعال نیست.', 'hamnoor'));
        }
        $now = current_time('mysql');
        if (!empty($campaign->starts_at) && (string)$campaign->starts_at > $now) {
            return new WP_Error('hamnoor_campaign_not_started', __('زمان شروع مشارکت در این پویش هنوز فرا نرسیده است.', 'hamnoor'));
        }
        if (!empty($campaign->ends_at) && (string)$campaign->ends_at < $now) {
            return new WP_Error('hamnoor_campaign_ended', __('زمان پذیرش مشارکت در این پویش به پایان رسیده است.', 'hamnoor'));
        }
        return true;
    }

    /**
     * Completion window for an ALREADY accepted Dua commitment.
     *
     * This mirrors the existing Quran-share completion contract: existing
     * accepted work may finish while active/paused/expired, starts_at is still
     * a lower bound, and completion_grace_days controls the optional deadline.
     */
    private static function accepts_existing_completion($campaign) {
        if (!$campaign) {
            return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor'));
        }
        if (!in_array((string)$campaign->lifecycle_status, array(
            Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,
            Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED,
            Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,
        ), true)) {
            return new WP_Error('hamnoor_dua_campaign_closed', __('این پویش دیگر قابل تکمیل نیست.', 'hamnoor'));
        }
        $now = current_time('mysql');
        if (!empty($campaign->starts_at) && (string)$campaign->starts_at > $now) {
            return new WP_Error('hamnoor_campaign_not_started', __('زمان شروع این پویش هنوز فرا نرسیده است و ثبت تکمیل مجاز نیست.', 'hamnoor'));
        }
        if (!empty($campaign->ends_at) && null !== $campaign->completion_grace_days && '' !== (string)$campaign->completion_grace_days) {
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$campaign->ends_at, wp_timezone());
            if ($end) {
                $deadline = $end->modify('+' . (int)$campaign->completion_grace_days . ' days');
                if ($deadline->getTimestamp() < time()) {
                    return new WP_Error('hamnoor_completion_deadline_passed', __('مهلت تکمیل دعای انتخاب‌شده در این پویش به پایان رسیده است.', 'hamnoor'));
                }
            }
        }
        return true;
    }

    public static function progress($campaign_id, $target = 0) {
        global $wpdb;
        $table = Hamnoor_DB::table('contributions');
        $current = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM $table WHERE campaign_id=%d AND status='valid'", (int)$campaign_id));
        $target = max(0, (int)$target);
        return array(
            'current' => max(0, $current),
            'target' => $target,
            'percentage' => $target > 0 ? min(100, round((max(0, $current) / $target) * 100, 2)) : 0,
            'is_completed' => $target > 0 && $current >= $target,
        );
    }

    /**
     * Bounded participant-facing history read model.
     *
     * The current Hamnoor session is the only identity input. Callers cannot
     * supply an arbitrary user id, which keeps this boundary IDOR-safe by
     * construction. Internal ledger identifiers, idempotency keys and metadata
     * are deliberately not selected into the participant DTO.
     */
    public static function history_for_current_user($page = 1, $per_page = 20) {
        global $wpdb;
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $page = max(1, (int)$page);
        $per_page = max(1, min(100, (int)$per_page));
        if (!$user_id) {
            return array('items'=>array(), 'total'=>0, 'page'=>$page, 'per_page'=>$per_page, 'pages'=>1);
        }

        $contributions = Hamnoor_DB::table('contributions');
        $campaigns = Hamnoor_DB::table('khatms');
        $where = "c.user_id=%d AND ((c.status='valid' AND c.kind IN ('completion','numeric')) OR (c.status='pending' AND c.kind='dua_commitment'))";
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $contributions c WHERE $where", $user_id));
        $pages = max(1, (int)ceil($total / $per_page));
        if ($page > $pages) { $page = $pages; }
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT c.public_id, c.campaign_id, c.kind, c.quantity, c.status, c.occurred_at_utc,
                       k.campaign_public_id, k.token AS campaign_token, k.title AS campaign_title,
                       k.content_type, k.participation_strategy, k.status AS campaign_status,
                       k.privacy, k.access_mode
                FROM $contributions c
                INNER JOIN $campaigns k ON k.id=c.campaign_id
                WHERE $where
                ORDER BY c.occurred_at_utc DESC, c.id DESC
                LIMIT %d OFFSET %d";
        $items = $wpdb->get_results($wpdb->prepare($sql, $user_id, $per_page, $offset));

        return array(
            'items'=>is_array($items) ? $items : array(),
            'total'=>$total,
            'page'=>$page,
            'per_page'=>$per_page,
            'pages'=>$pages,
        );
    }

    /** Create at most one active, non-counting Dua commitment per user/campaign. */
    public static function select_dua_for_later($campaign_id, $user_id, $idempotency_key, $metadata = array()) {
        global $wpdb;
        $campaign_id = (int)$campaign_id; $user_id = (int)$user_id;
        $key = Hamnoor_Claim_Policy::normalize_idempotency_key($idempotency_key);
        if (!$user_id || !$key) { return new WP_Error('hamnoor_dua_selection_invalid', __('درخواست انتخاب دعا معتبر نیست.', 'hamnoor')); }
        $table = Hamnoor_DB::table('contributions'); $campaigns = Hamnoor_DB::table('khatms');
        $wpdb->query('START TRANSACTION');
        try {
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d FOR UPDATE", $campaign_id));
            if (!$campaign || Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE !== (string)$campaign->participation_strategy) { throw new RuntimeException('invalid_campaign'); }
            // Preserve only an exact idempotent retry after the window closes.
            // A fresh browser action with a new idempotency key must still pass
            // today's schedule boundary even when an older pending row exists.
            $retry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $key));
            if ($retry) { $retry->_created = false; $wpdb->query('COMMIT'); return $retry; }
            $availability = self::accepts_new_participation($campaign);
            if (is_wp_error($availability)) { $wpdb->query('ROLLBACK'); return $availability; }
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE campaign_id=%d AND user_id=%d AND kind='dua_commitment' AND status='pending' LIMIT 1", $campaign_id, $user_id));
            if ($existing) { $existing->_created = false; $wpdb->query('COMMIT'); return $existing; }
            $now = gmdate('Y-m-d H:i:s');
            $ok = $wpdb->insert($table, array('public_id'=>Hamnoor_DB::generate_public_id('CTR'),'campaign_id'=>$campaign_id,'user_id'=>$user_id,'participant_id'=>null,'kind'=>'dua_commitment','quantity'=>0,'status'=>'pending','idempotency_key'=>$key,'metadata_json'=>$metadata ? wp_json_encode($metadata) : null,'occurred_at_utc'=>$now,'created_at_utc'=>$now), array('%s','%d','%d','%d','%s','%d','%s','%s','%s','%s','%s'));
            if (!$ok) { throw new RuntimeException($wpdb->last_error ?: 'write_failed'); }
            $id = (int)$wpdb->insert_id;
            $wpdb->query($wpdb->prepare("UPDATE $campaigns SET first_participation_at=COALESCE(first_participation_at,%s),updated_at=%s WHERE id=%d", current_time('mysql'), current_time('mysql'), $campaign_id));
            $wpdb->query('COMMIT');
            $created = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
            $created->_created = true;
            return $created;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_dua_selection_failed', __('ثبت دعا برای بعد انجام نشد.', 'hamnoor'));
        }
    }

    /** Atomically turn the caller's pending Dua into one completed recitation. */
    public static function complete_pending_dua($public_id, $user_id) {
        global $wpdb;
        $public_id = sanitize_text_field((string)$public_id); $user_id = (int)$user_id;
        $table = Hamnoor_DB::table('contributions'); $campaigns = Hamnoor_DB::table('khatms');
        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE public_id=%s FOR UPDATE", $public_id));
            if (!$row || (int)$row->user_id !== $user_id || !in_array((string)$row->kind, array('dua_commitment','completion'), true)) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_dua_commitment_not_found', __('دعای انتخاب‌شده پیدا نشد.', 'hamnoor')); }
            if ('valid' === (string)$row->status && 'completion' === (string)$row->kind) { $row->_transitioned = false; $wpdb->query('COMMIT'); return $row; }
            if ('pending' !== (string)$row->status) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_dua_commitment_closed', __('این انتخاب دیگر قابل تکمیل نیست.', 'hamnoor')); }
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d FOR UPDATE", (int)$row->campaign_id));
            $availability = self::accepts_existing_completion($campaign);
            if (is_wp_error($availability)) { $wpdb->query('ROLLBACK'); return $availability; }
            $target = max(0, (int)$campaign->target_quantity);
            $current = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM $table WHERE campaign_id=%d AND status='valid'", (int)$row->campaign_id));
            if ($target > 0 && $current >= $target) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_campaign_target_reached', __('هدف پویش تکمیل شده است.', 'hamnoor')); }
            $now = gmdate('Y-m-d H:i:s');
            $updated = $wpdb->update(
                $table,
                array('kind'=>'completion','quantity'=>1,'status'=>'valid','occurred_at_utc'=>$now),
                array('id'=>(int)$row->id,'status'=>'pending'),
                array('%s','%d','%s','%s'),
                array('%d','%s')
            );
            if (1 !== (int)$updated) {
                throw new RuntimeException(false === $updated ? ($wpdb->last_error ?: 'dua_completion_update_failed') : 'dua_completion_transition_conflict');
            }
            $completed = $target > 0 && ($current + 1) >= $target;
            $wpdb->query('COMMIT');
            $done = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int)$row->id));
            $done->_transitioned = true;
            Hamnoor_Domain_Events::contribution_completed($done, $campaign, $user_id);
            if ($completed) { Hamnoor_DB::change_status((int)$row->campaign_id, 'completed', '', 'auto'); }
            return $done;
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_dua_completion_failed', __('ثبت تکمیل دعا انجام نشد.', 'hamnoor')); }
    }

    public static function campaign_user_ids($campaign_id) {
        global $wpdb; $table = Hamnoor_DB::table('contributions');
        return array_values(array_unique(array_map('intval', (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM $table WHERE campaign_id=%d AND user_id IS NOT NULL AND user_id>0 AND status IN ('pending','valid')", (int)$campaign_id)))));
    }

    public static function pending_dua_for_user($campaign_id, $user_id) {
        global $wpdb; $table = Hamnoor_DB::table('contributions'); $campaigns = Hamnoor_DB::table('khatms');
        if (!(int)$campaign_id || !(int)$user_id) { return null; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT c.* FROM $table c INNER JOIN $campaigns k ON k.id=c.campaign_id WHERE c.campaign_id=%d AND c.user_id=%d AND c.kind='dua_commitment' AND c.status='pending' AND k.lifecycle_status IN ('active','paused','expired') ORDER BY c.id DESC LIMIT 1", (int)$campaign_id, (int)$user_id));
        if (!$row) { return null; }
        $campaign = Hamnoor_DB::get_khatm((int)$campaign_id);
        return is_wp_error(self::accepts_existing_completion($campaign)) ? null : $row;
    }

    public static function record($campaign_id, $user_id, $quantity, $kind, $idempotency_key, $participant_id = 0, $metadata = array()) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $user_id = (int)$user_id;
        $participant_id = (int)$participant_id;
        $kind = sanitize_key((string)$kind);
        $idempotency_key = Hamnoor_Claim_Policy::normalize_idempotency_key($idempotency_key);
        if (!$idempotency_key) { return new WP_Error('hamnoor_idempotency_key_invalid', __('شناسه یکتای درخواست معتبر نیست.', 'hamnoor')); }

        $table = Hamnoor_DB::table('contributions');
        $campaigns = Hamnoor_DB::table('khatms');
        $wpdb->query('START TRANSACTION');
        try {
            // Lock the campaign before the first consistent read. Under MySQL's
            // default REPEATABLE READ isolation, reading idempotency first can
            // establish an old snapshot while this transaction later waits for
            // the campaign lock. The stale snapshot can then undercount SUM().
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d FOR UPDATE", $campaign_id));
            if (!$campaign) { throw new RuntimeException('campaign_not_found'); }

            // Idempotent retries for one campaign are now serialized behind the
            // same row lock, so every retry observes the committed winner.
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $idempotency_key));
            if ($existing) { $existing->_created = false; $wpdb->query('COMMIT'); return $existing; }
            $strategy = sanitize_key((string)$campaign->participation_strategy);
            if (!in_array($strategy, array(Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE, Hamnoor_Campaign_Domain::STRATEGY_NUMERIC), true)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_contribution_strategy_invalid', __('این پویش از مشارکت عددی/تکرارشونده استفاده نمی‌کند.', 'hamnoor'));
            }
            if (Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY === (string)$campaign->participation_policy && (!$user_id || $user_id !== (int)$campaign->owner_user_id)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_contribution_owner_only', __('این پویش فقط برای مالک آن قابل انجام است.', 'hamnoor'));
            }
            if (!in_array((string)$campaign->lifecycle_status, array(Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE), true)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_campaign_not_active', __('این پویش در وضعیت فعال نیست.', 'hamnoor'));
            }
            // Admin ledger adjustments retain their existing lifecycle behavior;
            // only participant-originating writes are constrained by schedule.
            if ('adjustment' !== $kind) {
                $availability = self::accepts_new_participation($campaign);
                if (is_wp_error($availability)) { $wpdb->query('ROLLBACK'); return $availability; }
            }

            if ('adjustment' === $kind) {
                if (!current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('hamnoor_adjustment_denied', __('اصلاح دفتر مشارکت فقط برای مدیر مجاز است.', 'hamnoor'));
                }
                $quantity = (int)$quantity;
                if (0 === $quantity) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_contribution_quantity_invalid', __('مقدار اصلاح نمی‌تواند صفر باشد.', 'hamnoor')); }
            } elseif (Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE === $strategy) {
                if ('completion' !== $kind) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_contribution_kind_invalid', __('در پویش دعا هر ثبت برابر یک قرائت کامل است.', 'hamnoor')); }
                $quantity = 1;
            } else {
                if ('numeric' !== $kind) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_contribution_kind_invalid', __('نوع مشارکت ذکر معتبر نیست.', 'hamnoor')); }
                $quantity = (int)$quantity;
                if ($quantity < 1) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_contribution_quantity_invalid', __('مقدار مشارکت باید بیشتر از صفر باشد.', 'hamnoor')); }
                if (!empty($campaign->content_item_id)) {
                    $item = Hamnoor_Content_Repository::get((int)$campaign->content_item_id);
                    if ($item && !empty($item->max_contribution)) { $quantity = min($quantity, (int)$item->max_contribution); }
                }
            }

            $target = max(0, (int)$campaign->target_quantity);
            if ('adjustment' !== $kind && $target > 0) {
                $current = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM $table WHERE campaign_id=%d AND status='valid'", $campaign_id));
                $remaining = max(0, $target - max(0, $current));
                if ($remaining < 1) { $wpdb->query('ROLLBACK'); return new WP_Error('hamnoor_campaign_target_reached', __('هدف پویش تکمیل شده است.', 'hamnoor')); }
                $quantity = min($quantity, $remaining);
            }

            $now = gmdate('Y-m-d H:i:s');
            $ok = $wpdb->insert($table, array(
                'public_id'=>Hamnoor_DB::generate_public_id('CTR'),
                'campaign_id'=>$campaign_id,
                'user_id'=>$user_id ?: null,
                'participant_id'=>$participant_id ?: null,
                'kind'=>$kind,
                'quantity'=>$quantity,
                'status'=>'valid',
                'idempotency_key'=>$idempotency_key,
                'metadata_json'=>$metadata ? wp_json_encode($metadata) : null,
                'occurred_at_utc'=>$now,
                'created_at_utc'=>$now,
            ), array('%s','%d','%d','%d','%s','%d','%s','%s','%s','%s','%s'));
            if (!$ok) {
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $idempotency_key));
                if ($existing) { $existing->_created = false; $wpdb->query('COMMIT'); return $existing; }
                throw new RuntimeException($wpdb->last_error ?: 'contribution_write_failed');
            }
            $insert_id = (int)$wpdb->insert_id;
            $wpdb->query($wpdb->prepare("UPDATE $campaigns SET first_participation_at=COALESCE(first_participation_at,%s),updated_at=%s WHERE id=%d", current_time('mysql'), current_time('mysql'), $campaign_id));

            $current_after = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM $table WHERE campaign_id=%d AND status='valid'", $campaign_id));
            $completed = $target > 0 && max(0, $current_after) >= $target;
            $wpdb->query('COMMIT');

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $insert_id));
            $row->_created = true;
            if ('adjustment' !== (string)$row->kind) {
                Hamnoor_Domain_Events::contribution_completed($row, $campaign, $user_id);
            }
            if ($completed) { Hamnoor_DB::change_status($campaign_id, 'completed', '', 'auto'); }
            return $row;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ('campaign_not_found' === $e->getMessage()) { return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
            error_log('[Hamnoor] Contribution write failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_contribution_write_failed', __('ثبت مشارکت انجام نشد.', 'hamnoor'));
        }
    }
}
