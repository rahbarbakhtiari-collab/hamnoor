<?php
if (!defined('ABSPATH')) { exit; }

/** Optional adapter for durable Manahel Referral growth facts. */
final class Manahel_Rewards_Referral_Adapter {
    const SOURCE = 'manahel_referral';
    const EVENT = 'referral.qualified';
    const CAMPAIGN_EVENT = 'referral.campaign_participant';
    const CURSOR_OPTION = 'manahel_rewards_referral_cursor';
    const CAMPAIGN_CURSOR_OPTION = 'manahel_rewards_referral_campaign_cursor';
    const CRON_HOOK = 'manahel_rewards_referral_reconcile';
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_action('manahel_referral/event/referral.qualified', array(__CLASS__, 'on_qualified'), 20, 2);
        add_action('manahel_referral/event/referral.campaign_participant', array(__CLASS__, 'on_campaign_participant'), 20, 2);
        add_action(self::CRON_HOOK, array(__CLASS__, 'reconcile'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 40);
    }

    public static function ensure_schedule() {
        if (!function_exists('manahel_referral_get_qualified_events_after') && !function_exists('manahel_referral_get_campaign_participant_events_after')) { return; }
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) { return; }
        if (!wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK); }
    }

    public static function on_qualified($payload, $context = array()) { return self::process_payload($payload); }
    public static function on_campaign_participant($payload, $context = array()) { return self::process_campaign_payload($payload); }

    public static function process_payload($payload) {
        if (!is_array($payload)) { return new WP_Error('referral_payload_invalid', __('رویداد دعوت معتبر نیست.', 'manahel-rewards')); }
        $results = array();
        foreach (array('inviter','referred') as $role) {
            $event = Manahel_Rewards_Normalizer::referral_qualified($payload, $role);
            if (is_wp_error($event)) { return $event; }
            $wp_user_id = 'inviter' === $role ? (int)($payload['inviter_wp_user_id'] ?? 0) : (int)($payload['referred_wp_user_id'] ?? 0);
            $results[$role] = self::receive_with_retry($event, $wp_user_id);
        }
        return $results;
    }

    public static function process_campaign_payload($payload) {
        $event = Manahel_Rewards_Normalizer::referral_campaign_participant($payload);
        if (is_wp_error($event)) { return $event; }
        return self::receive_with_retry($event, (int)($payload['inviter_wp_user_id'] ?? 0));
    }

    private static function receive_with_retry($event, $wp_user_id) {
        $result = Manahel_Rewards_Event_Processor::receive($event, (int)$wp_user_id);
        if (is_array($result) && !empty($result['duplicate']) && in_array((string)($result['status'] ?? ''), array('failed','received'), true)) {
            $retry = Manahel_Rewards_Event_Processor::retry((int)$result['event_id']);
            if (!is_wp_error($retry)) { $result = $retry; }
        }
        return $result;
    }

    public static function reconcile($limit = 50) {
        $qualified = self::reconcile_stream('qualified', self::CURSOR_OPTION, 'manahel_referral_get_qualified_events_after', max(1, min(100, (int)$limit)));
        $campaign = self::reconcile_stream('campaign', self::CAMPAIGN_CURSOR_OPTION, 'manahel_referral_get_campaign_participant_events_after', max(1, min(100, (int)$limit)));
        return array(
            'status'=>(('failed' === ($qualified['status'] ?? '')) || ('failed' === ($campaign['status'] ?? ''))) ? 'failed' : 'ok',
            'processed'=>(int)($qualified['processed'] ?? 0) + (int)($campaign['processed'] ?? 0),
            'cursor'=>(int)($qualified['cursor'] ?? 0),
            'campaign_cursor'=>(int)($campaign['cursor'] ?? 0),
        );
    }

    private static function reconcile_stream($type, $option, $function, $limit) {
        if (!function_exists($function)) { return array('status'=>'unavailable','processed'=>0,'cursor'=>(int)get_option($option, 0)); }
        $cursor = max(0, (int)get_option($option, 0));
        $rows = call_user_func($function, $cursor, $limit);
        $processed = 0;
        foreach ((array)$rows as $row) {
            if (empty($row['id']) || empty($row['payload']) || !is_array($row['payload'])) { continue; }
            $result = 'campaign' === $type ? self::process_campaign_payload($row['payload']) : self::process_payload($row['payload']);
            $terminal = 'campaign' === $type ? self::result_terminal($result) : self::results_terminal($result);
            if (is_wp_error($result) || !$terminal) { return array('status'=>'failed','processed'=>$processed,'cursor'=>$cursor); }
            $cursor = (int)$row['id'];
            update_option($option, $cursor, false);
            $processed++;
        }
        return array('status'=>'ok','processed'=>$processed,'cursor'=>$cursor);
    }

    private static function results_terminal($results) {
        if (!is_array($results)) { return false; }
        foreach (array('inviter','referred') as $role) {
            if (!array_key_exists($role, $results) || !self::result_terminal($results[$role])) { return false; }
        }
        return true;
    }

    private static function result_terminal($result) {
        if (is_wp_error($result) || !is_array($result)) { return false; }
        if (!empty($result['duplicate'])) { return in_array((string)($result['status'] ?? ''), array('processed','ignored'), true); }
        return in_array((string)($result['status'] ?? ''), array('processed','ignored'), true);
    }

    public static function wp_user_id_from_stored_payload($payload, $entity_type) {
        if (!is_array($payload)) { return 0; }
        if ('referral_inviter' === (string)$entity_type) { return (int)($payload['inviter_wp_user_id'] ?? 0); }
        if ('referral_referred' === (string)$entity_type) { return (int)($payload['referred_wp_user_id'] ?? 0); }
        if ('campaign_referrer' === (string)$entity_type) { return (int)($payload['inviter_wp_user_id'] ?? 0); }
        return 0;
    }
}
