<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Transactional points-redemption boundary.
 *
 * Financial invariant:
 * - Ledger is the source of truth.
 * - A redemption debit and account balance update commit atomically.
 * - Inventory reservation is in the same transaction.
 * - Replay with the same idempotency key never double-spends.
 * - Refund is a new positive Ledger entry; historical debit is immutable.
 */
final class Manahel_Rewards_Redemption_Service {
    const STATUS_PENDING = 'pending';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_REFUNDED = 'refunded';

    public static function active_catalog() {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('catalog');
        return $wpdb->get_results("SELECT * FROM {$table} WHERE status='active' ORDER BY sort_order ASC,id ASC");
    }

    public static function catalog_for_user($wp_user_id) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        $items = self::active_catalog();
        if ($wp_user_id <= 0) { return $items; }
        $redemptions = Manahel_Rewards_DB::table('redemptions');
        foreach ((array)$items as $item) {
            $item->user_redeemed_count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$redemptions} WHERE wp_user_id=%d AND reward_id=%d AND status IN ('pending','fulfilled')",
                $wp_user_id, (int)$item->id
            ));
            if ('code' === (string)$item->fulfillment_mode && class_exists('Manahel_Rewards_Code_Inventory')) {
                $stats = Manahel_Rewards_Code_Inventory::stats((int)$item->id);
                $item->stock_total = (int)$stats['total'];
                $item->stock_used = (int)$stats['assigned'];
                $item->code_available = (int)$stats['available'];
            }
        }
        return $items;
    }

    public static function user_redemptions($wp_user_id, $limit = 20) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0) { return array(); }
        $limit = max(1, min(50, (int)$limit));
        $r = Manahel_Rewards_DB::table('redemptions');
        $c = Manahel_Rewards_DB::table('catalog');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT x.*,c.title reward_title,c.fulfillment_mode,c.requires_verification
             FROM {$r} x INNER JOIN {$c} c ON c.id=x.reward_id
             WHERE x.wp_user_id=%d ORDER BY x.id DESC LIMIT %d",
            $wp_user_id, $limit
        ));
    }

    public static function get_catalog_item($reward_id, $for_update = false) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('catalog');
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", (int)$reward_id);
        if ($for_update) { $sql .= ' FOR UPDATE'; }
        return $wpdb->get_row($sql);
    }

    public static function create_catalog_item($data) {
        global $wpdb;
        $data = is_array($data) ? $data : array();
        $code = sanitize_key((string)($data['code'] ?? ''));
        $title = trim(sanitize_text_field((string)($data['title'] ?? '')));
        $points = (int)($data['points_cost'] ?? 0);
        if (!$code || !$title || $points <= 0) {
            return new WP_Error('reward_catalog_invalid', __('عنوان، کد و هزینه امتیازی معتبر لازم است.', 'manahel-rewards'));
        }
        $mode = sanitize_key((string)($data['fulfillment_mode'] ?? 'manual'));
        if (!in_array($mode, array('manual','code','physical'), true)) { $mode = 'manual'; }
        $status = !empty($data['active']) ? 'active' : 'inactive';
        $now = Manahel_Rewards_DB::utc_now_mysql();
        $table = Manahel_Rewards_DB::table('catalog');
        $insert = Manahel_Rewards_DB::quiet_insert($table, array(
            'code'=>$code,
            'title'=>$title,
            'description'=>sanitize_textarea_field((string)($data['description'] ?? '')),
            'points_cost'=>$points,
            'status'=>$status,
            'fulfillment_mode'=>$mode,
            'requires_verification'=>!empty($data['requires_verification']) ? 1 : 0,
            'stock_total'=>'code' === $mode ? 0 : max(0,(int)($data['stock_total'] ?? 0)),
            'stock_used'=>0,
            'max_per_user'=>max(0,(int)($data['max_per_user'] ?? 0)),
            'note_label'=>sanitize_text_field((string)($data['note_label'] ?? '')),
            'sort_order'=>(int)($data['sort_order'] ?? 10),
            'created_at'=>$now,
            'updated_at'=>$now,
        ));
        if (false === $insert['result']) {
            return new WP_Error('reward_catalog_create_failed', __('ساخت جایزه انجام نشد؛ کد جایزه باید یکتا باشد.', 'manahel-rewards'));
        }
        return (int)$insert['insert_id'];
    }

    public static function update_catalog_item($reward_id, $data) {
        global $wpdb;
        $reward_id = (int)$reward_id;
        $item = self::get_catalog_item($reward_id, false);
        if (!$item) { return new WP_Error('reward_not_found', __('جایزه پیدا نشد.', 'manahel-rewards')); }
        $data = is_array($data) ? $data : array();
        $title = trim(sanitize_text_field((string)($data['title'] ?? $item->title)));
        $points = (int)($data['points_cost'] ?? $item->points_cost);
        if (!$title || $points <= 0) { return new WP_Error('reward_catalog_invalid', __('عنوان و هزینه امتیازی معتبر لازم است.', 'manahel-rewards')); }
        $mode = sanitize_key((string)($data['fulfillment_mode'] ?? $item->fulfillment_mode));
        if (!in_array($mode, array('manual','code','physical'), true)) { $mode = 'manual'; }
        $stock_total = 'code' === $mode ? (int)$item->stock_total : max(0,(int)($data['stock_total'] ?? $item->stock_total));
        if ($stock_total > 0 && $stock_total < (int)$item->stock_used) {
            return new WP_Error('reward_stock_below_used', __('موجودی کل نمی‌تواند از تعداد دریافت‌شده کمتر باشد.', 'manahel-rewards'));
        }
        $ok = $wpdb->update(Manahel_Rewards_DB::table('catalog'), array(
            'title'=>$title,
            'description'=>sanitize_textarea_field((string)($data['description'] ?? $item->description)),
            'points_cost'=>$points,
            'status'=>!empty($data['active']) ? 'active' : 'inactive',
            'fulfillment_mode'=>$mode,
            'requires_verification'=>!empty($data['requires_verification']) ? 1 : 0,
            'stock_total'=>$stock_total,
            'max_per_user'=>max(0,(int)($data['max_per_user'] ?? $item->max_per_user)),
            'note_label'=>sanitize_text_field((string)($data['note_label'] ?? $item->note_label)),
            'sort_order'=>(int)($data['sort_order'] ?? $item->sort_order),
            'updated_at'=>Manahel_Rewards_DB::utc_now_mysql(),
        ), array('id'=>$reward_id));
        if (false === $ok) { return new WP_Error('reward_catalog_update_failed', __('ذخیره جایزه انجام نشد.', 'manahel-rewards')); }
        if ('code' === $mode && class_exists('Manahel_Rewards_Code_Inventory')) { Manahel_Rewards_Code_Inventory::sync_catalog_stock($reward_id); }
        return true;
    }

    public static function redeem($wp_user_id, $reward_id, $user_note = '', $idempotency_key = '') {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        $reward_id = (int)$reward_id;
        if ($wp_user_id <= 0 || !get_user_by('id',$wp_user_id)) {
            return new WP_Error('redemption_user_invalid', __('کاربر معتبر برای دریافت جایزه پیدا نشد.', 'manahel-rewards'));
        }
        if ($reward_id <= 0) { return new WP_Error('reward_not_found', __('جایزه معتبر نیست.', 'manahel-rewards')); }
        if (!Manahel_Rewards_DB::storage_is_transactional()) {
            return new WP_Error('storage_not_transactional', __('جداول امتیازدهی باید InnoDB باشند.', 'manahel-rewards'));
        }
        $key = sanitize_key((string)$idempotency_key);
        if (!$key) { $key = 'redeem-' . wp_generate_uuid4(); }
        $redemptions = Manahel_Rewards_DB::table('redemptions');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE idempotency_key=%s LIMIT 1",$key));
        if ($existing) {
            if ((int)$existing->wp_user_id === $wp_user_id && (int)$existing->reward_id === $reward_id) { return $existing; }
            return new WP_Error('redemption_key_conflict', __('شناسه درخواست دریافت جایزه معتبر نیست.', 'manahel-rewards'));
        }

        // Ensure account exists before transaction. Account creation itself is idempotent.
        $account = Manahel_Rewards_Account_Service::get_or_create($wp_user_id);
        if (is_wp_error($account)) { return $account; }
        if (!Manahel_Rewards_DB::begin()) { return new WP_Error('transaction_start_failed', __('شروع تراکنش دریافت جایزه ناموفق بود.', 'manahel-rewards')); }

        try {
            // Lock order: account -> catalog -> redemptions/ledger.
            $account = Manahel_Rewards_Account_Service::lock((int)$account->id);
            if (!$account || 'active' !== (string)$account->status) { throw new RuntimeException('account_inactive'); }
            $item = self::get_catalog_item($reward_id, true);
            if (!$item || 'active' !== (string)$item->status) { throw new RuntimeException('reward_inactive'); }
            $cost = (int)$item->points_cost;
            if ($cost <= 0) { throw new RuntimeException('reward_cost_invalid'); }
            if ((int)$account->balance < $cost) { throw new RuntimeException('insufficient_balance'); }
            if ((int)$item->stock_total > 0 && (int)$item->stock_used >= (int)$item->stock_total) { throw new RuntimeException('out_of_stock'); }

            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$redemptions} WHERE wp_user_id=%d AND reward_id=%d AND status IN ('pending','fulfilled')",
                $wp_user_id, $reward_id
            ));
            if ((int)$item->max_per_user > 0 && $count >= (int)$item->max_per_user) { throw new RuntimeException('per_user_limit'); }

            $requires = Manahel_Rewards_Identity_Gate::redemption_requires_verification($wp_user_id, array(
                'requires_verification'=>!empty($item->requires_verification),
                'reward_id'=>$reward_id,
                'points_cost'=>$cost,
            ));
            if ($requires && !self::is_user_verified($wp_user_id)) { throw new RuntimeException('verification_required'); }

            $note = sanitize_textarea_field((string)$user_note);
            if (trim((string)$item->note_label) !== '' && trim($note) === '') { throw new RuntimeException('note_required'); }
            if (function_exists('mb_substr')) { $note = mb_substr($note,0,1000,'UTF-8'); } else { $note = substr($note,0,1000); }

            $auto_code = 'code' === (string)$item->fulfillment_mode;
            $locked_code = null;
            if ($auto_code) {
                if (!class_exists('Manahel_Rewards_Code_Inventory')) { throw new RuntimeException('code_inventory_unavailable'); }
                $locked_code = Manahel_Rewards_Code_Inventory::lock_next_available($reward_id);
                if (!$locked_code) { throw new RuntimeException('out_of_stock'); }
            }

            $now = Manahel_Rewards_DB::utc_now_mysql();
            $uuid = wp_generate_uuid4();
            $initial_status = $auto_code ? self::STATUS_FULFILLED : self::STATUS_PENDING;
            $initial_value = $auto_code ? (string)$locked_code->code_value : '';
            $insert = Manahel_Rewards_DB::quiet_insert($redemptions, array(
                'public_id'=>$uuid,
                'account_id'=>(int)$account->id,
                'wp_user_id'=>$wp_user_id,
                'reward_id'=>$reward_id,
                'points_cost'=>$cost,
                'status'=>$initial_status,
                'user_note'=>$note,
                'fulfillment_value'=>$initial_value,
                'admin_note'=>'',
                'debit_ledger_id'=>null,
                'refund_ledger_id'=>null,
                'idempotency_key'=>$key,
                'created_at'=>$now,
                'updated_at'=>$now,
                'fulfilled_at'=>$auto_code ? $now : null,
                'refunded_at'=>null,
            ));
            if (false === $insert['result']) {
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE idempotency_key=%s LIMIT 1 FOR UPDATE",$key));
                if ($existing && (int)$existing->wp_user_id===$wp_user_id && (int)$existing->reward_id===$reward_id) {
                    Manahel_Rewards_DB::rollback();
                    return $existing;
                }
                throw new RuntimeException('redemption_insert_failed');
            }
            $redemption_id = (int)$insert['insert_id'];
            if ($auto_code && !Manahel_Rewards_Code_Inventory::assign_locked((int)$locked_code->id, $redemption_id)) {
                throw new RuntimeException('code_assign_failed');
            }

            $ledger = Manahel_Rewards_DB::table('ledger');
            $ledger_insert = Manahel_Rewards_DB::quiet_insert($ledger, array(
                'account_id'=>(int)$account->id,
                'event_id'=>null,
                'rule_id'=>null,
                'entry_type'=>'redeem',
                'delta'=>-$cost,
                'reason'=>sprintf(__('دریافت جایزه: %s', 'manahel-rewards'), sanitize_text_field((string)$item->title)),
                'related_entry_id'=>null,
                'idempotency_key'=>'redemption-debit-'.$uuid,
                'created_by'=>0,
                'created_at'=>$now,
            ));
            if (false === $ledger_insert['result']) { throw new RuntimeException('ledger_debit_failed'); }
            $ledger_id = (int)$ledger_insert['insert_id'];
            if (!Manahel_Rewards_Account_Service::apply_delta((int)$account->id,-$cost)) { throw new RuntimeException('balance_update_failed'); }
            $stock_ok = $wpdb->query($wpdb->prepare(
                "UPDATE ".Manahel_Rewards_DB::table('catalog')." SET stock_used=stock_used+1,updated_at=%s WHERE id=%d",
                $now,$reward_id
            ));
            if (false === $stock_ok) { throw new RuntimeException('stock_update_failed'); }
            $redemption_ok = $wpdb->update($redemptions,array('debit_ledger_id'=>$ledger_id,'updated_at'=>$now),array('id'=>$redemption_id));
            if (false === $redemption_ok) { throw new RuntimeException('redemption_link_failed'); }
            if (!Manahel_Rewards_DB::commit()) { throw new RuntimeException('commit_failed'); }

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE id=%d LIMIT 1",$redemption_id));
            do_action('manahel_rewards_redemption_created',$row,$item,$wp_user_id);
            if ($auto_code && $row && self::STATUS_FULFILLED === (string)$row->status) {
                do_action('manahel_rewards_redemption_fulfilled',$row,0);
            }
            return $row;
        } catch (Throwable $e) {
            Manahel_Rewards_DB::rollback();
            $map = array(
                'account_inactive'=>array('redemption_account_inactive',__('حساب امتیاز برای دریافت جایزه فعال نیست.', 'manahel-rewards')),
                'reward_inactive'=>array('redemption_reward_unavailable',__('این جایزه در حال حاضر قابل دریافت نیست.', 'manahel-rewards')),
                'reward_cost_invalid'=>array('redemption_reward_invalid',__('هزینه امتیازی جایزه معتبر نیست.', 'manahel-rewards')),
                'insufficient_balance'=>array('redemption_insufficient_balance',__('امتیاز شما برای دریافت این جایزه کافی نیست.', 'manahel-rewards')),
                'out_of_stock'=>array('redemption_out_of_stock',__('موجودی این جایزه تمام شده است.', 'manahel-rewards')),
                'per_user_limit'=>array('redemption_per_user_limit',__('سقف دریافت این جایزه برای شما تکمیل شده است.', 'manahel-rewards')),
                'verification_required'=>array('redemption_verification_required',__('برای دریافت این جایزه خاص، تأیید هویت لازم است.', 'manahel-rewards')),
                'note_required'=>array('redemption_note_required',__('اطلاعات لازم برای دریافت این جایزه را وارد کنید.', 'manahel-rewards')),
                'code_inventory_unavailable'=>array('redemption_code_inventory_unavailable',__('مخزن کد این جایزه آماده نیست.', 'manahel-rewards')),
                'code_assign_failed'=>array('redemption_code_assign_failed',__('تخصیص کد جایزه انجام نشد؛ امتیازی کسر نشد.', 'manahel-rewards')),
            );
            $cause = $e->getMessage();
            if (isset($map[$cause])) { return new WP_Error($map[$cause][0],$map[$cause][1]); }
            return new WP_Error('redemption_failed',__('ثبت درخواست جایزه انجام نشد.', 'manahel-rewards'),array('cause'=>$cause));
        }
    }

    public static function fulfill($redemption_id, $fulfillment_value, $admin_note, $admin_user_id) {
        global $wpdb;
        $redemption_id=(int)$redemption_id; $admin_user_id=(int)$admin_user_id;
        if ($redemption_id<=0 || $admin_user_id<=0) { return new WP_Error('redemption_invalid',__('درخواست معتبر نیست.', 'manahel-rewards')); }
        $table=Manahel_Rewards_DB::table('redemptions');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$redemption_id));
        if (!$row) { return new WP_Error('redemption_not_found',__('درخواست پیدا نشد.', 'manahel-rewards')); }
        if (self::STATUS_FULFILLED===(string)$row->status) { return $row; }
        if (self::STATUS_PENDING!==(string)$row->status) { return new WP_Error('redemption_not_pending',__('فقط درخواست در انتظار قابل تحویل است.', 'manahel-rewards')); }
        $value=sanitize_textarea_field((string)$fulfillment_value);
        $note=sanitize_textarea_field((string)$admin_note);
        $now=Manahel_Rewards_DB::utc_now_mysql();
        $ok=$wpdb->update($table,array('status'=>self::STATUS_FULFILLED,'fulfillment_value'=>$value,'admin_note'=>$note,'updated_at'=>$now,'fulfilled_at'=>$now),array('id'=>$redemption_id,'status'=>self::STATUS_PENDING));
        if (false===$ok || 0===$ok) { return new WP_Error('redemption_fulfill_failed',__('ثبت تحویل جایزه انجام نشد.', 'manahel-rewards')); }
        $updated=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$redemption_id));
        do_action('manahel_rewards_redemption_fulfilled',$updated,$admin_user_id);
        return $updated;
    }

    public static function refund($redemption_id, $reason, $admin_user_id) {
        global $wpdb;
        $redemption_id=(int)$redemption_id; $admin_user_id=(int)$admin_user_id;
        if ($redemption_id<=0 || $admin_user_id<=0) { return new WP_Error('redemption_invalid',__('درخواست معتبر نیست.', 'manahel-rewards')); }
        if (!Manahel_Rewards_DB::storage_is_transactional()) { return new WP_Error('storage_not_transactional',__('جداول امتیازدهی باید InnoDB باشند.', 'manahel-rewards')); }
        if (!Manahel_Rewards_DB::begin()) { return new WP_Error('transaction_start_failed',__('شروع تراکنش بازپرداخت ناموفق بود.', 'manahel-rewards')); }
        $redemptions=Manahel_Rewards_DB::table('redemptions');
        try {
            $pre=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE id=%d LIMIT 1",$redemption_id));
            if (!$pre) { throw new RuntimeException('not_found'); }
            $account=Manahel_Rewards_Account_Service::lock((int)$pre->account_id);
            if (!$account) { throw new RuntimeException('account_not_found'); }
            $item=self::get_catalog_item((int)$pre->reward_id,true);
            if (!$item) { throw new RuntimeException('reward_not_found'); }
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE id=%d LIMIT 1 FOR UPDATE",$redemption_id));
            if (self::STATUS_REFUNDED===(string)$row->status) { Manahel_Rewards_DB::rollback(); return $row; }
            if (!in_array((string)$row->status,array(self::STATUS_PENDING,self::STATUS_FULFILLED),true)) { throw new RuntimeException('not_refundable'); }
            $cost=(int)$row->points_cost;
            $ledger=Manahel_Rewards_DB::table('ledger');
            $key='redemption-refund-'.sanitize_key((string)$row->public_id);
            $insert=Manahel_Rewards_DB::quiet_insert($ledger,array(
                'account_id'=>(int)$row->account_id,
                'event_id'=>null,
                'rule_id'=>null,
                'entry_type'=>'refund',
                'delta'=>$cost,
                'reason'=>sanitize_text_field($reason?:__('بازگشت امتیاز جایزه لغوشده','manahel-rewards')),
                'related_entry_id'=>$row->debit_ledger_id?(int)$row->debit_ledger_id:null,
                'idempotency_key'=>$key,
                'created_by'=>$admin_user_id,
                'created_at'=>Manahel_Rewards_DB::utc_now_mysql(),
            ));
            if (false===$insert['result']) {
                $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ledger} WHERE idempotency_key=%s LIMIT 1 FOR UPDATE",$key));
                if (!$existing) { throw new RuntimeException('refund_ledger_failed'); }
                $refund_ledger_id=(int)$existing;
            } else { $refund_ledger_id=(int)$insert['insert_id']; }
            if (!Manahel_Rewards_Account_Service::apply_delta((int)$row->account_id,$cost)) { throw new RuntimeException('balance_update_failed'); }
            $now=Manahel_Rewards_DB::utc_now_mysql();
            $consume_code_permanently = ('code' === (string)$item->fulfillment_mode && self::STATUS_FULFILLED === (string)$row->status && trim((string)$row->fulfillment_value) !== '');
            if (!$consume_code_permanently) {
                $stock=$wpdb->query($wpdb->prepare("UPDATE ".Manahel_Rewards_DB::table('catalog')." SET stock_used=GREATEST(stock_used-1,0),updated_at=%s WHERE id=%d",$now,(int)$row->reward_id));
                if (false===$stock) { throw new RuntimeException('stock_restore_failed'); }
            }
            $ok=$wpdb->update($redemptions,array('status'=>self::STATUS_REFUNDED,'refund_ledger_id'=>$refund_ledger_id,'admin_note'=>sanitize_textarea_field((string)$reason),'updated_at'=>$now,'refunded_at'=>$now),array('id'=>$redemption_id));
            if (false===$ok) { throw new RuntimeException('redemption_update_failed'); }
            if (!Manahel_Rewards_DB::commit()) { throw new RuntimeException('commit_failed'); }
            $updated=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$redemptions} WHERE id=%d",$redemption_id));
            do_action('manahel_rewards_redemption_refunded',$updated,$admin_user_id);
            return $updated;
        } catch (Throwable $e) {
            Manahel_Rewards_DB::rollback();
            return new WP_Error('redemption_refund_failed',__('بازگرداندن امتیاز انجام نشد.', 'manahel-rewards'),array('cause'=>$e->getMessage()));
        }
    }

    public static function is_user_verified($wp_user_id) {
        $wp_user_id=(int)$wp_user_id;
        $hamnoor_id=0;
        $meta_key=(class_exists('Hamnoor_WP_User_Bridge') && defined('Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID')) ? Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID : '_hamnoor_user_id';
        $hamnoor_id=(int)get_user_meta($wp_user_id,$meta_key,true);
        $verified=false;
        if ($hamnoor_id>0 && class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access','is_verified')) {
            $verified=(bool)Hamnoor_Mobile_Access::is_verified($hamnoor_id);
        }
        return (bool)apply_filters('manahel_rewards_redemption_identity_verified',$verified,$wp_user_id,$hamnoor_id);
    }
}
