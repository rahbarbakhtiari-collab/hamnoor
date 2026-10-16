<?php
if (!defined('ABSPATH')) { exit; }

/** Frontend and scheduling adapter for Personal Quran reminders. */
final class Hamnoor_Personal_Reminders {
    const CRON_HOOK = 'hamnoor_personal_reminders';
    const CRON_INTERVAL = 'hamnoor_15_minutes';

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_due'));
        add_action('admin_post_hamnoor_save_personal_quran_reminder', array(__CLASS__, 'handle_save'));
        add_action('admin_post_nopriv_hamnoor_save_personal_quran_reminder', array(__CLASS__, 'handle_save'));
        add_action('admin_post_hamnoor_snooze_personal_quran_reminder', array(__CLASS__, 'handle_snooze'));
        add_action('admin_post_nopriv_hamnoor_snooze_personal_quran_reminder', array(__CLASS__, 'handle_snooze'));
        add_action('hamnoor_personal_quran_lifecycle_changed', array('Hamnoor_Reminder_Schedule_Service', 'lifecycle_changed'), 10, 4);
        add_action('hamnoor_personal_quran_completed', array('Hamnoor_Reminder_Schedule_Service', 'completed'), 10, 2);
        self::ensure_scheduled();
    }

    public static function cron_schedules($schedules) {
        $schedules = is_array($schedules) ? $schedules : array();
        if (!isset($schedules[self::CRON_INTERVAL])) {
            // wp_get_schedules() can be called while Hamnoor wires cron jobs on
            // plugins_loaded. Do not trigger JIT translation loading that early
            // on WordPress 6.7+; translate the human label once init/theme setup
            // has reached a safe localization boundary.
            $translations_ready = did_action('after_setup_theme') || did_action('init') || doing_action('init');
            $display = $translations_ready ? __('هر ۱۵ دقیقه', 'hamnoor') : 'هر ۱۵ دقیقه';
            $schedules[self::CRON_INTERVAL] = array('interval'=>15 * MINUTE_IN_SECONDS,'display'=>$display);
        }
        return $schedules;
    }

    public static function ensure_scheduled() {
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && isset($event->schedule) && self::CRON_INTERVAL !== (string)$event->schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $event = null;
        }
        if (!$event && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public static function run_due() {
        if (get_transient('hamnoor_personal_reminder_runner_lock')) { return array('locked'=>1); }
        set_transient('hamnoor_personal_reminder_runner_lock', '1', 10 * MINUTE_IN_SECONDS);
        try {
            return Hamnoor_Reminder_Schedule_Service::process_due(200);
        } finally {
            delete_transient('hamnoor_personal_reminder_runner_lock');
        }
    }

    public static function state($campaign) {
        if (!$campaign || !Hamnoor_Mobile_Access::is_logged_in()) { return null; }
        $state = Hamnoor_Reminder_Schedule_Service::personal_quran_state($campaign, (int)Hamnoor_Mobile_Access::current_user_id());
        return is_wp_error($state) ? null : $state;
    }

    public static function day_options() {
        // ISO weekday numbers are used internally. Persian visual order starts Saturday.
        return array(
            6=>__('شنبه', 'hamnoor'),
            7=>__('یکشنبه', 'hamnoor'),
            1=>__('دوشنبه', 'hamnoor'),
            2=>__('سه‌شنبه', 'hamnoor'),
            3=>__('چهارشنبه', 'hamnoor'),
            4=>__('پنجشنبه', 'hamnoor'),
            5=>__('جمعه', 'hamnoor'),
        );
    }

    public static function local_datetime_label($utc, $timezone) {
        $utc = trim((string)$utc);
        if (!$utc) { return ''; }
        try {
            $tz = new DateTimeZone(Hamnoor_Reminder_Schedule_Service::sanitize_timezone($timezone));
            $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz);
            return wp_date('Y-m-d H:i', $dt->getTimestamp(), $tz);
        } catch (Throwable $e) {
            return $utc;
        }
    }

    private static function current_campaign_from_post() {
        $campaign_id = isset($_POST['campaign_id']) ? absint(Hamnoor_Validator::normalize_digits(wp_unslash($_POST['campaign_id']))) : 0;
        $campaign = $campaign_id ? Hamnoor_Campaign_Repository::get_by_id($campaign_id) : null;
        $user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign) || !$user_id || (int)$campaign->owner_user_id !== $user_id) { return null; }
        return $campaign;
    }

    public static function handle_save() {
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { Hamnoor_Helpers::redirect_with_notice($dashboard, __('برای تنظیم یادآوری، پنل هم‌نور خود را باز کنید.', 'hamnoor'), 'error'); }
        $campaign = self::current_campaign_from_post();
        if (!$campaign) { Hamnoor_Helpers::redirect_with_notice($dashboard, __('ختم شخصی برای تنظیم یادآوری پیدا نشد.', 'hamnoor'), 'error'); }
        check_admin_referer('hamnoor_personal_quran_reminder_' . (int)$campaign->id);
        $input = array(
            'enabled'=>!empty($_POST['enabled']) ? 1 : 0,
            'local_time'=>isset($_POST['local_time']) ? sanitize_text_field(wp_unslash($_POST['local_time'])) : '',
            'timezone'=>isset($_POST['timezone']) ? sanitize_text_field(wp_unslash($_POST['timezone'])) : '',
            'days'=>isset($_POST['days']) ? (array)wp_unslash($_POST['days']) : array(),
        );
        $rate = Hamnoor_Helpers::rate_limit('personal_quran_reminder_save', 'u:' . (int)$campaign->owner_user_id, 20, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','reminders',$dashboard), $rate->get_error_message(), 'error'); }
        $saved = Hamnoor_Reminder_Schedule_Service::save_personal_quran($campaign, (int)$campaign->owner_user_id, $input);
        if (is_wp_error($saved)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','reminders',$dashboard), $saved->get_error_message(), 'error'); }
        $message = !empty($saved['enabled']) ? __('یادآوری ختم شخصی ذخیره و فعال شد.', 'hamnoor') : __('یادآوری ختم شخصی خاموش شد.', 'hamnoor');
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','reminders',$dashboard), $message, 'success');
    }

    public static function handle_snooze() {
        $dashboard = Hamnoor_Helpers::page_url('dashboard');
        if (!Hamnoor_Mobile_Access::is_logged_in()) { Hamnoor_Helpers::redirect_with_notice($dashboard, __('برای تعویق یادآوری، پنل هم‌نور خود را باز کنید.', 'hamnoor'), 'error'); }
        $campaign = self::current_campaign_from_post();
        if (!$campaign) { Hamnoor_Helpers::redirect_with_notice($dashboard, __('ختم شخصی برای تعویق یادآوری پیدا نشد.', 'hamnoor'), 'error'); }
        check_admin_referer('hamnoor_personal_quran_reminder_snooze_' . (int)$campaign->id);
        $days = isset($_POST['days']) ? (int)Hamnoor_Validator::normalize_digits(wp_unslash($_POST['days'])) : 1;
        if (!in_array($days, array(1,3,7), true)) { $days = 1; }
        $saved = Hamnoor_Reminder_Schedule_Service::snooze_personal_quran($campaign, (int)$campaign->owner_user_id, $days);
        if (is_wp_error($saved)) { Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','reminders',$dashboard), $saved->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab','reminders',$dashboard), sprintf(__('یادآوری برای %d روز به تعویق افتاد.', 'hamnoor'), $days), 'success');
    }
}
