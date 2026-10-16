<?php
if (!defined('ABSPATH')) { exit; }

final class MQC_Repository {
    public function get_verse($surah, $ayah) {
        global $wpdb;
        $table = MQC_Installer::table('verses');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE surah=%d AND ayah=%d LIMIT 1", $surah, $ayah), ARRAY_A);
    }

    public function get_verse_by_index($index) {
        global $wpdb;
        $table = MQC_Installer::table('verses');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE global_index=%d LIMIT 1", $index), ARRAY_A);
    }

    public function get_range($start, $end, $limit = 500) {
        global $wpdb;
        $table = MQC_Installer::table('verses');
        $start = max(1, (int) $start); $end = max($start, (int) $end);
        $end = min($end, $start + max(1, min(500, (int) $limit)) - 1);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE global_index BETWEEN %d AND %d ORDER BY global_index ASC", $start, $end), ARRAY_A);
    }

    public function get_surah($surah) {
        global $wpdb;
        $table = MQC_Installer::table('surahs');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE surah_number=%d LIMIT 1", $surah), ARRAY_A);
    }

    public function get_division($type, $number) {
        global $wpdb;
        $table = MQC_Installer::table('divisions');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE division_type=%s AND division_number=%d LIMIT 1", sanitize_key($type), $number), ARRAY_A);
    }

    public function get_division_range($type, $number) {
        global $wpdb;
        $table = MQC_Installer::table('divisions');
        $current = $this->get_division($type, $number);
        if (!$current) { return null; }
        $next = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE division_type=%s AND division_number>%d ORDER BY division_number ASC LIMIT 1", sanitize_key($type), $number), ARRAY_A);
        $current['end_global_index'] = $next ? ((int)$next['start_global_index'] - 1) : 6236;
        return $current;
    }
}
