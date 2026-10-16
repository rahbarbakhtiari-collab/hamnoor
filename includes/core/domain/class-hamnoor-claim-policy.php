<?php
if (!defined('ABSPATH')) { exit; }

/** Claim-rate policy and append-only claim event ledger. */
final class Hamnoor_Claim_Policy {
    public static function quota_state($user_id, $campaign = null) {
        global $wpdb;
        $user_id = (int)$user_id;
        $settings = Hamnoor_Helpers::settings();
        $active_limit = $campaign && !empty($campaign->max_active_claims_per_participant)
            ? (int)$campaign->max_active_claims_per_participant
            : max(1, min(50, (int)($settings['default_active_claim_limit'] ?? 3)));
        $active_count = 0;
        if ($user_id && $campaign && 'auto_done_on_claim' !== (string)($campaign->completion_mode ?? '')) {
            $parts = Hamnoor_DB::table('parts');
            $participants = Hamnoor_DB::table('participants');
            $active_count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $parts p INNER JOIN $participants x ON x.id=p.participant_id WHERE x.user_id=%d AND p.khatm_id=%d AND p.status='claimed'",
                $user_id, (int)$campaign->id
            ));
        }
        $window = self::temporal_quota($user_id, $campaign);
        $active_blocked = $campaign && $active_count >= $active_limit && 'auto_done_on_claim' !== (string)($campaign->completion_mode ?? '');
        $time_blocked = empty($window['allowed']);
        $reasons = array();
        if ($active_blocked) { $reasons[] = 'active_limit'; }
        if ($time_blocked) { $reasons[] = 'time_window'; }
        $next_ts = !empty($window['retry_at']) ? strtotime($window['retry_at'] . ' UTC') : 0;
        return array(
            'can_claim'=>!$active_blocked && !$time_blocked,
            'active_claim_count'=>$active_count,
            'active_claim_limit'=>$active_limit,
            'window_enabled'=>!empty($window['enabled']) && empty($window['excluded']),
            'window_claim_count'=>(int)$window['used'],
            'window_claim_limit'=>(int)$window['limit'],
            'window_hours'=>(int)$window['window_hours'],
            'window_seconds'=>(int)$window['window_hours'] * HOUR_IN_SECONDS,
            'remaining_window_claims'=>max(0, (int)$window['limit'] - (int)$window['used']),
            'next_allowed_at_utc'=>$next_ts ? gmdate('c', $next_ts) : null,
            'next_allowed_at_local'=>$next_ts ? wp_date('Y-m-d H:i', $next_ts, wp_timezone()) : null,
            'next_allowed_label'=>$next_ts ? self::next_allowed_label($next_ts) : '',
            'blocking_reasons'=>$reasons,
        );
    }

    private static function next_allowed_label($timestamp) {
        $seconds = max(0, (int)$timestamp - time());
        $relative = human_time_diff(time(), (int)$timestamp);
        $absolute = wp_date($seconds < DAY_IN_SECONDS ? __('امروز ساعت H:i', 'hamnoor') : __('Y/m/d ساعت H:i', 'hamnoor'), (int)$timestamp, wp_timezone());
        return sprintf(__('%1$s دیگر (%2$s)', 'hamnoor'), $relative, $absolute);
    }

    public static function quota_message($state) {
        $messages = array();
        if (in_array('active_limit', (array)$state['blocking_reasons'], true)) {
            $messages[] = sprintf(__('در حال حاضر %1$d سهم ناتمام دارید. برای انتخاب سهم جدید، ابتدا یکی را تکمیل یا آزاد کنید.', 'hamnoor'), (int)$state['active_claim_count']);
        }
        if (in_array('time_window', (array)$state['blocking_reasons'], true)) {
            $messages[] = sprintf(__('سقف %1$d انتخاب در بازه %2$d ساعته تکمیل شده است؛ از %3$s می‌توانید سهم جدید انتخاب کنید.', 'hamnoor'), (int)$state['window_claim_limit'], (int)$state['window_hours'], (string)$state['next_allowed_label']);
        }
        return implode(' ', $messages);
    }
    public static function normalize_idempotency_key($key) {
        $key = strtolower(trim((string)$key));
        if ('' === $key) { return ''; }
        return preg_match('/^[a-f0-9]{64}$/', $key) ? $key : hash('sha256', $key);
    }

    public static function existing_event($idempotency_key, $event_type = '') {
        global $wpdb;
        $key = self::normalize_idempotency_key($idempotency_key);
        if (!$key) { return null; }
        $table = Hamnoor_DB::table('claim_events');
        if ($event_type) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s AND event_type=%s LIMIT 1", $key, sanitize_key((string)$event_type)));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $key));
    }

    public static function temporal_quota($user_id, $campaign = null) {
        $settings = Hamnoor_Helpers::settings();
        $enabled = !empty($settings['temporal_claim_quota_enabled']) && '1' === (string)$settings['temporal_claim_quota_enabled'];
        $limit = max(1, min(1000, (int)($settings['temporal_claim_limit'] ?? 3)));
        $hours = max(1, min(720, (int)($settings['temporal_claim_window_hours'] ?? 24)));
        $excluded = $campaign && isset($campaign->access_mode) && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)$campaign->access_mode;
        if (!$enabled || !(int)$user_id || $excluded) {
            return array('allowed'=>true,'enabled'=>$enabled,'excluded'=>$excluded,'used'=>0,'limit'=>$limit,'window_hours'=>$hours,'retry_at'=>null);
        }

        global $wpdb;
        $table = Hamnoor_DB::table('claim_events');
        $now_ts = time();
        $window_seconds = $hours * HOUR_IN_SECONDS;
        $since = gmdate('Y-m-d H:i:s', $now_ts - $window_seconds);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT quota_delta,occurred_at_utc FROM $table WHERE user_id=%d AND quota_delta<>0 AND occurred_at_utc >= %s ORDER BY occurred_at_utc ASC,id ASC",
            (int)$user_id,
            $since
        ));
        $used = 0;
        foreach ((array)$rows as $row) { $used += (int)$row->quota_delta; }
        $used = max(0, $used);

        $retry_at = null;
        if ($used >= $limit && $rows) {
            // Credits can expire too, so calculate the earliest future boundary at
            // which the effective rolling sum actually drops below the limit.
            $candidate_times = array();
            foreach ((array)$rows as $row) {
                $ts = strtotime((string)$row->occurred_at_utc . ' UTC');
                if ($ts) { $candidate_times[] = $ts + $window_seconds + 1; }
            }
            sort($candidate_times, SORT_NUMERIC);
            foreach (array_unique($candidate_times) as $candidate) {
                $candidate_since = $candidate - $window_seconds;
                $future_used = 0;
                foreach ((array)$rows as $row) {
                    $ts = strtotime((string)$row->occurred_at_utc . ' UTC');
                    if ($ts && $ts >= $candidate_since) { $future_used += (int)$row->quota_delta; }
                }
                if (max(0, $future_used) < $limit) {
                    $retry_at = gmdate('Y-m-d H:i:s', max($now_ts, $candidate));
                    break;
                }
            }
        }
        return array('allowed'=>$used < $limit,'enabled'=>true,'excluded'=>false,'used'=>$used,'limit'=>$limit,'window_hours'=>$hours,'retry_at'=>$retry_at);
    }

    public static function assert_temporal_quota($user_id, $campaign = null) {
        $q = self::temporal_quota((int)$user_id, $campaign);
        if (!empty($q['allowed'])) { return true; }
        $state = self::quota_state((int)$user_id, $campaign);
        return new WP_Error('hamnoor_temporal_claim_limit', self::quota_message($state), $state);
    }

    public static function record_event($event_type, $campaign_id, $part_id, $participant_id, $user_id, $quota_delta = 0, $idempotency_key = '', $metadata = array(), $occurred_at_utc = '') {
        global $wpdb;
        $table = Hamnoor_DB::table('claim_events');
        $idempotency_key = self::normalize_idempotency_key($idempotency_key);
        if ($idempotency_key) {
            $existing = self::existing_event($idempotency_key);
            if ($existing) { return (int)$existing->id; }
        }
        $now = $occurred_at_utc ?: gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($table, array(
            'public_id'=>Hamnoor_DB::generate_public_id('CEV'),
            'campaign_id'=>(int)$campaign_id,
            'part_id'=>(int)$part_id ?: null,
            'participant_id'=>(int)$participant_id ?: null,
            'user_id'=>(int)$user_id ?: null,
            'event_type'=>sanitize_key((string)$event_type),
            'quota_delta'=>(int)$quota_delta,
            'idempotency_key'=>$idempotency_key ?: null,
            'metadata_json'=>$metadata ? wp_json_encode($metadata) : null,
            'occurred_at_utc'=>$now,
        ), array('%s','%d','%d','%d','%d','%s','%d','%s','%s','%s'));
        if ($ok) { return (int)$wpdb->insert_id; }
        if ($idempotency_key) {
            $existing = self::existing_event($idempotency_key);
            if ($existing) { return (int)$existing->id; }
        }
        return 0;
    }
}
