<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Permissions {
    public static function new_khatm_creation_allowed($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ($user_id && (user_can($user_id, 'manage_options') || user_can($user_id, 'hamnoor_manage_all_khatms'))) {
            return true;
        }
        $settings = Hamnoor_Helpers::settings();
        return !isset($settings['new_khatm_creation_enabled']) || '1' === (string) $settings['new_khatm_creation_enabled'];
    }

    public static function can_create_khatm($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'hamnoor_manage_all_khatms')) {
            return true;
        }
        $settings = Hamnoor_Helpers::settings();
        $policy = isset($settings['creation_policy']) ? $settings['creation_policy'] : 'approved';
        if ('all' === $policy) {
            return user_can($user_id, 'read');
        }
        if ('admins' === $policy) {
            return false;
        }
        return user_can($user_id, 'hamnoor_create_khatm') || user_can($user_id, 'hamnoor_manage_own_khatm');
    }

    public static function can_manage_khatm($khatm_id, $user_id = 0) {
        return Hamnoor_DB::user_can_manage_khatm((int) $khatm_id, (int) $user_id);
    }

    public static function can_preview_unpublished_khatm($khatm, $user_id = 0) {
        if (!$khatm || 'pending_review' !== (string) $khatm->status) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) { return false; }
        if ((int) $khatm->owner_user_id === $user_id) { return true; }
        return Hamnoor_DB::user_can_manage_khatm((int) $khatm->id, $user_id);
    }

    public static function login_url($redirect = '') {
        $url = Hamnoor_Helpers::page_url('login');
        if ($redirect) {
            $url = add_query_arg('redirect_to', rawurlencode($redirect), $url);
        }
        return $url;
    }
}
