<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only inviter projection for Referral UX. It never creates an
 * attribution or touches Rewards financial state.
 */
final class Manahel_Referral_User_Summary_Service {
    const RECENT_LIMIT = 20;

    public static function get_compact_for_wp_user($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('referral_viewer_invalid', __('کاربر معتبر نیست.', 'manahel-referral'));
        }

        $counts = self::counts_for_inviter($wp_user_id);
        $financial = self::financial_summary($wp_user_id, array());

        return array(
            'successful' => (int)$counts['successful'],
            'pending' => (int)$counts['pending'],
            'expired' => (int)$counts['expired'],
            'total' => (int)$counts['total'],
            'earned_points' => isset($financial['earned_points']) ? (int)$financial['earned_points'] : null,
            'rewarded_referrals' => isset($financial['rewarded_referrals']) ? (int)$financial['rewarded_referrals'] : null,
            'rewards_available' => !empty($financial['available']),
        );
    }

    public static function get_for_wp_user($wp_user_id, $limit = self::RECENT_LIMIT) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('referral_viewer_invalid', __('کاربر معتبر نیست.', 'manahel-referral'));
        }

        $limit = max(1, min(50, (int)$limit));
        $table = Manahel_Referral_DB::table('attributions');
        $now = Manahel_Referral_DB::utc_now_mysql();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT public_id,status,attributed_at,qualifies_until,qualified_at,qualification_entity_type
             FROM {$table}
             WHERE inviter_wp_user_id=%d
             ORDER BY attributed_at DESC,id DESC
             LIMIT %d",
            $wp_user_id,
            $limit
        ));

        $recent = array();
        $public_ids = array();
        foreach ((array)$rows as $row) {
            $view_status = self::view_status($row, $now);
            $public_id = sanitize_text_field((string)$row->public_id);
            if ($public_id) { $public_ids[] = $public_id; }
            $recent[] = array(
                'public_id' => $public_id,
                'status' => $view_status,
                'attributed_at' => (string)$row->attributed_at,
                'qualifies_until' => (string)$row->qualifies_until,
                'qualified_at' => (string)$row->qualified_at,
                'qualification_entity_type' => sanitize_key((string)$row->qualification_entity_type),
            );
        }

        $counts = self::counts_for_inviter($wp_user_id);
        $financial = self::financial_summary($wp_user_id, $public_ids);
        $awards = isset($financial['awarded_by_referral']) && is_array($financial['awarded_by_referral']) ? $financial['awarded_by_referral'] : array();
        foreach ($recent as &$item) {
            $item['awarded_points'] = isset($awards[$item['public_id']]) ? (int)$awards[$item['public_id']] : 0;
        }
        unset($item);

        return array(
            'successful' => (int)$counts['successful'],
            'pending' => (int)$counts['pending'],
            'expired' => (int)$counts['expired'],
            'total' => (int)$counts['total'],
            'earned_points' => isset($financial['earned_points']) ? (int)$financial['earned_points'] : null,
            'rewarded_referrals' => isset($financial['rewarded_referrals']) ? (int)$financial['rewarded_referrals'] : null,
            'rewards_available' => !empty($financial['available']),
            'recent_referrals' => $recent,
        );
    }

    private static function counts_for_inviter($wp_user_id) {
        global $wpdb;
        $table = Manahel_Referral_DB::table('attributions');
        $now = Manahel_Referral_DB::utc_now_mysql();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) total_count,
                SUM(CASE WHEN status='qualified' THEN 1 ELSE 0 END) successful_count,
                SUM(CASE WHEN status='attributed' AND qualifies_until >= %s THEN 1 ELSE 0 END) pending_count,
                SUM(CASE WHEN status='expired' OR (status='attributed' AND qualifies_until < %s) THEN 1 ELSE 0 END) expired_count
             FROM {$table}
             WHERE inviter_wp_user_id=%d",
            $now,
            $now,
            (int)$wp_user_id
        ));

        return array(
            'total' => $row ? (int)$row->total_count : 0,
            'successful' => $row ? (int)$row->successful_count : 0,
            'pending' => $row ? (int)$row->pending_count : 0,
            'expired' => $row ? (int)$row->expired_count : 0,
        );
    }

    private static function view_status($row, $now) {
        $status = sanitize_key((string)($row->status ?? ''));
        if ('qualified' === $status) { return 'qualified'; }
        if ('expired' === $status) { return 'expired'; }
        if ('attributed' === $status && !empty($row->qualifies_until) && (string)$row->qualifies_until < (string)$now) { return 'expired'; }
        return 'attributed';
    }

    private static function financial_summary($wp_user_id, array $public_ids) {
        $default = array(
            'available' => false,
            'earned_points' => null,
            'rewarded_referrals' => null,
            'awarded_by_referral' => array(),
        );
        $result = apply_filters('manahel_referral_financial_summary', $default, (int)$wp_user_id, array_values(array_unique($public_ids)));
        return is_array($result) ? array_merge($default, $result) : $default;
    }
}
