<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Dua audio foundation adapted from Manahel Mafatih Online 3.3.1.
 * Audio metadata lives inside Content Registry payloads; no schema/runtime
 * dependency on the Mafatih plugin is introduced.
 */
final class Hamnoor_Dua_Audio_Service {
    const REFERENCE_VERSION = '3.3.1';
    const DEFAULT_MAX_BYTES = 209715200; // Reference default: configurable 200 MiB.

    public static function allowed_hosts() {
        return (array)apply_filters('hamnoor_dua_audio_allowed_hosts', array('erfan.ir','www.erfan.ir','file.erfan.ir'));
    }

    public static function validate_source_url($url) {
        $url = esc_url_raw(trim((string)$url));
        $parts = wp_parse_url($url);
        if (!$url || !is_array($parts) || 'https' !== strtolower((string)($parts['scheme'] ?? '')) || empty($parts['host'])) {
            return new WP_Error('hamnoor_dua_audio_url_invalid', __('نشانی منبع صوت باید یک نشانی امن HTTPS باشد.', 'hamnoor'));
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!in_array($host, self::allowed_hosts(), true) || !wp_http_validate_url($url)) {
            return new WP_Error('hamnoor_dua_audio_host_denied', __('این میزبان در فهرست منابع مجاز صوت نیست.', 'hamnoor'));
        }
        return $url;
    }

    public static function build_erfan_audio_url($value) {
        $value = trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'));
        if (!$value) { return ''; }
        if (0 === strpos($value, 'https://')) { return is_wp_error(self::validate_source_url($value)) ? '' : $value; }
        $value = ltrim(str_replace('\\', '/', $value), '/');
        $parts = array_map('rawurlencode', array_filter(explode('/', $value), 'strlen'));
        return $parts ? 'https://file.erfan.ir/files/sound/mafatih/' . implode('/', $parts) . '.mp3?seed=10' : '';
    }

    /** Parse the official #js_TogglePlayList select used by erfan.ir. */
    public static function parse_playlist($html, $source_page = '') {
        $html = (string)$html;
        if (strlen($html) > 5242880) { return new WP_Error('hamnoor_dua_audio_page_large', __('صفحه منبع بیش از اندازه بزرگ است.', 'hamnoor')); }
        $options = array();
        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            $old = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors(); libxml_use_internal_errors($old);
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query("//select[@id='js_TogglePlayList']//option[@value]") as $option) {
                $options[] = array('value'=>(string)$option->getAttribute('value'), 'label'=>trim((string)$option->textContent));
            }
        }
        if (!$options && preg_match('/<select[^>]+id=["\']js_TogglePlayList["\'][^>]*>(.*?)<\/select>/is', $html, $match)) {
            preg_match_all('/<option[^>]+value=["\']([^"\']+)["\'][^>]*>(.*?)<\/option>/is', $match[1], $rows, PREG_SET_ORDER);
            foreach ($rows as $row) { $options[] = array('value'=>html_entity_decode($row[1], ENT_QUOTES, 'UTF-8'), 'label'=>trim(wp_strip_all_tags($row[2]))); }
        }
        $items = array(); $order = 0;
        foreach ($options as $option) {
            $url = self::build_erfan_audio_url($option['value']);
            if (!$url) { continue; }
            $label = sanitize_text_field($option['label']);
            $scope = preg_match('/(فراز|بند|قطعه|segment)/ui', $label) ? 'segment' : 'full';
            $segment = 'segment' === $scope ? sanitize_title($label) : '';
            $items[] = self::normalize_item(array(
                'remote_url'=>$url, 'source_page_url'=>$source_page, 'source_value'=>$option['value'],
                'reciter'=>$label ?: __('قاری منبع', 'hamnoor'), 'scope'=>$scope,
                'segment_key'=>$segment, 'segment_title'=>'segment' === $scope ? $label : '',
                'layout_order'=>$order++, 'state'=>'remote',
            ));
        }
        return $items;
    }

    public static function discover($source_page) {
        $valid = self::validate_source_url($source_page);
        if (is_wp_error($valid)) { return $valid; }
        $response = wp_safe_remote_get($valid, array('timeout'=>25, 'redirection'=>5, 'limit_response_size'=>5242880));
        if (is_wp_error($response) || 200 !== (int)wp_remote_retrieve_response_code($response)) {
            return new WP_Error('hamnoor_dua_audio_source_unavailable', __('صفحه منبع صوت در دسترس نیست.', 'hamnoor'));
        }
        $items = self::parse_playlist(wp_remote_retrieve_body($response), $valid);
        return is_wp_error($items) || $items ? $items : new WP_Error('hamnoor_dua_audio_none', __('هیچ صوت معتبری در فهرست رسمی منبع پیدا نشد.', 'hamnoor'));
    }

    public static function normalize_item($item) {
        $item = is_array($item) ? $item : array();
        $remote = esc_url_raw((string)($item['remote_url'] ?? ''));
        $hash = hash('sha256', strtolower($remote));
        return array(
            'id'=>sanitize_key((string)($item['id'] ?? ('aud-' . substr($hash, 0, 16)))),
            'source_hash'=>$hash,
            'remote_url'=>$remote,
            'attachment_id'=>max(0, (int)($item['attachment_id'] ?? 0)),
            'reciter'=>sanitize_text_field((string)($item['reciter'] ?? '')),
            'scope'=>'segment' === ($item['scope'] ?? '') ? 'segment' : 'full',
            'segment_key'=>sanitize_key((string)($item['segment_key'] ?? '')),
            'segment_title'=>sanitize_text_field((string)($item['segment_title'] ?? '')),
            'layout_order'=>(int)($item['layout_order'] ?? 0),
            'source_page_url'=>esc_url_raw((string)($item['source_page_url'] ?? '')),
            'source_value'=>sanitize_text_field((string)($item['source_value'] ?? '')),
            'mime'=>sanitize_mime_type((string)($item['mime'] ?? '')),
            'size'=>max(0, (int)($item['size'] ?? 0)),
            'state'=>!empty($item['attachment_id']) ? 'local' : 'remote',
        );
    }

    public static function playback_url($item) {
        $item = self::normalize_item($item);
        if ($item['attachment_id']) {
            $local = wp_get_attachment_url($item['attachment_id']);
            if ($local) { return $local; }
        }
        return is_wp_error(self::validate_source_url($item['remote_url'])) ? '' : $item['remote_url'];
    }

    public static function probe($url) {
        $valid = self::validate_source_url($url);
        if (is_wp_error($valid)) { return $valid; }
        $response = wp_safe_remote_head($valid, array('timeout'=>20, 'redirection'=>3));
        $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
        if (!in_array($code, array(200,206), true)) {
            $response = wp_safe_remote_get($valid, array('timeout'=>25, 'redirection'=>3, 'headers'=>array('Range'=>'bytes=0-4095'), 'limit_response_size'=>4096));
            $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
        }
        if (is_wp_error($response) || !in_array($code, array(200,206), true)) { return new WP_Error('hamnoor_dua_audio_unavailable', __('فایل صوتی منبع پاسخ معتبر نداد.', 'hamnoor')); }
        $mime = strtolower(trim(explode(';', (string)wp_remote_retrieve_header($response, 'content-type'))[0]));
        if (!(0 === strpos($mime, 'audio/') || in_array($mime, array('application/octet-stream','application/ogg'), true))) {
            return new WP_Error('hamnoor_dua_audio_mime_invalid', __('پاسخ منبع یک فایل صوتی معتبر نیست.', 'hamnoor'));
        }
        $size = (int)wp_remote_retrieve_header($response, 'content-length');
        $range = (string)wp_remote_retrieve_header($response, 'content-range');
        if (preg_match('/\/(\d+)$/', $range, $m)) { $size = (int)$m[1]; }
        $max = max(1048576, (int)apply_filters('hamnoor_dua_audio_max_bytes', self::DEFAULT_MAX_BYTES));
        if ($size > $max) { return new WP_Error('hamnoor_dua_audio_oversized', __('حجم فایل صوتی از سقف انتقال بیشتر است.', 'hamnoor')); }
        $body = (string)wp_remote_retrieve_body($response);
        if ($body && !self::has_audio_signature($body)) { return new WP_Error('hamnoor_dua_audio_signature_invalid', __('محتوای دریافت‌شده امضای فایل صوتی معتبر ندارد.', 'hamnoor')); }
        return array('url'=>$valid,'mime'=>$mime,'size'=>$size,'max_bytes'=>$max);
    }

    private static function has_audio_signature($body) {
        $head = substr((string)$body, 0, 16);
        if (0 === strpos($head, 'ID3') || 0 === strpos($head, 'OggS') || 0 === strpos($head, 'RIFF') || 0 === strpos($head, 'fLaC')) { return true; }
        if (strlen($head) >= 2 && 0xFF === ord($head[0]) && 0xE0 === (ord($head[1]) & 0xE0)) { return true; }
        return strlen($head) >= 8 && 'ftyp' === substr($head, 4, 4);
    }

    public static function transfer($content, $audio_id) {
        if (!$content || Hamnoor_Campaign_Domain::CONTENT_DUA !== (string)$content->content_type) { return new WP_Error('hamnoor_dua_audio_content_invalid', __('محتوای دعای معتبر پیدا نشد.', 'hamnoor')); }
        $payload = json_decode((string)$content->content_payload, true);
        $payload = is_array($payload) ? $payload : array();
        $items = isset($payload['audio_items']) && is_array($payload['audio_items']) ? $payload['audio_items'] : array();
        foreach ($items as $index=>$raw) {
            $item = self::normalize_item($raw);
            if ($item['id'] !== sanitize_key($audio_id)) { continue; }
            if ($item['attachment_id'] && wp_get_attachment_url($item['attachment_id'])) { return $item; }
            $probe = self::probe($item['remote_url']);
            if (is_wp_error($probe)) { return $probe; }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $tmp = download_url($probe['url'], 300);
            if (is_wp_error($tmp)) { return new WP_Error('hamnoor_dua_audio_download_failed', __('انتقال فایل صوتی انجام نشد.', 'hamnoor')); }
            $name = sanitize_file_name(($content->title ?: 'dua') . '-' . ($item['reciter'] ?: 'audio') . '.mp3');
            $attachment_id = media_handle_sideload(array('name'=>$name,'tmp_name'=>$tmp), 0, (string)$content->title);
            if (is_wp_error($attachment_id)) { @unlink($tmp); return new WP_Error('hamnoor_dua_audio_media_failed', __('ثبت فایل در کتابخانه رسانه انجام نشد.', 'hamnoor')); }
            update_post_meta($attachment_id, '_hamnoor_dua_audio_source_url', $item['remote_url']);
            update_post_meta($attachment_id, '_hamnoor_dua_audio_source_page', $item['source_page_url']);
            update_post_meta($attachment_id, '_hamnoor_dua_audio_item_id', $item['id']);
            update_post_meta($attachment_id, '_hamnoor_dua_audio_reciter', $item['reciter']);
            delete_post_thumbnail($attachment_id);
            $item['attachment_id'] = (int)$attachment_id; $item['state'] = 'local'; $item['mime'] = $probe['mime']; $item['size'] = $probe['size'];
            $tagged = Hamnoor_Dua_ID3_Service::sync_attachment($attachment_id, $item, (string)$content->title);
            if (is_wp_error($tagged)) { wp_delete_attachment($attachment_id, true); return $tagged; }
            $items[$index] = $item; $payload['audio_items'] = array_values($items);
            $saved = Hamnoor_Content_Repository::upsert_item(array('id'=>(int)$content->id,'content_payload'=>$payload));
            return is_wp_error($saved) ? $saved : $item;
        }
        return new WP_Error('hamnoor_dua_audio_not_found', __('صوت انتخاب‌شده پیدا نشد.', 'hamnoor'));
    }

    public static function merge_discovered($content, $source_page, $discovered) {
        $payload = json_decode((string)$content->content_payload, true); $payload = is_array($payload) ? $payload : array();
        $old = isset($payload['audio_items']) && is_array($payload['audio_items']) ? $payload['audio_items'] : array();
        $by_hash = array(); foreach ($old as $item) { $item=self::normalize_item($item); $by_hash[$item['source_hash']]=$item; }
        foreach ($discovered as $item) { $item=self::normalize_item($item); if (isset($by_hash[$item['source_hash']])) { $item['attachment_id']=$by_hash[$item['source_hash']]['attachment_id']; $item['state']=$item['attachment_id']?'local':'remote'; } $by_hash[$item['source_hash']]=$item; }
        $payload['audio_source_page'] = esc_url_raw($source_page); $payload['audio_items'] = array_values($by_hash);
        return Hamnoor_Content_Repository::upsert_item(array('id'=>(int)$content->id,'content_payload'=>$payload));
    }

    public static function attach_existing($content, $audio_id, $attachment_id) {
        if (!$content || Hamnoor_Campaign_Domain::CONTENT_DUA !== (string)$content->content_type) { return new WP_Error('hamnoor_dua_audio_content_invalid', __('محتوای دعا معتبر نیست.', 'hamnoor')); }
        $attachment_id=absint($attachment_id);
        if(!$attachment_id||'attachment'!==get_post_type($attachment_id)||0!==strpos((string)get_post_mime_type($attachment_id),'audio/')){return new WP_Error('hamnoor_dua_audio_attachment_invalid',__('فقط یک فایل صوتی موجود در کتابخانه رسانه انتخاب کنید.','hamnoor'));}
        $payload=json_decode((string)$content->content_payload,true);$payload=is_array($payload)?$payload:array();$items=(array)($payload['audio_items']??array());
        foreach($items as $index=>$raw){$item=self::normalize_item($raw);if($item['id']!==sanitize_key($audio_id)){continue;}$item['attachment_id']=$attachment_id;$item['state']='local';$item['mime']=sanitize_mime_type((string)get_post_mime_type($attachment_id));$items[$index]=$item;$tag=Hamnoor_Dua_ID3_Service::sync_attachment($attachment_id,$item,(string)$content->title);if(is_wp_error($tag)){return $tag;}$payload['audio_items']=array_values($items);return Hamnoor_Content_Repository::upsert_item(array('id'=>(int)$content->id,'content_payload'=>$payload));}
        return new WP_Error('hamnoor_dua_audio_not_found',__('صوت انتخاب‌شده پیدا نشد.','hamnoor'));
    }

    public static function reorder($content, $ordered_ids) {
        $payload=json_decode((string)$content->content_payload,true);$payload=is_array($payload)?$payload:array();$items=(array)($payload['audio_items']??array());$map=array();foreach($items as $raw){$item=self::normalize_item($raw);$map[$item['id']]=$item;}$result=array();
        foreach((array)$ordered_ids as $id){$id=sanitize_key($id);if(isset($map[$id])){$map[$id]['layout_order']=count($result);$result[]=$map[$id];unset($map[$id]);}}
        foreach($map as $item){$item['layout_order']=count($result);$result[]=$item;}$payload['audio_items']=$result;return Hamnoor_Content_Repository::upsert_item(array('id'=>(int)$content->id,'content_payload'=>$payload));
    }
}
