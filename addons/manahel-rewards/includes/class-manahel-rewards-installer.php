<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Installer {
    public static function activate() {
        $schema_ok = self::install_schema();
        self::grant_capabilities();
        self::ensure_policy_timezone();
        if ($schema_ok) {
            self::seed_rule_templates();
            self::apply_growth_first_defaults();
            update_option('manahel_rewards_db_version', MANAHEL_REWARDS_DB_VERSION, false);
        }
        update_option('manahel_rewards_version', MANAHEL_REWARDS_VERSION, false);
    }

    public static function maybe_upgrade() {
        $current = (string)get_option('manahel_rewards_db_version', '');
        $version_changed = MANAHEL_REWARDS_VERSION !== (string)get_option('manahel_rewards_version', '');
        if (MANAHEL_REWARDS_DB_VERSION !== $current) {
            $schema_ok = self::install_schema();
            self::grant_capabilities();
            self::ensure_policy_timezone();
            if ($schema_ok) {
                self::seed_rule_templates();
                self::apply_growth_first_defaults();
                update_option('manahel_rewards_db_version', MANAHEL_REWARDS_DB_VERSION, false);
            }
        } else {
            self::ensure_policy_timezone();
            if ($version_changed) {
                // Version-only upgrades may add disabled templates without a DB migration.
                self::seed_rule_templates();
                self::apply_growth_first_defaults();
            }
        }
        if ($version_changed) {
            update_option('manahel_rewards_version', MANAHEL_REWARDS_VERSION, false);
        }
    }

    private static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $accounts = Manahel_Rewards_DB::table('accounts');
        $events = Manahel_Rewards_DB::table('events');
        $rules = Manahel_Rewards_DB::table('rules');
        $ledger = Manahel_Rewards_DB::table('ledger');
        $catalog = Manahel_Rewards_DB::table('catalog');
        $redemptions = Manahel_Rewards_DB::table('redemptions');
        $codes = Manahel_Rewards_DB::table('codes');

        dbDelta("CREATE TABLE {$accounts} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT(20) UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            balance BIGINT(20) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(32) NOT NULL,
            event_name VARCHAR(96) NOT NULL,
            schema_version SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            source_actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_subject_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_ref VARCHAR(64) NOT NULL DEFAULT '',
            subject_ref VARCHAR(64) NOT NULL DEFAULT '',
            entity_type VARCHAR(48) NOT NULL,
            entity_id VARCHAR(128) NOT NULL,
            campaign_id VARCHAR(128) NOT NULL DEFAULT '',
            occurred_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL,
            dedupe_key CHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            decision_json LONGTEXT NULL,
            account_id BIGINT(20) UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'received',
            error_code VARCHAR(96) NOT NULL DEFAULT '',
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY source_event (source, event_name),
            KEY subject_ref (subject_ref),
            KEY entity_lookup (entity_type, entity_id),
            KEY status (status),
            KEY occurred_at (occurred_at),
            KEY account_id (account_id)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$rules} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(96) NOT NULL,
            title VARCHAR(190) NOT NULL,
            source VARCHAR(32) NOT NULL,
            event_name VARCHAR(96) NOT NULL,
            entity_type VARCHAR(48) NULL,
            conditions_json LONGTEXT NOT NULL,
            limits_json LONGTEXT NULL,
            points BIGINT(20) NOT NULL,
            enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            priority INT(11) NOT NULL DEFAULT 10,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY event_rules (source, event_name, enabled),
            KEY priority (priority)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$ledger} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            event_id BIGINT(20) UNSIGNED NULL,
            rule_id BIGINT(20) UNSIGNED NULL,
            entry_type VARCHAR(24) NOT NULL,
            delta BIGINT(20) NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            related_entry_id BIGINT(20) UNSIGNED NULL,
            idempotency_key VARCHAR(128) NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_rule (event_id, rule_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY account_created (account_id, created_at),
            KEY event_id (event_id),
            KEY rule_id (rule_id),
            KEY related_entry_id (related_entry_id)
        ) ENGINE=InnoDB {$charset};");


        dbDelta("CREATE TABLE {$catalog} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(96) NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            points_cost BIGINT(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'inactive',
            fulfillment_mode VARCHAR(24) NOT NULL DEFAULT 'manual',
            requires_verification TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            stock_total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            stock_used BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            max_per_user INT(11) UNSIGNED NOT NULL DEFAULT 0,
            note_label VARCHAR(190) NOT NULL DEFAULT '',
            sort_order INT(11) NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status_sort (status, sort_order),
            KEY points_cost (points_cost)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$redemptions} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            reward_id BIGINT(20) UNSIGNED NOT NULL,
            points_cost BIGINT(20) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            user_note TEXT NULL,
            fulfillment_value TEXT NULL,
            admin_note TEXT NULL,
            debit_ledger_id BIGINT(20) UNSIGNED NULL,
            refund_ledger_id BIGINT(20) UNSIGNED NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            fulfilled_at DATETIME NULL,
            refunded_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY user_created (wp_user_id, created_at),
            KEY account_created (account_id, created_at),
            KEY reward_status (reward_id, status),
            KEY status_created (status, created_at),
            KEY debit_ledger_id (debit_ledger_id),
            KEY refund_ledger_id (refund_ledger_id)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$codes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            reward_id BIGINT(20) UNSIGNED NOT NULL,
            code_hash CHAR(64) NOT NULL,
            code_value TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            redemption_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            assigned_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reward_code_hash (reward_id, code_hash),
            KEY reward_status (reward_id, status, id),
            KEY redemption_id (redemption_id)
        ) ENGINE=InnoDB {$charset};");

        return self::schema_ready();
    }

    private static function schema_ready() {
        global $wpdb;
        $events = Manahel_Rewards_DB::table('events');
        $rules = Manahel_Rewards_DB::table('rules');
        $catalog = Manahel_Rewards_DB::table('catalog');
        $redemptions = Manahel_Rewards_DB::table('redemptions');
        $codes = Manahel_Rewards_DB::table('codes');
        $event_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$events} LIKE %s", 'decision_json'));
        $rule_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$rules} LIKE %s", 'limits_json'));
        $catalog_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$catalog} LIKE %s", 'points_cost'));
        $redemption_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$redemptions} LIKE %s", 'debit_ledger_id'));
        $code_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$codes} LIKE %s", 'code_hash'));
        return 'decision_json' === (string)$event_column
            && 'limits_json' === (string)$rule_column
            && 'points_cost' === (string)$catalog_column
            && 'debit_ledger_id' === (string)$redemption_column
            && 'code_hash' === (string)$code_column
            && Manahel_Rewards_DB::storage_is_transactional();
    }

    private static function ensure_policy_timezone() {
        $existing = trim((string)get_option(Manahel_Rewards_Limit_Evaluator::OPTION_TIMEZONE, ''));
        if (Manahel_Rewards_Limit_Evaluator::valid_timezone($existing)) { return; }
        $timezone = function_exists('wp_timezone_string') ? (string)wp_timezone_string() : 'UTC';
        if (!Manahel_Rewards_Limit_Evaluator::valid_timezone($timezone)) { $timezone = 'UTC'; }
        update_option(Manahel_Rewards_Limit_Evaluator::OPTION_TIMEZONE, $timezone, false);
    }

    private static function seed_rule_templates() {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('rules');
        if (!$table) { return; }

        $templates = array(
            array(
                'code' => 'HAMNOOR_QURAN_PART_COMPLETED',
                'title' => 'تکمیل سهم عمومی قرآن',
                'source' => 'hamnoor',
                'event_name' => 'participation.completed',
                'entity_type' => 'quran_part',
                'conditions' => array('context.content_type' => 'quran', 'context.kind' => 'completion'),
                'points' => 2,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>6,'max_points_per_day'=>12,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0),
                'priority' => 10,
            ),
            array(
                'code' => 'HAMNOOR_PERSONAL_QURAN_COMPLETED',
                'title' => 'تکمیل ختم شخصی قرآن',
                'source' => 'hamnoor',
                'event_name' => 'participation.completed',
                'entity_type' => 'personal_quran',
                'conditions' => array('context.content_type' => 'quran', 'context.kind' => 'completion'),
                'points' => 5,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>1,'max_points_per_day'=>5,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>604800),
                'priority' => 20,
            ),
            array(
                'code' => 'HAMNOOR_DUA_COMPLETED',
                'title' => 'تکمیل معتبر دعا',
                'source' => 'hamnoor',
                'event_name' => 'participation.completed',
                'entity_type' => 'contribution',
                'conditions' => array('context.content_type' => 'dua', 'context.kind' => 'completion'),
                'points' => 1,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>3,'max_points_per_day'=>3,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0),
                'priority' => 30,
            ),
            array(
                'code' => 'MANAHEL_REFERRAL_QUALIFIED_INVITER',
                'title' => 'دعوت موفق کاربر جدید',
                'source' => 'manahel_referral',
                'event_name' => 'referral.qualified',
                'entity_type' => 'referral_inviter',
                'conditions' => array('context.role' => 'inviter'),
                'points' => 40,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>10,'max_points_per_day'=>400,'max_awards_per_month'=>100,'max_points_per_month'=>4000,'cooldown_seconds'=>0),
                'priority' => 40,
            ),
            array(
                'code' => 'MANAHEL_REFERRAL_QUALIFIED_REFERRED',
                'title' => 'پاداش خوش‌آمد دعوت معتبر',
                'source' => 'manahel_referral',
                'event_name' => 'referral.qualified',
                'entity_type' => 'referral_referred',
                'conditions' => array('context.role' => 'referred'),
                'points' => 5,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>0,'max_points_per_day'=>0,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0),
                'priority' => 50,
            ),
            array(
                'code' => 'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT',
                'title' => 'جذب مشارکت‌کننده به پویش',
                'source' => 'manahel_referral',
                'event_name' => 'referral.campaign_participant',
                'entity_type' => 'campaign_referrer',
                'conditions' => array('context.role' => 'inviter'),
                'points' => 10,
                'limits' => array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>20,'max_points_per_day'=>200,'max_awards_per_month'=>200,'max_points_per_month'=>2000,'cooldown_seconds'=>0),
                'priority' => 60,
            ),
        );

        $now = Manahel_Rewards_DB::utc_now_mysql();
        foreach ($templates as $template) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code=%s LIMIT 1", $template['code']));
            if ($exists) { continue; }
            Manahel_Rewards_DB::quiet_insert($table, array(
                'code' => $template['code'],
                'title' => $template['title'],
                'source' => $template['source'],
                'event_name' => $template['event_name'],
                'entity_type' => $template['entity_type'],
                'conditions_json' => wp_json_encode($template['conditions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'limits_json' => wp_json_encode(Manahel_Rewards_Limit_Evaluator::sanitize_limits($template['limits']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'points' => (int)$template['points'],
                'enabled' => 0,
                'priority' => (int)$template['priority'],
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }
    }

    /**
     * Move only untouched v0.6 default templates to the growth-first policy.
     * Any rule the administrator has customized is left exactly as-is.
     */
    private static function apply_growth_first_defaults() {
        global $wpdb;
        $table = Manahel_Rewards_DB::table('rules');
        $legacy = array(
            'HAMNOOR_QURAN_PART_COMPLETED' => array('points'=>10,'limits'=>array('version'=>1,'unique_per_entity'=>true,'max_awards_per_day'=>6,'max_points_per_day'=>60,'cooldown_seconds'=>0),'new_points'=>2,'new_limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>6,'max_points_per_day'=>12,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0)),
            'HAMNOOR_PERSONAL_QURAN_COMPLETED' => array('points'=>50,'limits'=>array('version'=>1,'unique_per_entity'=>true,'max_awards_per_day'=>1,'max_points_per_day'=>50,'cooldown_seconds'=>604800),'new_points'=>5,'new_limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>1,'max_points_per_day'=>5,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>604800)),
            'HAMNOOR_DUA_COMPLETED' => array('points'=>3,'limits'=>array('version'=>1,'unique_per_entity'=>true,'max_awards_per_day'=>3,'max_points_per_day'=>9,'cooldown_seconds'=>0),'new_points'=>1,'new_limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>3,'max_points_per_day'=>3,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0)),
            'MANAHEL_REFERRAL_QUALIFIED_INVITER' => array('points'=>20,'limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>5,'max_points_per_day'=>100,'max_awards_per_month'=>20,'max_points_per_month'=>400,'cooldown_seconds'=>0),'new_points'=>40,'new_limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>10,'max_points_per_day'=>400,'max_awards_per_month'=>100,'max_points_per_month'=>4000,'cooldown_seconds'=>0)),
            'MANAHEL_REFERRAL_QUALIFIED_REFERRED' => array('points'=>10,'limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>0,'max_points_per_day'=>0,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0),'new_points'=>5,'new_limits'=>array('version'=>2,'unique_per_entity'=>true,'max_awards_per_day'=>0,'max_points_per_day'=>0,'max_awards_per_month'=>0,'max_points_per_month'=>0,'cooldown_seconds'=>0)),
        );
        foreach ($legacy as $code=>$policy) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,points,limits_json FROM {$table} WHERE code=%s LIMIT 1", $code));
            if (!$row || (int)$row->points !== (int)$policy['points']) { continue; }
            $current = json_decode((string)$row->limits_json, true);
            $expected = Manahel_Rewards_Limit_Evaluator::sanitize_limits($policy['limits']);
            if (!is_array($current) || Manahel_Rewards_Limit_Evaluator::sanitize_limits($current) !== $expected) { continue; }
            $wpdb->update($table, array(
                'points'=>(int)$policy['new_points'],
                'limits_json'=>wp_json_encode(Manahel_Rewards_Limit_Evaluator::sanitize_limits($policy['new_limits']), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'updated_at'=>Manahel_Rewards_DB::utc_now_mysql(),
            ), array('id'=>(int)$row->id), array('%d','%s','%s'), array('%d'));
        }
    }

    private static function grant_capabilities() {
        $role = get_role('administrator');
        if ($role && !$role->has_cap(MANAHEL_REWARDS_CAP)) {
            $role->add_cap(MANAHEL_REWARDS_CAP);
        }
    }
}
