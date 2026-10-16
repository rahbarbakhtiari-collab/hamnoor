<?php
$root=dirname(__DIR__);$fails=[];
function cs($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/manahel-referral.php');
$adapter=file_get_contents($root.'/includes/class-manahel-referral-hamnoor-adapter.php');
$service=file_get_contents($root.'/includes/class-manahel-referral-attribution-service.php');
cs(false!==strpos($adapter,"add_filter('hamnoor_share_invite_url'"),'Referral personalizes Hamnoor share-only URL contract');
cs(false!==strpos($adapter,"'mref'") && false!==strpos($adapter,"'mrc'"),'personalized campaign share carries mref and mrc');
cs(false!==strpos($service,"CAMPAIGN_COOKIE_NAME = 'manahel_referral_campaign_touch'"),'campaign touch uses separate cookie');
cs(false!==strpos($service,"'event_name'=>'referral.campaign_participant'"),'campaign activation emits durable referral.campaign_participant fact');
cs(false!==strpos($main,'manahel_referral_get_campaign_participant_events_after'),'campaign activation exposes reconciliation API');
cs(false!==strpos($service,"'reason'=>'general_referral_precedence'"),'new-user general referral takes precedence over same first campaign activation');
cs(false!==strpos($service,'$participant_wp_user_id === $inviter_wp_user_id'),'campaign self-referral is blocked');
cs(false!==strpos($service,"\$inviter_wp_user_id . '|' . \$participant_wp_user_id . '|' . \$payload_campaign"),'campaign fact has stable inviter/participant/campaign identity');
cs(false!==strpos($service,"'earning_policy'=>'participation_first_v1'"),'campaign activation uses participation-first policy');
if($fails){fwrite(STDERR,count($fails)." campaign attribution check(s) failed.\n");exit(1);} echo "All campaign share-attribution source tests passed.\n";
