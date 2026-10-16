<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Thin repository layer for khatm aggregate persistence.
 *
 * This class intentionally delegates to the legacy DB class in 2.5.0 so the
 * schema, data and links from 2.4.x remain untouched while services can depend
 * on a stable repository contract.
 */
class Hamnoor_Khatm_Repository {
    public static function create($data) { return Hamnoor_DB::create_khatm($data); }
    public static function update($id, $data) { return Hamnoor_DB::update_khatm((int) $id, $data); }
    public static function find($id_or_token) { return Hamnoor_DB::get_khatm($id_or_token); }
    public static function query($args = array()) { return Hamnoor_DB::get_khatms($args); }
    public static function count($args = array()) { return Hamnoor_DB::count_khatms($args); }
    public static function progress($id) { return Hamnoor_DB::get_progress((int) $id); }
    public static function change_status($id, $status, $review_note = '', $context = 'auto') { return Hamnoor_DB::change_status((int) $id, $status, $review_note, $context); }
    public static function can_manage($id, $user_id = 0) { return Hamnoor_DB::user_can_manage_khatm((int) $id, (int) $user_id); }
}
