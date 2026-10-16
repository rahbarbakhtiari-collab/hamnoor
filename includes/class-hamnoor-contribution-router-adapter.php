<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Direct-token routing adapter for contribution-ledger campaigns.
 *
 * Hamnoor_Router intentionally remains the canonical token resolver. This
 * adapter only replaces the final presentation boundary for repeatable/numeric
 * campaigns. Segmented Quran and every other route keep the established
 * renderer/template unchanged.
 */
final class Hamnoor_Contribution_Router_Adapter {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_filter('the_content', array(__CLASS__, 'filter_theme_content'), 30);
        // Core Router chooses its template at priority 10. Run afterwards and
        // replace only the contribution-ledger standalone view.
        add_filter('template_include', array(__CLASS__, 'template_include'), 20);
    }

    private static function current_campaign() {
        return !empty($GLOBALS['hamnoor_current_khatm']) && is_object($GLOBALS['hamnoor_current_khatm'])
            ? $GLOBALS['hamnoor_current_khatm']
            : null;
    }

    private static function is_contribution_campaign($campaign) {
        if (!$campaign) { return false; }
        return in_array((string)$campaign->participation_strategy, array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true);
    }

    private static function may_render($campaign) {
        return self::is_contribution_campaign($campaign)
            && empty($GLOBALS['hamnoor_not_found'])
            && empty($GLOBALS['hamnoor_unpublished_restricted']);
    }

    public static function filter_theme_content($content) {
        if (is_admin()) { return $content; }
        $router = Hamnoor_Router::instance();
        if (!$router->is_hamnoor_request() || 'theme' !== $router->render_mode()) { return $content; }
        $campaign = self::current_campaign();
        if (!self::may_render($campaign)) { return $content; }
        return Hamnoor_Frontend::instance()->wrap_public(Hamnoor_Contribution_Frontend::render($campaign));
    }

    public static function template_include($template) {
        $router = Hamnoor_Router::instance();
        if (!$router->is_hamnoor_request() || 'plugin' !== $router->render_mode()) { return $template; }
        $campaign = self::current_campaign();
        if (!self::may_render($campaign)) { return $template; }
        $contribution_template = Hamnoor_Helpers::locate_template('frontend/contribution-single.php');
        return $contribution_template ? $contribution_template : $template;
    }
}
