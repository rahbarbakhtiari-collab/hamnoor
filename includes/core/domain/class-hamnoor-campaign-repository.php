<?php
if (!defined('ABSPATH')) { exit; }

/** Canonical persistence boundary for Campaign v2 consumers. */
final class Hamnoor_Campaign_Repository {
    public static function get_by_id($id, $for_update = false) {
        global $wpdb;
        $table = Hamnoor_DB::table('khatms');
        $sql = $wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1" . ($for_update ? ' FOR UPDATE' : ''), (int)$id);
        return $wpdb->get_row($sql);
    }

    public static function get_by_public_id($public_id, $for_update = false) {
        global $wpdb;
        $public_id = self::normalize_public_id($public_id);
        if (!$public_id) { return null; }
        $table = Hamnoor_DB::table('khatms');
        $sql = $wpdb->prepare("SELECT * FROM $table WHERE campaign_public_id=%s LIMIT 1" . ($for_update ? ' FOR UPDATE' : ''), $public_id);
        return $wpdb->get_row($sql);
    }

    public static function normalize_public_id($value) {
        $value = strtoupper(trim((string)$value));
        return preg_match('/^CMP-[A-Z0-9]{12,28}$/', $value) ? $value : '';
    }

    /** Query only canonical fields. Internal integer IDs never leave this repository. */
    public static function query($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'content_type'=>'', 'strategy'=>'', 'access_mode'=>'', 'lifecycle_status'=>'',
            'review_status'=>'', 'owner_user_id'=>0, 'search'=>'', 'limit'=>20, 'offset'=>0,
            'order'=>'DESC',
        ));
        $table = Hamnoor_DB::table('khatms');
        $where = array('1=1');
        $params = array();
        $map = array(
            'content_type'=>'content_type', 'strategy'=>'participation_strategy', 'access_mode'=>'access_mode',
            'lifecycle_status'=>'lifecycle_status', 'review_status'=>'review_status',
        );
        foreach ($map as $arg=>$column) {
            if ('' !== (string)$args[$arg]) { $where[] = "$column=%s"; $params[] = sanitize_key((string)$args[$arg]); }
        }
        if (!empty($args['owner_user_id'])) { $where[] = 'owner_user_id=%d'; $params[] = (int)$args['owner_user_id']; }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field((string)$args['search'])) . '%';
            $where[] = '(title LIKE %s OR intention LIKE %s OR owner_name LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(100, (int)$args['limit']));
        $offset = max(0, (int)$args['offset']);
        $order = 'ASC' === strtoupper((string)$args['order']) ? 'ASC' : 'DESC';
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY id $order LIMIT %d OFFSET %d";
        $params[] = $limit; $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** Returns the owner's current non-terminal personal Quran completion, if any. */
    public static function find_open_personal_quran_for_owner($owner_user_id, $for_update = false) {
        global $wpdb;
        $owner_user_id = (int)$owner_user_id;
        if (!$owner_user_id) { return null; }
        $table = Hamnoor_DB::table('khatms');
        $statuses = Hamnoor_Campaign_Domain::personal_quran_open_statuses();
        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE owner_user_id=%d AND content_type=%s AND participation_strategy=%s AND access_mode=%s AND participation_policy=%s AND lifecycle_status IN (%s,%s) ORDER BY id DESC LIMIT 1" . ($for_update ? ' FOR UPDATE' : ''),
            $owner_user_id,
            Hamnoor_Campaign_Domain::CONTENT_QURAN,
            Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR,
            Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY,
            Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY,
            $statuses[0],
            $statuses[1]
        );
        return $wpdb->get_row($sql);
    }

    public static function progress($campaign) {
        if (!$campaign) { return array('current'=>0,'target'=>0,'percentage'=>0,'is_completed'=>false); }
        $strategy = Hamnoor_Participation_Strategy_Registry::get((string)$campaign->participation_strategy);
        if (!$strategy) { return new WP_Error('hamnoor_strategy_not_registered', __('مدل مشارکت پویش ثبت نشده است.', 'hamnoor')); }
        $progress = $strategy->progress($campaign);
        if (is_wp_error($progress)) { return $progress; }
        // Normalize every strategy to one stable API/domain shape.
        if (Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED === (string)$campaign->participation_strategy) {
            return array(
                'current'=>(int)($progress['done'] ?? 0),
                'target'=>(int)($progress['total'] ?? 0),
                'percentage'=>(float)($progress['percent'] ?? 0),
                'is_completed'=>!empty($progress['total']) && (int)($progress['done'] ?? 0) >= (int)$progress['total'],
            );
        }
        return array(
            'current'=>(int)($progress['current'] ?? 0),
            'target'=>(int)($progress['target'] ?? 0),
            'percentage'=>(float)($progress['percentage'] ?? 0),
            'is_completed'=>!empty($progress['is_completed']),
        );
    }

    public static function update_fields($campaign_id, $fields) {
        global $wpdb;
        if (!$fields) { return true; }
        $fields['updated_at'] = current_time('mysql');
        return false !== $wpdb->update(Hamnoor_DB::table('khatms'), $fields, array('id'=>(int)$campaign_id));
    }
}
