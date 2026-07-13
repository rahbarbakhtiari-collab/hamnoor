<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Part_Service {
    public static function claim($part_id, $data) { return Hamnoor_Part_Repository::claim($part_id, $data); }
    public static function claim_next($khatm_id, $data, $round_number = 0) { return Hamnoor_DB::claim_next_available_part((int) $khatm_id, $data, (int) $round_number); }
    public static function claim_random($khatm_id, $data, $round_number = 0) { return Hamnoor_Part_Repository::claim_random($khatm_id, $data, $round_number); }
    public static function complete($part_id, $token = '', $force = false) { return Hamnoor_Part_Repository::complete($part_id, $token, $force); }
    public static function complete_by_contact($part_id, $contact) { return Hamnoor_Part_Repository::complete_by_contact($part_id, $contact); }
    public static function get_by_khatm($khatm_id, $status = '', $args = array()) { return Hamnoor_Part_Repository::query($khatm_id, $status, $args); }
    public static function count_by_khatm($khatm_id, $status = '', $args = array()) { return Hamnoor_Part_Repository::count($khatm_id, $status, $args); }
    public static function get($part_id) { return Hamnoor_Part_Repository::find($part_id); }
}
