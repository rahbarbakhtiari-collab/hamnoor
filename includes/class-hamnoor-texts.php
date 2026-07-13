<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Texts {
    public static function defaults() {
        $file = HAMNOOR_PATH . 'includes/hamnoor-default-texts.php';
        $texts = file_exists($file) ? include $file : array();
        return is_array($texts) ? $texts : array();
    }

    public static function all() {
        $custom = get_option('hamnoor_texts', array());
        return wp_parse_args(is_array($custom) ? $custom : array(), self::defaults());
    }

    public static function get($key, $replacements = array()) {
        $texts = self::all();
        $value = isset($texts[$key]) ? (string) $texts[$key] : (isset(self::defaults()[$key]) ? (string) self::defaults()[$key] : '');
        if (!empty($replacements) && is_array($replacements)) {
            foreach ($replacements as $k => $v) {
                $value = str_replace('{' . strtoupper((string) $k) . '}', (string) $v, $value);
                $value = str_replace('{' . (string) $k . '}', (string) $v, $value);
            }
        }
        return $value;
    }

    public static function update($data) {
        $defaults = self::defaults();
        $custom = array();
        foreach ($defaults as $key => $default) {
            if (isset($data[$key])) {
                $custom[$key] = wp_kses_post(wp_unslash($data[$key]));
            }
        }
        update_option('hamnoor_texts', $custom, false);
        return $custom;
    }

    public static function reset() {
        delete_option('hamnoor_texts');
    }
}
