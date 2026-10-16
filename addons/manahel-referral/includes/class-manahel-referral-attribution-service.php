<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Attribution_Service {
    const QUERY_ARG = 'mref';
    const COOKIE_NAME = 'manahel_referral_first_touch';
    const FIRST_TOUCH_TIME_COOKIE = 'manahel_referral_first_touch_at';
    const CAMPAIGN_QUERY_ARG = 'mrc';
    const CAMPAIGN_COOKIE_NAME = 'manahel_referral_campaign_touch';
    const ATTRIBUTION_DAYS = 30;
    const FIRST_TOUCH_META = '_manahel_referral_first_touch_v1';
    const CAMPAIGN_TOUCH_META = '_manahel_referral_campaign_touch_v1';

    public static function capture_first_touch() {
        if (!headers_sent() && !empty($_GET[self::QUERY_ARG])) {
            $code = Manahel_Referral_Code_Service::normalize(wp_unslash($_GET[self::QUERY_ARG]));
            if ($code && Manahel_Referral_Code_Service::find($code)) {
                // General first-touch remains immutable: the first valid inviter wins.
                if (empty($_COOKIE[self::COOKIE_NAME]) || !Manahel_Referral_Code_Service::find(wp_unslash($_COOKIE[self::COOKIE_NAME]))) {
                    $captured_at = time();
                    self::set_cookie_named(self::COOKIE_NAME, $code, $captured_at + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS);
                    self::set_cookie_named(self::FIRST_TOUCH_TIME_COOKIE, (string)$captured_at, $captured_at + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS);
                    $_COOKIE[self::COOKIE_NAME] = $code;
                    $_COOKIE[self::FIRST_TOUCH_TIME_COOKIE] = (string)$captured_at;
                }

                // Campaign touch is intentionally last-touch per campaign share.
                if (!empty($_GET[self::CAMPAIGN_QUERY_ARG])) {
                    $campaign_id = self::normalize_campaign_id(wp_unslash($_GET[self::CAMPAIGN_QUERY_ARG]));
                    if ($campaign_id) {
                        $value = $code . '|' . $campaign_id;
                        self::set_cookie_named(self::CAMPAIGN_COOKIE_NAME, $value, time() + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS);
                        $_COOKIE[self::CAMPAIGN_COOKIE_NAME] = $value;
                    }
                }
            }
        }
        // Mirror browser attribution into the current Hamnoor-linked WP user
        // when one already exists. This survives a later listener/cron outage.
        self::bind_touch_state_to_current_user();
    }

    public static function persist_first_touch_for_identity($hamnoor_payload, $context = array()) {
        if (!is_array($hamnoor_payload)) { return; }
        $wp_user_id = Manahel_Referral_Hamnoor_Adapter::resolve_wp_user_id((int)($hamnoor_payload['subject_user_id'] ?? 0));
        if ($wp_user_id <= 0 || empty($_COOKIE[self::COOKIE_NAME])) { return; }
        $replayed = is_array($context) && !empty($context['replayed']);
        if ($replayed && !self::first_touch_cookie_predates_identity($hamnoor_payload)) { return; }
        $code = Manahel_Referral_Code_Service::normalize(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        if ($code && Manahel_Referral_Code_Service::find($code)) {
            $captured_at = self::first_touch_cookie_time();
            if (!$captured_at) { $captured_at = self::payload_time($hamnoor_payload); }
            self::persist_touch_meta($wp_user_id, self::FIRST_TOUCH_META, $code, false, $captured_at);
        }
    }

    private static function bind_touch_state_to_current_user() {
        $wp_user_id = Manahel_Referral_Hamnoor_Adapter::current_wp_user_id();
        if ($wp_user_id <= 0) { return; }
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $code = Manahel_Referral_Code_Service::normalize(wp_unslash($_COOKIE[self::COOKIE_NAME]));
            $captured_at = self::first_touch_cookie_time();
            if ($code && $captured_at && Manahel_Referral_Code_Service::find($code)) { self::persist_touch_meta($wp_user_id, self::FIRST_TOUCH_META, $code, false, $captured_at); }
        }
        if (!empty($_COOKIE[self::CAMPAIGN_COOKIE_NAME])) {
            $touch = self::parse_campaign_touch(wp_unslash($_COOKIE[self::CAMPAIGN_COOKIE_NAME]));
            if ($touch && Manahel_Referral_Code_Service::find($touch['code'])) {
                self::persist_touch_meta($wp_user_id, self::CAMPAIGN_TOUCH_META, $touch['code'].'|'.$touch['campaign_id'], true);
            }
        }
    }

    public static function attribute_new_identity($hamnoor_payload, $context = array()) {
        global $wpdb;
        if (!is_array($hamnoor_payload) || 'identity.created' !== (string)($hamnoor_payload['event_name'] ?? '') || 1 !== (int)($hamnoor_payload['schema_version'] ?? 0)) {
            return false;
        }
        $referred_wp_user_id = Manahel_Referral_Hamnoor_Adapter::resolve_wp_user_id((int)($hamnoor_payload['subject_user_id'] ?? 0));
        if ($referred_wp_user_id <= 0) { return false; }
        $replayed = is_array($context) && !empty($context['replayed']);
        $code_value = self::touch_meta_value($referred_wp_user_id, self::FIRST_TOUCH_META);
        if (!$code_value && !empty($_COOKIE[self::COOKIE_NAME]) && (!$replayed || self::first_touch_cookie_predates_identity($hamnoor_payload))) {
            $code_value = Manahel_Referral_Code_Service::normalize(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        }
        if (!$code_value) { return false; }
        $code = Manahel_Referral_Code_Service::find($code_value);
        if (!$code) { self::clear_first_touch_for_user($referred_wp_user_id); return false; }

        $inviter_wp_user_id = (int)$code->owner_wp_user_id;
        if ($referred_wp_user_id <= 0 || $inviter_wp_user_id <= 0 || !get_user_by('id', $inviter_wp_user_id)) {
            self::clear_first_touch_for_user($referred_wp_user_id); return false;
        }
        if ($referred_wp_user_id === $inviter_wp_user_id) {
            self::clear_first_touch_for_user($referred_wp_user_id);
            do_action('manahel_referral_rejected', 'self_referral', $inviter_wp_user_id, $referred_wp_user_id);
            return false;
        }

        $table = Manahel_Referral_DB::table('attributions');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE referred_wp_user_id=%d LIMIT 1", $referred_wp_user_id));
        if ($existing) { self::clear_first_touch_for_user($referred_wp_user_id); return $existing; }

        $attributed_at = Manahel_Referral_DB::iso_to_mysql_utc($hamnoor_payload['occurred_at'] ?? '') ?: Manahel_Referral_DB::utc_now_mysql();
        $until = gmdate('Y-m-d H:i:s', strtotime($attributed_at . ' UTC') + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS);
        for ($attempt=0; $attempt<5; $attempt++) {
            $public_id = self::new_public_id();
            $insert = Manahel_Referral_DB::quiet_insert($table, array(
                'public_id'=>$public_id,
                'referral_code_id'=>(int)$code->id,
                'inviter_wp_user_id'=>$inviter_wp_user_id,
                'referred_wp_user_id'=>$referred_wp_user_id,
                'status'=>'attributed',
                'attributed_at'=>$attributed_at,
                'qualifies_until'=>$until,
                'qualified_at'=>null,
                'qualification_event_name'=>'',
                'qualification_entity_type'=>'',
                'qualification_entity_id'=>'',
                'rejection_code'=>'',
                'created_at'=>$attributed_at,
                'updated_at'=>$attributed_at,
            ));
            if (false !== $insert['result']) {
                self::clear_first_touch_for_user($referred_wp_user_id);
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int)$insert['insert_id']));
            }
            $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE referred_wp_user_id=%d LIMIT 1", $referred_wp_user_id));
            if ($winner) { self::clear_first_touch_for_user($referred_wp_user_id); return $winner; }
        }
        return new WP_Error('referral_attribution_failed', __('ثبت انتساب دعوت انجام نشد.', 'manahel-referral'));
    }

    public static function qualify_from_participation($hamnoor_payload) {
        global $wpdb;
        if (!self::is_qualifying_participation($hamnoor_payload)) { return false; }
        $hamnoor_user_id = (int)($hamnoor_payload['subject_user_id'] ?? 0);
        $referred_wp_user_id = Manahel_Referral_Hamnoor_Adapter::resolve_wp_user_id($hamnoor_user_id);
        if ($referred_wp_user_id <= 0) { return false; }

        $table = Manahel_Referral_DB::table('attributions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE referred_wp_user_id=%d LIMIT 1", $referred_wp_user_id));
        if (!$row || 'attributed' !== (string)$row->status) { return false; }
        if (!Manahel_Referral_DB::storage_is_transactional() || !Manahel_Referral_DB::begin()) {
            return new WP_Error('referral_storage_unavailable', __('ذخیره‌سازی دعوت آماده نیست.', 'manahel-referral'));
        }

        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE", (int)$row->id));
            if (!$row || 'attributed' !== (string)$row->status) { Manahel_Referral_DB::rollback(); return false; }

            $occurred = Manahel_Referral_DB::iso_to_mysql_utc($hamnoor_payload['occurred_at'] ?? '');
            if (!$occurred) { throw new RuntimeException('qualification_time_invalid'); }
            if ($occurred < (string)$row->attributed_at) { Manahel_Referral_DB::rollback(); return false; }
            if ($occurred > (string)$row->qualifies_until) {
                $wpdb->update($table, array('status'=>'expired','updated_at'=>Manahel_Referral_DB::utc_now_mysql()), array('id'=>(int)$row->id), array('%s','%s'), array('%d'));
                Manahel_Referral_DB::commit();
                return false;
            }

            $entity_type = sanitize_key((string)($hamnoor_payload['entity_type'] ?? ''));
            $entity_id = sanitize_text_field((string)($hamnoor_payload['entity_id'] ?? ''));
            $update = $wpdb->update($table, array(
                'status'=>'qualified',
                'qualified_at'=>$occurred,
                'qualification_event_name'=>'participation.completed',
                'qualification_entity_type'=>$entity_type,
                'qualification_entity_id'=>$entity_id,
                'updated_at'=>Manahel_Referral_DB::utc_now_mysql(),
            ), array('id'=>(int)$row->id, 'status'=>'attributed'), array('%s','%s','%s','%s','%s','%s'), array('%d','%s'));
            if (false === $update) { throw new RuntimeException('qualification_update_failed'); }
            if (0 === (int)$update) { Manahel_Referral_DB::rollback(); return false; }

            $payload = self::qualified_payload($row, $hamnoor_payload, $occurred);
            $event_id = Manahel_Referral_Event_Store::append($payload);
            if (is_wp_error($event_id)) { throw new RuntimeException($event_id->get_error_code()); }
            if (!Manahel_Referral_DB::commit()) { throw new RuntimeException('qualification_commit_failed'); }

            do_action('manahel_referral/event/referral.qualified', $payload, array(
                'source'=>'manahel_referral', 'event_store_id'=>(int)$event_id, 'schema_version'=>1,
            ));
            return array('qualified'=>true, 'attribution_id'=>(int)$row->id, 'event_id'=>(int)$event_id, 'payload'=>$payload);
        } catch (Throwable $e) {
            Manahel_Referral_DB::rollback();
            return new WP_Error('referral_qualification_failed', __('ثبت دعوت موفق انجام نشد.', 'manahel-referral'), array('cause'=>$e->getMessage()));
        }
    }

    /**
     * Reward a campaign share when it actually activates a distinct participant.
     * New-user global referral qualification has precedence to avoid double
     * paying the same first completion. Existing users can still generate this
     * lower-value campaign-attraction fact.
     */
    public static function record_campaign_attraction_from_participation($hamnoor_payload, $general_result = null) {
        if (!self::is_qualifying_participation($hamnoor_payload)) { return false; }
        $participant_wp_user_id = Manahel_Referral_Hamnoor_Adapter::resolve_wp_user_id((int)($hamnoor_payload['subject_user_id'] ?? 0));
        if ($participant_wp_user_id <= 0) { return false; }
        if (is_array($general_result) && !empty($general_result['qualified'])) {
            self::clear_campaign_touch_for_user($participant_wp_user_id);
            return array('qualified'=>false, 'reason'=>'general_referral_precedence');
        }

        $raw_touch = !empty($_COOKIE[self::CAMPAIGN_COOKIE_NAME]) ? wp_unslash($_COOKIE[self::CAMPAIGN_COOKIE_NAME]) : self::touch_meta_value($participant_wp_user_id, self::CAMPAIGN_TOUCH_META);
        if (!$raw_touch) { return false; }
        $touch = self::parse_campaign_touch($raw_touch);
        if (!$touch) { self::clear_campaign_touch_for_user($participant_wp_user_id); return false; }
        $payload_campaign = self::normalize_campaign_id((string)($hamnoor_payload['campaign_id'] ?? ''));
        if (!$payload_campaign || $payload_campaign !== $touch['campaign_id']) { return false; }

        $code = Manahel_Referral_Code_Service::find($touch['code']);
        if (!$code) { self::clear_campaign_touch_for_user($participant_wp_user_id); return false; }
        $inviter_wp_user_id = (int)$code->owner_wp_user_id;
        if ($participant_wp_user_id <= 0 || $inviter_wp_user_id <= 0 || $participant_wp_user_id === $inviter_wp_user_id) {
            self::clear_campaign_touch_for_user($participant_wp_user_id);
            return false;
        }

        $occurred = Manahel_Referral_DB::iso_to_mysql_utc($hamnoor_payload['occurred_at'] ?? '');
        if (!$occurred) { return false; }
        $entity_id = 'MRC-' . strtoupper(substr(hash('sha256', $inviter_wp_user_id . '|' . $participant_wp_user_id . '|' . $payload_campaign), 0, 24));
        $payload = array(
            'event_name'=>'referral.campaign_participant',
            'schema_version'=>1,
            'occurred_at'=>gmdate('Y-m-d\\TH:i:s\\Z', strtotime($occurred . ' UTC')),
            'entity_type'=>'campaign_referral',
            'entity_id'=>$entity_id,
            'inviter_wp_user_id'=>$inviter_wp_user_id,
            'participant_wp_user_id'=>$participant_wp_user_id,
            'campaign_id'=>$payload_campaign,
            'context'=>array(
                'qualification_source'=>'hamnoor_campaign_share',
                'qualification_event'=>'participation.completed',
                'qualification_entity_type'=>sanitize_key((string)($hamnoor_payload['entity_type'] ?? '')),
                'qualification_entity_id'=>sanitize_text_field((string)($hamnoor_payload['entity_id'] ?? '')),
                'content_type'=>sanitize_key((string)($hamnoor_payload['context']['content_type'] ?? '')),
                'earning_policy'=>'participation_first_v1',
            ),
        );
        $event_id = Manahel_Referral_Event_Store::append($payload);
        if (is_wp_error($event_id)) { return $event_id; }
        self::clear_campaign_touch_for_user($participant_wp_user_id);
        do_action('manahel_referral/event/referral.campaign_participant', $payload, array(
            'source'=>'manahel_referral', 'event_store_id'=>(int)$event_id, 'schema_version'=>1,
        ));
        return array('qualified'=>true, 'event_id'=>(int)$event_id, 'payload'=>$payload);
    }

    public static function is_qualifying_participation($payload) {
        if (!is_array($payload) || 'participation.completed' !== (string)($payload['event_name'] ?? '') || 1 !== (int)($payload['schema_version'] ?? 0)) { return false; }
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $content = sanitize_key((string)($context['content_type'] ?? ''));
        $kind = sanitize_key((string)($context['kind'] ?? ''));
        $entity = sanitize_key((string)($payload['entity_type'] ?? ''));
        if ('completion' !== $kind) { return false; }
        if ('quran' === $content && in_array($entity, array('quran_part','personal_quran'), true)) { return true; }
        if ('dua' === $content && 'contribution' === $entity) { return true; }
        return false;
    }

    private static function qualified_payload($row, $source, $occurred_mysql) {
        return array(
            'event_name'=>'referral.qualified',
            'schema_version'=>1,
            'occurred_at'=>gmdate('Y-m-d\\TH:i:s\\Z', strtotime($occurred_mysql . ' UTC')),
            'entity_type'=>'referral',
            'entity_id'=>sanitize_text_field((string)$row->public_id),
            'inviter_wp_user_id'=>(int)$row->inviter_wp_user_id,
            'referred_wp_user_id'=>(int)$row->referred_wp_user_id,
            'context'=>array(
                'qualification_source'=>'hamnoor',
                'qualification_event'=>'participation.completed',
                'qualification_entity_type'=>sanitize_key((string)($source['entity_type'] ?? '')),
                'qualification_entity_id'=>sanitize_text_field((string)($source['entity_id'] ?? '')),
                'content_type'=>sanitize_key((string)($source['context']['content_type'] ?? '')),
                'earning_policy'=>'participation_first_v1',
            ),
        );
    }

    private static function new_public_id() {
        try { return 'MRF-' . strtoupper(bin2hex(random_bytes(10))); }
        catch (Throwable $e) { return 'MRF-' . strtoupper(substr(hash('sha256', wp_generate_password(40, true, true) . microtime(true)), 0, 20)); }
    }

    private static function set_cookie($value, $expires) {
        self::set_cookie_named(self::COOKIE_NAME, $value, $expires);
    }

    private static function set_cookie_named($name, $value, $expires) {
        $secure = is_ssl();
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string)COOKIE_DOMAIN : '';
        setcookie((string)$name, (string)$value, array(
            'expires'=>(int)$expires, 'path'=>$path, 'domain'=>$domain,
            'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Lax',
        ));
    }

    private static function normalize_campaign_id($value) {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9_-]/', '', $value);
        return is_string($value) ? substr($value, 0, 128) : '';
    }

    private static function parse_campaign_touch($value) {
        $parts = explode('|', (string)$value, 2);
        if (2 !== count($parts)) { return null; }
        $code = Manahel_Referral_Code_Service::normalize($parts[0]);
        $campaign_id = self::normalize_campaign_id($parts[1]);
        if (!$code || !$campaign_id) { return null; }
        return array('code'=>$code, 'campaign_id'=>$campaign_id);
    }

    private static function first_touch_cookie_time() {
        if (empty($_COOKIE[self::FIRST_TOUCH_TIME_COOKIE])) { return 0; }
        $value = preg_replace('/[^0-9]/', '', (string)wp_unslash($_COOKIE[self::FIRST_TOUCH_TIME_COOKIE]));
        $time = (int)$value;
        return ($time > 0 && $time <= time() + 300) ? $time : 0;
    }

    private static function payload_time($payload) {
        $mysql = Manahel_Referral_DB::iso_to_mysql_utc(is_array($payload) ? ($payload['occurred_at'] ?? '') : '');
        return $mysql ? (int)strtotime($mysql . ' UTC') : 0;
    }

    private static function first_touch_cookie_predates_identity($payload) {
        $captured = self::first_touch_cookie_time();
        $identity = self::payload_time($payload);
        return $captured > 0 && $identity > 0 && $captured <= ($identity + 5);
    }

    private static function persist_touch_meta($wp_user_id, $key, $value, $replace, $captured_at = 0) {
        $wp_user_id = (int)$wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) { return; }
        $existing = get_user_meta($wp_user_id, $key, true);
        if (!$replace && is_array($existing) && !empty($existing['value']) && !empty($existing['expires_at']) && (int)$existing['expires_at'] >= time()) { return; }
        update_user_meta($wp_user_id, $key, array(
            'value'=>(string)$value,
            'captured_at'=>$captured_at ? (int)$captured_at : time(),
            'expires_at'=>($captured_at ? (int)$captured_at : time()) + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS,
        ));
    }

    private static function touch_meta_value($wp_user_id, $key) {
        $data = get_user_meta((int)$wp_user_id, $key, true);
        if (!is_array($data) || empty($data['value']) || empty($data['expires_at']) || (int)$data['expires_at'] < time()) {
            if ((int)$wp_user_id > 0) { delete_user_meta((int)$wp_user_id, $key); }
            return '';
        }
        return (string)$data['value'];
    }

    private static function clear_first_touch_for_user($wp_user_id) {
        self::clear_cookie();
        if ((int)$wp_user_id > 0) { delete_user_meta((int)$wp_user_id, self::FIRST_TOUCH_META); }
    }

    private static function clear_campaign_touch_for_user($wp_user_id) {
        self::clear_campaign_cookie();
        if ((int)$wp_user_id > 0) { delete_user_meta((int)$wp_user_id, self::CAMPAIGN_TOUCH_META); }
    }

    public static function clear_cookie() {
        if (!headers_sent()) {
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? (string)COOKIE_DOMAIN : '';
            setcookie(self::COOKIE_NAME, '', array(
                'expires'=>time()-3600, 'path'=>$path, 'domain'=>$domain,
                'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax',
            ));
        }
        if (!headers_sent()) {
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? (string)COOKIE_DOMAIN : '';
            setcookie(self::FIRST_TOUCH_TIME_COOKIE, '', array(
                'expires'=>time()-3600, 'path'=>$path, 'domain'=>$domain,
                'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax',
            ));
        }
        unset($_COOKIE[self::COOKIE_NAME], $_COOKIE[self::FIRST_TOUCH_TIME_COOKIE]);
    }

    public static function clear_campaign_cookie() {
        if (!headers_sent()) {
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? (string)COOKIE_DOMAIN : '';
            setcookie(self::CAMPAIGN_COOKIE_NAME, '', array(
                'expires'=>time()-3600, 'path'=>$path, 'domain'=>$domain,
                'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax',
            ));
        }
        unset($_COOKIE[self::CAMPAIGN_COOKIE_NAME]);
    }
}
