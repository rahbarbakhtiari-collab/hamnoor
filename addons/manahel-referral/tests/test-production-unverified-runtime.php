<?php
$root=dirname(__DIR__);$fails=[];
function ur($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$service=file_get_contents($root.'/includes/class-manahel-referral-attribution-service.php');
$front=file_get_contents($root.'/frontend/class-manahel-referral-frontend.php');
$start=strpos($service,'public static function qualify_from_participation');
$end=strpos($service,'public static function record_campaign_attraction_from_participation',$start);
$method=substr($service,$start,$end-$start);
ur(false===strpos($method,'is_verified'),'qualification method performs no verification lookup');
ur(false===strpos($method,'identity_unverified'),'unverified identity is not a qualification rejection reason');
ur(false!==strpos($method,"'status'=>'qualified'"),'valid participation can reach qualified status');
ur(false!==strpos($front,'تأیید شماره برای کسب امتیاز لازم نیست'),'user copy explicitly states OTP is not required for earning');
ur(false!==strpos($front,'صرف بازکردن لینک امتیاز ندارد'),'click alone still earns zero value');
if($fails){fwrite(STDERR,count($fails)." participation-first policy test(s) failed.\n");exit(1);} echo "All Referral unverified-earning policy tests passed.\n";
