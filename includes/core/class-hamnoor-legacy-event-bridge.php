<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Maps the established 3.x WordPress hooks to canonical 4.x domain events.
 * The bridge remains intentionally one-way so 4.x events do not mutate legacy
 * write paths or create recursive domain actions.
 */
class Hamnoor_Legacy_Event_Bridge {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        add_action('hamnoor_khatm_created', array(__CLASS__, 'khatm_created'), 10, 2);
        add_action('hamnoor_khatm_published', array(__CLASS__, 'khatm_published'), 10, 2);
        add_action('hamnoor_khatm_completed', array(__CLASS__, 'khatm_completed'), 10, 1);
        add_action('hamnoor_part_claimed', array(__CLASS__, 'part_claimed'), 10, 3);
        add_action('hamnoor_part_completed', array(__CLASS__, 'part_completed'), 10, 3);
        add_action('hamnoor_part_released', array(__CLASS__, 'part_released'), 10, 4);
        add_action('hamnoor_reading_progress_saved', array(__CLASS__, 'reading_progressed'), 10, 4);
        add_action('hamnoor_after_participant_created', array(__CLASS__, 'participant_created'), 10, 3);
        add_action('hamnoor_after_participant_linked_to_user', array(__CLASS__, 'participant_linked'), 10, 3);
        add_action('hamnoor_notification_created', array(__CLASS__, 'notification_created'), 10, 2);
        add_action('hamnoor_notification_dispatched', array(__CLASS__, 'notification_dispatched'), 10, 3);
    }

    private static function emit($event, $payload, $legacy_hook) {
        Hamnoor_Event_Bus::instance()->emit($event, $payload, array('source' => 'legacy_bridge', 'legacy_hook' => $legacy_hook));
    }

    public static function khatm_created($khatm_id, $data = array()) {
        self::emit('khatm.created', array('khatm_id' => (int) $khatm_id, 'data' => is_array($data) ? $data : array()), 'hamnoor_khatm_created');
    }
    public static function khatm_published($khatm_id, $khatm = null) {
        self::emit('khatm.published', array('khatm_id' => (int) $khatm_id, 'khatm' => $khatm), 'hamnoor_khatm_published');
    }
    public static function khatm_completed($khatm_id) {
        self::emit('khatm.completed', array('khatm_id' => (int) $khatm_id), 'hamnoor_khatm_completed');
    }
    public static function part_claimed($part_id, $participant_id, $khatm_id) {
        self::emit('part.claimed', array('part_id' => (int) $part_id, 'participant_id' => (int) $participant_id, 'khatm_id' => (int) $khatm_id), 'hamnoor_part_claimed');
    }
    public static function part_completed($part_id, $participant_id, $khatm_id) {
        self::emit('part.completed', array('part_id' => (int) $part_id, 'participant_id' => (int) $participant_id, 'khatm_id' => (int) $khatm_id), 'hamnoor_part_completed');
    }
    public static function part_released($part_id, $khatm_id, $participant_id = 0, $reason = '') {
        self::emit('part.released', array('part_id' => (int) $part_id, 'participant_id' => (int) $participant_id, 'khatm_id' => (int) $khatm_id, 'reason' => (string) $reason), 'hamnoor_part_released');
    }
    public static function reading_progressed($part_id, $global_index, $percent, $user_id) {
        self::emit('reading.progressed', array('part_id' => (int) $part_id, 'global_index' => (int) $global_index, 'percent' => (float) $percent, 'user_id' => (int) $user_id), 'hamnoor_reading_progress_saved');
    }
    public static function participant_created($participant_id, $khatm_id, $data = array()) {
        self::emit('participant.created', array('participant_id' => (int) $participant_id, 'khatm_id' => (int) $khatm_id, 'data' => is_array($data) ? $data : array()), 'hamnoor_after_participant_created');
    }
    public static function participant_linked($participant_id, $user_id, $context = array()) {
        self::emit('participant.linked', array('participant_id' => (int) $participant_id, 'user_id' => (int) $user_id, 'context' => is_array($context) ? $context : array()), 'hamnoor_after_participant_linked_to_user');
    }
    public static function notification_created($notification_id, $user_id) {
        self::emit('notification.created', array('notification_id' => (int) $notification_id, 'user_id' => (int) $user_id), 'hamnoor_notification_created');
    }
    public static function notification_dispatched($notification, $channel_key, $delivery_id) {
        self::emit('notification.dispatched', array('notification' => $notification, 'channel' => sanitize_key((string) $channel_key), 'delivery_id' => (int) $delivery_id), 'hamnoor_notification_dispatched');
    }
}
