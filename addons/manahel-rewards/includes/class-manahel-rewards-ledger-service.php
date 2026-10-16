<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Ledger_Service {
    public static function append_event_earning($account_id, $event_id, $rule) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('ledger');
        $delta = (int)$rule->points;
        if ($delta <= 0) {
            return new WP_Error('invalid_rule_points', __('امتیاز Rule خودکار باید عدد صحیح مثبت باشد.', 'manahel-rewards'));
        }

        $insert = Manahel_Rewards_DB::quiet_insert($table, array(
            'account_id' => (int)$account_id,
            'event_id' => (int)$event_id,
            'rule_id' => (int)$rule->id,
            'entry_type' => 'earn',
            'delta' => $delta,
            'reason' => sanitize_text_field((string)$rule->title),
            'related_entry_id' => null,
            'idempotency_key' => null,
            'created_by' => 0,
            'created_at' => Manahel_Rewards_DB::utc_now_mysql(),
        ), array('%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s'));

        if (false === $insert['result']) {
            // The event_id + rule_id unique constraint is the second idempotency wall.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE event_id=%d AND rule_id=%d LIMIT 1 FOR UPDATE",
                (int)$event_id, (int)$rule->id
            ));
            if ($existing) { return 0; }
            return new WP_Error('ledger_insert_failed', __('ثبت تراکنش امتیاز ناموفق بود.', 'manahel-rewards'));
        }
        return $delta;
    }

    public static function manual_adjustment($account_id, $delta, $reason, $admin_user_id, $idempotency_key = '') {
        global $wpdb;
        $account_id = (int)$account_id;
        $delta = (int)$delta;
        $reason = trim((string)$reason);
        $admin_user_id = (int)$admin_user_id;
        if (!$account_id || 0 === $delta || '' === $reason || !$admin_user_id) {
            return new WP_Error('invalid_adjustment', __('اطلاعات اصلاح دستی امتیاز کامل نیست.', 'manahel-rewards'));
        }
        if (!Manahel_Rewards_DB::storage_is_transactional()) {
            return new WP_Error('storage_not_transactional', __('جداول امتیازدهی باید InnoDB باشند.', 'manahel-rewards'));
        }

        $key = $idempotency_key ? sanitize_key($idempotency_key) : 'manual-' . wp_generate_uuid4();
        if (!Manahel_Rewards_DB::begin()) {
            return new WP_Error('transaction_start_failed', __('شروع تراکنش دیتابیس ناموفق بود.', 'manahel-rewards'));
        }
        try {
            $account = Manahel_Rewards_Account_Service::lock($account_id);
            if (!$account) { throw new RuntimeException('account_not_found'); }
            if ($delta < 0 && ((int)$account->balance + $delta) < 0) {
                Manahel_Rewards_DB::rollback();
                return new WP_Error('balance_would_be_negative', __('این کاهش بیشتر از موجودی فعلی کاربر است و ثبت نشد.', 'manahel-rewards'), array(
                    'balance'=>(int)$account->balance,
                    'delta'=>$delta,
                ));
            }
            $table = Manahel_Rewards_DB::table('ledger');
            $insert = Manahel_Rewards_DB::quiet_insert($table, array(
                'account_id' => $account_id,
                'event_id' => null,
                'rule_id' => null,
                'entry_type' => 'adjustment',
                'delta' => $delta,
                'reason' => sanitize_text_field($reason),
                'related_entry_id' => null,
                'idempotency_key' => $key,
                'created_by' => $admin_user_id,
                'created_at' => Manahel_Rewards_DB::utc_now_mysql(),
            ), array('%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s'));
            if (false === $insert['result']) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE idempotency_key=%s LIMIT 1 FOR UPDATE",
                    $key
                ));
                if ($existing) {
                    Manahel_Rewards_DB::rollback();
                    return (int)$existing;
                }
                throw new RuntimeException('ledger_insert_failed');
            }
            if (!Manahel_Rewards_Account_Service::apply_delta($account_id, $delta)) { throw new RuntimeException('balance_update_failed'); }
            if (!Manahel_Rewards_DB::commit()) { throw new RuntimeException('commit_failed'); }
            return (int)$insert['insert_id'];
        } catch (Throwable $e) {
            Manahel_Rewards_DB::rollback();
            return new WP_Error('manual_adjustment_failed', __('اصلاح دستی امتیاز ثبت نشد.', 'manahel-rewards'), array('cause' => $e->getMessage()));
        }
    }
}
