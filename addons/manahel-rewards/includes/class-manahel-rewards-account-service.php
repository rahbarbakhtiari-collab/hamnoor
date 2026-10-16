<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Account_Service {
    public static function get_or_create($wp_user_id) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('reward_identity_unresolved', __('کاربر وردپرس معتبر برای حساب امتیاز پیدا نشد.', 'manahel-rewards'));
        }

        $table = Manahel_Rewards_DB::table('accounts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE wp_user_id=%d LIMIT 1", $wp_user_id));
        if ($row) { return $row; }

        $now = Manahel_Rewards_DB::utc_now_mysql();
        $insert = Manahel_Rewards_DB::quiet_insert($table, array(
            'wp_user_id' => $wp_user_id,
            'status' => 'active',
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%d', '%s', '%d', '%s', '%s'));

        if (false === $insert['result']) {
            // A concurrent request may have won the unique wp_user_id insert.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE wp_user_id=%d LIMIT 1 FOR UPDATE", $wp_user_id));
            if ($row) { return $row; }
            return new WP_Error('reward_account_create_failed', __('ساخت حساب امتیاز ناموفق بود.', 'manahel-rewards'));
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", (int)$insert['insert_id']));
    }

    public static function lock($account_id) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('accounts');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE", (int)$account_id));
    }

    public static function apply_delta($account_id, $delta) {
        global $wpdb;
        $account_id = (int)$account_id;
        $delta = (int)$delta;
        if ($account_id <= 0 || 0 === $delta) { return false; }
        $table = Manahel_Rewards_DB::table('accounts');
        $now = Manahel_Rewards_DB::utc_now_mysql();
        // Database-level second wall: no caller, including a future one, may
        // mutate an account below zero. Financial services still perform their
        // own locked pre-checks for truthful user/admin error messages.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET balance = balance + %d, updated_at=%s WHERE id=%d AND (balance + %d) >= 0",
            $delta, $now, $account_id, $delta
        ));
        return 1 === (int)$result;
    }

    public static function rebuild_balance($account_id) {
        global $wpdb;
        $account_id = (int)$account_id;
        if ($account_id <= 0) {
            return new WP_Error('balance_rebuild_account_invalid', __('حساب امتیاز معتبر نیست.', 'manahel-rewards'));
        }
        if (!Manahel_Rewards_DB::storage_is_transactional()) {
            return new WP_Error('storage_not_transactional', __('جداول امتیازدهی باید InnoDB باشند.', 'manahel-rewards'));
        }
        if (!Manahel_Rewards_DB::begin()) {
            return new WP_Error('transaction_start_failed', __('شروع تراکنش بازسازی موجودی ناموفق بود.', 'manahel-rewards'));
        }

        $ledger = Manahel_Rewards_DB::table('ledger');
        try {
            // Use the SAME lock order as automatic awards/manual adjustments:
            // account row first, then Ledger. This prevents rebuild from
            // overwriting a concurrently committed balance with a stale SUM.
            $account = self::lock($account_id);
            if (!$account) { throw new RuntimeException('account_not_found'); }

            $sum = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(delta),0) FROM {$ledger} WHERE account_id=%d",
                $account_id
            ));
            if (null === $sum && !empty($wpdb->last_error)) { throw new RuntimeException('ledger_sum_failed'); }
            $sum = (int)$sum;
            if ($sum < 0) {
                Manahel_Rewards_DB::rollback();
                return new WP_Error('negative_ledger_balance_detected', __('جمع دفتر امتیاز منفی است؛ بازسازی خودکار برای حفاظت از حساب متوقف شد.', 'manahel-rewards'));
            }

            $ok = $wpdb->query($wpdb->prepare(
                "UPDATE " . Manahel_Rewards_DB::table('accounts') . " SET balance=%d, updated_at=%s WHERE id=%d",
                $sum, Manahel_Rewards_DB::utc_now_mysql(), $account_id
            ));
            if (false === $ok) { throw new RuntimeException('balance_update_failed'); }
            if (!Manahel_Rewards_DB::commit()) { throw new RuntimeException('commit_failed'); }
            return $sum;
        } catch (Throwable $e) {
            Manahel_Rewards_DB::rollback();
            return new WP_Error('balance_rebuild_failed', __('بازسازی موجودی ناموفق بود.', 'manahel-rewards'), array('cause'=>$e->getMessage()));
        }
    }
}
