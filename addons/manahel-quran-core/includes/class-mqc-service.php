<?php
if (!defined('ABSPATH')) { exit; }

final class MQC_Service {
    private static $instance;
    private $repository;
    public static function instance() {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() { $this->repository = new MQC_Repository(); }

    public function get_verse($surah, $ayah) { return $this->repository->get_verse((int)$surah, (int)$ayah); }
    public function get_verse_by_index($index) { return $this->repository->get_verse_by_index((int)$index); }
    public function get_global_verse_index($surah, $ayah) {
        $verse = $this->get_verse($surah, $ayah);
        return $verse ? (int)$verse['global_index'] : 0;
    }
    public function get_range_by_index($start, $end, $limit = 500) { return $this->repository->get_range($start, $end, $limit); }
    public function get_range($start_key, $end_key, $limit = 500) {
        $a = $this->parse_verse_key($start_key); $b = $this->parse_verse_key($end_key);
        if (!$a || !$b) { return new WP_Error('mqc_bad_key', 'شناسه آیه باید مانند 2:255 باشد.'); }
        $start = $this->get_global_verse_index($a[0], $a[1]);
        $end = $this->get_global_verse_index($b[0], $b[1]);
        if (!$start || !$end || $end < $start) { return new WP_Error('mqc_bad_range', 'محدوده آیات معتبر نیست.'); }
        return $this->get_range_by_index($start, $end, $limit);
    }
    public function get_surah($surah) { return $this->repository->get_surah((int)$surah); }
    public function get_division($type, $number) { return $this->repository->get_division_range($type, (int)$number); }
    public function get_division_verses($type, $number, $limit = 500) {
        $division = $this->get_division($type, $number);
        if (!$division) { return new WP_Error('mqc_division_missing', 'تقسیم‌بندی موردنظر پیدا نشد.'); }
        return $this->get_range_by_index($division['start_global_index'], $division['end_global_index'], $limit);
    }
    public function progress_percent($start_index, $end_index, $last_index) {
        $start=(int)$start_index; $end=(int)$end_index; $last=(int)$last_index;
        if ($end < $start || $start < 1) { return 0; }
        if ($last < $start) { return 0; }
        if ($last >= $end) { return 100; }
        return round((($last - $start + 1) / ($end - $start + 1)) * 100, 2);
    }
    private function parse_verse_key($key) {
        if (is_array($key) && count($key) === 2) { return array((int)$key[0], (int)$key[1]); }
        if (!is_string($key) || !preg_match('/^(\d{1,3}):(\d{1,3})$/', trim($key), $m)) { return null; }
        return array((int)$m[1], (int)$m[2]);
    }
}
