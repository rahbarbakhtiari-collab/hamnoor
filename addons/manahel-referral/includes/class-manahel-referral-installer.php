<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Installer {
    public static function activate() {
        if (self::install_schema()) {
            update_option('manahel_referral_db_version', MANAHEL_REFERRAL_DB_VERSION, false);
        }
        update_option('manahel_referral_version', MANAHEL_REFERRAL_VERSION, false);
        self::grant_capabilities();
    }

    public static function maybe_upgrade() {
        if ((string)get_option('manahel_referral_db_version', '') !== MANAHEL_REFERRAL_DB_VERSION) {
            if (self::install_schema()) {
                update_option('manahel_referral_db_version', MANAHEL_REFERRAL_DB_VERSION, false);
            }
            self::grant_capabilities();
        }
        if ((string)get_option('manahel_referral_version', '') !== MANAHEL_REFERRAL_VERSION) {
            update_option('manahel_referral_version', MANAHEL_REFERRAL_VERSION, false);
        }
    }

    private static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $codes = Manahel_Referral_DB::table('codes');
        $attr = Manahel_Referral_DB::table('attributions');
        $events = Manahel_Referral_DB::table('events');

        dbDelta("CREATE TABLE {$codes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(32) NOT NULL,
            owner_wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            UNIQUE KEY owner_wp_user_id (owner_wp_user_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$attr} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(64) NOT NULL,
            referral_code_id BIGINT(20) UNSIGNED NOT NULL,
            inviter_wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            referred_wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'attributed',
            attributed_at DATETIME NOT NULL,
            qualifies_until DATETIME NOT NULL,
            qualified_at DATETIME NULL,
            qualification_event_name VARCHAR(96) NOT NULL DEFAULT '',
            qualification_entity_type VARCHAR(48) NOT NULL DEFAULT '',
            qualification_entity_id VARCHAR(128) NOT NULL DEFAULT '',
            rejection_code VARCHAR(96) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY referred_wp_user_id (referred_wp_user_id),
            KEY inviter_status (inviter_wp_user_id,status),
            KEY referral_code_id (referral_code_id),
            KEY qualifies_until (qualifies_until)
        ) ENGINE=InnoDB {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(96) NOT NULL,
            schema_version SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            entity_type VARCHAR(48) NOT NULL,
            entity_id VARCHAR(128) NOT NULL,
            event_key CHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY event_name (event_name),
            KEY entity_lookup (entity_type,entity_id),
            KEY occurred_at (occurred_at)
        ) ENGINE=InnoDB {$charset};");

        return self::schema_ready();
    }

    private static function schema_ready() {
        global $wpdb;
        foreach (array('codes','attributions','events') as $name) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Manahel_Referral_DB::table($name))) !== Manahel_Referral_DB::table($name)) {
                return false;
            }
        }
        return Manahel_Referral_DB::storage_is_transactional();
    }

    private static function grant_capabilities() {
        $role = get_role('administrator');
        if ($role && !$role->has_cap(MANAHEL_REFERRAL_CAP)) { $role->add_cap(MANAHEL_REFERRAL_CAP); }
    }
}
