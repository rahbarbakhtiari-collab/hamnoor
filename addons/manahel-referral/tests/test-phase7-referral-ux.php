<?php
$root=dirname(__DIR__);$fails=[];
function p7r($ok,$label){global $fails;if($ok){echo "PASS: $label\n";}else{$fails[]=$label;echo "FAIL: $label\n";}}
$main=file_get_contents($root.'/manahel-referral.php');
$front=file_get_contents($root.'/frontend/class-manahel-referral-frontend.php');
$sum=file_get_contents($root.'/includes/class-manahel-referral-user-summary-service.php');
$dash=file_get_contents($root.'/includes/class-manahel-referral-hamnoor-dashboard-integration.php');
$admin=file_get_contents($root.'/admin/class-manahel-referral-admin.php');
$js=file_get_contents($root.'/assets/js/frontend.js');
$installer=file_get_contents($root.'/includes/class-manahel-referral-installer.php');
p7r(false!==strpos($main,"Version: 0.3.2"),'Referral version 0.3.2');
p7r(false!==strpos($main,"MANAHEL_REFERRAL_DB_VERSION', '1.0.0"),'Referral DB remains 1.0.0');
p7r(false!==strpos($front,"manahel_referral_my_referrals"),'My Referrals shortcode exists');
p7r(false!==strpos($dash,"mref_context"),'Hamnoor-context identity link supported');
p7r(false!==strpos($dash,"hamnoor_dashboard_summary_extension"),'Existing generic Hamnoor dashboard hook consumed');
p7r(false===strpos($dash,'Hamnoor_Mobile_Access::is_verified') && false===strpos($dash,'برای دریافت امتیاز دعوت'),'Dashboard does not require OTP before earning');
p7r(false!==strpos($front,'تأیید شماره برای کسب امتیاز لازم نیست'),'Frontend explains participation-first earning');
p7r(false===strpos($dash,'Manahel_Rewards_') && false===strpos($sum,'manahel_reward_'),'Referral does not depend on Rewards classes/tables');
p7r(false!==strpos($sum,"manahel_referral_financial_summary"),'Financial UX uses optional filter contract');
p7r(false!==strpos($sum,"qualifies_until < %s"),'Expired view classification handles silent expiry');
p7r(false!==strpos($admin,"manahel_referral_my_referrals_page_id") || false!==strpos($admin,'OPTION_MY_REFERRALS_PAGE_ID'),'Admin page selection exists');
p7r(false!==strpos($js,'navigator.share'),'Web Share API used');
p7r(false!==strpos($js,'navigator.clipboard'),'Copy fallback used');
p7r(false===stripos($front,'referred_wp_user_id'),'Frontend never renders referred user id');
p7r(3===substr_count($installer,'CREATE TABLE'),'No new Referral table added in v0.3.1');
if($fails){fwrite(STDERR,count($fails)." test(s) failed.\n");exit(1);}echo "All Referral UX source tests passed.\n";
