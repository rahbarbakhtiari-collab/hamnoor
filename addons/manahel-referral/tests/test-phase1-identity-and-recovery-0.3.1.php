<?php
$root=dirname(__DIR__);$fails=[];function rf($ok,$m){global $fails;echo($ok?'PASS: ':'FAIL: ').$m."\n";if(!$ok)$fails[]=$m;}
$main=file_get_contents($root.'/manahel-referral.php');$a=file_get_contents($root.'/includes/class-manahel-referral-hamnoor-adapter.php');$d=file_get_contents($root.'/includes/class-manahel-referral-hamnoor-dashboard-integration.php');$s=file_get_contents($root.'/includes/class-manahel-referral-attribution-service.php');
rf(strpos($main,"MANAHEL_REFERRAL_VERSION', '0.3.2'")!==false,'Referral 0.3.2');
$ham=strpos($a,"Hamnoor_Mobile_Access::current_user()");$wp=strpos($a,'get_current_user_id()', $ham);rf($ham!==false&&$wp!==false&&$ham<$wp,'Hamnoor share identity takes precedence over WordPress cookie');
rf(strpos($d,"'growth' === \$tab")!==false,'Growth Hub is treated as Hamnoor identity context');
rf(strpos($a,'durable_events_after')!==false && strpos($a,'IDENTITY_CURSOR_OPTION')!==false && strpos($a,'PARTICIPATION_CURSOR_OPTION')!==false,'Referral reconciles durable Core identity/participation streams');
rf(strpos($a,'durable_event_for_subject')!==false,'current identity can recover first-touch after listener outage');
rf(strpos($s,'FIRST_TOUCH_META')!==false && strpos($s,'CAMPAIGN_TOUCH_META')!==false,'browser attribution has server-side recovery mirror');
rf(strpos($s,'touch_meta_value')!==false && strpos($s,'clear_campaign_touch_for_user')!==false,'campaign activation can replay without request cookie and clears durable touch');
if($fails){fwrite(STDERR,count($fails)." Referral Phase1 check(s) failed.\n");exit(1);}echo "Referral Phase1 contracts passed.\n";
