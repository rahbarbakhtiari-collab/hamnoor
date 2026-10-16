<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Event_Processor {
    public static function receive($event, $wp_user_id = 0) {
        if (is_wp_error($event)) { return $event; }
        if (!is_array($event) || empty($event['dedupe_key'])) {
            return new WP_Error('invalid_normalized_event', __('رویداد نرمال‌شده معتبر نیست.', 'manahel-rewards'));
        }

        $event_id = self::insert_receipt($event);
        if (is_wp_error($event_id)) { return $event_id; }
        if (is_array($event_id) && !empty($event_id['duplicate'])) {
            return $event_id;
        }
        return self::process_receipt((int)$event_id, $event, (int)$wp_user_id);
    }

    public static function retry($event_id) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('events');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", (int)$event_id));
        if (!$row) { return new WP_Error('event_not_found', __('رویداد پیدا نشد.', 'manahel-rewards')); }
        if (!in_array((string)$row->status, array('failed', 'received'), true)) {
            return new WP_Error('event_not_retryable', __('این رویداد در وضعیت قابل Retry نیست.', 'manahel-rewards'));
        }
        $payload = json_decode((string)$row->payload_json, true);
        if (!is_array($payload)) {
            return new WP_Error('event_payload_unreadable', __('Payload رویداد برای Retry قابل استفاده نیست.', 'manahel-rewards'));
        }

        if ('hamnoor' === (string)$row->source) {
            $event = Manahel_Rewards_Normalizer::hamnoor_participation_completed($payload, array());
            if (is_wp_error($event)) { return $event; }
            $wp_user_id = Manahel_Rewards_Hamnoor_Adapter::resolve_wp_user_id((int)$event['source_subject_user_id']);
            return self::process_receipt((int)$row->id, $event, $wp_user_id);
        }

        if ('manahel_referral' === (string)$row->source && 'referral.qualified' === (string)$row->event_name) {
            $role = 'referral_inviter' === (string)$row->entity_type ? 'inviter' : ('referral_referred' === (string)$row->entity_type ? 'referred' : '');
            if (!$role) { return new WP_Error('event_payload_unreadable', __('نوع رویداد دعوت برای Retry شناخته نشد.', 'manahel-rewards')); }
            $event = Manahel_Rewards_Normalizer::referral_qualified($payload, $role);
            if (is_wp_error($event)) { return $event; }
            $wp_user_id = Manahel_Rewards_Referral_Adapter::wp_user_id_from_stored_payload($payload, (string)$row->entity_type);
            return self::process_receipt((int)$row->id, $event, $wp_user_id);
        }

        if ('manahel_referral' === (string)$row->source && 'referral.campaign_participant' === (string)$row->event_name && 'campaign_referrer' === (string)$row->entity_type) {
            $event = Manahel_Rewards_Normalizer::referral_campaign_participant($payload);
            if (is_wp_error($event)) { return $event; }
            $wp_user_id = Manahel_Rewards_Referral_Adapter::wp_user_id_from_stored_payload($payload, (string)$row->entity_type);
            return self::process_receipt((int)$row->id, $event, $wp_user_id);
        }

        return new WP_Error('event_payload_unreadable', __('Payload رویداد برای Retry قابل استفاده نیست.', 'manahel-rewards'));
    }

    private static function insert_receipt($event) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('events');
        $now = Manahel_Rewards_DB::utc_now_mysql();
        $insert = Manahel_Rewards_DB::quiet_insert($table, array(
            'source' => $event['source'],
            'event_name' => $event['event_name'],
            'schema_version' => (int)$event['schema_version'],
            'source_actor_user_id' => (int)$event['source_actor_user_id'],
            'source_subject_user_id' => (int)$event['source_subject_user_id'],
            'actor_ref' => $event['actor_ref'],
            'subject_ref' => $event['subject_ref'],
            'entity_type' => $event['entity_type'],
            'entity_id' => $event['entity_id'],
            'campaign_id' => $event['campaign_id'],
            'occurred_at' => $event['occurred_at'],
            'received_at' => $now,
            'dedupe_key' => $event['dedupe_key'],
            'payload_json' => $event['payload_json'],
            'decision_json' => null,
            'account_id' => null,
            'status' => 'received',
            'error_code' => '',
            'processed_at' => null,
        ));
        if (false !== $insert['result']) { return (int)$insert['insert_id']; }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM {$table} WHERE dedupe_key=%s LIMIT 1",
            $event['dedupe_key']
        ));
        if ($existing) {
            $decision_code = self::audit_duplicate_receipt((int)$existing->id, $event, (string)$existing->status);
            return array(
                'duplicate' => true,
                'event_id' => (int)$existing->id,
                'status' => (string)$existing->status,
                'decision_code' => $decision_code,
            );
        }
        return new WP_Error('event_receipt_insert_failed', __('ثبت رسید رویداد امتیازدهی ناموفق بود.', 'manahel-rewards'));
    }

    private static function process_receipt($event_id, $event, $wp_user_id) {
        $policy_reason = Manahel_Rewards_Normalizer::policy_exclusion_reason($event);
        if ($policy_reason) {
            $decision = self::decision('ignored', 0, $policy_reason, array());
            self::mark_event($event_id, 'ignored', $policy_reason, null, $decision);
            return array('status' => 'ignored', 'event_id' => $event_id, 'reason' => $policy_reason, 'points' => 0);
        }

        if ($wp_user_id <= 0) {
            $decision = self::decision('ignored', 0, 'account_resolution_failed', array());
            self::mark_event($event_id, 'ignored', 'identity_unresolved', null, $decision);
            return array('status' => 'ignored', 'event_id' => $event_id, 'reason' => 'identity_unresolved', 'points' => 0);
        }

        $rules = Manahel_Rewards_Rule_Engine::matching_rules($event);
        if (!$rules) {
            $decision = self::decision('ignored', 0, 'no_matching_rule', array());
            self::mark_event($event_id, 'ignored', 'no_matching_rule', null, $decision);
            return array('status' => 'ignored', 'event_id' => $event_id, 'reason' => 'no_matching_rule', 'points' => 0);
        }

        // Identity policy boundary. In v0.7 earning is participation-first:
        // a resolved local account may earn without OTP. This call remains as
        // the stable policy seam for future redemption/value-withdrawal rules.
        $identity_gate = Manahel_Rewards_Identity_Gate::evaluate($event, $wp_user_id);
        if (empty($identity_gate['allowed'])) {
            $code = sanitize_key((string)($identity_gate['code'] ?? 'identity_unresolved'));
            $decision = self::decision('ignored', 0, $code, array(array(
                'result' => 'identity_gate',
                'decision_code' => $code,
                'contract' => sanitize_text_field((string)($identity_gate['contract'] ?? '')),
            )));
            self::mark_event($event_id, 'ignored', $code, null, $decision);
            return array('status'=>'ignored', 'event_id'=>$event_id, 'reason'=>$code, 'points'=>0);
        }

        if (!Manahel_Rewards_DB::storage_is_transactional()) {
            self::mark_event($event_id, 'failed', 'storage_not_transactional', null, self::decision('failed', 0, 'processing_failed', array()));
            return new WP_Error('storage_not_transactional', __('جداول Manahel Rewards باید InnoDB باشند.', 'manahel-rewards'));
        }

        /*
         * Resolve/create the non-financial account shell BEFORE opening the
         * reward transaction. Under InnoDB REPEATABLE READ, the old order
         * performed a plain SELECT in get_or_create() after START TRANSACTION.
         * That could establish a stale consistent-read snapshot before a
         * competing request released the account row lock. The later limit
         * aggregate would then still see the pre-lock Ledger snapshot and two
         * requests could both consume the final daily slot.
         *
         * The financial transaction now starts only after an account id exists;
         * its first account read is SELECT ... FOR UPDATE. A waiter therefore
         * acquires the row after the winner commits, and the subsequent first
         * consistent read for limits is created from the post-lock committed
         * state. A zero-balance account shell may survive a later reward failure;
         * Ledger and Balance remain fully transactional.
         */
        $account = Manahel_Rewards_Account_Service::get_or_create($wp_user_id);
        if (is_wp_error($account)) {
            $cause = sanitize_key($account->get_error_code());
            self::mark_event($event_id, 'failed', $cause, null, self::decision('failed', 0, 'processing_failed', array()));
            return new WP_Error('reward_event_processing_failed', __('پردازش رویداد امتیازدهی ناموفق بود.', 'manahel-rewards'), array('cause' => $account->get_error_code()));
        }
        $account_id = (int)$account->id;

        if (!Manahel_Rewards_DB::begin()) {
            self::mark_event($event_id, 'failed', 'transaction_start_failed', null, self::decision('failed', 0, 'processing_failed', array()));
            return new WP_Error('transaction_start_failed', __('شروع تراکنش امتیازدهی ناموفق بود.', 'manahel-rewards'));
        }

        try {
            // This MUST be the first account read inside the transaction.
            $account = Manahel_Rewards_Account_Service::lock($account_id);
            if (!$account) { throw new RuntimeException('account_lock_failed'); }
            if ('active' !== (string)$account->status) { throw new RuntimeException('account_not_active'); }

            $delta_total = 0;
            $rule_decisions = array();
            foreach ($rules as $rule) {
                $limit = Manahel_Rewards_Limit_Evaluator::evaluate((int)$account->id, $event, $rule);
                if (empty($limit['allowed'])) {
                    if ('processing_failed' === (string)($limit['code'] ?? '')) {
                        throw new RuntimeException('limit_evaluation_failed');
                    }
                    $entry = array(
                        'rule_id' => (int)$rule->id,
                        'rule_code' => (string)$rule->code,
                        'result' => 'limited',
                        'decision_code' => (string)$limit['code'],
                        'points' => 0,
                    );
                    if (null !== ($limit['observed'] ?? null)) { $entry['observed'] = (int)$limit['observed']; }
                    if (null !== ($limit['maximum'] ?? null)) { $entry['maximum'] = (int)$limit['maximum']; }
                    if (!empty($limit['attempted_points'])) { $entry['attempted_points'] = (int)$limit['attempted_points']; }
                    $rule_decisions[] = $entry;
                    continue;
                }

                $delta = Manahel_Rewards_Ledger_Service::append_event_earning((int)$account->id, $event_id, $rule);
                if (is_wp_error($delta)) { throw new RuntimeException($delta->get_error_code()); }
                if ((int)$delta > 0) {
                    $delta_total += (int)$delta;
                    $rule_decisions[] = array(
                        'rule_id' => (int)$rule->id,
                        'rule_code' => (string)$rule->code,
                        'result' => 'awarded',
                        'decision_code' => 'awarded',
                        'points' => (int)$delta,
                    );
                } else {
                    // Technical idempotency wall: the same event/rule was already awarded.
                    $rule_decisions[] = array(
                        'rule_id' => (int)$rule->id,
                        'rule_code' => (string)$rule->code,
                        'result' => 'idempotent',
                        'decision_code' => 'idempotent_existing_award',
                        'points' => 0,
                    );
                }
            }

            if ($delta_total && !Manahel_Rewards_Account_Service::apply_delta((int)$account->id, $delta_total)) {
                throw new RuntimeException('balance_update_failed');
            }
            $balance_after = (int)$account->balance + (int)$delta_total;

            $top_result = $delta_total > 0 ? 'awarded' : self::aggregate_zero_result($rule_decisions);
            $top_code = $delta_total > 0 ? 'awarded' : self::first_decision_code($rule_decisions, 'processed');
            $decision = self::decision($top_result, $delta_total, $top_code, $rule_decisions);
            $event_error_code = $delta_total > 0 ? '' : ($top_code === 'processed' ? '' : $top_code);

            if (!self::mark_event($event_id, 'processed', $event_error_code, (int)$account->id, $decision)) {
                throw new RuntimeException('event_status_update_failed');
            }
            if (!Manahel_Rewards_DB::commit()) { throw new RuntimeException('commit_failed'); }

            $result = array(
                'status' => 'processed',
                'event_id' => $event_id,
                'account_id' => (int)$account->id,
                'points' => $delta_total,
                'balance' => $balance_after,
                'rules' => count($rules),
                'decision' => $decision,
            );

            // Side effects happen strictly AFTER the financial transaction has
            // committed. A notification/plugin failure can never roll back or
            // change the authoritative Ledger result.
            if ($delta_total > 0) {
                try {
                    do_action('manahel_rewards/earning_committed', array(
                        'event_id' => (int)$event_id,
                        'account_id' => (int)$account->id,
                        'wp_user_id' => (int)$wp_user_id,
                        'points' => (int)$delta_total,
                        'balance' => (int)$balance_after,
                        'event' => $event,
                        'decision' => $decision,
                    ));
                } catch (Throwable $side_effect_error) {
                    do_action('manahel_rewards/side_effect_failed', 'earning_committed', $side_effect_error, $result);
                }
            }

            return $result;
        } catch (Throwable $e) {
            Manahel_Rewards_DB::rollback();
            $cause = sanitize_key($e->getMessage());
            self::mark_event($event_id, 'failed', $cause, null, self::decision('failed', 0, 'processing_failed', array()));
            return new WP_Error('reward_event_processing_failed', __('پردازش رویداد امتیازدهی ناموفق بود.', 'manahel-rewards'), array('cause' => $e->getMessage()));
        }
    }

    /**
     * Keep receipt-level deduplication financially idempotent while preserving
     * an explainable audit trace. We never overwrite the original financial
     * decision; a replay annotation is merged into decision_json instead.
     *
     * When the original Event already produced an earning, an exact replay of
     * the same business entity is exposed as limit.unique_entity. If no earning
     * exists, it is recorded as duplicate_event rather than inventing a limit.
     */
    private static function audit_duplicate_receipt($event_id, $event, $status) {
        global $wpdb;
        $events = Manahel_Rewards_DB::table('events');
        $ledger = Manahel_Rewards_DB::table('ledger');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT decision_json FROM {$events} WHERE id=%d LIMIT 1",
            (int)$event_id
        ));
        if (!$row) { return 'duplicate_event'; }

        $earn_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger} WHERE event_id=%d AND entry_type='earn'",
            (int)$event_id
        ));
        $code = $earn_count > 0 ? 'limit.unique_entity' : 'duplicate_event';

        $decision = json_decode((string)$row->decision_json, true);
        if (!is_array($decision)) { $decision = array(); }
        $previous = isset($decision['receipt_replay']) && is_array($decision['receipt_replay']) ? $decision['receipt_replay'] : array();
        $decision['receipt_replay'] = array(
            'count' => max(0, (int)($previous['count'] ?? 0)) + 1,
            'last_received_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'decision_code' => $code,
            'status_at_replay' => sanitize_key((string)$status),
            'entity_type' => sanitize_key((string)($event['entity_type'] ?? '')),
            'entity_id' => sanitize_text_field((string)($event['entity_id'] ?? '')),
        );

        // If the winner is still processing, its final mark_event() may replace
        // this transient annotation. A later replay of a completed Event is the
        // auditable business case covered by the v1 contract.
        $wpdb->update(
            $events,
            array('decision_json' => wp_json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            array('id' => (int)$event_id),
            array('%s'),
            array('%d')
        );
        return $code;
    }

    private static function decision($result, $total_awarded, $decision_code, $rules) {
        return array(
            'version' => 1,
            'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'result' => sanitize_key((string)$result),
            'decision_code' => sanitize_text_field((string)$decision_code),
            'total_awarded' => (int)$total_awarded,
            'rules' => array_values((array)$rules),
        );
    }

    private static function aggregate_zero_result($rule_decisions) {
        foreach ((array)$rule_decisions as $item) {
            if ('limited' === ($item['result'] ?? '')) { return 'limited'; }
        }
        foreach ((array)$rule_decisions as $item) {
            if ('idempotent' === ($item['result'] ?? '')) { return 'idempotent'; }
        }
        return 'processed';
    }

    private static function first_decision_code($rule_decisions, $fallback) {
        foreach ((array)$rule_decisions as $item) {
            if (!empty($item['decision_code'])) { return (string)$item['decision_code']; }
        }
        return (string)$fallback;
    }

    private static function mark_event($event_id, $status, $error_code = '', $account_id = null, $decision = null) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('events');
        $data = array(
            'status' => sanitize_key($status),
            'error_code' => sanitize_text_field((string)$error_code),
            'processed_at' => Manahel_Rewards_DB::utc_now_mysql(),
        );
        if (null !== $account_id) { $data['account_id'] = (int)$account_id; }
        if (is_array($decision)) {
            $data['decision_json'] = wp_json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return false !== $wpdb->update($table, $data, array('id' => (int)$event_id));
    }
}
