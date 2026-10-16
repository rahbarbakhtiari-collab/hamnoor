<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Normalizer {
    public static function hamnoor_participation_completed($payload, $context = array()) {
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', __('ساختار رویداد هم‌نور معتبر نیست.', 'manahel-rewards'));
        }
        if ('participation.completed' !== (string)($payload['event_name'] ?? '')) {
            return new WP_Error('unsupported_event', __('نام رویداد پشتیبانی نمی‌شود.', 'manahel-rewards'));
        }
        if (1 !== (int)($payload['schema_version'] ?? 0)) {
            return new WP_Error('unsupported_schema_version', __('نسخه قرارداد رویداد پشتیبانی نمی‌شود.', 'manahel-rewards'));
        }

        $occurred_at = Manahel_Rewards_DB::iso_to_mysql_utc($payload['occurred_at'] ?? '');
        $entity_type = sanitize_key((string)($payload['entity_type'] ?? ''));
        $entity_id = sanitize_text_field((string)($payload['entity_id'] ?? ''));
        if (!$occurred_at || !$entity_type || !$entity_id) {
            return new WP_Error('missing_required_fields', __('فیلدهای الزامی رویداد کامل نیست.', 'manahel-rewards'));
        }

        $domain_context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $normalized_context = array(
            'content_type' => sanitize_key((string)($domain_context['content_type'] ?? '')),
            'participation_strategy' => sanitize_key((string)($domain_context['participation_strategy'] ?? '')),
            'kind' => sanitize_key((string)($domain_context['kind'] ?? '')),
            'quantity' => (int)($domain_context['quantity'] ?? 0),
        );

        $normalized = array(
            'source' => 'hamnoor',
            'event_name' => 'participation.completed',
            'schema_version' => 1,
            'occurred_at' => $occurred_at,
            'source_actor_user_id' => max(0, (int)($payload['actor_user_id'] ?? 0)),
            'source_subject_user_id' => max(0, (int)($payload['subject_user_id'] ?? 0)),
            'actor_ref' => sanitize_text_field((string)($payload['actor_public_id'] ?? '')),
            'subject_ref' => sanitize_text_field((string)($payload['subject_public_id'] ?? '')),
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'campaign_id' => sanitize_text_field((string)($payload['campaign_id'] ?? '')),
            'context' => $normalized_context,
        );

        $normalized['dedupe_key'] = self::dedupe_key(
            $normalized['source'],
            $normalized['event_name'],
            $normalized['entity_type'],
            $normalized['entity_id']
        );
        $normalized['payload_json'] = wp_json_encode(array(
            'event_name' => $normalized['event_name'],
            'schema_version' => $normalized['schema_version'],
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($normalized['occurred_at'] . ' UTC')),
            'actor_user_id' => $normalized['source_actor_user_id'],
            'subject_user_id' => $normalized['source_subject_user_id'],
            'actor_public_id' => $normalized['actor_ref'],
            'subject_public_id' => $normalized['subject_ref'],
            'entity_type' => $normalized['entity_type'],
            'entity_id' => $normalized['entity_id'],
            'campaign_id' => $normalized['campaign_id'],
            'context' => $normalized['context'],
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $normalized;
    }


    public static function referral_qualified($payload, $role) {
        if (!is_array($payload)) {
            return new WP_Error('invalid_referral_payload', __('ساختار رویداد دعوت معتبر نیست.', 'manahel-rewards'));
        }
        if ('referral.qualified' !== (string)($payload['event_name'] ?? '') || 1 !== (int)($payload['schema_version'] ?? 0)) {
            return new WP_Error('unsupported_referral_event', __('نسخه یا نام رویداد دعوت پشتیبانی نمی‌شود.', 'manahel-rewards'));
        }
        $role = sanitize_key((string)$role);
        if (!in_array($role, array('inviter','referred'), true)) {
            return new WP_Error('invalid_referral_role', __('نقش دریافت‌کننده دعوت معتبر نیست.', 'manahel-rewards'));
        }
        $occurred_at = Manahel_Rewards_DB::iso_to_mysql_utc($payload['occurred_at'] ?? '');
        $referral_id = sanitize_text_field((string)($payload['entity_id'] ?? ''));
        if (!$occurred_at || !$referral_id) {
            return new WP_Error('missing_referral_fields', __('فیلدهای الزامی دعوت کامل نیست.', 'manahel-rewards'));
        }
        $recipient = 'inviter' === $role ? (int)($payload['inviter_wp_user_id'] ?? 0) : (int)($payload['referred_wp_user_id'] ?? 0);
        if ($recipient <= 0) {
            return new WP_Error('missing_referral_recipient', __('دریافت‌کننده پاداش دعوت مشخص نیست.', 'manahel-rewards'));
        }
        $ctx = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $normalized = array(
            'source' => 'manahel_referral',
            'event_name' => 'referral.qualified',
            'schema_version' => 1,
            'occurred_at' => $occurred_at,
            'source_actor_user_id' => (int)($payload['inviter_wp_user_id'] ?? 0),
            'source_subject_user_id' => $recipient,
            'actor_ref' => '',
            'subject_ref' => '',
            'entity_type' => 'inviter' === $role ? 'referral_inviter' : 'referral_referred',
            'entity_id' => $referral_id,
            'campaign_id' => '',
            'context' => array(
                'role' => $role,
                'qualification_source' => sanitize_key((string)($ctx['qualification_source'] ?? '')),
                'qualification_event' => sanitize_key((string)($ctx['qualification_event'] ?? '')),
                'qualification_entity_type' => sanitize_key((string)($ctx['qualification_entity_type'] ?? '')),
                'content_type' => sanitize_key((string)($ctx['content_type'] ?? '')),
            ),
        );
        $normalized['dedupe_key'] = self::dedupe_key($normalized['source'], $normalized['event_name'], $normalized['entity_type'], $normalized['entity_id']);
        $normalized['payload_json'] = wp_json_encode(array(
            'event_name' => 'referral.qualified',
            'schema_version' => 1,
            'occurred_at' => gmdate('Y-m-d\\TH:i:s\\Z', strtotime($occurred_at . ' UTC')),
            'entity_type' => 'referral',
            'entity_id' => $referral_id,
            'inviter_wp_user_id' => (int)($payload['inviter_wp_user_id'] ?? 0),
            'referred_wp_user_id' => (int)($payload['referred_wp_user_id'] ?? 0),
            'context' => isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array(),
            'reward_role' => $role,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $normalized;
    }

    public static function referral_campaign_participant($payload) {
        if (!is_array($payload) || 'referral.campaign_participant' !== (string)($payload['event_name'] ?? '') || 1 !== (int)($payload['schema_version'] ?? 0)) {
            return new WP_Error('unsupported_campaign_referral_event', __('رویداد جذب مشارکت‌کننده معتبر نیست.', 'manahel-rewards'));
        }
        $occurred_at = Manahel_Rewards_DB::iso_to_mysql_utc($payload['occurred_at'] ?? '');
        $entity_id = sanitize_text_field((string)($payload['entity_id'] ?? ''));
        $inviter = (int)($payload['inviter_wp_user_id'] ?? 0);
        $participant = (int)($payload['participant_wp_user_id'] ?? 0);
        $campaign_id = sanitize_text_field((string)($payload['campaign_id'] ?? ''));
        if (!$occurred_at || !$entity_id || $inviter <= 0 || $participant <= 0 || !$campaign_id) {
            return new WP_Error('missing_campaign_referral_fields', __('فیلدهای جذب مشارکت‌کننده کامل نیست.', 'manahel-rewards'));
        }
        $ctx = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $normalized = array(
            'source'=>'manahel_referral',
            'event_name'=>'referral.campaign_participant',
            'schema_version'=>1,
            'occurred_at'=>$occurred_at,
            'source_actor_user_id'=>$inviter,
            'source_subject_user_id'=>$inviter,
            'actor_ref'=>'',
            'subject_ref'=>'',
            'entity_type'=>'campaign_referrer',
            'entity_id'=>$entity_id,
            'campaign_id'=>$campaign_id,
            'context'=>array(
                'role'=>'inviter',
                'participant_wp_user_id'=>$participant,
                'qualification_source'=>sanitize_key((string)($ctx['qualification_source'] ?? '')),
                'qualification_event'=>sanitize_key((string)($ctx['qualification_event'] ?? '')),
                'qualification_entity_type'=>sanitize_key((string)($ctx['qualification_entity_type'] ?? '')),
                'content_type'=>sanitize_key((string)($ctx['content_type'] ?? '')),
            ),
        );
        $normalized['dedupe_key'] = self::dedupe_key($normalized['source'], $normalized['event_name'], $normalized['entity_type'], $normalized['entity_id']);
        $normalized['payload_json'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $normalized;
    }

    public static function dedupe_key($source, $event_name, $entity_type, $entity_id) {
        $canonical = strtolower(trim((string)$source)) . '|' .
            strtolower(trim((string)$event_name)) . '|' .
            strtolower(trim((string)$entity_type)) . '|' .
            strtoupper(trim((string)$entity_id));
        return hash('sha256', $canonical);
    }

    public static function policy_exclusion_reason($event) {
        if (!is_array($event)) { return ''; }
        $content_type = isset($event['context']['content_type']) ? (string)$event['context']['content_type'] : '';
        if ('hamnoor' === ($event['source'] ?? '') &&
            'participation.completed' === ($event['event_name'] ?? '') &&
            'dhikr' === $content_type) {
            return 'policy_excluded:dhikr';
        }
        return '';
    }
}
