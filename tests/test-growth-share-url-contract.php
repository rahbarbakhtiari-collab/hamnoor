<?php
$root=dirname(__DIR__);$fails=[];
function hg($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/hamnoor.php');
$helpers=file_get_contents($root.'/includes/class-hamnoor-helpers.php');
$front=file_get_contents($root.'/includes/class-hamnoor-frontend.php');
$contrib=file_get_contents($root.'/includes/class-hamnoor-contribution-frontend.php');
hg(false!==strpos($main,'Version: 4.0.19'),'Hamnoor version is 4.0.19');
hg(false!==strpos($helpers,'public static function share_invite_url'),'share-only URL helper exists');
hg(false!==strpos($helpers,"apply_filters('hamnoor_share_invite_url'"),'share-only extension filter exists');
hg(false!==strpos($helpers,'public static function invite_url($khatm)'),'canonical invite_url remains separate');
$inviteStart=strpos($helpers,'public static function invite_url($khatm)');
$inviteEnd=strpos($helpers,'public static function khatm_has_indexable_content',$inviteStart);
$inviteMethod=substr($helpers,$inviteStart,$inviteEnd-$inviteStart);
hg(false===strpos($inviteMethod,'hamnoor_share_invite_url'),'canonical URL does not acquire referral parameters');
hg(false!==strpos($helpers,'$url = self::share_invite_url($khatm') || false!==strpos($helpers,'share_invite_url($khatm'),'share text can use personalized share URL');
hg(false!==strpos($front,'share_invite_url'),'campaign share surface uses share-only URL');
hg(false!==strpos($contrib,'share_invite_url'),'contribution share surface uses share-only URL');
hg(false!==strpos($front,'$khatm_url = Hamnoor_Helpers::invite_url') || false!==strpos($front,'Hamnoor_Helpers::khatm_url'),'normal navigation still uses canonical campaign URL');
if($fails){fwrite(STDERR,count($fails)." Hamnoor share contract check(s) failed.\n");exit(1);}echo "All Hamnoor growth share URL contract tests passed.\n";
