<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Loads first-party add-on bootstrap files behind a Throwable boundary.
 *
 * This is the first physical isolation step in 4.x. A broken optional add-on
 * bootstrap can be recorded and skipped while the Core continues booting.
 */
class Hamnoor_Builtin_Addon_Loader {
    private static $states = array();

    public static function load($id, $file) {
        $id = sanitize_key((string)$id);
        $file = (string)$file;
        if (!$id || !$file) { return false; }
        if (isset(self::$states[$id]) && 'loaded' === self::$states[$id]['status']) { return true; }
        try {
            if (!is_readable($file)) {
                throw new RuntimeException('Bootstrap file is not readable: ' . $file);
            }
            require_once $file;
            self::$states[$id] = array('status'=>'loaded','error'=>'','file'=>$file);
            return true;
        } catch (Throwable $error) {
            self::$states[$id] = array('status'=>'failed','error'=>$error->getMessage(),'file'=>$file);
            error_log(sprintf('Hamnoor built-in add-on %s load error: %s', $id, $error->getMessage()));
            if (function_exists('update_option')) {
                $errors = get_option('hamnoor_builtin_addon_errors', array());
                $errors = is_array($errors) ? $errors : array();
                $errors[$id] = array('message'=>substr(wp_strip_all_tags($error->getMessage()),0,500),'at'=>current_time('mysql'));
                update_option('hamnoor_builtin_addon_errors', $errors, false);
            }
            return false;
        }
    }

    public static function states() { return self::$states; }
}
