<?php
if (!defined('ABSPATH')) { exit; }

/** Optional presentation bridge using Hamnoor's already-verified generic hook. */
final class Manahel_Referral_Hamnoor_Dashboard_Integration {
    private static $summary_cache = array();

    public static function init() {
        add_action('hamnoor_dashboard_summary_extension', array(__CLASS__, 'render_summary_card'), 20, 2);
        add_filter('manahel_referral_viewer_wp_user_id', array(__CLASS__, 'resolve_hamnoor_viewer'), 10, 1);
    }

    public static function resolve_hamnoor_viewer($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        $prefer_hamnoor = self::request_prefers_hamnoor_identity();
        if ($wp_user_id > 0 && !$prefer_hamnoor) { return $wp_user_id; }
        if (!class_exists('Hamnoor_Mobile_Access') || !method_exists('Hamnoor_Mobile_Access', 'current_user')) { return $wp_user_id; }
        $hamnoor_user = Hamnoor_Mobile_Access::current_user();
        if (!$hamnoor_user || empty($hamnoor_user->wp_user_id)) { return $wp_user_id; }
        $linked_wp_user_id = (int)$hamnoor_user->wp_user_id;
        return $linked_wp_user_id > 0 && get_user_by('id', $linked_wp_user_id) ? $linked_wp_user_id : $wp_user_id;
    }

    private static function request_prefers_hamnoor_identity() {
        $context = '';
        if (isset($_POST['mref_context'])) { $context = sanitize_key(wp_unslash($_POST['mref_context'])); }
        elseif (isset($_GET['mref_context'])) { $context = sanitize_key(wp_unslash($_GET['mref_context'])); }
        if ('hamnoor' === $context) { return true; }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        return 'growth' === $tab;
    }

    public static function render_summary_card($hamnoor_user, $surface = 'desktop') {
        if (!$hamnoor_user || !is_object($hamnoor_user) || empty($hamnoor_user->wp_user_id)) { return; }
        $wp_user_id = (int)$hamnoor_user->wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) { return; }

        $summary = self::compact_summary($wp_user_id);
        if (is_wp_error($summary)) { return; }
        $surface = 'mobile' === (string)$surface ? 'mobile' : 'desktop';
        self::protect_personalized_output();
        if (!wp_style_is('manahel-referral-frontend', 'registered')) { Manahel_Referral_Frontend::register_assets(); }
        wp_enqueue_style('manahel-referral-frontend');

        $url = Manahel_Referral_Frontend::my_referrals_url();
        if ($url) { $url = add_query_arg('mref_context', 'hamnoor', $url); }
        $successful = (int)($summary['successful'] ?? 0);
        $pending = (int)($summary['pending'] ?? 0);
        $earned = isset($summary['earned_points']) ? (int)$summary['earned_points'] : null;
        ?>
        <section class="mrf-hamnoor-card mrf-hamnoor-card--<?php echo esc_attr($surface); ?>" dir="rtl" aria-label="<?php esc_attr_e('خلاصه دعوت دوستان', 'manahel-referral'); ?>">
            <div class="mrf-hamnoor-card__main">
                <span class="mrf-hamnoor-card__eyebrow"><?php esc_html_e('دعوت دوستان', 'manahel-referral'); ?></span>
                <strong><?php printf(esc_html__('%s دعوت موفق', 'manahel-referral'), esc_html(number_format_i18n($successful))); ?></strong>
                <?php if ($pending > 0) : ?><small><?php printf(esc_html__('%s دعوت در انتظار تکمیل فعالیت', 'manahel-referral'), esc_html(number_format_i18n($pending))); ?></small><?php endif; ?>
            </div>
            <?php if (null !== $earned) : ?>
                <div class="mrf-hamnoor-card__points"><span><?php esc_html_e('امتیاز دعوت', 'manahel-referral'); ?></span><strong>+<?php echo esc_html(number_format_i18n($earned)); ?></strong></div>
            <?php endif; ?>
            <?php if ($url) : ?><a class="mrf-hamnoor-card__link" href="<?php echo esc_url($url); ?>"><?php esc_html_e('دعوت از دوستان', 'manahel-referral'); ?> <span aria-hidden="true">←</span></a><?php endif; ?>
        </section>
        <?php
    }

    private static function compact_summary($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        if (!array_key_exists($wp_user_id, self::$summary_cache)) {
            self::$summary_cache[$wp_user_id] = Manahel_Referral_User_Summary_Service::get_compact_for_wp_user($wp_user_id);
        }
        return self::$summary_cache[$wp_user_id];
    }

    private static function protect_personalized_output() {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'protect_session_from_page_cache')) {
            Hamnoor_Mobile_Access::protect_session_from_page_cache();
        }
        if (!headers_sent()) { nocache_headers(); }
    }
}
