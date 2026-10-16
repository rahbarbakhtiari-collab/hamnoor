<?php
$root=dirname(__DIR__);
$css=file_get_contents($root.'/assets/css/frontend.css');
$main=file_get_contents($root.'/manahel-referral.php');
$pass=0;$fail=0;
function p8($ok,$m){global $pass,$fail;if($ok){$pass++;echo "[PASS] $m\n";}else{$fail++;echo "[FAIL] $m\n";}}
p8(strpos($main,'Version: 0.3.2')!==false && strpos($main,"MANAHEL_REFERRAL_VERSION', '0.3.2'")!==false,'version 0.3.2');
p8(strpos($main,"MANAHEL_REFERRAL_DB_VERSION', '1.0.0'")!==false,'DB schema remains 1.0.0');
p8(strpos($css,'data-hamnoor-ui-contract~="v1"')!==false,'shared UI v1 bridge is opt-in');
p8(strpos($css,'--mrf-primary:#225f9e')!==false,'default primary preserved');
p8(strpos($css,'--mrf-muted:#526274')!==false,'default muted preserved');
p8(strpos($css,'--mrf-border:#dbe6f5')!==false,'default border preserved');
p8(strpos($css,'--mrf-surface:#fff')!==false,'default surface preserved');
p8(strpos($css,'--mrf-focus-ring:rgba(34,95,158,.24)')!==false,'default focus ring preserved');
p8(strpos($css,'--mrf-primary: var(--hamnoor-primary, #225f9e)')!==false,'primary bridge available');
p8(strpos($css,'--mrf-border: var(--hamnoor-border, #dbe6f5)')!==false,'border bridge preserves Referral fallback');
p8(strpos($css,'--mrf-touch-min: var(--hamnoor-touch-min, 44px)')!==false,'touch token bridge available');
p8(strpos($css,'--mrf-radius-card: var(--hamnoor-radius-card, 16px)')!==false,'card radius bridge available');
p8(strpos($css,'background:var(--mrf-primary)')!==false && strpos($css,'border:1px solid var(--mrf-border)')!==false,'safe local aliases are consumed');
echo "SUMMARY PASS=$pass FAIL=$fail\n";
exit($fail?1:0);
