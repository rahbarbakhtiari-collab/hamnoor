<?php
/**
 * Plugin Name: Hamnoor Notifications Suite | مجموعه اعلان هم‌نور
 * Plugin URI: https://www.manahel.ir/
 * Description: افزونه جامع و ماژولار اعلان هم‌نور؛ شامل Engine، Web Push، ایتا، بله و روبیکا.
 * Version: 4.0.4
 * Author: رهبر بختیاری
 * Author URI: https://www.manahel.ir/about/
 * Text Domain: hamnoor-notifications
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hamnoor
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HAMNOOR_NOTIFICATIONS_VERSION', '4.0.4');
define('HAMNOOR_NOTIFICATIONS_BUILD', 'rc1-20260828-eitaa-standalone-style-bridge');
define('HAMNOOR_NOTIFICATIONS_FILE', __FILE__);
define('HAMNOOR_NOTIFICATIONS_PATH', plugin_dir_path(__FILE__));

/**
 * Clear stale opcode entries once per internal build. Some shared hosts keep
 * compiled PHP after a same-version replacement even when the files changed.
 * The cache-safe plugin entry remains the active WordPress plugin path, while
 * Rubika's module and class use new physical paths for this internal build.
 */
function hamnoor_notifications_refresh_opcode_cache() {
    if (HAMNOOR_NOTIFICATIONS_BUILD === (string)get_option('hamnoor_notifications_active_build', '')) { return; }
    if (function_exists('opcache_invalidate')) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(HAMNOOR_NOTIFICATIONS_PATH, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                    @opcache_invalidate($file->getPathname(), true);
                }
            }
        } catch (Throwable $error) {
            error_log('Hamnoor Notifications OPcache refresh error: ' . $error->getMessage());
        }
    }
    clearstatcache(true);
    update_option('hamnoor_notifications_active_build', HAMNOOR_NOTIFICATIONS_BUILD, false);
}
add_action('plugins_loaded', 'hamnoor_notifications_refresh_opcode_cache', -10000);

/**
 * Load one internal module behind a Throwable boundary. Module failures are
 * persisted independently and never escape into WordPress bootstrap.
 */
function hamnoor_notifications_load_module($id, $file, $registration_function) {
    $id = sanitize_key((string)$id);
    if (function_exists($registration_function)) {
        update_option('hamnoor_notifications_module_' . $id, array('status'=>'external','error'=>'','at'=>current_time('mysql')), false);
        return true;
    }
    try {
        require_once $file;
        $loaded = function_exists($registration_function);
        update_option('hamnoor_notifications_module_' . $id, array(
            'status'=>$loaded ? 'loaded' : 'invalid',
            'error'=>$loaded ? '' : 'registration_function_missing',
            'at'=>current_time('mysql'),
        ), false);
        return $loaded;
    } catch (Throwable $error) {
        update_option('hamnoor_notifications_module_' . $id, array(
            'status'=>'failed',
            'error'=>substr($error->getMessage(), 0, 500),
            'at'=>current_time('mysql'),
        ), false);
        error_log('Hamnoor Notifications module ' . $id . ' error: ' . $error->getMessage());
        return false;
    }
}

/**
 * Runs before Hamnoor Core boots its add-on manager. No Core-dependent class
 * is declared until every plugin file has loaded and contracts are available.
 */
function hamnoor_notifications_prepare_suite() {
    static $prepared = false;
    if ($prepared) { return true; }
    if (!defined('HAMNOOR_VERSION') || version_compare(HAMNOOR_VERSION, '4.0.0', '<')
        || !interface_exists('Hamnoor_Addon_Interface')
        || !interface_exists('Hamnoor_Notification_Engine_Interface')) {
        update_option('hamnoor_notifications_addon_last_error', 'نسخه سازگار هسته هم‌نور نصب یا فعال نیست.', false);
        return false;
    }

    try {
        require_once HAMNOOR_NOTIFICATIONS_PATH . 'includes/class-hamnoor-notification-service.php';
        require_once HAMNOOR_NOTIFICATIONS_PATH . 'includes/class-hamnoor-notification-dispatcher.php';
        require_once HAMNOOR_NOTIFICATIONS_PATH . 'includes/class-hamnoor-notification-engine-service.php';
        require_once HAMNOOR_NOTIFICATIONS_PATH . 'includes/class-hamnoor-notification-broadcast.php';
        require_once HAMNOOR_NOTIFICATIONS_PATH . 'class-hamnoor-notifications-addon.php';
    } catch (Throwable $error) {
        update_option('hamnoor_notifications_addon_last_error', substr($error->getMessage(), 0, 500), false);
        error_log('Hamnoor Notifications engine load error: ' . $error->getMessage());
        return false;
    }

    add_action('hamnoor_register_addons', 'hamnoor_notifications_register_addon', 5, 2);
    add_action('hamnoor_activate_addons', array('Hamnoor_Notification_Dispatcher', 'activate'));
    add_action('hamnoor_deactivate_addons', array('Hamnoor_Notification_Dispatcher', 'deactivate'));
    add_filter('hamnoor_mobile_is_verified', 'hamnoor_notifications_repair_trusted_verification', 10, 3);
    add_action('hamnoor_mobile_changed', 'hamnoor_notifications_preserve_linked_channels_after_mobile_change', 10, 3);
    add_action('init', 'hamnoor_notifications_apply_channel_identity_policy_v2_once', 25);
    add_action('admin_post_hamnoor_notification_unsubscribe', 'hamnoor_notifications_frontend_unsubscribe');
    add_action('admin_post_nopriv_hamnoor_notification_unsubscribe', 'hamnoor_notifications_frontend_unsubscribe');
    add_action('admin_post_hamnoor_notification_reenable', 'hamnoor_notifications_frontend_reenable');
    add_action('admin_post_nopriv_hamnoor_notification_reenable', 'hamnoor_notifications_frontend_reenable');
    Hamnoor_Notification_Broadcast::init();

    hamnoor_notifications_load_module('web_push', HAMNOOR_NOTIFICATIONS_PATH . 'modules/web-push/module.php', 'hamnoor_web_push_register_addon');
    hamnoor_notifications_load_module('eitaa', HAMNOOR_NOTIFICATIONS_PATH . 'modules/eitaa/module.php', 'hamnoor_eitaa_register_addon');
    hamnoor_notifications_load_module('bale', HAMNOOR_NOTIFICATIONS_PATH . 'modules/bale/module.php', 'hamnoor_bale_register_addon');
    hamnoor_notifications_load_module('rubika', HAMNOOR_NOTIFICATIONS_PATH . 'modules/rubika/module-official-keypad.php', 'hamnoor_rubika_register_addon');
    hamnoor_notifications_load_module('whatsapp', HAMNOOR_NOTIFICATIONS_PATH . 'modules/whatsapp/module.php', 'hamnoor_whatsapp_register_addon');
    hamnoor_notifications_load_module('telegram', HAMNOOR_NOTIFICATIONS_PATH . 'modules/telegram/module.php', 'hamnoor_telegram_register_addon');
    hamnoor_notifications_load_module('app_push', HAMNOOR_NOTIFICATIONS_PATH . 'modules/app-push/module.php', 'hamnoor_app_push_register_addon');

    $prepared = true;
    delete_option('hamnoor_notifications_addon_last_error');
    return true;
}
add_action('plugins_loaded', 'hamnoor_notifications_prepare_suite', -100);

/**
 * Upgrade authoritative connections made by older releases. This is deliberately
 * limited to platform flows that supplied the account owner's own phone number.
 */
function hamnoor_notifications_channel_phone_binding_valid($user_id, $channel, $row = null) {
    if (!$user_id || !class_exists('Hamnoor_Mobile_Access') || !class_exists('Hamnoor_DB')) { return false; }
    global $wpdb;
    $channel = sanitize_key((string)$channel);
    if (!in_array($channel, array('eitaa','bale','rubika'), true)) { return true; }
    if (!$row) {
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,channel,external_id,metadata,status FROM $table WHERE user_id=%d AND channel=%s ORDER BY id DESC LIMIT 1",
            (int)$user_id, $channel
        ));
    }
    if (!$row || '' === trim((string)($row->external_id ?? ''))) { return false; }
    $meta = json_decode((string)($row->metadata ?? ''), true);
    $meta = is_array($meta) ? $meta : array();
    $fingerprint = isset($meta['verified_phone_hash']) ? strtolower(trim((string)$meta['verified_phone_hash'])) : '';
    return $fingerprint && Hamnoor_Mobile_Access::trusted_phone_matches_user((int)$user_id, $fingerprint);
}

/**
 * Messenger identity and Hamnoor phone are separate facts.
 *
 * Changing the verified Hamnoor mobile MUST NOT disconnect a previously linked
 * Eitaa/Bale/Rubika account. The external platform identity remains bound to
 * the same Hamnoor user_id and can still be used for navigation and delivery.
 * Its old phone proof, however, is not allowed to verify the new Hamnoor phone
 * or grant fresh sensitive access; that check remains in Hamnoor_Mobile_Access.
 */
function hamnoor_notifications_preserve_linked_channels_after_mobile_change($user_id, $old_phone = '', $new_phone = '') {
    if (!$user_id || !class_exists('Hamnoor_DB')) { return; }
    global $wpdb;
    $table = Hamnoor_DB::table('notification_channels');
    if (!$table) { return; }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,enabled,status,channel,external_id,metadata FROM $table WHERE user_id=%d AND channel IN ('eitaa','bale','rubika') AND external_id IS NOT NULL AND external_id<>''",
        (int)$user_id
    ));
    $now = current_time('mysql');
    foreach ((array)$rows as $row) {
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $meta['hamnoor_phone_changed_at'] = $now;
        $meta['channel_identity_binding'] = 'user_id';
        $meta['phone_proof_scope'] = 'verified_phone_at_connection_only';
        // Keep the historical proof for audit/sensitive-access comparison, but
        // never reinterpret it as proof of the newly verified Hamnoor phone.
        unset($meta['phone_binding_reason'], $meta['phone_binding_invalidated_at']);
        if ('reconnect_required' === (string)($meta['phone_binding_status'] ?? '')) {
            $meta['phone_binding_status'] = 'historical_phone_proof';
        }
        $updates = array(
            'metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at'=>$now,
        );
        $formats = array('%s','%s');
        // Only undo the old phone-mismatch invalidation. Explicit user opt-out,
        // permission_required, provider failures and disconnected states stay as-is.
        if ('needs_reconnect' === (string)$row->status && empty($meta['provider_reconnect_required']) && empty($meta['user_unsubscribed'])) {
            $updates['status'] = 1 === (int)$row->enabled ? 'connected' : 'disabled';
            $formats[] = '%s';
        }
        $wpdb->update($table, $updates, array('id'=>(int)$row->id), $formats, array('%d'));
    }
    if (class_exists('Hamnoor_Notification_Dispatcher')) { Hamnoor_Notification_Dispatcher::reset_channels_cache(); }
    elseif (class_exists('Hamnoor_Notifications')) { Hamnoor_Notifications::reset_channels_cache(); }
}

/**
 * Whether the linked external messenger identity is still usable for the same
 * Hamnoor account. This deliberately does NOT compare phone numbers.
 */
function hamnoor_notifications_channel_identity_valid($user_id, $channel, $row = null) {
    if (!$user_id || !class_exists('Hamnoor_DB')) { return false; }
    global $wpdb;
    $channel = sanitize_key((string)$channel);
    if (!in_array($channel, array('eitaa','bale','rubika'), true)) { return true; }
    if (!$row) {
        $table = Hamnoor_DB::table('notification_channels');
        if (!$table) { return false; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,channel,external_id,metadata,status FROM $table WHERE user_id=%d AND channel=%s ORDER BY id DESC LIMIT 1",
            (int)$user_id, $channel
        ));
    }
    if (!$row || (int)($row->user_id ?? $user_id) !== (int)$user_id || '' === trim((string)($row->external_id ?? ''))) { return false; }
    $user = class_exists('Hamnoor_Mobile_Access') ? Hamnoor_Mobile_Access::get_user((int)$user_id) : null;
    if ($user && isset($user->status) && 'active' !== (string)$user->status) { return false; }
    return !in_array((string)($row->status ?? ''), array('disconnected'), true);
}

/**
 * Migrate the temporary v1 phone-binding policy to the final identity policy.
 * The project is not public yet, so stale test rows invalidated only because
 * the Hamnoor phone changed can be restored directly.
 */
function hamnoor_notifications_apply_channel_identity_policy_v2_once() {
    if ('1' === (string)get_option('hamnoor_notifications_channel_identity_policy_v2', '')) { return; }
    if (!class_exists('Hamnoor_DB')) { return; }
    global $wpdb;
    $table = Hamnoor_DB::table('notification_channels');
    if (!$table) { return; }
    $rows = $wpdb->get_results("SELECT id,user_id,channel,enabled,status,external_id,metadata FROM $table WHERE channel IN ('eitaa','bale','rubika') AND external_id IS NOT NULL AND external_id<>''");
    $now = current_time('mysql');
    foreach ((array)$rows as $row) {
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $reason = (string)($meta['phone_binding_reason'] ?? '');
        $was_phone_policy = 'needs_reconnect' === (string)$row->status && in_array($reason, array('mobile_changed','phone_mismatch','legacy_missing_phone_proof'), true);
        $meta['channel_identity_binding'] = 'user_id';
        $meta['phone_proof_scope'] = 'verified_phone_at_connection_only';
        if ($was_phone_policy) {
            unset($meta['phone_binding_reason'], $meta['phone_binding_invalidated_at']);
            $meta['phone_binding_status'] = 'historical_phone_proof';
        }
        $data = array('metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>$now);
        $formats = array('%s','%s');
        if ($was_phone_policy && empty($meta['user_unsubscribed'])) {
            // v1 itself forced enabled=0, so that bit no longer represents the
            // user's prior consent. These are test-only rows and the only known
            // cause is our superseded phone-mismatch policy; restore delivery.
            $data['enabled'] = 1;
            $data['status'] = 'connected';
            $formats[] = '%d';
            $formats[] = '%s';
        }
        $wpdb->update($table, $data, array('id'=>(int)$row->id), $formats, array('%d'));
    }
    update_option('hamnoor_notifications_channel_identity_policy_v2', '1', false);
    if (class_exists('Hamnoor_Notification_Dispatcher')) { Hamnoor_Notification_Dispatcher::reset_channels_cache(); }
}

/** Upgrade trusted verification only when the stored platform proof matches the CURRENT phone. */
function hamnoor_notifications_repair_trusted_verification($verified, $user_id, $user = null) {
    if ($verified || !$user_id || !class_exists('Hamnoor_Mobile_Access') || !class_exists('Hamnoor_DB')) { return (bool)$verified; }
    $current_user = $user && isset($user->phone) ? $user : Hamnoor_Mobile_Access::get_user((int)$user_id);
    if (!$current_user) { return false; }
    $current_phone = Hamnoor_Validator::normalize_phone((string)$current_user->phone);
    if (!Hamnoor_Validator::valid_iran_mobile($current_phone)) { return false; }
    global $wpdb;
    $table = Hamnoor_DB::table('notification_channels');
    if (!$table) { return false; }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id,channel,status,external_id,metadata FROM $table WHERE user_id=%d AND channel IN ('eitaa','bale','rubika') AND external_id IS NOT NULL AND external_id<>''",
        (int)$user_id
    ));
    foreach ((array)$rows as $row) {
        if (!hamnoor_notifications_channel_phone_binding_valid((int)$user_id, (string)$row->channel, $row)) { continue; }
        $source = array('eitaa'=>'eitaa_initdata_request_contact','bale'=>'bale_request_contact','rubika'=>'rubika_ask_my_phone_number')[(string)$row->channel] ?? '';
        if (!$source) { continue; }
        $result = Hamnoor_Mobile_Access::verify_by_trusted_channel((int)$user_id, $source, $current_phone);
        if (!is_wp_error($result)) { return true; }
    }
    return false;
}

/** Return identity and consent separately for public dashboard rendering. */
function hamnoor_notifications_channel_dashboard_state($user_id, $channel) {
    global $wpdb;
    $user_id = (int)$user_id;
    $channel = sanitize_key((string)$channel);
    if (!$user_id || !$channel || !class_exists('Hamnoor_DB')) {
        return array('identity_connected'=>false,'notification_consent'=>false,'status'=>'','needs_reconnect'=>false,'phone_binding_valid'=>false,'phone_last4'=>'');
    }
    $table = Hamnoor_DB::table('notification_channels');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id,channel,enabled,status,external_id,metadata FROM $table WHERE user_id=%d AND channel=%s ORDER BY id DESC LIMIT 1",
        $user_id, $channel
    ));
    if (!$row) { return array('identity_connected'=>false,'notification_consent'=>false,'status'=>'','needs_reconnect'=>false,'phone_binding_valid'=>false,'phone_last4'=>''); }
    $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
    $identity_present = '' !== trim((string)$row->external_id);
    $phone_binding_valid = !in_array($channel, array('eitaa','bale','rubika'), true)
        ? true
        : hamnoor_notifications_channel_phone_binding_valid($user_id, $channel, $row);
    $identity_valid = !in_array($channel, array('eitaa','bale','rubika'), true) ? $identity_present : hamnoor_notifications_channel_identity_valid($user_id, $channel, $row);
    $needs_reconnect = $identity_present && 'needs_reconnect' === (string)$row->status && !empty($meta['provider_reconnect_required']);
    $consent = $identity_valid && !$needs_reconnect && 1 === (int)$row->enabled && 'connected' === (string)$row->status && empty($meta['user_unsubscribed']);
    return array(
        'identity_connected'=>$identity_present,
        'notification_consent'=>$consent,
        'status'=>(string)$row->status,
        'needs_reconnect'=>$needs_reconnect,
        'phone_binding_valid'=>$phone_binding_valid,
        'phone_last4'=>isset($meta['phone_last4']) ? preg_replace('/\D+/', '', (string)$meta['phone_last4']) : '',
    );
}

/** Disable delivery on one channel without deleting identity, history or proof. */
function hamnoor_notifications_frontend_unsubscribe() {
    check_admin_referer('hamnoor_notification_unsubscribe');
    $user_id = class_exists('Hamnoor_Mobile_Access') ? Hamnoor_Mobile_Access::current_user_id() : 0;
    if (!$user_id) { wp_die(esc_html__('نشست هم‌نور فعال نیست.', 'hamnoor')); }
    $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '';
    if (!in_array($channel, array('all','eitaa','bale','rubika','telegram','whatsapp','app_push'), true)) {
        wp_die(esc_html__('کانال اعلان معتبر نیست.', 'hamnoor'));
    }
    global $wpdb;
    $table = Hamnoor_DB::table('notification_channels');
    $channels = 'all' === $channel ? array('eitaa','bale','rubika','telegram','whatsapp','app_push') : array($channel);
    $placeholders = implode(',', array_fill(0, count($channels), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id,channel,metadata FROM $table WHERE user_id=%d AND channel IN ($placeholders)", array_merge(array((int)$user_id), $channels)));
    foreach ((array)$rows as $row) {
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
        $meta['unsubscribed_at'] = current_time('mysql');
        $meta['unsubscribed_by'] = 'user_dashboard';
        $meta['user_unsubscribed'] = 1;
        $wpdb->update($table, array('enabled'=>0,'status'=>'disabled','metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
    }
    if ('all' === $channel) {
        $push = Hamnoor_DB::table('push_subscriptions');
        if ($push) { $wpdb->update($push, array('revoked_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('user_id'=>(int)$user_id,'revoked_at'=>null), array('%s','%s'), array('%d','%s')); }
    }
    // A consent withdrawal also cancels work already waiting in the queue.
    $deliveries = Hamnoor_DB::table('notification_deliveries');
    $notifications = Hamnoor_DB::table('notifications');
    $cancel_channels = 'all' === $channel ? array('web_push','eitaa','bale','rubika','telegram','whatsapp','app_push') : array($channel);
    $cancel_placeholders = implode(',', array_fill(0, count($cancel_channels), '%s'));
    $wpdb->query($wpdb->prepare(
        "UPDATE $deliveries d INNER JOIN $notifications n ON n.id=d.notification_id SET d.status='cancelled',d.next_attempt_at=NULL,d.updated_at=%s WHERE n.user_id=%d AND d.channel IN ($cancel_placeholders) AND d.status IN ('queued','retry_wait')",
        array_merge(array(current_time('mysql'), (int)$user_id), $cancel_channels)
    ));
    Hamnoor_Notification_Dispatcher::reset_channels_cache();
    $url = add_query_arg('tab', 'reminders', Hamnoor_Helpers::page_url('dashboard'));
    Hamnoor_Helpers::redirect_with_notice($url, 'all' === $channel
        ? __('همه اعلان‌های بیرونی متوقف شدند. حساب، تأیید شماره و سوابق شما باقی مانده است.', 'hamnoor')
        : __('اعلان‌های این کانال متوقف شدند. حساب، تأیید شماره و سوابق شما باقی مانده است.', 'hamnoor'));
}

/** Re-enable consent for an identity the user previously disabled from Hamnoor. */
function hamnoor_notifications_frontend_reenable() {
    check_admin_referer('hamnoor_notification_reenable');
    $user_id = class_exists('Hamnoor_Mobile_Access') ? Hamnoor_Mobile_Access::current_user_id() : 0;
    if (!$user_id) { wp_die(esc_html__('نشست هم‌نور فعال نیست.', 'hamnoor')); }
    $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '';
    if (!in_array($channel, array('eitaa','bale','rubika'), true)) { wp_die(esc_html__('کانال اعلان معتبر نیست.', 'hamnoor')); }
    global $wpdb;
    $table = Hamnoor_DB::table('notification_channels');
    $row = $wpdb->get_row($wpdb->prepare("SELECT id,external_id,metadata FROM $table WHERE user_id=%d AND channel=%s ORDER BY id DESC LIMIT 1", (int)$user_id, $channel));
    $url = add_query_arg('tab', 'reminders', Hamnoor_Helpers::page_url('dashboard'));
    if (!$row || '' === trim((string)$row->external_id)) {
        Hamnoor_Helpers::redirect_with_notice($url, __('هویت این کانال هنوز به پنل شما متصل نیست؛ ابتدا اتصال را از همان پیام‌رسان انجام دهید.', 'hamnoor'), 'error');
    }
    if (in_array($channel, array('eitaa','bale','rubika'), true) && !hamnoor_notifications_channel_phone_binding_valid((int)$user_id, $channel, $row)) {
        Hamnoor_Helpers::redirect_with_notice($url, __('شمارهٔ تأییدشدهٔ این پیام‌رسان با شمارهٔ فعلی هم‌نور تطبیق ندارد؛ ابتدا اتصال را از همان پیام‌رسان دوباره برقرار کنید.', 'hamnoor'), 'warning');
    }
    $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : array();
    if (empty($meta['user_unsubscribed'])) {
        Hamnoor_Helpers::redirect_with_notice($url, __('برای فعال‌سازی این کانال، اتصال یا مجوز پیام‌رسان را دوباره بررسی کنید.', 'hamnoor'), 'warning');
    }
    unset($meta['user_unsubscribed'], $meta['unsubscribed_at'], $meta['unsubscribed_by'], $meta['disconnected_at']);
    $meta['notifications_reenabled_at'] = current_time('mysql');
    $ok = $wpdb->update($table, array('enabled'=>1,'status'=>'connected','metadata'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')), array('id'=>(int)$row->id), array('%d','%s','%s','%s'), array('%d'));
    if (false === $ok) { Hamnoor_Helpers::redirect_with_notice($url, __('فعال‌سازی دوباره اعلان انجام نشد؛ لطفاً دوباره تلاش کنید.', 'hamnoor'), 'error'); }
    Hamnoor_Notification_Dispatcher::reset_channels_cache();
    Hamnoor_Helpers::redirect_with_notice($url, __('اعلان‌های این کانال دوباره فعال شد. هویت و سوابق قبلی همان‌طور حفظ شده‌اند.', 'hamnoor'));
}

function hamnoor_notifications_register_addon($manager) {
    if (!class_exists('Hamnoor_Addon_Manager') || !($manager instanceof Hamnoor_Addon_Manager)) { return; }
    if ($manager->state('notifications')) { return; }
    $result = $manager->register_instance(new Hamnoor_Notifications_Addon());
    if (is_wp_error($result)) { update_option('hamnoor_notifications_addon_last_error', $result->get_error_message(), false); }
}

function hamnoor_notifications_suite_activate() {
    if (!hamnoor_notifications_prepare_suite()) {
        $message = (string) get_option('hamnoor_notifications_addon_last_error', 'Hamnoor Notifications dependencies are not ready.');
        throw new RuntimeException($message ?: 'Hamnoor Notifications dependencies are not ready.');
    }

    try {
        Hamnoor_Notification_Dispatcher::activate();
        Hamnoor_Notification_Broadcast::activate();
        foreach (array('hamnoor_web_push_activate','hamnoor_eitaa_activate','hamnoor_bale_activate','hamnoor_rubika_activate') as $callback) {
            if (!function_exists($callback)) {
                throw new RuntimeException('Required Hamnoor Notifications activation callback is missing: ' . $callback);
            }
            call_user_func($callback);
        }
        update_option('hamnoor_notifications_activation_status', array(
            'status'=>'ok',
            'version'=>HAMNOOR_NOTIFICATIONS_VERSION,
            'build'=>HAMNOOR_NOTIFICATIONS_BUILD,
            'at'=>current_time('mysql'),
        ), false);
        delete_option('hamnoor_notifications_addon_last_error');
    } catch (Throwable $error) {
        $message = substr($error->getMessage(), 0, 500);
        update_option('hamnoor_notifications_addon_last_error', $message, false);
        update_option('hamnoor_notifications_activation_status', array(
            'status'=>'failed',
            'version'=>defined('HAMNOOR_NOTIFICATIONS_VERSION') ? HAMNOOR_NOTIFICATIONS_VERSION : '',
            'build'=>defined('HAMNOOR_NOTIFICATIONS_BUILD') ? HAMNOOR_NOTIFICATIONS_BUILD : '',
            'at'=>current_time('mysql'),
            'error'=>$message,
        ), false);
        error_log('Hamnoor Notifications activation error: ' . $error->getMessage());
        throw $error;
    }
}
register_activation_hook(__FILE__, 'hamnoor_notifications_suite_activate');

function hamnoor_notifications_suite_deactivate() {
    if (class_exists('Hamnoor_Notification_Dispatcher')) { Hamnoor_Notification_Dispatcher::deactivate(); }
    if (class_exists('Hamnoor_Notification_Broadcast')) { Hamnoor_Notification_Broadcast::deactivate(); }
    foreach (array('hamnoor_web_push_deactivate','hamnoor_eitaa_deactivate','hamnoor_bale_deactivate','hamnoor_rubika_deactivate') as $callback) {
        if (function_exists($callback)) {
            try { call_user_func($callback); } catch (Throwable $error) { error_log('Hamnoor Notifications deactivation module error: ' . $error->getMessage()); }
        }
    }
}
register_deactivation_hook(__FILE__, 'hamnoor_notifications_suite_deactivate');

function hamnoor_notifications_dependency_notice() {
    if (!current_user_can('activate_plugins')) { return; }
    if (defined('HAMNOOR_VERSION') && version_compare(HAMNOOR_VERSION, '4.0.0', '>=')) { return; }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('مجموعه اعلان هم‌نور غیرفعال است.', 'hamnoor-notifications') . '</strong> '
        . esc_html__('نسخه سازگار هسته هم‌نور را نصب، فعال یا به‌روزرسانی کنید.', 'hamnoor-notifications') . '</p></div>';
}
add_action('admin_notices', 'hamnoor_notifications_dependency_notice');
