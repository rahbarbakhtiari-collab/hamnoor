<?php
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/admin/class-manahel-rewards-admin.php');
$main = file_get_contents($root . '/manahel-rewards.php');
$css = file_get_contents($root . '/admin/assets/manahel-rewards-admin.css');
$js = file_get_contents($root . '/admin/assets/manahel-rewards-admin-rules.js');
$fail = array();
function p9($ok,$m){global $fail; echo ($ok?'PASS: ':'FAIL: ').$m."\n"; if(!$ok)$fail[]=$m;}
p9(false!==strpos($main,'Version: 0.8.11') && false!==strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'"),'version is 0.8.11');
p9(false!==strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'"),'redemption + digital code inventory schema baseline is 1.3.0');
p9(false!==strpos($admin,"add_action('admin_enqueue_scripts'") && false!==strpos($admin,'manahel-rewards-admin.css'),'admin UX assets are scoped/enqueued');
p9(false!==strpos($admin,'امتیاز فعالیت‌ها') && false!==strpos($admin,'گزارش فعالیت‌های امتیازی') && false!==strpos($admin,'تاریخچه افزایش و کاهش امتیاز') && false!==strpos($admin,'امتیاز کاربران'),'technical menu names are humanized');
p9(false!==strpos($admin,'mr-rule-grid') && false!==strpos($admin,'mr-rule-card') && false!==strpos($admin,'فعالیت‌های هم‌نور') && false!==strpos($admin,'دعوت دوستان'),'rules use grouped card UX');
p9(false!==strpos($admin,'امتیاز هر بار') && false!==strpos($admin,'حداکثر دفعات دریافت در روز') && false!==strpos($admin,'حداکثر امتیاز در ماه') && false!==strpos($admin,'فاصله تا دریافت دوباره'),'primary fields use plain-language labels');
p9(false!==strpos($admin,'اطلاعات فنی — فقط برای توسعه‌دهنده') && false!==strpos($admin,'ابزار توسعه‌دهنده: افزودن قانون سفارشی'),'technical surfaces are collapsed and developer-labelled');
p9(false!==strpos($admin,'type="hidden" name="priority"') && false===strpos($admin,'<tr><th><?php esc_html_e(\'اولویت\', \'manahel-rewards\')'),'priority is hidden from normal edit form');
p9(false!==strpos($admin,'cooldown_preset') && false!==strpos($admin,'cooldown_custom_value') && false!==strpos($admin,'cooldown_seconds_from_request'),'cooldown uses human presets with server-side parser');
p9(false!==strpos($admin,'rule_effect_summary') && false!==strpos($js,'mr-live-summary'),'human effect summary is server-rendered and live-updated');
p9(false!==strpos($admin,'Deliberately immutable here: code, source, event_name, entity_type, conditions_json'),'technical rule identity remains immutable');
p9(false!==strpos($css,'.mr-rule-card') && false!==strpos($css,'@media(max-width:782px)'),'responsive admin cards exist');
p9(false!==strpos($js,"'use strict'") && false!==strpos($js,'refreshCooldown') && false!==strpos($js,'refreshSummary'),'admin JS is minimal and focused');
if($fail){fwrite(STDERR,count($fail)." test(s) failed.\n");exit(1);} echo "All Phase 9 admin UX contract tests passed.\n";
