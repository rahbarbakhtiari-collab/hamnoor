<?php
define('ABSPATH', __DIR__.'/');
function get_option($name,$default=''){ return 'manahel_rewards_policy_timezone'===$name?'Asia/Tehran':$default; }
function wp_timezone_string(){return 'Asia/Tehran';}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function __($s,$d=null){return $s;}
function esc_html($s){return (string)$s;}
require_once dirname(__DIR__).'/includes/class-manahel-rewards-limit-evaluator.php';
require_once dirname(__DIR__).'/admin/class-manahel-rewards-admin.php';
$ref=new ReflectionClass('Manahel_Rewards_Admin'); $fail=[];
function p9r($ok,$m){global $fail;echo($ok?'PASS: ':'FAIL: ').$m."\n";if(!$ok)$fail[]=$m;}
$cd=$ref->getMethod('cooldown_ui_parts');
p9r(['preset'=>'week','value'=>1,'unit'=>'hours']===$cd->invoke(null,604800),'7-day cooldown maps to simple preset');
p9r(['preset'=>'custom','value'=>2,'unit'=>'days']===$cd->invoke(null,172800),'custom exact day duration stays lossless');
p9r(['preset'=>'custom','value'=>90,'unit'=>'seconds']===$cd->invoke(null,90),'non-round legacy seconds stay lossless');
$dur=$ref->getMethod('human_duration');
p9r('7 روز'===$dur->invoke(null,604800),'duration renders as human Persian days');
$title=$ref->getMethod('rule_display_title');
$r=(object)['code'=>'HAMNOOR_QURAN_PART_COMPLETED','title'=>'تکمیل سهم عمومی قرآن'];
p9r('خواندن و تکمیل یک سهم قرآن'===$title->invoke(null,$r),'legacy default title is presented in plain language without migration');
$r2=(object)['code'=>'HAMNOOR_QURAN_PART_COMPLETED','title'=>'عنوان سفارشی مدیر'];
p9r('عنوان سفارشی مدیر'===$title->invoke(null,$r2),'administrator custom title is preserved');
$summary=$ref->getMethod('rule_effect_summary');
$r3=(object)['code'=>'HAMNOOR_QURAN_PART_COMPLETED','title'=>'تکمیل سهم عمومی قرآن','points'=>10];
$limits=Manahel_Rewards_Limit_Evaluator::sanitize_limits(['unique_per_entity'=>true,'max_awards_per_day'=>6,'max_points_per_day'=>60]);
$text=$summary->invoke(null,$r3,$limits);
p9r(false!==strpos($text,'10 امتیاز') && false!==strpos($text,'6 بار در روز') && false!==strpos($text,'60 امتیاز در روز'),'effect summary explains points and daily caps');
if($fail){fwrite(STDERR,count($fail)." test(s) failed.\n");exit(1);}echo "All Phase 9 admin UX runtime helper tests passed.\n";
