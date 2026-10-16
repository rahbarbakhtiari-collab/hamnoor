<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Hamnoor_Adapter {
    const CURSOR_OPTION = 'manahel_rewards_hamnoor_participation_cursor';
    const CRON_HOOK = 'manahel_rewards_hamnoor_reconcile';
    private static $registered = false;
    private static $request_results = array();

    public static function init() {
        if (self::$registered || !function_exists('hamnoor_core_api')) { return; }
        $api = hamnoor_core_api();
        if (!$api || !is_object($api) || !method_exists($api, 'on')) { return; }
        self::$registered = (bool)$api->on(
            'participation.completed',
            array(__CLASS__, 'on_participation_completed'),
            20,
            'manahel-rewards'
        );
        add_action(self::CRON_HOOK, array(__CLASS__, 'reconcile'));
        add_action('init', array(__CLASS__, 'ensure_reconcile_schedule'), 40);
    }

    public static function is_registered() {
        return self::$registered;
    }

    public static function on_participation_completed($payload, $context = array()) {
        return self::process_payload($payload, $context, empty($context['replayed']));
    }

    private static function process_payload($payload, $context = array(), $record_request_result = false) {
        $event = Manahel_Rewards_Normalizer::hamnoor_participation_completed($payload, $context);
        if (is_wp_error($event)) { return $event; }
        $wp_user_id = self::resolve_wp_user_id((int)$event['source_subject_user_id']);
        $result = Manahel_Rewards_Event_Processor::receive($event, $wp_user_id);
        if (is_array($result) && !empty($result['duplicate']) && in_array((string)($result['status'] ?? ''), array('failed','received'), true)) {
            $retry = Manahel_Rewards_Event_Processor::retry((int)$result['event_id']);
            if (!is_wp_error($retry)) { $result = $retry; }
        }
        if ($record_request_result) {
            self::$request_results[self::request_key((string)$event['entity_type'], (string)$event['entity_id'])] = array(
                'event' => $event,
                'wp_user_id' => $wp_user_id,
                'result' => $result,
            );
        }
        return $result;
    }

    public static function ensure_reconcile_schedule() {
        $api = function_exists('hamnoor_core_api') ? hamnoor_core_api() : null;
        if (!$api || !is_object($api) || !method_exists($api, 'durable_events_after')) { return; }
        if (!wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK); }
    }

    public static function reconcile($limit = 50) {
        $api = function_exists('hamnoor_core_api') ? hamnoor_core_api() : null;
        if (!$api || !is_object($api) || !method_exists($api, 'durable_events_after')) {
            return array('status'=>'unavailable','processed'=>0,'cursor'=>(int)get_option(self::CURSOR_OPTION, 0));
        }
        $limit = max(1, min(100, (int)$limit));
        $cursor = max(0, (int)get_option(self::CURSOR_OPTION, 0));
        $rows = $api->durable_events_after('participation.completed', $cursor, $limit);
        $processed = 0;
        foreach ((array)$rows as $row) {
            if (empty($row['id']) || empty($row['payload']) || !is_array($row['payload'])) { continue; }
            $context = isset($row['context']) && is_array($row['context']) ? $row['context'] : array();
            $context['replayed'] = true;
            $result = self::process_payload($row['payload'], $context, false);
            if (!self::result_terminal($result)) { return array('status'=>'failed','processed'=>$processed,'cursor'=>$cursor); }
            $cursor = (int)$row['id'];
            update_option(self::CURSOR_OPTION, $cursor, false);
            $processed++;
        }
        return array('status'=>'ok','processed'=>$processed,'cursor'=>$cursor);
    }

    private static function result_terminal($result) {
        if (is_wp_error($result) || !is_array($result)) { return false; }
        if (!empty($result['duplicate'])) { return in_array((string)($result['status'] ?? ''), array('processed','ignored'), true); }
        return in_array((string)($result['status'] ?? ''), array('processed','ignored'), true);
    }

    /** Result exists only for completion events observed in this PHP request. */
    public static function request_result_for_payload($payload) {
        if (!is_array($payload)) { return null; }
        $key = self::request_key((string)($payload['entity_type'] ?? ''), (string)($payload['entity_id'] ?? ''));
        return $key && array_key_exists($key, self::$request_results) ? self::$request_results[$key] : null;
    }

    private static function request_key($entity_type, $entity_id) {
        $entity_type = sanitize_key((string)$entity_type);
        $entity_id = trim((string)$entity_id);
        return ($entity_type && $entity_id) ? $entity_type . '|' . $entity_id : '';
    }

    public static function resolve_wp_user_id($hamnoor_user_id) {
        $hamnoor_user_id = (int)$hamnoor_user_id;
        if ($hamnoor_user_id <= 0) { return 0; }

        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'get_user')) {
            $user = Hamnoor_Mobile_Access::get_user($hamnoor_user_id);
            if ($user && !empty($user->wp_user_id) && get_user_by('id', (int)$user->wp_user_id)) {
                return (int)$user->wp_user_id;
            }
        }

        // Read-only compatibility fallback. Rewards never creates or relinks a
        // WordPress identity; Hamnoor remains authoritative for that mapping.
        $ids = get_users(array(
            'meta_key' => '_hamnoor_user_id',
            'meta_value' => (string)$hamnoor_user_id,
            'number' => 2,
            'fields' => 'ID',
        ));
        return 1 === count((array)$ids) ? (int)reset($ids) : 0;
    }
}
