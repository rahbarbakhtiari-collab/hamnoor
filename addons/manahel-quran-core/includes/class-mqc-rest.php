<?php
if (!defined('ABSPATH')) { exit; }

final class MQC_REST {
    public static function register() { add_action('rest_api_init', array(__CLASS__, 'routes')); }
    public static function routes() {
        register_rest_route('manahel-quran/v1', '/verse/(?P<surah>\d+)/(?P<ayah>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'verse'), 'permission_callback' => '__return_true',
            'args' => array('surah'=>array('validate_callback'=>array(__CLASS__,'valid_surah')),'ayah'=>array('validate_callback'=>array(__CLASS__,'positive'))),
        ));
        register_rest_route('manahel-quran/v1', '/range', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'range'), 'permission_callback' => '__return_true',
            'args' => array('start'=>array('required'=>true),'end'=>array('required'=>true),'limit'=>array('default'=>100,'sanitize_callback'=>'absint')),
        ));
        register_rest_route('manahel-quran/v1', '/division/(?P<type>[a-z_]+)/(?P<number>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'division'), 'permission_callback' => '__return_true',
        ));
        register_rest_route('manahel-quran/v1', '/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'status'), 'permission_callback' => function(){ return current_user_can('manage_options'); },
        ));
    }
    public static function valid_surah($value) { return (int)$value >= 1 && (int)$value <= 114; }
    public static function positive($value) { return (int)$value > 0; }
    public static function verse($request) {
        $v=manahel_quran_core()->get_verse($request['surah'],$request['ayah']);
        return $v ? rest_ensure_response($v) : new WP_Error('mqc_not_found','آیه پیدا نشد.',array('status'=>404));
    }
    public static function range($request) {
        $r=manahel_quran_core()->get_range($request['start'],$request['end'],$request['limit']);
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }
    public static function division($request) {
        $allowed=array('juz','hizb_quarter','manzil','ruku','page','sajda');
        if (!in_array($request['type'],$allowed,true)) { return new WP_Error('mqc_bad_type','نوع تقسیم‌بندی معتبر نیست.',array('status'=>400)); }
        $d=manahel_quran_core()->get_division($request['type'],$request['number']);
        return $d ? rest_ensure_response($d) : new WP_Error('mqc_not_found','تقسیم‌بندی پیدا نشد.',array('status'=>404));
    }
    public static function status() { return rest_ensure_response(MQC_Installer::status()); }
}
