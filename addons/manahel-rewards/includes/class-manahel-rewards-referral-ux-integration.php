<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional read-only financial projection for Manahel Referral UX.
 * Referral owns invite status; Rewards owns only actual awarded points.
 */
final class Manahel_Rewards_Referral_UX_Integration {
    private static $cache = array();

    public static function init() {
        add_filter('manahel_referral_financial_summary', array(__CLASS__, 'financial_summary'), 10, 3);
    }

    public static function financial_summary($summary, $wp_user_id, $public_ids = array()) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0) { return is_array($summary) ? $summary : array(); }
        $public_ids = array_values(array_filter(array_map('sanitize_text_field', (array)$public_ids)));
        sort($public_ids, SORT_STRING);
        $cache_key = $wp_user_id . '|' . hash('sha256', wp_json_encode($public_ids));
        if (isset(self::$cache[$cache_key])) { return array_merge((array)$summary, self::$cache[$cache_key]); }

        $accounts = Manahel_Rewards_DB::table('accounts');
        $ledger = Manahel_Rewards_DB::table('ledger');
        $events = Manahel_Rewards_DB::table('events');
        $account_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$accounts} WHERE wp_user_id=%d LIMIT 1", $wp_user_id));
        if ($account_id <= 0) {
            $result = array('available'=>true,'earned_points'=>0,'rewarded_referrals'=>0,'awarded_by_referral'=>array());
            self::$cache[$cache_key] = $result;
            return array_merge((array)$summary, $result);
        }

        $agg = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(l.delta),0) earned_points,COUNT(*) rewarded_referrals
             FROM {$ledger} l
             INNER JOIN {$events} e ON e.id=l.event_id
             WHERE l.account_id=%d AND l.entry_type='earn' AND l.delta>0
               AND e.source='manahel_referral'
               AND ((e.event_name='referral.qualified' AND e.entity_type='referral_inviter')
                    OR (e.event_name='referral.campaign_participant' AND e.entity_type='campaign_referrer'))",
            $account_id
        ));

        $map = array();
        if ($public_ids) {
            $placeholders = implode(',', array_fill(0, count($public_ids), '%s'));
            $sql = $wpdb->prepare(
                "SELECT e.entity_id,SUM(l.delta) awarded_points
                 FROM {$ledger} l
                 INNER JOIN {$events} e ON e.id=l.event_id
                 WHERE l.account_id=%d AND l.entry_type='earn' AND l.delta>0
                   AND e.source='manahel_referral' AND e.event_name='referral.qualified' AND e.entity_type='referral_inviter'
                   AND e.entity_id IN ({$placeholders})
                 GROUP BY e.entity_id",
                array_merge(array($account_id), $public_ids)
            );
            foreach ((array)$wpdb->get_results($sql) as $row) {
                $map[(string)$row->entity_id] = (int)$row->awarded_points;
            }
        }

        $result = array(
            'available'=>true,
            'earned_points'=>$agg ? (int)$agg->earned_points : 0,
            'rewarded_referrals'=>$agg ? (int)$agg->rewarded_referrals : 0,
            'awarded_by_referral'=>$map,
        );
        self::$cache[$cache_key] = $result;
        return array_merge((array)$summary, $result);
    }
}
