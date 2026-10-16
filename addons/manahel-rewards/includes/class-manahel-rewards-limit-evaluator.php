<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Reward Policy / Limit Contract.
 *
 * v1: unique entity, daily awards/points, cooldown.
 * v2: backward-compatible monthly awards/points windows.
 *
 * Limits are evaluated only after the reward account row has been locked by
 * the event processor. Successful reward history is derived from the Ledger;
 * Events that were valid but limited do not consume capacity.
 */
final class Manahel_Rewards_Limit_Evaluator {
    const CONTRACT_VERSION = 2;
    const OPTION_TIMEZONE = 'manahel_rewards_policy_timezone';

    public static function sanitize_limits($limits) {
        $limits = is_array($limits) ? $limits : array();
        $has_monthly = !empty($limits['max_awards_per_month']) || !empty($limits['max_points_per_month']);
        $version = isset($limits['version']) ? (int)$limits['version'] : ($has_monthly ? 2 : 1);
        if ($version < 1 || $version > self::CONTRACT_VERSION) { $version = $has_monthly ? 2 : 1; }
        if ($has_monthly && $version < 2) { $version = 2; }
        return array(
            'version' => $version,
            'unique_per_entity' => !empty($limits['unique_per_entity']),
            'max_awards_per_day' => max(0, (int)($limits['max_awards_per_day'] ?? 0)),
            'max_points_per_day' => max(0, (int)($limits['max_points_per_day'] ?? 0)),
            'max_awards_per_month' => max(0, (int)($limits['max_awards_per_month'] ?? 0)),
            'max_points_per_month' => max(0, (int)($limits['max_points_per_month'] ?? 0)),
            'cooldown_seconds' => max(0, (int)($limits['cooldown_seconds'] ?? 0)),
        );
    }

    public static function limits_from_rule($rule) {
        if (!is_object($rule) || !property_exists($rule, 'limits_json') || null === $rule->limits_json || '' === trim((string)$rule->limits_json)) {
            return self::sanitize_limits(array());
        }
        $decoded = json_decode((string)$rule->limits_json, true);
        return self::sanitize_limits(is_array($decoded) ? $decoded : array());
    }

    public static function policy_timezone() {
        $stored = function_exists('get_option') ? trim((string)get_option(self::OPTION_TIMEZONE, '')) : '';
        if (self::valid_timezone($stored)) { return $stored; }
        $fallback = function_exists('wp_timezone_string') ? (string)wp_timezone_string() : 'UTC';
        if (!self::valid_timezone($fallback)) { $fallback = 'UTC'; }
        return $fallback;
    }

    public static function valid_timezone($timezone) {
        $timezone = trim((string)$timezone);
        if ('' === $timezone) { return false; }
        try { new DateTimeZone($timezone); return true; }
        catch (Exception $e) { return false; }
    }

    public static function day_bounds_utc($occurred_at_mysql, $timezone = '') {
        return self::calendar_bounds_utc($occurred_at_mysql, $timezone, 'day');
    }

    public static function month_bounds_utc($occurred_at_mysql, $timezone = '') {
        return self::calendar_bounds_utc($occurred_at_mysql, $timezone, 'month');
    }

    private static function calendar_bounds_utc($occurred_at_mysql, $timezone, $unit) {
        $timezone = $timezone ?: self::policy_timezone();
        if (!self::valid_timezone($timezone)) { $timezone = 'UTC'; }
        try {
            $utc = new DateTimeZone('UTC');
            $policy_tz = new DateTimeZone($timezone);
            $event_utc = new DateTimeImmutable((string)$occurred_at_mysql, $utc);
            $local = $event_utc->setTimezone($policy_tz);
            if ('month' === $unit) {
                $start_local = $local->modify('first day of this month')->setTime(0, 0, 0);
                $end_local = $start_local->modify('+1 month');
            } else {
                $start_local = $local->setTime(0, 0, 0);
                $end_local = $start_local->modify('+1 day');
            }
            return array(
                $start_local->setTimezone($utc)->format('Y-m-d H:i:s'),
                $end_local->setTimezone($utc)->format('Y-m-d H:i:s'),
            );
        } catch (Exception $e) { return array('', ''); }
    }

    /** Caller MUST hold the reward-account row lock. */
    public static function evaluate($account_id, $event, $rule) {
        global $wpdb;
        $limits = self::limits_from_rule($rule);
        $account_id = (int)$account_id;
        $rule_id = isset($rule->id) ? (int)$rule->id : 0;
        $points = isset($rule->points) ? (int)$rule->points : 0;
        $occurred_at = (string)($event['occurred_at'] ?? '');
        if ($account_id <= 0 || $rule_id <= 0 || $points <= 0 || '' === $occurred_at) {
            return array('allowed'=>false,'code'=>'processing_failed','observed'=>null,'maximum'=>null,'limits'=>$limits);
        }

        $ledger = Manahel_Rewards_DB::table('ledger');
        $events = Manahel_Rewards_DB::table('events');

        if ($limits['unique_per_entity']) {
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ledger} l INNER JOIN {$events} e ON e.id=l.event_id
                 WHERE l.account_id=%d AND l.rule_id=%d AND l.entry_type='earn'
                   AND e.entity_type=%s AND e.entity_id=%s",
                $account_id, $rule_id, (string)$event['entity_type'], (string)$event['entity_id']
            ));
            if ($count > 0) { return array('allowed'=>false,'code'=>'limit.unique_entity','observed'=>$count,'maximum'=>1,'limits'=>$limits); }
        }

        if ($limits['cooldown_seconds'] > 0) {
            $previous = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(e.occurred_at) FROM {$ledger} l INNER JOIN {$events} e ON e.id=l.event_id
                 WHERE l.account_id=%d AND l.rule_id=%d AND l.entry_type='earn' AND e.occurred_at <= %s",
                $account_id, $rule_id, $occurred_at
            ));
            $next = $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(e.occurred_at) FROM {$ledger} l INNER JOIN {$events} e ON e.id=l.event_id
                 WHERE l.account_id=%d AND l.rule_id=%d AND l.entry_type='earn' AND e.occurred_at >= %s",
                $account_id, $rule_id, $occurred_at
            ));
            $distances = array();
            if ($previous) { $distances[] = abs(strtotime($occurred_at . ' UTC') - strtotime((string)$previous . ' UTC')); }
            if ($next) { $distances[] = abs(strtotime((string)$next . ' UTC') - strtotime($occurred_at . ' UTC')); }
            if ($distances) {
                $elapsed = min($distances);
                if ($elapsed < $limits['cooldown_seconds']) {
                    return array('allowed'=>false,'code'=>'limit.cooldown','observed'=>$elapsed,'maximum'=>$limits['cooldown_seconds'],'limits'=>$limits);
                }
            }
        }

        $daily = self::evaluate_window(
            $account_id, $rule_id, $points, $occurred_at,
            $limits['max_awards_per_day'], $limits['max_points_per_day'], 'day'
        );
        if (!$daily['allowed']) { $daily['limits'] = $limits; return $daily; }

        $monthly = self::evaluate_window(
            $account_id, $rule_id, $points, $occurred_at,
            $limits['max_awards_per_month'], $limits['max_points_per_month'], 'month'
        );
        if (!$monthly['allowed']) { $monthly['limits'] = $limits; return $monthly; }

        return array('allowed'=>true,'code'=>'awarded','observed'=>null,'maximum'=>null,'limits'=>$limits);
    }

    private static function evaluate_window($account_id, $rule_id, $points, $occurred_at, $max_awards, $max_points, $unit) {
        global $wpdb;
        $max_awards = max(0, (int)$max_awards);
        $max_points = max(0, (int)$max_points);
        if (0 === $max_awards && 0 === $max_points) { return array('allowed'=>true,'code'=>'awarded'); }
        list($start, $end) = 'month' === $unit ? self::month_bounds_utc($occurred_at) : self::day_bounds_utc($occurred_at);
        if (!$start || !$end) { return array('allowed'=>false,'code'=>'processing_failed','observed'=>null,'maximum'=>null); }
        $ledger = Manahel_Rewards_DB::table('ledger');
        $events = Manahel_Rewards_DB::table('events');
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) award_count, COALESCE(SUM(l.delta),0) point_total
             FROM {$ledger} l INNER JOIN {$events} e ON e.id=l.event_id
             WHERE l.account_id=%d AND l.rule_id=%d AND l.entry_type='earn'
               AND e.occurred_at >= %s AND e.occurred_at < %s",
            $account_id, $rule_id, $start, $end
        ));
        $award_count = $stats ? (int)$stats->award_count : 0;
        $point_total = $stats ? (int)$stats->point_total : 0;
        $award_code = 'month' === $unit ? 'limit.monthly_awards' : 'limit.daily_awards';
        $point_code = 'month' === $unit ? 'limit.monthly_points' : 'limit.daily_points';
        if ($max_awards > 0 && $award_count >= $max_awards) {
            return array('allowed'=>false,'code'=>$award_code,'observed'=>$award_count,'maximum'=>$max_awards,'window_start'=>$start,'window_end'=>$end);
        }
        if ($max_points > 0 && ($point_total + $points) > $max_points) {
            return array('allowed'=>false,'code'=>$point_code,'observed'=>$point_total,'maximum'=>$max_points,'attempted_points'=>$points,'window_start'=>$start,'window_end'=>$end);
        }
        return array('allowed'=>true,'code'=>'awarded');
    }
}
