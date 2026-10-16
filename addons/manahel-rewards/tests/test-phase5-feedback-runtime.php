<?php
define('ABSPATH', __DIR__ . '/');
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function __($text,$domain=null){ return $text; }
function number_format_i18n($n){ return (string)$n; }
class WP_Error { public function __construct($code='',$message=''){} }
function is_wp_error($value){ return $value instanceof WP_Error; }
class Manahel_Rewards_Hamnoor_Adapter {
    public static $record = null;
    public static function request_result_for_payload($payload){ return self::$record; }
}
require dirname(__DIR__) . '/includes/class-manahel-rewards-hamnoor-feedback-integration.php';

$fail=[];
function rt($ok,$message){global $fail;echo ($ok?'PASS: ':'FAIL: ').$message.PHP_EOL;if(!$ok){$fail[]=$message;}}
function payload($type,$id,$content='quran'){return ['event_name'=>'participation.completed','entity_type'=>$type,'entity_id'=>$id,'context'=>['content_type'=>$content]];}

Manahel_Rewards_Hamnoor_Adapter::$record=['result'=>['status'=>'processed','event_id'=>1,'points'=>10,'balance'=>110,'decision'=>['decision_code'=>'awarded']]];
$f=Manahel_Rewards_Hamnoor_Feedback_Integration::append_feedback([],payload('quran_part','PRT-1','quran'))['manahel_rewards']??[];
rt(10===(int)($f['points']??0) && 110===(int)($f['balance']??0) && 'awarded'===($f['status']??''),'fresh Quran award produces +10 feedback with exact balance');
rt(false!==strpos((string)($f['message']??''),'سهم قرآن'),'Quran feedback is human and activity-specific');

Manahel_Rewards_Hamnoor_Adapter::$record=['result'=>['duplicate'=>true,'event_id'=>1,'status'=>'processed']];
rt([]===Manahel_Rewards_Hamnoor_Feedback_Integration::append_feedback([],payload('quran_part','PRT-1','quran')),'receipt replay is silent');

Manahel_Rewards_Hamnoor_Adapter::$record=['result'=>['status'=>'processed','event_id'=>2,'points'=>0,'balance'=>9,'decision'=>['decision_code'=>'limit.daily_awards']]];
$f=Manahel_Rewards_Hamnoor_Feedback_Integration::append_feedback([],payload('contribution','CTR-1','dua'))['manahel_rewards']??[];
rt('limited'===($f['status']??'') && 'info'===($f['tone']??'') && 'limit.daily_awards'===($f['decision_code']??''),'daily-limit completion gets informational feedback');

Manahel_Rewards_Hamnoor_Adapter::$record=['result'=>['status'=>'ignored','event_id'=>3,'points'=>0,'reason'=>'policy_excluded:dhikr']];
rt([]===Manahel_Rewards_Hamnoor_Feedback_Integration::append_feedback([],payload('contribution','CTR-D','dhikr')),'Dhikr remains silent');

if($fail){fwrite(STDERR,count($fail)." runtime check(s) failed.\n");exit(1);}echo "All Phase 5 feedback runtime checks passed.\n";
