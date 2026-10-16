<?php
$root=dirname(__DIR__);$fails=[];
function ht($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/hamnoor.php');
$router=file_get_contents($root.'/includes/class-hamnoor-router.php');
$template=file_get_contents($root.'/templates/block/hamnoor-campaign.html');
ht(false!==strpos($main,"HAMNOOR_VERSION', '4.0.19'"),'Hamnoor version is 4.0.19');
ht(false!==strpos($router,"register_block_template('hamnoor//hamnoor-campaign'"),'campaign block template is plugin-registered');
ht(false!==strpos($router,"locate_block_template(\$php_template, 'hamnoor-campaign'"),'virtual campaign forces dedicated block template');
ht(false!==strpos($router,'$wp_query->is_home = false'),'virtual campaign clears is_home');
ht(false!==strpos($router,'$wp_query->is_archive = false'),'virtual campaign clears archive state');
ht(false!==strpos($router,'$wp_query->is_page = true'),'virtual campaign remains singular page-like request');
ht(false!==strpos($router,"add_filter('body_class', array(\$this, 'body_class'))"),'theme-compatible campaign registers body class filter');
ht(false!==strpos($router,"\$classes[] = 'hamnoor-public-khatm-page'"),'theme-compatible campaign receives public campaign body class');
ht(false!==strpos($template,'"slug":"header"'),'registered template keeps theme Header');
ht(false!==strpos($template,'"slug":"footer"'),'registered template keeps theme Footer');
ht(false!==strpos($template,'wp:post-content'),'registered template renders virtual Hamnoor content');
ht(false===strpos($template,'wp:post-title'),'registered template does not duplicate campaign title');
ht(false===strpos($template,'wp:query-title'),'registered template does not render Blog/query title');
if($fails){fwrite(STDERR,count($fails)." theme-compatible campaign template check(s) failed.\n");exit(1);} echo "All Hamnoor 4.0.19 campaign template contract checks passed.\n";
