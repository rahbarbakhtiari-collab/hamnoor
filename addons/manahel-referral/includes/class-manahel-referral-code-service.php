<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Code_Service {
    const CODE_LENGTH = 12;

    public static function normalize($code) {
        $code = strtoupper(trim((string)$code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        return is_string($code) ? substr($code, 0, 32) : '';
    }

    public static function find($code) {
        global $wpdb;
        $code = self::normalize($code);
        if (strlen($code) < 8) { return null; }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Manahel_Referral_DB::table('codes') . ' WHERE code=%s AND status=%s LIMIT 1',
            $code, 'active'
        ));
    }

    public static function get_or_create_for_wp_user($wp_user_id) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) {
            return new WP_Error('referral_user_invalid', __('کاربر معتبر نیست.', 'manahel-referral'));
        }
        $table = Manahel_Referral_DB::table('codes');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE owner_wp_user_id=%d LIMIT 1", $wp_user_id));
        if ($existing) { return $existing; }

        $now = Manahel_Referral_DB::utc_now_mysql();
        for ($i=0; $i<8; $i++) {
            $code = self::random_code();
            $insert = Manahel_Referral_DB::quiet_insert($table, array(
                'code'=>$code, 'owner_wp_user_id'=>$wp_user_id, 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now,
            ), array('%s','%d','%s','%s','%s'));
            if (false !== $insert['result']) {
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int)$insert['insert_id']));
            }
            $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE owner_wp_user_id=%d LIMIT 1", $wp_user_id));
            if ($winner) { return $winner; }
        }
        return new WP_Error('referral_code_create_failed', __('ساخت کد دعوت انجام نشد.', 'manahel-referral'));
    }

    public static function invite_url($wp_user_id) {
        $code = self::get_or_create_for_wp_user((int)$wp_user_id);
        if (is_wp_error($code)) { return $code; }
        $destination = apply_filters('manahel_referral_destination_url', home_url('/'), (int)$wp_user_id, $code);
        $destination = esc_url_raw((string)$destination);
        if (!$destination) { $destination = home_url('/'); }
        return add_query_arg('mref', (string)$code->code, $destination);
    }

    private static function random_code() {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $value = '';
        try {
            for ($i=0; $i<self::CODE_LENGTH; $i++) { $value .= $alphabet[random_int(0, $max)]; }
        } catch (Throwable $e) {
            $seed = strtoupper(hash('sha256', wp_generate_password(32, true, true) . microtime(true) . wp_rand()));
            $value = substr(preg_replace('/[01IO]/', 'X', $seed), 0, self::CODE_LENGTH);
        }
        return $value;
    }
}
