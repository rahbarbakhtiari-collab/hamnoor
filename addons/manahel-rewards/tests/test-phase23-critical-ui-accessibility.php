<?php
$root=dirname(__DIR__);
$css=file_get_contents($root.'/assets/css/frontend.css');
$js=file_get_contents($root.'/assets/js/reward-catalog.js');
$main=file_get_contents($root.'/manahel-rewards.php');
$pass=0;$fail=0;
function p23($ok,$m){global $pass,$fail;if($ok){$pass++;echo "[PASS] $m\n";}else{$fail++;echo "[FAIL] $m\n";}}
p23(strpos($main,'Version: 0.8.11')!==false && strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
p23(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB schema remains 1.3.0');
p23(strpos($css,'font-size:9px')===false,'no 9px frontend text remains');
p23(strpos($css,'font-size:10px')===false,'no 10px frontend text remains');
p23(strpos($css,'#98a2b3')===false,'low-contrast #98a2b3 removed');
p23(strpos($css,'.mr-reward-filters button{min-height:44px')!==false,'reward filters are at least 44px');
p23(strpos($css,'.mr-feedback-toast__close{position:absolute;inset-inline-start:6px;top:4px;width:44px;height:44px')!==false,'toast close target is 44x44');
p23(strpos($css,'.mr-redeem-dialog__close{position:absolute;inset-inline-start:8px;top:8px;width:44px;height:44px')!==false,'dialog close target is 44x44');
p23(strpos($js,'function focusables()')!==false && strpos($js,"e.key!=='Tab'")!==false,'dialog keyboard focus trap present');
p23(strpos($js,'lastFocus')!==false && strpos($js,'lastFocus.focus()')!==false,'dialog restores trigger focus');
p23(strpos($js,"if(e.key==='Escape')")!==false,'Escape closes dialog');
echo "SUMMARY PASS=$pass FAIL=$fail\n";
exit($fail?1:0);
