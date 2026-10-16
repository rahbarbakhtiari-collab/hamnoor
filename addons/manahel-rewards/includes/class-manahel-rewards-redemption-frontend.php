<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Redemption_Frontend {
    private static $initialized=false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized=true;
        add_action('admin_post_manahel_rewards_redeem',array(__CLASS__,'handle_redeem'));
        add_action('admin_post_nopriv_manahel_rewards_redeem',array(__CLASS__,'handle_redeem'));
    }

    public static function token($wp_user_id,$reward_id,$bucket=null) {
        $bucket=null===$bucket?(int)floor(time()/(12*HOUR_IN_SECONDS)):(int)$bucket;
        return hash_hmac('sha256',(int)$wp_user_id.'|'.(int)$reward_id.'|'.$bucket,wp_salt('nonce'));
    }

    public static function verify_token($token,$wp_user_id,$reward_id) {
        $token=(string)$token;
        if (!$token) { return false; }
        $bucket=(int)floor(time()/(12*HOUR_IN_SECONDS));
        return hash_equals(self::token($wp_user_id,$reward_id,$bucket),$token) || hash_equals(self::token($wp_user_id,$reward_id,$bucket-1),$token);
    }

    public static function handle_redeem() {
        $wp_user_id=self::viewer_wp_user_id();
        $reward_id=isset($_POST['reward_id'])?(int)$_POST['reward_id']:0;
        $token=isset($_POST['redemption_token'])?sanitize_text_field(wp_unslash($_POST['redemption_token'])):'';
        $key=isset($_POST['idempotency_key'])?sanitize_key(wp_unslash($_POST['idempotency_key'])):'';
        $note=isset($_POST['user_note'])?wp_unslash($_POST['user_note']):'';
        if ($wp_user_id<=0 || !self::verify_token($token,$wp_user_id,$reward_id)) {
            self::redirect('security');
        }
        $result=Manahel_Rewards_Redemption_Service::redeem($wp_user_id,$reward_id,$note,$key);
        if (is_wp_error($result)) { self::redirect($result->get_error_code()); }
        if ($result && 'fulfilled' === (string)$result->status && trim((string)$result->fulfillment_value) !== '') { self::redirect('delivered'); }
        self::redirect('success');
    }


    private static function viewer_wp_user_id() {
        $wp_user_id=Manahel_Rewards_Frontend::viewer_wp_user_id();
        if ($wp_user_id>0) { return $wp_user_id; }
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access','current_user')) {
            $hu=Hamnoor_Mobile_Access::current_user();
            if ($hu && !empty($hu->wp_user_id) && get_user_by('id',(int)$hu->wp_user_id)) { return (int)$hu->wp_user_id; }
        }
        return 0;
    }

    private static function redirect($notice) {
        $url=wp_get_referer();
        if (!$url || 0!==strpos($url,home_url('/'))) { $url=Manahel_Rewards_Frontend::my_points_url(); }
        if (!$url) { $url=home_url('/'); }
        $url=remove_query_arg(array('mr_redeem'),$url);
        $url=add_query_arg('mr_redeem',sanitize_key((string)$notice),$url);
        wp_safe_redirect($url);
        exit;
    }
}
