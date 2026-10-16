<?php
$root=dirname(__DIR__);
function r12($ok,$m){echo ($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;if(!$ok)$GLOBALS['f']=($GLOBALS['f']??0)+1;}
$main=file_get_contents($root.'/manahel-rewards.php');
$db=file_get_contents($root.'/includes/class-manahel-rewards-db.php');
$ins=file_get_contents($root.'/includes/class-manahel-rewards-installer.php');
$svc=file_get_contents($root.'/includes/class-manahel-rewards-redemption-service.php');
$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
$notify=file_get_contents($root.'/includes/class-manahel-rewards-redemption-notification-bridge.php');
$admin=file_get_contents($root.'/admin/class-manahel-rewards-admin.php');
r12(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
r12(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'schema 1.3.0');
r12(strpos($db,"'catalog'")!==false && strpos($db,"'redemptions'")!==false,'DB maps redemption tables');
r12(strpos($ins,'CREATE TABLE {$catalog}')!==false && strpos($ins,'CREATE TABLE {$redemptions}')!==false,'migration creates InnoDB catalog/redemptions');
r12(strpos($svc,'START TRANSACTION')===false && strpos($svc,'Manahel_Rewards_DB::begin()')!==false,'redemption uses DB transaction abstraction');
r12(strpos($svc,'Manahel_Rewards_Account_Service::lock')!==false && strpos($svc,'FOR UPDATE')!==false,'redemption locks account/catalog');
r12(strpos($svc,"'entry_type'=>'redeem'")!==false && strpos($svc,"'delta'=>-$cost")!==false,'redemption appends negative Ledger debit');
r12(strpos($svc,"'entry_type'=>'refund'")!==false && strpos($svc,"'delta'=>$cost")!==false,'refund appends positive Ledger entry');
r12(strpos($svc,'insufficient_balance')!==false && strpos($svc,'out_of_stock')!==false && strpos($svc,'per_user_limit')!==false,'redemption guards balance/stock/per-user limit');
r12(strpos($svc,'redemption_requires_verification')!==false && strpos($svc,'is_user_verified')!==false,'verification is enforced only through redemption policy');
r12(strpos($front,'استفاده از امتیاز')!==false && strpos($front,'دریافت جایزه')!==false,'user-facing redemption UX exists');
r12(strpos($admin,"'جوایز'")!==false && strpos($admin,'درخواست‌های جایزه')!==false,'admin catalog and redemption queues exist');
r12(strpos($svc,"do_action('manahel_rewards_redemption_created'")!==false && strpos($svc,"do_action('manahel_rewards_redemption_fulfilled'")!==false,'future fulfillment integration hooks exist');
r12(strpos($notify,'reward_redemption_status')!==false && strpos($notify,'Hamnoor_DB::add_notification')!==false,'fulfillment/refund reuses Hamnoor notifications');
exit(!empty($GLOBALS['f'])?1:0);
