<?php
if (!defined('ABSPATH')) { exit; }

/** Optional Hamnoor notification bridge for redemption lifecycle. */
final class Manahel_Rewards_Redemption_Notification_Bridge {
    const TYPE='reward_redemption_status';
    private static $initialized=false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized=true;
        add_filter('hamnoor_important_notification_types',array(__CLASS__,'register_internal_type'));
        add_filter('hamnoor_external_notification_types',array(__CLASS__,'register_external_type'),10,2);
        add_action('manahel_rewards_redemption_created',array(__CLASS__,'created'),10,3);
        add_action('manahel_rewards_redemption_fulfilled',array(__CLASS__,'fulfilled'),10,2);
        add_action('manahel_rewards_redemption_refunded',array(__CLASS__,'refunded'),10,2);
    }

    public static function register_internal_type($types) {
        $types=is_array($types)?$types:array(); if(!in_array(self::TYPE,$types,true)){$types[]=self::TYPE;} return $types;
    }
    public static function register_external_type($types,$notification=null) {
        $types=is_array($types)?$types:array(); if(!in_array(self::TYPE,$types,true)){$types[]=self::TYPE;} return $types;
    }
    public static function created($redemption,$item,$wp_user_id) {
        if(!$redemption||!$item){return;}
        if ('fulfilled' === (string)$redemption->status) { return; }
        self::notify((int)$wp_user_id,__('درخواست جایزه ثبت شد','manahel-rewards'),sprintf(__('درخواست «%s» ثبت شد و در انتظار تحویل است.','manahel-rewards'),(string)$item->title),false,(int)$redemption->id);
    }
    public static function fulfilled($redemption,$admin_user_id) {
        if(!$redemption){return;} $item=Manahel_Rewards_Redemption_Service::get_catalog_item((int)$redemption->reward_id,false);
        $title=$item?(string)$item->title:__('جایزه','manahel-rewards');
        $value=trim((string)$redemption->fulfillment_value);
        if ($item && 'code' === (string)$item->fulfillment_mode && '' !== $value) {
            $message=sprintf(__('جایزه «%1$s» تحویل شد. کد شما: %2$s','manahel-rewards'),$title,$value);
        } else {
            $message=sprintf(__('درخواست «%s» تحویل شد. جزئیات را در بخش امتیازهای من ببینید.','manahel-rewards'),$title);
        }
        self::notify((int)$redemption->wp_user_id,__('جایزه شما تحویل شد','manahel-rewards'),$message,true,(int)$redemption->id);
    }
    public static function refunded($redemption,$admin_user_id) {
        if(!$redemption){return;} $item=Manahel_Rewards_Redemption_Service::get_catalog_item((int)$redemption->reward_id,false);
        $title=$item?(string)$item->title:__('جایزه','manahel-rewards');
        self::notify((int)$redemption->wp_user_id,__('امتیاز جایزه برگشت داده شد','manahel-rewards'),sprintf(__('درخواست «%s» لغو شد و %s امتیاز به موجودی شما برگشت.','manahel-rewards'),$title,number_format_i18n((int)$redemption->points_cost)),true,(int)$redemption->id);
    }
    private static function notify($wp_user_id,$title,$message,$dispatch,$related_id) {
        $wp_user_id=(int)$wp_user_id; $related_id=(int)$related_id;
        if(!class_exists('Hamnoor_DB')){self::record_failure('hamnoor_db_missing',$wp_user_id,$related_id);return false;}
        $hamnoor_id=self::hamnoor_user_id($wp_user_id);
        if($hamnoor_id<=0){self::record_failure('hamnoor_user_link_missing',$wp_user_id,$related_id);return false;}
        $notification_id=Hamnoor_DB::add_notification($hamnoor_id,self::TYPE,$title,$message,'reward_redemption',$related_id,(bool)$dispatch);
        if(!$notification_id){self::record_failure('hamnoor_add_notification_failed',$wp_user_id,$related_id);return false;}
        self::clear_failure();
        return $notification_id;
    }
    /**
     * Resolve the Hamnoor identity from the authoritative domain link first.
     * Older/partially migrated accounts can have users.wp_user_id populated while
     * the mirrored WordPress user meta is absent. Redemption must not silently
     * lose notifications in that state.
     */
    private static function hamnoor_user_id($wp_user_id) {
        global $wpdb;
        $wp_user_id=(int)$wp_user_id; if($wp_user_id<=0){return 0;}
        $key=(class_exists('Hamnoor_WP_User_Bridge') && defined('Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID'))?Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID:'_hamnoor_user_id';
        $meta=max(0,(int)get_user_meta($wp_user_id,$key,true));
        if(class_exists('Hamnoor_DB') && method_exists('Hamnoor_DB','table')){
            $table=Hamnoor_DB::table('users');
            if($table){
                $domain_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE wp_user_id=%d AND status='active' LIMIT 1",$wp_user_id));
                if($domain_id>0){
                    if($meta!==$domain_id){update_user_meta($wp_user_id,$key,$domain_id);}
                    return $domain_id;
                }
                if($meta>0){
                    $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d AND status='active' LIMIT 1",$meta));
                    if($valid>0){return $valid;}
                }
            }
        }
        return $meta;
    }
    private static function record_failure($reason,$wp_user_id,$related_id) {
        update_option('manahel_rewards_last_redemption_notification_error',array(
            'reason'=>sanitize_key((string)$reason),
            'wp_user_id'=>(int)$wp_user_id,
            'redemption_id'=>(int)$related_id,
            'at'=>current_time('mysql'),
        ),false);
    }
    private static function clear_failure() {
        delete_option('manahel_rewards_last_redemption_notification_error');
    }
}
