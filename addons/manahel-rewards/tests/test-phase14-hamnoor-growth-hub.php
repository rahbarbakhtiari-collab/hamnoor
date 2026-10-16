<?php
$root=dirname(__DIR__);$fails=[];
function p14($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/manahel-rewards.php');
$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
$integration=file_get_contents($root.'/includes/class-manahel-rewards-hamnoor-dashboard-integration.php');
$css=file_get_contents($root.'/assets/css/frontend.css');
p14(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'Rewards version is 0.8.11');
p14(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'Rewards DB remains 1.3.0');
p14(strpos($front,'render_growth_hub')!==false,'unified growth hub renderer exists');
p14(strpos($front,"'points' => array('label' => __('امتیازهای من'")!==false,'Points tab exists');
p14(strpos($front,"'rewards' => array('label' => __('جوایز'")!==false,'Rewards tab exists');
p14(strpos($front,"'invite' => array('label' => __('دعوت دوستان'")!==false,'Invite tab exists');
p14(strpos($front,"'history' => array('label' => __('تاریخچه'")!==false,'History tab exists');
p14(strpos($front,"'tab'=>'growth'")!==false && strpos($front,"'section'=>$section")!==false,'hub URLs live under Hamnoor base query navigation');
p14(strpos($integration,"add_filter('hamnoor_dashboard_tabs'")!==false,'Rewards registers Hamnoor growth tab');
p14(strpos($integration,"add_filter('hamnoor_dashboard_groups'")!==false,'Rewards registers logical growth group');
p14(strpos($integration,"add_action('hamnoor_dashboard_extension_panels'")!==false,'Rewards renders inside Hamnoor');
p14(strpos($integration,"add_action('hamnoor_mobile_more_extension'")!==false,'mobile More exposes growth hub');
p14(strpos($integration,"add_filter('manahel_referral_my_referrals_url'")!==false,'Referral links route into unified Invite tab');
p14(strpos($integration,"remove_action('hamnoor_dashboard_summary_extension'")!==false,'duplicate Referral teaser is merged when Rewards is active');
p14(strpos($css,'.mr-growth-tabs')!==false && strpos($css,'grid-template-columns:repeat(4')!==false,'four-tab hub is styled');
p14(strpos($css,'@media (max-width:782px)')!==false,'hub is responsive for mobile/Eitaa');
p14(strpos($front,'$wpdb->insert(')===false && strpos($front,'$wpdb->update(')===false,'hub rendering stays read-only');
if($fails){fwrite(STDERR,count($fails)." Phase 14 check(s) failed.\n");exit(1);} echo "All Phase 14 Hamnoor Growth Hub checks passed.\n";
