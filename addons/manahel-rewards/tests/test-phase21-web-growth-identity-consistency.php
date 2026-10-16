<?php
$root=dirname(__DIR__);
$main=file_get_contents($root.'/manahel-rewards.php');
$i=file_get_contents($root.'/includes/class-manahel-rewards-hamnoor-dashboard-integration.php');
$f=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
function p21($ok,$label){echo ($ok?'PASS':'FAIL')." | ".$label."\n";if(!$ok){$GLOBALS['p21_fail']=1;}}
p21(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
p21(strpos($i,'request_prefers_hamnoor_identity')!==false,'explicit Hamnoor identity-preference helper exists');
p21(strpos($i,"return 'growth' === \$tab")!==false,'Growth Hub automatically prefers Hamnoor domain identity');
p21(strpos($i,"isset(\$_POST['mr_context'])")!==false,'POST redemption can preserve Hamnoor identity context');
p21(strpos($f,'name="mr_context" value="hamnoor"')!==false,'Growth reward form carries Hamnoor context marker');
p21(strpos($i,'if ($wp_user_id > 0 && !$prefer_hamnoor) { return $wp_user_id; }')!==false,'standalone WordPress precedence is preserved outside Hamnoor context');
exit(!empty($GLOBALS['p21_fail'])?1:0);
