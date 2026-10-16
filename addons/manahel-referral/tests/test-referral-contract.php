<?php
define('ABSPATH', __DIR__ . '/');
function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }

require_once dirname(__DIR__) . '/includes/class-manahel-referral-code-service.php';
require_once dirname(__DIR__) . '/includes/class-manahel-referral-attribution-service.php';

$fails=[];
function mr_ref_check($ok,$label){ global $fails; if($ok){echo "PASS: $label\n";}else{$fails[]=$label;echo "FAIL: $label\n";} }

mr_ref_check('ABCD2345XYZ9'===Manahel_Referral_Code_Service::normalize(' abcd-2345 xyz9 '),'referral code normalization is stable');

$quran=[
 'event_name'=>'participation.completed','schema_version'=>1,'entity_type'=>'quran_part',
 'context'=>['content_type'=>'quran','kind'=>'completion']
];
$personal=$quran; $personal['entity_type']='personal_quran';
$dua=$quran; $dua['entity_type']='contribution'; $dua['context']['content_type']='dua';
$dhikr=$dua; $dhikr['context']['content_type']='dhikr'; $dhikr['context']['kind']='numeric';
$pending=$dua; $pending['context']['kind']='pending';
mr_ref_check(Manahel_Referral_Attribution_Service::is_qualifying_participation($quran),'Quran share completion qualifies');
mr_ref_check(Manahel_Referral_Attribution_Service::is_qualifying_participation($personal),'Personal Quran completion qualifies');
mr_ref_check(Manahel_Referral_Attribution_Service::is_qualifying_participation($dua),'Dua completion qualifies');
mr_ref_check(!Manahel_Referral_Attribution_Service::is_qualifying_participation($dhikr),'Dhikr does not qualify referral');
mr_ref_check(!Manahel_Referral_Attribution_Service::is_qualifying_participation($pending),'Pending Dua does not qualify referral');
mr_ref_check(30===Manahel_Referral_Attribution_Service::ATTRIBUTION_DAYS,'qualification window is 30 days');

$all=file_get_contents(dirname(__DIR__).'/manahel-referral.php');
foreach(['includes','admin','frontend'] as $dir){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/'.$dir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){ if($f->isFile() && 'php'===$f->getExtension()){$all.=file_get_contents($f->getPathname());} }
}
mr_ref_check(false===strpos($all,'$_SERVER[\'REMOTE_ADDR\']'),'no IP address persistence contract');
mr_ref_check(false===stripos($all,'device_fingerprint'),'no device fingerprinting in v1 implementation');
mr_ref_check(false===stripos($all,'multi-level'),'no multi-level referral implementation');

if($fails){fwrite(STDERR,count($fails)." test(s) failed.\n");exit(1);} echo "All referral contract tests passed.\n";
