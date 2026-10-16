<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Administrative curation UI for the live Dua/Dhikr Content Registry.
 *
 * This adapter never creates campaigns and never introduces content versions.
 * Mafatih JSON is staged temporarily, searched by the administrator, and only
 * explicitly selected articles are imported. Imported/manual content remains
 * unavailable to campaign creators until a separate enable action is taken.
 */
final class Hamnoor_Content_Admin {
    const PAGE_SLUG = 'hamnoor-content-registry';
    const STAGE_TTL = 7200;
    const MAX_SOURCE_BYTES = 20971520; // 20 MiB; Mafatih 3.1.0 is ~6.7 MB.

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('admin_post_hamnoor_content_stage_mafatih', array(__CLASS__, 'stage_mafatih'));
        add_action('admin_post_hamnoor_content_clear_mafatih', array(__CLASS__, 'clear_mafatih'));
        add_action('admin_post_hamnoor_content_import_mafatih', array(__CLASS__, 'import_mafatih'));
        add_action('admin_post_hamnoor_content_save_manual', array(__CLASS__, 'save_manual'));
        add_action('admin_post_hamnoor_content_toggle', array(__CLASS__, 'toggle_item'));
        add_action('admin_post_hamnoor_dua_audio_discover', array(__CLASS__, 'discover_dua_audio'));
        add_action('admin_post_hamnoor_dua_audio_transfer', array(__CLASS__, 'transfer_dua_audio'));
        add_action('admin_post_hamnoor_dua_audio_attach', array(__CLASS__, 'attach_dua_audio'));
        add_action('admin_post_hamnoor_dua_audio_reorder', array(__CLASS__, 'reorder_dua_audio'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_audio_admin'));
    }

    private static function can_manage() {
        return current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
    }

    private static function require_manage() {
        if (!self::can_manage()) {
            wp_die(esc_html__('دسترسی مدیریت محتوای پویش را ندارید.', 'hamnoor'), 403);
        }
    }

    public static function menu() {
        add_submenu_page(
            'hamnoor',
            __('محتوای دعا و ذکر', 'hamnoor'),
            __('محتوای دعا و ذکر', 'hamnoor'),
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'page')
        );
    }

    private static function page_url($args = array()) {
        return add_query_arg(array_merge(array('page'=>self::PAGE_SLUG), (array)$args), admin_url('admin.php'));
    }

    private static function redirect($notice, $type = 'success', $extra = array()) {
        $args = array_merge(array(
            'hamnoor_content_notice'=>sanitize_key((string)$notice),
            'hamnoor_content_notice_type'=>sanitize_key((string)$type),
        ), (array)$extra);
        wp_safe_redirect(self::page_url($args));
        exit;
    }

    private static function notice_text($code) {
        $map = array(
            'source_staged'=>__('منبع مفاتیح با موفقیت بررسی و برای انتخاب موارد آماده شد.', 'hamnoor'),
            'source_cleared'=>__('منبع موقت مفاتیح پاک شد.', 'hamnoor'),
            'source_missing'=>__('ابتدا فایل JSON مفاتیح را بارگذاری کنید.', 'hamnoor'),
            'source_invalid'=>__('فایل مفاتیح معتبر نیست یا قابل خواندن نیست.', 'hamnoor'),
            'source_too_large'=>__('حجم فایل مفاتیح از سقف مجاز ۲۰ مگابایت بیشتر است.', 'hamnoor'),
            'source_upload_failed'=>__('بارگذاری فایل مفاتیح انجام نشد.', 'hamnoor'),
            'import_none'=>__('حداقل یک مطلب مفاتیح را برای درون‌ریزی انتخاب کنید.', 'hamnoor'),
            'import_done'=>__('مطالب انتخاب‌شده وارد Content Registry شدند و طبق سیاست ایمن، هنوز برای ساخت پویش غیرفعال‌اند.', 'hamnoor'),
            'manual_saved'=>__('محتوای دستی ذخیره شد و تا زمان فعال‌سازی جداگانه در Creator نمایش داده نمی‌شود.', 'hamnoor'),
            'manual_invalid'=>__('عنوان و متن محتوای دستی را کامل کنید.', 'hamnoor'),
            'item_enabled'=>__('این محتوا برای ساخت پویش فعال شد.', 'hamnoor'),
            'item_disabled'=>__('این محتوا از فهرست ساخت پویش خارج شد.', 'hamnoor'),
            'status_active'=>__('وضعیت محتوا فعال شد؛ فعال‌سازی برای Creator همچنان تصمیم جداگانه است.', 'hamnoor'),
            'status_disabled'=>__('محتوا غیرفعال شد و هم‌زمان از Creator نیز خارج شد.', 'hamnoor'),
            'item_not_found'=>__('محتوای موردنظر پیدا نشد.', 'hamnoor'),
            'write_failed'=>__('ثبت تغییرات محتوا انجام نشد.', 'hamnoor'),
            'audio_discovered'=>__('فهرست رسمی صوت بررسی و بدون دانلود خودکار در محتوا ثبت شد.', 'hamnoor'),
            'audio_transferred'=>__('صوت انتخاب‌شده به کتابخانه رسانه منتقل شد و نسخه محلی در اولویت پخش قرار گرفت.', 'hamnoor'),
            'audio_failed'=>__('مدیریت صوت انجام نشد؛ منبع، نوع فایل یا دسترسی را بررسی کنید.', 'hamnoor'),
        );
        return isset($map[$code]) ? $map[$code] : '';
    }

    private static function render_notice() {
        $code = isset($_GET['hamnoor_content_notice']) ? sanitize_key(wp_unslash($_GET['hamnoor_content_notice'])) : '';
        if (!$code) { return; }
        $text = self::notice_text($code);
        if (!$text) { return; }
        $type = isset($_GET['hamnoor_content_notice_type']) ? sanitize_key(wp_unslash($_GET['hamnoor_content_notice_type'])) : 'success';
        $class = 'error' === $type ? 'notice-error' : ('warning' === $type ? 'notice-warning' : 'notice-success');
        $count = isset($_GET['count']) ? max(0, (int)$_GET['count']) : 0;
        if ('import_done' === $code && $count) {
            $text .= ' ' . sprintf(__('تعداد موفق: %d', 'hamnoor'), $count);
        }
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private static function stage_key() {
        return 'hamnoor_mafatih_stage_' . (int)get_current_user_id();
    }

    private static function staging_dir() {
        $base = function_exists('sys_get_temp_dir') ? (string)sys_get_temp_dir() : '';
        if (!$base || !is_dir($base) || !is_writable($base)) {
            $base = function_exists('get_temp_dir') ? (string)get_temp_dir() : '';
        }
        if (!$base) { return ''; }
        $site_seed = wp_normalize_path(ABSPATH) . '|' . home_url('/');
        return trailingslashit(wp_normalize_path($base))
            . 'hamnoor-content-staging-' . substr(hash('sha256', $site_seed), 0, 16);
    }

    private static function safe_stage() {
        $stage = get_transient(self::stage_key());
        if (!is_array($stage) || empty($stage['path'])) { return null; }
        $dir = realpath(self::staging_dir());
        $path = realpath((string)$stage['path']);
        if (!$dir || !$path || !is_file($path)) {
            delete_transient(self::stage_key());
            return null;
        }
        $dir = trailingslashit(wp_normalize_path($dir));
        $path_normalized = wp_normalize_path($path);
        if (0 !== strpos($path_normalized, $dir)) {
            delete_transient(self::stage_key());
            return null;
        }
        $stage['path'] = $path;
        return $stage;
    }

    private static function delete_stage_file($stage = null) {
        $stage = is_array($stage) ? $stage : self::safe_stage();
        if ($stage && !empty($stage['path'])) {
            $dir = realpath(self::staging_dir());
            $path = realpath((string)$stage['path']);
            if ($dir && $path && is_file($path)) {
                $dir = trailingslashit(wp_normalize_path($dir));
                if (0 === strpos(wp_normalize_path($path), $dir)) { @unlink($path); }
            }
        }
        delete_transient(self::stage_key());
    }

    private static function cleanup_staging_files() {
        $dir = self::staging_dir();
        if (!is_dir($dir)) { return; }
        $cutoff = time() - DAY_IN_SECONDS;
        foreach ((array)glob(trailingslashit($dir) . 'mafatih-*.json') as $file) {
            if (is_file($file) && (int)@filemtime($file) < $cutoff) { @unlink($file); }
        }
    }

    public static function stage_mafatih() {
        self::require_manage();
        check_admin_referer('hamnoor_content_stage_mafatih');
        if (empty($_FILES['mafatih_json']) || !is_array($_FILES['mafatih_json'])) {
            self::redirect('source_upload_failed', 'error');
        }
        $file = $_FILES['mafatih_json'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            self::redirect('source_upload_failed', 'error');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 2 || $size > self::MAX_SOURCE_BYTES) {
            self::redirect($size > self::MAX_SOURCE_BYTES ? 'source_too_large' : 'source_invalid', 'error');
        }
        $name = sanitize_file_name((string)($file['name'] ?? 'mafatih.json'));
        if ('json' !== strtolower((string)pathinfo($name, PATHINFO_EXTENSION))) {
            self::redirect('source_invalid', 'error');
        }
        $data = Hamnoor_Mafatih_Importer::load_json_file($file['tmp_name']);
        if (is_wp_error($data)) { self::redirect('source_invalid', 'error'); }
        $articles = Hamnoor_Mafatih_Importer::index_articles($data);
        if (!$articles) { self::redirect('source_invalid', 'error'); }

        $dir = self::staging_dir();
        if (!$dir || !wp_mkdir_p($dir)) { self::redirect('source_upload_failed', 'error'); }
        self::cleanup_staging_files();
        self::delete_stage_file();
        $target = trailingslashit($dir) . 'mafatih-' . (int)get_current_user_id() . '-' . strtolower(wp_generate_password(12, false, false)) . '.json';
        if (!@copy($file['tmp_name'], $target)) { self::redirect('source_upload_failed', 'error'); }

        $release = isset($_POST['source_release'])
            ? Hamnoor_Validator::trim_len(sanitize_text_field(wp_unslash($_POST['source_release'])), 60)
            : Hamnoor_Mafatih_Importer::DEFAULT_SOURCE_RELEASE;
        if (!$release) { $release = Hamnoor_Mafatih_Importer::DEFAULT_SOURCE_RELEASE; }
        $stage = array(
            'path'=>$target,
            'name'=>$name,
            'source_release'=>$release,
            'sha256'=>hash_file('sha256', $target),
            'article_count'=>count($articles),
            'created_at'=>time(),
        );
        set_transient(self::stage_key(), $stage, self::STAGE_TTL);
        self::redirect('source_staged', 'success');
    }

    public static function clear_mafatih() {
        self::require_manage();
        check_admin_referer('hamnoor_content_clear_mafatih');
        self::delete_stage_file();
        self::redirect('source_cleared', 'success');
    }

    public static function import_mafatih() {
        self::require_manage();
        check_admin_referer('hamnoor_content_import_mafatih');
        $stage = self::safe_stage();
        if (!$stage) { self::redirect('source_missing', 'error'); }
        $keys = isset($_POST['source_keys']) ? array_map('strval', (array)wp_unslash($_POST['source_keys'])) : array();
        $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
        if (!$keys) { self::redirect('import_none', 'warning'); }
        $data = Hamnoor_Mafatih_Importer::load_json_file($stage['path']);
        if (is_wp_error($data)) { self::redirect('source_invalid', 'error'); }
        // Safety invariant: importing and enabling are separate administrator decisions.
        $results = Hamnoor_Mafatih_Importer::import_selected($data, $keys, array(
            'source_release'=>(string)$stage['source_release'],
            'campaign_enabled'=>false,
        ));
        $success = 0;
        foreach ((array)$results as $result) { if (!is_wp_error($result)) { $success++; } }
        if (!$success) { self::redirect('write_failed', 'error'); }
        self::redirect('import_done', 'success', array('count'=>$success));
    }

    private static function manual_payload_text($item) {
        if (!$item || empty($item->content_payload)) { return ''; }
        $decoded = json_decode((string)$item->content_payload, true);
        if (!is_array($decoded)) { return (string)$item->content_payload; }
        foreach (array('text','content','body') as $key) {
            if (isset($decoded[$key]) && !is_array($decoded[$key])) { return (string)$decoded[$key]; }
        }
        return '';
    }

    public static function save_manual() {
        self::require_manage();
        check_admin_referer('hamnoor_content_save_manual');
        $id = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        $existing = $id ? Hamnoor_Content_Repository::get($id) : null;
        if ($id && (!$existing || 'manual' !== (string)$existing->source_type)) { self::redirect('item_not_found', 'error'); }

        $type = isset($_POST['content_type']) ? sanitize_key(wp_unslash($_POST['content_type'])) : '';
        if ($existing) { $type = (string)$existing->content_type; }
        $title = isset($_POST['title']) ? Hamnoor_Validator::trim_len(sanitize_text_field(wp_unslash($_POST['title'])), 191) : '';
        $text = isset($_POST['content_text']) ? wp_kses_post(wp_unslash($_POST['content_text'])) : '';
        if (!in_array($type, array(Hamnoor_Campaign_Domain::CONTENT_DUA, Hamnoor_Campaign_Domain::CONTENT_DHIKR), true) || !$title || '' === trim(wp_strip_all_tags($text))) {
            self::redirect('manual_invalid', 'error');
        }
        $max = null;
        if (Hamnoor_Campaign_Domain::CONTENT_DHIKR === $type && isset($_POST['max_contribution'])) {
            $max_raw = (int)$_POST['max_contribution'];
            $max = $max_raw > 0 ? $max_raw : null;
        }
        $payload = array('title'=>$title, 'text'=>$text);
        $result = Hamnoor_Content_Repository::upsert_item(array(
            'id'=>$id ?: null,
            'content_type'=>$type,
            'source_type'=>'manual',
            'title'=>$title,
            'content_payload'=>$payload,
            'max_contribution'=>$max,
            'status'=>'active',
            // New manual content is deliberately unavailable to creators until enabled separately.
            'campaign_enabled'=>$existing ? (int)$existing->campaign_enabled : 0,
        ));
        if (is_wp_error($result)) { self::redirect('write_failed', 'error'); }
        self::redirect('manual_saved', 'success');
    }

    public static function toggle_item() {
        self::require_manage();
        $id = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        check_admin_referer('hamnoor_content_toggle_' . $id);
        $item = $id ? Hamnoor_Content_Repository::get($id) : null;
        if (!$item) { self::redirect('item_not_found', 'error'); }
        $mode = isset($_POST['toggle_mode']) ? sanitize_key(wp_unslash($_POST['toggle_mode'])) : '';
        $value = isset($_POST['toggle_value']) ? sanitize_key(wp_unslash($_POST['toggle_value'])) : '';
        $fields = array('id'=>$id);
        $notice = 'write_failed';
        if ('campaign' === $mode) {
            $enable = '1' === $value;
            if ($enable && 'active' !== (string)$item->status) { self::redirect('status_disabled', 'warning'); }
            $fields['campaign_enabled'] = $enable ? 1 : 0;
            $notice = $enable ? 'item_enabled' : 'item_disabled';
        } elseif ('status' === $mode) {
            $active = 'active' === $value;
            $fields['status'] = $active ? 'active' : 'disabled';
            if (!$active) { $fields['campaign_enabled'] = 0; }
            $notice = $active ? 'status_active' : 'status_disabled';
        } else {
            self::redirect('write_failed', 'error');
        }
        $result = Hamnoor_Content_Repository::upsert_item($fields);
        if (is_wp_error($result)) { self::redirect('write_failed', 'error'); }
        self::redirect($notice, 'success');
    }

    public static function discover_dua_audio() {
        self::require_manage();
        $id = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        check_admin_referer('hamnoor_dua_audio_discover_' . $id);
        $item = $id ? Hamnoor_Content_Repository::get($id) : null;
        if (!$item || Hamnoor_Campaign_Domain::CONTENT_DUA !== (string)$item->content_type) { self::redirect('item_not_found', 'error'); }
        $source = isset($_POST['audio_source_page']) ? esc_url_raw(wp_unslash($_POST['audio_source_page'])) : '';
        $found = Hamnoor_Dua_Audio_Service::discover($source);
        if (is_wp_error($found)) { self::redirect('audio_failed', 'error', array('edit_audio'=>$id)); }
        $saved = Hamnoor_Dua_Audio_Service::merge_discovered($item, $source, $found);
        self::redirect(is_wp_error($saved) ? 'audio_failed' : 'audio_discovered', is_wp_error($saved) ? 'error' : 'success', array('edit_audio'=>$id));
    }

    public static function transfer_dua_audio() {
        self::require_manage();
        $id = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        check_admin_referer('hamnoor_dua_audio_transfer_' . $id);
        $item = $id ? Hamnoor_Content_Repository::get($id) : null;
        $audio_id = isset($_POST['audio_id']) ? sanitize_key(wp_unslash($_POST['audio_id'])) : '';
        $result = Hamnoor_Dua_Audio_Service::transfer($item, $audio_id);
        self::redirect(is_wp_error($result) ? 'audio_failed' : 'audio_transferred', is_wp_error($result) ? 'error' : 'success', array('edit_audio'=>$id));
    }

    public static function enqueue_audio_admin($hook) {
        if ('hamnoor_page_' . self::PAGE_SLUG !== $hook || empty($_GET['edit_audio'])) { return; }
        wp_enqueue_media();
        wp_enqueue_script('hamnoor-dua-audio-admin', HAMNOOR_URL . 'assets/js/hamnoor-dua-audio-admin.js', array('jquery'), Hamnoor_Helpers::asset_version('assets/js/hamnoor-dua-audio-admin.js'), true);
    }

    public static function attach_dua_audio() {
        self::require_manage(); $id=isset($_POST['content_id'])?(int)$_POST['content_id']:0; check_admin_referer('hamnoor_dua_audio_attach_'.$id);
        $item=$id?Hamnoor_Content_Repository::get($id):null;$audio_id=isset($_POST['audio_id'])?sanitize_key(wp_unslash($_POST['audio_id'])):'';$attachment=isset($_POST['attachment_id'])?(int)$_POST['attachment_id']:0;
        $result=Hamnoor_Dua_Audio_Service::attach_existing($item,$audio_id,$attachment);self::redirect(is_wp_error($result)?'audio_failed':'audio_attached',is_wp_error($result)?'error':'success',array('edit_audio'=>$id));
    }

    public static function reorder_dua_audio() {
        self::require_manage();$id=isset($_POST['content_id'])?(int)$_POST['content_id']:0;check_admin_referer('hamnoor_dua_audio_reorder_'.$id);$item=$id?Hamnoor_Content_Repository::get($id):null;
        $ids=isset($_POST['audio_order'])&&is_array($_POST['audio_order'])?array_map('sanitize_key',wp_unslash($_POST['audio_order'])):array();$result=$item?Hamnoor_Dua_Audio_Service::reorder($item,$ids):new WP_Error('missing');self::redirect(is_wp_error($result)?'audio_failed':'audio_reordered',is_wp_error($result)?'error':'success',array('edit_audio'=>$id));
    }

    private static function render_audio_panel() {
        $id = isset($_GET['edit_audio']) ? (int)$_GET['edit_audio'] : 0;
        if (!$id) { return; }
        $item = Hamnoor_Content_Repository::get($id);
        if (!$item || Hamnoor_Campaign_Domain::CONTENT_DUA !== (string)$item->content_type) { return; }
        $payload = json_decode((string)$item->content_payload, true); $payload = is_array($payload) ? $payload : array();
        $source = (string)($payload['audio_source_page'] ?? $item->source_href ?? '');
        $audios = isset($payload['audio_items']) && is_array($payload['audio_items']) ? $payload['audio_items'] : array();
        echo '<section class="card" style="max-width:none;padding:20px;margin-top:16px" data-dua-audio-admin="1"><h2>' . esc_html(sprintf(__('مدیریت صوت: %s', 'hamnoor'), $item->title)) . '</h2>';
        echo '<p>' . esc_html__('کشف، فقط فهرست رسمی را ثبت می‌کند؛ دانلود به هاست فقط با انتخاب مدیر انجام می‌شود.', 'hamnoor') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_dua_audio_discover_' . $id);
        echo '<input type="hidden" name="action" value="hamnoor_dua_audio_discover"><input type="hidden" name="content_id" value="' . $id . '"><label><strong>' . esc_html__('صفحه رسمی دعا در erfan.ir', 'hamnoor') . '</strong><br><input class="large-text" type="url" name="audio_source_page" value="' . esc_attr($source) . '" required></label>';
        submit_button(__('کشف صوت‌های منبع', 'hamnoor'), 'secondary'); echo '</form>';
        if ($audios) { usort($audios,static function($a,$b){return Hamnoor_Dua_Audio_Service::normalize_item($a)['layout_order']<=>Hamnoor_Dua_Audio_Service::normalize_item($b)['layout_order'];}); echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('ترتیب', 'hamnoor') . '</th><th>' . esc_html__('قاری/عنوان', 'hamnoor') . '</th><th>' . esc_html__('جایگاه', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody data-dua-audio-sortable>';
            foreach ($audios as $raw) { $audio=Hamnoor_Dua_Audio_Service::normalize_item($raw); echo '<tr data-audio-row><td><button class="button" type="button" data-audio-up aria-label="' . esc_attr__('انتقال به بالا','hamnoor') . '">↑</button> <button class="button" type="button" data-audio-down aria-label="' . esc_attr__('انتقال به پایین','hamnoor') . '">↓</button><input type="hidden" value="'.esc_attr($audio['id']).'" data-audio-order-value></td><td><strong>' . esc_html($audio['reciter']) . '</strong><br><small>' . esc_html($audio['remote_url']) . '</small></td><td>' . esc_html('segment'===$audio['scope'] ? ($audio['segment_title'] ?: __('فراز', 'hamnoor')) : __('دعای کامل', 'hamnoor')) . '</td><td>' . esc_html($audio['attachment_id'] ? __('محلی (اولویت پخش)', 'hamnoor') : __('راه‌دور', 'hamnoor')) . '</td><td>';
                if (!$audio['attachment_id']) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('hamnoor_dua_audio_transfer_' . $id); echo '<input type="hidden" name="action" value="hamnoor_dua_audio_transfer"><input type="hidden" name="content_id" value="' . $id . '"><input type="hidden" name="audio_id" value="' . esc_attr($audio['id']) . '"><button class="button" type="submit">' . esc_html__('انتقال به هاست', 'hamnoor') . '</button></form>'; } else { echo esc_html__('در کتابخانه رسانه', 'hamnoor'); }
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-audio-media-form>';wp_nonce_field('hamnoor_dua_audio_attach_'.$id);echo '<input type="hidden" name="action" value="hamnoor_dua_audio_attach"><input type="hidden" name="content_id" value="'.$id.'"><input type="hidden" name="audio_id" value="'.esc_attr($audio['id']).'"><input type="hidden" name="attachment_id" value="'.(int)$audio['attachment_id'].'" data-audio-attachment><button class="button" type="button" data-audio-media-picker>'.esc_html__('انتخاب از کتابخانه رسانه','hamnoor').'</button><button class="button button-primary" type="submit" data-audio-media-save hidden>'.esc_html__('ثبت رسانه','hamnoor').'</button></form>';
                echo '</td></tr>'; }
            echo '</tbody></table><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-audio-order-form>';wp_nonce_field('hamnoor_dua_audio_reorder_'.$id);echo '<input type="hidden" name="action" value="hamnoor_dua_audio_reorder"><input type="hidden" name="content_id" value="'.$id.'"><span data-audio-order-fields></span>';submit_button(__('ذخیره ترتیب صوت‌ها','hamnoor'),'secondary');echo '</form>'; }
        echo '</section>';
    }

    private static function render_stage_panel($stage) {
        echo '<section class="card" style="max-width:none;padding:20px;margin-top:16px">';
        echo '<h2>' . esc_html__('منبع مفاتیح برای درون‌ریزی انتخابی', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('فایل فقط موقتاً برای جست‌وجو و انتخاب نگه‌داری می‌شود. هیچ مطلبی خودکار وارد یا برای Creator فعال نمی‌شود.', 'hamnoor') . '</p>';
        if (!$stage) {
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" data-content-source-upload="1">';
            wp_nonce_field('hamnoor_content_stage_mafatih');
            echo '<input type="hidden" name="action" value="hamnoor_content_stage_mafatih">';
            echo '<table class="form-table"><tr><th scope="row"><label for="hamnoor-mafatih-json">' . esc_html__('فایل JSON مفاتیح', 'hamnoor') . '</label></th><td><input id="hamnoor-mafatih-json" type="file" name="mafatih_json" accept="application/json,.json" required></td></tr>';
            echo '<tr><th scope="row"><label for="hamnoor-source-release">' . esc_html__('نسخه منبع', 'hamnoor') . '</label></th><td><input id="hamnoor-source-release" type="text" name="source_release" value="' . esc_attr(Hamnoor_Mafatih_Importer::DEFAULT_SOURCE_RELEASE) . '" class="regular-text"><p class="description">' . esc_html__('این مقدار فقط متادیتای منبع است و کمپین به نسخه محتوا pin نمی‌شود.', 'hamnoor') . '</p></td></tr></table>';
            submit_button(__('بارگذاری و آماده‌سازی', 'hamnoor'));
            echo '</form></section>';
            return;
        }

        echo '<div data-mafatih-stage="1"><p><strong>' . esc_html__('فایل آماده:', 'hamnoor') . '</strong> ' . esc_html((string)$stage['name']) . '</p>';
        echo '<p><strong>' . esc_html__('نسخه منبع:', 'hamnoor') . '</strong> ' . esc_html((string)$stage['source_release']) . ' &nbsp; <strong>' . esc_html__('تعداد مطالب قابل شناسایی:', 'hamnoor') . '</strong> ' . (int)$stage['article_count'] . '</p>';
        echo '<p><code>SHA-256: ' . esc_html((string)$stage['sha256']) . '</code></p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
        wp_nonce_field('hamnoor_content_clear_mafatih');
        echo '<input type="hidden" name="action" value="hamnoor_content_clear_mafatih">';
        submit_button(__('پاک‌کردن منبع موقت', 'hamnoor'), 'secondary', 'submit', false);
        echo '</form>';

        $term = isset($_GET['mafatih_q']) ? sanitize_text_field(wp_unslash($_GET['mafatih_q'])) : '';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:8px;align-items:center;max-width:760px">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="search" name="mafatih_q" value="' . esc_attr($term) . '" class="regular-text" placeholder="' . esc_attr__('مثلاً دعای کمیل یا زیارت عاشورا', 'hamnoor') . '">';
        submit_button(__('جست‌وجو در منبع', 'hamnoor'), 'secondary', '', false);
        echo '</form>';

        $data = Hamnoor_Mafatih_Importer::load_json_file($stage['path']);
        $results = is_wp_error($data) ? array() : Hamnoor_Mafatih_Importer::search($data, $term, 50);
        if ($results) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-mafatih-import-form="1">';
            wp_nonce_field('hamnoor_content_import_mafatih');
            echo '<input type="hidden" name="action" value="hamnoor_content_import_mafatih"><table class="widefat striped" style="margin-top:12px"><thead><tr><th style="width:42px">✓</th><th>' . esc_html__('عنوان', 'hamnoor') . '</th><th>' . esc_html__('شناسه منبع', 'hamnoor') . '</th><th>' . esc_html__('قطعات', 'hamnoor') . '</th></tr></thead><tbody>';
            foreach ($results as $result) {
                echo '<tr data-mafatih-result><td><input type="checkbox" name="source_keys[]" value="' . esc_attr($result['source_key']) . '"></td><td><strong>' . esc_html($result['title']) . '</strong></td><td><code>' . esc_html($result['source_key']) . '</code></td><td>' . (int)$result['item_count'] . '</td></tr>';
            }
            echo '</tbody></table><p class="description">' . esc_html__('درون‌ریزی فقط موارد تیک‌خورده را ثبت می‌کند و آن‌ها را برای ساخت پویش فعال نمی‌کند.', 'hamnoor') . '</p>';
            submit_button(__('درون‌ریزی موارد انتخاب‌شده', 'hamnoor'));
            echo '</form>';
        } else {
            echo '<p class="description" style="margin-top:12px">' . esc_html__('موردی برای عبارت جست‌وجوشده پیدا نشد.', 'hamnoor') . '</p>';
        }
        echo '</section>';
    }

    private static function render_manual_form() {
        $edit_id = isset($_GET['edit_content']) ? (int)$_GET['edit_content'] : 0;
        $item = $edit_id ? Hamnoor_Content_Repository::get($edit_id) : null;
        if ($item && 'manual' !== (string)$item->source_type) { $item = null; }
        $type = $item ? (string)$item->content_type : Hamnoor_Campaign_Domain::CONTENT_DHIKR;
        $title = $item ? (string)$item->title : '';
        $text = $item ? self::manual_payload_text($item) : '';
        $max = $item && !empty($item->max_contribution) ? (int)$item->max_contribution : '';
        echo '<section class="card" style="max-width:none;padding:20px;margin-top:16px"><h2>' . esc_html($item ? __('ویرایش محتوای دستی', 'hamnoor') : __('افزودن محتوای دستی دعا/ذکر', 'hamnoor')) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-manual-content-form="1">';
        wp_nonce_field('hamnoor_content_save_manual');
        echo '<input type="hidden" name="action" value="hamnoor_content_save_manual"><input type="hidden" name="content_id" value="' . (int)($item->id ?? 0) . '"><table class="form-table">';
        echo '<tr><th scope="row"><label for="hamnoor-content-type">' . esc_html__('نوع محتوا', 'hamnoor') . '</label></th><td><select id="hamnoor-content-type" name="content_type" ' . ($item ? 'disabled' : '') . '><option value="dhikr" ' . selected($type, 'dhikr', false) . '>' . esc_html__('ذکر', 'hamnoor') . '</option><option value="dua" ' . selected($type, 'dua', false) . '>' . esc_html__('دعا', 'hamnoor') . '</option></select>' . ($item ? '<input type="hidden" name="content_type" value="' . esc_attr($type) . '">' : '') . '</td></tr>';
        echo '<tr><th scope="row"><label for="hamnoor-content-title">' . esc_html__('عنوان', 'hamnoor') . '</label></th><td><input id="hamnoor-content-title" class="regular-text" type="text" name="title" value="' . esc_attr($title) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="hamnoor-content-text">' . esc_html__('متن', 'hamnoor') . '</label></th><td><textarea id="hamnoor-content-text" name="content_text" rows="7" class="large-text" required>' . esc_textarea($text) . '</textarea></td></tr>';
        echo '<tr><th scope="row"><label for="hamnoor-max-contribution">' . esc_html__('سقف هر مشارکت ذکر', 'hamnoor') . '</label></th><td><input id="hamnoor-max-contribution" type="number" min="1" name="max_contribution" value="' . esc_attr($max) . '"><p class="description">' . esc_html__('برای دعا نادیده گرفته می‌شود؛ برای ذکر خالی یعنی بدون سقف مستقل و فقط محدود به باقیمانده هدف.', 'hamnoor') . '</p></td></tr>';
        echo '</table>';
        submit_button($item ? __('ذخیره ویرایش', 'hamnoor') : __('افزودن به Registry', 'hamnoor'));
        echo '</form></section>';
    }

    private static function render_registry() {
        $type = isset($_GET['content_type']) ? sanitize_key(wp_unslash($_GET['content_type'])) : '';
        $source = isset($_GET['source_type']) ? sanitize_key(wp_unslash($_GET['source_type'])) : '';
        $enabled_raw = isset($_GET['campaign_enabled']) ? (string)wp_unslash($_GET['campaign_enabled']) : '';
        $enabled = in_array($enabled_raw, array('0','1'), true) ? (int)$enabled_raw : null;
        $search = isset($_GET['content_s']) ? sanitize_text_field(wp_unslash($_GET['content_s'])) : '';
        $items = Hamnoor_Content_Repository::query_items(array(
            'content_type'=>$type,
            'source_type'=>$source,
            'campaign_enabled'=>$enabled,
            'search'=>$search,
            'limit'=>100,
        ));

        echo '<section class="card" style="max-width:none;padding:20px;margin-top:16px"><h2>' . esc_html__('Content Registry زنده', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('کمپین‌های دعا و ذکر همیشه محتوای جاری این Registry را می‌خوانند؛ نسخه فنی محتوا به کمپین pin نمی‌شود.', 'hamnoor') . '</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px"><input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="search" name="content_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جست‌وجوی عنوان/شناسه منبع', 'hamnoor') . '"><select name="content_type"><option value="">' . esc_html__('همه انواع', 'hamnoor') . '</option><option value="dua" ' . selected($type, 'dua', false) . '>' . esc_html__('دعا', 'hamnoor') . '</option><option value="dhikr" ' . selected($type, 'dhikr', false) . '>' . esc_html__('ذکر', 'hamnoor') . '</option></select>';
        echo '<select name="source_type"><option value="">' . esc_html__('همه منابع', 'hamnoor') . '</option><option value="mafatih" ' . selected($source, 'mafatih', false) . '>' . esc_html__('مفاتیح', 'hamnoor') . '</option><option value="manual" ' . selected($source, 'manual', false) . '>' . esc_html__('دستی', 'hamnoor') . '</option></select>';
        echo '<select name="campaign_enabled"><option value="">' . esc_html__('همه وضعیت‌های Creator', 'hamnoor') . '</option><option value="1" ' . selected($enabled_raw, '1', false) . '>' . esc_html__('فعال برای Creator', 'hamnoor') . '</option><option value="0" ' . selected($enabled_raw, '0', false) . '>' . esc_html__('غیرفعال برای Creator', 'hamnoor') . '</option></select>';
        submit_button(__('فیلتر', 'hamnoor'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped" data-content-registry-table="1"><thead><tr><th>' . esc_html__('عنوان', 'hamnoor') . '</th><th>' . esc_html__('نوع/منبع', 'hamnoor') . '</th><th>' . esc_html__('نسخه منبع', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('Creator', 'hamnoor') . '</th><th>' . esc_html__('سقف ذکر', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody>';
        if (!$items) { echo '<tr><td colspan="7">' . esc_html__('هنوز محتوایی ثبت نشده است.', 'hamnoor') . '</td></tr>'; }
        foreach ($items as $item) {
            $is_enabled = 1 === (int)$item->campaign_enabled;
            $is_active = 'active' === (string)$item->status;
            echo '<tr data-content-item="' . (int)$item->id . '"><td><strong>' . esc_html((string)$item->title) . '</strong><br><code>' . esc_html((string)$item->public_id) . '</code>';
            if (!empty($item->source_key)) { echo '<br><small>' . esc_html((string)$item->source_key) . '</small>'; }
            echo '</td><td>' . esc_html('dua' === (string)$item->content_type ? __('دعا', 'hamnoor') : __('ذکر', 'hamnoor')) . '<br><small>' . esc_html((string)$item->source_type) . '</small></td><td>' . esc_html((string)($item->source_release ?: '—')) . '</td>';
            echo '<td><strong>' . esc_html($is_active ? __('فعال', 'hamnoor') : __('غیرفعال', 'hamnoor')) . '</strong></td><td><strong data-campaign-enabled>' . esc_html($is_enabled ? __('فعال', 'hamnoor') : __('غیرفعال', 'hamnoor')) . '</strong></td><td>' . esc_html(!empty($item->max_contribution) ? (string)(int)$item->max_contribution : '—') . '</td><td>';
            if ('manual' === (string)$item->source_type) { echo '<a class="button button-small" href="' . esc_url(self::page_url(array('edit_content'=>(int)$item->id))) . '">' . esc_html__('ویرایش', 'hamnoor') . '</a> '; }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'; wp_nonce_field('hamnoor_content_toggle_' . (int)$item->id); echo '<input type="hidden" name="action" value="hamnoor_content_toggle"><input type="hidden" name="content_id" value="' . (int)$item->id . '"><input type="hidden" name="toggle_mode" value="campaign"><input type="hidden" name="toggle_value" value="' . ($is_enabled ? '0' : '1') . '"><button class="button button-small" type="submit" data-toggle-campaign>' . esc_html($is_enabled ? __('خروج از Creator', 'hamnoor') : __('فعال برای Creator', 'hamnoor')) . '</button></form> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'; wp_nonce_field('hamnoor_content_toggle_' . (int)$item->id); echo '<input type="hidden" name="action" value="hamnoor_content_toggle"><input type="hidden" name="content_id" value="' . (int)$item->id . '"><input type="hidden" name="toggle_mode" value="status"><input type="hidden" name="toggle_value" value="' . ($is_active ? 'disabled' : 'active') . '"><button class="button button-small" type="submit">' . esc_html($is_active ? __('غیرفعال‌سازی', 'hamnoor') : __('فعال‌سازی وضعیت', 'hamnoor')) . '</button></form>';
            if (Hamnoor_Campaign_Domain::CONTENT_DUA === (string)$item->content_type) { echo '<a class="button button-small" href="' . esc_url(self::page_url(array('edit_audio'=>(int)$item->id))) . '">' . esc_html__('مدیریت صوت', 'hamnoor') . '</a> '; }
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('حداکثر ۱۰۰ رکورد در این نما نشان داده می‌شود؛ برای فهرست‌های بزرگ از فیلتر استفاده کنید.', 'hamnoor') . '</p></section>';
    }

    public static function page() {
        self::require_manage();
        self::cleanup_staging_files();
        $stage = self::safe_stage();
        echo '<div class="wrap hamnoor-admin-wrap" data-hamnoor-content-admin="1"><h1>' . esc_html__('هم‌نور: محتوای دعا و ذکر', 'hamnoor') . '</h1>';
        echo '<p>' . esc_html__('این صفحه فقط Content Registry را مدیریت می‌کند؛ ایجاد پویش و ثبت مشارکت از قراردادهای مستقل خود عبور می‌کنند.', 'hamnoor') . '</p>';
        self::render_notice();
        self::render_stage_panel($stage);
        self::render_manual_form();
        self::render_audio_panel();
        self::render_registry();
        echo '</div>';
    }
}
