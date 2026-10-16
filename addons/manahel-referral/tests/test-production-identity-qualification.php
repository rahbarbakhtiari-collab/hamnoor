<?php
$root=dirname(__DIR__);$fails=array();
function rq($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/manahel-referral.php');
$adapter=file_get_contents($root.'/includes/class-manahel-referral-hamnoor-adapter.php');
$service=file_get_contents($root.'/includes/class-manahel-referral-attribution-service.php');
rq(false!==strpos($main,"Version: 0.3.2"),'Referral participation-first version is 0.3.2');
rq(false===strpos(substr($service,strpos($service,'function qualify_from_participation'),strpos($service,'function record_campaign_attraction_from_participation')-strpos($service,'function qualify_from_participation')),'is_verified_hamnoor_user'),'general referral qualification has no OTP gate');
rq(false===strpos($service,"'reason' => 'identity_unverified'") && false===strpos($service,"'identity_verified'=>1"),'old verification-gate financial evidence removed');
rq(false!==strpos($service,"'earning_policy'=>'participation_first_v1'"),'qualified facts carry participation-first policy marker');
rq(false!==strpos($service,"Manahel_Referral_DB::begin()") && false!==strpos($service,"status'=>'qualified'"),'transactional qualification path remains intact');
rq(false!==strpos($adapter,'is_verified_hamnoor_user') && false!==strpos($adapter,'Backward-compatible helper'),'old verification helper retained only for compatibility');
if($fails){fwrite(STDERR,count($fails)." Referral participation-first check(s) failed.\n");exit(1);}echo "All Referral participation-first source tests passed.\n";
