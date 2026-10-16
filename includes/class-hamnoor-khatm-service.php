<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Khatm_Service {
    public static function create($data) { return Hamnoor_Khatm_Repository::create($data); }
    public static function update($id, $data) { return Hamnoor_Khatm_Repository::update($id, $data); }
    public static function get($id_or_token) { return Hamnoor_Khatm_Repository::find($id_or_token); }
    public static function query($args = array()) { return Hamnoor_Khatm_Repository::query($args); }
    public static function count($args = array()) { return Hamnoor_Khatm_Repository::count($args); }
    public static function change_status($id, $status, $review_note = '', $context = 'auto') { return Hamnoor_Khatm_Repository::change_status($id, $status, $review_note, $context); }
    public static function progress($id) { return Hamnoor_Khatm_Repository::progress($id); }
}
