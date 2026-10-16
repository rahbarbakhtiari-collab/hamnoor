<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Stable, privacy-minimal domain-event contracts for external Hamnoor add-ons.
 *
 * This class deliberately does not call remote services, persist an event log,
 * or make reward decisions. It only announces business facts after the
 * authoritative state transition has already been persisted.
 */
final class Hamnoor_Domain_Events {
    const PARTICIPATION_COMPLETED = 'participation.completed';
    const PARTICIPATION_COMPLETED_SCHEMA_VERSION = 1;
    const IDENTITY_CREATED = 'identity.created';
    const IDENTITY_CREATED_SCHEMA_VERSION = 1;
    private static $request_completion_events = array();

    /**
     * Announce a newly-created Hamnoor identity after its authoritative user row
     * and WordPress bridge have both been persisted. This is a privacy-minimal
     * fact for optional local add-ons such as referral attribution. Existing
     * users never emit this event merely because they log in or are looked up.
     */
    public static function identity_created($user) {
        try {
            if (!$user || empty($user->id)) { return false; }
            $user_id = (int)$user->id;
            $public_id = !empty($user->public_id) ? sanitize_text_field((string)$user->public_id) : '';
            $payload = array(
                'event_name' => self::IDENTITY_CREATED,
                'schema_version' => self::IDENTITY_CREATED_SCHEMA_VERSION,
                'occurred_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'subject_user_id' => $user_id,
                'subject_public_id' => $public_id,
                'context' => array(
                    'identity_type' => 'hamnoor_user',
                ),
            );

            return Hamnoor_Event_Bus::instance()->emit(
                self::IDENTITY_CREATED,
                $payload,
                array(
                    'source' => 'domain_contract',
                    'schema_version' => self::IDENTITY_CREATED_SCHEMA_VERSION,
                    'emitted_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    'durable' => true,
                    'event_key' => self::IDENTITY_CREATED . ':v' . self::IDENTITY_CREATED_SCHEMA_VERSION . ':user:' . $user_id,
                )
            );
        } catch (Throwable $error) {
            // The identity has already been created. Optional listener failure
            // must never roll back or invalidate the persisted Hamnoor user.
            return self::record_failure($error, 'identity_created');
        }
    }

    /** Announce a newly completed segmented Quran share. */
    public static function quran_part_completed($part_id, $actor_user_id = 0) {
        try {
            $part = Hamnoor_DB::get_part((int)$part_id);
            if (!$part) { return false; }
            $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$part->khatm_id);
            if (!$campaign) { return false; }

            $subject_user_id = !empty($part->participant_user_id) ? (int)$part->participant_user_id : 0;
            return self::emit_participation_completed(array(
                'actor_user_id' => (int)$actor_user_id,
                'subject_user_id' => $subject_user_id,
                'entity_type' => 'quran_part',
                'entity_id' => self::stable_entity_id(isset($part->public_id) ? $part->public_id : '', 'part', (int)$part->id),
                'campaign_id' => self::stable_entity_id(isset($campaign->campaign_public_id) ? $campaign->campaign_public_id : '', 'campaign', (int)$campaign->id),
                'content_type' => Hamnoor_Campaign_Domain::CONTENT_QURAN,
                'participation_strategy' => Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED,
                'kind' => 'completion',
                'quantity' => 1,
            ));
        } catch (Throwable $error) {
            return self::record_failure($error, 'quran_part_completed');
        }
    }

    /** Announce a newly completed owner-only personal Quran completion. */
    public static function personal_quran_completed($campaign_id, $user_id) {
        try {
            $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id);
            if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) { return false; }
            $user_id = (int)$user_id;
            return self::emit_participation_completed(array(
                'actor_user_id' => $user_id,
                'subject_user_id' => $user_id,
                'entity_type' => 'personal_quran',
                // A personal-Quran participation is the campaign itself; there
                // is intentionally no separate claim/participation row.
                'entity_id' => self::stable_entity_id(isset($campaign->campaign_public_id) ? $campaign->campaign_public_id : '', 'campaign', (int)$campaign->id),
                'campaign_id' => self::stable_entity_id(isset($campaign->campaign_public_id) ? $campaign->campaign_public_id : '', 'campaign', (int)$campaign->id),
                'content_type' => Hamnoor_Campaign_Domain::CONTENT_QURAN,
                'participation_strategy' => Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR,
                'kind' => 'completion',
                'quantity' => 1,
            ));
        } catch (Throwable $error) {
            return self::record_failure($error, 'personal_quran_completed');
        }
    }

    /** Announce a newly-created valid Dua/Dhikr participation ledger row. */
    public static function contribution_completed($row, $campaign = null, $actor_user_id = 0) {
        try {
            if (!$row || empty($row->id) || 'valid' !== (string)$row->status) { return false; }
            if (!in_array((string)$row->kind, array('completion', 'numeric'), true)) { return false; }
            if (!$campaign) { $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$row->campaign_id); }
            if (!$campaign) { return false; }

            $subject_user_id = !empty($row->user_id) ? (int)$row->user_id : 0;
            if (!$actor_user_id) { $actor_user_id = $subject_user_id; }
            return self::emit_participation_completed(array(
                'actor_user_id' => (int)$actor_user_id,
                'subject_user_id' => $subject_user_id,
                'entity_type' => 'contribution',
                'entity_id' => self::stable_entity_id(isset($row->public_id) ? $row->public_id : '', 'contribution', (int)$row->id),
                'campaign_id' => self::stable_entity_id(isset($campaign->campaign_public_id) ? $campaign->campaign_public_id : '', 'campaign', (int)$campaign->id),
                'content_type' => sanitize_key((string)$campaign->content_type),
                'participation_strategy' => sanitize_key((string)$campaign->participation_strategy),
                'kind' => sanitize_key((string)$row->kind),
                'quantity' => (int)$row->quantity,
            ));
        } catch (Throwable $error) {
            return self::record_failure($error, 'contribution_completed');
        }
    }

    private static function emit_participation_completed($facts) {
        $actor_user_id = isset($facts['actor_user_id']) ? (int)$facts['actor_user_id'] : 0;
        $subject_user_id = isset($facts['subject_user_id']) ? (int)$facts['subject_user_id'] : 0;

        $payload = array(
            'event_name' => self::PARTICIPATION_COMPLETED,
            'schema_version' => self::PARTICIPATION_COMPLETED_SCHEMA_VERSION,
            'occurred_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'actor_user_id' => $actor_user_id,
            'subject_user_id' => $subject_user_id,
            'actor_public_id' => self::user_public_id($actor_user_id),
            'subject_public_id' => self::user_public_id($subject_user_id),
            'entity_type' => sanitize_key((string)$facts['entity_type']),
            'entity_id' => sanitize_text_field((string)$facts['entity_id']),
            'campaign_id' => sanitize_text_field((string)$facts['campaign_id']),
            'context' => array(
                'content_type' => sanitize_key((string)$facts['content_type']),
                'participation_strategy' => sanitize_key((string)$facts['participation_strategy']),
                'kind' => sanitize_key((string)$facts['kind']),
                'quantity' => (int)$facts['quantity'],
            ),
        );

        // Keep the stable contract privacy-minimal. No phone, email, IP,
        // tracking token, metadata_json, or full database objects are emitted.
        // A request-local copy lets transport adapters ask optional add-ons for
        // immediate feedback only when THIS request emitted a new completion.
        // Replays that do not emit a new domain fact therefore cannot display a
        // second reward toast. The canonical fact is journaled durably for
        // optional add-on recovery; request feedback itself is not persisted.
        self::$request_completion_events[self::request_event_key($payload['entity_type'], $payload['entity_id'])] = $payload;
        try {
            return Hamnoor_Event_Bus::instance()->emit(
                self::PARTICIPATION_COMPLETED,
                $payload,
                array(
                    'source' => 'domain_contract',
                    'schema_version' => self::PARTICIPATION_COMPLETED_SCHEMA_VERSION,
                    'emitted_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    'durable' => true,
                    'event_key' => self::PARTICIPATION_COMPLETED . ':v' . self::PARTICIPATION_COMPLETED_SCHEMA_VERSION . ':' . $payload['entity_type'] . ':' . $payload['entity_id'],
                )
            );
        } catch (Throwable $error) {
            // Completion has already committed. An extension/listener failure
            // must never invalidate the business fact that was just persisted.
            return self::record_failure($error, 'emit_participation_completed');
        }
    }

    /** Public stable-id helper for transport adapters; no private data is exposed. */
    public static function stable_entity_id($public_id, $entity_type, $internal_id) {
        $public_id = strtoupper(trim((string)$public_id));
        if ($public_id) { return $public_id; }
        // Numeric IDs are stable within this Hamnoor installation. This fallback
        // is only for a damaged/pre-backfill local record; healthy installs have
        // public IDs for all participating entities.
        return sanitize_key((string)$entity_type) . ':' . (int)$internal_id;
    }

    /**
     * Collect opaque, namespaced response feedback from optional add-ons.
     * Hamnoor does not interpret the payload and does not persist it.
     */
    public static function completion_extension_feedback($entity_type, $entity_id) {
        $key = self::request_event_key($entity_type, $entity_id);
        if (!$key || empty(self::$request_completion_events[$key])) { return array(); }
        $payload = self::$request_completion_events[$key];
        $feedback = apply_filters('hamnoor_completion_extension_feedback', array(), $payload);
        return is_array($feedback) ? $feedback : array();
    }

    private static function request_event_key($entity_type, $entity_id) {
        $entity_type = sanitize_key((string)$entity_type);
        $entity_id = trim((string)$entity_id);
        return ($entity_type && $entity_id) ? $entity_type . '|' . $entity_id : '';
    }

    private static function user_public_id($user_id) {
        $user_id = (int)$user_id;
        if (!$user_id || !class_exists('Hamnoor_Mobile_Access')) { return ''; }
        $user = Hamnoor_Mobile_Access::get_user($user_id);
        return ($user && !empty($user->public_id)) ? sanitize_text_field((string)$user->public_id) : '';
    }

    private static function record_failure($error, $context) {
        if ($error instanceof Throwable) {
            if (function_exists('hamnoor_record_runtime_error')) {
                hamnoor_record_runtime_error($error, 'domain_event_' . sanitize_key((string)$context));
            } else {
                error_log('[Hamnoor] Domain event error: ' . $error->getMessage());
            }
        }
        return false;
    }
}
