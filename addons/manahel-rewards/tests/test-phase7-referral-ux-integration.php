<?php
$root=dirname(__DIR__);$fails=[];
function p7w($ok,$label){global $fails;if($ok){echo "PASS: $label\n";}else{$fails[]=$label;echo "FAIL: $label\n";}}
$main=file_get_contents($root.'/manahel-rewards.php');
$plugin=file_get_contents($root.'/includes/class-manahel-rewards-plugin.php');
$ux=file_get_contents($root.'/includes/class-manahel-rewards-referral-ux-integration.php');
$installer=file_get_contents($root.'/includes/class-manahel-rewards-installer.php');
p7w(false!==strpos($main,'Version: 0.8.11'),'Rewards version 0.8.11');
p7w(false!==strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0"),'Rewards DB is 1.3.0');
p7w(false!==strpos($plugin,'Manahel_Rewards_Referral_UX_Integration::init'),'Referral UX financial projection initialized');
p7w(false!==strpos($ux,"manahel_referral_financial_summary"),'Optional Referral financial filter registered');
p7w(false!==strpos($ux,"e.source='manahel_referral'"),'Only Referral reward source included');
p7w(false!==strpos($ux,"e.event_name='referral.qualified'") && false!==strpos($ux,"e.entity_type='referral_inviter'") && false!==strpos($ux,"e.event_name='referral.campaign_participant'") && false!==strpos($ux,"e.entity_type='campaign_referrer'"),'financial summary counts both new-user and campaign-attraction inviter rewards');
p7w(false===stripos($ux,'insert into') && false===stripos($ux,'update '),'UX projection performs no SQL writes');
p7w(7===substr_count($installer,'CREATE TABLE'),'Rewards schema includes catalog, redemptions and one-time code inventory');
if($fails){fwrite(STDERR,count($fails)." test(s) failed.\n");exit(1);}echo "All Phase 7 Rewards growth UX integration source tests passed.\n";
