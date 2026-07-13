<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Part_Repository {
    public static function find($part_id) { return Hamnoor_DB::get_part((int) $part_id); }
    public static function query($khatm_id, $status = '', $args = array()) { return Hamnoor_DB::get_parts((int) $khatm_id, $status, $args); }
    public static function count($khatm_id, $status = '', $args = array()) { return Hamnoor_DB::count_parts((int) $khatm_id, $status, $args); }
    public static function claim($part_id, $data) { return Hamnoor_DB::claim_part((int) $part_id, $data); }
    public static function claim_next($khatm_id, $data, $round_number = 0) { return Hamnoor_DB::claim_next_available_part((int) $khatm_id, $data, (int) $round_number); }
    public static function claim_random($khatm_id, $data, $round_number = 0) { return Hamnoor_DB::claim_random_available_part((int) $khatm_id, $data, (int) $round_number); }
    public static function complete($part_id, $token = '', $force = false) { return Hamnoor_DB::complete_part((int) $part_id, $token, (bool) $force); }
    public static function complete_by_contact($part_id, $contact) { return Hamnoor_DB::complete_part_by_contact((int) $part_id, $contact); }
}
