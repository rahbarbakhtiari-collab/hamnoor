<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Permissions {
    public static function new_khatm_creation_allowed($user_id = 0) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $settings = Hamnoor_Helpers::settings();
        return !isset($settings['new_khatm_creation_enabled']) || '1' === (string) $settings['new_khatm_creation_enabled'];
    }

    public static function can_create_khatm($user_id = 0) {
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $user_id = $user_id ? (int) $user_id : Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id || !Hamnoor_Mobile_Access::has_create_access($user_id)) { return false; }
        return true;
    }

    public static function can_manage_khatm($khatm_id, $user_id = 0) {
        return Hamnoor_DB::user_can_manage_khatm((int) $khatm_id, (int) $user_id);
    }

    public static function can_view_khatm($khatm, $user_id = 0) {
        if (!$khatm) { return false; }
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $user_id = $user_id ? (int)$user_id : Hamnoor_Mobile_Access::current_user_id();
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)($khatm->access_mode ?? '')) {
            return $user_id && (int)$khatm->owner_user_id === $user_id;
        }
        return self::can_preview_unpublished_khatm($khatm, $user_id);
    }

    public static function can_preview_unpublished_khatm($khatm, $user_id = 0) {
        if (!$khatm) { return false; }
        if (in_array((string)$khatm->status, array('active','paused','expired','completed','cancelled','archived'), true)) { return true; }
        if (current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        $user_id = $user_id ? (int)$user_id : Hamnoor_Mobile_Access::current_user_id();
        // Viewing one's own review result is not a mutating sensitive action.
        return $user_id && (int)$khatm->owner_user_id === $user_id;
    }

    public static function login_url($redirect = '') {
        return Hamnoor_Helpers::page_url('dashboard');
    }
}
