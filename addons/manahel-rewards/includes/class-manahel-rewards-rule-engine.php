<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Rule_Engine {
    private static $allowed_condition_paths = array(
        'entity_type',
        'campaign_id',
        'context.content_type',
        'context.participation_strategy',
        'context.kind',
        'context.quantity',
        'context.role',
        'context.qualification_source',
        'context.qualification_event',
        'context.qualification_entity_type',
    );

    public static function matching_rules($event) {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('rules');
        $occurred = (string)$event['occurred_at'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE source=%s AND event_name=%s AND enabled=1
               AND (entity_type IS NULL OR entity_type='' OR entity_type=%s)
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (ends_at IS NULL OR ends_at >= %s)
             ORDER BY priority ASC, id ASC",
            (string)$event['source'], (string)$event['event_name'], (string)$event['entity_type'], $occurred, $occurred
        ));

        $matches = array();
        foreach ((array)$rows as $row) {
            $conditions = json_decode((string)$row->conditions_json, true);
            if (!is_array($conditions)) { $conditions = array(); }
            if (self::conditions_match($event, $conditions)) { $matches[] = $row; }
        }
        return $matches;
    }

    public static function conditions_match($event, $conditions) {
        foreach ((array)$conditions as $path => $expected) {
            if (!in_array((string)$path, self::$allowed_condition_paths, true)) { return false; }
            $actual = self::read_path($event, (string)$path);
            if (is_bool($expected)) {
                if ((bool)$actual !== $expected) { return false; }
            } elseif (is_int($expected) || is_float($expected)) {
                if ((string)$actual !== (string)$expected) { return false; }
            } else {
                if ((string)$actual !== (string)$expected) { return false; }
            }
        }
        return true;
    }

    public static function sanitize_conditions($conditions) {
        $safe = array();
        foreach ((array)$conditions as $path => $value) {
            $path = (string)$path;
            if (!in_array($path, self::$allowed_condition_paths, true)) { continue; }
            if ('context.quantity' === $path) {
                $safe[$path] = (int)$value;
            } else {
                $safe[$path] = sanitize_text_field((string)$value);
            }
        }
        return $safe;
    }

    private static function read_path($data, $path) {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) { return null; }
            $current = $current[$segment];
        }
        return $current;
    }
}
