<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Verification policy boundary.
 *
 * v0.7 changes the product policy from verification-before-earning to
 * participation-first earning. Points may be earned by a resolved local user
 * without OTP. Verification is intentionally deferred to redemption/value
 * withdrawal policy, where a future redemption module can call the public API.
 */
final class Manahel_Rewards_Identity_Gate {
    const CONTRACT = 'earn_first_verify_on_redemption_v1';

    public static function evaluate($event, $wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        if (!is_array($event) || $wp_user_id <= 0) {
            return array('allowed'=>false, 'code'=>'identity_unresolved', 'contract'=>self::CONTRACT);
        }
        return array(
            'allowed'=>true,
            'code'=>'earning_verification_not_required',
            'contract'=>self::CONTRACT,
            'hamnoor_user_id'=>self::resolve_hamnoor_user_id($wp_user_id),
        );
    }

    /**
     * Future redemption/value-withdrawal modules should consult this policy.
     * Verification is opt-in per reward. High-value products may require it,
     * while ordinary redemption stays frictionless. Integrations can still
     * override the decision with the public filter.
     */
    public static function redemption_requires_verification($wp_user_id, $context = array()) {
        $context = is_array($context) ? $context : array();
        $required = !empty($context['requires_verification']);
        return (bool)apply_filters('manahel_rewards_redemption_requires_verification', $required, (int)$wp_user_id, $context);
    }

    private static function resolve_hamnoor_user_id($wp_user_id) {
        $meta_key = (class_exists('Hamnoor_WP_User_Bridge') && defined('Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID'))
            ? Hamnoor_WP_User_Bridge::META_HAMNOOR_USER_ID : '_hamnoor_user_id';
        return max(0, (int)get_user_meta((int)$wp_user_id, $meta_key, true));
    }
}
