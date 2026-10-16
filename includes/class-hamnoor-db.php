<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_DB {
    public static function table($name) {
        global $wpdb;
        $allowed = class_exists('Hamnoor_Schema_Manifest') ? Hamnoor_Schema_Manifest::table_names() : array('users','sessions','notifications','notification_channels','push_subscriptions','notification_deliveries','khatms','parts','participants','logs','otps','reading_progress','personal_quran_progress','reminder_schedules','rubika_links','content_items','contributions','claim_events','domain_events','migrations');
        if (!in_array($name, $allowed, true)) {
            return '';
        }
        return $wpdb->prefix . 'hamnoor_' . $name;
    }

    public static function install() {
        self::create_tables();
        self::assert_tables_health('activation');
        self::migrate_legacy_profiles();
        self::migrate_featured_fields();
        self::migrate_root_tokens();
        self::migrate_pause_metadata();
        self::migrate_part_round_titles();
        self::create_options();
        self::create_roles();
        $migration_result = Hamnoor_Migrations::run_pending((string) get_option('hamnoor_db_version', '0'));
        if (is_wp_error($migration_result)) { throw new RuntimeException($migration_result->get_error_message()); }
        if (false === $migration_result) { throw new RuntimeException('Hamnoor pending database migrations did not complete.'); }
        self::create_pages(true);
        self::assert_tables_health('activation-post-migration');
        self::assert_foundation_data_health('activation-post-migration');
        self::persist_db_version();
        self::maybe_reconcile_notification_channel_states();
        if (self::repeat_limit_schema_ready()) { update_option('hamnoor_repeat_limit_schema_version', '1', false); }
    }

    public static function maybe_upgrade() {
        if (get_option('hamnoor_db_version') !== HAMNOOR_DB_VERSION) {
            self::create_tables();
            self::assert_tables_health('upgrade');
            self::migrate_legacy_profiles();
            self::migrate_featured_fields();
            self::migrate_root_tokens();
            self::migrate_pause_metadata();
            self::migrate_part_round_titles();
            self::create_options();
            self::create_roles();
            $migration_result = Hamnoor_Migrations::run_pending((string) get_option('hamnoor_db_version', '0'));
        if (is_wp_error($migration_result)) { throw new RuntimeException($migration_result->get_error_message()); }
        if (false === $migration_result) { throw new RuntimeException('Hamnoor pending database migrations did not complete.'); }
            self::create_pages(false);
            self::cleanup_expired_records();
            self::assert_tables_health('upgrade-post-migration');
            self::assert_foundation_data_health('upgrade-post-migration');
            self::persist_db_version();
        }
        self::maybe_reconcile_notification_channel_states();
        self::maybe_migrate_repeat_limit();
    }

    private static function persist_db_version() {
        update_option('hamnoor_db_version', HAMNOOR_DB_VERSION, false);
        if ((string) get_option('hamnoor_db_version', '') !== (string) HAMNOOR_DB_VERSION) {
            throw new RuntimeException('Hamnoor database version marker could not be persisted.');
        }
    }

    public static function assert_foundation_data_health($context = 'runtime') {
        global $wpdb;
        $checks = array(
            'campaign_public_id_missing'=>"SELECT COUNT(*) FROM " . self::table('khatms') . " WHERE campaign_public_id IS NULL OR campaign_public_id=''",
            'part_public_id_missing'=>"SELECT COUNT(*) FROM " . self::table('parts') . " WHERE public_id IS NULL OR public_id=''",
            'participant_public_id_missing'=>"SELECT COUNT(*) FROM " . self::table('participants') . " WHERE public_id IS NULL OR public_id=''",
            'wp_user_link_missing'=>"SELECT COUNT(*) FROM " . self::table('users') . " WHERE status='active' AND wp_user_id IS NULL",
            'personal_quran_progress_missing'=>"SELECT COUNT(*) FROM " . self::table('khatms') . " k LEFT JOIN " . self::table('personal_quran_progress') . " p ON p.campaign_id=k.id WHERE k.content_type='quran' AND k.participation_strategy='linear_quran_progress' AND k.access_mode='owner_only' AND p.id IS NULL",
            'personal_reminder_scope_invalid'=>"SELECT COUNT(*) FROM " . self::table('reminder_schedules') . " r LEFT JOIN " . self::table('khatms') . " k ON r.scope_type='personal_quran' AND r.scope_id=k.id WHERE r.scope_type='personal_quran' AND (k.id IS NULL OR k.owner_user_id<>r.user_id OR k.participation_strategy<>'linear_quran_progress' OR k.access_mode<>'owner_only')",
        );
        $violations = array();
        foreach ($checks as $name=>$sql) {
            $count = (int)$wpdb->get_var($sql);
            if ($count > 0) { $violations[$name] = $count; }
        }
        if ($violations) {
            throw new RuntimeException('Hamnoor foundation data check failed during ' . sanitize_key((string)$context) . ': ' . wp_json_encode($violations));
        }
        return true;
    }

    public static function assert_tables_health($context = 'runtime') {
        $health = self::check_tables_health();
        if (empty($health['ok'])) {
            $missing = !empty($health['missing']) ? implode(', ', (array) $health['missing']) : '-';
            $columns = !empty($health['columns_missing']) ? implode(', ', (array) $health['columns_missing']) : '-';
            $indexes = !empty($health['indexes_missing']) ? implode(', ', (array) $health['indexes_missing']) : '-';
            throw new RuntimeException(sprintf(
                'Hamnoor database schema check failed during %s. Missing tables: %s. Missing columns: %s. Missing indexes: %s.',
                sanitize_key((string) $context),
                $missing,
                $columns,
                $indexes
            ));
        }
        return true;
    }

    /**
     * Reconcile notification-channel state written by legacy versions.
     *
     * This migration is intentionally conservative: a channel carrying a real
     * provider error remains disabled/needs_reconnect. Only contradictory or
     * clearly legacy rows are normalized. The result is stored as a sanitized
     * audit option so support can see what changed without exposing secrets.
     */
    public static function maybe_reconcile_notification_channel_states() {
        if ('1' === (string) get_option('hamnoor_notification_state_reconcile_version', '0')) { return; }
        $report = self::reconcile_notification_channel_states();
        if (is_array($report)) {
            update_option('hamnoor_notification_state_reconcile_last', $report, false);
            update_option('hamnoor_notification_state_reconcile_version', '1', false);
        }
    }

    public static function reconcile_notification_channel_states() {
        global $wpdb;
        $table = self::table('notification_channels');
        if (!$table) { return array(); }

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string) $exists !== (string) $table) { return array(); }

        $rows = $wpdb->get_results("SELECT id,user_id,channel,enabled,status,external_id,metadata FROM $table WHERE channel IN ('eitaa','bale','rubika') ORDER BY id ASC");
        $report = array(
            'at' => current_time('mysql'),
            'version' => HAMNOOR_VERSION,
            'scanned' => 0,
            'changed' => 0,
            'preserved_error_state' => 0,
            'rules' => array(),
        );

        $bump = static function (&$report, $rule) {
            if (!isset($report['rules'][$rule])) { $report['rules'][$rule] = 0; }
            $report['rules'][$rule]++;
        };

        foreach ((array) $rows as $row) {
            $report['scanned']++;
            $channel = sanitize_key((string) $row->channel);
            $status = sanitize_key((string) $row->status);
            $enabled = (int) $row->enabled;
            $external_id = trim((string) $row->external_id);
            $meta = json_decode((string) $row->metadata, true);
            if (!is_array($meta)) { $meta = array(); }
            $has_provider_error = !empty($meta['last_error']) || !empty($meta['last_error_at']);
            $updates = array();
            $formats = array();
            $rule = '';

            // An active status without a recipient can never be deliverable.
            if (in_array($status, array('connected','needs_reconnect'), true) && '' === $external_id) {
                $updates = array('status'=>'disconnected','enabled'=>0,'updated_at'=>current_time('mysql'));
                $formats = array('%s','%d','%s');
                $rule = $channel . '_missing_recipient_disconnected';
            // Core adapters define connected as enabled=1 + status=connected. A
            // legacy connected row with a recipient but enabled=0 is contradictory.
            } elseif ('connected' === $status && '' !== $external_id && 1 !== $enabled) {
                $updates = array('enabled'=>1,'updated_at'=>current_time('mysql'));
                $formats = array('%d','%s');
                $rule = $channel . '_connected_enabled_repaired';
            // Older Bale/Rubika builds could leave a usable recipient in
            // needs_reconnect without recording a provider failure. Restore only
            // those safe legacy rows; real terminal-error rows remain disabled.
            } elseif (in_array($channel, array('bale','rubika'), true) && 'needs_reconnect' === $status && '' !== $external_id) {
                if ($has_provider_error) {
                    if (0 !== $enabled) {
                        $updates = array('enabled'=>0,'updated_at'=>current_time('mysql'));
                        $formats = array('%d','%s');
                        $rule = $channel . '_error_state_disabled';
                    } else {
                        $report['preserved_error_state']++;
                        $bump($report, $channel . '_error_state_preserved');
                    }
                } else {
                    $updates = array('status'=>'connected','enabled'=>1,'updated_at'=>current_time('mysql'));
                    $formats = array('%s','%d','%s');
                    $rule = $channel . '_legacy_reconnected';
                }
            }

            if ($updates) {
                $ok = $wpdb->update($table, $updates, array('id'=>(int)$row->id), $formats, array('%d'));
                if (false !== $ok) {
                    $report['changed']++;
                    $bump($report, $rule ?: 'normalized');
                } else {
                    $bump($report, 'db_update_failed');
                }
            }
        }

        return $report;
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

    public static function migrate_pause_metadata() {
        global $wpdb;
        $table = self::table('khatms');
        if (!$table || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return; }
        $columns = (array) $wpdb->get_col('DESC ' . $table, 0);
        if (!in_array('pause_source', $columns, true)) { return; }

        // Normalize any stale pre-3.9.4 stop state into the single supported
        // paused state, then mark old pauses as administrative so an owner
        // cannot accidentally resume an administrator stop.
        $wpdb->query("UPDATE $table SET status='paused' WHERE status='closed'");
        $wpdb->query("UPDATE $table SET pause_source='admin', paused_at=COALESCE(paused_at, updated_at), pause_note=COALESCE(pause_note, review_note) WHERE status='paused' AND (pause_source IS NULL OR pause_source='')");
    }

    /**
     * Normalize share titles after the product terminology changed from an
     * internal round label to a user-facing share label.
     *
     * A single-cycle campaign must not show the meaningless prefix "پویش 1".
     * Multi-cycle campaigns use "دور N" because all cycles belong to the same
     * campaign and are not separate campaigns.
     */
    public static function migrate_part_round_titles() {
        global $wpdb;
        $parts = self::table('parts');
        $khatms = self::table('khatms');
        if (!$parts || !$khatms) { return; }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($parts))) !== $parts) { return; }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($khatms))) !== $khatms) { return; }

        // Existing one-cycle campaigns: "پویش 1، صفحه 9" -> "صفحه 9".
        $wpdb->query("UPDATE $parts p INNER JOIN $khatms k ON k.id=p.khatm_id SET p.title=REPLACE(p.title,'پویش 1، ','') WHERE COALESCE(k.khatm_count,1)=1 AND p.round_number=1 AND p.title LIKE 'پویش 1، %'");

        // Existing multi-cycle campaigns: "پویش 2، جزء 4" -> "دور 2، جزء 4".
        $wpdb->query("UPDATE $parts p INNER JOIN $khatms k ON k.id=p.khatm_id SET p.title=REPLACE(p.title,'پویش ','دور ') WHERE COALESCE(k.khatm_count,1)>1 AND p.title LIKE 'پویش %'");
    }

    public static function create_options() {
        $current = get_option('hamnoor_settings', array());
        $current = is_array($current) ? $current : array();
        foreach (array('allow_frontend_registration','auto_grant_manager','track_requires_otp','auto_create_track_page','auto_create_create_page','participation_guide_page_id','create_khatm_guide_page_id','creation_policy') as $legacy_key) { unset($current[$legacy_key]); }
        update_option('hamnoor_settings', wp_parse_args($current, Hamnoor_Helpers::defaults()), false);
    }

    public static function create_roles() {
        add_role('hamnoor_member', __('کاربر هم‌نور', 'hamnoor'), array('read' => true));
        add_role('hamnoor_manager', __('مدیر هم‌نور', 'hamnoor'), array('read' => true, 'hamnoor_create_khatm' => true, 'hamnoor_manage_own_khatm' => true));
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

    public static function create_pages_after_init() {
        self::create_pages(false);
    }

    /**
     * برگه‌های منسوخ هم‌نور را فقط زمانی حذف می‌کند که تعلق آن‌ها به افزونه
     * از طریق شناسه ذخیره‌شده، متای داخلی، شورت‌کد قدیمی یا slug اختصاصی روشن باشد.
     */
    private static function cleanup_legacy_pages(&$stored, $required) {
        global $wpdb;

        $legacy_keys = array('login','register','account','track','auth','login_register','participation_guide','create_guide');
        $required_ids = array();
        foreach (array_keys($required) as $key) {
            if (!empty($stored[$key])) { $required_ids[] = (int) $stored[$key]; }
        }

        $candidate_ids = array();
        foreach ($legacy_keys as $key) {
            if (!empty($stored[$key])) { $candidate_ids[] = (int) $stored[$key]; }
        }

        $legacy_option_names = array(
            'hamnoor_login_page_id',
            'hamnoor_register_page_id',
            'hamnoor_account_page_id',
            'hamnoor_track_page_id',
            'hamnoor_auth_page_id',
            'hamnoor_login_register_page_id',
        );
        foreach ($legacy_option_names as $option_name) {
            $legacy_id = (int) get_option($option_name, 0);
            if ($legacy_id) { $candidate_ids[] = $legacy_id; }
            delete_option($option_name);
        }

        $meta_pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => array('publish','draft','private','pending','trash'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(array('key' => '_hamnoor_auto_page', 'value' => $legacy_keys, 'compare' => 'IN')),
        ));
        $candidate_ids = array_merge($candidate_ids, array_map('intval', $meta_pages));

        $obsolete_shortcodes = array(
            '[hamnoor_login',
            '[hamnoor_register',
            '[hamnoor_account',
            '[hamnoor_track',
            '[hamnoor_auth',
            '[hamnoor_login_register',
            '[hamnoor_user_account',
            '[hamnoor_my_account',
        );
        $like_parts = array();
        $like_values = array();
        foreach ($obsolete_shortcodes as $shortcode) {
            $like_parts[] = 'post_content LIKE %s';
            $like_values[] = '%' . $wpdb->esc_like($shortcode) . '%';
        }
        if ($like_parts) {
            $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private','pending','trash') AND (" . implode(' OR ', $like_parts) . ')';
            $shortcode_pages = $wpdb->get_col($wpdb->prepare($sql, $like_values));
            $candidate_ids = array_merge($candidate_ids, array_map('intval', $shortcode_pages));
        }

        $legacy_slugs = array(
            'hamnoor-login',
            'hamnoor-register',
            'hamnoor-account',
            'hamnoor-track',
            'hamnoor-auth',
            'hamnoor-login-register',
            'login-register',
            'login-and-register',
            'ورود-و-ثبت-نام',
        );
        $legacy_titles = array('ورود و ثبت‌نام', 'ورود و ثبت نام', 'ورود هم‌نور', 'ثبت‌نام هم‌نور', 'ثبت نام هم‌نور');
        foreach ($legacy_slugs as $slug) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page) { $candidate_ids[] = (int) $page->ID; }
        }
        foreach ($legacy_titles as $legacy_title) {
            $title_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = %s AND post_content LIKE %s",
                $legacy_title,
                '%' . $wpdb->esc_like('hamnoor') . '%'
            ));
            $candidate_ids = array_merge($candidate_ids, array_map('intval', $title_ids));
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
        foreach ($candidate_ids as $page_id) {
            if (in_array($page_id, $required_ids, true)) { continue; }
            $post = get_post($page_id);
            if (!$post || 'page' !== $post->post_type) { continue; }

            $meta_key = (string) get_post_meta($page_id, '_hamnoor_auto_page', true);
            $content = strtolower((string) $post->post_content);
            $slug = (string) $post->post_name;
            $belongs_to_hamnoor = in_array($meta_key, $legacy_keys, true) || in_array($slug, $legacy_slugs, true);
            if (!$belongs_to_hamnoor && in_array((string) $post->post_title, $legacy_titles, true) && false !== strpos($content, 'hamnoor')) {
                $belongs_to_hamnoor = true;
            }
            if (!$belongs_to_hamnoor) {
                foreach ($obsolete_shortcodes as $shortcode) {
                    if (false !== strpos($content, strtolower($shortcode))) {
                        $belongs_to_hamnoor = true;
                        break;
                    }
                }
            }

            if ($belongs_to_hamnoor && 'trash' !== $post->post_status) {
                wp_trash_post($page_id);
            }
        }

        foreach ($legacy_keys as $legacy_key) { unset($stored[$legacy_key]); }
    }

    public static function create_pages($include_guides = false) {
        if (!function_exists('wp_insert_post')) { return; }
        // wp_insert_post/wp_update_post can invoke the revisions subsystem.
        // On plugins_loaded WordPress has not yet defined WP_POST_REVISIONS.
        // Defer page synchronization rather than defining a core constant here.
        if (!defined('WP_POST_REVISIONS') && !did_action('init')) {
            if (!has_action('init', array(__CLASS__, 'create_pages_after_init'))) {
                add_action('init', array(__CLASS__, 'create_pages_after_init'), 20);
            }
            return;
        }

        $stored = get_option('hamnoor_pages', array());
        $stored = is_array($stored) ? $stored : array();
        $required = Hamnoor_Helpers::default_pages(true);
        self::cleanup_legacy_pages($stored, $required);

        foreach ($required as $key => $page) {
            $desired_content = isset($page['content']) ? (string) $page['content'] : (isset($page['shortcode']) ? (string) $page['shortcode'] : '');
            $page_id = !empty($stored[$key]) ? (int) $stored[$key] : 0;
            $post = $page_id ? get_post($page_id) : null;
            if (!$post || 'trash' === $post->post_status) {
                $post = get_page_by_path($page['slug']);
                $page_id = ($post && 'page' === $post->post_type) ? (int) $post->ID : 0;
            }
            if ($page_id) {
                $is_auto = (string) get_post_meta($page_id, '_hamnoor_auto_page', true);
                $legacy_dashboard_title = 'dashboard' === $key && in_array((string) get_the_title($page_id), array('ورود و ثبت‌نام','ورود و ثبت نام','ورود هم‌نور','ثبت‌نام هم‌نور','ثبت نام هم‌نور'), true);
                $update = array('ID' => $page_id);
                if ($is_auto || !$post || $legacy_dashboard_title) {
                    $update['post_title'] = $page['title'];
                    $update['post_name'] = $page['slug'];
                    $update['post_content'] = $desired_content;
                    $update['post_status'] = 'publish';
                } else {
                    $content = (string) get_post_field('post_content', $page_id);
                    if (!empty($page['shortcode']) && false === strpos($content, $page['shortcode'])) {
                        $update['post_content'] = trim($content . "\n\n" . $page['shortcode']);
                    }
                }
                if (count($update) > 1) { wp_update_post($update); }
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
        update_option('hamnoor_settings', wp_parse_args($settings, Hamnoor_Helpers::defaults()), false);
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $users = self::table('users');
        $sessions = self::table('sessions');
        $notifications = self::table('notifications');
        $notification_channels = self::table('notification_channels');
        $push_subscriptions = self::table('push_subscriptions');
        $notification_deliveries = self::table('notification_deliveries');
        $khatms = self::table('khatms');
        $parts = self::table('parts');
        $participants = self::table('participants');
        $logs = self::table('logs');
        $otps = self::table('otps');
        $rubika_links = self::table('rubika_links');
        $content_items = self::table('content_items');
        $contributions = self::table('contributions');
        $claim_events = self::table('claim_events');
        $domain_events = self::table('domain_events');
        $migrations = self::table('migrations');
        $personal_quran_progress = self::table('personal_quran_progress');
        $reminder_schedules = self::table('reminder_schedules');
        $retention_rollups = self::table('retention_rollups');

        dbDelta("CREATE TABLE $users (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT(20) UNSIGNED NULL,
            public_id VARCHAR(32) NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_at DATETIME NULL,
            claim_blocked TINYINT(1) NOT NULL DEFAULT 0,
            claim_block_reason TEXT NULL,
            claim_blocked_at DATETIME NULL,
            claim_blocked_by BIGINT(20) UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY phone (phone),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY status (status),
            KEY mobile_verified (mobile_verified),
            KEY claim_blocked (claim_blocked),
            KEY last_seen_at (last_seen_at)
        ) $charset;");

        dbDelta("CREATE TABLE $sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            ip_hash VARCHAR(64) NULL,
            user_agent_hash VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            assurance_level TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            trusted_until DATETIME NULL,
            step_up_until DATETIME NULL,
            assurance_source VARCHAR(60) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at),
            KEY revoked_at (revoked_at),
            KEY user_active (user_id, revoked_at, expires_at),
            KEY user_assurance (user_id, assurance_level, trusted_until)
        ) $charset;");

        dbDelta("CREATE TABLE $notifications (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(60) NOT NULL,
            title VARCHAR(191) NOT NULL,
            message TEXT NULL,
            related_type VARCHAR(40) NULL,
            related_id BIGINT(20) UNSIGNED NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY user_read (user_id, read_at),
            KEY related (related_type, related_id),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE $notification_channels (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            channel VARCHAR(32) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'connected',
            external_id VARCHAR(191) NULL,
            metadata LONGTEXT NULL,
            connected_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_channel (user_id, channel),
            KEY channel_status (channel, status),
            KEY enabled (enabled)
        ) $charset;");

        dbDelta("CREATE TABLE $rubika_links (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            target_hash CHAR(64) NOT NULL,
            target_url TEXT NOT NULL,
            secret_hash CHAR(64) NOT NULL,
            use_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            UNIQUE KEY user_target (user_id, target_hash),
            KEY user_id (user_id),
            KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE $push_subscriptions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
            user_agent VARCHAR(500) NULL,
            last_success_at DATETIME NULL,
            last_error_at DATETIME NULL,
            last_error_code VARCHAR(80) NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_id (user_id),
            KEY user_active (user_id, revoked_at),
            KEY revoked_at (revoked_at)
        ) $charset;");

        dbDelta("CREATE TABLE $notification_deliveries (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            channel VARCHAR(32) NOT NULL,
            provider VARCHAR(60) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempt_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 4,
            response_code SMALLINT(5) UNSIGNED NULL,
            error_code VARCHAR(80) NULL,
            error_message TEXT NULL,
            context_json LONGTEXT NULL,
            next_attempt_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY notification_id (notification_id),
            KEY user_id (user_id),
            KEY channel_status (channel, status),
            KEY queue_due (status, next_attempt_at),
            KEY created_at (created_at)
        ) $charset;");

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
            campaign_public_id VARCHAR(32) NULL,
            content_type VARCHAR(20) NOT NULL DEFAULT 'quran',
            participation_strategy VARCHAR(32) NOT NULL DEFAULT 'segmented_claim',
            access_mode VARCHAR(20) NOT NULL DEFAULT 'public',
            participation_policy VARCHAR(20) NOT NULL DEFAULT 'anyone',
            lifecycle_status VARCHAR(20) NOT NULL DEFAULT 'draft',
            review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            content_item_id BIGINT(20) UNSIGNED NULL,
            target_quantity BIGINT(20) UNSIGNED NULL,
            first_participation_at DATETIME NULL,
            domain_version SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            khatm_type VARCHAR(30) NOT NULL DEFAULT 'page',
            khatm_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            max_khatm_count SMALLINT(5) UNSIGNED NULL,
            max_repeat_count SMALLINT(5) UNSIGNED NULL,
            auto_release_mode VARCHAR(20) NOT NULL DEFAULT 'none',
            auto_release_after_days SMALLINT(5) UNSIGNED NULL,
            completion_grace_days SMALLINT(5) UNSIGNED NULL,
            completion_mode VARCHAR(30) NOT NULL DEFAULT 'manual_done',
            max_active_claims_per_participant SMALLINT(5) UNSIGNED NOT NULL DEFAULT 3,
            parts_per_khatm SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            total_parts INT(10) UNSIGNED NOT NULL DEFAULT 0,
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
            pause_source VARCHAR(20) NULL,
            pause_note TEXT NULL,
            paused_at DATETIME NULL,
            paused_by_user_id BIGINT(20) UNSIGNED NULL,
            review_note TEXT NULL,
            reviewed_at DATETIME NULL,
            reviewed_by_user_id BIGINT(20) UNSIGNED NULL,
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
            UNIQUE KEY campaign_public_id (campaign_public_id),
            KEY slug (slug),
            KEY content_strategy (content_type, participation_strategy),
            KEY lifecycle_review (lifecycle_status, review_status),
            KEY content_item_id (content_item_id),
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
            KEY owner_phone (owner_phone)
        ) $charset;");

        dbDelta("CREATE TABLE $participants (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NULL,
            khatm_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            phone VARCHAR(50) NULL,
            normalized_phone VARCHAR(20) NULL,
            tracking_token VARCHAR(80) NOT NULL,
            claim_blocked TINYINT(1) NOT NULL DEFAULT 0,
            claim_block_reason TEXT NULL,
            claim_blocked_at DATETIME NULL,
            claim_blocked_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY khatm_id (khatm_id),
            KEY user_id (user_id),
            KEY normalized_phone (normalized_phone),
            KEY tracking_token (tracking_token),
            KEY khatm_phone (khatm_id, normalized_phone),
            KEY user_khatm (user_id, khatm_id),
            KEY khatm_claim_blocked (khatm_id, claim_blocked)
        ) $charset;");

        dbDelta("CREATE TABLE $parts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NULL,
            khatm_id BIGINT(20) UNSIGNED NOT NULL,
            participant_id BIGINT(20) UNSIGNED NULL,
            round_number SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            part_type VARCHAR(30) NOT NULL DEFAULT 'page',
            part_number SMALLINT(5) UNSIGNED NOT NULL,
            title VARCHAR(191) NULL,
            quran_ref VARCHAR(80) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            claimed_at DATETIME NULL,
            done_at DATETIME NULL,
            released_at DATETIME NULL,
            released_by BIGINT(20) UNSIGNED NULL,
            release_reason VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY khatm_id (khatm_id),
            KEY participant_id (participant_id),
            KEY round_number (round_number),
            KEY status (status),
            KEY khatm_status_round (khatm_id, status, round_number),
            KEY status_claimed_at (status, claimed_at),
            KEY released_at (released_at),
            KEY part_type_number (part_type, part_number)
        ) $charset;");

        $reading_progress = self::table('reading_progress');

        dbDelta("CREATE TABLE $reading_progress (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            part_id BIGINT(20) UNSIGNED NOT NULL,
            participant_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            khatm_id BIGINT(20) UNSIGNED NOT NULL,
            start_global_index MEDIUMINT(8) UNSIGNED NOT NULL,
            end_global_index MEDIUMINT(8) UNSIGNED NOT NULL,
            last_global_index MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
            reading_status VARCHAR(20) NOT NULL DEFAULT 'not_started',
            started_at DATETIME NULL,
            last_read_at DATETIME NULL,
            completed_at DATETIME NULL,
            audio_global_index BIGINT UNSIGNED NULL,
            audio_position_seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
            audio_updated_at DATETIME NULL,
            reminder_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_reminder_at DATETIME NULL,
            reminder_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY part_id (part_id),
            KEY participant_id (participant_id),
            KEY user_id (user_id),
            KEY khatm_id (khatm_id),
            KEY reading_status (reading_status),
            KEY last_read_at (last_read_at),
            KEY user_status (user_id, reading_status)
        ) $charset;");

        dbDelta("CREATE TABLE $personal_quran_progress (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            start_global_index MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 1,
            end_global_index MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 6236,
            last_global_index MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
            reading_status VARCHAR(20) NOT NULL DEFAULT 'not_started',
            started_at_utc DATETIME NULL,
            last_read_at_utc DATETIME NULL,
            completed_at_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_id (campaign_id),
            KEY user_status (user_id, reading_status),
            KEY last_read_at_utc (last_read_at_utc)
        ) $charset;");

        dbDelta("CREATE TABLE $reminder_schedules (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            scope_type VARCHAR(32) NOT NULL,
            scope_id BIGINT(20) UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            local_time CHAR(5) NOT NULL DEFAULT '20:00',
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            days_mask TINYINT(3) UNSIGNED NOT NULL DEFAULT 127,
            snoozed_until_utc DATETIME NULL,
            next_due_at_utc DATETIME NULL,
            last_sent_at_utc DATETIME NULL,
            last_occurrence_key VARCHAR(64) NULL,
            send_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            disabled_reason VARCHAR(32) NULL,
            metadata_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY user_scope (user_id, scope_type, scope_id),
            KEY due (enabled, next_due_at_utc),
            KEY scope (scope_type, scope_id)
        ) $charset;");

        dbDelta("CREATE TABLE $content_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NOT NULL,
            content_type VARCHAR(20) NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
            source_key VARCHAR(191) NULL,
            title VARCHAR(191) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            campaign_enabled TINYINT(1) NOT NULL DEFAULT 0,
            content_payload LONGTEXT NULL,
            source_release VARCHAR(60) NULL,
            source_href TEXT NULL,
            max_contribution BIGINT(20) UNSIGNED NULL,
            updated_by_wp_user_id BIGINT(20) UNSIGNED NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY source_identity (content_type, source_type, source_key),
            KEY type_enabled (content_type, campaign_enabled, status)
        ) $charset;");

        dbDelta("CREATE TABLE $contributions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NOT NULL,
            campaign_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            participant_id BIGINT(20) UNSIGNED NULL,
            kind VARCHAR(20) NOT NULL,
            quantity BIGINT(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'valid',
            idempotency_key CHAR(64) NOT NULL,
            parent_contribution_id BIGINT(20) UNSIGNED NULL,
            metadata_json LONGTEXT NULL,
            occurred_at_utc DATETIME NOT NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY campaign_time (campaign_id, occurred_at_utc),
            KEY user_time (user_id, occurred_at_utc),
            KEY participant_id (participant_id)
        ) $charset;");

        dbDelta("CREATE TABLE $claim_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(32) NOT NULL,
            campaign_id BIGINT(20) UNSIGNED NOT NULL,
            part_id BIGINT(20) UNSIGNED NULL,
            participant_id BIGINT(20) UNSIGNED NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            event_type VARCHAR(40) NOT NULL,
            quota_delta SMALLINT(6) NOT NULL DEFAULT 0,
            idempotency_key CHAR(64) NULL,
            metadata_json LONGTEXT NULL,
            occurred_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY user_quota_window (user_id, occurred_at_utc, quota_delta),
            KEY campaign_time (campaign_id, occurred_at_utc),
            KEY part_id (part_id),
            KEY event_type (event_type)
        ) $charset;");

        dbDelta("CREATE TABLE $retention_rollups (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(32) NOT NULL,
            scope_id BIGINT(20) UNSIGNED NOT NULL,
            rollup_key VARCHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            source_max_part_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_max_participant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_max_claim_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_max_contribution_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_max_log_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            generated_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_rollup (scope_type, scope_id, rollup_key),
            KEY generated_at_utc (generated_at_utc)
        ) $charset;");

        dbDelta("CREATE TABLE $domain_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key CHAR(64) NOT NULL,
            event_name VARCHAR(96) NOT NULL,
            schema_version SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            subject_user_id BIGINT(20) UNSIGNED NULL,
            entity_type VARCHAR(48) NOT NULL DEFAULT '',
            entity_id VARCHAR(128) NOT NULL DEFAULT '',
            payload_json LONGTEXT NOT NULL,
            context_json LONGTEXT NULL,
            occurred_at_utc DATETIME NOT NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY event_stream (event_name,id),
            KEY subject_event (subject_user_id,event_name,id),
            KEY entity_lookup (entity_type,entity_id)
        ) ENGINE=InnoDB $charset;");

        dbDelta("CREATE TABLE $migrations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(191) NOT NULL,
            from_version VARCHAR(32) NULL,
            to_version VARCHAR(32) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message TEXT NULL,
            applied_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY migration_key (migration_key),
            KEY status (status),
            KEY applied_at_utc (applied_at_utc)
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

        // Schema upgrades are additive. Obsolete columns are left harmlessly in place
        // and may be removed only by an explicit maintenance tool with backup/rollback.
    }

    /**
     * Removes the e-mail columns created by pre-3.1 builds. The current model
     * has no e-mail field in either the creator or participant workflow.
     */
    private static function remove_legacy_email_columns() {
        global $wpdb;
        $targets = array(
            self::table('khatms') => array('owner_email'),
            self::table('participants') => array('email'),
        );
        foreach ($targets as $table => $columns) {
            if (!$table) { continue; }
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) { continue; }
            foreach ($columns as $column) {
                $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column));
                if ($found) { $wpdb->query("ALTER TABLE `$table` DROP COLUMN `$column`"); }
            }
        }
    }

    /**
     * Migrates test data made by the 3.0 build from wp_users to Hamnoor's own
     * user table. This is intentionally non-destructive: legacy WordPress
     * users are not deleted, while khatms and participations are relinked.
     */
    public static function migrate_legacy_profiles() {
        if ('1' === (string) get_option('hamnoor_custom_users_migrated_310', '0')) { return; }
        global $wpdb;
        $profiles = array();
        $legacy_ids = array();

        $legacy_users = get_users(array(
            'meta_key' => 'hamnoor_phone',
            'meta_compare' => 'EXISTS',
            'number' => -1,
            'fields' => 'all',
        ));
        foreach ($legacy_users as $legacy_user) {
            $phone = Hamnoor_Validator::normalize_phone(get_user_meta((int) $legacy_user->ID, 'hamnoor_phone', true));
            if (!Hamnoor_Validator::valid_iran_mobile($phone)) { continue; }
            $name = trim((string) $legacy_user->display_name);
            if (!$name || $name === (string) $legacy_user->user_login) { $name = __('همراه هم‌نور', 'hamnoor'); }
            $verified = '1' === (string) get_user_meta((int) $legacy_user->ID, 'hamnoor_mobile_verified', true);
            if (!isset($profiles[$phone])) { $profiles[$phone] = array('name'=>$name,'verified'=>$verified,'legacy_ids'=>array()); }
            if ($verified) { $profiles[$phone]['verified'] = true; }
            $profiles[$phone]['legacy_ids'][] = (int) $legacy_user->ID;
            $legacy_ids[(int) $legacy_user->ID] = $phone;
        }

        $participant_rows = $wpdb->get_results('SELECT normalized_phone AS phone, MAX(name) AS name FROM ' . self::table('participants') . " WHERE normalized_phone IS NOT NULL AND normalized_phone <> '' GROUP BY normalized_phone");
        foreach ((array) $participant_rows as $row) {
            $phone = Hamnoor_Validator::normalize_phone($row->phone);
            if (!Hamnoor_Validator::valid_iran_mobile($phone)) { continue; }
            if (!isset($profiles[$phone])) { $profiles[$phone] = array('name'=>trim((string)$row->name),'verified'=>false,'legacy_ids'=>array()); }
        }
        $owner_rows = $wpdb->get_results('SELECT owner_phone AS phone, MAX(owner_name) AS name FROM ' . self::table('khatms') . " WHERE owner_phone IS NOT NULL AND owner_phone <> '' GROUP BY owner_phone");
        foreach ((array) $owner_rows as $row) {
            $phone = Hamnoor_Validator::normalize_phone($row->phone);
            if (!Hamnoor_Validator::valid_iran_mobile($phone)) { continue; }
            if (!isset($profiles[$phone])) { $profiles[$phone] = array('name'=>trim((string)$row->name),'verified'=>false,'legacy_ids'=>array()); }
        }

        foreach ($profiles as $phone => $profile) {
            $name = !empty($profile['name']) ? $profile['name'] : __('همراه هم‌نور', 'hamnoor');
            $custom = Hamnoor_Mobile_Access::ensure_user($phone, $name);
            if (is_wp_error($custom) || !$custom) { continue; }
            $custom_id = (int) $custom->id;
            if (!empty($profile['verified'])) {
                $wpdb->update(self::table('users'), array('mobile_verified'=>1,'verified_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('id'=>$custom_id), array('%d','%s','%s'), array('%d'));
            }
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table('participants') . ' SET user_id = %d, updated_at = %s WHERE normalized_phone = %s',
                $custom_id,
                current_time('mysql'),
                $phone
            ));
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table('khatms') . ' SET owner_user_id = %d, owner_phone = %s, updated_at = %s WHERE owner_phone = %s',
                $custom_id,
                $phone,
                current_time('mysql'),
                $phone
            ));
            foreach ((array) $profile['legacy_ids'] as $legacy_id) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('khatms') . ' SET owner_user_id = %d WHERE owner_user_id = %d', $custom_id, (int) $legacy_id));
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('participants') . ' SET user_id = %d WHERE user_id = %d', $custom_id, (int) $legacy_id));
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('logs') . ' SET user_id = %d WHERE user_id = %d', $custom_id, (int) $legacy_id));
            }
        }
        update_option('hamnoor_custom_users_migrated_310', '1', false);
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
        $data['status'] = isset($source['status']) && in_array(sanitize_key($source['status']), array('pending_review','needs_revision','rejected','active','paused','expired','completed','cancelled','archived','failed'), true) ? sanitize_key($source['status']) : '';
        $data['indexable'] = !empty($source['indexable']) ? 1 : 0;
        $start_raw = isset($source['starts_at_jalali']) ? $source['starts_at_jalali'] : (isset($source['starts_at']) ? $source['starts_at'] : '');
        $end_raw = isset($source['ends_at_jalali']) ? $source['ends_at_jalali'] : (isset($source['ends_at']) ? $source['ends_at'] : '');
        $data['starts_at_jalali'] = isset($source['starts_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['starts_at_jalali']))) : '';
        $data['ends_at_jalali'] = isset($source['ends_at_jalali']) ? sanitize_text_field(Hamnoor_Validator::normalize_digits(wp_unslash($source['ends_at_jalali']))) : '';
        $data['starts_at'] = $start_raw !== '' ? Hamnoor_Validator::normalize_datetime(wp_unslash($start_raw), false) : '';
        $data['ends_at'] = $end_raw !== '' ? Hamnoor_Validator::normalize_datetime(wp_unslash($end_raw), true) : '';
        if (isset($source['cover_url']) && '' !== trim((string) $source['cover_url'])) { $data['cover_url'] = esc_url_raw(wp_unslash($source['cover_url'])); }
        if (!empty($source['remove_cover'])) { $data['remove_cover'] = 1; }
        $mode = isset($source['auto_release_mode']) ? sanitize_key(wp_unslash($source['auto_release_mode'])) : 'none';
        $data['auto_release_mode'] = in_array($mode, array('none','after_days'), true) ? $mode : 'none';
        $data['auto_release_after_days'] = isset($source['auto_release_after_days']) ? max(0, min(3650, (int) Hamnoor_Validator::normalize_digits(wp_unslash($source['auto_release_after_days'])))) : 0;
        if ('after_days' !== $data['auto_release_mode']) { $data['auto_release_after_days'] = 0; }
        $grace_raw = isset($source['completion_grace_days']) ? trim((string) wp_unslash($source['completion_grace_days'])) : '';
        $data['completion_grace_days'] = '' === $grace_raw ? null : max(0, min(3650, (int) Hamnoor_Validator::normalize_digits($grace_raw)));
        if (empty($data['ends_at'])) { $data['completion_grace_days'] = null; }
        $completion_mode = isset($source['completion_mode']) ? sanitize_key(wp_unslash($source['completion_mode'])) : 'manual_done';
        $data['completion_mode'] = in_array($completion_mode, array('manual_done','auto_done_on_claim'), true) ? $completion_mode : 'manual_done';
        $settings = Hamnoor_Helpers::settings();
        $active_limit_default = isset($settings['default_active_claim_limit']) ? (int)$settings['default_active_claim_limit'] : 3;
        $active_limit_raw = isset($source['max_active_claims_per_participant']) ? Hamnoor_Validator::normalize_digits(wp_unslash($source['max_active_claims_per_participant'])) : $active_limit_default;
        $data['max_active_claims_per_participant'] = max(1, min(50, (int)$active_limit_raw));
        if ('auto_done_on_claim' === $data['completion_mode']) {
            $data['auto_release_mode'] = 'none';
            $data['auto_release_after_days'] = 0;
        }
        return $data;
    }

    public static function normalize_type($type) {
        $type = sanitize_key((string) $type);
        return in_array($type, array('page', 'juz', 'surah', 'repeated_surah'), true) ? $type : 'page';
    }

    private static function canonical_status_fields($legacy_status) {
        list($lifecycle, $review) = Hamnoor_Campaign_Domain::legacy_status_to_domain($legacy_status);
        return array('lifecycle_status'=>$lifecycle, 'review_status'=>$review);
    }

    public static function create_khatm($data, $domain_overrides = array()) {
        global $wpdb;
        $user = Hamnoor_Mobile_Access::current_user();
        $requested_owner = !empty($domain_overrides['owner_user_id']) ? Hamnoor_Mobile_Access::get_user((int)$domain_overrides['owner_user_id']) : null;
        $identity_for_validation = $requested_owner ?: $user;
        if ($identity_for_validation) {
            $data['owner_name'] = (string) $identity_for_validation->display_name;
            $data['owner_phone'] = (string) $identity_for_validation->phone;
        }
        $valid = Hamnoor_Validator::validate_khatm($data);
        if (is_wp_error($valid)) { return $valid; }
        $now = current_time('mysql');
        $token = self::generate_unique_token();
        if (is_wp_error($token)) { return $token; }
        $title = Hamnoor_Validator::trim_len(sanitize_text_field($data['title']), 191);
        $slug = Hamnoor_Validator::trim_len(sanitize_title($title), 191);
        $owner_user_id = Hamnoor_Mobile_Access::actor_id() ? Hamnoor_Mobile_Access::actor_id() : null;
        if (!empty($domain_overrides['owner_user_id'])) { $owner_user_id = (int)$domain_overrides['owner_user_id']; }
        $owner_identity = $owner_user_id ? Hamnoor_Mobile_Access::get_user((int)$owner_user_id) : $user;
        $owner_name = !empty($data['owner_name']) ? Hamnoor_Validator::trim_len(sanitize_text_field($data['owner_name']), 191) : ($owner_identity ? Hamnoor_Validator::trim_len($owner_identity->display_name, 191) : '');
        if ($owner_identity) { $owner_name = Hamnoor_Validator::trim_len($owner_identity->display_name, 191); $data['owner_phone'] = (string) $owner_identity->phone; }
        $type = self::normalize_type($data['khatm_type']);
        $khatm_count = self::khatm_count_for_type($type, isset($data['khatm_count']) ? (int) $data['khatm_count'] : 1);
        $parts_per_khatm = self::parts_per_khatm($type, isset($data['repeat_count']) ? (int) $data['repeat_count'] : 100);
        $total_parts = $khatm_count * $parts_per_khatm;
        $settings = Hamnoor_Helpers::settings();
        $is_admin_creator = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $initial_status = $is_admin_creator ? 'active' : 'pending_review';
        do_action('hamnoor_before_create_khatm', $data);
        $privacy = isset($data['privacy']) && 'private' === $data['privacy'] ? 'private' : 'public';
        list($initial_lifecycle, $initial_review) = Hamnoor_Campaign_Domain::legacy_status_to_domain($initial_status);
        $canonical_access = Hamnoor_Campaign_Domain::legacy_privacy_to_access($privacy);
        $canonical_participation = Hamnoor_Campaign_Domain::PARTICIPATION_ANYONE;
        if (!empty($domain_overrides) && is_array($domain_overrides)) {
            $requested_access = sanitize_key((string)($domain_overrides['access_mode'] ?? $canonical_access));
            $requested_participation = sanitize_key((string)($domain_overrides['participation_policy'] ?? $canonical_participation));
            if (!in_array($requested_access, Hamnoor_Campaign_Domain::access_modes(), true) || !in_array($requested_participation, Hamnoor_Campaign_Domain::participation_policies(), true)) {
                return new WP_Error('hamnoor_campaign_domain_override_invalid', __('سیاست دسترسی پویش معتبر نیست.', 'hamnoor'));
            }
            if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $requested_access && Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY !== $requested_participation) {
                return new WP_Error('hamnoor_campaign_owner_policy_required', __('پویش شخصی باید فقط برای مالک قابل مشارکت باشد.', 'hamnoor'));
            }
            if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY !== $requested_access && Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY === $requested_participation) {
                return new WP_Error('hamnoor_campaign_owner_access_required', __('مشارکت فقط مالک تنها برای پویش شخصی مجاز است.', 'hamnoor'));
            }
            $canonical_access = $requested_access;
            $canonical_participation = $requested_participation;
            $privacy = Hamnoor_Campaign_Domain::ACCESS_PUBLIC === $canonical_access ? 'public' : 'private';
            if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $canonical_access) {
                $initial_status = 'active';
                $initial_lifecycle = Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE;
                $initial_review = Hamnoor_Campaign_Domain::REVIEW_NOT_REQUIRED;
            }
        }
        $wpdb->query('START TRANSACTION');
        $insert_values = array(
            'token'=>$token,
            'slug'=>$slug,
            'title'=>$title,
            'intention'=>wp_kses_post($data['intention']),
            'description'=>wp_kses_post($data['description']),
            'cover_id'=>isset($data['cover_id']) ? (int)$data['cover_id'] : null,
            'cover_url'=>!empty($data['cover_url']) ? esc_url_raw($data['cover_url']) : '',
            'owner_user_id'=>$owner_user_id,
            'owner_name'=>$owner_name,
            'owner_phone'=>Hamnoor_Validator::normalize_phone($data['owner_phone']),
            // Canonical domain projection. The legacy khatm_* fields remain an adapter
            // for the existing Quran UI until the frontend is moved to Campaign v2.
            'campaign_public_id'=>self::generate_public_id('CMP'),
            'content_type'=>Hamnoor_Campaign_Domain::CONTENT_QURAN,
            'participation_strategy'=>Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED,
            'access_mode'=>$canonical_access,
            'participation_policy'=>$canonical_participation,
            'lifecycle_status'=>$initial_lifecycle,
            'review_status'=>$initial_review,
            'domain_version'=>1,
            'khatm_type'=>$type,
            'khatm_count'=>$khatm_count,
            'max_khatm_count'=>!empty($data['max_khatm_count']) ? (int)$data['max_khatm_count'] : null,
            'max_repeat_count'=>!empty($data['max_repeat_count']) ? (int)$data['max_repeat_count'] : null,
            'auto_release_mode'=>isset($data['auto_release_mode']) ? $data['auto_release_mode'] : 'none',
            'auto_release_after_days'=>!empty($data['auto_release_after_days']) ? (int)$data['auto_release_after_days'] : null,
            'completion_grace_days'=>isset($data['completion_grace_days']) ? $data['completion_grace_days'] : null,
            'completion_mode'=>isset($data['completion_mode']) && 'auto_done_on_claim' === $data['completion_mode'] ? 'auto_done_on_claim' : 'manual_done',
            'max_active_claims_per_participant'=>isset($data['max_active_claims_per_participant']) ? max(1, min(50, (int)$data['max_active_claims_per_participant'])) : max(1, min(50, (int)$settings['default_active_claim_limit'])),
            'parts_per_khatm'=>$parts_per_khatm,
            'total_parts'=>$total_parts,
            'is_featured'=>0,
            'featured_until'=>null,
            'featured_label'=>null,
            'privacy'=>$privacy,
            'status'=>$initial_status,
            'indexable'=>(Hamnoor_Campaign_Domain::ACCESS_PUBLIC !== $canonical_access || '1' !== $settings['index_public_khatms'] || empty($data['indexable'])) ? 0 : 1,
            'published_at'=>('active' === $initial_status) ? $now : null,
            'published_by_user_id'=>('active' === $initial_status) ? Hamnoor_Mobile_Access::actor_id() : null,
            'starts_at'=>!empty($data['starts_at']) ? $data['starts_at'] : null,
            'ends_at'=>!empty($data['ends_at']) ? $data['ends_at'] : null,
            'created_at'=>$now,
            'updated_at'=>$now,
        );
        $insert_formats = array('%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%d','%s','%d','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s');
        $inserted = $wpdb->insert(self::table('khatms'), $insert_values, $insert_formats);
        if (!$inserted) {
            $error = $wpdb->last_error ? $wpdb->last_error : 'Unknown database insert error.';
            $wpdb->query('ROLLBACK');
            self::log(0, 0, 0, $owner_user_id, 'khatm_create_db_error', $error);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) { error_log('[Hamnoor] Khatm insert failed: ' . $error); }
            return new WP_Error('hamnoor_db_error', __('ایجاد پویش انجام نشد. اطلاعات واردشده حفظ شد؛ لطفاً چند لحظه دیگر دوباره تلاش کنید یا با مدیر سایت تماس بگیرید.', 'hamnoor'));
        }
        $khatm_id = (int) $wpdb->insert_id;
        $parts_result = self::generate_parts($khatm_id, $type, isset($data['selected_surah_number']) ? (int)$data['selected_surah_number'] : 1, isset($data['repeat_count']) ? (int)$data['repeat_count'] : 100, $khatm_count);
        if (is_wp_error($parts_result)) {
            $wpdb->update(self::table('khatms'), array('status'=>'failed','lifecycle_status'=>Hamnoor_Campaign_Domain::LIFECYCLE_FAILED,'review_status'=>Hamnoor_Campaign_Domain::REVIEW_PENDING,'updated_at'=>$now), array('id'=>$khatm_id));
            self::log($khatm_id, 0, 0, $owner_user_id, 'khatm_parts_failed', $parts_result->get_error_message());
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_parts_failed', __('پویش ایجاد نشد؛ در تولید سهم‌های قرائت خطایی رخ داد. لطفاً دوباره تلاش کنید.', 'hamnoor'));
        }
        $wpdb->query('COMMIT');
        $created_active = 'active' === $initial_status;
        self::log($khatm_id, 0, 0, $owner_user_id, 'khatm_created', $created_active ? __('پویش ایجاد و منتشر شد.', 'hamnoor') : __('پویش ایجاد و برای بررسی مدیر ارسال شد.', 'hamnoor'));
        if ($owner_user_id) {
            self::add_notification($owner_user_id, 'khatm_created', $created_active ? __('پویش شما منتشر شد', 'hamnoor') : __('پویش شما در انتظار بررسی است', 'hamnoor'), $created_active ? __('پویش با موفقیت ایجاد و منتشر شد.', 'hamnoor') : __('پویش با موفقیت ثبت شد و پس از بررسی مدیر منتشر خواهد شد.', 'hamnoor'), 'khatm', $khatm_id);
        }
        $created_khatm = self::get_khatm($khatm_id);
        do_action('hamnoor_khatm_created', $khatm_id, $data);
        do_action('hamnoor_after_create_khatm', $khatm_id, $created_khatm, $data);
        if ($created_khatm && 'active' === (string) $created_khatm->status && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY !== (string) $created_khatm->access_mode) {
            do_action('hamnoor_after_invitation_created', $khatm_id, Hamnoor_Helpers::invite_url($created_khatm), $created_khatm);
        }
        return $khatm_id;
    }

    public static function update_khatm($khatm_id, $data) {
        global $wpdb;
        $khatm = self::get_khatm($khatm_id);
        if (!$khatm) {
            return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor'));
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

        $owner_user = !empty($khatm->owner_user_id) ? Hamnoor_Mobile_Access::get_user((int) $khatm->owner_user_id) : Hamnoor_Mobile_Access::current_user();
        if ($owner_user) {
            // Keep ownership identity consistent. Admin content edits must not silently
            // rewrite the owner's display phone/name while owner_user_id still points elsewhere.
            $data['owner_name'] = (string) $owner_user->display_name;
            $data['owner_phone'] = (string) $owner_user->phone;
        }
        $valid = Hamnoor_Validator::validate_khatm($data);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $privacy = isset($data['privacy']) && 'private' === $data['privacy'] ? 'private' : 'public';
        $can_change_status = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms');
        $requested_status = isset($data['status']) ? sanitize_key($data['status']) : '';
        $new_status = ($can_change_status && in_array($requested_status, array('pending_review','needs_revision','rejected','active','paused','expired','completed','cancelled','archived','failed'), true)) ? $requested_status : (string) $khatm->status;
        $fields = array(
            'title' => Hamnoor_Validator::trim_len(sanitize_text_field($data['title']), 191),
            'slug' => Hamnoor_Validator::trim_len(sanitize_title($data['title']), 191),
            'intention' => wp_kses_post($data['intention']),
            'description' => wp_kses_post($data['description']),
            'max_khatm_count' => !empty($data['max_khatm_count']) ? (int) $data['max_khatm_count'] : null,
            'max_repeat_count' => !empty($data['_can_set_limits']) ? (!empty($data['max_repeat_count']) ? (int) $data['max_repeat_count'] : null) : (isset($khatm->max_repeat_count) ? $khatm->max_repeat_count : null),
            'auto_release_mode' => isset($data['auto_release_mode']) ? $data['auto_release_mode'] : (isset($khatm->auto_release_mode) ? $khatm->auto_release_mode : 'none'),
            'auto_release_after_days' => !empty($data['auto_release_after_days']) ? (int) $data['auto_release_after_days'] : null,
            'completion_grace_days' => isset($data['completion_grace_days']) ? $data['completion_grace_days'] : null,
            'completion_mode' => isset($data['completion_mode']) && 'auto_done_on_claim' === $data['completion_mode'] ? 'auto_done_on_claim' : 'manual_done',
            'max_active_claims_per_participant' => isset($data['max_active_claims_per_participant']) ? max(1, min(50, (int)$data['max_active_claims_per_participant'])) : (isset($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : 3),
            'owner_name' => Hamnoor_Validator::trim_len(sanitize_text_field($data['owner_name']), 191),
            'owner_phone' => Hamnoor_Validator::normalize_phone($data['owner_phone']),
            'privacy' => $privacy,
            'access_mode' => Hamnoor_Campaign_Domain::legacy_privacy_to_access($privacy),
            'status' => $new_status,
            'lifecycle_status' => self::canonical_status_fields($new_status)['lifecycle_status'],
            'review_status' => self::canonical_status_fields($new_status)['review_status'],
            'indexable' => !empty($data['indexable']) && 'private' !== $privacy ? 1 : 0,
            'starts_at' => !empty($data['starts_at']) ? $data['starts_at'] : null,
            'ends_at' => !empty($data['ends_at']) ? $data['ends_at'] : null,
            'updated_at' => current_time('mysql'),
        );
        if ('active' === $new_status && empty($khatm->published_at)) {
            $fields['published_at'] = current_time('mysql');
            $fields['published_by_user_id'] = Hamnoor_Mobile_Access::actor_id();
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
            self::log($khatm_id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'khatm_update_db_error', $wpdb->last_error ? $wpdb->last_error : 'Unknown update error');
            return new WP_Error('hamnoor_update_failed', __('ویرایش پویش انجام نشد. لطفاً دوباره تلاش کنید.', 'hamnoor'));
        }
        self::log($khatm_id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'khatm_updated', __('اطلاعات پویش ویرایش شد.', 'hamnoor'));
        $updated_khatm = self::get_khatm((int) $khatm_id);
        do_action('hamnoor_after_update_khatm', (int) $khatm_id, $updated_khatm, $khatm, $fields);
        if ($updated_khatm) { self::notify_operational_khatm_change($khatm, $updated_khatm); }
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
            return new WP_Error('hamnoor_parts_too_many', sprintf(__('تعداد سهم‌های این پویش بسیار زیاد است (%1$d سهم). برای پایداری سایت، حداکثر مجاز فعلی %2$d سهم است.', 'hamnoor'), $total, $hard_limit));
        }

        $table = self::table('parts');
        $rows = array();
        $values = array();
        $batch_size = (int) apply_filters('hamnoor_parts_batch_size', 250);
        $batch_size = max(50, min(500, $batch_size));

        $flush = function() use (&$rows, &$values, $wpdb, $table) {
            if (!$rows) { return true; }
            $sql = "INSERT INTO $table (public_id, khatm_id, participant_id, round_number, part_type, part_number, title, quran_ref, status, created_at, updated_at) VALUES " . implode(',', $rows);
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
                $round_prefix = $khatm_count > 1 ? sprintf(__('دور %d، ', 'hamnoor'), $round) : '';
                if ('page' === $part_type) {
                    $title = $round_prefix . sprintf(__('صفحه %d', 'hamnoor'), $part_number);
                    $ref = 'round:' . $round . ':page:' . $part_number;
                } elseif ('juz' === $part_type) {
                    $title = $round_prefix . sprintf(__('جزء %d', 'hamnoor'), $part_number);
                    $ref = 'round:' . $round . ':juz:' . $part_number;
                } else {
                    $surah = Hamnoor_Helpers::surah($part_number);
                    if ('surah' === $type) {
                        $title = $round_prefix . sprintf(__('سوره %s', 'hamnoor'), $surah ? $surah['fa'] : $part_number);
                        $ref = 'round:' . $round . ':surah:' . $part_number;
                    } else {
                        $title = sprintf(__('سوره %s', 'hamnoor'), $surah ? $surah['fa'] : $part_number);
                        $ref = 'surah:' . $part_number . ':repeat:' . $i;
                        $title .= ' - ' . sprintf(__('مرتبه %d', 'hamnoor'), $i);
                    }
                }
                $rows[] = '(%s,%d,%d,%d,%s,%d,%s,%s,%s,%s,%s)';
                array_push($values, self::generate_public_id('PRT'), (int) $khatm_id, null, (int) $round, $part_type, (int) $part_number, $title, $ref, 'available', $now, $now);
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
        if (is_int($id_or_token) || (is_string($id_or_token) && ctype_digit($id_or_token) && 5 !== strlen($id_or_token))) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id_or_token));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", Hamnoor_Helpers::sanitize_token($id_or_token)));
    }

    public static function get_khatms($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('limit'=>20,'offset'=>0,'s'=>'','privacy'=>'','status'=>'','khatm_type'=>'','participation_strategy'=>'','participation_strategy__not'=>'','owner_user_id'=>0,'featured_filter'=>'','orderby'=>'id','order'=>'DESC'));
        $table = self::table('khatms');
        $where = 'WHERE 1=1';
        $params = array();
        foreach (array('privacy','khatm_type','participation_strategy') as $key) {
            if (!empty($args[$key])) { $where .= " AND $key = %s"; $params[] = sanitize_key($args[$key]); }
        }
        if (!empty($args['participation_strategy__not'])) { $where .= ' AND (participation_strategy IS NULL OR participation_strategy <> %s)'; $params[] = sanitize_key($args['participation_strategy__not']); }
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
            $where .= ' AND (title LIKE %s OR intention LIKE %s OR owner_name LIKE %s OR owner_phone LIKE %s OR token LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
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
        $args = wp_parse_args($args, array('s'=>'','privacy'=>'','status'=>'','khatm_type'=>'','participation_strategy'=>'','participation_strategy__not'=>'','owner_user_id'=>0,'featured_filter'=>''));
        $table = self::table('khatms');
        $where = 'WHERE 1=1';
        $params = array();
        foreach (array('privacy','khatm_type','participation_strategy') as $key) {
            if (!empty($args[$key])) { $where .= " AND $key = %s"; $params[] = sanitize_key($args[$key]); }
        }
        if (!empty($args['participation_strategy__not'])) { $where .= ' AND (participation_strategy IS NULL OR participation_strategy <> %s)'; $params[] = sanitize_key($args['participation_strategy__not']); }
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
            $where .= ' AND (title LIKE %s OR intention LIKE %s OR owner_name LIKE %s OR owner_phone LIKE %s OR token LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT COUNT(*) FROM $table $where";
        return $params ? (int) $wpdb->get_var($wpdb->prepare($sql, $params)) : (int) $wpdb->get_var($sql);
    }

    public static function get_parts($khatm_id, $status = '', $args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('round_number'=>0, 'search'=>'', 'limit'=>0, 'offset'=>0));
        $parts = self::table('parts');
        $participants = self::table('participants');
        $sql = "SELECT p.*, r.name AS participant_name, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token FROM $parts p LEFT JOIN $participants r ON r.id = p.participant_id WHERE p.khatm_id = %d";
        $params = array((int) $khatm_id);
        if ($status) { $sql .= ' AND p.status = %s'; $params[] = sanitize_key($status); }
        if (!empty($args['round_number'])) { $sql .= ' AND p.round_number = %d'; $params[] = (int) $args['round_number']; }
        if (!empty($args['search'])) { $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%'; $sql .= ' AND (p.title LIKE %s OR p.part_number = %d OR p.quran_ref LIKE %s OR r.name LIKE %s OR r.phone LIKE %s)'; $params[] = $like; $params[] = (int) Hamnoor_Validator::normalize_digits($args['search']); $params[] = $like; $params[] = $like; $params[] = $like; }
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
            $sql .= ' AND (p.title LIKE %s OR p.part_number = %d OR p.quran_ref LIKE %s OR r.name LIKE %s OR r.phone LIKE %s)';
            $params[] = $like;
            $params[] = (int) Hamnoor_Validator::normalize_digits($args['search']);
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
        return $wpdb->get_row($wpdb->prepare("SELECT p.*, r.name AS participant_name, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token FROM $parts p LEFT JOIN $participants r ON r.id = p.participant_id WHERE p.id = %d", (int) $part_id));
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
        $current_user_id = Hamnoor_Mobile_Access::actor_id();

        // An unauthenticated claim must never reuse or modify a participant row
        // already linked to an account merely because the same phone was typed.
        // Authenticated users may reuse their own row or adopt an unlinked guest
        // row for the same phone. Guest claims only reuse guest rows.
        if ($current_user_id) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                 WHERE khatm_id = %d
                   AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND normalized_phone = %s))
                 ORDER BY CASE WHEN user_id = %d THEN 0 ELSE 1 END, id ASC
                 LIMIT 1 FOR UPDATE",
                (int)$khatm_id,
                (int)$current_user_id,
                $phone,
                (int)$current_user_id
            ));
        } else {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                 WHERE khatm_id = %d AND (user_id IS NULL OR user_id = 0) AND normalized_phone = %s
                 ORDER BY id ASC LIMIT 1 FOR UPDATE",
                (int)$khatm_id,
                $phone
            ));
        }

        $now = current_time('mysql');
        if ($existing) {
            $record_meta['linked_to_user'] = $current_user_id && (int)$existing->user_id !== (int)$current_user_id;
            $update = array(
                'name'=>sanitize_text_field($data['name']),
                'phone'=>$phone,
                'normalized_phone'=>$phone,
                'updated_at'=>$now,
            );
            $formats = array('%s','%s','%s','%s');
            if ($current_user_id) {
                $update['user_id'] = (int)$current_user_id;
                $formats[] = '%d';
            }
            $wpdb->update($table, $update, array('id'=>(int)$existing->id), $formats, array('%d'));
            return (int)$existing->id;
        }

        $tracking = self::generate_token(24);
        $inserted = $wpdb->insert(
            $table,
            array(
                'public_id'=>self::generate_public_id('PAR'),
                'khatm_id'=>(int)$khatm_id,
                'user_id'=>$current_user_id ?: null,
                'name'=>sanitize_text_field($data['name']),
                'phone'=>$phone,
                'normalized_phone'=>$phone,
                'tracking_token'=>$tracking,
                'created_at'=>$now,
                'updated_at'=>$now,
            ),
            array('%s','%d','%d','%s','%s','%s','%s','%s','%s')
        );
        if (!$inserted) { return 0; }
        $record_meta['created'] = true;
        $record_meta['linked_to_user'] = (bool)$current_user_id;
        return (int)$wpdb->insert_id;
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
        $blocked = (int)$wpdb->get_var($wpdb->prepare("SELECT claim_blocked FROM $participants WHERE id=%d", $participant_id));
        if ($blocked) { return false; }
        return false !== $wpdb->delete($participants, array('id' => $participant_id), array('%d'));
    }

    public static function khatm_accepts_claims($khatm) {
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        if ('active' !== (string) $khatm->status) {
            return new WP_Error('hamnoor_khatm_not_active', __('این پویش در وضعیت فعال نیست و امکان رزرو سهم جدید وجود ندارد.', 'hamnoor'));
        }
        $now = current_time('mysql');
        if (!empty($khatm->starts_at) && (string)$khatm->starts_at > $now) {
            return new WP_Error('hamnoor_khatm_not_started', __('زمان شروع پذیرش سهم هنوز فرا نرسیده است.', 'hamnoor'));
        }
        if (!empty($khatm->ends_at) && (string)$khatm->ends_at < $now) {
            // Keep the persisted lifecycle consistent with the deadline. Existing
            // claimed shares remain completable through khatm_accepts_completion().
            if ('active' === (string)$khatm->status && !empty($khatm->id)) {
                self::change_status((int)$khatm->id, 'expired');
                $khatm->status = 'expired';
            }
            return new WP_Error('hamnoor_khatm_ended', __('پذیرش سهم جدید در این پویش پایان یافته است.', 'hamnoor'));
        }
        return true;
    }


    public static function khatm_accepts_completion($khatm, $privileged = false) {
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        // Pausing closes new claims but keeps already accepted shares usable.
        if (!in_array((string)$khatm->status, array('active','paused','expired'), true)) {
            return new WP_Error('hamnoor_khatm_not_active', __('این پویش در وضعیتی نیست که ثبت قرائت برای سهم‌های قبلی مجاز باشد.', 'hamnoor'));
        }
        $now = current_time('mysql');
        if (!empty($khatm->starts_at) && (string)$khatm->starts_at > $now) {
            return new WP_Error('hamnoor_khatm_not_started', __('زمان شروع پذیرش سهم هنوز فرا نرسیده است و ثبت قرائت مجاز نیست.', 'hamnoor'));
        }
        // Empty completion_grace_days means no completion time limit. A numeric
        // value (including zero) limits existing claims to ends_at + N days.
        if (!empty($khatm->ends_at) && null !== $khatm->completion_grace_days && '' !== (string)$khatm->completion_grace_days) {
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$khatm->ends_at, wp_timezone());
            if ($end) {
                $deadline = $end->modify('+' . (int)$khatm->completion_grace_days . ' days');
                if ($deadline->getTimestamp() < time() && !$privileged) {
                    return new WP_Error('hamnoor_completion_deadline_passed', __('مهلت تکمیل سهم‌های قبلی در این پویش به پایان رسیده است.', 'hamnoor'));
                }
            }
        }
        return true;
    }

    private static function current_request_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    private static function claim_guard_before_transaction($khatm, $user_id) {
        global $wpdb;
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        $user_id = (int)$user_id;
        if (Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED !== (string)($khatm->participation_strategy ?? Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED)) {
            return new WP_Error('hamnoor_claim_strategy_invalid', __('این پویش از مدل انتخاب سهم استفاده نمی‌کند.', 'hamnoor'));
        }
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)($khatm->access_mode ?? '')) {
            if (!$user_id || $user_id !== (int)$khatm->owner_user_id) {
                return new WP_Error('hamnoor_claim_owner_only', __('این پویش شخصی فقط برای مالک آن قابل استفاده است.', 'hamnoor'));
            }
        }
        if ($user_id) {
            $user = Hamnoor_Mobile_Access::get_user($user_id);
            if ($user && !empty($user->claim_blocked)) {
                return new WP_Error('hamnoor_claim_globally_blocked', Hamnoor_Texts::get('claim_globally_blocked'));
            }
        }

        // Ordinary claim flows never trigger SMS. High-volume abuse is throttled
        // server-side instead of escalating a low-risk action to OTP.
        if (!$user_id || !Hamnoor_Mobile_Access::is_verified($user_id)) {
            $settings = Hamnoor_Helpers::settings();
            $window_minutes = max(1, min(120, (int)($settings['claim_abuse_window_minutes'] ?? 10)));
            $ip_limit = max(10, min(500, (int)($settings['claim_abuse_ip_limit'] ?? 30)));
            $ip = self::current_request_ip();
            if ($ip) {
                $since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * MINUTE_IN_SECONDS), wp_timezone());
                $logs = self::table('logs');
                $recent_ip_claims = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $logs WHERE khatm_id=%d AND ip_address=%s AND action IN ('part_claimed','part_claimed_done') AND created_at >= %s",
                    (int)$khatm->id,
                    $ip,
                    $since
                ));
                if ($recent_ip_claims >= $ip_limit) {
                    return new WP_Error('hamnoor_claim_rate_limited', __('تعداد انتخاب‌های بسیار سریع از این اتصال بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'hamnoor'));
                }
            }
        }
        return true;
    }

    private static function claim_guard_for_participant($khatm, $participant_id, $user_id) {
        global $wpdb;
        $participant_id = (int)$participant_id;
        $user_id = (int)$user_id;
        $participants = self::table('participants');
        $parts = self::table('parts');
        $logs = self::table('logs');
        $participant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $participants WHERE id=%d FOR UPDATE", $participant_id));
        if (!$participant) { return new WP_Error('hamnoor_participant_failed', __('ثبت شرکت‌کننده انجام نشد.', 'hamnoor')); }
        if (!empty($participant->claim_blocked)) {
            return new WP_Error('hamnoor_claim_campaign_blocked', Hamnoor_Texts::get('claim_campaign_blocked'));
        }
        if ($user_id) {
            $user = Hamnoor_Mobile_Access::get_user($user_id);
            if ($user && !empty($user->claim_blocked)) {
                return new WP_Error('hamnoor_claim_globally_blocked', Hamnoor_Texts::get('claim_globally_blocked'));
            }
            // claim_part() has already acquired the user row before any
            // participant lock. Keep temporal quota validation here while the
            // identity lock is held for the rest of the transaction.
            $temporal = Hamnoor_Claim_Policy::assert_temporal_quota($user_id, $khatm);
            if (is_wp_error($temporal)) { return $temporal; }
        }

        $limit = isset($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : 0;
        if ($limit < 1) {
            $settings = Hamnoor_Helpers::settings();
            $limit = max(1, min(50, (int)($settings['default_active_claim_limit'] ?? 3)));
        }
        if ('auto_done_on_claim' !== (string)$khatm->completion_mode) {
            $active_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE participant_id=%d AND khatm_id=%d AND status='claimed'", $participant_id, (int)$khatm->id));
            if ($active_count >= $limit) {
                return new WP_Error('hamnoor_active_claim_limit', Hamnoor_Texts::get('active_claim_limit_reached', array('COUNT'=>$active_count, 'MAX'=>$limit)));
            }
        }

        if (!$user_id || !Hamnoor_Mobile_Access::is_verified($user_id)) {
            $settings = Hamnoor_Helpers::settings();
            $window_minutes = max(1, min(120, (int)($settings['claim_abuse_window_minutes'] ?? 10)));
            $participant_limit = max(3, min(100, (int)($settings['participant_suspicious_claim_limit'] ?? 8)));
            $since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * MINUTE_IN_SECONDS), wp_timezone());
            // Parts retain participant_id after completion; logs retain it after
            // release. Use the larger count so rapid claim/complete/release cycles
            // cannot reset the abuse detector.
            $recent_parts = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE participant_id=%d AND claimed_at IS NOT NULL AND claimed_at >= %s", $participant_id, $since));
            $recent_logs = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE participant_id=%d AND action IN ('part_claimed','part_claimed_done') AND created_at >= %s", $participant_id, $since));
            if (max($recent_parts, $recent_logs) >= $participant_limit) {
                return new WP_Error('hamnoor_claim_rate_limited', __('انتخاب‌های پیاپی بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'hamnoor'));
            }
        }
        return true;
    }

    private static function existing_claim_result($data) {
        $key = isset($data['idempotency_key']) ? Hamnoor_Claim_Policy::normalize_idempotency_key($data['idempotency_key']) : '';
        if (!$key) { return null; }
        $event = Hamnoor_Claim_Policy::existing_event($key, 'claim_created');
        if (!$event || empty($event->part_id)) { return null; }
        $part = self::get_part((int)$event->part_id);
        if (!$part) { return null; }
        return array('part_id'=>(int)$event->part_id, 'tracking_token'=>!empty($part->tracking_token) ? (string)$part->tracking_token : '');
    }

    public static function claim_part($part_id, $data) {
        global $wpdb;
        $existing_claim = self::existing_claim_result($data);
        if ($existing_claim) {
            if ((int)$existing_claim['part_id'] !== (int)$part_id) {
                return new WP_Error('hamnoor_idempotency_conflict', __('این درخواست قبلاً برای سهم دیگری ثبت شده است.', 'hamnoor'));
            }
            return (string)$existing_claim['tracking_token'];
        }
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if ('available' !== $part->status) { return new WP_Error('hamnoor_part_unavailable', __('این سهم قبلاً رزرو شده است.', 'hamnoor')); }
        $khatm = self::get_khatm((int) $part->khatm_id);
        $claimable = self::khatm_accepts_claims($khatm);
        if (is_wp_error($claimable)) { return $claimable; }
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }
        $actor_user_id = Hamnoor_Mobile_Access::actor_id();
        $guard = self::claim_guard_before_transaction($khatm, $actor_user_id);
        if (is_wp_error($guard)) { return $guard; }

        $now = current_time('mysql');
        $auto_done = $khatm && isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode;
        $new_status = $auto_done ? 'done' : 'claimed';
        do_action('hamnoor_before_claim_part', (int) $part_id, $part, $data, $khatm);
        $wpdb->query('START TRANSACTION');
        if ($actor_user_id) {
            // Global lock order for authenticated claims: user -> participant ->
            // part/ledger. Cross-campaign requests for one identity therefore
            // serialize before they can acquire participant index/gap locks.
            $users_table = self::table('users');
            $locked_user_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $users_table WHERE id=%d FOR UPDATE", $actor_user_id));
            if (!$locked_user_id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_user_not_found', __('حساب هم‌نور پیدا نشد.', 'hamnoor'));
            }
        }
        $participant = self::find_or_create_participant_record((int) $part->khatm_id, $data);
        if (is_wp_error($participant)) { $wpdb->query('ROLLBACK'); return $participant; }
        $participant_id = (int) $participant['id'];
        $participant_guard = self::claim_guard_for_participant($khatm, $participant_id, $actor_user_id);
        if (is_wp_error($participant_guard)) {
            if (!empty($participant['created'])) { self::delete_orphan_participant($participant_id); }
            $wpdb->query('ROLLBACK');
            return $participant_guard;
        }
        $update_data = array('participant_id'=>$participant_id,'status'=>$new_status,'claimed_at'=>$now,'updated_at'=>$now);
        $update_formats = array('%d','%s','%s','%s');
        if ($auto_done) { $update_data['done_at'] = $now; $update_formats[] = '%s'; }
        $updated = $wpdb->update(self::table('parts'), $update_data, array('id'=>(int)$part_id,'status'=>'available'), $update_formats, array('%d','%s'));
        if (!$updated) {
            if (!empty($participant['created'])) { self::delete_orphan_participant($participant_id); }
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_claim_failed', Hamnoor_Texts::get('part_already_claimed'));
        }
        $event_key = !empty($data['idempotency_key']) ? (string)$data['idempotency_key'] : hash('sha256', 'claim|' . (int)$part_id . '|' . $participant_id . '|' . $now);
        $event_id = Hamnoor_Claim_Policy::record_event('claim_created', (int)$part->khatm_id, (int)$part_id, $participant_id, $actor_user_id, 1, $event_key, array('auto_done'=>(bool)$auto_done));
        if (!$event_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_claim_ledger_failed', __('ثبت رویداد انتخاب سهم انجام نشد؛ هیچ تغییری ذخیره نشد.', 'hamnoor'));
        }
        $wpdb->query($wpdb->prepare("UPDATE " . self::table('khatms') . " SET first_participation_at=COALESCE(first_participation_at,%s),updated_at=%s WHERE id=%d", $now, $now, (int)$part->khatm_id));
        if ($auto_done) {
            $complete_event_key = hash('sha256', 'claim-auto-complete|' . $event_id);
            if (!Hamnoor_Claim_Policy::record_event('claim_completed', (int)$part->khatm_id, (int)$part_id, $participant_id, $actor_user_id, 0, $complete_event_key, array('source'=>'auto_done_on_claim'))) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_claim_ledger_failed', __('ثبت رویداد تکمیل سهم انجام نشد؛ هیچ تغییری ذخیره نشد.', 'hamnoor'));
            }
        }
        $wpdb->query('COMMIT');
        self::log((int) $part->khatm_id, (int) $part_id, $participant_id, Hamnoor_Mobile_Access::actor_id(), $auto_done ? 'part_claimed_done' : 'part_claimed', $auto_done ? __('سهم با انتخاب، خوانده‌شده ثبت شد.', 'hamnoor') : __('سهم رزرو شد.', 'hamnoor'));
        $claim_user_id = Hamnoor_Mobile_Access::actor_id();
        if ($claim_user_id) { self::add_notification($claim_user_id, 'part_claimed', __('سهم شما ثبت شد', 'hamnoor'), $auto_done ? __('این سهم مطابق تنظیم پویش، انجام‌شده ثبت شد.', 'hamnoor') : __('سهم انتخاب‌شده در بخش «سهم‌های من» داشبورد شما قرار گرفت.', 'hamnoor'), 'part', (int) $part_id); }
        if (!empty($participant['created'])) { do_action('hamnoor_after_participant_created', $participant_id, (int) $part->khatm_id, $data); }
        if (!empty($participant['linked_to_user'])) { do_action('hamnoor_after_participant_linked_to_user', $participant_id, Hamnoor_Mobile_Access::actor_id(), array('khatm_id' => (int) $part->khatm_id)); }
        do_action('hamnoor_part_claimed', (int) $part_id, (int) $participant_id, (int) $part->khatm_id);
        do_action('hamnoor_after_claim_part', (int) $part_id, (int) $participant_id, (int) $part->khatm_id, $data);
        if ($auto_done) {
            Hamnoor_Domain_Events::quran_part_completed((int)$part_id, (int)$actor_user_id);
            do_action('hamnoor_part_completed', (int) $part_id, (int) $participant_id, (int) $part->khatm_id);
            do_action('hamnoor_after_complete_part', (int) $part_id, (int) $participant_id, (int) $part->khatm_id, array('source' => 'auto_done_on_claim'));
            self::maybe_mark_completed((int) $part->khatm_id);
        }
        $participant = self::get_part($part_id);
        return $participant && !empty($participant->tracking_token) ? $participant->tracking_token : '';
    }

    public static function claim_next_available_part($khatm_id, $data, $round_number = 0) {
        global $wpdb;
        $existing_claim = self::existing_claim_result($data);
        if ($existing_claim) { return $existing_claim; }
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }
        $khatm = self::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        $claimable = self::khatm_accepts_claims($khatm);
        if (is_wp_error($claimable)) { return $claimable; }
        $parts = self::table('parts');
        $params = array((int) $khatm_id);
        $where = "khatm_id = %d AND status = 'available'";
        if ($round_number) { $where .= ' AND round_number = %d'; $params[] = (int) $round_number; }

        // A candidate can disappear between SELECT and the conditional UPDATE.
        // Retry another free candidate instead of surfacing a false failure.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $part_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $parts WHERE $where ORDER BY round_number ASC, id ASC LIMIT 1", $params));
            if (!$part_id) { return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part')); }
            $result = self::claim_part($part_id, $data);
            if (!is_wp_error($result)) { return array('part_id' => $part_id, 'tracking_token' => $result); }
            if (!in_array($result->get_error_code(), array('hamnoor_claim_failed','hamnoor_part_unavailable'), true)) { return $result; }
        }
        return new WP_Error('hamnoor_claim_retry', __('هم‌زمان چند نفر در حال انتخاب سهم هستند؛ لطفاً یک‌بار دیگر تلاش کنید.', 'hamnoor'));
    }

    public static function claim_random_available_part($khatm_id, $data, $round_number = 0) {
        global $wpdb;
        $existing_claim = self::existing_claim_result($data);
        if ($existing_claim) { return $existing_claim; }
        $valid = Hamnoor_Validator::validate_claim($data);
        if (is_wp_error($valid)) { return $valid; }
        $khatm = self::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
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

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $part_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $parts WHERE $where ORDER BY RAND() LIMIT 1", $params));
            if (!$part_id) { return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part')); }
            $result = self::claim_part($part_id, $data);
            if (!is_wp_error($result)) { return array('part_id' => $part_id, 'tracking_token' => $result); }
            if (!in_array($result->get_error_code(), array('hamnoor_claim_failed','hamnoor_part_unavailable'), true)) { return $result; }
        }
        return new WP_Error('hamnoor_claim_retry', __('هم‌زمان چند نفر در حال انتخاب سهم هستند؛ لطفاً یک‌بار دیگر تلاش کنید.', 'hamnoor'));
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
                return new WP_Error('hamnoor_current_round_no_free', __('در پویش فعلی سهم آزادی باقی نمانده است. پس از ثبت قرائت سهم‌های انتخاب‌شده، امکان انتخاب از پویش بعدی فعال می‌شود.', 'hamnoor'));
            }
        }
        return new WP_Error('hamnoor_no_free_part', Hamnoor_Texts::get('no_free_part'));
    }

    public static function can_complete_part($part, $tracking_token = '', $force = false) {
        if (!$part) { return false; }
        if ($force && self::user_can_manage_khatm((int) $part->khatm_id)) { return true; }
        if (!empty($tracking_token) && !empty($part->tracking_token) && hash_equals((string) $part->tracking_token, (string) $tracking_token)) { return true; }
        $user_id = Hamnoor_Mobile_Access::actor_id();
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
        do_action('hamnoor_before_complete_part', (int) $part_id, $part, array('force' => (bool) $force, 'user_id' => Hamnoor_Mobile_Access::actor_id()));
        $now = current_time('mysql');
        // The state transition itself is the idempotency/concurrency gate.
        // A second simultaneous completion request must not re-emit business
        // events after another request has already changed claimed -> done.
        $updated = $wpdb->update(
            self::table('parts'),
            array('status'=>'done','done_at'=>$now,'updated_at'=>$now),
            array('id'=>(int)$part_id,'status'=>'claimed'),
            array('%s','%s','%s'),
            array('%d','%s')
        );
        if (false === $updated) { return new WP_Error('hamnoor_complete_failed', __('ثبت انجام قرائت انجام نشد.', 'hamnoor')); }
        if (0 === (int)$updated) {
            $current = self::get_part((int)$part_id);
            if ($current && 'done' === (string)$current->status) { return true; }
            return new WP_Error('hamnoor_complete_conflict', __('وضعیت سهم هم‌زمان تغییر کرده است؛ لطفاً وضعیت جدید را بررسی کنید.', 'hamnoor'));
        }
        Hamnoor_Claim_Policy::record_event(
            'claim_completed',
            (int)$part->khatm_id,
            (int)$part_id,
            (int)$part->participant_id,
            !empty($part->participant_user_id) ? (int)$part->participant_user_id : Hamnoor_Mobile_Access::actor_id(),
            0,
            hash('sha256', 'complete|' . (int)$part_id . '|' . (string)$part->claimed_at),
            array('force'=>(bool)$force)
        );
        self::log((int) $part->khatm_id, (int) $part_id, (int) $part->participant_id, Hamnoor_Mobile_Access::actor_id(), 'part_done', __('قرائت سهم انجام شد.', 'hamnoor'));
        if (!empty($part->participant_user_id)) { self::add_notification((int) $part->participant_user_id, 'part_done', __('قرائت سهم ثبت شد', 'hamnoor'), __('وضعیت سهم شما به خوانده‌شده تغییر کرد.', 'hamnoor'), 'part', (int) $part_id); }
        Hamnoor_Domain_Events::quran_part_completed((int)$part_id, (int)Hamnoor_Mobile_Access::actor_id());
        do_action('hamnoor_part_completed', (int) $part_id, (int) $part->participant_id, (int) $part->khatm_id);
        do_action('hamnoor_after_complete_part', (int) $part_id, (int) $part->participant_id, (int) $part->khatm_id, array('force' => (bool) $force, 'user_id' => Hamnoor_Mobile_Access::actor_id()));
        self::maybe_mark_completed((int) $part->khatm_id);
        return true;
    }

    public static function maybe_mark_completed($khatm_id) {
        global $wpdb;
        $progress = self::get_progress($khatm_id);
        if ($progress['total'] > 0 && $progress['done'] >= $progress['total']) {
            $now = current_time('mysql');
            $updated = $wpdb->query($wpdb->prepare("UPDATE " . self::table('khatms') . " SET status = 'completed', lifecycle_status = 'completed', review_status = 'approved', completed_at = %s, updated_at = %s WHERE id = %d AND status <> 'completed'", $now, $now, (int) $khatm_id));
            if (!$updated) { return; }
            self::log($khatm_id, 0, 0, 0, 'khatm_completed', __('همه سهم‌های پویش خوانده شد.', 'hamnoor'));
            $completed_khatm = self::get_khatm((int)$khatm_id);
            if ($completed_khatm && !empty($completed_khatm->owner_user_id)) {
                self::add_notification((int)$completed_khatm->owner_user_id, 'khatm_completed', __('پویش با موفقیت تکمیل شد', 'hamnoor'), __('همه سهم‌های این پویش خوانده‌شده ثبت شدند. گزارش نهایی در داشبورد شما در دسترس است.', 'hamnoor'), 'khatm', (int)$khatm_id);
            }
            do_action('hamnoor_khatm_completed', (int) $khatm_id);
            do_action('hamnoor_after_khatm_completed', (int) $khatm_id, $completed_khatm);
        }
    }

    public static function get_parts_by_contact($contact, $khatm_id = 0, $limit = 200) {
        global $wpdb;
        $phone = Hamnoor_Validator::normalize_phone($contact);
        $parts = self::table('parts');
        $participants = self::table('participants');
        $khatms = self::table('khatms');
        $where = array();
        $params = array();
        if ($phone) { $where[] = 'r.normalized_phone = %s'; $params[] = $phone; }
        if (!$where) { return array(); }
        $sql = "SELECT p.*, r.name AS participant_name, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token, k.title AS khatm_title, k.token AS khatm_token FROM $parts p INNER JOIN $participants r ON r.id = p.participant_id INNER JOIN $khatms k ON k.id = p.khatm_id WHERE (" . implode(' OR ', $where) . ")";
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
        return $wpdb->get_results($wpdb->prepare("SELECT p.*, r.name AS participant_name, r.phone AS participant_phone, r.normalized_phone AS participant_normalized_phone, r.user_id AS participant_user_id, r.tracking_token, k.title AS khatm_title, k.token AS khatm_token FROM $parts p INNER JOIN $participants r ON r.id = p.participant_id INNER JOIN $khatms k ON k.id = p.khatm_id WHERE r.user_id = %d ORDER BY p.id DESC LIMIT %d OFFSET %d", (int) $user_id, (int) $limit, (int) $offset));
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
        $phone = Hamnoor_Validator::normalize_phone($contact);
        return $phone && ((string) $part->participant_normalized_phone === $phone || Hamnoor_Validator::normalize_phone((string) $part->participant_phone) === $phone);
    }

    public static function complete_part_by_contact($part_id, $contact) {
        $part = self::get_part($part_id);
        if (!$part) { return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor')); }
        if (!self::contact_matches_part($part, $contact)) { return new WP_Error('hamnoor_contact_mismatch', __('این سهم با شماره موبایل واردشده ثبت نشده است.', 'hamnoor')); }
        // در نسخه کاربردی هم‌نور، پیگیری با موبایل ساده است و OTP در مسیر اصلی اجباری نیست.
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
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::actor_id();
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
        Hamnoor_Claim_Policy::record_event(
            'claim_released',
            (int)$part->khatm_id,
            (int)$part_id,
            $old_participant,
            !empty($part->participant_user_id) ? (int)$part->participant_user_id : $user_id,
            0,
            hash('sha256', 'release|' . (int)$part_id . '|' . (string)$part->claimed_at),
            array('reason'=>sanitize_key((string)$reason),'actor_user_id'=>(int)$user_id)
        );
        if ($old_participant) { self::delete_orphan_participant($old_participant); }
        self::log((int) $part->khatm_id, (int) $part_id, $old_participant, $user_id, 'part_released', __('سهم آزاد شد.', 'hamnoor'));
        if (!empty($part->participant_user_id) && 0 !== strpos((string)$reason, 'owner_bulk_')) {
            $release_message = __('این سهم دیگر به نام شما رزرو نیست و دیگران می‌توانند آن را انتخاب کنند.', 'hamnoor');
            if (0 === strpos((string)$reason, 'auto')) {
                $release_message = __('مهلت این سهم به پایان رسید و سهم به‌صورت خودکار آزاد شد. این سهم دیگر به نام شما رزرو نیست و دیگران می‌توانند آن را انتخاب کنند.', 'hamnoor');
            } elseif ($can_manage && !$participant_user && !$participant_contact && !$participant_token) {
                $release_message = __('سازنده یا مدیر پویش این سهم را آزاد کرد. این سهم دیگر به نام شما رزرو نیست و دیگران می‌توانند آن را انتخاب کنند.', 'hamnoor');
            } elseif ($participant_user || $participant_contact || $participant_token) {
                $release_message = __('این سهم به درخواست شما آزاد شد و اکنون دیگران می‌توانند آن را انتخاب کنند.', 'hamnoor');
            }
            self::add_notification((int) $part->participant_user_id, 'part_released', __('سهم آزاد شد', 'hamnoor'), $release_message, 'part', (int) $part_id);
        }
        do_action('hamnoor_part_released', (int) $part_id, (int) $part->khatm_id, $old_participant, $reason);
        do_action('hamnoor_after_release_part', (int) $part_id, (int) $part->khatm_id, $old_participant, $reason, $user_id);
        return true;
    }

    /**
     * Move active campaigns whose public deadline has passed to an explicit
     * expired state. This closes new claims without stranding claimed shares.
     */
    public static function expire_due_khatms() {
        global $wpdb;
        $table = self::table('khatms');
        $now = current_time('mysql');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at <> '0000-00-00 00:00:00' AND ends_at < %s ORDER BY ends_at ASC LIMIT 500",
            $now
        ));
        $count = 0;
        foreach ((array)$ids as $id) {
            $result = self::change_status((int)$id, 'expired');
            if (!is_wp_error($result)) { $count++; }
        }
        return $count;
    }

    /**
     * Send at most one release warning per claim cycle.
     *
     * Global administrator policy:
     * - smart: warn during the final quarter of the claim lifetime, capped at
     *   24 hours before release (1d=>6h, 2d=>12h, 3d=>18h, 4d+=>24h).
     * - fixed: warn a configured number of hours before release.
     * - disabled: create no release warnings.
     *
     * Automatic release warnings are meaningful only while the campaign still
     * accepts new claims. If acceptance ends before the calculated release
     * time, neither a release warning nor an automatic release is generated.
     */
    public static function send_due_release_warnings() {
        global $wpdb;
        $settings = Hamnoor_Helpers::settings();
        $mode = isset($settings['release_warning_mode']) ? sanitize_key((string)$settings['release_warning_mode']) : 'smart';
        if (!in_array($mode, array('smart','fixed','disabled'), true)) { $mode = 'smart'; }
        if ('disabled' === $mode) {
            update_option('hamnoor_release_warning_last_run', array(
                'at' => current_time('mysql'),
                'mode' => 'disabled',
                'created' => 0,
            ), false);
            return 0;
        }

        $fixed_hours = max(1, min(720, (int)(isset($settings['release_warning_fixed_hours']) ? $settings['release_warning_fixed_hours'] : 24)));
        $batch_limit = max(1, min(500, (int)(isset($settings['release_warning_batch_limit']) ? $settings['release_warning_batch_limit'] : 100)));
        $parts = self::table('parts');
        $khatms = self::table('khatms');
        $participants = self::table('participants');
        $logs = self::table('logs');
        $now = current_time('mysql');

        // The timing condition is kept inside SQL so a large number of claims
        // that are not due yet cannot crowd genuinely due warnings out of the
        // batch limit.
        if ('fixed' === $mode) {
            $timing_sql = "TIMESTAMPDIFF(SECOND, %s, DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY)) <= %d";
            $timing_args = array($now, $fixed_hours * HOUR_IN_SECONDS);
        } else {
            $timing_sql = "TIMESTAMPDIFF(SECOND, %s, DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY)) <= LEAST(86400, (k.auto_release_after_days * 21600))";
            $timing_args = array($now);
        }

        $sql = "SELECT p.id, p.khatm_id, p.participant_id, p.claimed_at, r.user_id AS participant_user_id, k.title, k.auto_release_after_days,
                       DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY) AS release_due_at
                FROM $parts p
                INNER JOIN $khatms k ON k.id = p.khatm_id
                LEFT JOIN $participants r ON r.id = p.participant_id
                WHERE p.status = 'claimed'
                  AND r.user_id IS NOT NULL AND r.user_id > 0
                  AND k.completion_mode = 'manual_done'
                  AND k.status = 'active'
                  AND k.auto_release_mode = 'after_days'
                  AND k.auto_release_after_days > 0
                  AND p.claimed_at IS NOT NULL
                  AND TIMESTAMPDIFF(SECOND, %s, DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY)) > 0
                  AND $timing_sql
                  AND (k.ends_at IS NULL OR k.ends_at = '0000-00-00 00:00:00' OR DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY) < k.ends_at)
                  AND NOT EXISTS (
                       SELECT 1 FROM $logs l
                       WHERE l.part_id = p.id AND l.action = 'part_release_warning'
                         AND l.created_at >= p.claimed_at
                  )
                ORDER BY release_due_at ASC, p.id ASC
                LIMIT %d";
        $args = array_merge(array($now), $timing_args, array($batch_limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

        $count = 0;
        foreach ((array)$rows as $row) {
            $when = !empty($row->release_due_at) ? Hamnoor_Jalali::to_jalali($row->release_due_at, true) : '';
            $message = $when
                ? Hamnoor_Texts::get('part_release_warning_message', array('DATE' => $when))
                : Hamnoor_Texts::get('part_release_warning_fallback');
            $ok = self::add_notification((int)$row->participant_user_id, 'part_release_warning', Hamnoor_Texts::get('part_release_warning_title'), $message, 'part', (int)$row->id);
            if ($ok) {
                self::log((int)$row->khatm_id, (int)$row->id, (int)$row->participant_id, (int)$row->participant_user_id, 'part_release_warning', __('هشدار پیش از آزادسازی خودکار سهم ارسال شد.', 'hamnoor'));
                $count++;
            }
        }
        update_option('hamnoor_release_warning_last_run', array(
            'at' => current_time('mysql'),
            'mode' => $mode,
            'fixed_hours' => $fixed_hours,
            'batch_limit' => $batch_limit,
            'created' => $count,
        ), false);
        return $count;
    }

    public static function release_due_parts() {
        // One lifecycle worker handles campaign acceptance deadlines, warnings,
        // and per-claim automatic release. Automatic release is meaningful only
        // while new participation is still open.
        self::expire_due_khatms();
        self::send_due_release_warnings();
        global $wpdb;
        $parts = self::table('parts');
        $khatms = self::table('khatms');
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id
             FROM $parts p
             INNER JOIN $khatms k ON k.id = p.khatm_id
             WHERE p.status = 'claimed'
               AND (p.done_at IS NULL OR p.done_at = '0000-00-00 00:00:00')
               AND k.completion_mode = 'manual_done'
               AND k.status = 'active'
               AND k.auto_release_mode = 'after_days'
               AND k.auto_release_after_days > 0
               AND p.claimed_at IS NOT NULL
               AND DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY) <= %s
               AND (k.ends_at IS NULL OR k.ends_at = '0000-00-00 00:00:00' OR (
                    %s < k.ends_at AND DATE_ADD(p.claimed_at, INTERVAL k.auto_release_after_days DAY) < k.ends_at
               ))
             ORDER BY p.claimed_at ASC, p.id ASC
             LIMIT 500",
            $now,
            $now
        ));
        $count = 0;
        foreach ((array)$rows as $row) {
            $result = self::release_part((int) $row->id, 'auto_after_days', 0);
            if (!is_wp_error($result)) { $count++; }
        }
        return $count;
    }

    public static function sync_guest_history_to_user($user_id) {
        global $wpdb;
        $user = Hamnoor_Mobile_Access::get_user((int) $user_id);
        if (!$user || empty($user->phone)) { return 0; }
        $participants = self::table('participants');
        $linked = (int) $wpdb->query($wpdb->prepare(
            "UPDATE $participants SET user_id = %d, updated_at = %s WHERE (user_id IS NULL OR user_id = 0) AND normalized_phone = %s",
            (int) $user_id,
            current_time('mysql'),
            (string) $user->phone
        ));
        if ($linked > 0) {
            do_action('hamnoor_after_participant_linked_to_user', 0, (int) $user_id, array('count'=>$linked,'source'=>'mobile_profile_sync'));
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
            $where .= ' AND (r.name LIKE %s OR r.phone LIKE %s)';
            array_push($params, $like, $like);
        }
        $settings = Hamnoor_Helpers::settings();
        $window_minutes = max(1, min(120, (int)($settings['claim_abuse_window_minutes'] ?? 10)));
        $recent_since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * MINUTE_IN_SECONDS), wp_timezone());
        $logs = self::table('logs');
        $sql = "SELECT r.*, r.id AS participant_id, COUNT(p.id) AS total_parts, SUM(p.status='claimed') AS claimed_parts, SUM(p.status='done') AS done_parts, MAX(p.claimed_at) AS last_claimed, (SELECT COUNT(*) FROM $logs l WHERE l.participant_id=r.id AND l.action IN ('part_claimed','part_claimed_done') AND l.created_at >= %s) AS recent_claims FROM $participants r LEFT JOIN $parts p ON p.participant_id = r.id $where GROUP BY r.id ORDER BY r.id DESC LIMIT %d OFFSET %d";
        array_unshift($params, $recent_since);
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
            $where .= ' AND (name LIKE %s OR phone LIKE %s)';
            array_push($params, $like, $like);
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

    public static function get_participant($participant_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('participants') . " WHERE id=%d", (int)$participant_id));
    }

    public static function set_participant_claim_blocked($participant_id, $blocked, $actor_user_id = 0, $reason = '') {
        global $wpdb;
        $participant = self::get_participant((int)$participant_id);
        if (!$participant) { return new WP_Error('hamnoor_participant_not_found', __('شرکت‌کننده پیدا نشد.', 'hamnoor')); }
        $actor_user_id = $actor_user_id ? (int)$actor_user_id : Hamnoor_Mobile_Access::actor_id();
        if (!self::user_can_manage_khatm((int)$participant->khatm_id, $actor_user_id) && !current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) {
            return new WP_Error('hamnoor_participant_manage_denied', __('اجازه مدیریت این شرکت‌کننده را ندارید.', 'hamnoor'));
        }
        $blocked = $blocked ? 1 : 0;
        $now = current_time('mysql');
        $ok = $wpdb->update(self::table('participants'), array(
            'claim_blocked'=>$blocked,
            'claim_block_reason'=>$blocked ? sanitize_textarea_field($reason) : null,
            'claim_blocked_at'=>$blocked ? $now : null,
            'claim_blocked_by'=>$blocked ? ($actor_user_id ?: null) : null,
            'updated_at'=>$now,
        ), array('id'=>(int)$participant_id));
        if (false === $ok) { return new WP_Error('hamnoor_participant_block_failed', __('تغییر وضعیت انتخاب سهم این شرکت‌کننده انجام نشد.', 'hamnoor')); }
        self::log((int)$participant->khatm_id, 0, (int)$participant_id, $actor_user_id, $blocked ? 'participant_claim_blocked' : 'participant_claim_unblocked', $blocked ? __('انتخاب سهم جدید برای این شرکت‌کننده در پویش متوقف شد.', 'hamnoor') : __('امکان انتخاب سهم جدید برای این شرکت‌کننده در پویش باز شد.', 'hamnoor'));
        if (!$blocked) { self::delete_orphan_participant((int)$participant_id); }
        return true;
    }

    public static function set_user_claim_blocked($user_id, $blocked, $actor_user_id = 0, $reason = '') {
        global $wpdb;
        if (!current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) { return new WP_Error('hamnoor_global_block_denied', __('فقط مدیر سایت می‌تواند محدودیت سراسری انتخاب سهم را تغییر دهد.', 'hamnoor')); }
        $user = Hamnoor_Mobile_Access::get_user((int)$user_id);
        if (!$user) { return new WP_Error('hamnoor_user_not_found', __('کاربر هم‌نور پیدا نشد.', 'hamnoor')); }
        $blocked = $blocked ? 1 : 0;
        $now = current_time('mysql');
        $ok = $wpdb->update(self::table('users'), array(
            'claim_blocked'=>$blocked,
            'claim_block_reason'=>$blocked ? sanitize_textarea_field($reason) : null,
            'claim_blocked_at'=>$blocked ? $now : null,
            'claim_blocked_by'=>$blocked ? ((int)$actor_user_id ?: null) : null,
            'updated_at'=>$now,
        ), array('id'=>(int)$user_id));
        if (false === $ok) { return new WP_Error('hamnoor_global_block_failed', __('تغییر محدودیت سراسری کاربر انجام نشد.', 'hamnoor')); }
        $log_actor_id = (int) $actor_user_id;
        $target_label = !empty($user->display_name) ? sanitize_text_field($user->display_name) : sprintf(__('کاربر هم‌نور #%d', 'hamnoor'), (int)$user_id);
        self::log(0, 0, 0, $log_actor_id, $blocked ? 'user_claim_blocked' : 'user_claim_unblocked', $blocked ? sprintf(__('انتخاب سهم جدید برای %s در کل هم‌نور متوقف شد.', 'hamnoor'), $target_label) : sprintf(__('محدودیت سراسری انتخاب سهم %s برداشته شد.', 'hamnoor'), $target_label));
        return true;
    }

    public static function release_participant_open_parts($participant_id, $actor_user_id = 0) {
        global $wpdb;
        $participant = self::get_participant((int)$participant_id);
        if (!$participant) { return new WP_Error('hamnoor_participant_not_found', __('شرکت‌کننده پیدا نشد.', 'hamnoor')); }
        $actor_user_id = $actor_user_id ? (int)$actor_user_id : Hamnoor_Mobile_Access::actor_id();
        if (!self::user_can_manage_khatm((int)$participant->khatm_id, $actor_user_id) && !current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) {
            return new WP_Error('hamnoor_participant_manage_denied', __('اجازه مدیریت این شرکت‌کننده را ندارید.', 'hamnoor'));
        }
        $part_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table('parts') . " WHERE participant_id=%d AND khatm_id=%d AND status='claimed' ORDER BY id ASC", (int)$participant_id, (int)$participant->khatm_id));
        $released = 0;
        foreach ((array)$part_ids as $part_id) {
            $result = self::release_part((int)$part_id, 'owner_bulk_manage', $actor_user_id);
            if (!is_wp_error($result)) { $released++; }
        }
        if ($released && !empty($participant->user_id)) {
            self::add_notification((int)$participant->user_id, 'part_released', __('سهم‌های ناتمام آزاد شدند', 'hamnoor'), sprintf(__('%d سهم ناتمام شما در این پویش توسط سازنده یا مدیر آزاد شد.', 'hamnoor'), $released), 'khatm', (int)$participant->khatm_id);
        }
        self::log((int)$participant->khatm_id, 0, (int)$participant_id, $actor_user_id, 'participant_open_parts_released', sprintf(__('%d سهم ناتمام شرکت‌کننده به‌صورت گروهی آزاد شد.', 'hamnoor'), $released));
        return $released;
    }

    private static function khatm_participant_user_ids($khatm_id) {
        global $wpdb;
        $participants = self::table('participants');
        $participant_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM $participants WHERE khatm_id = %d AND user_id IS NOT NULL AND user_id > 0",
            (int) $khatm_id
        )));
        $contributor_ids = class_exists('Hamnoor_Contribution_Service') ? Hamnoor_Contribution_Service::campaign_user_ids((int)$khatm_id) : array();
        return array_values(array_unique(array_merge($participant_ids, $contributor_ids)));
    }

    private static function notify_operational_khatm_change($before, $after) {
        if (!$before || !$after || !in_array((string) $before->status, array('active','paused','expired'), true)) { return; }
        $watched = array(
            'ends_at' => __('پایان پذیرش سهم جدید', 'hamnoor'),
            'completion_grace_days' => __('مهلت تکمیل سهم‌های قبلی', 'hamnoor'),
            'auto_release_mode' => __('مدت نگهداری سهم', 'hamnoor'),
            'auto_release_after_days' => __('مهلت نگهداری سهم', 'hamnoor'),
            'completion_mode' => __('شیوه ثبت تکمیل سهم', 'hamnoor'),
            'privacy' => __('حریم خصوصی پویش', 'hamnoor'),
        );
        $changed = array();
        foreach ($watched as $field => $label) {
            $old = isset($before->$field) ? (string) $before->$field : '';
            $new = isset($after->$field) ? (string) $after->$field : '';
            if ($old !== $new) { $changed[] = $label; }
        }
        if (!$changed) { return; }
        $message = sprintf(
            __('سازنده پویش «%1$s» این تنظیمات را تغییر داد: %2$s. اگر سهم ناتمامی دارید، جزئیات پویش را دوباره بررسی کنید.', 'hamnoor'),
            (string) $after->title,
            implode('، ', $changed)
        );
        foreach (self::khatm_participant_user_ids((int) $after->id) as $user_id) {
            self::add_notification((int) $user_id, 'khatm_updated', __('تنظیمات پویش تغییر کرد', 'hamnoor'), $message, 'khatm', (int) $after->id);
        }
        self::log((int) $after->id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'khatm_operational_settings_changed', implode('، ', $changed));
    }

    public static function khatm_thanks_sent($khatm_id) {
        return (bool) get_option('hamnoor_khatm_thanks_sent_' . (int) $khatm_id, false);
    }

    public static function send_khatm_thanks($khatm_id) {
        $khatm = self::get_khatm((int) $khatm_id);
        if (!$khatm) { return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        if ('completed' !== (string) $khatm->status) { return new WP_Error('hamnoor_thanks_not_completed', __('پیام تشکر پس از تکمیل پویش قابل ارسال است.', 'hamnoor')); }
        $option_key = 'hamnoor_khatm_thanks_sent_' . (int) $khatm_id;
        if (get_option($option_key, false)) { return new WP_Error('hamnoor_thanks_already_sent', __('پیام تشکر این پویش قبلاً ارسال شده است.', 'hamnoor')); }
        $user_ids = self::khatm_participant_user_ids((int) $khatm_id);
        if (!$user_ids) { return 0; }
        // add_option is atomic enough for this one-time owner action and prevents double-click duplicate sends.
        if (!add_option($option_key, current_time('mysql'), '', false)) {
            return new WP_Error('hamnoor_thanks_already_sent', __('پیام تشکر این پویش قبلاً ارسال شده است.', 'hamnoor'));
        }
        $sent = 0;
        $message = sprintf(__('پویش «%s» با همراهی شما به پایان رسید. از مشارکت ارزشمندتان سپاسگزاریم.', 'hamnoor'), (string) $khatm->title);
        foreach ($user_ids as $user_id) {
            if (self::add_notification((int) $user_id, 'khatm_thanks', __('سپاس از همراهی شما', 'hamnoor'), $message, 'khatm', (int) $khatm_id)) { $sent++; }
        }
        self::log((int) $khatm_id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'khatm_thanks_sent', sprintf(__('پیام تشکر برای %d کاربر ثبت شد.', 'hamnoor'), $sent));
        return $sent;
    }

    public static function get_logs($khatm_id, $limit = 200) {
        global $wpdb;
        $logs = self::table('logs');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $logs WHERE khatm_id = %d ORDER BY id DESC LIMIT %d", (int) $khatm_id, (int) $limit));
    }

    public static function user_can_manage_khatm($khatm_id, $user_id = 0) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { return false; }
        $khatm = self::get_khatm($khatm_id);
        // Ownership is independent from assurance. Low-assurance sessions may
        // view/manage ordinary dashboard data; sensitive mutations must call
        // Hamnoor_Access_Policy separately (campaign_edit/create, identity change).
        return $khatm && (int) $khatm->owner_user_id === (int) $user_id;
    }

    public static function change_status($khatm_id, $status, $review_note = '', $context = 'auto') {
        global $wpdb;
        $status = sanitize_key($status);
        $context = sanitize_key($context);
        if (!in_array($context, array('auto','admin','owner'), true)) { $context = 'auto'; }
        $allowed = array('pending_review','needs_revision','rejected','active','paused','expired','completed','cancelled','archived','failed');
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('hamnoor_invalid_status', __('وضعیت انتخاب‌شده معتبر نیست.', 'hamnoor'));
        }
        $note = sanitize_textarea_field((string) $review_note);
        if (in_array($status, array('needs_revision','rejected'), true) && '' === trim($note)) {
            return new WP_Error('hamnoor_review_note_required', __('برای درخواست اصلاح یا رد پویش، دلیل را بنویسید.', 'hamnoor'));
        }

        // Legacy status mutations serialize on the campaign row. This path still
        // carries review-era status values, so we preserve those semantics while
        // preventing stale concurrent requests from resurrecting terminal states.
        $khatms_table = self::table('khatms');
        $wpdb->query('START TRANSACTION');
        $previous = $wpdb->get_row($wpdb->prepare("SELECT * FROM $khatms_table WHERE id=%d FOR UPDATE", (int)$khatm_id));
        if (!$previous) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_not_found', __('پویش پیدا نشد.', 'hamnoor'));
        }
        $terminal_statuses = array('completed','expired','cancelled','archived');
        if ((string)$previous->status !== $status && in_array((string)$previous->status, $terminal_statuses, true)) {
            $terminal_to_archive = 'archived' === $status && in_array((string)$previous->status, array('completed','expired','cancelled'), true);
            if (!$terminal_to_archive) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_campaign_transition_invalid', __('تغییر وضعیت پویش در این مرحله مجاز نیست.', 'hamnoor'), array('from'=>(string)$previous->status,'to'=>$status));
            }
        }

        $now = current_time('mysql');
        $actor_id = ('admin' === $context && get_current_user_id()) ? (int) get_current_user_id() : (int) Hamnoor_Mobile_Access::actor_id();
        $was_paused = in_array((string) $previous->status, array('paused'), true);
        $previous_pause_source = isset($previous->pause_source) ? sanitize_key((string) $previous->pause_source) : '';
        $is_first_publish = ('active' === $status && in_array((string) $previous->status, array('pending_review','needs_revision','rejected','failed'), true));

        $canonical_status = self::canonical_status_fields($status);
        $fields = array('status'=>$status, 'lifecycle_status'=>$canonical_status['lifecycle_status'], 'review_status'=>$canonical_status['review_status'], 'updated_at'=>$now);
        if ('paused' === $status) {
            $pause_source = 'owner' === $context ? 'owner' : ('admin' === $context ? 'admin' : 'system');
            $fields['pause_source'] = $pause_source;
            $fields['pause_note'] = ('admin' === $pause_source && '' !== trim($note)) ? $note : null;
            $fields['paused_at'] = $now;
            $fields['paused_by_user_id'] = $actor_id ?: null;
        } elseif ($was_paused || !empty($previous_pause_source)) {
            $fields['pause_source'] = null;
            $fields['pause_note'] = null;
            $fields['paused_at'] = null;
            $fields['paused_by_user_id'] = null;
        }
        if ('completed' === $status) { $fields['completed_at'] = $now; }
        if (in_array($status, array('needs_revision','rejected'), true)) {
            $fields['review_note'] = $note;
            $fields['reviewed_at'] = $now;
            $fields['reviewed_by_user_id'] = get_current_user_id() ?: Hamnoor_Mobile_Access::actor_id();
        } elseif ('pending_review' === $status && 'owner' === $context && 'needs_revision' === (string) $previous->status) {
            // A corrected campaign is a new review request. The previous review reason
            // remains in the audit log, while the current review fields are cleared so
            // the dashboard/admin UI does not present an old reason as the active one.
            $fields['review_note'] = null;
            $fields['reviewed_at'] = null;
            $fields['reviewed_by_user_id'] = null;
        }
        if ($is_first_publish) {
            $fields['published_at'] = $now;
            $fields['published_by_user_id'] = get_current_user_id() ?: Hamnoor_Mobile_Access::actor_id();
            $fields['review_note'] = null;
            $fields['reviewed_at'] = $now;
            $fields['reviewed_by_user_id'] = get_current_user_id() ?: Hamnoor_Mobile_Access::actor_id();
        }

        $updated = $wpdb->update($khatms_table, $fields, array('id'=>(int)$khatm_id));
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('hamnoor_status_failed', __('تغییر وضعیت انجام نشد.', 'hamnoor'));
        }
        $wpdb->query('COMMIT');

        $log_action = 'khatm_status_' . $status;
        $log_message = $note ? $note : sprintf(__('وضعیت پویش به %s تغییر کرد.', 'hamnoor'), $status);
        if ('paused' === $status) {
            $log_action = 'khatm_paused_' . ('owner' === $context ? 'owner' : ('admin' === $context ? 'admin' : 'system'));
            $log_message = $note ? $note : ('owner' === $context ? __('پویش توسط سازنده موقتاً متوقف شد.', 'hamnoor') : __('پویش توسط مدیر سایت موقتاً متوقف شد.', 'hamnoor'));
        } elseif ('active' === $status && $was_paused) {
            $log_action = 'khatm_resumed_' . ('owner' === $context ? 'owner' : ('admin' === $context ? 'admin' : 'system'));
            $log_message = 'owner' === $context ? __('پویش توسط سازنده از سر گرفته شد.', 'hamnoor') : __('توقف پویش توسط مدیر سایت برداشته شد.', 'hamnoor');
        } elseif ('cancelled' === $status) {
            $log_action = 'khatm_cancelled_' . ('owner' === $context ? 'owner' : ('admin' === $context ? 'admin' : 'system'));
        } elseif ('pending_review' === $status && 'owner' === $context && 'needs_revision' === (string) $previous->status) {
            $log_action = 'khatm_resubmitted';
            $log_message = __('پویش پس از اصلاح توسط سازنده دوباره برای بررسی مدیر ارسال شد.', 'hamnoor');
        }
        self::log((int)$khatm_id, 0, 0, $actor_id, $log_action, $log_message);

        $status_changed = (string)$previous->status !== $status;
        $pause_control_changed = ('paused' === $status && 'admin' === $context && 'admin' !== $previous_pause_source);
        if ($status_changed || $pause_control_changed) {
            $current = self::get_khatm((int)$khatm_id);
            if ($status_changed && $is_first_publish) { self::after_khatm_published($current); }
            if ($current && !empty($current->owner_user_id)) {
                if ('needs_revision' === $status) {
                    self::add_notification((int)$current->owner_user_id, 'khatm_needs_revision', __('پویش نیاز به اصلاح دارد', 'hamnoor'), $note, 'khatm', (int)$khatm_id);
                } elseif ('rejected' === $status) {
                    self::add_notification((int)$current->owner_user_id, 'khatm_rejected', __('پویش تأیید نشد', 'hamnoor'), $note, 'khatm', (int)$khatm_id);
                } elseif ('paused' === $status && 'admin' === $context) {
                    $message = __('مدیر سایت پذیرش سهم جدید در این پویش را موقتاً متوقف کرده است. سهم‌های قبلی همچنان قابل ادامه و تکمیل هستند.', 'hamnoor');
                    if ($note) { $message .= "\n" . sprintf(__('دلیل: %s', 'hamnoor'), $note); }
                    self::add_notification((int)$current->owner_user_id, 'khatm_updated', __('پویش توسط مدیر موقتاً متوقف شد', 'hamnoor'), $message, 'khatm', (int)$khatm_id);
                } elseif ('active' === $status && $was_paused && 'admin' === $context) {
                    self::add_notification((int)$current->owner_user_id, 'khatm_updated', __('پویش دوباره فعال شد', 'hamnoor'), __('توقف مدیریتی پویش برداشته شد و پذیرش سهم جدید دوباره فعال است.', 'hamnoor'), 'khatm', (int)$khatm_id);
                } elseif ('cancelled' === $status && 'owner' !== $context) {
                    self::add_notification((int)$current->owner_user_id, 'khatm_cancelled', __('پویش لغو شد', 'hamnoor'), __('این پویش لغو شده و سهم جدیدی در آن ثبت نمی‌شود.', 'hamnoor'), 'khatm', (int)$khatm_id);
                } elseif ('expired' === $status) {
                    $message = __('پذیرش سهم جدید در این پویش پایان یافته است. صاحبان سهم‌های قبلی همچنان می‌توانند سهم خود را تکمیل کنند.', 'hamnoor');
                    if (isset($current->completion_grace_days) && null !== $current->completion_grace_days && '' !== (string)$current->completion_grace_days) {
                        $message = sprintf(__('پذیرش سهم جدید در این پویش پایان یافته است. صاحبان سهم‌های قبلی %d روز برای تکمیل سهم خود فرصت دارند.', 'hamnoor'), (int)$current->completion_grace_days);
                    }
                    self::add_notification((int)$current->owner_user_id, 'khatm_expired', __('پذیرش سهم جدید پایان یافت', 'hamnoor'), $message, 'khatm', (int)$khatm_id);
                }
            }
            if ($status_changed && 'cancelled' === $status && $current) {
                $cancel_message = sprintf(__('پویش «%s» لغو شده است. سهم‌های ناتمام این پویش دیگر قابل تکمیل نیستند.', 'hamnoor'), (string)$current->title);
                foreach (self::khatm_participant_user_ids((int)$khatm_id) as $participant_user_id) {
                    if (!empty($current->owner_user_id) && (int)$participant_user_id === (int)$current->owner_user_id) { continue; }
                    self::add_notification((int)$participant_user_id, 'khatm_updated', __('پویش لغو شد', 'hamnoor'), $cancel_message, 'khatm', (int)$khatm_id);
                }
            }
            if ($status_changed && in_array($status, array('expired','completed'), true) && $current) {
                $type = 'completed' === $status ? 'khatm_completed' : 'khatm_expired';
                $title = 'completed' === $status ? __('پویش تکمیل شد', 'hamnoor') : __('زمان مشارکت در پویش پایان یافت', 'hamnoor');
                $message = 'completed' === $status ? sprintf(__('پویش «%s» با همراهی شما تکمیل شد.', 'hamnoor'), (string)$current->title) : sprintf(__('زمان ثبت مشارکت جدید در پویش «%s» پایان یافته است.', 'hamnoor'), (string)$current->title);
                foreach (self::khatm_participant_user_ids((int)$khatm_id) as $participant_user_id) {
                    if (!empty($current->owner_user_id) && (int)$participant_user_id === (int)$current->owner_user_id) { continue; }
                    self::add_notification((int)$participant_user_id, $type, $title, $message, 'khatm', (int)$khatm_id);
                }
            }
            if ($status_changed && 'completed' === $status) { do_action('hamnoor_after_khatm_completed', (int)$khatm_id, $current); }
            if ($status_changed && 'archived' === $status) { do_action('hamnoor_after_khatm_archived', (int)$khatm_id, $current); }
        }
        return true;
    }

    private static function after_khatm_published($khatm) {
        if (!$khatm) { return; }
        $url = Hamnoor_Helpers::invite_url($khatm);
        self::log((int) $khatm->id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'khatm_published', __('پویش توسط مدیر سایت منتشر شد.', 'hamnoor'));
        if (!empty($khatm->owner_user_id)) { self::add_notification((int) $khatm->owner_user_id, 'khatm_published', __('پویش شما منتشر شد', 'hamnoor'), __('مدیر سایت پویش شما را تأیید و منتشر کرد. لینک دعوت اکنون فعال است.', 'hamnoor'), 'khatm', (int) $khatm->id); }
        do_action('hamnoor_khatm_published', (int) $khatm->id, $khatm);
        do_action('hamnoor_after_invitation_created', (int) $khatm->id, $url, $khatm);
    }

    public static function normalize_contact($contact) {
        return Hamnoor_Validator::normalize_phone($contact);
    }

    /**
     * سازگاری داخلی برای بخش‌های قدیمی افزونه؛ تأیید واقعی ایجاد پویش توسط
     * Hamnoor_Mobile_Access و ارائه‌دهنده OTP انجام می‌شود.
     */
    public static function create_otp($contact, $purpose = 'track') {
        $phone = self::normalize_contact($contact);
        if (!Hamnoor_Validator::valid_iran_mobile($phone)) {
            return new WP_Error('hamnoor_otp_contact_invalid', __('شماره موبایل معتبر نیست.', 'hamnoor'));
        }
        return new WP_Error('hamnoor_otp_use_mobile_access', __('برای تأیید موبایل از بخش تأیید داخل داشبورد استفاده کنید.', 'hamnoor'));
    }

    public static function verify_otp($contact, $code, $purpose = 'track', $user_id = 0) {
        $phone = self::normalize_contact($contact);
        $user = $user_id ? Hamnoor_Mobile_Access::get_user((int) $user_id) : Hamnoor_Mobile_Access::current_user();
        return $user && $phone === (string) $user->phone && !empty($user->mobile_verified);
    }

    public static function contact_cookie_name($normalized, $purpose = 'track') {
        return 'hamnoor_' . sanitize_key($purpose) . '_' . substr(md5((string) $normalized), 0, 16);
    }

    public static function mark_contact_verified($contact, $purpose = 'track', $user_id = 0) {
        $phone = self::normalize_contact($contact);
        $user = $user_id ? Hamnoor_Mobile_Access::get_user((int) $user_id) : Hamnoor_Mobile_Access::current_user();
        return (bool) ($user && $phone === (string) $user->phone && !empty($user->mobile_verified));
    }

    public static function is_contact_verified($contact, $purpose = 'track') {
        if (current_user_can('manage_options')) { return true; }
        $phone = self::normalize_contact($contact);
        $user = Hamnoor_Mobile_Access::current_user();
        return (bool) ($user && $phone === (string) $user->phone && !empty($user->mobile_verified));
    }

    public static function get_user_verified_contacts($user_id) {
        $user = Hamnoor_Mobile_Access::get_user((int) $user_id);
        return ($user && !empty($user->mobile_verified) && !empty($user->phone)) ? array((string) $user->phone) : array();
    }

    public static function generate_public_id($prefix = 'HNO') {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$prefix));
        if (!$prefix) { $prefix = 'HNO'; }
        try { $random = bin2hex(random_bytes(10)); }
        catch (Throwable $e) { $random = strtolower(wp_generate_password(20, false, false)); }
        return strtoupper(substr($prefix, 0, 4) . '-' . substr(preg_replace('/[^a-z0-9]/i', '', $random), 0, 20));
    }

    public static function generate_unique_token() {
        global $wpdb;
        $table = self::table('khatms');
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $length = strlen($alphabet);
        for ($try = 0; $try < 200; $try++) {
            $token = '';
            for ($i = 0; $i < 5; $i++) {
                try { $index = random_int(0, $length - 1); }
                catch (Throwable $e) { $index = wp_rand(0, $length - 1); }
                $token .= $alphabet[$index];
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE token = %s", $token));
            if (!$exists && self::root_token_available($token)) { return $token; }
        }
        return new WP_Error('hamnoor_token_failed', __('ساخت کد پنج‌کاراکتری یکتا انجام نشد؛ دوباره تلاش کنید.', 'hamnoor'));
    }

    private static function root_token_available($token) {
        global $wpdb;
        $token = Hamnoor_Helpers::sanitize_token($token);
        $reserved = array('admin','login','index','track','users','media','about','panel','quran');
        if (!Hamnoor_Helpers::is_public_token($token) || in_array($token, $reserved, true)) { return false; }
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
            $token
        ));
        return !$post_id;
    }

    public static function migrate_root_tokens() {
        global $wpdb;
        $table = self::table('khatms');
        if (!$table || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return; }
        $rows = $wpdb->get_results("SELECT id, token FROM $table ORDER BY id ASC");
        foreach ((array) $rows as $row) {
            if (Hamnoor_Helpers::is_public_token((string) $row->token)) { continue; }
            $token = self::generate_unique_token();
            if (!is_wp_error($token)) { $wpdb->update($table, array('token'=>$token), array('id'=>(int)$row->id), array('%s'), array('%d')); }
        }
    }

    public static function generate_token($length = 16) {
        $token = strtolower(wp_generate_password((int) $length, false, false));
        return preg_replace('/[^a-z0-9]/', '', $token);
    }


    public static function important_notification_types() {
        return apply_filters('hamnoor_important_notification_types', array(
            'mobile_changed',
            'mobile_verified',
            'khatm_created',
            'khatm_published',
            'khatm_rejected',
            'khatm_needs_revision',
            'khatm_cancelled',
            'khatm_expired',
            'khatm_completed',
            'khatm_updated',
            'khatm_thanks',
            'part_claimed',
            'part_done',
            'part_released',
            'part_release_warning',
            'security_notice',
            'part_reminder',
            'personal_quran_reminder',
            'dua_selected',
            'dua_completed',
            'dhikr_contributed',
            'dua_reminder',
        ));
    }

    public static function add_notification($user_id, $type, $title, $message = '', $related_type = '', $related_id = 0, $dispatch = true) {
        global $wpdb;
        $user_id = (int) $user_id;
        $type = sanitize_key($type);
        if (!$user_id || !in_array($type, self::important_notification_types(), true)) { return false; }
        $ok = $wpdb->insert(self::table('notifications'), array(
            'user_id' => $user_id,
            'type' => $type,
            'title' => Hamnoor_Validator::trim_len(sanitize_text_field($title), 191),
            'message' => sanitize_textarea_field($message),
            'related_type' => $related_type ? sanitize_key($related_type) : null,
            'related_id' => $related_id ? (int) $related_id : null,
            'created_at' => current_time('mysql'),
        ), array('%d','%s','%s','%s','%s','%d','%s'));
        if (!$ok) { return false; }
        $notification_id = (int)$wpdb->insert_id;
        if ($dispatch) { do_action('hamnoor_notification_created', $notification_id, $user_id); }
        // Returning the ID remains backwards-compatible with boolean callers and
        // lets reminder/reporting code inspect the synchronous delivery result.
        return $notification_id;
    }

    private static function notification_type_sql() {
        $types = array_values(array_filter(array_map('sanitize_key', self::important_notification_types())));
        if (!$types) { return array("''", array()); }
        return array(implode(',', array_fill(0, count($types), '%s')), $types);
    }

    private static function prepare_query($query, $args) {
        global $wpdb;
        $args = is_array($args) ? array_values($args) : array($args);
        return call_user_func_array(array($wpdb, 'prepare'), array_merge(array($query), $args));
    }

    public static function get_notifications($user_id, $limit = 100) {
        global $wpdb;
        list($placeholders, $types) = self::notification_type_sql();
        $args = array_merge(array((int) $user_id), $types, array(max(1, min(500, (int) $limit))));
        return $wpdb->get_results(self::prepare_query(
            'SELECT * FROM ' . self::table('notifications') . " WHERE user_id = %d AND type IN ($placeholders) ORDER BY id DESC LIMIT %d",
            $args
        ));
    }

    public static function count_unread_notifications($user_id) {
        global $wpdb;
        list($placeholders, $types) = self::notification_type_sql();
        $args = array_merge(array((int) $user_id), $types);
        return (int) $wpdb->get_var(self::prepare_query(
            'SELECT COUNT(*) FROM ' . self::table('notifications') . " WHERE user_id = %d AND read_at IS NULL AND type IN ($placeholders)",
            $args
        ));
    }

    public static function mark_notifications_read($user_id) {
        global $wpdb;
        list($placeholders, $types) = self::notification_type_sql();
        $args = array_merge(array(current_time('mysql'), (int) $user_id), $types);
        return $wpdb->query(self::prepare_query(
            'UPDATE ' . self::table('notifications') . " SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND type IN ($placeholders)",
            $args
        ));
    }

    public static function required_tables() {
        $names = class_exists('Hamnoor_Schema_Manifest') ? Hamnoor_Schema_Manifest::table_names() : array();
        return array_values(array_filter(array_map(array(__CLASS__, 'table'), $names)));
    }

    public static function check_tables_health() {
        global $wpdb;
        $missing = array();
        $columns_missing = array();
        $indexes_missing = array();
        $manifest = class_exists('Hamnoor_Schema_Manifest') ? Hamnoor_Schema_Manifest::tables() : array();
        foreach ($manifest as $logical_name => $contract) {
            $table = self::table($logical_name);
            if (!$table) { continue; }
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ((string)$exists !== (string)$table) {
                $missing[] = $table;
                continue;
            }
            $cols = $wpdb->get_col("DESC `$table`", 0);
            foreach ((array)($contract['columns'] ?? array()) as $col) {
                if (!in_array($col, (array)$cols, true)) { $columns_missing[] = $table . '.' . $col; }
            }
            if (!empty($contract['indexes'])) {
                $index_rows = $wpdb->get_results("SHOW INDEX FROM `$table`");
                $index_names = array();
                foreach ((array)$index_rows as $index_row) { $index_names[(string)$index_row->Key_name] = true; }
                foreach ((array)$contract['indexes'] as $index_name) {
                    if (empty($index_names[$index_name])) { $indexes_missing[] = $table . '.' . $index_name; }
                }
            }
        }
        $pages = get_option('hamnoor_pages', array());
        $settings = Hamnoor_Helpers::settings();
        return array(
            'ok' => empty($missing) && empty($columns_missing) && empty($indexes_missing),
            'missing' => $missing,
            'columns_missing' => $columns_missing,
            'indexes_missing' => $indexes_missing,
            'schema_contract_version' => defined('HAMNOOR_DB_VERSION') ? HAMNOOR_DB_VERSION : '',
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
        if (!class_exists('Hamnoor_Retention_Service')) { return array(); }
        return Hamnoor_Retention_Service::run_safe_housekeeping(250);
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
