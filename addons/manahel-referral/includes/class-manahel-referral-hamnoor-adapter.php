<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Hamnoor_Adapter {
    const IDENTITY_CURSOR_OPTION = 'manahel_referral_hamnoor_identity_cursor';
    const PARTICIPATION_CURSOR_OPTION = 'manahel_referral_hamnoor_participation_cursor';
    const CRON_HOOK = 'manahel_referral_hamnoor_reconcile';
    private static $registered = false;

    public static function init() {
        if (self::$registered || !function_exists('hamnoor_core_api')) { return; }
        $api = hamnoor_core_api();
        if (!$api || !is_object($api) || !method_exists($api, 'on')) { return; }
        $identity = $api->on('identity.created', array(__CLASS__, 'on_identity_created'), 10, 'manahel-referral');
        // Referral qualification runs before Rewards' normal Hamnoor adapter
        // (priority 20) so a same-request welcome reward is reflected in the
        // completion feedback's post-award balance.
        $completion = $api->on('participation.completed', array(__CLASS__, 'on_participation_completed'), 10, 'manahel-referral');
        add_filter('hamnoor_share_invite_url', array(__CLASS__, 'personalize_share_url'), 20, 3);
        add_action(self::CRON_HOOK, array(__CLASS__, 'reconcile'));
        add_action('init', array(__CLASS__, 'ensure_reconcile_schedule'), 40);
        self::$registered = (bool)$identity && (bool)$completion;
    }

    public static function on_core_ready() { self::init(); }
    public static function is_registered() { return self::$registered; }

    public static function on_identity_created($payload, $context = array()) {
        Manahel_Referral_Attribution_Service::persist_first_touch_for_identity($payload, $context);
        return Manahel_Referral_Attribution_Service::attribute_new_identity($payload, $context);
    }

    public static function on_participation_completed($payload, $context = array()) {
        $general = Manahel_Referral_Attribution_Service::qualify_from_participation($payload);
        if (is_wp_error($general)) { return $general; }
        $campaign = Manahel_Referral_Attribution_Service::record_campaign_attraction_from_participation($payload, $general);
        if (is_wp_error($campaign)) { return $campaign; }
        return array('general'=>$general,'campaign'=>$campaign);
    }

    public static function reconcile_current_identity() {
        if (empty($_COOKIE[Manahel_Referral_Attribution_Service::COOKIE_NAME])) { return false; }
        if (!class_exists('Hamnoor_Mobile_Access') || !method_exists('Hamnoor_Mobile_Access', 'current_user')) { return false; }
        $user = Hamnoor_Mobile_Access::current_user();
        if (!$user || empty($user->id)) { return false; }
        $api = function_exists('hamnoor_core_api') ? hamnoor_core_api() : null;
        if (!$api || !method_exists($api, 'durable_event_for_subject')) { return false; }
        $row = $api->durable_event_for_subject('identity.created', (int)$user->id);
        if (!$row || empty($row['payload'])) { return false; }
        return self::on_identity_created($row['payload'], isset($row['context']) ? $row['context'] : array());
    }

    /**
     * Personalize only Hamnoor share URLs. Canonical campaign navigation stays
     * clean and cache/index friendly. Attribution is channel-neutral: the same
     * URL can be shared through Eitaa, WhatsApp, Telegram, SMS, QR, etc.
     */
    public static function personalize_share_url($url, $campaign = null, $mode = 'web') {
        $wp_user_id = self::current_wp_user_id();
        if ($wp_user_id <= 0) { return $url; }
        $code = Manahel_Referral_Code_Service::get_or_create_for_wp_user($wp_user_id);
        if (is_wp_error($code) || empty($code->code)) { return $url; }
        $args = array('mref' => (string)$code->code);
        if (is_object($campaign) && !empty($campaign->campaign_public_id)) {
            $args['mrc'] = sanitize_text_field((string)$campaign->campaign_public_id);
        }
        return add_query_arg($args, (string)$url);
    }

    public static function current_wp_user_id() {
        // This adapter is invoked only from Hamnoor-domain share surfaces. The
        // active Hamnoor session is authoritative here and must not be shadowed
        // by an unrelated WordPress admin/editor cookie in the same browser.
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'current_user')) {
            $user = Hamnoor_Mobile_Access::current_user();
            if ($user && !empty($user->wp_user_id) && get_user_by('id', (int)$user->wp_user_id)) { return (int)$user->wp_user_id; }
        }
        $wp_user_id = (int)get_current_user_id();
        return $wp_user_id > 0 && get_user_by('id', $wp_user_id) ? $wp_user_id : 0;
    }

    public static function ensure_reconcile_schedule() {
        $api = function_exists('hamnoor_core_api') ? hamnoor_core_api() : null;
        if (!$api || !is_object($api) || !method_exists($api, 'durable_events_after')) { return; }
        if (!wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK); }
    }

    public static function reconcile($limit = 50) {
        $api = function_exists('hamnoor_core_api') ? hamnoor_core_api() : null;
        if (!$api || !is_object($api) || !method_exists($api, 'durable_events_after')) {
            return array('status'=>'unavailable','identity'=>0,'participation'=>0);
        }
        $limit = max(1, min(100, (int)$limit));
        $identity = self::reconcile_stream($api, 'identity.created', self::IDENTITY_CURSOR_OPTION, $limit, array(__CLASS__, 'on_identity_created'));
        $participation = self::reconcile_stream($api, 'participation.completed', self::PARTICIPATION_CURSOR_OPTION, $limit, array(__CLASS__, 'on_participation_completed'));
        return array(
            'status'=>(('failed' === $identity['status']) || ('failed' === $participation['status'])) ? 'failed' : 'ok',
            'identity'=>(int)$identity['processed'],
            'participation'=>(int)$participation['processed'],
            'identity_cursor'=>(int)$identity['cursor'],
            'participation_cursor'=>(int)$participation['cursor'],
        );
    }

    private static function reconcile_stream($api, $event_name, $option, $limit, $callback) {
        $cursor = max(0, (int)get_option($option, 0));
        $rows = $api->durable_events_after($event_name, $cursor, $limit);
        $processed = 0;
        foreach ((array)$rows as $row) {
            if (empty($row['id']) || empty($row['payload']) || !is_array($row['payload'])) { continue; }
            $result = call_user_func($callback, $row['payload'], isset($row['context']) && is_array($row['context']) ? $row['context'] : array());
            if (is_wp_error($result)) { return array('status'=>'failed','processed'=>$processed,'cursor'=>$cursor); }
            $cursor = (int)$row['id'];
            update_option($option, $cursor, false);
            $processed++;
        }
        return array('status'=>'ok','processed'=>$processed,'cursor'=>$cursor);
    }


    /** Backward-compatible helper; earning policy no longer calls this method. */
    public static function is_verified_hamnoor_user($hamnoor_user_id) {
        $hamnoor_user_id = (int)$hamnoor_user_id;
        if ($hamnoor_user_id <= 0) { return false; }
        if (!class_exists('Hamnoor_Mobile_Access') || !method_exists('Hamnoor_Mobile_Access', 'is_verified')) {
            return false;
        }
        return (bool) Hamnoor_Mobile_Access::is_verified($hamnoor_user_id);
    }

    public static function resolve_wp_user_id($hamnoor_user_id) {
        $hamnoor_user_id = (int)$hamnoor_user_id;
        if ($hamnoor_user_id <= 0) { return 0; }
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'get_user')) {
            $user = Hamnoor_Mobile_Access::get_user($hamnoor_user_id);
            if ($user && !empty($user->wp_user_id) && get_user_by('id', (int)$user->wp_user_id)) { return (int)$user->wp_user_id; }
        }
        $ids = get_users(array('meta_key'=>'_hamnoor_user_id','meta_value'=>(string)$hamnoor_user_id,'number'=>2,'fields'=>'ID'));
        return 1 === count((array)$ids) ? (int)reset($ids) : 0;
    }
}
