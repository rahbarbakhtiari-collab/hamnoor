<?php
$root=dirname(__DIR__);$fails=[];
function gf($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$main=file_get_contents($root.'/manahel-rewards.php');
$installer=file_get_contents($root.'/includes/class-manahel-rewards-installer.php');
$gate=file_get_contents($root.'/includes/class-manahel-rewards-identity-gate.php');
$normalizer=file_get_contents($root.'/includes/class-manahel-rewards-normalizer.php');
gf(false!==strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'") && false!==strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'"),'growth-first policy remains intact after 1.3.0 digital code inventory migration');
$expected=array(
 'HAMNOOR_QURAN_PART_COMPLETED'=>2,
 'HAMNOOR_PERSONAL_QURAN_COMPLETED'=>5,
 'HAMNOOR_DUA_COMPLETED'=>1,
 'MANAHEL_REFERRAL_QUALIFIED_INVITER'=>40,
 'MANAHEL_REFERRAL_QUALIFIED_REFERRED'=>5,
 'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT'=>10,
);
foreach($expected as $code=>$points){
    $needle="'code' => '".$code."'";
    $pos=strpos($installer,$needle);
    $chunk=$pos===false?'':substr($installer,$pos,1000);
    gf(false!==$pos && false!==strpos($chunk,"'points' => ".$points),$code.' default points = '.$points);
}
gf(substr_count($installer,"'enabled' => 0")>=1,'seeded templates remain disabled by default');
gf(false!==strpos($installer,'apply_growth_first_defaults'),'guarded legacy-default upgrade exists');
gf(false!==strpos($installer,"(int)\$row->points !== (int)\$policy['points']") && false!==strpos($installer,'sanitize_limits($current) !== $expected'),'legacy migration updates only exact untouched defaults');
gf(false!==strpos($gate,"earning_verification_not_required") && false!==strpos($gate,"earn_first_verify_on_redemption_v1"),'earning is not gated by OTP');
gf(false!==strpos($gate,'redemption_requires_verification') && false!==strpos($gate,'manahel_rewards_redemption_requires_verification'),'future redemption has explicit verification policy seam');
gf(false!==strpos($normalizer,'referral_campaign_participant'),'campaign-attraction facts normalize into Rewards');
if($fails){fwrite(STDERR,count($fails)." growth-first policy check(s) failed.\n");exit(1);}echo "All Rewards growth-first policy tests passed.\n";
