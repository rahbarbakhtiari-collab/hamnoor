<?php
$root=dirname(__DIR__);
$main=file_get_contents($root.'/manahel-rewards.php');
$inv=file_get_contents($root.'/includes/class-manahel-rewards-code-inventory.php');
$admin=file_get_contents($root.'/admin/class-manahel-rewards-admin.php');
$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
$fail=0;
function p18($ok,$msg){global $fail; echo ($ok?'PASS':'FAIL').': '.$msg.PHP_EOL; if(!$ok)$fail++;}
p18(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
p18(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB schema remains 1.3.0');
p18(strpos($inv,'public static function list_codes')!==false,'admin inventory read model exists');
p18(strpos($inv,"do_action('manahel_rewards_code_inventory_changed'")!==false,'inventory change signal exists after import');
p18(strpos($admin,"افزودن کدهای جدید")!==false,'edit form clearly says codes are appended');
p18(strpos($admin,"کدهای قبلی پاک نمی‌شوند")!==false,'edit form explains old codes are retained');
p18(strpos($admin,"مخزن کدهای این جایزه")!==false,'existing code inventory is visible to manager');
p18(strpos($admin,"reward_codes_none_added")!==false,'zero-added import has explicit warning');
p18(strpos($admin,"mr_codes_added")!==false && strpos($admin,"mr_codes_available")!==false,'import result reports added and available counts');
p18(strpos($front,"protect_growth_route_from_cache")!==false,'growth route gets early no-cache protection');
p18(strpos($front,"litespeed_control_set_nocache")!==false,'LiteSpeed no-cache integration is explicit');
p18(strpos($front,"کدهای این جایزه فعلاً تمام شده‌اند")!==false,'out-of-code message is specific');
p18(strpos($front,"کد آماده: %s")!==false,'frontend shows exact ready-code count');
exit($fail?1:0);
