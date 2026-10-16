<?php
$root=dirname(__DIR__);
$main=file_get_contents($root.'/manahel-rewards.php');
$css=file_get_contents($root.'/assets/css/frontend.css');
$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
$pass=0;$fail=0;
function p16($ok,$msg){global $pass,$fail;if($ok){$pass++;echo "[PASS] $msg\n";}else{$fail++;echo "[FAIL] $msg\n";}}
p16(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version is 0.8.11');
p16(strpos($front,'data-mr-growth-section')!==false,'Growth Hub exposes section scoping hook');
p16(strpos($css,'.mr-growth-hub[data-mr-growth-section="invite"] .mr-growth-hub__content{padding-inline:6px}')!==false,'invite content gets compact side gutter');
p16(strpos($css,'.mr-growth-hub[data-mr-growth-section="invite"] .manahel-referral-app')!==false && strpos($css,'padding:0!important')!==false,'nested Referral app padding is removed inside hub');
p16(strpos($css,'.mr-growth-hub[data-mr-growth-section="invite"] .mrf-hero>strong')!==false && strpos($css,'font-size:18px')!==false,'invite hero heading is mobile-sized');
p16(strpos($css,'.mr-growth-hub[data-mr-growth-section="invite"] .mrf-hero p')!==false && strpos($css,'font-size:13px')!==false,'invite explanatory copy is compact');
p16(strpos($css,'.mr-growth-hub[data-mr-growth-section="invite"] .manahel-referral-invite--inside')!==false && strpos($css,'padding:12px')!==false,'inner invite card is compact');
p16(strpos($css,'@media (max-width:380px)')!==false && strpos($css,'padding-inline:4px')!==false,'very narrow screens get 4px safe gutter');
p16(strpos($css,'.manahel-referral-app{max-width:780px')===false,'Rewards CSS does not rewrite standalone Referral base rule');
echo "PASS=$pass FAIL=$fail\n"; exit($fail?1:0);
