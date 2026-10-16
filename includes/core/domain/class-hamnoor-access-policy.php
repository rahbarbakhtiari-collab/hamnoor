<?php
if (!defined('ABSPATH')) { exit; }

/** Single server-side policy gateway for frontend Hamnoor actions. */
final class Hamnoor_Access_Policy {
    const LEVEL_LOW = 0;
    const LEVEL_TRUSTED = 1;
    const LEVEL_RECENT_OTP = 2;

    public static function required_level($action) {
        $action = sanitize_key((string)$action);
        $map = array(
            'campaign_view' => self::LEVEL_LOW,
            'claim_create' => self::LEVEL_LOW,
            'claim_complete' => self::LEVEL_LOW,
            'claim_release' => self::LEVEL_LOW,
            'personal_quran_create' => self::LEVEL_LOW,
            'personal_quran_lifecycle' => self::LEVEL_LOW,
            'campaign_create' => self::LEVEL_TRUSTED,
            'campaign_edit' => self::LEVEL_TRUSTED,
            'profile_name_change' => self::LEVEL_RECENT_OTP,
            'profile_mobile_change' => self::LEVEL_RECENT_OTP,
        );
        return isset($map[$action]) ? $map[$action] : self::LEVEL_RECENT_OTP;
    }

    public static function allows($action, $resource = null, $user_id = 0) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $user_id = $user_id ? (int)$user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id) { return false; }
        $required = self::required_level($action);
        if (!Hamnoor_Mobile_Access::has_assurance($required, $user_id)) { return false; }
        if (in_array(sanitize_key((string)$action), array('campaign_edit','personal_quran_lifecycle'), true) && $resource) {
            $campaign_id = is_object($resource) ? (int)$resource->id : (int)$resource;
            return Hamnoor_DB::user_can_manage_khatm($campaign_id, $user_id);
        }
        return true;
    }
}
