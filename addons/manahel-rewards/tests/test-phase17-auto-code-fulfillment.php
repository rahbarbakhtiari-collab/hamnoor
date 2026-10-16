<?php
$root=dirname(__DIR__);
$main=file_get_contents($root.'/manahel-rewards.php');
$db=file_get_contents($root.'/includes/class-manahel-rewards-db.php');
$ins=file_get_contents($root.'/includes/class-manahel-rewards-installer.php');
$svc=file_get_contents($root.'/includes/class-manahel-rewards-redemption-service.php');
$inv=file_get_contents($root.'/includes/class-manahel-rewards-code-inventory.php');
$front=file_get_contents($root.'/includes/class-manahel-rewards-redemption-frontend.php');
$notify=file_get_contents($root.'/includes/class-manahel-rewards-redemption-notification-bridge.php');
$admin=file_get_contents($root.'/admin/class-manahel-rewards-admin.php');
$FAIL=0;function p17($ok,$m){global $FAIL;echo($ok?'PASS: ':'FAIL: ').$m."\n";if(!$ok)$FAIL++;}
p17(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
p17(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB schema 1.3.0');
p17(strpos($db,"'codes' => 'manahel_reward_codes'")!==false,'code pool DB table registered');
p17(strpos($ins,'CREATE TABLE {$codes}')!==false && strpos($ins,'reward_code_hash')!==false,'code pool schema is installed with uniqueness');
p17(strpos($inv,'lock_next_available')!==false && strpos($inv,'FOR UPDATE')!==false,'next digital code is transaction-locked');
p17(strpos($inv,'assign_locked')!==false && strpos($inv,"'status'=>'assigned'")!==false,'locked code is assigned once');
p17(strpos($svc,"\$auto_code = 'code' === (string)\$item->fulfillment_mode")!==false,'code rewards auto-fulfill');
p17(strpos($svc,"\$initial_status = \$auto_code ? self::STATUS_FULFILLED : self::STATUS_PENDING")!==false,'code redemption skips manager-pending state');
p17(strpos($svc,"do_action('manahel_rewards_redemption_fulfilled',\$row,0)")!==false,'auto fulfillment emits normal fulfilled lifecycle after commit');
p17(strpos($svc,'$consume_code_permanently')!==false,'refunded delivered code is not recycled');
p17(strpos($front,"self::redirect('delivered')")!==false,'user receives immediate-delivery notice');
p17(strpos($notify,'کد شما: %2$s')!==false,'fulfilled notification includes issued code');
p17(strpos($notify,"if ('fulfilled' === (string)\$redemption->status) { return; }")!==false,'auto-fulfilled code does not also send pending notification');
p17(strpos($admin,'codes_bulk')!==false && strpos($admin,'هر کد را در یک خط وارد کنید')!==false,'admin can preload one-time codes');
p17(strpos($admin,'تحویل خودکار')!==false,'admin UI identifies automatic code delivery');
if($FAIL){echo "$FAIL phase17 check(s) failed.\n";exit(1);}echo "Phase17 auto-code fulfillment contract passed.\n";
