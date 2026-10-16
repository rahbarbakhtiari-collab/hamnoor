<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_DB {
    public static function table($name) {
        global $wpdb;
        $map = array(
            'codes' => 'manahel_referral_codes',
            'attributions' => 'manahel_referral_attributions',
            'events' => 'manahel_referral_events',
        );
        return isset($map[$name]) ? $wpdb->prefix . $map[$name] : '';
    }

    public static function utc_now_mysql() { return gmdate('Y-m-d H:i:s'); }

    public static function iso_to_mysql_utc($value) {
        $value = trim((string)$value);
        if (!$value) { return ''; }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) { return ''; }
    }

    public static function begin() { global $wpdb; return false !== $wpdb->query('START TRANSACTION'); }
    public static function commit() { global $wpdb; return false !== $wpdb->query('COMMIT'); }
    public static function rollback() { global $wpdb; return false !== $wpdb->query('ROLLBACK'); }

    public static function quiet_insert($table, array $data, $format = null) {
        global $wpdb;
        $can = method_exists($wpdb, 'suppress_errors');
        $previous = $can ? $wpdb->suppress_errors(true) : null;
        try {
            $result = $wpdb->insert($table, $data, $format);
            return array('result'=>$result, 'insert_id'=>(int)$wpdb->insert_id, 'last_error'=>(string)$wpdb->last_error);
        } finally {
            if ($can) { $wpdb->suppress_errors($previous); }
        }
    }

    public static function storage_is_transactional() {
        global $wpdb;
        foreach (array('codes','attributions','events') as $name) {
            $table = self::table($name);
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ));
            if (!$engine || 'innodb' !== strtolower((string)$engine)) { return false; }
        }
        return true;
    }
}
