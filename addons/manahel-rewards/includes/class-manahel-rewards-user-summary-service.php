<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only projection for the authenticated user's Rewards experience.
 *
 * This service never creates accounts and never mutates Ledger/Event state.
 * Ledger remains the financial source of truth; account.balance is the fast
 * projection already maintained by the transactional write path.
 */
final class Manahel_Rewards_User_Summary_Service {
    const DEFAULT_HISTORY_LIMIT = 20;

    private static $canonical_policy_codes = array(
        'quran_part' => 'HAMNOOR_QURAN_PART_COMPLETED',
        'personal_quran' => 'HAMNOOR_PERSONAL_QURAN_COMPLETED',
        'dua' => 'HAMNOOR_DUA_COMPLETED',
    );

    /**
     * Compact read-only projection for embedded integrations such as the
     * Hamnoor dashboard card. Intentionally avoids policy/history queries.
     */
    public static function get_compact_for_wp_user($wp_user_id) {
        global $wpdb;

        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('reward_user_invalid', __('کاربر معتبر برای نمایش امتیازها پیدا نشد.', 'manahel-rewards'));
        }

        $accounts = Manahel_Rewards_DB::table('accounts');
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT id,balance,status FROM {$accounts} WHERE wp_user_id=%d LIMIT 1",
            $wp_user_id
        ));
        $account_id = $account ? (int)$account->id : 0;
        $balance = $account ? (int)$account->balance : 0;
        $now_utc = Manahel_Rewards_DB::utc_now_mysql();
        list($day_start, $day_end) = Manahel_Rewards_Limit_Evaluator::day_bounds_utc($now_utc);

        return array(
            'wp_user_id' => $wp_user_id,
            'has_account' => $account_id > 0,
            'account_id' => $account_id,
            'balance' => $balance,
            'today_earned' => self::today_earned($account_id, $day_start, $day_end),
            'policy_timezone' => Manahel_Rewards_Limit_Evaluator::policy_timezone(),
        );
    }

    public static function get_for_wp_user($wp_user_id, $history_limit = self::DEFAULT_HISTORY_LIMIT) {
        global $wpdb;

        $wp_user_id = (int)$wp_user_id;
        $history_limit = max(1, min(50, (int)$history_limit));
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('reward_user_invalid', __('کاربر معتبر برای نمایش امتیازها پیدا نشد.', 'manahel-rewards'));
        }

        $accounts = Manahel_Rewards_DB::table('accounts');
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$accounts} WHERE wp_user_id=%d LIMIT 1",
            $wp_user_id
        ));
        $account_id = $account ? (int)$account->id : 0;
        $balance = $account ? (int)$account->balance : 0;

        $now_utc = Manahel_Rewards_DB::utc_now_mysql();
        list($day_start, $day_end) = Manahel_Rewards_Limit_Evaluator::day_bounds_utc($now_utc);

        $policy_rules = self::canonical_policy_rules();
        $policy = self::policy_statuses($account_id, $policy_rules, $day_start, $day_end, $now_utc);

        $summary = array(
            'wp_user_id' => $wp_user_id,
            'has_account' => $account_id > 0,
            'account_id' => $account_id,
            'account_status' => $account ? (string)$account->status : 'not_created',
            'balance' => $balance,
            'today_earned' => self::today_earned($account_id, $day_start, $day_end),
            'policy_timezone' => Manahel_Rewards_Limit_Evaluator::policy_timezone(),
            'policy_day_start_utc' => $day_start,
            'policy_day_end_utc' => $day_end,
            'policy' => $policy,
            'recent_ledger' => self::recent_ledger($account_id, $history_limit),
            'recent_no_reward' => self::recent_no_reward($account_id, 5),
        );

        return apply_filters('manahel_rewards_user_summary', $summary, $wp_user_id);
    }

    private static function canonical_policy_rules() {
        global $wpdb;
        $rules_table = Manahel_Rewards_DB::table('rules');
        $codes = array_values(self::$canonical_policy_codes);
        if (!$codes) { return array(); }
        $placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $sql = $wpdb->prepare(
            "SELECT * FROM {$rules_table} WHERE code IN ({$placeholders})",
            $codes
        );
        $rows = $wpdb->get_results($sql);
        $by_code = array();
        foreach ((array)$rows as $row) { $by_code[(string)$row->code] = $row; }
        return $by_code;
    }

    private static function today_earned($account_id, $day_start, $day_end) {
        global $wpdb;
        if ($account_id <= 0 || !$day_start || !$day_end) { return 0; }
        $ledger = Manahel_Rewards_DB::table('ledger');
        $events = Manahel_Rewards_DB::table('events');
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(l.delta),0)
             FROM {$ledger} l
             INNER JOIN {$events} e ON e.id=l.event_id
             WHERE l.account_id=%d AND l.entry_type='earn' AND l.delta>0
               AND e.occurred_at >= %s AND e.occurred_at < %s",
            $account_id, $day_start, $day_end
        ));
    }

    private static function policy_statuses($account_id, $rules_by_code, $day_start, $day_end, $now_utc) {
        global $wpdb;
        $rule_ids = array();
        foreach ((array)$rules_by_code as $rule) {
            if (!empty($rule->id)) { $rule_ids[] = (int)$rule->id; }
        }

        $daily = array();
        $latest = array();
        if ($account_id > 0 && $rule_ids) {
            $ids = implode(',', array_map('intval', $rule_ids));
            $ledger = Manahel_Rewards_DB::table('ledger');
            $events = Manahel_Rewards_DB::table('events');

            if ($day_start && $day_end) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT l.rule_id, COUNT(*) award_count, COALESCE(SUM(l.delta),0) point_total
                     FROM {$ledger} l
                     INNER JOIN {$events} e ON e.id=l.event_id
                     WHERE l.account_id=%d AND l.entry_type='earn'
                       AND l.rule_id IN ({$ids})
                       AND e.occurred_at >= %s AND e.occurred_at < %s
                     GROUP BY l.rule_id",
                    $account_id, $day_start, $day_end
                ));
                foreach ((array)$rows as $row) {
                    $daily[(int)$row->rule_id] = array(
                        'award_count' => (int)$row->award_count,
                        'point_total' => (int)$row->point_total,
                    );
                }
            }

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT l.rule_id, MAX(e.occurred_at) last_awarded_at
                 FROM {$ledger} l
                 INNER JOIN {$events} e ON e.id=l.event_id
                 WHERE l.account_id=%d AND l.entry_type='earn' AND l.rule_id IN ({$ids})
                 GROUP BY l.rule_id",
                $account_id
            ));
            foreach ((array)$rows as $row) { $latest[(int)$row->rule_id] = (string)$row->last_awarded_at; }
        }

        $result = array();
        foreach (self::$canonical_policy_codes as $key => $code) {
            $rule = $rules_by_code[$code] ?? null;
            if (!$rule) {
                $result[$key] = self::empty_policy_status($key, $code, 'missing');
                continue;
            }

            $limits = Manahel_Rewards_Limit_Evaluator::limits_from_rule($rule);
            $rule_id = (int)$rule->id;
            $stats = $daily[$rule_id] ?? array('award_count'=>0, 'point_total'=>0);
            $points = (int)$rule->points;
            $max_awards = (int)$limits['max_awards_per_day'];
            $max_points = (int)$limits['max_points_per_day'];
            $remaining_awards = $max_awards > 0 ? max(0, $max_awards - (int)$stats['award_count']) : null;
            $remaining_points = $max_points > 0 ? max(0, $max_points - (int)$stats['point_total']) : null;
            $rewardable_remaining = null;
            if ($remaining_awards !== null) { $rewardable_remaining = $remaining_awards; }
            if ($remaining_points !== null && $points > 0) {
                $by_points = (int)floor($remaining_points / $points);
                $rewardable_remaining = null === $rewardable_remaining ? $by_points : min($rewardable_remaining, $by_points);
            }

            $last_awarded_at = $latest[$rule_id] ?? '';
            $cooldown_remaining = 0;
            $next_eligible_at = '';
            if ((int)$limits['cooldown_seconds'] > 0 && $last_awarded_at) {
                $last_ts = strtotime($last_awarded_at . ' UTC');
                $now_ts = strtotime($now_utc . ' UTC');
                $next_ts = $last_ts + (int)$limits['cooldown_seconds'];
                if ($next_ts > $now_ts) {
                    $cooldown_remaining = $next_ts - $now_ts;
                    $next_eligible_at = gmdate('Y-m-d H:i:s', $next_ts);
                }
            }

            $enabled = !empty($rule->enabled);
            $status_code = 'available';
            if (!$enabled) {
                $status_code = 'inactive';
            } elseif ($cooldown_remaining > 0) {
                $status_code = 'cooldown';
            } elseif (null !== $rewardable_remaining && $rewardable_remaining <= 0) {
                $status_code = 'daily_limit';
            }

            $result[$key] = array(
                'key' => $key,
                'rule_id' => $rule_id,
                'rule_code' => $code,
                'title' => (string)$rule->title,
                'enabled' => $enabled,
                'status_code' => $status_code,
                'points' => $points,
                'awards_today' => (int)$stats['award_count'],
                'points_today' => (int)$stats['point_total'],
                'max_awards_per_day' => $max_awards,
                'max_points_per_day' => $max_points,
                'remaining_awards' => $remaining_awards,
                'remaining_points' => $remaining_points,
                'rewardable_remaining' => $rewardable_remaining,
                'cooldown_seconds' => (int)$limits['cooldown_seconds'],
                'cooldown_remaining_seconds' => $cooldown_remaining,
                'last_awarded_at' => $last_awarded_at,
                'next_eligible_at' => $next_eligible_at,
                'unique_per_entity' => !empty($limits['unique_per_entity']),
            );
        }
        return $result;
    }

    private static function empty_policy_status($key, $code, $status) {
        return array(
            'key'=>$key, 'rule_id'=>0, 'rule_code'=>$code, 'title'=>'', 'enabled'=>false,
            'status_code'=>$status, 'points'=>0, 'awards_today'=>0, 'points_today'=>0,
            'max_awards_per_day'=>0, 'max_points_per_day'=>0, 'remaining_awards'=>null,
            'remaining_points'=>null, 'rewardable_remaining'=>null, 'cooldown_seconds'=>0,
            'cooldown_remaining_seconds'=>0, 'last_awarded_at'=>'', 'next_eligible_at'=>'',
            'unique_per_entity'=>false,
        );
    }

    private static function recent_ledger($account_id, $limit) {
        global $wpdb;
        if ($account_id <= 0) { return array(); }
        $ledger = Manahel_Rewards_DB::table('ledger');
        $events = Manahel_Rewards_DB::table('events');
        $rules = Manahel_Rewards_DB::table('rules');
        $limit = max(1, min(50, (int)$limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.entry_type,l.delta,l.reason,l.created_at,l.created_by,l.event_id,l.rule_id,
                    e.entity_type,e.entity_id,e.campaign_id,e.occurred_at,e.payload_json,
                    r.code rule_code,r.title rule_title
             FROM {$ledger} l
             LEFT JOIN {$events} e ON e.id=l.event_id
             LEFT JOIN {$rules} r ON r.id=l.rule_id
             WHERE l.account_id=%d
             ORDER BY l.id DESC
             LIMIT %d",
            $account_id, $limit
        ));

        $items = array();
        foreach ((array)$rows as $row) {
            $payload = $row->payload_json ? json_decode((string)$row->payload_json, true) : array();
            $content = is_array($payload) ? (string)($payload['context']['content_type'] ?? '') : '';
            $items[] = array(
                'ledger_id' => (int)$row->id,
                'entry_type' => (string)$row->entry_type,
                'delta' => (int)$row->delta,
                'reason' => (string)$row->reason,
                'created_at' => (string)$row->created_at,
                'occurred_at' => $row->occurred_at ? (string)$row->occurred_at : '',
                'event_id' => $row->event_id ? (int)$row->event_id : 0,
                'rule_id' => $row->rule_id ? (int)$row->rule_id : 0,
                'rule_code' => (string)$row->rule_code,
                'rule_title' => (string)$row->rule_title,
                'entity_type' => (string)$row->entity_type,
                'entity_id' => (string)$row->entity_id,
                'campaign_id' => (string)$row->campaign_id,
                'content_type' => $content,
                'label' => self::history_label($row, $content),
            );
        }
        return $items;
    }

    private static function recent_no_reward($account_id, $limit) {
        global $wpdb;
        if ($account_id <= 0) { return array(); }
        $events = Manahel_Rewards_DB::table('events');
        $limit = max(1, min(10, (int)$limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,entity_type,entity_id,campaign_id,occurred_at,status,error_code,decision_json,payload_json
             FROM {$events}
             WHERE account_id=%d AND status IN ('processed','ignored')
               AND (decision_json IS NOT NULL AND decision_json <> '')
             ORDER BY id DESC
             LIMIT %d",
            $account_id, $limit * 3
        ));
        $items = array();
        foreach ((array)$rows as $row) {
            $decision = json_decode((string)$row->decision_json, true);
            if (!is_array($decision) || (int)($decision['total_awarded'] ?? 0) > 0) { continue; }
            $code = (string)($decision['decision_code'] ?? $row->error_code);
            if (!$code || 'awarded' === $code) { continue; }
            $payload = json_decode((string)$row->payload_json, true);
            $items[] = array(
                'event_id'=>(int)$row->id,
                'entity_type'=>(string)$row->entity_type,
                'entity_id'=>(string)$row->entity_id,
                'campaign_id'=>(string)$row->campaign_id,
                'occurred_at'=>(string)$row->occurred_at,
                'content_type'=>is_array($payload)?(string)($payload['context']['content_type']??''):'',
                'decision_code'=>$code,
            );
            if (count($items) >= $limit) { break; }
        }
        return $items;
    }

    private static function history_label($row, $content) {
        $code = (string)$row->rule_code;
        if ('HAMNOOR_QURAN_PART_COMPLETED' === $code || ('quran_part' === (string)$row->entity_type && 'earn' === (string)$row->entry_type)) {
            return __('تکمیل سهم قرآن', 'manahel-rewards');
        }
        if ('HAMNOOR_PERSONAL_QURAN_COMPLETED' === $code || ('personal_quran' === (string)$row->entity_type && 'earn' === (string)$row->entry_type)) {
            return __('تکمیل ختم شخصی قرآن', 'manahel-rewards');
        }
        if ('HAMNOOR_DUA_COMPLETED' === $code || ('dua' === $content && 'earn' === (string)$row->entry_type)) {
            return __('تکمیل دعا', 'manahel-rewards');
        }
        if ('MANAHEL_REFERRAL_QUALIFIED_INVITER' === $code || ('referral_inviter' === (string)$row->entity_type && 'earn' === (string)$row->entry_type)) {
            return __('دعوت موفق دوست', 'manahel-rewards');
        }
        if ('MANAHEL_REFERRAL_QUALIFIED_REFERRED' === $code || ('referral_referred' === (string)$row->entity_type && 'earn' === (string)$row->entry_type)) {
            return __('پاداش خوش‌آمد دعوت', 'manahel-rewards');
        }
        if ('MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT' === $code || ('campaign_referrer' === (string)$row->entity_type && 'earn' === (string)$row->entry_type)) {
            return __('جذب مشارکت‌کننده به پویش', 'manahel-rewards');
        }
        if ('adjustment' === (string)$row->entry_type) {
            return (int)$row->delta >= 0 ? __('افزایش امتیاز توسط مدیر', 'manahel-rewards') : __('کسر امتیاز توسط مدیر', 'manahel-rewards');
        }
        if ('reversal' === (string)$row->entry_type) { return __('اصلاح امتیاز', 'manahel-rewards'); }
        if ('redeem' === (string)$row->entry_type) { return __('استفاده از امتیاز', 'manahel-rewards'); }
        return $row->rule_title ? (string)$row->rule_title : ((string)$row->reason ?: __('تغییر امتیاز', 'manahel-rewards'));
    }
}
