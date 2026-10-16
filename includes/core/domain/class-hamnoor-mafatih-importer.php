<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Imports explicitly selected Mafatih articles into the Dua Content Registry.
 * The full Mafatih catalogue is never automatically campaign-enabled.
 */
final class Hamnoor_Mafatih_Importer {
    const SOURCE_TYPE = 'mafatih';
    const DEFAULT_SOURCE_RELEASE = '3.3.1';

    public static function load_json_file($path) {
        $path = (string)$path;
        if (!$path || !is_readable($path)) { return new WP_Error('hamnoor_mafatih_file_unreadable', __('فایل داده مفاتیح قابل خواندن نیست.', 'hamnoor')); }
        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) { return new WP_Error('hamnoor_mafatih_file_empty', __('فایل داده مفاتیح خالی است.', 'hamnoor')); }
        $data = json_decode($raw, true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) { return new WP_Error('hamnoor_mafatih_json_invalid', __('ساختار JSON مفاتیح معتبر نیست.', 'hamnoor')); }
        return $data;
    }

    public static function index_articles($data) {
        $index = array();
        self::walk($data, $index);
        return $index;
    }

    private static function walk($node, &$index) {
        if (!is_array($node)) { return; }
        if (isset($node['title'], $node['items']) && is_array($node['items'])) {
            $href = isset($node['href']) ? trim((string)$node['href']) : '';
            if ($href) { $index[$href] = $node; }
        }
        foreach ($node as $value) {
            if (is_array($value)) { self::walk($value, $index); }
        }
    }

    /** Search is for admin selection only; nothing is enabled by search itself. */
    public static function search($data, $term, $limit = 50) {
        $term = trim((string)$term);
        $limit = max(1, min(200, (int)$limit));
        $articles = self::index_articles($data);
        $out = array();
        foreach ($articles as $href=>$article) {
            $title = (string)($article['title'] ?? '');
            if ($term && false === mb_stripos($title . ' ' . $href, $term)) { continue; }
            $out[] = array('source_key'=>$href,'title'=>$title,'href'=>$href,'item_count'=>count((array)$article['items']));
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /**
     * Import only explicit source keys. Imported items default to disabled until
     * the administrator deliberately enables them for campaign creation.
     */
    public static function import_selected($data, $source_keys, $args = array()) {
        if (!current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) {
            return new WP_Error('hamnoor_mafatih_import_denied', __('درون‌ریزی محتوای مفاتیح فقط برای مدیر مجاز است.', 'hamnoor'));
        }
        $index = self::index_articles($data);
        $release = Hamnoor_Validator::trim_len(sanitize_text_field((string)($args['source_release'] ?? self::DEFAULT_SOURCE_RELEASE)), 60);
        $enable = !empty($args['campaign_enabled']);
        $results = array();
        foreach (array_values(array_unique(array_filter(array_map('strval', (array)$source_keys)))) as $key) {
            if (!isset($index[$key])) { $results[$key] = new WP_Error('hamnoor_mafatih_article_missing', __('مورد انتخاب‌شده در منبع مفاتیح پیدا نشد.', 'hamnoor')); continue; }
            $article = $index[$key];
            $payload = array(
                'title'=>(string)$article['title'],
                'href'=>$key,
                'items'=>array_values((array)$article['items']),
                'content_schema'=>isset($article['content_schema']) ? (string)$article['content_schema'] : null,
            );
            $item = Hamnoor_Content_Repository::upsert_item(array(
                'content_type'=>Hamnoor_Campaign_Domain::CONTENT_DUA,
                'source_type'=>self::SOURCE_TYPE,
                'source_key'=>$key,
                'title'=>(string)$article['title'],
                'content_payload'=>$payload,
                'source_release'=>$release,
                'source_href'=>$key,
                'status'=>'active',
                'campaign_enabled'=>$enable ? 1 : 0,
            ));
            $results[$key] = $item;
        }
        return $results;
    }
}
