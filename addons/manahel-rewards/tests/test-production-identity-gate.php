<?php
define('ABSPATH', __DIR__ . '/');
function get_user_meta($user_id, $key, $single=true) { return $GLOBALS['mr_meta'][$user_id][$key] ?? ''; }
function apply_filters($hook,$value){ return $value; }
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-identity-gate.php';

$fails=array();
function pg($ok,$label){global $fails; echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL; if(!$ok){$fails[]=$label;}}
$GLOBALS['mr_meta'][101]['_hamnoor_user_id']=11;
$GLOBALS['mr_meta'][102]['_hamnoor_user_id']=12;

$direct=array('source'=>'hamnoor','event_name'=>'participation.completed','source_subject_user_id'=>11);
$r=Manahel_Rewards_Identity_Gate::evaluate($direct,101);
pg(!empty($r['allowed']) && 'earning_verification_not_required'===($r['code']??''),'unverified direct participation may earn without OTP');
pg('earn_first_verify_on_redemption_v1'===($r['contract']??''),'earning policy contract is explicit');
pg(11===(int)($r['hamnoor_user_id']??0),'Hamnoor identity bridge remains observable without being a gate');

$ref=array('source'=>'manahel_referral','event_name'=>'referral.qualified','source_subject_user_id'=>102);
$r=Manahel_Rewards_Identity_Gate::evaluate($ref,102);
pg(!empty($r['allowed']) && 'earning_verification_not_required'===($r['code']??''),'referral recipient may earn without OTP');

$campaign=array('source'=>'manahel_referral','event_name'=>'referral.campaign_participant','source_subject_user_id'=>101);
$r=Manahel_Rewards_Identity_Gate::evaluate($campaign,101);
pg(!empty($r['allowed']),'campaign referrer may earn without OTP');

$r=Manahel_Rewards_Identity_Gate::evaluate($direct,0);
pg(empty($r['allowed']) && 'identity_unresolved'===($r['code']??''),'unresolved local account still fails closed');

pg(false===Manahel_Rewards_Identity_Gate::redemption_requires_verification(101,array('value'=>'economic')),'redemption verification defaults to optional unless reward requires it');

$manual=array('source'=>'admin','event_name'=>'manual.adjustment');
$r=Manahel_Rewards_Identity_Gate::evaluate($manual,101);
pg(!empty($r['allowed']),'manual adjustments remain operable for resolved account');

if($fails){fwrite(STDERR,count($fails)." production earning-policy test(s) failed.\n");exit(1);} echo "All production earn-first/verify-on-redemption runtime tests passed.\n";
