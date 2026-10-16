<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Durable, privacy-minimal journal for canonical Hamnoor domain facts.
 *
 * Core owns only the fact log. Optional plugins keep their own cursors and
 * idempotency walls, so Core never needs to know about Referral or Rewards.
 */
final class Hamnoor_Domain_Event_Store {
    public static function append($event, $payload, $context = array()) {
        global $wpdb;
        $event = strtolower(trim((string)$event));
        $payload = is_array($payload) ? $payload : array();
        $context = is_array($context) ? $context : array();
        $business_key = isset($context['event_key']) ? trim((string)$context['event_key']) : '';
        if (!$event || !$business_key || empty($payload['event_name']) || empty($payload['schema_version'])) {
            return new WP_Error('hamnoor_domain_event_invalid', 'Durable domain event is incomplete.');
        }
        $table = Hamnoor_DB::table('domain_events');
        if (!$table) { return new WP_Error('hamnoor_domain_event_table_unavailable', 'Domain event table is unavailable.'); }
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string)$exists !== (string)$table) { return new WP_Error('hamnoor_domain_event_table_missing', 'Domain event table is missing.'); }

        $occurred = self::iso_to_mysql_utc(isset($payload['occurred_at']) ? $payload['occurred_at'] : '');
        if (!$occurred) { $occurred = gmdate('Y-m-d H:i:s'); }
        $event_key = hash('sha256', $business_key);
        // One atomic INSERT/duplicate resolution keeps the journal idempotent
        // even when the same committed business fact is announced twice at the
        // same instant. LAST_INSERT_ID(id) lets callers receive the winning row
        // without first doing a race-prone SELECT or emitting a duplicate-key
        // database error into production logs.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
             (event_key,event_name,schema_version,subject_user_id,entity_type,entity_id,payload_json,context_json,occurred_at_utc,created_at_utc)
             VALUES (%s,%s,%d,%d,%s,%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $event_key,
            substr(sanitize_key($event), 0, 96),
            max(1, (int)$payload['schema_version']),
            !empty($payload['subject_user_id']) ? (int)$payload['subject_user_id'] : 0,
            substr(sanitize_key((string)($payload['entity_type'] ?? '')), 0, 48),
            substr(sanitize_text_field((string)($payload['entity_id'] ?? '')), 0, 128),
            wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            wp_json_encode(self::safe_context($context), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $occurred,
            gmdate('Y-m-d H:i:s')
        );
        $result = $wpdb->query($sql);
        if (false !== $result && (int)$wpdb->insert_id > 0) { return (int)$wpdb->insert_id; }

        // Defensive fallback for connectors/drivers that do not expose
        // LAST_INSERT_ID on the duplicate path.
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key));
        return $existing ? (int)$existing : new WP_Error('hamnoor_domain_event_insert_failed', $wpdb->last_error ?: 'Could not persist domain event.');
    }

    public static function get_after($event, $after_id = 0, $limit = 50) {
        global $wpdb;
        $event = substr(sanitize_key((string)$event), 0, 96);
        $after_id = max(0, (int)$after_id);
        $limit = max(1, min(200, (int)$limit));
        if (!$event) { return array(); }
        $table = Hamnoor_DB::table('domain_events');
        if (!$table) { return array(); }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,event_name,schema_version,payload_json,context_json,occurred_at_utc FROM {$table} WHERE id>%d AND event_name=%s ORDER BY id ASC LIMIT %d",
            $after_id, $event, $limit
        ));
        $out = array();
        foreach ((array)$rows as $row) {
            $payload = json_decode((string)$row->payload_json, true);
            $context = json_decode((string)$row->context_json, true);
            if (!is_array($payload)) { continue; }
            $context = is_array($context) ? $context : array();
            $context['durable_event_id'] = (int)$row->id;
            $context['replayed'] = true;
            $out[] = array(
                'id'=>(int)$row->id,
                'event_name'=>(string)$row->event_name,
                'schema_version'=>(int)$row->schema_version,
                'payload'=>$payload,
                'context'=>$context,
                'occurred_at_utc'=>(string)$row->occurred_at_utc,
            );
        }
        return $out;
    }

    public static function latest_for_subject($event, $subject_user_id) {
        global $wpdb;
        $event = substr(sanitize_key((string)$event), 0, 96);
        $subject_user_id = (int)$subject_user_id;
        if (!$event || $subject_user_id <= 0) { return null; }
        $table = Hamnoor_DB::table('domain_events');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,event_name,schema_version,payload_json,context_json,occurred_at_utc FROM {$table} WHERE event_name=%s AND subject_user_id=%d ORDER BY id DESC LIMIT 1",
            $event, $subject_user_id
        ));
        if (!$row) { return null; }
        $payload = json_decode((string)$row->payload_json, true);
        $context = json_decode((string)$row->context_json, true);
        if (!is_array($payload)) { return null; }
        $context = is_array($context) ? $context : array();
        $context['durable_event_id'] = (int)$row->id;
        $context['replayed'] = true;
        return array('id'=>(int)$row->id,'payload'=>$payload,'context'=>$context,'occurred_at_utc'=>(string)$row->occurred_at_utc);
    }

    private static function safe_context($context) {
        $out = array();
        foreach (array('source','schema_version','emitted_at','event_key') as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) { $out[$key] = (string)$context[$key]; }
        }
        return $out;
    }

    private static function iso_to_mysql_utc($value) {
        try {
            $value = trim((string)$value);
            if (!$value) { return ''; }
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) { return ''; }
    }
}
