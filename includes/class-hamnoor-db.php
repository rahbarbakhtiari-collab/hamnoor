<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_DB {
    public static function table($name) {
        global $wpdb;
        $allowed = array('khatms', 'parts', 'participants', 'logs', 'otps');
        if (!in_array($name, $allowed, true)) {
            return '';
        }
        return $wpdb->prefix . 'hamnoor_' . $name;
    }

    public static function install() {
        self::create_tables();
        self::migrate_featured_fields();
        self::create_options();
        self::create_roles();
        self::create_pages(true);
        update_option('hamnoor_db_version', HAMNOOR_DB_VERSION, false);
        if (self::repeat_limit_schema_ready()) { update_option('hamnoor_repeat_limit_schema_version', '1', false); }
    }

    public static function maybe_upgrade() {
        if (get_option('hamnoor_db_version') !== HAMNOOR_DB_VERSION) {
            self::create_tables();
            self::migrate_featured_fields();
            self::create_options();
            self::create_roles();
            self::create_pages(false);
            self::cleanup_expired_records();
            update_option('hamnoor_db_version', HAMNOOR_DB_VERSION, false);
        }
        self::maybe_migrate_repeat_limit();
    }

    public static function maybe_migrate_repeat_limit() {
        if ('1' === (string) get_option('hamnoor_repeat_limit_schema_version', '0')) { return; }
        self::create_tables();
        if (self::repeat_limit_schema_ready()) { update_option('hamnoor_repeat_limit_schema_version', '1', false); }
    }

    private static function repeat_limit_schema_ready() {
        global $wpdb;
        $columns = $wpdb->get_col('DESC ' . self::table('khatms'), 0);
        return in_array('max_repeat_count', $columns, true);
    }

    public static function create_options() {
        $current = get_option('hamnoor_settings', array());
        $current = is_array($current) ? $current : array();
        $db_version = (string) get_option('hamnoor_db_version', '0');
        if (version_compare($db_version, '2.5.1', '<') && isset($current['creation_policy']) && 'approved' === $current['creation_policy'] && !get_option('hamnoor_creation_policy_admin_set')) {
            $current['creation_policy'] = 'all';
            update_option('hamnoor_creation_policy_migrated_251', '1', false);
        }
        update_option('hamnoor_settings', wp_parse_args($current, Hamnoor_Helpers::defaults()), false);
    }

    public static function create_roles() {
        add_role('hamnoor_manager', __('کاربر هم‌نور', 'hamnoor'), array('read' => true, 'hamnoor_create_khatm' => true, 'hamnoor_manage_own_khatm' => true));
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('hamnoor_create_khatm');
            $admin->add_cap('hamnoor_manage_own_khatm');
            $admin->add_cap('hamnoor_manage_all_khatms');
        }
        $manager = get_role('hamnoor_manager');
        if ($manager) {
            $manager->add_cap('hamnoor_create_khatm');
            $manager->add_cap('hamnoor_manage_own_khatm');
        }
    }

    public static function create_pages($include_guides = false) {
        if (!function_exists('wp_insert_post')) {
            return;
        }
        $stored = get_option('hamnoor_pages', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        foreach (Hamnoor_Helpers::default_pages(true) as $key => $page) {
            if (!$include_guides && in_array($key, array('participation_guide','create_guide'), true)) { continue; }
            $desired_content = isset($page['content']) ? (string) $page['content'] : (isset($page['shortcode']) ? (string) $page['shortcode'] : '');
            $page_id = !empty($stored[$key]) ? (int) $stored[$key] : 0;
            $post = $page_id ? get_post($page_id) : null;
            if (!$post || 'trash' === $post->post_status) {
                $post = get_page_by_path($page['slug']);
                $page_id = ($post && 'page' === $post->post_type) ? (int) $post->ID : 0;
            }
            if ($page_id) {
                $content = (string) get_post_field('post_content', $page_id);
                if (!empty($page['shortcode']) && false === strpos($content, $page['shortcode'])) {
                    wp_update_post(array('ID' => $page_id, 'post_content' => trim($content . "\n\n" . $page['shortcode'])));
                } elseif (isset($page['content']) && '' === trim($content)) {
                    wp_update_post(array('ID' => $page_id, 'post_content' => $desired_content));
                }
                update_post_meta($page_id, '_hamnoor_auto_page', $key);
                $stored[$key] = $page_id;
                continue;
            }
            $new_id = wp_insert_post(array(
                'post_title' => $page['title'],
                'post_name' => $page['slug'],
                'post_content' => $desired_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ), true);
            if (!is_wp_error($new_id) && $new_id) {
                update_post_meta((int) $new_id, '_hamnoor_auto_page', $key);
                $stored[$key] = (int) $new_id;
            }
        }
        update_option('hamnoor_pages', $stored, false);
        $settings = get_option('hamnoor_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $settings['participation_guide_page_id'] = !empty($stored['participation_guide']) ? (int) $stored['participation_guide'] : 0;
        $settings['create_khatm_guide_page_id'] = !empty($stored['create_guide']) ? (int) $stored['create_guide'] : 0;
        update_option('hamnoor_settings', wp_parse_args($settings, Hamnoor_Helpers::defaults()), false);
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $khatms = self::table('khatms');
        $parts = self::table('parts');
        $participants = self::table('participants');
        $logs = self::table('logs');
        $otps = self::table('otps');

        dbDelta("CREATE TABLE $khatms (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(40) NOT NULL,
            slug VARCHAR(191) NULL,
            title VARCHAR(191) NOT NULL,
            intention LONGTEXT NULL,
            description LONGTEXT NULL,
            cover_id BIGINT(20) UNSIGNED NULL,
            cover_url TEXT NULL,
            owner_user_id BIGINT(20) UNSIGNED NULL,
            owner_name VARCHAR(191) NULL,
            owner_phone VARCHAR(50) NULL,
            owner_email VARCHAR(191) NULL,
            khatm_type VARCHAR(30) NOT NULL DEFAULT 'page',
            khatm_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            max_khatm_count SMALLINT(5) UNSIGNED NULL,
            max_repeat_count SMALLINT(5) UNSIGNED NULL,
            auto_release_mode VARCHAR(20) NOT NULL DEFAULT 'none',
            auto_release_after_days SMALLINT(5) UNSIGNED NULL,
            auto_release_at DATETIME NULL,
            completion_mode VARCHAR(30) NOT NULL DEFAULT 'manual_done',
            parts_per_khatm SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            total_parts INT(10) UNSIGNED NOT NULL DEFAULT 0,
            design_style VARCHAR(50) NOT NULL DEFAULT 'default',
            custom_primary_color VARCHAR(20) NULL,
            custom_accent_color VARCHAR(20) NULL,
            cover_layout VARCHAR(30) NOT NULL DEFAULT 'standard',
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            featured_until DATETIME NULL,
            featured_label VARCHAR(100) NULL,
            featured_status VARCHAR(20) NOT NULL DEFAULT 'normal',
            featured_starts_at DATETIME NULL,
            featured_weight INT(11) NOT NULL DEFAULT 0,
            featured_reason TEXT NULL,
            featured_by_user_id BIGINT(20) UNSIGNED NULL,
            featured_updated_at DATETIME NULL,
            featured_request_status VARCHAR(20) NOT NULL DEFAULT 'none',
            featured_request_message TEXT NULL,
            featured_requested_at DATETIME NULL,
            featured_request_reviewed_at DATETIME NULL,
            featured_request_reviewed_by BIGINT(20) UNSIGNED NULL,
            privacy VARCHAR(20) NOT NULL DEFAULT 'public',
            status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
            indexable TINYINT(1) NOT NULL DEFAULT 1,
            published_at DATETIME NULL,
            published_by_user_id BIGINT(20) UNSIGNED NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY slug (slug),
            KEY privacy (privacy),
            KEY status (status),
            KEY is_featured (is_featured),
            KEY featured_until (featured_until),
            KEY featured_status (featured_status),
            KEY featured_request_status (featured_request_status),
            KEY status_featured (status, featured_status, featured_starts_at, featured_until, featured_weight),
            KEY owner_user_id (owner_user_id),
            KEY created_at (created_at),
            KEY owner_status (owner_user_id, status),
            KEY published_at (published_at),
            KEY public_index (privacy, status, indexable),
            KEY owner_phone (owner_phone),
            KEY owner_email (owner_email)
        ) $charset;");

        dbDelta("CREATE TABLE $participants (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            khatm_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(191) NULL,
            normalized_phone VARCHAR(20) NULL,
            tracking_token VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY khatm_id (khatm_id),
            KEY user_id (user_id),
            KEY email (email),
            KEY normalized_phone (normalized_phone),
            KEY tracking_token (tracking_token),
            KEY khatm_phone (khatm_id, normalized_phone),
            KEY khatm_email (khatm_id, email),
            KEY user_khatm (user_id, khatm_id)
        ) $charset;");

        dbDelta("CREATE TABLE $parts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            khatm_id BIGINT(20) UNSIGNED NOT NULL,
            participant_id BIGINT(20) UNSIGNED NULL,
            round_number SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            part_type VARCHAR(30) NOT NULL DEFAULT 'page',
            part_number SMALLINT(5) UNSIGNED NOT NULL,
            title VARCHAR(191) NULL,
            quran_ref VARCHAR(80) NULL,
            quran_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            claimed_at DATETIME NULL,
            done_at DATETIME NULL,
            released_at DATETIME NULL,
            released_by BIGINT(20) UNSIGNED NULL,
            release_reason VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY khatm_id (khatm_id),
            KEY participant_id (participant_id),
            KEY round_number (round_number),
            KEY status (status),
            KEY khatm_status_round (khatm_id, status, round_number),
            KEY status_claimed_at (status, claimed_at),
            KEY released_at (released_at),
            KEY part_type_number (part_type, part_number)
        ) $charset;");

        dbDelta("CREATE TABLE $logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            khatm_id BIGINT(20) UNSIGNED NULL,
            part_id BIGINT(20) UNSIGNED NULL,
            participant_id BIGINT(20) UNSIGNED NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            message TEXT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY khatm_id (khatm_id),
            KEY part_id (part_id),
            KEY participant_id (participant_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE $otps (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            contact VARCHAR(191) NOT NULL,
            normalized_contact VARCHAR(191) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            purpose VARCHAR(60) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            ip_address VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY normalized_contact (normalized_contact),
            KEY purpose (purpose),
            KEY expires_at (expires_at),
            KEY expires_consumed (expires_at, consumed_at)
        ) $charset;");
    }

    public static function migrate_featured_fields() {
        global $wpdb;
        $table = self::table('khatms');
        if (!$table) { return; }
        $wpdb->query("UPDATE $table SET featured_status = 'featured' WHERE is_featured = 1 AND featured_status = 'normal'");
    }

    public static function sanitize_khatm_data($source) {
        $data = array();
        $data['title'] = isset($source['title']) ? Hamnoor_Validator::trim_len(sanitize_text_field(wp_unslash($source['title'])), 191) : '';
        $data['intention'] = isset($source['intention']) ? wp_kses_post(wp_unslash($source['intention'])) : '';
        $data['description'] = isset($source['description']) ? wp_kses_post(wp_unslash($source['description'])) : '';
        $data['owner_name'] = isset($source['owner_name']) ? Hamnoor_Validator::trim_len(sanitize_text_field(wp_unslash($source['owner_name'])), 191) : '';
        $data['owner_phone'] = isset($source['owner_phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($source['owner_phone'])) : '';
        $data['owner_email'] = isset($source['owner_email']) ? Hamnoor_Validator::trim_len(sanitize_email(wp_unslash($source['owner_email'])), 191) : '';
        $data['khatm_type'] = isset($source['khatm_type']) ? self::normalize_type($source['khatm_type']) : 'page';
        $data['khatm_count'] = isset($source['khatm_count']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['khatm_count'])) : 1;
        $data['selected_surah_number'] = isset($source['selected_surah_number']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['selected_surah_number'])) : 1;
        $data['repeat_count'] = isset($source['repeat_count']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['repeat_count'])) : 100;
        $data['max_khatm_count'] = isset($source['max_khatm_count']) && '' !== trim((string) wp_unslash($source['max_khatm_count'])) ? max(1, min(1000, (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['max_khatm_count'])))) : null;
        $can_set_limits = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $data['_can_set_limits'] = $can_set_limits ? 1 : 0;
        $max_repeat_raw = isset($source['max_repeat_count']) ? trim((string) wp_unslash($source['max_repeat_count'])) : '';
        $data['max_repeat_count'] = $can_set_limits && '' !== $max_repeat_raw ? Hamnoor_Helpers::normalize_repeat_limit($max_repeat_raw) : null;
        $data['_max_repeat_count_invalid'] = $can_set_limits && '' !== $max_repeat_raw && !$data['max_repeat_count'] ? 1 : 0;
        $data['privacy'] = isset($source['privacy']) && 'private' === sanitize_key($source['privacy']) ? 'private' : 'public';
        $data['status'] = isset($source['status']) && in_array(sanitize_key($source['status']), array('pending_review','active','completed','closed','archived','failed'), true) ? sanitize_key($source['status']) : '';
        $data['indexable'] = !empty($source['indexable']) ? 1 : 0;
        $start_raw = isset($source['starts_at_jalali']) ? $source['starts_at_jalali'] : (isset($source['starts_at']) ? $source['starts_at'] : '');
        $end_raw = isset($source['ends_at_jalali']) ? $source['ends_at_jalali'] : (isset($source['ends_at']) ? $source['ends_at'] : '');
        $data['starts_at_jalali'] = isset($source['starts_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['starts_at_jalali']))) : '';
        $data['ends_at_jalali'] = isset($source['ends_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['ends_at_jalali']))) : '';
        $data['starts_at'] = $start_raw !== '' ? Hamnoor_Validator::normalize_datetime(wp_unslash($start_raw), false) : '';
        $data['ends_at'] = $end_raw !== '' ? Hamnoor_Validator::normalize_datetime(wp_unslash($end_raw), true) : '';
        if (isset($source['cover_url']) && '' !== trim((string) $source['cover_url'])) { $data['cover_url'] = esc_url_raw(wp_unslash($source['cover_url'])); }
        if (!empty($source['remove_cover'])) { $data['remove_cover'] = 1; }
        $styles = array('default','blue-quran','golden','memorial','ramadan','heyati');
        $data['design_style'] = isset($source['design_style']) && in_array(sanitize_key($source['design_style']), $styles, true) ? sanitize_key($source['design_style']) : 'default';
        $data['custom_primary_color'] = isset($source['custom_primary_color']) ? sanitize_hex_color(wp_unslash($source['custom_primary_color'])) : '';
        $data['custom_accent_color'] = isset($source['custom_accent_color']) ? sanitize_hex_color(wp_unslash($source['custom_accent_color'])) : '';
        $data['cover_layout'] = isset($source['cover_layout']) && in_array(sanitize_key($source['cover_layout']), array('standard','wide','compact'), true) ? sanitize_key($source['cover_layout']) : 'standard';
        $mode = isset($source['auto_release_mode']) ? sanitize_key(wp_unslash($source['auto_release_mode'])) : 'none';
        $data['auto_release_mode'] = in_array($mode, array('none','after_days','fixed_date'), true) ? $mode : 'none';
        $data['auto_release_after_days'] = isset($source['auto_release_after_days']) ? max(0, min(3650, (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['auto_release_after_days'])))) : 0;
        $release_raw = isset($source['auto_release_at_jalali']) ? $source['auto_release_at_jalali'] : (isset($source['auto_release_at']) ? $source['auto_release_at'] : '');
        $data['auto_release_at_jalali'] = isset($source['auto_release_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['auto_release_at_jalali']))) : '';
        $data['auto_release_at'] = $release_raw !== '' ? Hamnoor_Validator::normalize_datetime(wp_unslash($release_raw), true) : '';
        $completion_mode = isset($source['completion_mode']) ? sanitize_key(wp_unslash($source['completion_mode'])) : 'manual_done';
        $data['completion_mode'] = in_array($completion_mode, array('manual_done','auto_done_on_claim'), true) ? $completion_mode : 'manual_done';
        if ('auto_done_on_claim' === $data['completion_mode']) {
            $data['auto_release_mode'] = 'none';
            $data['auto_release_after_days'] = 0;
            $data['auto_release_at'] = '';
            $data['auto_release_at_jalali'] = '';
        }
        return $data;
    }

    public static function normalize_type($type) {
        $type = sanitize_key((string) $type);
        return in_array($type, array('page', 'juz', 'surah', 'repeated_surah'), true) ? $type : 'page';
    }

    public static function create_khatm($data) {
        global $wpdb;
        $valid = Hamnoor_Validator::validate_khatm($data);
        if (is_wp_error($valid)) { return $valid; }
        $now = current_time('mysql');
        $user = wp_get_current_user();
        $token = self::generate_unique_token();
        $title = Hamnoor_Validator::trim_len(sanitize_text_field($data['title']), 191);
        $slug = Hamnoor_Validator::trim_len(sanitize_title($title), 191);
        $owner_user_id = get_current_user_id() ? get_current_user_id() : null;
        $owner_name = !empty($data['owner_name']) ? Hamnoor_Validator::trim_len(sanitize_text_field($data['owner_name']), 191) : ($user instanceof WP_User ? Hamnoor_Validator::trim_len($user->display_name, 191) : '');
        $owner_email = !empty($data['owner_email']) ? Hamnoor_Validator::trim_len(sanitize_email($data['owner_email']), 191) : ($user instanceof WP_User ? Hamnoor_Validator::trim_len($user->user_email, 191) : '');
        $type = self::normalize_type($data['khatm_type']);
        $khatm_count = self::khatm_count_for_type($type, isset($data['khatm_count']) ? (int) $data['khatm_count'] : 1);
        $parts_per_khatm = self::parts_per_khatm($type, isset($data['repeat_count']) ? (int) $data['repeat_count'] : 100);
        $total_parts = $khatm_count * $parts_per_khatm;
        $settings = Hamnoor_Helpers::settings();
        $is_admin_creator = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $initial_status = $is_admin_creator ? 'active' : 'pending_review';
        do_action('hamnoor_before_create_khatm', $data);
        $wpdb->query('START TRANSACTION');
        $inserted = $wpdb->insert(self::table('khatms'), array(
            'token'=>$token, 'slug'=>$slug, 'title'=>$title, 'intention'=>wp_kses_post($data['intention']), 'description'=>wp_kses_post($data['description']),
            'cover_id'=>isset($data['cover_id']) ? (int)$data['cover_id'] : null, 'cover_url'=>!empty($data['cover_url']) ? esc_url_raw($data['cover_url']) : '',
            'owner_user_id'=>$owner_user_id, 'owner_name'=>$owner_name, 'owner_phone'=>Hamnoor_Validator::normalize_phone($data['owner_phone']), 'owner_email'=>$owner_email,
            'khatm_type'=>$type, 'khatm_count'=>$khatm_count, 'max_khatm_count'=>!empty($data['max_khatm_count']) ? (int)$data['max_khatm_count'] : null, 'max_repeat_count'=>!empty($data['max_repeat_count']) ? (int)$data['max_repeat_count'] : null,
            'auto_release_mode'=>isset($data['auto_release_mode']) ? $data['auto_release_mode'] : 'none', 'auto_release_after_days'=>!empty($data['auto_release_after_days']) ? (int)$data['auto_release_after_days'] : null, 'auto_release_at'=>!empty($data['auto_release_at']) ? $data['auto_release_at'] : null,
            'completion_mode'=>isset($data['completion_mode']) && 'auto_done_on_claim' === $data['completion_mode'] ? 'auto_done_on_claim' : 'manual_done',
            'parts_per_khatm'=>$parts_per_khatm, 'total_parts'=>$total_parts,
            'design_style'=>isset($data['design_style']) ? $data['design_style'] : 'default', 'custom_primary_color'=>!empty($data['custom_primary_color']) ? $data['custom_primary_color'] : null, 'custom_accent_color'=>!empty($data['custom_accent_color']) ? $data['custom_accent_color'] : null, 'cover_layout'=>isset($data['cover_layout']) ? $data['cover_layout'] : 'standard', 'is_featured'=>0, 'featured_until'=>null, 'featured_label'=>null,
            'privacy'=>isset($data['privacy']) && 'private' === $data['privacy'] ? 'private' : 'public', 'status'=>$initial_status,
            'indexable'=>('private' === $data['privacy'] || '1' !== $settings['index_public_khatms']) ? 0 : 1,
            'published_at'=>$is_admin_creator ? $now : null, 'published_by_user_id'=>$is_admin_creator ? get_current_user_id() : null,
            'starts_at'=>!empty($data['starts_at']) ? $data['starts_at'] : null, 'ends_at'=>!empty($data['ends_at']) ? $data['ends_at'] : null,
            'created_at'=>$now, 'updated_at'=>$now,
        ), array('%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s','%d','%d','%d','%s','%d','%s','%s','%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s'));
        if (!$inserted) {
            $error = $wpdb->last_error ? $wpdb->last_error : 'Unknown database insert error.';
            $wpdb->query('ROLLBACK');
            self::log(0, 0, 0, $owner_user_id, 'khatm_create_db_error', $error);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) { error_log('[Hamnoor] Khatm insert failed: ' . $error); }
            return new WP_Error('hamnoor_db_error', __('ایجاد ختم انجام نشد. اطلاعات واردشده حفظ شد؛ لطفاً چند لحظه دیگر دوباره تلاش کنید یا با مدیر سایت تماس بگیرید.', 'hamnoor'));
        }
        $khatm_id = (int) $wpdb->insert_id;
        $parts_result = self::generate_parts($khatm_id, $type, isset($data['selected_surah_number']) ? (int)$data['selected_surah_number'] : 1, isset($data['repeat_count']) ? (int)$data['repeat_count'] : 100, $khatm_count);
        if (is_wp_error($parts_result)) {
            $wpdb->update(self::table('khatms'), array('status'=>'failed','updated_at'=>$now), array('id'=>$khatm_id));
            self::log($khatm_id, 0, 0, $owner_user_id, 'khatm_parts_failed', $parts_result->get_error_message());
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_parts_failed', __('ختم ایجاد نشد؛ در تولید سهم‌های قرائت خطایی رخ داد. لطفاً دوباره تلاش کنید.', 'hamnoor'));
        }
        $wpdb->query('COMMIT');
        self::log($khatm_id, 0, 0, $owner_user_id, 'khatm_created', $is_admin_creator ? __('ختم ایجاد و منتشر شد.', 'hamnoor') : __('ختم ایجاد و برای بررسی مدیر ارسال شد.', 'hamnoor'));
        $created_khatm = self::get_khatm($khatm_id);
        do_action('hamnoor_khatm_created', $khatm_id, $data);
        do_action('hamnoor_after_create_khatm', $khatm_id, $created_khatm, $data);
        if ($created_khatm && 'active' === (string) $created_khatm->status) { do_action('hamnoor_after_invitation_created', $khatm_id, Hamnoor_Helpers::invite_url($created_khatm), $created_khatm); }
        return $khatm_id;
    }

    public static function update_khatm($khatm_id, $data) {
        global $wpdb;
        $khatm = self::get_khatm($khatm_id);
        if (!$khatm) {
            return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor'));
        }

        // Structure fields are intentionally locked after creation so khatm_count/parts never drift from rows in hamnoor_parts.
        $data['_created_at_for_validation'] = !empty($khatm->created_at) ? $khatm->created_at : current_time('mysql');
        $data['khatm_type'] = $khatm->khatm_type;
        $data['khatm_count'] = isset($khatm->khatm_count) ? (int) $khatm->khatm_count : 1;
        $data['repeat_count'] = self::parts_per_khatm($khatm->khatm_type);
        $data['_existing_repeat_count'] = isset($khatm->parts_per_khatm) ? (int) $khatm->parts_per_khatm : 0;
        if ('repeated_surah' === $khatm->khatm_type) {
            $first_part = self::get_parts((int) $khatm_id, '', array('limit' => 1));
            $data['selected_surah_number'] = !empty($first_part[0]->part_number) ? (int) $first_part[0]->part_number : 1;
            $data['repeat_count'] = isset($khatm->parts_per_khatm) ? (int) $khatm->parts_per_khatm : 100;
        }

        $valid = Hamnoor_Validator::validate_khatm($data);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $privacy = isset($data['privacy']) && 'private' === $data['privacy'] ? 'private' : 'public';
        $can_change_status = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $requested_status = isset($data['status']) ? sanitize_key($data['status']) : '';
        $new_status = ($can_change_status && in_array($requested_status, array('pending_review','active','completed','closed','archived','failed'), true)) ? $requested_status : (string) $khatm->status;
        $fields = array(
            'title' => Hamnoor_Validator::trim_len(sanitize_text_field($data['title']), 191),
            'slug' => Hamnoor_Validator::trim_len(sanitize_title($data['title']), 191),
            'intention' => wp_kses_post($data['intention']),
            'description' => wp_kses_post($data['description']),
            'max_khatm_count' => !empty($data['max_khatm_count']) ? (int) $data['max_khatm_count'] : null,
            'max_repeat_count' => !empty($data['_can_set_limits']) ? (!empty($data['max_repeat_count']) ? (int) $data['max_repeat_count'] : null) : (isset($khatm->max_repeat_count) ? $khatm->max_repeat_count : null),
            'auto_release_mode' => isset($data['auto_release_mode']) ? $data['auto_release_mode'] : (isset($khatm->auto_release_mode) ? $khatm->auto_release_mode : 'none'),
            'auto_release_after_days' => !empty($data['auto_release_after_days']) ? (int) $data['auto_release_after_days'] : null,
            'auto_release_at' => !empty($data['auto_release_at']) ? $data['auto_release_at'] : null,
            'completion_mode' => isset($data['completion_mode']) && 'auto_done_on_claim' === $data['completion_mode'] ? 'auto_done_on_claim' : 'manual_done',
            'design_style' => isset($data['design_style']) ? $data['design_style'] : (isset($khatm->design_style) ? $khatm->design_style : 'default'),
            'custom_primary_color' => !empty($data['custom_primary_color']) ? $data['custom_primary_color'] : null,
            'custom_accent_color' => !empty($data['custom_accent_color']) ? $data['custom_accent_color'] : null,
            'cover_layout' => isset($data['cover_layout']) ? $data['cover_layout'] : (isset($khatm->cover_layout) ? $khatm->cover_layout : 'standard'),
            'owner_name' => Hamnoor_Validator::trim_len(sanitize_text_field($data['owner_name']), 191),
            'owner_phone' => Hamnoor_Validator::normalize_phone($data['owner_phone']),
            'owner_email' => Hamnoor_Validator::trim_len(sanitize_email($data['owner_email']), 191),
            'privacy' => $privacy,
            'status' => $new_status,
            'indexable' => !empty($data['indexable']) && 'private' !== $privacy ? 1 : 0,
            'starts_at' => !empty($data['starts_at']) ? $data['starts_at'] : null,
            'ends_at' => !empty($data['ends_at']) ? $data['ends_at'] : null,
            'updated_at' => current_time('mysql'),
        );
        if ('active' === $new_status && 'active' !== (string) $khatm->status) {
            $fields['published_at'] = current_time('mysql');
            $fields['published_by_user_id'] = get_current_user_id();
        }
        if (!empty($data['remove_cover'])) {
            $fields['cover_id'] = null;
            $fields['cover_url'] = '';
        } elseif (isset($data['cover_id']) && isset($data['cover_url'])) {
            $fields['cover_id'] = (int) $data['cover_id'];
            $fields['cover_url'] = esc_url_raw($data['cover_url']);
        }
        do_action('hamnoor_before_update_khatm', (int) $khatm_id, $fields, $khatm);
        $updated = $wpdb->update(self::table('khatms'), $fields, array('id' => (int) $khatm_id));
        if (false === $updated) {
            self::log($khatm_id, 0, 0, get_current_user_id(), 'khatm_update_db_error', $wpdb->last_error ? $wpdb->last_error : 'Unknown update error');
            return new WP_Error('hamnoor_update_failed', __('ویرایش ختم انجام نشد. لطفاً دوباره تلاش کنید.', 'hamnoor'));
        }
        self::log($khatm_id, 0, 0, get_current_user_id(), 'khatm_updated', __('اطلاعات ختم ویرایش شد.', 'hamnoor'));
        $updated_khatm = self::get_khatm((int) $khatm_id);
        do_action('hamnoor_after_update_khatm', (int) $khatm_id, $updated_khatm, $khatm, $fields);
        if ($updated_khatm && (string) $khatm->status !== (string) $updated_khatm->status) {
            if ('active' === (string) $updated_khatm->status) { self::after_khatm_published($updated_khatm); }
            if ('completed' === (string) $updated_khatm->status) { do_action('hamnoor_after_khatm_completed', (int) $khatm_id, $updated_khatm); }
            if ('archived' === (string) $updated_khatm->status) { do_action('hamnoor_after_khatm_archived', (int) $khatm_id, $updated_khatm); }
        }
        return true;
    }

    public static function parts_per_khatm($type, $repeat = 100) {
        $type = self::normalize_type($type);
        if ('juz' === $type) { return 30; }
        if ('surah' === $type) { return 114; }
        if ('repeated_surah' === $type) { return max(1, min(1000, (int) $repeat)); }
        return 604;
    }

    public static function khatm_count_for_type($type, $count = 1) {
        $type = self::normalize_type($type);
        if (in_array($type, array('page', 'juz', 'surah'), true)) { return max(1, min(100, (int) $count)); }
        return 1;
    }

    public static function generate_parts($khatm_id, $type, $selected_surah = 1, $repeat = 100, $khatm_count = 1) {
        global $wpdb;
        $now = current_time('mysql');
        $type = self::normalize_type($type);
        $parts_per_khatm = self::parts_per_khatm($type, $repeat);
        $khatm_count = self::khatm_count_for_type($type, $khatm_count);
        $selected_surah = max(1, min(114, (int) $selected_surah));
        $total = $parts_per_khatm * $khatm_count;
        $hard_limit = (int) apply_filters('hamnoor_parts_generation_hard_limit', 12000);
        if ($total > $hard_limit) {
            return new WP_Error('hamnoor_parts_too_many', sprintf(__('تعداد سهم‌های این ختم بسیار زیاد است (%1$d سهم). برای پایداری سایت، حداکثر مجاز فعلی %2$d سهم است.', 'hamnoor'), $total, $hard_limit));
        }

        $table = self::table('parts');
        $rows = array();
        $values = array();
        $batch_size = (int) apply_filters('hamnoor_parts_batch_size', 250);
        $batch_size = max(50, min(500, $batch_size));

        $flush = function() use (&$rows, &$values, $wpdb, $table) {
            if (!$rows) { return true; }
            $sql = "INSERT INTO $table (khatm_id, participant_id, round_number, part_type, part_number, title, quran_ref, quran_url, status, created_at, updated_at) VALUES " . implode(',', $rows);
            $prepared = $wpdb->prepare($sql, $values);
            $ok = $wpdb->query($prepared);
            $rows = array();
            $values = array();
            return false !== $ok;
        };

        for ($round = 1; $round <= $khatm_count; $round++) {
            for ($i = 1; $i <= $parts_per_khatm; $i++) {
                $part_type = 'repeated_surah' === $type ? 'surah' : $type;
                $part_number = 'repeated_surah' === $type ? $selected_surah : $i;
                $quran_url = Hamnoor_Helpers::quran_url($part_type, $part_number);
                if ('page' === $part_type) {
                    $title = sprintf(__('ختم %1$d، صفحه %2$d', 'hamnoor'), $round, $part_number);
                    $ref = 'round:' . $round . ':page:' . $part_number;
                } elseif ('juz' === $part_type) {
                    $title = sprintf(__('ختم %1$d، جزء %2$d', 'hamnoor'), $round, $part_number);
                    $ref = 'round:' . $round . ':juz:' . $part_number;
                } else {
                    $surah = Hamnoor_Helpers::surah($part_number);
                    if ('surah' === $type) {
                        $title = sprintf(__('ختم %1$d، سوره %2$s', 'hamnoor'), $round, $surah ? $surah['fa'] : $part_number);
                        $ref = 'round:' . $round . ':surah:' . $part_number;
                    } else {
                        $title = sprintf(__('سوره %s', 'hamnoor'), $surah ? $surah['fa'] : $part_number);
                        $ref = 'surah:' . $part_number . ':repeat:' . $i;
                        $title .= ' - ' . sprintf(__('مرتبه %d', 'hamnoor'), $i);
                    }
                }
                $rows[] = '(%d,%d,%d,%s,%d,%s,%s,%s,%s,%s,%s)';
                array_push($values, (int) $khatm_id, null, (int) $round, $part_type, (int) $part_number, $title, $ref, esc_url_raw($quran_url), 'available', $now, $now);
                if (count($rows) >= $batch_size && true !== $flush()) {
                    return new WP_Error('hamnoor_part_insert_error', $wpdb->last_error ? $wpdb->last_error : __('خطا در ایجاد سهم قرائت.', 'hamnoor'));
                }
            }
        }
        if (true !== $flush()) {
            return new WP_Error('hamnoor_part_insert_error', $wpdb->last_error ? $wpdb->last_error : __('خطا در ایجاد سهم قرائت.', 'hamnoor'));
        }
        return true;
    }

    public static function get_khatm($id_or_token) {
        global $wpdb;
        $table = self::table('khatms');
        if (is_numeric($id_or_token)) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id_or_token));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", Hamnoor_Helpers::sanitize_token($id_or_token)));
    }

    public static function get_khatms($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('limit'=>20,'offset'=>0,'s'=>'','privacy'=>'','status'=>'','khatm_type'=>'','owner_user_id'=>0,'featured_filter'=>'','orderby'=>'id','order'=>'DESC'));
        $table = self::table('khatms');
        $where = 'WHERE 1=1';
        $params = array();
        foreach (array('privacy','khatm_type') as $key) {
            if (!empty($args[$key])) { $where .= " AND $key = %s"; $params[] = sanitize_key($args[$key]); }
        }
        $statuses = array();
        if (!empty($args['status__in']) && is_array($args['status__in'])) { $statuses = $args['status__in']; }
        elseif (!empty($args['status']) && is_array($args['status'])) { $statuses = $args['status']; }
        elseif (!empty($args['status'])) { $where .= ' AND status = %s'; $params[] = sanitize_key($args['status']); }
        if ($statuses) {
            $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
            if ($statuses) { $where .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'; foreach ($statuses as $status) { $params[] = $status; } }
        }
        if (!empty($args['owner_user_id'])) { $where .= ' AND owner_user_id = %d'; $params[] = (int) $args['owner_user_id']; }
        $featured_filter = sanitize_key((string) $args['featured_filter']);
        if (in_array($featured_filter, array('normal','featured','pinned'), true)) { $where .= ' AND featured_status = %s'; $params[] = $featured_filter; }
        elseif ('pending' === $featured_filter) { $where .= ' AND featured_request_status = %s'; $params[] = 'pending'; }
        if (!empty($args['s'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['s'])) . '%';
            $where .= ' AND (title LIKE %s OR intention LIKE %s OR owner_name LIKE %s OR owner_phone LIKE %s OR owner_email LIKE %s OR token LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $allowed_orderby = array('id','title','status','created_at','updated_at','status_priority');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';
        if ('status_priority' === $orderby) { $orderby = 'id'; }
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_clauses = $statuses && class_exists('Hamnoor_Featured_Service') ? Hamnoor_Featured_Service::order_clauses($args) : array();
        $order_clauses[] = "$orderby $order";
        $sql = "SELECT * FROM $table $where ORDER BY " . implode(', ', $order_clauses) . ' LIMIT %d OFFSET %d';
        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function count_khatms($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('s'=>'','privacy'=>'','status'=>'','khatm_type'=>'','owner_user_id'=>0,'featured_filter'=>''));
        $table = self::table('khatms');
        $where = 'WHERE 1=1';
        $params = array();
        foreach (array('privacy','khatm_type') as $key) {
            if (!empty($args[$key])) { $where .= " AND $key = %s"; $params[] = sanitize_key($args[$key]); }
        }
        $statuses = array();
        if (!empty($args['status__in']) && is_array($args['status__in'])) { $statuses = $args['status__in']; }
        elseif (!empty($args['status']) && is_array($args['status'])) { $statuses = $args['status']; }
        elseif (!empty($args['status'])) { $where .= ' AND status = %s'; $params[] = sanitize_key($args['status']); }
        if ($statuses) {
            $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
            if ($statuses) { $where .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'; foreach ($statuses as $status) { $params[] = $status; } }
        }
        if (!empty($args['owner_user_id'])) { $where .= ' AND owner_user_id = %d'; $params[] = (int) $args['owner_user_id']; }
        $featured_filter = sanitize_key((string) $args['featured_filter']);
        if (in_array($featured_filter, array('normal','featured','pinned'), true)) { $where .= ' AND featured_status = %s'; $params[] = $featured_filter; }
        elseif ('pending' === $featured_filter) { $where .= ' AND featured_request_status = %s'; $params[] = 'pending'; }
        if (!empty($args['s'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['s'])) . '%';
            $where .= ' AND (title LIKE %s OR intention LIKE %s OR owner_name LIKE %s OR owner_phone LIKE %s OR owner_email LIKE %s OR token LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT COUNT(*) FROM $table $where";
        return $params ? (int) $wpdb->get_var($wpdb->prepare($sql, $params)) : (int) $wpdb->get_var($sql);
    }

    public static function get_parts($khatm_id, $status = '', $args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('round_number'=>0, 'search'=>'', 'limit'=>0, 'offset'=>0));
        $parts = self::table('parts');
        $participants = self::table('participants');
        $sql = "SELECT p.*, r.name AS participant_name, r.email AS participant_email, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token FROM $parts p LEFT JOIN $participants r ON r.id = p.participant_id WHERE p.khatm_id = %d";
        $params = array((int) $khatm_id);
        if ($status) { $sql .= ' AND p.status = %s'; $params[] = sanitize_key($status); }
        if (!empty($args['round_number'])) { $sql .= ' AND p.round_number = %d'; $params[] = (int) $args['round_number']; }
        if (!empty($args['search'])) { $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%'; $sql .= ' AND (p.title LIKE %s OR p.part_number = %d OR p.quran_ref LIKE %s OR r.name LIKE %s OR r.phone LIKE %s OR r.email LIKE %s)'; $params[] = $like; $params[] = (int) Hamnoor_Validator::normalize_digits($args['search']); $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; }
        $sql .= ' ORDER BY p.round_number ASC, p.id ASC';
        if (!empty($args['limit'])) { $sql .= ' LIMIT %d OFFSET %d'; $params[] = (int) $args['limit']; $params[] = (int) $args['offset']; }
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function count_parts($khatm_id, $status = '', $args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('round_number'=>0, 'search'=>''));
        $parts = self::table('parts');
        $participants = self::table('participants');
        $sql = "SELECT COUNT(*) FROM $parts p LEFT JOIN $participants r ON r.id = p.participant_id WHERE p.khatm_id = %d";
        $params = array((int) $khatm_id);
        if ($status) { $sql .= ' AND p.status = %s'; $params[] = sanitize_key($status); }
        if (!empty($args['round_number'])) { $sql .= ' AND p.round_number = %d'; $params[] = (int) $args['round_number']; }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $sql .= ' AND (p.title LIKE %s OR p.part_number = %d OR p.quran_ref LIKE %s OR r.name LIKE %s OR r.phone LIKE %s OR r.email LIKE %s)';
            $params[] = $like;
            $params[] = (int) Hamnoor_Validator::normalize_digits($args['search']);
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    public static function get_part($part_id) {
        global $wpdb;
        $parts = self::table('parts');
        $participants = self::table('participants');
        return $wpdb->get_row($wpdb->prepare("SELECT p.*, r.name AS participant_name, r.email AS participant_email, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token FROM $parts p LEFT JOIN $participants r ON r.id = p.participant_id WHERE p.id = %d", (int) $part_id));
    }

    public static function get_progress($khatm_id) {
        global $wpdb;
        $table = self::table('parts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total, SUM(status='available') available, SUM(status='claimed') claimed, SUM(status='done') done FROM $table WHERE khatm_id = %d", (int) $khatm_id));
        $total = $row ? (int) $row->total : 0;
        $done = $row ? (int) $row->done : 0;
        return array('total'=>$total,'available'=>$row?(int)$row->available:0,'claimed'=>$row?(int)$row->claimed:0,'done'=>$done,'percent'=>$total ? round(($done / $total) * 100) : 0);
    }

    public static function find_or_create_participant($khatm_id, $data, &$record_meta = null) {
        global $wpdb;
        $record_meta = array('created' => false, 'linked_to_user' => false);
        $table = self::table('participants');
        $phone = Hamnoor_Validator::normalize_phone($data['phone']);
        $email = !empty($data['email']) ? sanitize_email($data['email']) : '';
        if ($email) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE khatm_id = %d AND (normalized_phone = %s OR email = %s) ORDER BY id ASC LIMIT 1", (int) $khatm_id, $phone, $email));
        } else {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE khatm_id = %d AND normalized_phone = %s ORDER BY id ASC LIMIT 1", (int) $khatm_id, $phone));
        }
        $now = current_time('mysql');
        if ($existing) {
            $current_user_id = get_current_user_id();
            $record_meta['linked_to_user'] = $current_user_id && (int) $existing->user_id !== (int) $current_user_id;
            $wpdb->update($table, array('name'=>sanitize_text_field($data['name']),'phone'=>$phone,'email'=>$email,'normalized_phone'=>$phone,'user_id'=>$current_user_id ?: null,'updated_at'=>$now), array('id'=>(int)$existing->id));
            return (int) $existing->id;
        }
        $tracking = self::generate_token(24);
        $current_user_id = get_current_user_id();
        $inserted = $wpdb->insert($table, array('khatm_id'=>(int)$khatm_id,'user_id'=>$current_user_id ?: null,'name'=>sanitize_text_field($data['name']),'phone'=>$phone,'email'=>$email,'normalized_phone'=>$phone,'tracking_token'=>$tracking,'created_at'=>$now,'updated_at'=>$now), array('%d','%d','%s','%s','%s','%s','%s','%s','%s'));
        if (!$inserted) { return 0; }
        $record_meta['created'] = true;
        $record_meta['linked_to_user'] = (bool) $current_user_id;
        return (int) $wpdb->insert_id;
    }


    public static function find_or_create_participant_record($khatm_id, $data) {
        $record_meta = array();
        $id = self::find_or_create_participant($khatm_id, $data, $record_meta);
        if (!$id) { return new WP_Error('hamnoor_participant_failed', __('ثبت شرکت‌کننده انجام نشد.', 'hamnoor')); }
        return array(
            'id' => (int) $id,
            'created' => !empty($record_meta['created']),
            'linked_to_user' => !empty($record_meta['linked_to_user']),
        );
    }

    public static function delete_orphan_participant($participant_id) {
        global $wpdb;
        $participant_id = (int) $participant_id;
        if (!$participant_id) { return false; }
        $parts = self::table('parts');
        $participants = self::table('participants');
        $used = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE participant_id = %d", $participant_id));
        if ($used > 0) { return false; }
        return false !== $wpdb->delete($participants, array('id' => $participant_id), array('%d'));
    }

    public static function khatm_accepts_claims($khatm) {
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor')); }
        if ('active' !== (string) $khatm->status) {
            return new WP_Error('hamnoor_khatm_not_active', __('این ختم در وضعیت فعال نیست و امکان رزرو سهم جدید وجود ندارد.', 'hamnoor'));
        }
        $now = current_time('timestamp');
        if (!empty($khatm->starts_at) && strtotime($khatm->starts_at) > $now) {
            return new WP_Error('hamnoor_khatm_not_started', __('زمان شروع این ختم هنوز فرا نرسیده است.', 'hamnoor'));
        }
        if (!empty($khatm->ends_at) && strtotime($khatm->ends_at) < $now) {
            return new WP_Error('hamnoor_khatm_ended', __('مهلت رزرو سهم در این ختم پایان یافته است.', 'hamnoor'));
        }
        return true;
    }


    public static function khatm_accepts_completion($khatm, $privileged = false) {
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor')); }
        if ('active' !== (string) $khatm->status) {
            return new WP_Error('hamnoor_khatm_not_active', __('این ختم فعال نیست و امکان ثبت قرائت برای آن وجود ندارد.', 'hamnoor'));
        }
        $now = current_time('timestamp');
        if (!empty($khatm->starts_at) && strtotime($khatm->starts_at) > $now) {
            return new WP_Error('hamnoor_khatm_not_started', __('زمان شروع این ختم هنوز فرا نرسیده است و ثبت قرائت مجاز نیست.', 'hamnoor'));
        }
        if (!$privileged && !empty($khatm->ends_at) && strtotime($khatm->ends_at) < $now) {
            return new WP_Error('hamnoor_khatm_ended', __('مهلت ثبت قرائت در این ختم پایان یافته است.', 'hamnoor'));
        }
        return true;
    }

    public static function claim_part($part_id, $data) {
        global $wpdb;
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if ('available' !== $part->status) { return new WP_Error('hamnoor_part_unavailable', __('این سهم قبلاً رزرو شده است.', 'hamnoor')); }
        $khatm = self::get_khatm((int) $part->khatm_id);
        $claimable = self::khatm_accepts_claims($khatm);
        if (is_wp_error($claimable)) { return $claimable; }
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }

        $now = current_time('mysql');
        $auto_done = $khatm && isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode;
        $new_status = $auto_done ? 'done' : 'claimed';
        do_action('hamnoor_before_claim_part', (int) $part_id, $part, $data, $khatm);
        $wpdb->query('START TRANSACTION');
        $participant = self::find_or_create_participant_record((int) $part->khatm_id, $data);
        if (is_wp_error($participant)) { $wpdb->query('ROLLBACK'); return $participant; }
        $participant_id = (int) $participant['id'];
        $update_data = array('participant_id'=>$participant_id,'status'=>$new_status,'claimed_at'=>$now,'updated_at'=>$now);
        $update_formats = array('%d','%s','%s','%s');
        if ($auto_done) { $update_data['done_at'] = $now; $update_formats[] = '%s'; }
        $updated = $wpdb->update(self::table('parts'), $update_data, array('id'=>(int)$part_id,'status'=>'available'), $update_formats, array('%d','%s'));
        if (!$updated) {
            if (!empty($participant['created'])) { self::delete_orphan_participant($participant_id); }
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_claim_failed', Hamnoor_Texts::get('part_already_claimed'));
        }
        $wpdb->query('COMMIT');
        self::log((int) $part->khatm_id, (int) $part_id, $participant_id, get_current_user_id(), $auto_done ? 'part_claimed_done' : 'part_claimed', $auto_done ? __('سهم با انتخاب، خوانده‌شده ثبت شد.', 'hamnoor') : __('سهم رزرو شد.', 'hamnoor'));
        if (!empty($participant['created'])) { do_action('hamnoor_after_participant_created', $participant_id, (int) $part->khatm_id, $data); }
        if (!empty($participant['linked_to_user'])) { do_action('hamnoor_after_participant_linked_to_user', $participant_id, get_current_user_id(), array('khatm_id' => (int) $part->khatm_id)); }
        do_action('hamnoor_part_claimed', (int) $part_id, (int) $participant_id, (int) $part->khatm_id);
        do_action('hamnoor_after_claim_part', (int) $part_id, (int) $participant_id, (int) $part->khatm_id, $data);
        if ($auto_done) {
            do_action('hamnoor_part_completed', (int) $part_id, (int) $participant_id, (int) $part->khatm_id);
            do_action('hamnoor_after_complete_part', (int) $part_id, (int) $participant_id, (int) $part->khatm_id, array('source' => 'auto_done_on_claim'));
            self::maybe_mark_completed((int) $part->khatm_id);
        }
        $participant = self::get_part($part_id);
        return $participant && !empty($participant->tracking_token) ? $participant->tracking_token : '';
    }

    public static function claim_next_available_part($khatm_id, $data, $round_number = 0) {
        global $wpdb;
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }
        $khatm = self::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor')); }
        $claimable = self::khatm_accepts_claims($khatm);
        if (is_wp_error($claimable)) { return $claimable; }
        $parts = self::table('parts');
        $params = array((int) $khatm_id);
        $where = 'khatm_id = %d AND status = \'available\'';
        if ($round_number) { $where .= ' AND round_number = %d'; $params[] = (int) $round_number; }
        $part_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $parts WHERE $where ORDER BY round_number ASC, id ASC LIMIT 1", $params));
        if (!$part_id) { return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part')); }
        $result = self::claim_part($part_id, $data);
        if (is_wp_error($result)) {
            if ('hamnoor_claim_failed' === $result->get_error_code() || 'hamnoor_part_unavailable' === $result->get_error_code()) {
                return new WP_Error('hamnoor_claim_failed', Hamnoor_Texts::get('part_already_claimed'));
            }
            return $result;
        }
        return array('part_id' => $part_id, 'tracking_token' => $result);
    }

    public static function claim_random_available_part($khatm_id, $data, $round_number = 0) {
        global $wpdb;
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }
        $khatm = self::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor')); }
        $claimable = self::khatm_accepts_claims($khatm);
        if (is_wp_error($claimable)) { return $claimable; }
        $parts = self::table('parts');
        if (!$round_number && (int) $khatm->khatm_count > 1) {
            $round_state = self::current_random_claim_round((int) $khatm_id);
            if (is_wp_error($round_state)) { return $round_state; }
            $round_number = (int) $round_state;
        }
        $params = array((int) $khatm_id);
        $where = "khatm_id = %d AND status = 'available'";
        if ($round_number) { $where .= ' AND round_number = %d'; $params[] = (int) $round_number; }
        $part_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $parts WHERE $where ORDER BY RAND() LIMIT 1", $params));
        if (!$part_id) { return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part')); }

        $now = current_time('mysql');
        $auto_done = $khatm && isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode;
        $new_status = $auto_done ? 'done' : 'claimed';
        $selected_part = self::get_part($part_id);
        do_action('hamnoor_before_claim_part', (int) $part_id, $selected_part, $data, $khatm);
        $wpdb->query('START TRANSACTION');
        $participant = self::find_or_create_participant_record((int) $khatm_id, $data);
        if (is_wp_error($participant)) { $wpdb->query('ROLLBACK'); return $participant; }
        $participant_id = (int) $participant['id'];
        $update_data = array('participant_id'=>$participant_id,'status'=>$new_status,'claimed_at'=>$now,'updated_at'=>$now);
        $update_formats = array('%d','%s','%s','%s');
        if ($auto_done) { $update_data['done_at'] = $now; $update_formats[] = '%s'; }
        $updated = $wpdb->update($parts, $update_data, array('id'=>$part_id,'status'=>'available'), $update_formats, array('%d','%s'));
        if (!$updated) {
            if (!empty($participant['created'])) { self::delete_orphan_participant($participant_id); }
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_claim_failed', Hamnoor_Texts::get('part_already_claimed'));
        }
        $wpdb->query('COMMIT');
        self::log((int) $khatm_id, (int) $part_id, (int) $participant_id, get_current_user_id(), $auto_done ? 'part_random_claimed_done' : 'part_random_claimed', $auto_done ? __('سهم تصادفی با انتخاب، خوانده‌شده ثبت شد.', 'hamnoor') : __('سهم آزاد به‌صورت تصادفی رزرو شد.', 'hamnoor'));
        if (!empty($participant['created'])) { do_action('hamnoor_after_participant_created', (int) $participant_id, (int) $khatm_id, $data); }
        if (!empty($participant['linked_to_user'])) { do_action('hamnoor_after_participant_linked_to_user', (int) $participant_id, get_current_user_id(), array('khatm_id' => (int) $khatm_id)); }
        do_action('hamnoor_part_claimed', (int) $part_id, (int) $participant_id, (int) $khatm_id);
        do_action('hamnoor_after_claim_part', (int) $part_id, (int) $participant_id, (int) $khatm_id, $data);
        if ($auto_done) {
            do_action('hamnoor_part_completed', (int) $part_id, (int) $participant_id, (int) $khatm_id);
            do_action('hamnoor_after_complete_part', (int) $part_id, (int) $participant_id, (int) $khatm_id, array('source' => 'auto_done_on_claim'));
            self::maybe_mark_completed((int) $khatm_id);
        }
        $part = self::get_part($part_id);
        return array('part_id' => $part_id, 'tracking_token' => $part && !empty($part->tracking_token) ? $part->tracking_token : '');
    }

    private static function current_random_claim_round($khatm_id) {
        global $wpdb;
        $parts = self::table('parts');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT round_number, COUNT(*) AS total, SUM(status = 'done') AS done_count, SUM(status = 'available') AS available_count FROM $parts WHERE khatm_id = %d GROUP BY round_number ORDER BY round_number ASC", (int) $khatm_id));
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $done = (int) $row->done_count;
            $available = (int) $row->available_count;
            if ($total > 0 && $done < $total) {
                if ($available > 0) { return (int) $row->round_number; }
                return new WP_Error('hamnoor_current_round_no_free', __('در ختم فعلی سهم آزادی باقی نمانده است. پس از ثبت قرائت سهم‌های انتخاب‌شده، امکان انتخاب از ختم بعدی فعال می‌شود.', 'hamnoor'));
            }
        }
        return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part'));
    }

    public static function can_complete_part($part, $tracking_token = '', $force = false) {
        if (!$part) { return false; }
        if ($force && self::user_can_manage_khatm((int) $part->khatm_id)) { return true; }
        if (!empty($tracking_token) && !empty($part->tracking_token) && hash_equals((string) $part->tracking_token, (string) $tracking_token)) { return true; }
        $user_id = get_current_user_id();
        if ($user_id && !empty($part->participant_user_id) && (int) $part->participant_user_id === (int) $user_id) { return true; }
        if (self::user_can_manage_khatm((int) $part->khatm_id, $user_id)) { return true; }
        return (bool) apply_filters('hamnoor_part_completion_verified', false, $part, $tracking_token, $user_id);
    }

    public static function complete_part($part_id, $tracking_token = '', $force = false) {
        global $wpdb;
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if ('done' === $part->status) { return true; }
        if ('claimed' !== $part->status) { return new WP_Error('hamnoor_part_not_claimed', __('این سهم هنوز رزرو نشده است و امکان ثبت قرائت ندارد.', 'hamnoor')); }
        if (!self::can_complete_part($part, $tracking_token, $force)) {
            return new WP_Error('hamnoor_access_denied', __('دسترسی ثبت انجام این سهم معتبر نیست.', 'hamnoor'));
        }
        $privileged = $force || self::user_can_manage_khatm((int) $part->khatm_id);
        $completion_allowed = self::khatm_accepts_completion(self::get_khatm((int) $part->khatm_id), $privileged);
        if (is_wp_error($completion_allowed)) { return $completion_allowed; }
        do_action('hamnoor_before_complete_part', (int) $part_id, $part, array('force' => (bool) $force, 'user_id' => get_current_user_id()));
        $now = current_time('mysql');
        $updated = $wpdb->update(self::table('parts'), array('status'=>'done','done_at'=>$now,'updated_at'=>$now), array('id'=>(int)$part_id), array('%s','%s','%s'), array('%d'));
        if (false === $updated) { return new WP_Error('hamnoor_complete_failed', __('ثبت انجام قرائت انجام نشد.', 'hamnoor')); }
        self::log((int) $part->khatm_id, (int) $part_id, (int) $part->participant_id, get_current_user_id(), 'part_done', __('قرائت سهم انجام شد.', 'hamnoor'));
        do_action('hamnoor_part_completed', (int) $part_id, (int) $part->participant_id, (int) $part->khatm_id);
        do_action('hamnoor_after_complete_part', (int) $part_id, (int) $part->participant_id, (int) $part->khatm_id, array('force' => (bool) $force, 'user_id' => get_current_user_id()));
        self::maybe_mark_completed((int) $part->khatm_id);
        return true;
    }

    public static function maybe_mark_completed($khatm_id) {
        global $wpdb;
        $progress = self::get_progress($khatm_id);
        if ($progress['total'] > 0 && $progress['done'] >= $progress['total']) {
            $now = current_time('mysql');
            $updated = $wpdb->query($wpdb->prepare("UPDATE " . self::table('khatms') . " SET status = 'completed', completed_at = %s, updated_at = %s WHERE id = %d AND status <> 'completed'", $now, $now, (int) $khatm_id));
            if (!$updated) { return; }
            self::log($khatm_id, 0, 0, 0, 'khatm_completed', __('همه سهم‌های ختم خوانده شد.', 'hamnoor'));
            do_action('hamnoor_khatm_completed', (int) $khatm_id);
            do_action('hamnoor_after_khatm_completed', (int) $khatm_id, self::get_khatm((int) $khatm_id));
        }
    }

    public static function get_parts_by_contact($contact, $khatm_id = 0, $limit = 200) {
        global $wpdb;
        $email = is_email($contact) ? sanitize_email($contact) : '';
        $phone = Hamnoor_Validator::normalize_phone($contact);
        $parts = self::table('parts');
        $participants = self::table('participants');
        $khatms = self::table('khatms');
        $where = array();
        $params = array();
        if ($email) { $where[] = 'r.email = %s'; $params[] = $email; }
        if ($phone) { $where[] = 'r.normalized_phone = %s'; $params[] = $phone; }
        if (!$where) { return array(); }
        $sql = "SELECT p.*, r.name AS participant_name, r.email AS participant_email, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.tracking_token, k.title AS khatm_title, k.token AS khatm_token FROM $parts p INNER JOIN $participants r ON r.id = p.participant_id INNER JOIN $khatms k ON k.id = p.khatm_id WHERE (" . implode(' OR ', $where) . ")";
        if ($khatm_id) { $sql .= ' AND p.khatm_id = %d'; $params[] = (int) $khatm_id; }
        $sql .= ' ORDER BY p.id DESC LIMIT %d'; $params[] = (int) $limit;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function get_parts_by_user($user_id, $limit = 200, $offset = 0) {
        global $wpdb;
        self::sync_guest_history_to_user((int) $user_id);
        $parts = self::table('parts');
        $participants = self::table('participants');
        $khatms = self::table('khatms');
        return $wpdb->get_results($wpdb->prepare("SELECT p.*, r.name AS participant_name, r.email AS participant_email, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.tracking_token, k.title AS khatm_title, k.token AS khatm_token FROM $parts p INNER JOIN $participants r ON r.id = p.participant_id INNER JOIN $khatms k ON k.id = p.khatm_id WHERE r.user_id = %d ORDER BY p.id DESC LIMIT %d OFFSET %d", (int) $user_id, (int) $limit, (int) $offset));
    }

    public static function count_parts_by_user($user_id) {
        global $wpdb;
        self::sync_guest_history_to_user((int) $user_id);
        $parts = self::table('parts');
        $participants = self::table('participants');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts p INNER JOIN $participants r ON r.id = p.participant_id WHERE r.user_id = %d", (int) $user_id));
    }

    public static function contact_matches_part($part, $contact) {
        if (!$part || '' === trim((string) $contact)) { return false; }
        $email = is_email($contact) ? sanitize_email($contact) : '';
        $phone = Hamnoor_Validator::normalize_phone($contact);
        return ($email && strtolower((string) $part->participant_email) === strtolower($email)) || ($phone && ((string) $part->participant_normalized_phone === $phone || Hamnoor_Validator::normalize_phone((string) $part->participant_phone) === $phone));
    }

    public static function complete_part_by_contact($part_id, $contact) {
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if (!self::contact_matches_part($part, $contact)) { return new WP_Error('hamnoor_contact_mismatch', __('این سهم با ایمیل یا موبایل واردشده ثبت نشده است.', 'hamnoor')); }
        // در نسخه کاربردی هم‌نور، پیگیری با موبایل/ایمیل ساده است و OTP در مسیر اصلی اجباری نیست.
        // کنترل اصلی این است که contact واردشده دقیقاً با همان participant سهم تطبیق داشته باشد.
        return self::complete_part($part_id, (string) $part->tracking_token, false);
    }


    public static function release_part($part_id, $reason = 'manual', $user_id = 0, $contact = '', $tracking_token = '') {
        global $wpdb;
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if ('available' === (string) $part->status) { return new WP_Error('hamnoor_release_already_available', __('این سهم قبلاً آزاد شده است.', 'hamnoor')); }
        if (!empty($part->done_at) || 'done' === (string) $part->status) { return new WP_Error('hamnoor_release_done', __('این سهم قبلاً خوانده‌شده ثبت شده و قابل آزادسازی نیست.', 'hamnoor')); }
        if ('claimed' !== (string) $part->status) { return new WP_Error('hamnoor_release_not_claimed', __('فقط سهم رزروشده و خوانده‌نشده قابل آزادسازی است.', 'hamnoor')); }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $can_manage = self::user_can_manage_khatm((int) $part->khatm_id, $user_id);
        $participant_user = $user_id && !empty($part->participant_user_id) && (int) $part->participant_user_id === (int) $user_id;
        $participant_contact = self::contact_matches_part($part, $contact);
        $participant_token = !empty($tracking_token) && !empty($part->tracking_token) && hash_equals((string) $part->tracking_token, (string) $tracking_token);
        if (0 !== strpos((string) $reason, 'auto') && !$can_manage && !$participant_user && !$participant_contact && !$participant_token) {
            return new WP_Error('hamnoor_release_denied', __('شما اجازه آزادسازی این سهم را ندارید.', 'hamnoor'));
        }
        do_action('hamnoor_before_release_part', (int) $part_id, $part, $reason, $user_id);
        $now = current_time('mysql');
        $old_participant = (int) $part->participant_id;
        $updated = $wpdb->update(self::table('parts'), array(
            'participant_id' => null,
            'status' => 'available',
            'claimed_at' => null,
            'done_at' => null,
            'released_at' => $now,
            'released_by' => $user_id ?: null,
            'release_reason' => sanitize_key($reason),
            'updated_at' => $now,
        ), array('id' => (int) $part_id, 'status' => 'claimed'), array('%d','%s','%s','%s','%s','%d','%s','%s'), array('%d','%s'));
        if (!$updated) { return new WP_Error('hamnoor_release_failed', __('آزادسازی سهم انجام نشد.', 'hamnoor')); }
        if ($old_participant) { self::delete_orphan_participant($old_participant); }
        self::log((int) $part->khatm_id, (int) $part_id, $old_participant, $user_id, 'part_released', __('سهم آزاد شد.', 'hamnoor'));
        do_action('hamnoor_part_released', (int) $part_id, (int) $part->khatm_id, $old_participant, $reason);
        do_action('hamnoor_after_release_part', (int) $part_id, (int) $part->khatm_id, $old_participant, $reason, $user_id);
        return true;
    }

    public static function release_due_parts() {
        global $wpdb;
        $parts = self::table('parts');
        $khatms = self::table('khatms');
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, k.auto_release_mode
            FROM $parts p
            INNER JOIN $khatms k ON k.id = p.khatm_id
            WHERE p.status = 'claimed'
                AND (p.done_at IS NULL OR p.done_at = '0000-00-00 00:00:00')
                AND k.completion_mode = 'manual_done'
                AND (
                    (k.auto_release_mode = 'after_days' AND k.auto_release_after_days > 0 AND p.claimed_at IS NOT NULL AND p.claimed_at <= DATE_SUB(%s, INTERVAL k.auto_release_after_days DAY))
                    OR (k.auto_release_mode = 'fixed_date' AND k.auto_release_at IS NOT NULL AND k.auto_release_at <= %s)
                )
            ORDER BY p.claimed_at ASC, p.id ASC
            LIMIT 500",
            $now,
            $now
        ));
        $count = 0;
        foreach ($rows as $row) {
            $reason = 'fixed_date' === (string) $row->auto_release_mode ? 'auto_fixed_date' : 'auto_after_days';
            $result = self::release_part((int) $row->id, $reason, 0);
            if (!is_wp_error($result)) { $count++; }
        }
        return $count;
    }

    public static function sync_guest_history_to_user($user_id) {
        global $wpdb;
        $user = get_user_by('id', (int) $user_id);
        if (!$user) { return 0; }
        $email = sanitize_email($user->user_email);
        $phone = Hamnoor_Validator::normalize_phone(get_user_meta((int) $user_id, 'hamnoor_phone', true));
        $participants = self::table('participants');
        $conditions = array();
        $params = array((int) $user_id, current_time('mysql'));
        // نسخه نصب تازه هم‌نور با رویکرد ساده و کاربردی کار می‌کند: سهم‌های مهمان
        // با ایمیل حساب یا موبایل ذخیره‌شده کاربر به داشبورد او متصل می‌شوند.
        if ($email) { $conditions[] = 'email = %s'; $params[] = $email; }
        if ($phone) { $conditions[] = 'normalized_phone = %s'; $params[] = $phone; }
        if (!$conditions) { return 0; }
        $sql = "UPDATE $participants SET user_id = %d, updated_at = %s WHERE (user_id IS NULL OR user_id = 0) AND (" . implode(' OR ', $conditions) . ')';
        $linked = (int) $wpdb->query($wpdb->prepare($sql, $params));
        if ($linked > 0) {
            do_action('hamnoor_after_participant_linked_to_user', 0, (int) $user_id, array('count' => $linked, 'source' => 'history_sync'));
        }
        return $linked;
    }

    public static function get_participants($khatm_id, $args = array()) {
        global $wpdb;
        if (!is_array($args)) { $args = array('limit' => (int) $args); }
        $args = wp_parse_args($args, array('limit'=>50, 'offset'=>0, 'search'=>''));
        $participants = self::table('participants');
        $parts = self::table('parts');
        $where = 'WHERE r.khatm_id = %d';
        $params = array((int) $khatm_id);
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where .= ' AND (r.name LIKE %s OR r.phone LIKE %s OR r.email LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $sql = "SELECT r.*, r.id AS participant_id, COUNT(p.id) AS total_parts, SUM(p.status='done') AS done_parts, MAX(p.claimed_at) AS last_claimed FROM $participants r LEFT JOIN $parts p ON p.participant_id = r.id $where GROUP BY r.id ORDER BY r.id DESC LIMIT %d OFFSET %d";
        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function count_participants($khatm_id, $args = array()) {
        global $wpdb;
        $args = wp_parse_args(is_array($args) ? $args : array(), array('search'=>''));
        $participants = self::table('participants');
        $where = 'WHERE khatm_id = %d';
        $params = array((int) $khatm_id);
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where .= ' AND (name LIKE %s OR phone LIKE %s OR email LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $participants $where", $params));
    }

    public static function get_participant_parts($participant_id, $limit = 200, $khatm_id = 0) {
        global $wpdb;
        $parts = self::table('parts');
        if ($khatm_id) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $parts WHERE participant_id = %d AND khatm_id = %d ORDER BY id DESC LIMIT %d", (int) $participant_id, (int) $khatm_id, (int) $limit));
        }
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $parts WHERE participant_id = %d ORDER BY id DESC LIMIT %d", (int) $participant_id, (int) $limit));
    }

    public static function get_logs($khatm_id, $limit = 200) {
        global $wpdb;
        $logs = self::table('logs');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $logs WHERE khatm_id = %d ORDER BY id DESC LIMIT %d", (int) $khatm_id, (int) $limit));
    }

    public static function user_can_manage_khatm($khatm_id, $user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) { return false; }
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'hamnoor_manage_all_khatms')) { return true; }
        $khatm = self::get_khatm($khatm_id);
        return $khatm && (int) $khatm->owner_user_id === (int) $user_id && user_can($user_id, 'hamnoor_manage_own_khatm');
    }

    public static function change_status($khatm_id, $status) {
        global $wpdb;
        $status = sanitize_key($status);
        if (!in_array($status, array('pending_review','active','completed','closed','archived','failed'), true)) {
            return new WP_Error('hamnoor_invalid_status', __('وضعیت انتخاب‌شده معتبر نیست.', 'hamnoor'));
        }
        $previous = self::get_khatm((int) $khatm_id);
        if (!$previous) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor')); }
        $fields = array('status' => $status, 'updated_at' => current_time('mysql'));
        if ('completed' === $status) { $fields['completed_at'] = current_time('mysql'); }
        if ('active' === $status && 'active' !== (string) $previous->status) {
            $fields['published_at'] = current_time('mysql');
            $fields['published_by_user_id'] = get_current_user_id();
        }
        $updated = $wpdb->update(self::table('khatms'), $fields, array('id' => (int) $khatm_id));
        if (false === $updated) { return new WP_Error('hamnoor_status_failed', __('تغییر وضعیت انجام نشد.', 'hamnoor')); }
        self::log((int) $khatm_id, 0, 0, get_current_user_id(), 'khatm_status_' . $status, sprintf(__('وضعیت ختم به %s تغییر کرد.', 'hamnoor'), $status));
        if ((string) $previous->status !== $status) {
            $current = self::get_khatm((int) $khatm_id);
            if ('active' === $status) { self::after_khatm_published($current); }
            if ('completed' === $status) { do_action('hamnoor_after_khatm_completed', (int) $khatm_id, $current); }
            if ('archived' === $status) { do_action('hamnoor_after_khatm_archived', (int) $khatm_id, $current); }
        }
        return true;
    }

    private static function after_khatm_published($khatm) {
        if (!$khatm) { return; }
        $url = Hamnoor_Helpers::invite_url($khatm);
        self::log((int) $khatm->id, 0, 0, get_current_user_id(), 'khatm_published', __('ختم توسط مدیر سایت منتشر شد.', 'hamnoor'));
        do_action('hamnoor_khatm_published', (int) $khatm->id, $khatm);
        do_action('hamnoor_after_invitation_created', (int) $khatm->id, $url, $khatm);
        if (!empty($khatm->owner_email) && is_email($khatm->owner_email)) {
            $subject = sprintf(__('ختم «%s» منتشر شد', 'hamnoor'), $khatm->title);
            $message = sprintf(__('ختم شما با عنوان «%1$s» توسط مدیر سایت منتشر شد و اکنون قابل مشارکت است.\n\nلینک دعوت: %2$s', 'hamnoor'), $khatm->title, $url);
            wp_mail(sanitize_email($khatm->owner_email), $subject, $message);
        }
    }

    public static function normalize_contact($contact) {
        $contact = trim((string) $contact);
        if (is_email($contact)) { return strtolower(sanitize_email($contact)); }
        return Hamnoor_Validator::normalize_phone($contact);
    }

    public static function create_otp($contact, $purpose = 'track') {
        global $wpdb;
        $normalized = self::normalize_contact($contact);
        if (!$normalized) { return new WP_Error('hamnoor_otp_contact_invalid', __('اطلاعات تماس معتبر نیست.', 'hamnoor')); }
        $rate = Hamnoor_Helpers::rate_limit('otp_request_' . sanitize_key($purpose), $normalized, 5, 15 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { return $rate; }
        $code = (string) wp_rand(100000, 999999);
        $now = current_time('mysql');
        $expires = date('Y-m-d H:i:s', current_time('timestamp') + 10 * MINUTE_IN_SECONDS);
        $wpdb->insert(self::table('otps'), array('contact'=>sanitize_text_field($contact),'normalized_contact'=>$normalized,'otp_hash'=>wp_hash_password($code),'purpose'=>sanitize_key($purpose),'expires_at'=>$expires,'ip_address'=>isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '', 'created_at'=>$now), array('%s','%s','%s','%s','%s','%s','%s'));
        do_action('hamnoor_otp_created', $normalized, $code, $purpose);
        do_action('hamnoor_otp_requested', $normalized, $purpose, $code, array('raw_contact' => $contact));
        if (is_email($normalized)) {
            wp_mail($normalized, __('کد تأیید هم‌نور', 'hamnoor'), sprintf(__('کد تأیید شما در هم‌نور: %s', 'hamnoor'), $code));
        }
        return current_user_can('manage_options') ? $code : true;
    }

    public static function verify_otp($contact, $code, $purpose = 'track', $user_id = 0) {
        global $wpdb;
        $normalized = self::normalize_contact($contact);
        $purpose = sanitize_key($purpose);
        $code = Hamnoor_Validator::normalize_digits(sanitize_text_field((string) $code));
        if (!$normalized || !preg_match('/^[0-9]{6}$/', $code)) { return new WP_Error('hamnoor_otp_invalid', __('کد تأیید معتبر نیست.', 'hamnoor')); }
        $attempt_key = 'hamnoor_otp_attempts_' . md5($purpose . '|' . $normalized);
        $attempts = (int) get_transient($attempt_key);
        if ($attempts >= 5) { return new WP_Error('hamnoor_otp_too_many', __('تعداد تلاش ناموفق زیاد است. لطفاً چند دقیقه بعد دوباره درخواست کد دهید.', 'hamnoor')); }
        $table = self::table('otps');
        $now = current_time('mysql');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE normalized_contact = %s AND purpose = %s AND consumed_at IS NULL AND expires_at >= %s ORDER BY id DESC LIMIT 1", $normalized, $purpose, $now));
        if (!$row || !wp_check_password($code, $row->otp_hash)) {
            set_transient($attempt_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
            return new WP_Error('hamnoor_otp_invalid', __('کد تأیید نادرست یا منقضی است.', 'hamnoor'));
        }
        $wpdb->update($table, array('consumed_at' => $now), array('id' => (int) $row->id), array('%s'), array('%d'));
        delete_transient($attempt_key);
        self::mark_contact_verified($normalized, $purpose, $user_id);
        return true;
    }

    public static function contact_cookie_name($normalized, $purpose = 'track') {
        return 'hamnoor_' . sanitize_key($purpose) . '_' . substr(md5($normalized), 0, 16);
    }

    public static function mark_contact_verified($contact, $purpose = 'track', $user_id = 0) {
        $normalized = self::normalize_contact($contact);
        if (!$normalized) { return false; }
        $purpose = sanitize_key($purpose);
        $token = self::generate_token(32);
        set_transient('hamnoor_verified_' . md5($purpose . '|' . $normalized . '|' . $token), 1, 30 * MINUTE_IN_SECONDS);
        $cookie = self::contact_cookie_name($normalized, $purpose);
        if (!headers_sent()) {
            setcookie($cookie, $token, time() + 30 * MINUTE_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[$cookie] = $token;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ($user_id) {
            $verified = self::get_user_verified_contacts($user_id);
            if (!in_array($normalized, $verified, true)) { $verified[] = $normalized; }
            update_user_meta($user_id, 'hamnoor_verified_contacts', array_values($verified));
            if (!is_email($normalized)) { update_user_meta($user_id, 'hamnoor_phone_verified', '1'); }
        }
        return true;
    }

    public static function is_contact_verified($contact, $purpose = 'track') {
        $normalized = self::normalize_contact($contact);
        if (!$normalized) { return false; }
        if (current_user_can('manage_options')) { return true; }
        $cookie = self::contact_cookie_name($normalized, $purpose);
        $token = isset($_COOKIE[$cookie]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie])) : '';
        if ($token && get_transient('hamnoor_verified_' . md5(sanitize_key($purpose) . '|' . $normalized . '|' . $token))) { return true; }
        $user_id = get_current_user_id();
        if ($user_id && in_array($normalized, self::get_user_verified_contacts($user_id), true)) { return true; }
        return (bool) apply_filters('hamnoor_track_contact_verified', false, $contact, $purpose);
    }

    public static function get_user_verified_contacts($user_id) {
        $verified = get_user_meta((int) $user_id, 'hamnoor_verified_contacts', true);
        if (!is_array($verified)) { $verified = array(); }
        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize_contact'), $verified))));
    }

    public static function generate_unique_token() {
        global $wpdb;
        $table = self::table('khatms');
        do {
            $token = self::generate_token(12);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE token = %s", $token));
        } while ($exists);
        return $token;
    }

    public static function generate_token($length = 16) {
        $token = strtolower(wp_generate_password((int) $length, false, false));
        return preg_replace('/[^a-z0-9]/', '', $token);
    }


    public static function required_tables() {
        return array(self::table('khatms'), self::table('parts'), self::table('participants'), self::table('logs'), self::table('otps'));
    }

    public static function check_tables_health() {
        global $wpdb;
        $missing = array();
        $columns_missing = array();
        $required_columns = array(
            self::table('khatms') => array('id','token','title','owner_user_id','owner_phone','khatm_type','khatm_count','max_khatm_count','max_repeat_count','auto_release_mode','auto_release_after_days','auto_release_at','completion_mode','parts_per_khatm','total_parts','design_style','is_featured','featured_status','featured_starts_at','featured_until','featured_weight','featured_request_status','privacy','status','published_at','published_by_user_id','starts_at','ends_at'),
            self::table('parts') => array('id','khatm_id','participant_id','round_number','part_type','part_number','quran_url','status','released_at','released_by','release_reason'),
            self::table('participants') => array('id','khatm_id','name','phone','email','normalized_phone','tracking_token'),
            self::table('logs') => array('id','khatm_id','action','message','created_at'),
            self::table('otps') => array('id','contact','normalized_contact','otp_hash','purpose','expires_at'),
        );
        foreach (self::required_tables() as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                $missing[] = $table;
                continue;
            }
            if (isset($required_columns[$table])) {
                $cols = $wpdb->get_col("DESC $table", 0);
                foreach ($required_columns[$table] as $col) {
                    if (!in_array($col, $cols, true)) {
                        $columns_missing[] = $table . '.' . $col;
                    }
                }
            }
        }
        $pages = get_option('hamnoor_pages', array());
        $settings = Hamnoor_Helpers::settings();
        return array(
            'ok' => empty($missing) && empty($columns_missing),
            'missing' => $missing,
            'columns_missing' => $columns_missing,
            'db_version' => get_option('hamnoor_db_version'),
            'rewrite_signature' => get_option('hamnoor_rewrite_version'),
            'pages' => is_array($pages) ? $pages : array(),
            'ziparchive' => class_exists('ZipArchive'),
            'rest_enabled' => !empty($settings['rest_api_enabled']) && '1' === $settings['rest_api_enabled'],
            'uploads' => wp_upload_dir(),
            'index_public_khatms' => !empty($settings['index_public_khatms']) && '1' === $settings['index_public_khatms'],
        );
    }

    public static function cleanup_expired_records() {
        global $wpdb;
        $otps = self::table('otps');
        $logs = self::table('logs');
        $now = current_time('mysql');
        $old_logs = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 90 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM $otps WHERE expires_at < %s OR consumed_at IS NOT NULL", $now));
        $wpdb->query($wpdb->prepare("DELETE FROM $logs WHERE created_at < %s", $old_logs));
    }

    public static function log($khatm_id, $part_id, $participant_id, $user_id, $action, $message) {
        global $wpdb;
        $wpdb->insert(self::table('logs'), array(
            'khatm_id' => $khatm_id ? (int) $khatm_id : null,
            'part_id' => $part_id ? (int) $part_id : null,
            'participant_id' => $participant_id ? (int) $participant_id : null,
            'user_id' => $user_id ? (int) $user_id : null,
            'action' => sanitize_key($action),
            'message' => sanitize_text_field($message),
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'created_at' => current_time('mysql'),
        ), array('%d','%d','%d','%d','%s','%s','%s','%s','%s'));
    }
}
