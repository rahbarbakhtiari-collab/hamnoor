<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Keeps Hamnoor's domain identity separate from WordPress authentication while
 * ensuring every Hamnoor person is visible/manageable in WordPress Users.
 */
final class Hamnoor_WP_User_Bridge {
    const META_HAMNOOR_USER_ID = '_hamnoor_user_id';

    public static function init() {
        add_filter('manage_users_columns', array(__CLASS__, 'columns'));
        add_filter('manage_users_custom_column', array(__CLASS__, 'column_value'), 10, 3);
        add_action('delete_user', array(__CLASS__, 'on_wp_user_deleted'), 10, 3);
        add_action('restrict_manage_users', array(__CLASS__, 'identity_filter'));
        add_action('pre_get_users', array(__CLASS__, 'apply_identity_filter'));
    }

    public static function ensure_link($hamnoor_user) {
        if (!$hamnoor_user || empty($hamnoor_user->id)) { return new WP_Error('hamnoor_user_invalid', __('کاربر هم‌نور معتبر نیست.', 'hamnoor')); }
        $hamnoor_id = (int)$hamnoor_user->id;
        if (!empty($hamnoor_user->wp_user_id)) {
            $wp_user = get_user_by('id', (int)$hamnoor_user->wp_user_id);
            if ($wp_user) {
                $linked_meta = (int)get_user_meta((int)$wp_user->ID, self::META_HAMNOOR_USER_ID, true);
                if ($linked_meta && $linked_meta !== $hamnoor_id) {
                    return new WP_Error('hamnoor_wp_user_link_conflict', __('این حساب وردپرس قبلاً به هویت دیگری در هم‌نور متصل شده است.', 'hamnoor'));
                }
                self::sync_profile($wp_user->ID, $hamnoor_user);
                return (int)$wp_user->ID;
            }
        }

        $existing = get_users(array('meta_key'=>self::META_HAMNOOR_USER_ID, 'meta_value'=>(string)$hamnoor_id, 'number'=>2, 'fields'=>'ID'));
        if (count((array)$existing) > 1) {
            return new WP_Error('hamnoor_wp_user_duplicate_link', __('برای این هویت هم‌نور بیش از یک حساب وردپرس پیدا شد و اتصال خودکار متوقف شد.', 'hamnoor'));
        }
        if ($existing) {
            $wp_user_id = (int)reset($existing);
            $conflict = self::wp_user_claimed_by_other_identity($wp_user_id, $hamnoor_id);
            if ($conflict) { return $conflict; }
            if (!self::link($hamnoor_id, $wp_user_id)) { return new WP_Error('hamnoor_wp_user_link_failed', __('اتصال حساب وردپرس به هویت هم‌نور انجام نشد.', 'hamnoor')); }
            self::sync_profile($wp_user_id, $hamnoor_user);
            return $wp_user_id;
        }

        $login = 'hn_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$hamnoor_user->public_id));
        if (!$login || username_exists($login)) { $login = 'hn_' . $hamnoor_id . '_' . strtolower(wp_generate_password(8, false, false)); }
        $wp_user_id = wp_insert_user(array(
            'user_login'=>$login,
            'user_pass'=>wp_generate_password(64, true, true),
            'display_name'=>(string)$hamnoor_user->display_name,
            'nickname'=>(string)$hamnoor_user->display_name,
            'role'=>'hamnoor_member',
        ));
        if (is_wp_error($wp_user_id)) { return $wp_user_id; }
        update_user_meta((int)$wp_user_id, self::META_HAMNOOR_USER_ID, $hamnoor_id);
        if (!self::link($hamnoor_id, (int)$wp_user_id)) {
            delete_user_meta((int)$wp_user_id, self::META_HAMNOOR_USER_ID);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int)$wp_user_id);
            return new WP_Error('hamnoor_wp_user_link_failed', __('ساخت حساب وردپرس انجام شد اما اتصال امن آن به هویت هم‌نور ناموفق بود.', 'hamnoor'));
        }
        return (int)$wp_user_id;
    }

    private static function wp_user_claimed_by_other_identity($wp_user_id, $hamnoor_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('users');
        $other = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE wp_user_id=%d AND id<>%d LIMIT 1", (int)$wp_user_id, (int)$hamnoor_id));
        if ($other) { return new WP_Error('hamnoor_wp_user_link_conflict', __('این حساب وردپرس قبلاً به هویت دیگری در هم‌نور متصل شده است.', 'hamnoor')); }
        $meta = (int)get_user_meta((int)$wp_user_id, self::META_HAMNOOR_USER_ID, true);
        return ($meta && $meta !== (int)$hamnoor_id) ? new WP_Error('hamnoor_wp_user_meta_conflict', __('فراداده حساب وردپرس با هویت هم‌نور ناسازگار است.', 'hamnoor')) : false;
    }

    private static function link($hamnoor_id, $wp_user_id) {
        global $wpdb;
        $conflict = self::wp_user_claimed_by_other_identity((int)$wp_user_id, (int)$hamnoor_id);
        if ($conflict) { return false; }
        return false !== $wpdb->update(Hamnoor_DB::table('users'), array('wp_user_id'=>(int)$wp_user_id,'updated_at'=>current_time('mysql')), array('id'=>(int)$hamnoor_id), array('%d','%s'), array('%d'));
    }

    public static function sync_profile($wp_user_id, $hamnoor_user) {
        $wp_user_id = (int)$wp_user_id;
        if (!$wp_user_id || !$hamnoor_user) { return false; }
        wp_update_user(array('ID'=>$wp_user_id,'display_name'=>(string)$hamnoor_user->display_name,'nickname'=>(string)$hamnoor_user->display_name));
        update_user_meta($wp_user_id, self::META_HAMNOOR_USER_ID, (int)$hamnoor_user->id);
        return true;
    }

    public static function sync_all($limit = 5000) {
        global $wpdb;
        $table = Hamnoor_DB::table('users');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id ASC LIMIT %d", max(1, min(5000, (int)$limit))));
        $count = 0;
        foreach ((array)$rows as $row) {
            $linked = self::ensure_link($row);
            if (is_wp_error($linked)) {
                Hamnoor_DB::log(0, 0, 0, (int)$row->id, 'wp_user_bridge_failed', $linked->get_error_code() . ': ' . $linked->get_error_message());
                return false;
            }
            $count++;
        }
        return $count;
    }

    public static function identity_filter($which = '') {
        if (!current_user_can('list_users')) { return; }
        $value = isset($_GET['hamnoor_identity_status']) ? sanitize_key(wp_unslash($_GET['hamnoor_identity_status'])) : '';
        echo '<select name="hamnoor_identity_status">';
        echo '<option value="">' . esc_html__('همه وضعیت‌های هم‌نور', 'hamnoor') . '</option>';
        echo '<option value="verified"' . selected($value, 'verified', false) . '>' . esc_html__('هم‌نور: تأییدشده', 'hamnoor') . '</option>';
        echo '<option value="unverified"' . selected($value, 'unverified', false) . '>' . esc_html__('هم‌نور: تأییدنشده', 'hamnoor') . '</option>';
        echo '<option value="blocked"' . selected($value, 'blocked', false) . '>' . esc_html__('هم‌نور: مسدود', 'hamnoor') . '</option>';
        echo '</select>';
    }

    public static function apply_identity_filter($query) {
        if (!is_admin() || !($query instanceof WP_User_Query) || !current_user_can('list_users')) { return; }
        $value = isset($_GET['hamnoor_identity_status']) ? sanitize_key(wp_unslash($_GET['hamnoor_identity_status'])) : '';
        if (!in_array($value, array('verified','unverified','blocked'), true)) { return; }
        global $wpdb;
        $domain = Hamnoor_DB::table('users');
        if ('blocked' === $value) { $ids = $wpdb->get_col("SELECT wp_user_id FROM $domain WHERE wp_user_id IS NOT NULL AND status='blocked'"); }
        elseif ('verified' === $value) { $ids = $wpdb->get_col("SELECT wp_user_id FROM $domain WHERE wp_user_id IS NOT NULL AND status='active' AND mobile_verified=1"); }
        else { $ids = $wpdb->get_col("SELECT wp_user_id FROM $domain WHERE wp_user_id IS NOT NULL AND status='active' AND mobile_verified=0"); }
        $query->set('include', array_values(array_filter(array_map('intval', (array)$ids))) ?: array(0));
    }

    public static function columns($columns) {
        $columns['hamnoor_mobile'] = __('موبایل هم‌نور', 'hamnoor');
        $columns['hamnoor_identity'] = __('وضعیت هم‌نور', 'hamnoor');
        return $columns;
    }

    public static function column_value($value, $column, $wp_user_id) {
        if (!in_array($column, array('hamnoor_mobile','hamnoor_identity'), true)) { return $value; }
        $hamnoor_id = (int)get_user_meta((int)$wp_user_id, self::META_HAMNOOR_USER_ID, true);
        if (!$hamnoor_id) { return '—'; }
        $user = Hamnoor_Mobile_Access::get_user($hamnoor_id);
        if (!$user) { return '—'; }
        if ('hamnoor_mobile' === $column) { return esc_html((string)$user->phone); }
        return 1 === (int)$user->mobile_verified ? esc_html__('تأییدشده', 'hamnoor') : esc_html__('تأییدنشده', 'hamnoor');
    }

    public static function on_wp_user_deleted($wp_user_id, $reassign = null, $user = null) {
        $hamnoor_id = (int)get_user_meta((int)$wp_user_id, self::META_HAMNOOR_USER_ID, true);
        if (!$hamnoor_id) { return; }
        global $wpdb;
        $wpdb->update(Hamnoor_DB::table('users'), array('status'=>'blocked','wp_user_id'=>null,'updated_at'=>current_time('mysql')), array('id'=>$hamnoor_id), array('%s','%d','%s'), array('%d'));
    }
}
