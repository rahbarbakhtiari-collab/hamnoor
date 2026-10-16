<?php
$root=dirname(__DIR__);
$css=file_get_contents($root.'/assets/css/frontend.css');
$main=file_get_contents($root.'/manahel-rewards.php');
$pass=0;$fail=0;
function p24($ok,$m){global $pass,$fail;if($ok){$pass++;echo "[PASS] $m\n";}else{$fail++;echo "[FAIL] $m\n";}}
p24(strpos($main,'Version: 0.8.11')!==false && strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
p24(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB schema remains 1.3.0');
p24(strpos($css,'data-hamnoor-ui-contract~="v1"')!==false,'shared UI v1 bridge is opt-in');
p24(strpos($css,'--mr-primary: var(--hamnoor-primary, #1F63C3)')!==false,'primary alias available');
p24(strpos($css,'--mr-text: var(--hamnoor-text, #172033)')!==false,'text alias available');
p24(strpos($css,'--mr-muted: var(--hamnoor-muted, #667085)')!==false,'muted alias available');
p24(strpos($css,'--mr-border: var(--hamnoor-border, #e5eaf1)')!==false,'border bridge preserves current Rewards fallback');
p24(strpos($css,'--mr-bg: var(--hamnoor-bg, #f7f9fc)')!==false,'background bridge preserves current Rewards fallback');
p24(strpos($css,'--mr-success: var(--hamnoor-success, #0f7a45)')!==false,'success bridge preserves current Rewards fallback');
p24(strpos($css,'--mr-warn: var(--hamnoor-warning, #8a5a00)')!==false,'warning bridge preserves current Rewards fallback');
p24(strpos($css,'--mr-touch-min: var(--hamnoor-touch-min, 44px)')!==false,'touch target token bridge available');
p24(strpos($css,'--mr-radius-card: var(--hamnoor-radius-card, 16px)')!==false && strpos($css,'--mr-radius-large: var(--hamnoor-radius-large, 24px)')!==false,'radius token bridge available');
// Default/current Rewards root must retain its exact visual values until host opt-in.
p24(strpos($css,'.manahel-rewards-app{--mr-primary:#1F63C3;--mr-primary-soft:#eef5ff;--mr-text:#172033;--mr-muted:#667085;--mr-border:#e5eaf1;--mr-bg:#f7f9fc;--mr-success:#0f7a45;--mr-warn:#8a5a00;')===0,'default visual token values preserved');
echo "SUMMARY PASS=$pass FAIL=$fail\n";
exit($fail?1:0);
