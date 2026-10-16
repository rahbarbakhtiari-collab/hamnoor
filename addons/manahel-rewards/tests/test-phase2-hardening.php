<?php
$root = dirname(__DIR__);
$processor = file_get_contents($root . '/includes/class-manahel-rewards-event-processor.php');
$admin = file_get_contents($root . '/admin/class-manahel-rewards-admin.php');
$main = file_get_contents($root . '/manahel-rewards.php');
$fail=[];
function chk($v,$m){global $fail; echo ($v?'PASS: ':'FAIL: ').$m."\n"; if(!$v)$fail[]=$m;}

$pos_get = strpos($processor, 'Manahel_Rewards_Account_Service::get_or_create($wp_user_id)');
$pos_begin = strpos($processor, 'Manahel_Rewards_DB::begin()', $pos_get ?: 0);
$pos_lock = strpos($processor, 'Manahel_Rewards_Account_Service::lock($account_id)', $pos_begin ?: 0);
chk(false !== $pos_get && false !== $pos_begin && false !== $pos_lock && $pos_get < $pos_begin && $pos_begin < $pos_lock,
    'account shell is resolved before transaction and FOR UPDATE is first account read inside transaction');
chk(false !== strpos($processor, "'decision_code' => \$code") && false !== strpos($processor, "'receipt_replay'"),
    'duplicate receipt audit is persisted in decision_json');
chk(false !== strpos($processor, "'limit.unique_entity'") && false !== strpos($processor, "'duplicate_event'"),
    'duplicate replay distinguishes previously-awarded entity from non-awarded duplicate');
chk(false !== strpos($admin, "تکرار دریافت") && false !== strpos($admin, "'duplicate_event'"),
    'Events admin exposes duplicate receipt audit');
chk(false !== strpos($main, "MANAHEL_REWARDS_VERSION', '0.8.11'") && false !== strpos($main, "MANAHEL_REWARDS_DB_VERSION', '1.3.0'"),
    'Phase 2 hardening remains present in 0.8.11 with schema baseline 1.3.0');
if($fail){fwrite(STDERR,count($fail)." test(s) failed.\n"); exit(1);} echo "All Phase 2 hardening source-contract tests passed.\n";
