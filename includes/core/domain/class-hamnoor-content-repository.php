<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Live content registry for Dua/Dhikr campaign content.
 *
 * Content is intentionally not version-pinned to campaigns. Editorial changes
 * are human/admin decisions and every campaign referencing an item reads the
 * current item payload. Generic audit logging may record edits separately.
 */
final class Hamnoor_Content_Repository {
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Hamnoor_DB::table('content_items') . ' WHERE id=%d LIMIT 1', (int)$id));
    }

    public static function get_by_public_id($public_id) {
        global $wpdb;
        $public_id = strtoupper(trim((string)$public_id));
        if (!preg_match('/^CNT-[A-Z0-9]{12,28}$/', $public_id)) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Hamnoor_DB::table('content_items') . ' WHERE public_id=%s LIMIT 1', $public_id));
    }

    public static function find_by_source($content_type, $source_type, $source_key) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Hamnoor_DB::table('content_items') . ' WHERE content_type=%s AND source_type=%s AND source_key=%s LIMIT 1',
            sanitize_key((string)$content_type), sanitize_key((string)$source_type), sanitize_text_field((string)$source_key)
        ));
    }

    /**
     * Read-only filtered registry query used by admin curation and later UI adapters.
     * All filter and ordering dimensions are whitelisted here so presentation code
     * never needs to compose SQL against the registry directly.
     */
    public static function query_items($args = array()) {
        global $wpdb;
        $args = wp_parse_args((array)$args, array(
            'content_type'=>'',
            'source_type'=>'',
            'status'=>'',
            'campaign_enabled'=>null,
            'search'=>'',
            'limit'=>50,
            'offset'=>0,
        ));

        $where = array('1=1');
        $params = array();
        $type = sanitize_key((string)$args['content_type']);
        if ($type && in_array($type, array(Hamnoor_Campaign_Domain::CONTENT_DUA, Hamnoor_Campaign_Domain::CONTENT_DHIKR), true)) {
            $where[] = 'content_type=%s';
            $params[] = $type;
        }
        $source = sanitize_key((string)$args['source_type']);
        if ($source) {
            $where[] = 'source_type=%s';
            $params[] = $source;
        }
        $status = sanitize_key((string)$args['status']);
        if ($status && in_array($status, array('active','disabled'), true)) {
            $where[] = 'status=%s';
            $params[] = $status;
        }
        if (null !== $args['campaign_enabled'] && '' !== $args['campaign_enabled']) {
            $where[] = 'campaign_enabled=%d';
            $params[] = !empty($args['campaign_enabled']) ? 1 : 0;
        }
        $search = trim(sanitize_text_field((string)$args['search']));
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(title LIKE %s OR public_id LIKE %s OR source_key LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $limit = max(1, min(200, (int)$args['limit']));
        $offset = max(0, (int)$args['offset']);
        $sql = 'SELECT * FROM ' . Hamnoor_DB::table('content_items')
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY updated_at_utc DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** Creator-facing lookup: only active, explicitly enabled content is returned. */
    public static function creator_items($content_type, $search = '', $limit = 50) {
        return self::query_items(array(
            'content_type'=>$content_type,
            'status'=>'active',
            'campaign_enabled'=>1,
            'search'=>$search,
            'limit'=>$limit,
        ));
    }

    public static function upsert_item($data) {
        global $wpdb;
        $data = is_array($data) ? $data : array();

        $existing = null;
        if (!empty($data['id'])) { $existing = self::get((int)$data['id']); }
        elseif (!empty($data['public_id'])) { $existing = self::get_by_public_id((string)$data['public_id']); }
        elseif (!empty($data['source_key']) && !empty($data['content_type'])) {
            $existing = self::find_by_source(
                sanitize_key((string)$data['content_type']),
                sanitize_key((string)($data['source_type'] ?? 'manual')),
                sanitize_text_field((string)$data['source_key'])
            );
        }

        $type = sanitize_key((string)($data['content_type'] ?? ($existing->content_type ?? '')));
        $source_type = sanitize_key((string)($data['source_type'] ?? ($existing->source_type ?? 'manual')));
        $source_key = array_key_exists('source_key', $data)
            ? sanitize_text_field((string)$data['source_key'])
            : (string)($existing->source_key ?? '');
        $title = array_key_exists('title', $data)
            ? Hamnoor_Validator::trim_len(sanitize_text_field((string)$data['title']), 191)
            : Hamnoor_Validator::trim_len(sanitize_text_field((string)($existing->title ?? '')), 191);
        if (!in_array($type, array(Hamnoor_Campaign_Domain::CONTENT_DUA, Hamnoor_Campaign_Domain::CONTENT_DHIKR), true) || !$title) {
            return new WP_Error('hamnoor_content_invalid', __('اطلاعات محتوای پویش معتبر نیست.', 'hamnoor'));
        }

        $payload = array_key_exists('content_payload', $data) ? $data['content_payload'] : ($existing->content_payload ?? null);
        if (is_array($payload) || is_object($payload)) {
            $payload = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (null !== $payload) {
            $payload = (string)$payload;
        }

        $status = array_key_exists('status', $data) ? sanitize_key((string)$data['status']) : (string)($existing->status ?? 'active');
        if (!in_array($status, array('active','disabled'), true)) { $status = 'active'; }
        $campaign_enabled = array_key_exists('campaign_enabled', $data)
            ? (!empty($data['campaign_enabled']) ? 1 : 0)
            : (int)($existing->campaign_enabled ?? 0);
        if ('active' !== $status) { $campaign_enabled = 0; }
        $max_contribution = array_key_exists('max_contribution', $data)
            ? ((int)$data['max_contribution'] > 0 ? (int)$data['max_contribution'] : null)
            : (isset($existing->max_contribution) ? (int)$existing->max_contribution : null);
        $source_release = array_key_exists('source_release', $data)
            ? Hamnoor_Validator::trim_len(sanitize_text_field((string)$data['source_release']), 60)
            : (string)($existing->source_release ?? '');
        $source_href = array_key_exists('source_href', $data)
            ? esc_url_raw((string)$data['source_href'])
            : (string)($existing->source_href ?? '');

        $now = gmdate('Y-m-d H:i:s');
        $values = array(
            'content_type'=>$type,
            'source_type'=>$source_type ?: 'manual',
            'source_key'=>$source_key ?: null,
            'title'=>$title,
            'content_payload'=>$payload,
            'source_release'=>$source_release ?: null,
            'source_href'=>$source_href ?: null,
            'status'=>$status,
            'campaign_enabled'=>$campaign_enabled,
            'max_contribution'=>$max_contribution,
            'updated_by_wp_user_id'=>get_current_user_id() ?: null,
            'updated_at_utc'=>$now,
        );
        $formats = array('%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s');
        if ($existing) {
            $ok = $wpdb->update(Hamnoor_DB::table('content_items'), $values, array('id'=>(int)$existing->id), $formats, array('%d'));
            if (false === $ok) {
                error_log('[Hamnoor] Content item update failed: ' . $wpdb->last_error);
                return new WP_Error('hamnoor_content_write_failed', __('ثبت محتوای پویش انجام نشد.', 'hamnoor'));
            }
            return self::get((int)$existing->id);
        }
        $values['public_id'] = Hamnoor_DB::generate_public_id('CNT');
        $values['created_at_utc'] = $now;
        $formats[] = '%s';
        $formats[] = '%s';
        $ok = $wpdb->insert(Hamnoor_DB::table('content_items'), $values, $formats);
        return $ok ? self::get((int)$wpdb->insert_id) : new WP_Error('hamnoor_content_write_failed', __('ثبت محتوای پویش انجام نشد.', 'hamnoor'));
    }
}
