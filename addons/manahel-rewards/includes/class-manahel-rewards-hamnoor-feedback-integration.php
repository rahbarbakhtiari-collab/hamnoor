<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional response-time UX bridge for Hamnoor completion actions.
 *
 * Financial truth is already committed by the normal event adapter before this
 * filter runs. This class only exposes a privacy-minimal, request-local summary
 * for a completion event that Hamnoor actually emitted in the same request.
 */
final class Manahel_Rewards_Hamnoor_Feedback_Integration {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_filter('hamnoor_completion_extension_feedback', array(__CLASS__, 'append_feedback'), 20, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 40);
    }

    public static function enqueue_assets() {
        if (is_admin() || !function_exists('hamnoor_core_api')) { return; }
        if (!wp_style_is('manahel-rewards-frontend', 'registered')) {
            Manahel_Rewards_Frontend::register_assets();
        }
        wp_enqueue_style('manahel-rewards-frontend');
        wp_enqueue_script(
            'manahel-rewards-feedback',
            MANAHEL_REWARDS_URL . 'assets/js/reward-feedback.js',
            array(),
            MANAHEL_REWARDS_VERSION,
            true
        );
    }

    public static function append_feedback($feedback, $payload) {
        $feedback = is_array($feedback) ? $feedback : array();
        if (!is_array($payload) || 'participation.completed' !== (string)($payload['event_name'] ?? '')) {
            return $feedback;
        }

        $actor_user_id = (int)($payload['actor_user_id'] ?? 0);
        $subject_user_id = (int)($payload['subject_user_id'] ?? 0);
        if ($actor_user_id !== $subject_user_id) {
            return $feedback;
        }

        $record = Manahel_Rewards_Hamnoor_Adapter::request_result_for_payload($payload);
        if (!is_array($record) || !array_key_exists('result', $record)) { return $feedback; }
        $result = $record['result'];
        if (is_wp_error($result) || !is_array($result) || !empty($result['duplicate'])) { return $feedback; }

        $points = (int)($result['points'] ?? 0);
        $decision = isset($result['decision']) && is_array($result['decision']) ? $result['decision'] : array();
        $decision_code = (string)($decision['decision_code'] ?? ($result['reason'] ?? ''));

        // Dhikr, disabled/no-rule policies and technical failures stay silent.
        // Identity verification is different: it is an actionable requirement
        // that must be explained before users assume points were lost. Hamnoor's
        // completion success remains authoritative and independent.
        $allowed_zero_codes = array('limit.daily_awards', 'limit.daily_points', 'limit.monthly_awards', 'limit.monthly_points', 'limit.cooldown', 'limit.unique_entity');
        if ($points <= 0 && !in_array($decision_code, $allowed_zero_codes, true)) { return $feedback; }

        $event_id = (int)($result['event_id'] ?? 0);
        $balance = array_key_exists('balance', $result) ? (int)$result['balance'] : null;
        $awarded = $points > 0;
        $message = $awarded ? self::awarded_message($payload) : self::limited_message($decision_code);

        $feedback['manahel_rewards'] = array(
            'version' => 1,
            'feedback_id' => $event_id > 0 ? 'mr-event-' . $event_id : '',
            'announce' => 1,
            'status' => $awarded ? 'awarded' : 'limited',
            'tone' => $awarded ? 'success' : 'info',
            'points' => $points,
            'points_display' => number_format_i18n($points),
            'balance' => $balance,
            'balance_display' => null !== $balance ? number_format_i18n($balance) : '',
            'decision_code' => sanitize_text_field($decision_code),
            'title' => $awarded
                ? sprintf(__('+%s امتیاز', 'manahel-rewards'), number_format_i18n($points))
                : __('امتیازی اضافه نشد', 'manahel-rewards'),
            'message' => $message,
        );
        return $feedback;
    }

    private static function awarded_message($payload) {
        $entity_type = (string)($payload['entity_type'] ?? '');
        $content_type = (string)($payload['context']['content_type'] ?? '');
        if ('quran_part' === $entity_type) {
            return __('برای تکمیل سهم قرآن به امتیازهای شما اضافه شد.', 'manahel-rewards');
        }
        if ('personal_quran' === $entity_type) {
            return __('برای تکمیل ختم شخصی قرآن به امتیازهای شما اضافه شد.', 'manahel-rewards');
        }
        if ('dua' === $content_type) {
            return __('برای تکمیل دعا به امتیازهای شما اضافه شد.', 'manahel-rewards');
        }
        return __('امتیاز این فعالیت به حساب شما اضافه شد.', 'manahel-rewards');
    }

    private static function limited_message($code) {
        $map = array(
            'limit.daily_awards' => __('سقف دفعات امتیاز امروز این فعالیت تکمیل شده است.', 'manahel-rewards'),
            'limit.daily_points' => __('سقف امتیاز امروز این فعالیت تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_awards' => __('سقف دفعات امتیاز این ماه تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_points' => __('سقف امتیاز این ماه تکمیل شده است.', 'manahel-rewards'),
            'limit.cooldown' => __('هنوز زمان دریافت مجدد این پاداش نرسیده است.', 'manahel-rewards'),
            'limit.unique_entity' => __('این مشارکت قبلاً امتیاز گرفته است.', 'manahel-rewards'),
        );
        return $map[$code] ?? __('برای این فعالیت امتیازی اضافه نشد.', 'manahel-rewards');
    }
}
