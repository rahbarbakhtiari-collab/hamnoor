<?php
$root=dirname(__DIR__);$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');$css=file_get_contents($root.'/assets/css/frontend.css');$main=file_get_contents($root.'/manahel-rewards.php');$fail=[];
function p15($ok,$m){global $fail;echo($ok?'[PASS] ':'[FAIL] ').$m.PHP_EOL;if(!$ok)$fail[]=$m;}
p15(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version is 0.8.11');
p15(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB stays 1.3.0');
p15(strpos($front,"'short' => __('امتیاز'")!==false && strpos($front,"'short' => __('دعوت'")!==false,'mobile-short tab labels exist');
p15(strpos($front,'mr-growth-tab__full')!==false && strpos($front,'mr-growth-tab__short')!==false,'responsive tab label spans render');
p15(strpos($css,'.hamnoor-wrap .hamnoor-dashboard-panel[data-tab-panel="growth"]{padding-inline:0!important')!==false,'mobile host-panel side padding is removed for Growth Hub');
p15(strpos($css,'.mr-growth-hub__content{padding-inline:10px}')!==false,'content keeps only compact safe gutter');
p15(substr_count($css,'grid-template-columns:repeat(4,minmax(0,1fr))')>=2,'mobile tabs use four fixed equal columns');
p15(strpos($css,'.mr-growth-tabs a.is-active::after{background:#1683df}')!==false,'active tab has a strong underline');
p15(strpos($css,'.mr-growth-tab__full{display:none}')!==false && strpos($css,'.mr-growth-tab__short{display:inline}')!==false,'short labels are used on mobile');
exit($fail?1:0);
