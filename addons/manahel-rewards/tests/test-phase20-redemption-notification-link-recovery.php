<?php
$root=dirname(__DIR__);$b=file_get_contents($root.'/includes/class-manahel-rewards-redemption-notification-bridge.php');$f=file_get_contents($root.'/frontend/class-manahel-rewards-frontend.php');$fail=0;$pass=0;
function p20($ok,$m){global $fail,$pass;if($ok){$pass++;echo "[PASS] $m\n";}else{$fail++;fwrite(STDERR,"[FAIL] $m\n");}}
p20(strpos($b,"WHERE wp_user_id=%d AND status='active'")!==false,'bridge resolves authoritative Hamnoor user by wp_user_id');
p20(strpos($b,'update_user_meta($wp_user_id,$key,$domain_id)')!==false,'bridge repairs missing/stale Hamnoor user meta');
p20(strpos($b,"manahel_rewards_last_redemption_notification_error")!==false,'bridge records silent notification failures');
p20(strpos($b,"hamnoor_user_link_missing")!==false && strpos($b,"hamnoor_add_notification_failed")!==false,'bridge distinguishes mapping and notification-store failures');
p20(strpos($f,'برای ارسال کد تلاش می‌کند')!==false,'frontend no longer claims messenger delivery succeeded before evidence');
if($fail){fwrite(STDERR,"$fail phase20 check(s) failed.\n");exit(1);}echo "Phase20 redemption notification recovery contract passed ($pass).\n";
