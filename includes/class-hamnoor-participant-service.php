<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Participant_Service {
    public static function get_by_khatm($khatm_id, $args = array()) { return Hamnoor_Participant_Repository::query($khatm_id, $args); }
    public static function count_by_khatm($khatm_id, $args = array()) { return Hamnoor_Participant_Repository::count($khatm_id, $args); }
    public static function get_parts_by_contact($contact) { return Hamnoor_Participant_Repository::parts_by_contact($contact); }
    public static function get_parts_by_user($user_id, $limit = 200, $offset = 0) { return Hamnoor_Participant_Repository::parts_by_user($user_id, $limit, $offset); }
    public static function sync_user($user_id) { return Hamnoor_Participant_Repository::sync_user($user_id); }
    public static function create_tracking_otp($contact) { return Hamnoor_Participant_Repository::create_tracking_otp($contact); }
}
