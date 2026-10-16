<?php
define('ABSPATH', __DIR__ . '/');
function __($s,$d=null){ return $s; }
function get_option($name,$default=''){ return 'manahel_rewards_policy_timezone'===$name ? 'Asia/Tehran' : $default; }
function wp_timezone_string(){ return 'Asia/Tehran'; }

final class MR_Limit_Test_WPDB {
    public $prefix = 'wp_';
    public $unique_count = 0;
    public $previous = null;
    public $next = null;
    public $award_count = 0;
    public $point_total = 0;
    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1);
        }
        return $query;
    }
    public function get_var($query) {
        if (false !== strpos($query, 'COUNT(*)')) { return $this->unique_count; }
        if (false !== strpos($query, 'MAX(e.occurred_at)')) { return $this->previous; }
        if (false !== strpos($query, 'MIN(e.occurred_at)')) { return $this->next; }
        return null;
    }
    public function get_row($query) {
        return (object)array('award_count'=>$this->award_count, 'point_total'=>$this->point_total);
    }
}

require_once dirname(__DIR__) . '/includes/class-manahel-rewards-db.php';
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-limit-evaluator.php';

$failures=[];
function check_limit($condition,$label){ global $failures; if($condition){echo "PASS: $label\n";}else{$failures[]=$label;echo "FAIL: $label\n";} }
function rule_with_limits($limits,$points=3){ return (object)array('id'=>7,'points'=>$points,'limits_json'=>json_encode($limits)); }
$event=['occurred_at'=>'2026-08-24 12:00:00','entity_type'=>'contribution','entity_id'=>'CTR-X'];

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->unique_count=1;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['unique_per_entity'=>true]));
check_limit(!$r['allowed'] && 'limit.unique_entity'===$r['code'],'unique_per_entity blocks a previously rewarded entity');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->previous='2026-08-23 12:00:00';
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['cooldown_seconds'=>604800]));
check_limit(!$r['allowed'] && 'limit.cooldown'===$r['code'] && 86400===$r['observed'],'cooldown blocks an award inside the interval');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->award_count=3; $wpdb->point_total=9;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['max_awards_per_day'=>3,'max_points_per_day'=>9]));
check_limit(!$r['allowed'] && 'limit.daily_awards'===$r['code'],'daily award-count cap is checked before daily points cap');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->award_count=2; $wpdb->point_total=8;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['max_awards_per_day'=>3,'max_points_per_day'=>9],3));
check_limit(!$r['allowed'] && 'limit.daily_points'===$r['code'],'daily points cap refuses partial awards');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->award_count=2; $wpdb->point_total=6;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['max_awards_per_day'=>3,'max_points_per_day'=>9],3));
check_limit($r['allowed'] && 'awarded'===$r['code'],'award passes while both daily limits have capacity');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->next='2026-08-25 12:00:00';
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['cooldown_seconds'=>604800]));
check_limit(!$r['allowed'] && 'limit.cooldown'===$r['code'],'out-of-order older event cannot bypass cooldown against a later award');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->award_count=20; $wpdb->point_total=400;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['version'=>2,'max_awards_per_month'=>20,'max_points_per_month'=>400],20));
check_limit(!$r['allowed'] && 'limit.monthly_awards'===$r['code'],'monthly award-count cap is enforced');

$wpdb = new MR_Limit_Test_WPDB();
$wpdb->award_count=19; $wpdb->point_total=390;
$r=Manahel_Rewards_Limit_Evaluator::evaluate(2,$event,rule_with_limits(['version'=>2,'max_awards_per_month'=>20,'max_points_per_month'=>400],20));
check_limit(!$r['allowed'] && 'limit.monthly_points'===$r['code'],'monthly points cap refuses partial awards');

$mb=Manahel_Rewards_Limit_Evaluator::month_bounds_utc('2026-08-24 20:45:00','Asia/Tehran');
check_limit('2026-07-31 20:30:00'===$mb[0] && '2026-08-31 20:30:00'===$mb[1],'Tehran calendar-month bounds convert to UTC correctly');

if($failures){fwrite(STDERR,count($failures)." test(s) failed.\n");exit(1);} echo "All limit evaluator tests passed.\n";
