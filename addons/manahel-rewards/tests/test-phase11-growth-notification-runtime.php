<?php
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
$GLOBALS['filters']=[];$GLOBALS['actions']=[];$GLOBALS['notifications']=[];$GLOBALS['meta']=[55=>['_hamnoor_user_id'=>7]];
function add_filter($h,$cb,$p=10,$a=1){$GLOBALS['filters'][$h][]=$cb;return true;}
function add_action($h,$cb,$p=10,$a=1){$GLOBALS['actions'][$h][]=$cb;return true;}
function apply_filters($h,$v,...$args){foreach($GLOBALS['filters'][$h]??[] as $cb){$v=$cb($v,...$args);}return $v;}
function sanitize_key($s){$s=strtolower((string)$s);return preg_replace('/[^a-z0-9_\-]/','',$s);}
function get_user_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}
function number_format_i18n($n){return number_format((int)$n);}
function __($s,$d=null){return $s;}
class Hamnoor_Mobile_Access { public static function get_user($id){return 7===(int)$id?(object)['id'=>7,'wp_user_id'=>55]:null;} }
class Hamnoor_DB {
 public static function table($n){return 'x_'.$n;}
 public static function add_notification($uid,$type,$title,$message='',$related_type='',$related_id=0,$dispatch=true){$id=count($GLOBALS['notifications'])+1;$GLOBALS['notifications'][]=compact('id','uid','type','title','message','related_type','related_id','dispatch');return $id;}
}
class WPDBStub {function prepare($q,...$a){return [$q,$a];}function get_var($p){if(is_array($p)){[$q,$a]=$p;if(strpos($q,'FROM x_notifications')!==false){foreach($GLOBALS['notifications'] as $n){if($n['uid']===$a[0]&&$n['type']===$a[1]&&$n['related_type']===$a[2]&&$n['related_id']===$a[3])return $n['id'];}}}return 0;}}
$GLOBALS['wpdb']=new WPDBStub();
require dirname(__DIR__).'/includes/class-manahel-rewards-notification-bridge.php';
Manahel_Rewards_Notification_Bridge::init();
$fail=[];$pass=0;function rt11($ok,$m){global$fail,$pass;if($ok){$pass++;echo"[PASS] $m\n";}else{$fail[]=$m;echo"[FAIL] $m\n";}}
$base=['event_id'=>91,'wp_user_id'=>55,'points'=>40,'balance'=>140,'decision'=>['rules'=>[['rule_code'=>'MANAHEL_REFERRAL_QUALIFIED_INVITER','result'=>'awarded','points'=>40]]]];
$r=Manahel_Rewards_Notification_Bridge::on_earning_committed($base);
rt11(1===$r && 1===count($GLOBALS['notifications']),'high-value inviter award creates one notification');
$n=$GLOBALS['notifications'][0]??[];
rt11('reward_growth_earned'===($n['type']??'') && true===($n['dispatch']??false),'growth notification is dispatchable');
rt11(false!==strpos((string)($n['title']??''),'40') && false!==strpos((string)($n['message']??''),'140'),'message contains awarded points and resulting balance');
Manahel_Rewards_Notification_Bridge::on_earning_committed($base);
rt11(1===count($GLOBALS['notifications']),'same reward event cannot create duplicate notification');
$small=['event_id'=>92,'wp_user_id'=>55,'points'=>2,'balance'=>142,'decision'=>['rules'=>[['rule_code'=>'HAMNOOR_QURAN_PART_COMPLETED','result'=>'awarded','points'=>2]]]];
rt11(false===Manahel_Rewards_Notification_Bridge::on_earning_committed($small) && 1===count($GLOBALS['notifications']),'routine Quran reward stays externally silent');
$welcome=['event_id'=>93,'wp_user_id'=>55,'points'=>5,'balance'=>147,'decision'=>['rules'=>[['rule_code'=>'MANAHEL_REFERRAL_QUALIFIED_REFERRED','result'=>'awarded','points'=>5]]]];
rt11(false===Manahel_Rewards_Notification_Bridge::on_earning_committed($welcome),'small referred-user welcome reward stays externally silent');
$types=apply_filters('hamnoor_important_notification_types',[]);$ext=apply_filters('hamnoor_external_notification_types',[],null);
rt11(in_array('reward_growth_earned',$types,true)&&in_array('reward_growth_earned',$ext,true),'semantic type is registered for internal and external Hamnoor routing');
if($fail){fwrite(STDERR,count($fail)." runtime check(s) failed.\n");exit(1);}echo"All Phase 11 growth-notification runtime checks passed ($pass).\n";
