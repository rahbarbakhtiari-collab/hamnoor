<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Participant_Repository {
    public static function query($khatm_id, $args = array()) { return Hamnoor_DB::get_participants((int) $khatm_id, $args); }
    public static function count($khatm_id, $args = array()) { return Hamnoor_DB::count_participants((int) $khatm_id, $args); }
    public static function parts_by_contact($contact, $khatm_id = 0, $limit = 200) { return Hamnoor_DB::get_parts_by_contact($contact, (int) $khatm_id, (int) $limit); }
    public static function parts_by_user($user_id, $limit = 200, $offset = 0) { return Hamnoor_DB::get_parts_by_user((int) $user_id, (int) $limit, (int) $offset); }
    public static function sync_user($user_id) { return Hamnoor_DB::sync_guest_history_to_user((int) $user_id); }
    public static function create_tracking_otp($contact) { return Hamnoor_DB::create_otp($contact, 'track'); }
}
