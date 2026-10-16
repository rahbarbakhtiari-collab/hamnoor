<?php
$root=dirname(__DIR__);$fails=[];
function p6_check($ok,$label){global $fails;if($ok){echo "PASS: $label\n";}else{$fails[]=$label;echo "FAIL: $label\n";}}
$main=file_get_contents($root.'/manahel-rewards.php');
$adapter=file_get_contents($root.'/includes/class-manahel-rewards-referral-adapter.php');
$installer=file_get_contents($root.'/includes/class-manahel-rewards-installer.php');
$limit=file_get_contents($root.'/includes/class-manahel-rewards-limit-evaluator.php');
p6_check(false!==strpos($main,"Version: 0.8.11"),'plugin version is 0.8.11');
p6_check(false!==strpos($adapter,"manahel_referral/event/referral.qualified"),'general referral listener registered');
p6_check(false!==strpos($adapter,"manahel_referral/event/referral.campaign_participant"),'campaign-participant listener registered');
p6_check(false!==strpos($adapter,'manahel_referral_get_qualified_events_after') && false!==strpos($adapter,'manahel_referral_get_campaign_participant_events_after'),'both durable Referral streams reconcile through public APIs');
p6_check(false!==strpos($adapter,'CAMPAIGN_CURSOR_OPTION'),'campaign stream has independent reconciliation cursor');
p6_check(false===strpos($adapter,'manahel_referral_attributions'),'Rewards adapter does not query Referral tables directly');
p6_check(false!==strpos($installer,"MANAHEL_REFERRAL_QUALIFIED_INVITER"),'inviter rule template is seeded');
p6_check(false!==strpos($installer,"MANAHEL_REFERRAL_QUALIFIED_REFERRED"),'referred-user rule template is seeded');
p6_check(false!==strpos($installer,"MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT"),'campaign-attraction rule template is seeded');
p6_check(false!==strpos($installer,"'enabled' => 0"),'templates remain disabled by default');
p6_check(false!==strpos($limit,'max_awards_per_month') && false!==strpos($limit,'limit.monthly_awards'),'monthly limit contract is implemented');
p6_check(false!==strpos($limit,'month_bounds_utc'),'monthly policy windows use calendar boundaries');
if($fails){fwrite(STDERR,count($fails)." test(s) failed.\n");exit(1);} echo "All Phase 6 growth Referral adapter source tests passed.\n";
