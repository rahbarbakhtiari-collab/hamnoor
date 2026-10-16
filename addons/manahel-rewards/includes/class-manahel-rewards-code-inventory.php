<?php
if (!defined('ABSPATH')) { exit; }

/** Transactional one-time code inventory for auto-fulfilled digital rewards. */
final class Manahel_Rewards_Code_Inventory {
    public static function import_codes($reward_id, $raw_codes) {
        global $wpdb;
        $reward_id=(int)$reward_id;
        $item=Manahel_Rewards_Redemption_Service::get_catalog_item($reward_id,false);
        if(!$item){return new WP_Error('reward_not_found',__('جایزه پیدا نشد.','manahel-rewards'));}
        if('code'!==(string)$item->fulfillment_mode){return new WP_Error('reward_not_code_mode',__('مخزن کد فقط برای جایزه‌های «کد / شارژ / ووچر» قابل استفاده است.','manahel-rewards'));}
        $lines=is_array($raw_codes)?$raw_codes:preg_split('/\r\n|\r|\n/',(string)$raw_codes);
        $table=Manahel_Rewards_DB::table('codes');
        $now=Manahel_Rewards_DB::utc_now_mysql();
        $added=0;$duplicates=0;$invalid=0;
        foreach((array)$lines as $line){
            $value=trim((string)$line);
            if(''===$value){continue;}
            $value=preg_replace('/[\x00-\x1F\x7F]/u','',$value);
            if(function_exists('mb_substr')){$value=mb_substr($value,0,500,'UTF-8');}else{$value=substr($value,0,500);}
            if(''===trim($value)){$invalid++;continue;}
            $hash=hash('sha256',$value);
            $ins=Manahel_Rewards_DB::quiet_insert($table,array(
                'reward_id'=>$reward_id,'code_hash'=>$hash,'code_value'=>$value,'status'=>'available','redemption_id'=>null,
                'created_at'=>$now,'assigned_at'=>null,
            ));
            if(false===$ins['result']){$duplicates++;}else{$added++;}
        }
        self::sync_catalog_stock($reward_id);
        $stats=self::stats($reward_id);
        do_action('manahel_rewards_code_inventory_changed',$reward_id,$stats,array(
            'added'=>$added,'duplicates'=>$duplicates,'invalid'=>$invalid,
        ));
        return array('added'=>$added,'duplicates'=>$duplicates,'invalid'=>$invalid,'stats'=>$stats);
    }

    public static function stats($reward_id) {
        global $wpdb;
        $reward_id=(int)$reward_id;$table=Manahel_Rewards_DB::table('codes');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,COALESCE(SUM(status='available'),0) available,COALESCE(SUM(status='assigned'),0) assigned,COALESCE(SUM(status='void'),0) voided FROM {$table} WHERE reward_id=%d",
            $reward_id
        ));
        return array(
            'total'=>(int)($row->total??0),'available'=>(int)($row->available??0),'assigned'=>(int)($row->assigned??0),'voided'=>(int)($row->voided??0),
        );
    }

    /** Admin-only read model used to make append-only inventory visible. */
    public static function list_codes($reward_id,$limit=100) {
        global $wpdb;
        $reward_id=(int)$reward_id;
        $limit=max(1,min(500,(int)$limit));
        $table=Manahel_Rewards_DB::table('codes');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,code_value,status,redemption_id,created_at,assigned_at FROM {$table} WHERE reward_id=%d ORDER BY CASE status WHEN 'available' THEN 0 WHEN 'assigned' THEN 1 ELSE 2 END ASC,id DESC LIMIT %d",
            $reward_id,$limit
        ));
    }

    /** Must be called from an active transaction after catalog row is locked. */
    public static function lock_next_available($reward_id) {
        global $wpdb;
        $table=Manahel_Rewards_DB::table('codes');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE reward_id=%d AND status='available' ORDER BY id ASC LIMIT 1 FOR UPDATE",
            (int)$reward_id
        ));
    }

    /** Must be called in the same transaction that creates the redemption. */
    public static function assign_locked($code_id,$redemption_id) {
        global $wpdb;
        $now=Manahel_Rewards_DB::utc_now_mysql();
        $ok=$wpdb->update(Manahel_Rewards_DB::table('codes'),array(
            'status'=>'assigned','redemption_id'=>(int)$redemption_id,'assigned_at'=>$now,
        ),array('id'=>(int)$code_id,'status'=>'available'));
        return 1===$ok;
    }

    public static function sync_catalog_stock($reward_id) {
        global $wpdb;
        $stats=self::stats($reward_id);
        $ok=$wpdb->update(Manahel_Rewards_DB::table('catalog'),array(
            'stock_total'=>(int)$stats['total'],'stock_used'=>(int)$stats['assigned'],'updated_at'=>Manahel_Rewards_DB::utc_now_mysql(),
        ),array('id'=>(int)$reward_id));
        return false!==$ok;
    }
}
