<?php
$root=dirname(__DIR__);
$fail=[];$pass=0;
function p11($ok,$m){global $fail,$pass;if($ok){$pass++;echo "[PASS] $m\n";}else{$fail[]=$m;echo "[FAIL] $m\n";}}
$main=file_get_contents($root.'/manahel-rewards.php');
$plugin=file_get_contents($root.'/includes/class-manahel-rewards-plugin.php');
$processor=file_get_contents($root.'/includes/class-manahel-rewards-event-processor.php');
$bridge=file_get_contents($root.'/includes/class-manahel-rewards-notification-bridge.php');
p11(false!==strpos($main,'Version: 0.8.11') && false!==strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'"),'version is 0.8.11');
p11(false!==strpos($main,'class-manahel-rewards-notification-bridge.php'),'bridge is packaged');
p11(false!==strpos($plugin,'Manahel_Rewards_Notification_Bridge::init()'),'bridge initializes');
p11(false!==strpos($processor,"do_action('manahel_rewards/earning_committed'"),'post-commit earning action exists');
p11(strpos($processor,"commit())") < strpos($processor,"do_action('manahel_rewards/earning_committed'"),'notification hook is after COMMIT');
p11(false!==strpos($processor,'catch (Throwable $side_effect_error)'),'side-effect failure is isolated from financial result');
p11(false!==strpos($bridge,"'MANAHEL_REFERRAL_QUALIFIED_INVITER'") && false!==strpos($bridge,"'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT'"),'only high-value growth rules are default external candidates');
p11(false===strpos($bridge,"'HAMNOOR_QURAN_PART_COMPLETED'") && false===strpos($bridge,"'HAMNOOR_DUA_COMPLETED'") && false===strpos($bridge,"'MANAHEL_REFERRAL_QUALIFIED_REFERRED'"),'routine/welcome rewards are not external candidates');
p11(false!==strpos($bridge,"add_filter('hamnoor_important_notification_types'") && false!==strpos($bridge,"add_filter('hamnoor_external_notification_types'"),'bridge reuses Hamnoor notification contracts');
p11(false!==strpos($bridge,"'reward_event'") && false!==strpos($bridge,'already_created'),'event-id notification dedupe exists');
p11(false!==strpos($bridge,'Hamnoor_DB::add_notification') && preg_match('/Hamnoor_DB::add_notification\s*\([\s\S]*?true\s*\)/',$bridge),'notification is persisted and dispatched through Hamnoor');
p11(false!==strpos($bridge,"apply_filters('manahel_rewards_external_notification_rule_codes'"),'future growth notification rules are extensible');
if($fail){fwrite(STDERR,count($fail)." check(s) failed.\n");exit(1);}echo "All Phase 11 growth-notification source checks passed ($pass).\n";
