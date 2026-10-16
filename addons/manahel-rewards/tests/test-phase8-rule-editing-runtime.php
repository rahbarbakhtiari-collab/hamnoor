<?php
define('ABSPATH', __DIR__ . '/');
function get_option($name, $default='') { return 'manahel_rewards_policy_timezone' === $name ? 'Asia/Tehran' : $default; }
function wp_timezone_string(){ return 'Asia/Tehran'; }
function wp_unslash($v){ return $v; }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }

require_once dirname(__DIR__) . '/includes/class-manahel-rewards-limit-evaluator.php';
require_once dirname(__DIR__) . '/admin/class-manahel-rewards-admin.php';

$fail = array();
function p8r($ok, $msg){ global $fail; echo ($ok?'PASS: ':'FAIL: ').$msg."\n"; if(!$ok)$fail[]=$msg; }
$ref = new ReflectionClass('Manahel_Rewards_Admin');
$toUtc = $ref->getMethod('admin_datetime_to_utc_mysql');
$toLocal = $ref->getMethod('utc_mysql_to_admin_datetime');
$valid = $ref->getMethod('limits_valid_for_points');
$priority = $ref->getMethod('sanitize_priority');
$truncate = $ref->getMethod('truncate_text');

// 2026-08-26 is in Iran DST-free +03:30.
p8r('2026-08-26 16:30:00' === $toUtc->invoke(null, '2026-08-26T20:00'), 'policy-local Tehran datetime converts to UTC');
p8r('2026-08-26T20:00' === $toLocal->invoke(null, '2026-08-26 16:30:00'), 'stored UTC converts back to policy-local datetime');
p8r(false === $toUtc->invoke(null, '2026-99-99T99:99'), 'invalid datetime is rejected');
p8r('' === $toUtc->invoke(null, ''), 'blank datetime remains unbounded');

$limits = Manahel_Rewards_Limit_Evaluator::sanitize_limits(array('max_points_per_day'=>9,'max_points_per_month'=>30));
p8r(true === $valid->invoke(null, $limits, 3), 'valid point caps accept one full award');
p8r(false === $valid->invoke(null, $limits, 10), 'daily point cap below one award is rejected');
$limits2 = Manahel_Rewards_Limit_Evaluator::sanitize_limits(array('max_points_per_day'=>0,'max_points_per_month'=>5));
p8r(false === $valid->invoke(null, $limits2, 10), 'monthly point cap below one award is rejected');
p8r(100000 === $priority->invoke(null, 999999999), 'priority is upper-clamped');
p8r(-100000 === $priority->invoke(null, -999999999), 'priority is lower-clamped');
$title = str_repeat('الف', 200);
$truncated = $truncate->invoke(null, $title, 190); preg_match_all('/./us', $truncated, $chars); p8r(190 === count($chars[0]) && false !== preg_match('//u', $truncated), 'Persian title truncates safely to schema length without requiring mbstring');

if($fail){fwrite(STDERR,count($fail)." test(s) failed.\n"); exit(1);} echo "All Phase 8 rule-editing runtime helper tests passed.\n";
