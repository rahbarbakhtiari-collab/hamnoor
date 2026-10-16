<?php
if (!defined('ABSPATH')) { exit; }

final class MQC_Installer {
    public static function table($name) {
        global $wpdb;
        $map = array(
            'verses' => $wpdb->prefix . 'mqc_verses',
            'surahs' => $wpdb->prefix . 'mqc_surahs',
            'divisions' => $wpdb->prefix . 'mqc_divisions',
        );
        return isset($map[$name]) ? $map[$name] : '';
    }

    public static function activate() {
        self::create_tables();
        self::import_metadata();
        update_option('mqc_db_version', MQC_DB_VERSION, false);
        if (false === get_option('mqc_import_offset', false)) {
            update_option('mqc_import_offset', 0, false);
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $verses = self::table('verses');
        $surahs = self::table('surahs');
        $divisions = self::table('divisions');

        dbDelta("CREATE TABLE {$verses} (
            global_index mediumint(8) unsigned NOT NULL,
            surah tinyint(3) unsigned NOT NULL,
            ayah smallint(3) unsigned NOT NULL,
            verse_key varchar(12) NOT NULL,
            text_arabic longtext NOT NULL,
            translation_makarem longtext NOT NULL,
            PRIMARY KEY  (global_index),
            UNIQUE KEY verse_key (verse_key),
            KEY surah_ayah (surah, ayah)
        ) {$charset};");

        dbDelta("CREATE TABLE {$surahs} (
            surah_number tinyint(3) unsigned NOT NULL,
            start_global_index mediumint(8) unsigned NOT NULL,
            ayah_count smallint(3) unsigned NOT NULL,
            revelation_order tinyint(3) unsigned NOT NULL,
            ruku_count smallint(3) unsigned NOT NULL,
            arabic_name varchar(80) NOT NULL,
            transliterated_name varchar(100) NOT NULL,
            english_name varchar(150) NOT NULL,
            revelation_type varchar(20) NOT NULL,
            PRIMARY KEY  (surah_number)
        ) {$charset};");

        dbDelta("CREATE TABLE {$divisions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            division_type varchar(24) NOT NULL,
            division_number smallint(5) unsigned NOT NULL,
            start_surah tinyint(3) unsigned NOT NULL,
            start_ayah smallint(3) unsigned NOT NULL,
            start_global_index mediumint(8) unsigned NOT NULL,
            extra_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY type_number (division_type, division_number),
            KEY start_index (start_global_index),
            KEY start_verse (start_surah, start_ayah)
        ) {$charset};");
    }

    public static function import_metadata() {
        global $wpdb;
        $file = MQC_DIR . 'data/metadata.json';
        if (!is_readable($file)) { return new WP_Error('mqc_metadata_missing', 'فایل متادیتای قرآن در دسترس نیست.'); }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || empty($data['surahs']) || empty($data['divisions'])) {
            return new WP_Error('mqc_metadata_invalid', 'ساختار فایل متادیتای قرآن معتبر نیست.');
        }
        $surahs = self::table('surahs');
        $divisions = self::table('divisions');
        foreach ($data['surahs'] as $row) {
            $wpdb->replace($surahs, array(
                'surah_number' => (int) $row['surah_number'],
                'start_global_index' => (int) $row['start_global_index'],
                'ayah_count' => (int) $row['ayah_count'],
                'revelation_order' => (int) $row['revelation_order'],
                'ruku_count' => (int) $row['ruku_count'],
                'arabic_name' => (string) $row['arabic_name'],
                'transliterated_name' => (string) $row['transliterated_name'],
                'english_name' => (string) $row['english_name'],
                'revelation_type' => (string) $row['revelation_type'],
            ), array('%d','%d','%d','%d','%d','%s','%s','%s','%s'));
        }
        foreach ($data['divisions'] as $row) {
            $wpdb->replace($divisions, array(
                'division_type' => sanitize_key($row['division_type']),
                'division_number' => (int) $row['division_number'],
                'start_surah' => (int) $row['start_surah'],
                'start_ayah' => (int) $row['start_ayah'],
                'start_global_index' => (int) $row['start_global_index'],
                'extra_json' => wp_json_encode(isset($row['extra']) ? $row['extra'] : array(), JSON_UNESCAPED_UNICODE),
            ), array('%s','%d','%d','%d','%d','%s'));
        }
        update_option('mqc_metadata_version', isset($data['version']) ? $data['version'] : '1.0', false);
        return true;
    }

    public static function import_verses_batch($offset = 0, $limit = 250) {
        global $wpdb;
        $offset = max(0, (int) $offset);
        $limit = max(10, min(500, (int) $limit));
        $file = MQC_DIR . 'data/verses.jsonl';
        if (!is_readable($file)) { return new WP_Error('mqc_data_missing', 'فایل متن و ترجمه قرآن در دسترس نیست.'); }
        $handle = fopen($file, 'rb');
        if (!$handle) { return new WP_Error('mqc_data_open', 'بازکردن فایل داده قرآن ممکن نشد.'); }
        $rows = array(); $line_no = 0;
        while (($line = fgets($handle)) !== false) {
            if ($line_no++ < $offset) { continue; }
            if (count($rows) >= $limit) { break; }
            $row = json_decode($line, true);
            if (!is_array($row)) { fclose($handle); return new WP_Error('mqc_json_invalid', 'یکی از ردیف‌های داده قرآن معتبر نیست.'); }
            $rows[] = $row;
        }
        $eof = feof($handle);
        fclose($handle);
        if (!$rows) {
            update_option('mqc_import_offset', 6236, false);
            update_option('mqc_data_version', '1.0.0', false);
            return array('imported' => 0, 'offset' => 6236, 'total' => 6236, 'done' => true);
        }
        $values = array();
        foreach ($rows as $row) {
            $values[] = $wpdb->prepare('(%d,%d,%d,%s,%s,%s)',
                (int) $row['global_index'], (int) $row['surah'], (int) $row['ayah'],
                (string) $row['verse_key'], (string) $row['text_arabic'], (string) $row['translation_makarem']
            );
        }
        $table = self::table('verses');
        $sql = "INSERT INTO {$table} (global_index,surah,ayah,verse_key,text_arabic,translation_makarem) VALUES " . implode(',', $values) .
               " ON DUPLICATE KEY UPDATE surah=VALUES(surah), ayah=VALUES(ayah), verse_key=VALUES(verse_key), text_arabic=VALUES(text_arabic), translation_makarem=VALUES(translation_makarem)";
        $result = $wpdb->query($sql);
        if (false === $result) { return new WP_Error('mqc_db_insert', 'ثبت داده قرآن در دیتابیس ناموفق بود: ' . $wpdb->last_error); }
        $new_offset = min(6236, $offset + count($rows));
        update_option('mqc_import_offset', $new_offset, false);
        $done = ($new_offset >= 6236) || ($eof && count($rows) < $limit);
        if ($done) { update_option('mqc_data_version', '1.0.0', false); }
        return array('imported' => count($rows), 'offset' => $new_offset, 'total' => 6236, 'done' => $done);
    }

    public static function reset_verse_import() {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::table('verses'));
        update_option('mqc_import_offset', 0, false);
        delete_option('mqc_data_version');
    }

    public static function status() {
        global $wpdb;
        return array(
            'verses' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('verses')),
            'surahs' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('surahs')),
            'divisions' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('divisions')),
            'offset' => (int) get_option('mqc_import_offset', 0),
            'data_version' => (string) get_option('mqc_data_version', ''),
        );
    }
}
