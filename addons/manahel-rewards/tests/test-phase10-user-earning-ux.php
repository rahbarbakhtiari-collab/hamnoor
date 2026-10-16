<?php
$root=dirname(__DIR__);
$main=file_get_contents($root.'/manahel-rewards.php');
$front=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');
$css=file_get_contents($root.'/assets/css/frontend.css');
$dash=file_get_contents($root.'/includes/class-manahel-rewards-hamnoor-dashboard-integration.php');
$fails=0;
function p10($ok,$msg){global $fails; echo ($ok?'PASS: ':'FAIL: ').$msg.PHP_EOL; if(!$ok)$fails++;}
p10(false!==strpos($main,'Version: 0.8.11') && false!==strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'"),'version is 0.8.11');
p10(false!==strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'"),'DB schema baseline now 1.3.0');
p10(false!==strpos($front,'چطور امتیاز بگیرم؟'),'plain-language earning heading exists');
p10(false!==strpos($front,'خواندن یک سهم قرآن') && false!==strpos($front,'خواندن یک دعا') && false!==strpos($front,'تکمیل قرآن شخصی'),'personal earning activity names remain plain-language');
p10(false===strpos($front,'شماره موبایل حساب هم‌نور باید تأیید شده باشد') && false===strpos($front,'manahel_rewards_identity_verification_url'),'current earning UX has no OTP prerequisite or verification CTA');
p10(false!==strpos($front,'manahel_referral_get_my_referrals_url') && false!==strpos($front,'manahel_referral_get_compact_user_summary'),'Referral UX uses optional public Referral APIs');
p10(false!==strpos($front,'MANAHEL_REFERRAL_QUALIFIED_INVITER') && false!==strpos($front,'MANAHEL_REFERRAL_QUALIFIED_REFERRED') && false!==strpos($front,'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT'),'Referral card reads all growth-first reward rules');
p10(false!==strpos($front,'بیشترین امتیاز برای جذب واقعی است') && false!==strpos($front,'کاربر موجود را از لینک پویش به مشارکت واقعی برسانید'),'Referral card leads with growth and campaign activation');
p10(false!==strpos($front,'mr-policy-card__cta') && false!==strpos($front,'دعوت دوستان'),'Referral CTA exists');
$posReferral=strpos($front,'self::referral_card'); $posCards=strpos($front,"self::policy_card('quran_part'");
p10(false!==$posReferral && false!==$posCards && $posReferral<$posCards,'growth/referral card is rendered before personal-activity cards');
p10(false!==strpos($dash,'امتیاز، جوایز و دعوت'),'Hamnoor dashboard CTA leads to the unified growth hub');
p10(false!==strpos($css,'.mr-policy-card--referral') && false!==strpos($css,'@media(max-width:680px)'),'growth-first user UX is styled and responsive');
p10(false===strpos($front,'$wpdb->insert(') && false===strpos($front,'$wpdb->update(') && false===strpos($front,'$wpdb->delete('),'My Points UX remains read-only');
if($fails){fwrite(STDERR,$fails." Phase 10 check(s) failed.\n"); exit(1);} echo "All Phase 10 growth-first user earning UX checks passed.\n";
