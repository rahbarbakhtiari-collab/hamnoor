<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_DB {
    public static function table($name) {
        global $wpdb;
        $map = array(
            'accounts' => 'manahel_reward_accounts',
            'events'   => 'manahel_reward_events',
            'rules'    => 'manahel_reward_rules',
            'ledger'   => 'manahel_reward_ledger',
            'catalog'  => 'manahel_reward_catalog',
            'redemptions' => 'manahel_reward_redemptions',
            'codes' => 'manahel_reward_codes',
        );
        if (!isset($map[$name])) { return ''; }
        return $wpdb->prefix . $map[$name];
    }

    public static function utc_now_mysql() {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso_to_mysql_utc($value) {
        $value = trim((string)$value);
        if ('' === $value) { return ''; }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function begin() {
        global $wpdb;
        return false !== $wpdb->query('START TRANSACTION');
    }

    public static function commit() {
        global $wpdb;
        return false !== $wpdb->query('COMMIT');
    }

    public static function rollback() {
        global $wpdb;
        return false !== $wpdb->query('ROLLBACK');
    }

    /**
     * Run an INSERT without letting wpdb print/log an expected SQL error.
     *
     * Unique-key collisions are part of the idempotency/concurrency contract in
     * Rewards. Callers must still verify that the failed insert corresponds to
     * the expected existing row; unexpected failures remain normal WP_Error
     * paths instead of leaking raw database diagnostics.
     */
    public static function quiet_insert($table, array $data, $format = null) {
        global $wpdb;

        $can_suppress = is_object($wpdb) && method_exists($wpdb, 'suppress_errors');
        $previous = null;
        if ($can_suppress) {
            $previous = $wpdb->suppress_errors(true);
        }

        try {
            $result = $wpdb->insert($table, $data, $format);
            return array(
                'result' => $result,
                'insert_id' => (int)$wpdb->insert_id,
                'last_error' => (string)$wpdb->last_error,
            );
        } finally {
            if ($can_suppress) {
                $wpdb->suppress_errors($previous);
            }
        }
    }

    public static function storage_is_transactional() {
        global $wpdb;
        $tables = array_filter(array(
            self::table('accounts'), self::table('events'), self::table('rules'), self::table('ledger'),
            self::table('catalog'), self::table('redemptions'), self::table('codes')
        ));
        foreach ($tables as $table) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if (!$engine || 'innodb' !== strtolower((string)$engine)) { return false; }
        }
        return true;
    }
}
