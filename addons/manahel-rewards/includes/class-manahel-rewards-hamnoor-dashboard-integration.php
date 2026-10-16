<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional UI bridge from Hamnoor's generic dashboard extension points to
 * Manahel Rewards' read-only projections and unified Growth Hub.
 */
final class Manahel_Rewards_Hamnoor_Dashboard_Integration {
    private static $summary_cache = array();

    public static function init() {
        add_action('hamnoor_dashboard_summary_extension', array(__CLASS__, 'render_summary_card'), 10, 2);
        add_filter('manahel_rewards_viewer_wp_user_id', array(__CLASS__, 'resolve_hamnoor_viewer'), 10, 1);

        // Hamnoor 4.0.9+ generic extension seam. Older Hamnoor releases simply
        // ignore these hooks, preserving backward compatibility.
        add_filter('hamnoor_dashboard_tabs', array(__CLASS__, 'register_dashboard_tab'), 10, 2);
        add_filter('hamnoor_dashboard_groups', array(__CLASS__, 'register_dashboard_group'), 10, 3);
        add_action('hamnoor_dashboard_extension_panels', array(__CLASS__, 'render_growth_panel'), 10, 2);
        add_action('hamnoor_mobile_more_extension', array(__CLASS__, 'render_mobile_more'), 10, 2);

        // Existing Referral surfaces keep working, but links rendered inside
        // Hamnoor now land on the unified Invite tab instead of a detached page.
        add_filter('manahel_referral_my_referrals_url', array(__CLASS__, 'referral_hub_url'), 20, 2);

        // When Rewards is active, merge the two optional dashboard teaser cards
        // into one lower-priority growth card. Referral remains independent when
        // Rewards is disabled.
        if (class_exists('Manahel_Referral_Hamnoor_Dashboard_Integration')) {
            remove_action('hamnoor_dashboard_summary_extension', array('Manahel_Referral_Hamnoor_Dashboard_Integration', 'render_summary_card'), 20);
        }
    }

    public static function resolve_hamnoor_viewer($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        $prefer_hamnoor = self::request_prefers_hamnoor_identity();
        if ($wp_user_id > 0 && !$prefer_hamnoor) { return $wp_user_id; }
        if (!class_exists('Hamnoor_Mobile_Access')) { return $wp_user_id; }

        $hamnoor_user = Hamnoor_Mobile_Access::current_user();
        if (!$hamnoor_user || empty($hamnoor_user->wp_user_id)) { return $wp_user_id; }
        $linked_wp_user_id = (int)$hamnoor_user->wp_user_id;
        return $linked_wp_user_id > 0 && get_user_by('id', $linked_wp_user_id) ? $linked_wp_user_id : $wp_user_id;
    }

    /**
     * The unified Hamnoor Growth Hub is a Hamnoor-domain surface. A WordPress
     * admin/editor cookie in the same browser must never shadow the active
     * Hamnoor session there. The POST marker keeps the same identity when the
     * reward form moves from the front end to admin-post.php.
     */
    private static function request_prefers_hamnoor_identity() {
        $context = '';
        if (isset($_POST['mr_context'])) {
            $context = sanitize_key(wp_unslash($_POST['mr_context']));
        } elseif (isset($_GET['mr_context'])) {
            $context = sanitize_key(wp_unslash($_GET['mr_context']));
        }
        if ('hamnoor' === $context) { return true; }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        return 'growth' === $tab;
    }

    public static function register_dashboard_tab($tabs, $user_id = 0) {
        $tabs = is_array($tabs) ? $tabs : array();
        if (!in_array('growth', $tabs, true)) { $tabs[] = 'growth'; }
        return $tabs;
    }

    public static function register_dashboard_group($groups, $hamnoor_user = null, $active_tab = '') {
        $groups = is_array($groups) ? $groups : array();
        $groups['growth'] = array(
            'label' => __('رشد و جوایز', 'manahel-rewards'),
            'description' => __('امتیاز، جایزه و دعوت دوستان', 'manahel-rewards'),
            'default' => 'growth',
            'tabs' => array(
                'growth' => array(
                    'label' => __('امتیاز، جوایز و دعوت', 'manahel-rewards'),
                    'href' => Manahel_Rewards_Frontend::hub_url(self::current_section()),
                ),
            ),
        );
        return $groups;
    }

    public static function render_growth_panel($hamnoor_user, $active_tab = 'summary') {
        $active = 'growth' === (string)$active_tab;
        ?>
        <section id="hamnoor-panel-growth" class="hamnoor-dashboard-panel <?php echo $active ? 'is-active' : ''; ?>" data-tab-panel="growth" role="tabpanel" aria-labelledby="hamnoor-tab-growth" <?php echo $active ? '' : 'hidden'; ?>>
            <span class="screen-reader-text" data-hamnoor-panel-title><?php esc_html_e('امتیاز و جوایز', 'manahel-rewards'); ?></span>
            <?php echo Manahel_Rewards_Frontend::render_growth_hub(self::current_section()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
        <?php
    }

    public static function render_mobile_more($hamnoor_user, $active_tab = 'summary') {
        ?>
        <section class="hamnoor-mobile-more-section" aria-labelledby="hamnoor-more-growth">
            <strong id="hamnoor-more-growth" class="hamnoor-mobile-more-section-title"><?php esc_html_e('امتیاز و جوایز', 'manahel-rewards'); ?></strong>
            <div class="hamnoor-mobile-more-grid">
                <a data-hamnoor-more-tab="growth" href="<?php echo esc_url(Manahel_Rewards_Frontend::hub_url('points')); ?>"><span>★</span><?php esc_html_e('امتیازهای من', 'manahel-rewards'); ?></a>
                <a data-hamnoor-more-tab="growth" href="<?php echo esc_url(Manahel_Rewards_Frontend::hub_url('rewards')); ?>"><span>🎁</span><?php esc_html_e('جوایز', 'manahel-rewards'); ?></a>
                <a data-hamnoor-more-tab="growth" href="<?php echo esc_url(Manahel_Rewards_Frontend::hub_url('invite')); ?>"><span>↗</span><?php esc_html_e('دعوت دوستان', 'manahel-rewards'); ?></a>
            </div>
        </section>
        <?php
    }

    public static function referral_hub_url($url, $page_id = 0) {
        if (class_exists('Hamnoor_Helpers') || shortcode_exists('hamnoor_dashboard')) {
            return Manahel_Rewards_Frontend::hub_url('invite');
        }
        return $url;
    }

    public static function render_summary_card($hamnoor_user, $surface = 'desktop') {
        if (!$hamnoor_user || !is_object($hamnoor_user) || empty($hamnoor_user->wp_user_id)) { return; }

        $wp_user_id = (int)$hamnoor_user->wp_user_id;
        if ($wp_user_id <= 0 || !get_user_by('id', $wp_user_id)) { return; }

        $surface = 'mobile' === (string)$surface ? 'mobile' : 'desktop';
        $summary = self::compact_summary($wp_user_id);
        if (is_wp_error($summary)) { return; }

        self::protect_personalized_output();
        if (!wp_style_is('manahel-rewards-frontend', 'registered')) {
            Manahel_Rewards_Frontend::register_assets();
        }
        wp_enqueue_style('manahel-rewards-frontend');

        $balance = (int)($summary['balance'] ?? 0);
        $today = (int)($summary['today_earned'] ?? 0);
        $successful = 0;
        if (function_exists('manahel_referral_get_compact_user_summary')) {
            $ref = manahel_referral_get_compact_user_summary($wp_user_id);
            if (!is_wp_error($ref) && is_array($ref)) { $successful = (int)($ref['successful'] ?? 0); }
        }
        $url = Manahel_Rewards_Frontend::hub_url('points');
        ?>
        <section class="mr-hamnoor-dashboard-card mr-hamnoor-dashboard-card--<?php echo esc_attr($surface); ?>" data-mr-balance="<?php echo esc_attr((string)$balance); ?>" data-mr-today-earned="<?php echo esc_attr((string)$today); ?>" dir="rtl" aria-label="<?php esc_attr_e('امتیاز، جوایز و دعوت', 'manahel-rewards'); ?>">
            <div class="mr-hamnoor-dashboard-card__main">
                <span class="mr-hamnoor-dashboard-card__eyebrow"><?php esc_html_e('امتیاز و جوایز', 'manahel-rewards'); ?></span>
                <div class="mr-hamnoor-dashboard-card__balance"><strong><?php echo esc_html(number_format_i18n($balance)); ?></strong><small><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></small></div>
                <?php if ($successful > 0) : ?><small><?php printf(esc_html__('%s دعوت موفق', 'manahel-rewards'), esc_html(number_format_i18n($successful))); ?></small><?php endif; ?>
            </div>
            <div class="mr-hamnoor-dashboard-card__today"><span><?php esc_html_e('امروز', 'manahel-rewards'); ?></span><strong>+<?php echo esc_html(number_format_i18n($today)); ?></strong></div>
            <a class="mr-hamnoor-dashboard-card__link" href="<?php echo esc_url($url); ?>"><?php esc_html_e('امتیاز، جوایز و دعوت', 'manahel-rewards'); ?> <span aria-hidden="true">←</span></a>
        </section>
        <?php
    }

    private static function current_section() {
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'points';
        return in_array($section, array('points','rewards','invite','history'), true) ? $section : 'points';
    }

    private static function compact_summary($wp_user_id) {
        $wp_user_id = (int)$wp_user_id;
        if (!array_key_exists($wp_user_id, self::$summary_cache)) {
            self::$summary_cache[$wp_user_id] = Manahel_Rewards_User_Summary_Service::get_compact_for_wp_user($wp_user_id);
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
