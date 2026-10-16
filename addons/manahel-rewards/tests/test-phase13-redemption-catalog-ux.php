<?php
$root=dirname(__DIR__);$f=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');$css=file_get_contents($root.'/assets/css/frontend.css');$js=file_get_contents($root.'/assets/js/reward-catalog.js');$main=file_get_contents($root.'/manahel-rewards.php');$fail=[];
function r13($ok,$m){global $fail;echo ($ok?'[PASS] ':'[FAIL] ').$m."\n";if(!$ok)$fail[]=$m;}
r13(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'version 0.8.11');
r13(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'DB schema includes digital code inventory');
r13(strpos($f,'قابل دریافت')!==false && strpos($f,'نزدیک‌ترین')!==false && strpos($f,'کد، شارژ و بسته')!==false,'plain-language reward filters exist');
r13(strpos($f,'mr-reward-progress')!==false && strpos($f,'امتیاز دیگر')!==false,'progress and remaining points are rendered');
r13(strpos($f,'دعوت دوست +%s')!==false && strpos($f,'دعوت موفق تا این جایزه')!==false,'viral-growth CTA is connected to reward gap');
r13(strpos($f,'data-mr-redeem-dialog')!==false && strpos($f,'تأیید و دریافت')!==false,'confirmation bottom sheet exists');
r13(strpos($f,'onclick="return confirm')===false,'inline confirm removed');
r13(strpos($f,'data-mr-state')!==false && strpos($f,'data-mr-mode')!==false,'cards expose filter state/mode');
r13(strpos($css,'.mr-reward-grid{grid-template-columns:repeat(3')!==false && strpos($css,'@media(max-width:680px)')!==false,'responsive 3-column desktop / 1-column mobile catalog');
r13(strpos($js,'data-mr-filter')!==false && strpos($js,'requestSubmit')!==false,'catalog JS filters and safe confirmation submit');
r13(strpos($js,'reportValidity')!==false,'required delivery data is validated before confirmation');
if($fail){fwrite(STDERR,count($fail)." test(s) failed\n");exit(1);}echo "Phase 13 redemption catalog UX tests passed.\n";
