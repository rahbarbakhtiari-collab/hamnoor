<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bridge committed high-value growth rewards into Hamnoor's existing
 * notification pipeline. It deliberately does NOT create a second transport
 * system and does NOT notify for low-value personal activity rewards.
 */
final class Manahel_Rewards_Notification_Bridge {
    const NOTIFICATION_TYPE = 'reward_growth_earned';
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        // Hamnoor Core owns the durable in-app notification store. These filters
        // opt in one Rewards-owned semantic type without changing Core bytes.
        add_filter('hamnoor_important_notification_types', array(__CLASS__, 'register_internal_type'));
        add_filter('hamnoor_external_notification_types', array(__CLASS__, 'register_external_type'), 20, 2);
        add_action('manahel_rewards/earning_committed', array(__CLASS__, 'on_earning_committed'), 20, 1);
    }

    public static function register_internal_type($types) {
        $types = is_array($types) ? $types : array();
        if (!in_array(self::NOTIFICATION_TYPE, $types, true)) { $types[] = self::NOTIFICATION_TYPE; }
        return $types;
    }

    public static function register_external_type($types, $notification = null) {
        $types = is_array($types) ? $types : array();
        if (!in_array(self::NOTIFICATION_TYPE, $types, true)) { $types[] = self::NOTIFICATION_TYPE; }
        return $types;
    }

    /**
     * High-value growth rules only. The list is filterable for future milestone
     * rules, but personal Quran/Dua/self-completion rewards stay silent externally.
     */
    public static function external_growth_rule_codes() {
        $codes = array(
            'MANAHEL_REFERRAL_QUALIFIED_INVITER',
            'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT',
        );
        $codes = apply_filters('manahel_rewards_external_notification_rule_codes', $codes);
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array)$codes))));
    }

    public static function on_earning_committed($payload) {
        if (!is_array($payload) || empty($payload['event_id']) || empty($payload['wp_user_id'])) { return false; }
        $points_total = (int)($payload['points'] ?? 0);
        if ($points_total <= 0) { return false; }

        $decision = isset($payload['decision']) && is_array($payload['decision']) ? $payload['decision'] : array();
        $rules = isset($decision['rules']) && is_array($decision['rules']) ? $decision['rules'] : array();
        $allowed = self::external_growth_rule_codes();
        $growth_points = 0;
        $matched_codes = array();
        foreach ($rules as $row) {
            if (!is_array($row) || 'awarded' !== (string)($row['result'] ?? '')) { continue; }
            $code = sanitize_key((string)($row['rule_code'] ?? ''));
            if (!$code || !in_array($code, $allowed, true)) { continue; }
            $pts = max(0, (int)($row['points'] ?? 0));
            if ($pts <= 0) { continue; }
            $growth_points += $pts;
            $matched_codes[] = $code;
        }
        if ($growth_points <= 0 || !$matched_codes) { return false; }

        $hamnoor_user_id = self::resolve_hamnoor_user_id((int)$payload['wp_user_id']);
        if ($hamnoor_user_id <= 0 || !class_exists('Hamnoor_DB') || !method_exists('Hamnoor_DB', 'add_notification')) { return false; }

        $event_id = (int)$payload['event_id'];
        if (self::already_created($hamnoor_user_id, $event_id)) { return true; }

        $balance = array_key_exists('balance', $payload) ? (int)$payload['balance'] : null;
        $primary = reset($matched_codes);
        $copy = self::copy_for($primary, $growth_points, $balance);

        // dispatch=true intentionally reuses Hamnoor Notifications. Its dispatcher
        // respects user consent and sends Web Push plus at most one durable channel
        // according to its existing priority/fallback policy.
        $notification_id = Hamnoor_DB::add_notification(
            $hamnoor_user_id,
            self::NOTIFICATION_TYPE,
            $copy['title'],
            $copy['message'],
            'reward_event',
            $event_id,
            true
        );

        return $notification_id ? (int)$notification_id : false;
    }

    private static function resolve_hamnoor_user_id($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0) { return 0; }

        $candidate = (int)get_user_meta($wp_user_id, '_hamnoor_user_id', true);
        if ($candidate > 0 && class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'get_user')) {
            $user = Hamnoor_Mobile_Access::get_user($candidate);
            if ($user && (int)($user->wp_user_id ?? 0) === $wp_user_id) { return $candidate; }
        }

        if (class_exists('Hamnoor_DB') && method_exists('Hamnoor_DB', 'table')) {
            global $wpdb;
            $table = Hamnoor_DB::table('users');
            if ($table) {
                return (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE wp_user_id=%d AND status='active' LIMIT 1",
                    $wp_user_id
                ));
            }
        }
        return 0;
    }

    private static function already_created($hamnoor_user_id, $event_id) {
        if (!class_exists('Hamnoor_DB') || !method_exists('Hamnoor_DB', 'table')) { return false; }
        global $wpdb;
        $table = Hamnoor_DB::table('notifications');
        if (!$table) { return false; }
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id=%d AND type=%s AND related_type=%s AND related_id=%d LIMIT 1",
            (int)$hamnoor_user_id,
            self::NOTIFICATION_TYPE,
            'reward_event',
            (int)$event_id
        ));
    }

    private static function copy_for($rule_code, $points, $balance = null) {
        $points_display = number_format_i18n((int)$points);
        $balance_suffix = null !== $balance
            ? sprintf(__(' موجودی شما: %s امتیاز.', 'manahel-rewards'), number_format_i18n((int)$balance))
            : '';

        if ('manahel_referral_qualified_inviter' === $rule_code) {
            return array(
                'title' => sprintf(__('+%s امتیاز برای دعوت موفق', 'manahel-rewards'), $points_display),
                'message' => sprintf(
                    __('یکی از افرادی که دعوت کرده بودید اولین مشارکت واقعی خود را انجام داد. %1$s امتیاز به شما اضافه شد.%2$s', 'manahel-rewards'),
                    $points_display,
                    $balance_suffix
                ),
            );
        }

        return array(
            'title' => sprintf(__('+%s امتیاز برای جذب مشارکت‌کننده', 'manahel-rewards'), $points_display),
            'message' => sprintf(
                __('فردی که از لینک پویش شما وارد شده بود مشارکتش را تکمیل کرد. %1$s امتیاز به شما اضافه شد.%2$s', 'manahel-rewards'),
                $points_display,
                $balance_suffix
            ),
        );
    }
}
