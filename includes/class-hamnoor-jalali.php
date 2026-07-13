<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Jalali/Gregorian conversion helpers for Hamnoor.
 * Internal storage remains WordPress DATETIME; UI may use Jalali dates.
 */
class Hamnoor_Jalali {
    public static function normalize($value) {
        $value = Hamnoor_Validator::normalize_digits((string) $value);
        $value = trim($value);
        $value = str_replace(array('\\', '.', '_'), '/', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }

    public static function parse_jalali_datetime($value, $end_of_day = false) {
        $value = self::normalize($value);
        if ('' === $value) { return ''; }
        if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?$/', $value, $m)) {
            $jy = (int) $m[1]; $jm = (int) $m[2]; $jd = (int) $m[3];
            $hh = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : ($end_of_day ? 23 : 0);
            $ii = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : ($end_of_day ? 59 : 0);
            if (!self::valid_jalali_date($jy, $jm, $jd) || $hh < 0 || $hh > 23 || $ii < 0 || $ii > 59) { return ''; }
            list($gy, $gm, $gd) = self::jalali_to_gregorian($jy, $jm, $jd);
            return sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $hh, $ii);
        }
        return '';
    }

    public static function to_jalali($datetime, $with_time = false) {
        if (!$datetime || '0000-00-00 00:00:00' === $datetime) { return ''; }
        $ts = strtotime((string) $datetime);
        if (!$ts) { return ''; }
        list($jy, $jm, $jd) = self::gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($with_time) { $out .= ' ' . date_i18n('H:i', $ts); }
        return $out;
    }

    public static function valid_jalali_date($jy, $jm, $jd) {
        if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1) { return false; }
        $days = ($jm <= 6) ? 31 : (($jm <= 11) ? 30 : (self::is_jalali_leap($jy) ? 30 : 29));
        return $jd <= $days;
    }

    public static function is_jalali_leap($jy) {
        $breaks = array(-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178);
        $bl = count($breaks); $gy = $jy + 621; $leap_j = -14; $jp = $breaks[0];
        if ($jy < $jp || $jy >= $breaks[$bl-1]) { return false; }
        for ($i = 1; $i < $bl; $i++) { $jm = $breaks[$i]; $jump = $jm - $jp; if ($jy < $jm) { break; } $leap_j += intdiv($jump,33)*8 + intdiv(($jump%33),4); $jp = $jm; }
        $n = $jy - $jp;
        $leap_j += intdiv($n,33)*8 + intdiv((($n%33)+3),4);
        if (($jump%33) == 4 && $jump - $n == 4) { $leap_j++; }
        $leap_g = intdiv($gy,4) - intdiv((intdiv($gy,100)+1)*3,4) - 150;
        $march = 20 + $leap_j - $leap_g;
        if ($jump - $n < 6) { $n = $n - $jump + intdiv($jump + 4,33)*33; }
        $leap = (($n + 1) % 33 - 1) % 4;
        if ($leap == -1) { $leap = 4; }
        return $leap === 0;
    }

    public static function jalali_to_gregorian($jy, $jm, $jd) {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy,33)*8 + intdiv((($jy % 33) + 3),4) + $jd;
        $days += ($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days,146097); $days %= 146097;
        if ($days > 36524) { $gy += 100 * intdiv(--$days,36524); $days %= 36524; if ($days >= 365) { $days++; } }
        $gy += 4 * intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days - 1,365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $sal_a = array(0,31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31);
        for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) { $gd -= $sal_a[$gm]; }
        return array($gy, $gm, $gd);
    }

    public static function gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = array(0,31,59,90,120,151,181,212,243,273,304,334);
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3,4) - intdiv($gy2 + 99,100) + intdiv($gy2 + 399,400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days,12053)); $days %= 12053;
        $jy += 4 * intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1,365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days,31); $jd = 1 + ($days % 31); }
        else { $jm = 7 + intdiv($days - 186,30); $jd = 1 + (($days - 186) % 30); }
        return array($jy, $jm, $jd);
    }
}
