<?php
$root=dirname(__DIR__);$fails=[];
function hx($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/hamnoor.php');
$front=file_get_contents($root.'/includes/class-hamnoor-frontend.php');
$dash=file_get_contents($root.'/templates/frontend/dashboard.php');
$mobile=file_get_contents($root.'/assets/js/hamnoor-mobile.js');
hx(strpos($main,"HAMNOOR_VERSION', '4.0.19'")!==false,'Hamnoor version is 4.0.19');
hx(strpos($front,"apply_filters('hamnoor_dashboard_tabs'")!==false,'generic dashboard tab allowlist filter exists');
hx(strpos($dash,"apply_filters('hamnoor_dashboard_groups'")!==false,'generic dashboard group filter exists');
hx(strpos($dash,"do_action('hamnoor_dashboard_extension_panels'")!==false,'generic extension panel action exists');
hx(strpos($dash,"do_action('hamnoor_mobile_more_extension'")!==false,'generic mobile More extension action exists');
hx(strpos($dash,'$custom_href')!==false,'dashboard tabs may expose canonical hrefs');
hx(strpos($mobile,'data-hamnoor-panel-title')!==false,'mobile title resolves extension panel label');
hx(strpos($mobile,'data-hamnoor-more-tab')!==false,'mobile More can mark extension tab active');
if($fails){fwrite(STDERR,count($fails)." dashboard extension check(s) failed.\n");exit(1);} echo "All Hamnoor 4.0.19 dashboard extension navigation checks passed.\n";
