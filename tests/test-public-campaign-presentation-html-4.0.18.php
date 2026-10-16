<?php
$root=dirname(__DIR__);$fails=[];
function hp($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/hamnoor.php');
$css=file_get_contents($root.'/assets/css/hamnoor-frontend.css');
$desktop=file_get_contents($root.'/assets/css/hamnoor-desktop.css');
$front=file_get_contents($root.'/includes/class-hamnoor-frontend.php');
$contrib=file_get_contents($root.'/includes/class-hamnoor-contribution-frontend.php');
$db=file_get_contents($root.'/includes/class-hamnoor-db.php');
$core=file_get_contents($root.'/includes/core/domain/class-hamnoor-command-contracts.php');
$rest=file_get_contents($root.'/includes/class-hamnoor-rest-api.php');
hp(false!==strpos($main,"HAMNOOR_VERSION', '4.0.19'"),'Hamnoor version is 4.0.19');
hp(false!==strpos($css,'body.hamnoor-public-khatm-page .hamnoor-wrap .hamnoor-khatm-header'),'public campaign hero CSS is narrowly scoped');
hp(false!==strpos($css,'grid-template-columns: minmax(260px, 38%) minmax(0, 1fr)'),'desktop public campaign gives the image a proportional hero column');
hp(false!==strpos($css,'aspect-ratio: auto;') && false!==strpos($css,'object-fit: contain;'),'public campaign cover preserves its natural aspect ratio');
hp(false!==strpos($css,'font-size: clamp(1.35rem, 2.15vw, 1.85rem)'),'public campaign H1 typography is reduced only in campaign context');
hp(false!==strpos($desktop,'aspect-ratio: 1200 / 630;') && false!==strpos($desktop,'max-height: none;'),'desktop campaign-card media preserves the 1200x630 banner ratio without height clipping');
hp(false!==strpos($desktop,'object-fit: contain;'),'desktop campaign-card image is contained rather than cropped');
hp(false!==strpos($front,'<div class="hamnoor-intention">\' . wp_kses_post($khatm->intention) . \'</div>'),'Quran campaign intention permits safe block HTML at render');
hp(false!==strpos($contrib,'<div class="hamnoor-intention"><?php echo wp_kses_post((string)$campaign->intention); ?></div>'),'contribution campaign intention permits safe block HTML at render');
hp(false!==strpos($db, "wp_kses_post(wp_unslash(\$source['intention']))"),'legacy save path sanitizes intention with wp_kses_post');
hp(false!==strpos($core,"'intention'=>wp_kses_post"),'core command contract sanitizes intention with wp_kses_post');
hp(false!==strpos($rest,"'intention'=>array('type'=>'string','sanitize_callback'=>'wp_kses_post')"),'REST intention input sanitizes with wp_kses_post');
if($fails){fwrite(STDERR,count($fails)." public campaign presentation/HTML check(s) failed.\n");exit(1);}
echo "All Hamnoor 4.0.19 public campaign presentation/HTML checks passed.\n";
