<?php
// Standalone contract tests for pure Reward Core behavior. No WordPress DB needed.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code; private $message;
    public function __construct($code='', $message='') { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($s,$d=null){ return $s; }
function sanitize_key($key){ $key=strtolower((string)$key); return preg_replace('/[^a-z0-9_\-]/','',$key); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }

require_once dirname(__DIR__) . '/includes/class-manahel-rewards-db.php';
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-rule-engine.php';
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-limit-evaluator.php';

$failures = array();
function check_true($condition, $label) { global $failures; if (!$condition) { $failures[]=$label; echo "FAIL: {$label}\n"; } else { echo "PASS: {$label}\n"; } }

$payload = array(
    'event_name'=>'participation.completed','schema_version'=>1,'occurred_at'=>'2026-08-24T15:31:39Z',
    'actor_user_id'=>1,'subject_user_id'=>1,'actor_public_id'=>'HM-00000001','subject_public_id'=>'HM-00000001',
    'entity_type'=>'quran_part','entity_id'=>'PRT-59BF9DC5FB5A34F0D6CD','campaign_id'=>'CMP-C62686E6E1E49DF3F503',
    'context'=>array('content_type'=>'quran','participation_strategy'=>'segmented','kind'=>'completion','quantity'=>1),
    'phone'=>'SHOULD_NOT_LEAK','email'=>'SHOULD_NOT_LEAK@example.com','metadata_json'=>array('secret'=>'x')
);
$event = Manahel_Rewards_Normalizer::hamnoor_participation_completed($payload, array());
check_true(!is_wp_error($event), 'valid Hamnoor v1 event normalizes');
check_true(64 === strlen($event['dedupe_key']), 'dedupe key is SHA-256');
check_true(false === strpos($event['payload_json'], 'SHOULD_NOT_LEAK'), 'privacy extras are stripped');
check_true('' === Manahel_Rewards_Normalizer::policy_exclusion_reason($event), 'Quran is not policy-excluded');

$payload2=$payload; $payload2['schema_version']=2;
check_true(is_wp_error(Manahel_Rewards_Normalizer::hamnoor_participation_completed($payload2,array())), 'unsupported schema is rejected');

$dhikr=$payload; $dhikr['entity_type']='contribution'; $dhikr['entity_id']='CTR-1'; $dhikr['context']['content_type']='dhikr'; $dhikr['context']['kind']='numeric'; $dhikr['context']['quantity']=100;
$dhikr_event=Manahel_Rewards_Normalizer::hamnoor_participation_completed($dhikr,array());
check_true('policy_excluded:dhikr' === Manahel_Rewards_Normalizer::policy_exclusion_reason($dhikr_event), 'Dhikr is hard policy-excluded from rewards');

$key1=Manahel_Rewards_Normalizer::dedupe_key('hamnoor','participation.completed','quran_part','PRT-1');
$key2=Manahel_Rewards_Normalizer::dedupe_key('hamnoor','participation.completed','quran_part','PRT-1');
$key3=Manahel_Rewards_Normalizer::dedupe_key('hamnoor','participation.completed','quran_part','PRT-2');
check_true($key1===$key2, 'same business fact has stable dedupe key');
check_true($key1!==$key3, 'different entity produces different dedupe key');

check_true(Manahel_Rewards_Rule_Engine::conditions_match($event,array('context.content_type'=>'quran','context.kind'=>'completion')), 'allowlisted exact conditions match');
check_true(!Manahel_Rewards_Rule_Engine::conditions_match($event,array('context.content_type'=>'dua')), 'nonmatching condition is rejected');
check_true(!Manahel_Rewards_Rule_Engine::conditions_match($event,array('dangerous.php_callable'=>'x')), 'unknown condition path is rejected');

$ref_payload=array(
    'event_name'=>'referral.qualified','schema_version'=>1,'occurred_at'=>'2026-08-25T07:10:00Z',
    'entity_type'=>'referral','entity_id'=>'MRF-ABC123','inviter_wp_user_id'=>10,'referred_wp_user_id'=>22,
    'context'=>array('qualification_source'=>'hamnoor','qualification_event'=>'participation.completed','qualification_entity_type'=>'quran_part','content_type'=>'quran')
);
$ref_inv=Manahel_Rewards_Normalizer::referral_qualified($ref_payload,'inviter');
$ref_new=Manahel_Rewards_Normalizer::referral_qualified($ref_payload,'referred');
check_true(!is_wp_error($ref_inv) && 'manahel_referral'===$ref_inv['source'] && 'referral_inviter'===$ref_inv['entity_type'], 'qualified referral normalizes for inviter');
check_true(!is_wp_error($ref_new) && 'referral_referred'===$ref_new['entity_type'], 'qualified referral normalizes for referred user');
check_true($ref_inv['dedupe_key']!==$ref_new['dedupe_key'], 'inviter and referred reward facts have distinct dedupe keys');
check_true(Manahel_Rewards_Rule_Engine::conditions_match($ref_inv,array('context.role'=>'inviter')), 'referral role is an allowlisted rule condition');

$limits = Manahel_Rewards_Limit_Evaluator::sanitize_limits(array(
    'unique_per_entity'=>1,
    'max_awards_per_day'=>3,
    'max_points_per_day'=>9,
    'cooldown_seconds'=>-5,
));
check_true(1 === $limits['version'], 'limit contract version is 1');
check_true(true === $limits['unique_per_entity'], 'unique_per_entity is normalized to boolean');
check_true(3 === $limits['max_awards_per_day'], 'daily award limit is normalized');
check_true(9 === $limits['max_points_per_day'], 'daily point limit is normalized');
check_true(0 === $limits['cooldown_seconds'], 'negative cooldown is clamped to zero');
$monthly = Manahel_Rewards_Limit_Evaluator::sanitize_limits(array('version'=>2,'max_awards_per_month'=>20,'max_points_per_month'=>400));
check_true(2 === $monthly['version'] && 20 === $monthly['max_awards_per_month'] && 400 === $monthly['max_points_per_month'], 'limit contract v2 normalizes monthly caps');
check_true(Manahel_Rewards_Limit_Evaluator::valid_timezone('Asia/Tehran'), 'IANA policy timezone is accepted');
check_true(!Manahel_Rewards_Limit_Evaluator::valid_timezone('Not/A_Timezone'), 'invalid policy timezone is rejected');
$bounds = Manahel_Rewards_Limit_Evaluator::day_bounds_utc('2026-08-24 20:45:00', 'Asia/Tehran');
check_true('2026-08-24 20:30:00' === $bounds[0], 'Tehran local-day start converts to UTC correctly');
check_true('2026-08-25 20:30:00' === $bounds[1], 'Tehran local-day end converts to UTC correctly');
$legacy_rule = (object)array('id'=>1,'points'=>10,'limits_json'=>null);
$legacy_limits = Manahel_Rewards_Limit_Evaluator::limits_from_rule($legacy_rule);
check_true(false === $legacy_limits['unique_per_entity'] && 0 === $legacy_limits['max_awards_per_day'], 'legacy NULL limits preserve no additional policy limits');


// Expected UNIQUE collisions must not leak raw wpdb diagnostics.
class Manahel_Rewards_Test_WPDB {
    public $insert_id = 0;
    public $last_error = '';
    public $suppressed_during_insert = false;
    private $suppressed = false;
    public function suppress_errors($suppress = true) {
        $previous = $this->suppressed;
        $this->suppressed = (bool)$suppress;
        return $previous;
    }
    public function insert($table, $data, $format = null) {
        $this->suppressed_during_insert = $this->suppressed;
        $this->last_error = 'Duplicate entry expected-by-test';
        return false;
    }
    public function is_suppressed() { return $this->suppressed; }
}
$wpdb = new Manahel_Rewards_Test_WPDB();
$quiet = Manahel_Rewards_DB::quiet_insert('fake_table', array('id'=>1), array('%d'));
check_true(false === $quiet['result'], 'quiet insert preserves failed INSERT result');
check_true(true === $wpdb->suppressed_during_insert, 'quiet insert suppresses wpdb output during expected collision');
check_true(false === $wpdb->is_suppressed(), 'quiet insert restores previous wpdb suppress_errors state');
check_true(false !== strpos($quiet['last_error'], 'Duplicate entry'), 'quiet insert still captures DB error for caller diagnostics');

if ($failures) { fwrite(STDERR, count($failures) . " test(s) failed.\n"); exit(1); }
echo "All standalone contract tests passed.\n";
