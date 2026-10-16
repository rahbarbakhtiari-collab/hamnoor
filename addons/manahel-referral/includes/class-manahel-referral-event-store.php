<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Event_Store {
    public static function append($payload) {
        global $wpdb;
        if (!is_array($payload) || empty($payload['event_name']) || empty($payload['entity_type']) || empty($payload['entity_id'])) {
            return new WP_Error('referral_event_invalid', __('رویداد دعوت معتبر نیست.', 'manahel-referral'));
        }
        $occurred = Manahel_Referral_DB::iso_to_mysql_utc($payload['occurred_at'] ?? '');
        if (!$occurred) { return new WP_Error('referral_event_time_invalid', __('زمان رویداد دعوت معتبر نیست.', 'manahel-referral')); }
        $event_name = sanitize_key((string)$payload['event_name']);
        $entity_type = sanitize_key((string)$payload['entity_type']);
        $entity_id = sanitize_text_field((string)$payload['entity_id']);
        $key = hash('sha256', strtolower($event_name) . '|' . strtolower($entity_type) . '|' . strtoupper($entity_id));
        $table = Manahel_Referral_DB::table('events');
        $insert = Manahel_Referral_DB::quiet_insert($table, array(
            'event_name'=>$event_name,
            'schema_version'=>max(1, (int)($payload['schema_version'] ?? 1)),
            'entity_type'=>$entity_type,
            'entity_id'=>$entity_id,
            'event_key'=>$key,
            'payload_json'=>wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'occurred_at'=>$occurred,
            'created_at'=>Manahel_Referral_DB::utc_now_mysql(),
        ));
        if (false !== $insert['result']) { return (int)$insert['insert_id']; }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $key));
        return $existing ? (int)$existing : new WP_Error('referral_event_insert_failed', __('ثبت رویداد دعوت انجام نشد.', 'manahel-referral'));
    }

    public static function get_after($after_id, $limit, $event_name = '') {
        global $wpdb;
        $after_id = max(0, (int)$after_id);
        $limit = max(1, min(100, (int)$limit));
        $table = Manahel_Referral_DB::table('events');
        if ($event_name) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id>%d AND event_name=%s ORDER BY id ASC LIMIT %d",
                $after_id, sanitize_key($event_name), $limit
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT %d", $after_id, $limit));
        }
        $out = array();
        foreach ((array)$rows as $row) {
            $payload = json_decode((string)$row->payload_json, true);
            if (!is_array($payload)) { continue; }
            $out[] = array('id'=>(int)$row->id, 'payload'=>$payload, 'occurred_at'=>(string)$row->occurred_at);
        }
        return $out;
    }
}
