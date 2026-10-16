<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Eitaa App Mode for Hamnoor frontend pages.
 *
 * The signed Eitaa bridge issues a dedicated embedded session cookie. Presence
 * of that valid-format HttpOnly cookie only selects presentation/session
 * transport; authorization still comes from Hamnoor's normal server-side
 * session lookup and permissions.
 */
final class Hamnoor_Eitaa_App_Shell {
    const SESSION_COOKIE = 'hamnoor_eitaa_session';
    const COOKIE_CONTEXT = 'eitaa_cross_site';

    public static function init() {
        // Extend Core's provider-neutral embedded-session contract.
        add_filter('hamnoor_mobile_session_cookie_names', array(__CLASS__, 'session_cookie_names'));
        add_filter('hamnoor_mobile_cross_site_cookie_names', array(__CLASS__, 'cross_site_cookie_names'));
        add_filter('hamnoor_mobile_cookie_name_for_context', array(__CLASS__, 'cookie_name_for_context'), 10, 2);

        // Presentation and response isolation are active only while the Eitaa
        // embedded-session cookie is present on an actual Hamnoor frontend.
        add_filter('template_include', array(__CLASS__, 'template_include'), 1000);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_head', array(__CLASS__, 'print_official_sdk'), -1000);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 1000);
        add_filter('wp_headers', array(__CLASS__, 'filter_frame_headers'), 1000);
        add_action('send_headers', array(__CLASS__, 'send_frame_headers'), 1000);
        add_action('template_redirect', array(__CLASS__, 'protect_app_response'), -1000);
        add_action('template_redirect', array(__CLASS__, 'ensure_frame_headers_late'), 99999);
    }

    public static function session_cookie_names($names) {
        $names = is_array($names) ? $names : array();
        // Embedded identity must win over a stale normal website session.
        $names = array_values(array_diff($names, array(self::SESSION_COOKIE)));
        array_unshift($names, self::SESSION_COOKIE);
        return $names;
    }

    public static function cross_site_cookie_names($names) {
        $names = is_array($names) ? $names : array();
        if (!in_array(self::SESSION_COOKIE, $names, true)) { $names[] = self::SESSION_COOKIE; }
        return $names;
    }

    public static function cookie_name_for_context($name, $context) {
        return self::COOKIE_CONTEXT === (string)$context ? self::SESSION_COOKIE : $name;
    }

    /** UI context only. The session token itself is still validated by Core. */
    public static function is_app_context() {
        if (empty($_COOKIE[self::SESSION_COOKIE])) { return false; }
        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE]));
        return (bool)preg_match('/^[a-f0-9]{64}$/', $raw);
    }

    private static function is_hamnoor_frontend() {
        if (is_admin()) { return false; }
        if (class_exists('Hamnoor_Frontend') && Hamnoor_Frontend::instance()->is_shortcode_context()) { return true; }
        if (class_exists('Hamnoor_Router') && Hamnoor_Router::instance()->is_hamnoor_request()) { return true; }
        return false;
    }

    private static function is_shortcode_page() {
        return class_exists('Hamnoor_Frontend') && Hamnoor_Frontend::instance()->is_shortcode_context();
    }

    public static function template_include($template) {
        if (!self::is_app_context()) { return $template; }

        // Public campaign routes may be configured to use the site's theme for
        // ordinary web visitors. Inside Eitaa we always force Hamnoor's own
        // standalone campaign template so the site header/footer never return.
        if (class_exists('Hamnoor_Router') && Hamnoor_Router::instance()->is_hamnoor_request() && class_exists('Hamnoor_Helpers')) {
            $campaign_template = Hamnoor_Helpers::locate_template('frontend/khatm-single.php');
            if ($campaign_template) { return $campaign_template; }
        }

        if (!self::is_shortcode_page()) { return $template; }
        $app_template = HAMNOOR_EITAA_PATH . 'templates/eitaa-app-shell.php';
        return is_readable($app_template) ? $app_template : $template;
    }

    public static function body_class($classes) {
        $classes = is_array($classes) ? $classes : array();
        if (self::is_app_context() && self::is_hamnoor_frontend()) {
            $classes[] = 'hamnoor-eitaa-app-page';
        }
        return array_values(array_unique($classes));
    }

    /**
     * Eitaa explicitly requires the official SDK from developer.eitaa.com and
     * recommends placing it in <head> before application scripts.
     */
    public static function print_official_sdk() {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return; }
        echo "\n<script src=\"https://developer.eitaa.com/eitaa-web-app.js\"></script>\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
    }

    public static function enqueue_assets() {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return; }

        // Eitaa forces Hamnoor's standalone campaign template even when normal
        // web visitors use theme-compatible campaign rendering. Core therefore
        // does not enqueue this stylesheet in that configuration, so the forced
        // template would otherwise render its logo/navigation without layout CSS.
        if (class_exists('Hamnoor_Router') && Hamnoor_Router::instance()->is_hamnoor_request() && defined('HAMNOOR_URL')) {
            $standalone_version = class_exists('Hamnoor_Helpers') && method_exists('Hamnoor_Helpers', 'asset_version')
                ? Hamnoor_Helpers::asset_version('assets/css/hamnoor-standalone.css')
                : (defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : null);
            wp_enqueue_style(
                'hamnoor-standalone',
                HAMNOOR_URL . 'assets/css/hamnoor-standalone.css',
                array('hamnoor-frontend'),
                $standalone_version
            );
        }

        $css = HAMNOOR_EITAA_PATH . 'assets/css/hamnoor-eitaa-app-shell.css';
        $js = HAMNOOR_EITAA_PATH . 'assets/js/hamnoor-eitaa-app-shell.js';
        wp_enqueue_style(
            'hamnoor-eitaa-app-shell',
            HAMNOOR_EITAA_URL . 'assets/css/hamnoor-eitaa-app-shell.css',
            array(),
            is_readable($css) ? (string)filemtime($css) : HAMNOOR_EITAA_VERSION
        );
        wp_enqueue_script(
            'hamnoor-eitaa-app-shell',
            HAMNOOR_EITAA_URL . 'assets/js/hamnoor-eitaa-app-shell.js',
            array(),
            is_readable($js) ? (string)filemtime($js) : HAMNOOR_EITAA_VERSION,
            true
        );
        wp_localize_script('hamnoor-eitaa-app-shell', 'HamnoorEitaaApp', array(
            'dashboardUrl' => class_exists('Hamnoor_Helpers') ? Hamnoor_Helpers::page_url('dashboard') : home_url('/'),
            'bridgeUrl' => class_exists('Hamnoor_Eitaa_Bridge') ? Hamnoor_Eitaa_Bridge::url() : home_url('/hamnoor-eitaa/'),
        ));
    }

    public static function protect_app_response() {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        do_action('litespeed_control_set_nocache', 'Hamnoor Eitaa App Mode');
        self::apply_frame_headers();
    }

    public static function ensure_frame_headers_late() {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return; }
        self::apply_frame_headers();
    }

    public static function filter_frame_headers($headers) {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return $headers; }
        $headers = is_array($headers) ? $headers : array();
        foreach (array_keys($headers) as $name) {
            $lower = strtolower((string)$name);
            if ('x-frame-options' === $lower) { unset($headers[$name]); }
            if ('content-security-policy' === $lower) {
                $headers[$name] = self::csp_with_eitaa_frame_ancestors((string)$headers[$name]);
            }
        }
        $has_csp = false;
        foreach (array_keys($headers) as $name) {
            if ('content-security-policy' === strtolower((string)$name)) { $has_csp = true; break; }
        }
        if (!$has_csp) {
            $headers['Content-Security-Policy'] = self::frame_ancestors_directive();
        }
        return $headers;
    }

    public static function send_frame_headers() {
        if (!self::is_app_context() || !self::is_hamnoor_frontend()) { return; }
        self::apply_frame_headers();
    }

    /** Also used by the bridge before the embedded cookie exists. */
    public static function apply_frame_headers() {
        if (headers_sent()) { return; }
        header_remove('X-Frame-Options');

        $existing = '';
        foreach (headers_list() as $header_line) {
            if (0 === stripos($header_line, 'Content-Security-Policy:')) {
                $existing = trim(substr($header_line, strlen('Content-Security-Policy:')));
            }
        }
        header_remove('Content-Security-Policy');
        header('Content-Security-Policy: ' . self::csp_with_eitaa_frame_ancestors($existing), true);
    }

    private static function frame_ancestors_directive() {
        return "frame-ancestors 'self' https://eitaa.com https://*.eitaa.com";
    }

    private static function csp_with_eitaa_frame_ancestors($policy) {
        $directives = array();
        foreach (preg_split('/\s*;\s*/', trim((string)$policy), -1, PREG_SPLIT_NO_EMPTY) as $directive) {
            if (0 === stripos(trim($directive), 'frame-ancestors')) { continue; }
            $directives[] = trim($directive);
        }
        $directives[] = self::frame_ancestors_directive();
        return implode('; ', array_values(array_filter($directives)));
    }
}
