<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Frontend {
    const OPTION_MY_POINTS_PAGE_ID = 'manahel_rewards_my_points_page_id';
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_shortcode('manahel_rewards_my_points', array(__CLASS__, 'shortcode_my_points'));
        add_shortcode('manahel_rewards_balance', array(__CLASS__, 'shortcode_balance'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('template_redirect', array(__CLASS__, 'protect_growth_route_from_cache'), 0);
    }

    public static function protect_growth_route_from_cache() {
        if (is_admin()) { return; }
        $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'';
        if ('growth'!==$tab) { return; }
        self::protect_personalized_page();
    }

    public static function register_assets() {
        wp_register_style(
            'manahel-rewards-frontend',
            MANAHEL_REWARDS_URL . 'assets/css/frontend.css',
            array(),
            MANAHEL_REWARDS_VERSION
        );
        wp_register_script(
            'manahel-rewards-catalog',
            MANAHEL_REWARDS_URL . 'assets/js/reward-catalog.js',
            array(),
            MANAHEL_REWARDS_VERSION,
            true
        );

        // Enqueue in wp_head for the known shortcode surfaces. Rendering methods
        // still enqueue defensively for nonstandard builders/embedding paths.
        if (is_singular()) {
            $post = get_post();
            $content = $post && isset($post->post_content) ? (string)$post->post_content : '';
            if ($content && (has_shortcode($content, 'manahel_rewards_my_points') || has_shortcode($content, 'hamnoor_dashboard'))) {
                wp_enqueue_style('manahel-rewards-frontend');
            }
        }
    }

    public static function viewer_wp_user_id() {
        $wp_user_id = (int)get_current_user_id();
        return max(0, (int)apply_filters('manahel_rewards_viewer_wp_user_id', $wp_user_id));
    }

    public static function my_points_url() {
        // Inside Hamnoor, Rewards is a first-class dashboard destination rather
        // than a detached WordPress page. Keep the legacy configured page only
        // as a compatibility fallback when Hamnoor is unavailable.
        if (class_exists('Hamnoor_Helpers') || shortcode_exists('hamnoor_dashboard')) {
            $url = self::hub_url('points');
            return (string)apply_filters('manahel_rewards_my_points_url', $url, 0);
        }
        $page_id = (int)get_option(self::OPTION_MY_POINTS_PAGE_ID, 0);
        $url = '';
        if ($page_id > 0 && 'publish' === get_post_status($page_id) && 'page' === get_post_type($page_id)) {
            $url = (string)get_permalink($page_id);
        }
        return (string)apply_filters('manahel_rewards_my_points_url', $url, $page_id);
    }

    public static function hub_url($section = 'points') {
        $allowed = array('points','rewards','invite','history');
        $section = sanitize_key((string)$section);
        if (!in_array($section, $allowed, true)) { $section = 'points'; }
        return (string)add_query_arg(array('tab'=>'growth','section'=>$section), home_url('/'));
    }

    public static function render_growth_hub($section = '') {
        self::protect_personalized_page();
        if (!wp_style_is('manahel-rewards-frontend', 'registered')) { self::register_assets(); }
        wp_enqueue_style('manahel-rewards-frontend');
        wp_enqueue_script('manahel-rewards-catalog');

        $section = $section ? sanitize_key((string)$section) : (isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'points');
        if (!in_array($section, array('points','rewards','invite','history'), true)) { $section = 'points'; }
        $wp_user_id = self::viewer_wp_user_id();
        if ($wp_user_id <= 0) { return self::guest_markup(); }

        ob_start(); ?>
        <div class="mr-growth-hub" dir="rtl" data-mr-growth-section="<?php echo esc_attr($section); ?>">
            <?php echo self::growth_tabs_markup($section); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="mr-growth-hub__content">
                <?php if ('invite' === $section) : ?>
                    <?php if (class_exists('Manahel_Referral_Frontend')) : ?>
                        <?php echo Manahel_Referral_Frontend::shortcode_my_referrals(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php else : ?>
                        <div class="mr-empty"><strong><?php esc_html_e('دعوت دوستان', 'manahel-rewards'); ?></strong><p><?php esc_html_e('افزونه دعوت در حال حاضر در دسترس نیست.', 'manahel-rewards'); ?></p></div>
                    <?php endif; ?>
                <?php else : ?>
                    <?php
                    $summary = Manahel_Rewards_User_Summary_Service::get_for_wp_user($wp_user_id);
                    if (is_wp_error($summary)) {
                        echo '<div class="mr-empty">' . esc_html($summary->get_error_message()) . '</div>';
                    } else {
                        $referral = self::referral_snapshot($wp_user_id);
                        if ('points' === $section) {
                            echo self::growth_points_markup($summary, $referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } elseif ('rewards' === $section) {
                            $catalog = Manahel_Rewards_Redemption_Service::catalog_for_user($wp_user_id);
                            $redemptions = Manahel_Rewards_Redemption_Service::user_redemptions($wp_user_id, 10);
                            echo self::growth_rewards_markup($wp_user_id, $summary, $catalog, $redemptions, $referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        } else {
                            echo self::growth_history_markup($summary); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        }
                    }
                    ?>
                <?php endif; ?>
            </div>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function growth_tabs_markup($active) {
        $tabs = array(
            'points' => array('label' => __('امتیازهای من', 'manahel-rewards'), 'short' => __('امتیاز', 'manahel-rewards')),
            'rewards' => array('label' => __('جوایز', 'manahel-rewards'), 'short' => __('جوایز', 'manahel-rewards')),
            'invite' => array('label' => __('دعوت دوستان', 'manahel-rewards'), 'short' => __('دعوت', 'manahel-rewards')),
            'history' => array('label' => __('تاریخچه', 'manahel-rewards'), 'short' => __('تاریخچه', 'manahel-rewards')),
        );
        ob_start(); ?>
        <nav class="mr-growth-tabs" aria-label="<?php esc_attr_e('امتیاز، جوایز و دعوت', 'manahel-rewards'); ?>">
            <?php foreach ($tabs as $key=>$tab) : ?>
                <a href="<?php echo esc_url(self::hub_url($key)); ?>" class="<?php echo $active === $key ? 'is-active' : ''; ?>" <?php echo $active === $key ? 'aria-current="page"' : ''; ?>>
                    <span class="mr-growth-tab__full"><?php echo esc_html($tab['label']); ?></span>
                    <span class="mr-growth-tab__short" aria-hidden="true"><?php echo esc_html($tab['short']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php return (string)ob_get_clean();
    }

    private static function growth_hero_markup($summary, $compact = false) {
        ob_start(); ?>
        <header class="mr-hero <?php echo $compact ? 'mr-hero--compact' : ''; ?>">
            <div class="mr-hero__eyebrow"><?php esc_html_e('امتیازهای من', 'manahel-rewards'); ?></div>
            <div class="mr-hero__balance"><?php echo esc_html(number_format_i18n((int)$summary['balance'])); ?></div>
            <div class="mr-hero__unit"><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></div>
            <?php if (!$compact) : ?><div class="mr-hero__today"><?php printf(esc_html__('امروز %s امتیاز کسب کرده‌اید', 'manahel-rewards'), '<strong>+' . esc_html(number_format_i18n((int)$summary['today_earned'])) . '</strong>'); ?></div><?php endif; ?>
        </header>
        <?php return (string)ob_get_clean();
    }

    private static function growth_points_markup($summary, $referral) {
        ob_start(); ?>
        <section class="manahel-rewards-app mr-growth-section" aria-labelledby="mr-growth-points-title">
            <h2 id="mr-growth-points-title" class="screen-reader-text"><?php esc_html_e('امتیازهای من', 'manahel-rewards'); ?></h2>
            <?php echo self::growth_hero_markup($summary, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <section class="mr-section" aria-labelledby="mr-policy-heading">
                <div class="mr-section__head"><h2 id="mr-policy-heading"><?php esc_html_e('چطور امتیاز بگیرم؟', 'manahel-rewards'); ?></h2><span><?php echo esc_html(self::timezone_label((string)$summary['policy_timezone'])); ?></span></div>
                <div class="mr-policy-grid">
                    <?php if (!empty($referral['available'])): ?><?php echo self::referral_card($referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
                    <?php echo self::policy_card('quran_part', $summary['policy']['quran_part'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::policy_card('dua', $summary['policy']['dua'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::policy_card('personal_quran', $summary['policy']['personal_quran'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <p class="mr-policy-note"><?php esc_html_e('ذکر در سیاست فعلی مشمول امتیاز نیست.', 'manahel-rewards'); ?></p>
            </section>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function growth_rewards_markup($wp_user_id, $summary, $catalog, $redemptions, $referral) {
        ob_start(); ?>
        <section class="manahel-rewards-app mr-growth-section" aria-labelledby="mr-growth-rewards-title">
            <div class="mr-growth-balance-strip"><span id="mr-growth-rewards-title"><?php esc_html_e('موجودی شما', 'manahel-rewards'); ?></span><strong><?php echo esc_html(number_format_i18n((int)$summary['balance'])); ?> <?php esc_html_e('امتیاز', 'manahel-rewards'); ?></strong></div>
            <?php echo self::redemption_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <section class="mr-section mr-redemption-section" aria-labelledby="mr-redemption-heading">
                <div class="mr-section__head"><h2 id="mr-redemption-heading"><?php esc_html_e('جوایز', 'manahel-rewards'); ?></h2><span><?php esc_html_e('امتیازتان را به جایزه تبدیل کنید', 'manahel-rewards'); ?></span></div>
                <?php echo self::redemption_catalog_markup($wp_user_id, (int)$summary['balance'], $catalog, $referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if (!empty($redemptions)): ?><details class="mr-redemptions"><summary><?php esc_html_e('درخواست‌های جایزه من', 'manahel-rewards'); ?></summary><?php echo self::redemptions_markup($redemptions); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></details><?php endif; ?>
            </section>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function growth_history_markup($summary) {
        ob_start(); ?>
        <section class="manahel-rewards-app mr-growth-section" aria-labelledby="mr-growth-history-title">
            <div class="mr-section__head"><h2 id="mr-growth-history-title"><?php esc_html_e('تاریخچه امتیازها', 'manahel-rewards'); ?></h2><span><?php printf(esc_html__('موجودی فعلی: %s امتیاز', 'manahel-rewards'), esc_html(number_format_i18n((int)$summary['balance']))); ?></span></div>
            <?php echo self::history_markup($summary['recent_ledger']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if (!empty($summary['recent_no_reward'])): ?><details class="mr-no-reward"><summary><?php esc_html_e('فعالیت‌های اخیر بدون امتیاز', 'manahel-rewards'); ?></summary><div class="mr-no-reward__list"><?php foreach ($summary['recent_no_reward'] as $item): ?><div class="mr-no-reward__item"><strong><?php echo esc_html(self::activity_label_from_event($item)); ?></strong><span><?php echo esc_html(self::decision_message((string)$item['decision_code'])); ?></span><small><?php echo esc_html(self::format_utc_for_user((string)$item['occurred_at'])); ?></small></div><?php endforeach; ?></div></details><?php endif; ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    public static function shortcode_balance() {
        self::protect_personalized_page();
        $wp_user_id = self::viewer_wp_user_id();
        if ($wp_user_id <= 0) {
            return '<span class="manahel-rewards-balance manahel-rewards-balance--guest">' . esc_html__('—', 'manahel-rewards') . '</span>';
        }
        $summary = Manahel_Rewards_User_Summary_Service::get_for_wp_user($wp_user_id, 1);
        if (is_wp_error($summary)) { return ''; }
        return '<span class="manahel-rewards-balance" data-manahel-rewards-balance="1">' . esc_html(number_format_i18n((int)$summary['balance'])) . '</span>';
    }

    public static function shortcode_my_points() {
        self::protect_personalized_page();
        if (!wp_style_is('manahel-rewards-frontend', 'registered')) { self::register_assets(); }
        wp_enqueue_style('manahel-rewards-frontend');
        wp_enqueue_script('manahel-rewards-catalog');

        $wp_user_id = self::viewer_wp_user_id();
        if ($wp_user_id <= 0) {
            return self::guest_markup();
        }

        $summary = Manahel_Rewards_User_Summary_Service::get_for_wp_user($wp_user_id);
        if (is_wp_error($summary)) {
            return '<div class="manahel-rewards-app" dir="rtl"><div class="mr-empty">' . esc_html($summary->get_error_message()) . '</div></div>';
        }
        $referral = self::referral_snapshot($wp_user_id);
        $catalog = Manahel_Rewards_Redemption_Service::catalog_for_user($wp_user_id);
        $redemptions = Manahel_Rewards_Redemption_Service::user_redemptions($wp_user_id, 10);

        ob_start();
        ?>
        <section class="manahel-rewards-app" dir="rtl" aria-labelledby="mr-my-points-title">
            <header class="mr-hero">
                <div class="mr-hero__eyebrow" id="mr-my-points-title"><?php esc_html_e('امتیازهای من', 'manahel-rewards'); ?></div>
                <div class="mr-hero__balance"><?php echo esc_html(number_format_i18n((int)$summary['balance'])); ?></div>
                <div class="mr-hero__unit"><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></div>
                <div class="mr-hero__today">
                    <?php printf(esc_html__('امروز %s امتیاز کسب کرده‌اید', 'manahel-rewards'), '<strong>+' . esc_html(number_format_i18n((int)$summary['today_earned'])) . '</strong>'); ?>
                </div>
            </header>

            <?php echo self::redemption_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <section class="mr-section mr-redemption-section" aria-labelledby="mr-redemption-heading">
                <div class="mr-section__head">
                    <h2 id="mr-redemption-heading"><?php esc_html_e('استفاده از امتیاز', 'manahel-rewards'); ?></h2>
                    <span><?php esc_html_e('امتیازتان را به جایزه تبدیل کنید', 'manahel-rewards'); ?></span>
                </div>
                <?php echo self::redemption_catalog_markup($wp_user_id, (int)$summary['balance'], $catalog, $referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if (!empty($redemptions)): ?>
                    <details class="mr-redemptions">
                        <summary><?php esc_html_e('درخواست‌های جایزه من', 'manahel-rewards'); ?></summary>
                        <?php echo self::redemptions_markup($redemptions); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </details>
                <?php endif; ?>
            </section>

            <section class="mr-section" aria-labelledby="mr-policy-heading">
                <div class="mr-section__head">
                    <h2 id="mr-policy-heading"><?php esc_html_e('چطور امتیاز بگیرم؟', 'manahel-rewards'); ?></h2>
                    <span><?php echo esc_html(self::timezone_label((string)$summary['policy_timezone'])); ?></span>
                </div>
                <div class="mr-policy-grid">
                    <?php if (!empty($referral['available'])): ?>
                        <?php echo self::referral_card($referral); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                    <?php echo self::policy_card('quran_part', $summary['policy']['quran_part'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::policy_card('dua', $summary['policy']['dua'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::policy_card('personal_quran', $summary['policy']['personal_quran'] ?? array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <p class="mr-policy-note"><?php esc_html_e('ذکر در سیاست فعلی مشمول امتیاز نیست. این موضوع مانع ثبت و انجام ذکر در هم‌نور نمی‌شود.', 'manahel-rewards'); ?></p>
            </section>

            <section class="mr-section" aria-labelledby="mr-history-heading">
                <div class="mr-section__head"><h2 id="mr-history-heading"><?php esc_html_e('تاریخچه امتیازها', 'manahel-rewards'); ?></h2></div>
                <?php echo self::history_markup($summary['recent_ledger']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </section>

            <?php if (!empty($summary['recent_no_reward'])): ?>
                <details class="mr-no-reward">
                    <summary><?php esc_html_e('فعالیت‌های اخیر بدون امتیاز', 'manahel-rewards'); ?></summary>
                    <div class="mr-no-reward__list">
                        <?php foreach ($summary['recent_no_reward'] as $item): ?>
                            <div class="mr-no-reward__item">
                                <strong><?php echo esc_html(self::activity_label_from_event($item)); ?></strong>
                                <span><?php echo esc_html(self::decision_message((string)$item['decision_code'])); ?></span>
                                <small><?php echo esc_html(self::format_utc_for_user((string)$item['occurred_at'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private static function guest_markup() {
        return '<section class="manahel-rewards-app" dir="rtl"><div class="mr-empty"><strong>' . esc_html__('امتیازهای من', 'manahel-rewards') . '</strong><p>' . esc_html__('برای مشاهده امتیازهای خود، باید با حساب کاربری شناخته‌شده وارد شده باشید.', 'manahel-rewards') . '</p></div></section>';
    }

    private static function protect_personalized_page() {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access','protect_session_from_page_cache')) {
            Hamnoor_Mobile_Access::protect_session_from_page_cache();
        }
        // LiteSpeed Cache understands this control action; it is harmless when LiteSpeed is absent.
        do_action('litespeed_control_set_nocache','manahel-rewards personalized growth/reward inventory');
        if (!headers_sent()) { nocache_headers(); }
    }

    private static function policy_card($key, $status) {
        $status = is_array($status) ? $status : array();
        $labels = array(
            'quran_part' => array('title'=>__('خواندن یک سهم قرآن', 'manahel-rewards'), 'desc'=>__('پس از تکمیل معتبر هر سهم عمومی قرآن', 'manahel-rewards')),
            'dua' => array('title'=>__('خواندن یک دعا', 'manahel-rewards'), 'desc'=>__('پس از تکمیل معتبر یک مشارکت دعا', 'manahel-rewards')),
            'personal_quran' => array('title'=>__('تکمیل قرآن شخصی', 'manahel-rewards'), 'desc'=>__('پس از رسیدن به پایان قرآن شخصی و ثبت تکمیل', 'manahel-rewards')),
        );
        $label = $labels[$key] ?? array('title'=>'', 'desc'=>'');
        $enabled = !empty($status['enabled']);
        $points = (int)($status['points'] ?? 0);
        $awards = (int)($status['awards_today'] ?? 0);
        $max_awards = (int)($status['max_awards_per_day'] ?? 0);
        $percent = $max_awards > 0 ? min(100, (int)round(($awards / $max_awards) * 100)) : 0;
        $state = (string)($status['status_code'] ?? 'missing');

        ob_start(); ?>
        <article class="mr-policy-card mr-policy-card--<?php echo esc_attr($key); ?> <?php echo !$enabled ? 'is-inactive' : ''; ?>">
            <div class="mr-policy-card__top">
                <div><h3><?php echo esc_html($label['title']); ?></h3><p><?php echo esc_html($label['desc']); ?></p></div>
                <div class="mr-policy-card__points"><?php echo $points > 0 ? '+' . esc_html(number_format_i18n($points)) : '—'; ?><small><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></small></div>
            </div>
            <?php if (!$enabled): ?>
                <div class="mr-policy-state mr-policy-state--muted"><?php esc_html_e('امتیازدهی این فعالیت فعلاً فعال نیست.', 'manahel-rewards'); ?></div>
            <?php elseif ('personal_quran' === $key): ?>
                <?php echo self::personal_quran_state($status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else: ?>
                <?php if ($max_awards > 0): ?>
                    <div class="mr-progress" aria-label="<?php echo esc_attr(sprintf(__('%1$d از %2$d پاداش امروز', 'manahel-rewards'), $awards, $max_awards)); ?>">
                        <span style="width:<?php echo esc_attr((string)$percent); ?>%"></span>
                    </div>
                    <div class="mr-policy-card__meta"><?php printf(esc_html__('%1$s از %2$s پاداش امروز', 'manahel-rewards'), esc_html(number_format_i18n($awards)), esc_html(number_format_i18n($max_awards))); ?></div>
                <?php endif; ?>
                <div class="mr-policy-state <?php echo 'daily_limit' === $state ? 'mr-policy-state--limit' : ''; ?>">
                    <?php echo esc_html(self::policy_state_message($status)); ?>
                </div>
            <?php endif; ?>
        </article>
        <?php return (string)ob_get_clean();
    }

    private static function personal_quran_state($status) {
        $state = (string)($status['status_code'] ?? '');
        if ('cooldown' === $state && !empty($status['next_eligible_at'])) {
            return '<div class="mr-policy-state mr-policy-state--limit"><span>' . esc_html__('پاداش بعدی از', 'manahel-rewards') . '</span><strong>' . esc_html(self::format_utc_for_policy((string)$status['next_eligible_at'])) . '</strong></div>';
        }
        if ('daily_limit' === $state) {
            return '<div class="mr-policy-state mr-policy-state--limit">' . esc_html__('پاداش این فعالیت برای امروز تکمیل شده است.', 'manahel-rewards') . '</div>';
        }
        return '<div class="mr-policy-state">' . esc_html__('پاداش تکمیل ختم شخصی در دسترس است.', 'manahel-rewards') . '</div>';
    }

    private static function policy_state_message($status) {
        if ('daily_limit' === (string)($status['status_code'] ?? '')) {
            return __('سقف امتیاز امروز تکمیل شده است؛ همچنان می‌توانید در فعالیت شرکت کنید.', 'manahel-rewards');
        }
        $remaining = $status['rewardable_remaining'] ?? null;
        if (null !== $remaining) {
            if ((int)$remaining <= 0) { return __('سقف امتیاز امروز تکمیل شده است.', 'manahel-rewards'); }
            return sprintf(_n('%s پاداش دیگر امروز باقی مانده است.', '%s پاداش دیگر امروز باقی مانده است.', (int)$remaining, 'manahel-rewards'), number_format_i18n((int)$remaining));
        }
        return __('پاداش این فعالیت فعال است.', 'manahel-rewards');
    }

    private static function referral_snapshot($wp_user_id) {
        global $wpdb;
        $wp_user_id = (int)$wp_user_id;
        $result = array(
            'available'=>false, 'url'=>'', 'inviter_enabled'=>false, 'inviter_points'=>0,
            'referred_enabled'=>false, 'referred_points'=>0, 'campaign_enabled'=>false, 'campaign_points'=>0, 'successful'=>0, 'pending'=>0,
            'earned_points'=>null,
        );
        if ($wp_user_id <= 0 || !function_exists('manahel_referral_get_my_referrals_url')) { return $result; }
        $result['available'] = true;
        $result['url'] = (string)manahel_referral_get_my_referrals_url();
        if (function_exists('manahel_referral_get_compact_user_summary')) {
            $summary = manahel_referral_get_compact_user_summary($wp_user_id);
            if (!is_wp_error($summary) && is_array($summary)) {
                $result['successful'] = (int)($summary['successful'] ?? 0);
                $result['pending'] = (int)($summary['pending'] ?? 0);
                $result['earned_points'] = isset($summary['earned_points']) ? (int)$summary['earned_points'] : null;
            }
        }
        $rules = Manahel_Rewards_DB::table('rules');
        $rows = $wpdb->get_results("SELECT code,points,enabled FROM {$rules} WHERE code IN ('MANAHEL_REFERRAL_QUALIFIED_INVITER','MANAHEL_REFERRAL_QUALIFIED_REFERRED','MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT')");
        foreach ((array)$rows as $row) {
            if ('MANAHEL_REFERRAL_QUALIFIED_INVITER' === (string)$row->code) {
                $result['inviter_enabled'] = !empty($row->enabled);
                $result['inviter_points'] = (int)$row->points;
            } elseif ('MANAHEL_REFERRAL_QUALIFIED_REFERRED' === (string)$row->code) {
                $result['referred_enabled'] = !empty($row->enabled);
                $result['referred_points'] = (int)$row->points;
            } elseif ('MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT' === (string)$row->code) {
                $result['campaign_enabled'] = !empty($row->enabled);
                $result['campaign_points'] = (int)$row->points;
            }
        }
        return $result;
    }

    private static function referral_card($referral) {
        $referral = is_array($referral) ? $referral : array();
        $enabled = !empty($referral['inviter_enabled']);
        $points = (int)($referral['inviter_points'] ?? 0);
        $welcome = (int)($referral['referred_points'] ?? 0);
        $campaign_points = (int)($referral['campaign_points'] ?? 0);
        ob_start(); ?>
        <article class="mr-policy-card mr-policy-card--referral <?php echo !$enabled ? 'is-inactive' : ''; ?>">
            <div class="mr-policy-card__top">
                <div><h3><?php esc_html_e('دعوت دوستان', 'manahel-rewards'); ?></h3><p><?php esc_html_e('بیشترین امتیاز برای جذب واقعی است: لینک خود یا یک پویش را به اشتراک بگذارید و دیگران را به مشارکت برسانید.', 'manahel-rewards'); ?></p></div>
                <div class="mr-policy-card__points"><?php echo $enabled && $points > 0 ? '+' . esc_html(number_format_i18n($points)) : '—'; ?><small><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></small></div>
            </div>
            <?php if (!$enabled): ?>
                <div class="mr-policy-state mr-policy-state--muted"><?php esc_html_e('پاداش دعوت دوستان فعلاً فعال نیست.', 'manahel-rewards'); ?></div>
            <?php else: ?>
                <div class="mr-referral-stats">
                    <span><?php printf(esc_html__('دعوت موفق: %s', 'manahel-rewards'), esc_html(number_format_i18n((int)($referral['successful'] ?? 0)))); ?></span>
                    <?php if ((int)($referral['pending'] ?? 0) > 0): ?><span><?php printf(esc_html__('در انتظار تکمیل: %s', 'manahel-rewards'), esc_html(number_format_i18n((int)$referral['pending']))); ?></span><?php endif; ?>
                </div>
                <?php if ($campaign_points > 0 && !empty($referral['campaign_enabled'])): ?><div class="mr-policy-state"><?php printf(esc_html__('اگر یک کاربر موجود را از لینک پویش به مشارکت واقعی برسانید: %s امتیاز.', 'manahel-rewards'), esc_html(number_format_i18n($campaign_points))); ?></div><?php endif; ?>
                <?php if ($welcome > 0 && !empty($referral['referred_enabled'])): ?><div class="mr-policy-state"><?php printf(esc_html__('کاربر جدیدِ جذب‌شده هم %s امتیاز خوش‌آمد می‌گیرد.', 'manahel-rewards'), esc_html(number_format_i18n($welcome))); ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($referral['url'])): ?><a class="mr-policy-card__cta" href="<?php echo esc_url($referral['url']); ?>"><?php esc_html_e('دعوت دوستان', 'manahel-rewards'); ?> <span aria-hidden="true">←</span></a><?php endif; ?>
        </article>
        <?php return (string)ob_get_clean();
    }

    private static function redemption_notice() {
        if (empty($_GET['mr_redeem'])) { return ''; }
        $code=sanitize_key(wp_unslash($_GET['mr_redeem']));
        $map=array(
            'success'=>array('success',__('درخواست جایزه ثبت شد و امتیاز آن از موجودی شما کم شد.', 'manahel-rewards')),
            'delivered'=>array('success',__('جایزه شما همان لحظه تحویل شد. کد در «درخواست‌های جایزه من» نمایش داده می‌شود. اگر پیام‌رسان شما فعال و متصل باشد، سامانه اعلان هم‌نور نیز برای ارسال کد تلاش می‌کند.', 'manahel-rewards')),
            'redemption_insufficient_balance'=>array('error',__('امتیاز شما برای دریافت این جایزه کافی نیست.', 'manahel-rewards')),
            'redemption_out_of_stock'=>array('error',__('موجودی این جایزه تمام شده است.', 'manahel-rewards')),
            'redemption_per_user_limit'=>array('error',__('سقف دریافت این جایزه برای شما تکمیل شده است.', 'manahel-rewards')),
            'redemption_verification_required'=>array('warning',__('برای دریافت این جایزه خاص، تأیید هویت لازم است.', 'manahel-rewards')),
            'redemption_note_required'=>array('warning',__('اطلاعات لازم برای دریافت جایزه را وارد کنید.', 'manahel-rewards')),
            'redemption_reward_unavailable'=>array('error',__('این جایزه در حال حاضر قابل دریافت نیست.', 'manahel-rewards')),
            'redemption_code_inventory_unavailable'=>array('error',__('مخزن کد این جایزه آماده نیست.', 'manahel-rewards')),
            'redemption_code_assign_failed'=>array('error',__('تحویل کد انجام نشد و امتیازی از شما کسر نشد.', 'manahel-rewards')),
            'security'=>array('error',__('اعتبار درخواست منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.', 'manahel-rewards')),
        );
        if (!isset($map[$code])) { $map[$code]=array('error',__('ثبت درخواست جایزه انجام نشد.', 'manahel-rewards')); }
        return '<div class="mr-redemption-notice is-'.esc_attr($map[$code][0]).'">'.esc_html($map[$code][1]).'</div>';
    }

    private static function redemption_catalog_markup($wp_user_id,$balance,$items,$referral=array()) {
        if (empty($items)) {
            return '<div class="mr-empty"><p>'.esc_html__('فعلاً جایزه‌ای برای تبدیل امتیاز تعریف نشده است.', 'manahel-rewards').'</p></div>';
        }
        $balance=max(0,(int)$balance);
        $verified=Manahel_Rewards_Redemption_Service::is_user_verified($wp_user_id);
        $referral=is_array($referral)?$referral:array();
        $invite_points=!empty($referral['inviter_enabled'])?max(0,(int)($referral['inviter_points']??0)):0;
        $invite_url=trim((string)($referral['url']??''));
        $prepared=array();
        $available_count=0;
        foreach ((array)$items as $item) {
            $cost=max(1,(int)$item->points_cost);
            $stock_total=(int)$item->stock_total;
            $stock_used=(int)$item->stock_used;
            $code_mode='code'===(string)$item->fulfillment_mode;
            $out=$code_mode ? ((int)($item->code_available ?? 0) <= 0) : ($stock_total>0 && $stock_used >= $stock_total);
            $user_count=(int)($item->user_redeemed_count ?? 0);
            $limit=(int)$item->max_per_user;
            $limited=$limit>0 && $user_count >= $limit;
            $verification=!empty($item->requires_verification);
            $blocked=$out || $limited || ($verification && !$verified);
            $can=$balance >= $cost && !$blocked;
            $remaining=max(0,$cost-$balance);
            $progress=min(100,(int)floor(($balance/$cost)*100));
            $invites_needed=($remaining>0 && $invite_points>0)?(int)ceil($remaining/$invite_points):0;
            $near=!$can && !$blocked && ($progress>=50 || ($invites_needed>0 && $invites_needed<=2));
            $state=$can?'available':($blocked?'unavailable':($near?'near':'locked'));
            if($can){$available_count++;}
            $prepared[]=array('item'=>$item,'cost'=>$cost,'out'=>$out,'limited'=>$limited,'verification'=>$verification,'blocked'=>$blocked,'can'=>$can,'remaining'=>$remaining,'progress'=>$progress,'invites_needed'=>$invites_needed,'state'=>$state);
        }
        usort($prepared,static function($a,$b){
            $rank=array('available'=>0,'near'=>1,'locked'=>2,'unavailable'=>3);
            $ra=$rank[$a['state']]??9;$rb=$rank[$b['state']]??9;
            if($ra!==$rb){return $ra<=>$rb;}
            if($a['remaining']!==$b['remaining']){return $a['remaining']<=>$b['remaining'];}
            $sa=(int)$a['item']->sort_order;$sb=(int)$b['item']->sort_order;
            return $sa!==$sb?$sa<=>$sb:(int)$a['item']->id<=>(int)$b['item']->id;
        });
        ob_start(); ?>
        <div class="mr-reward-catalog" data-mr-reward-catalog>
            <div class="mr-reward-summary" aria-label="<?php esc_attr_e('خلاصه جوایز', 'manahel-rewards'); ?>">
                <div><strong><?php echo esc_html(number_format_i18n($balance)); ?></strong><span><?php esc_html_e('امتیاز فعلی', 'manahel-rewards'); ?></span></div>
                <div><strong><?php echo esc_html(number_format_i18n($available_count)); ?></strong><span><?php esc_html_e('جایزه قابل دریافت', 'manahel-rewards'); ?></span></div>
                <?php if ($invite_points>0 && $invite_url!==''): ?><a href="<?php echo esc_url($invite_url); ?>"><strong>+<?php echo esc_html(number_format_i18n($invite_points)); ?></strong><span><?php esc_html_e('هر دعوت موفق', 'manahel-rewards'); ?></span></a><?php endif; ?>
            </div>
            <nav class="mr-reward-filters" aria-label="<?php esc_attr_e('فیلتر جوایز', 'manahel-rewards'); ?>">
                <button type="button" class="is-active" data-mr-filter="all"><?php esc_html_e('همه', 'manahel-rewards'); ?></button>
                <button type="button" data-mr-filter="available"><?php esc_html_e('قابل دریافت', 'manahel-rewards'); ?></button>
                <button type="button" data-mr-filter="near"><?php esc_html_e('نزدیک‌ترین', 'manahel-rewards'); ?></button>
                <button type="button" data-mr-filter="code"><?php esc_html_e('کد، شارژ و بسته', 'manahel-rewards'); ?></button>
                <button type="button" data-mr-filter="physical"><?php esc_html_e('محصولات فیزیکی', 'manahel-rewards'); ?></button>
            </nav>
            <div class="mr-reward-grid">
            <?php foreach ($prepared as $row):
                $item=$row['item'];$cost=$row['cost'];$out=$row['out'];$limited=$row['limited'];$verification=$row['verification'];$can=$row['can'];$remaining=$row['remaining'];$progress=$row['progress'];$invites_needed=$row['invites_needed'];$state=$row['state'];
                $stock_total=(int)$item->stock_total;$stock_used=(int)$item->stock_used;$limit=(int)$item->max_per_user;
                $mode=in_array((string)$item->fulfillment_mode,array('code','physical'),true)?(string)$item->fulfillment_mode:'manual';
                $delivery_label='code'===$mode?__('تحویل کد / شارژ','manahel-rewards'):('physical'===$mode?__('ارسال فیزیکی','manahel-rewards'):__('تحویل توسط هم‌نور','manahel-rewards'));
                $icon='code'===$mode?'🎟️':('physical'===$mode?'📦':'🎁');
                $request_key='redeem-'.wp_generate_uuid4();
            ?>
                <article class="mr-reward-card mr-reward-card--<?php echo esc_attr($state); ?>" data-mr-state="<?php echo esc_attr($state); ?>" data-mr-mode="<?php echo esc_attr($mode); ?>" data-mr-missing="<?php echo (int)$remaining; ?>">
                    <div class="mr-reward-card__visual" aria-hidden="true"><?php echo esc_html($icon); ?></div>
                    <div class="mr-reward-card__body">
                        <div class="mr-reward-card__titleline">
                            <h3><?php echo esc_html((string)$item->title); ?></h3>
                            <?php if ($can): ?><span class="mr-reward-badge is-available"><?php esc_html_e('قابل دریافت', 'manahel-rewards'); ?></span>
                            <?php elseif ('near'===$state): ?><span class="mr-reward-badge is-near"><?php esc_html_e('نزدیک', 'manahel-rewards'); ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($item->description)): ?><p><?php echo esc_html((string)$item->description); ?></p><?php endif; ?>
                        <div class="mr-reward-card__facts"><strong><?php echo esc_html(number_format_i18n($cost)); ?> <?php esc_html_e('امتیاز', 'manahel-rewards'); ?></strong><span><?php echo esc_html($delivery_label); ?></span></div>
                        <?php if (!$out && !$limited && (!$verification || $verified) && !$can): ?>
                            <div class="mr-reward-progress" aria-label="<?php echo esc_attr(sprintf(__('%1$s از %2$s امتیاز', 'manahel-rewards'),number_format_i18n($balance),number_format_i18n($cost))); ?>"><span style="width:<?php echo esc_attr((string)$progress); ?>%"></span></div>
                            <div class="mr-reward-progress__meta"><strong><?php printf(esc_html__('%1$s از %2$s امتیاز', 'manahel-rewards'),esc_html(number_format_i18n($balance)),esc_html(number_format_i18n($cost))); ?></strong><span><?php printf(esc_html__('%s امتیاز دیگر', 'manahel-rewards'),esc_html(number_format_i18n($remaining))); ?></span></div>
                            <?php if ($invite_points>0 && $invite_url!=='' && $invites_needed>0 && $invites_needed<=3): ?>
                                <div class="mr-reward-growth-hint"><?php printf(_n('فقط %s دعوت موفق تا این جایزه', 'فقط %s دعوت موفق تا این جایزه', $invites_needed, 'manahel-rewards'), esc_html(number_format_i18n($invites_needed))); ?></div>
                                <a class="mr-reward-growth-cta" href="<?php echo esc_url($invite_url); ?>"><?php printf(esc_html__('دعوت دوست +%s', 'manahel-rewards'),esc_html(number_format_i18n($invite_points))); ?></a>
                            <?php else: ?>
                                <a class="mr-reward-secondary-cta" href="#mr-policy-heading"><?php esc_html_e('راه‌های کسب امتیاز', 'manahel-rewards'); ?></a>
                            <?php endif; ?>
                        <?php elseif ($out): ?><div class="mr-reward-blocked"><?php echo 'code'===$mode?esc_html__('کدهای این جایزه فعلاً تمام شده‌اند', 'manahel-rewards'):esc_html__('فعلاً ناموجود', 'manahel-rewards'); ?></div>
                        <?php elseif ($limited): ?><div class="mr-reward-blocked"><?php esc_html_e('سقف دریافت شما تکمیل شده است', 'manahel-rewards'); ?></div>
                        <?php elseif ($verification && !$verified): ?><div class="mr-reward-blocked"><?php esc_html_e('این جایزه نیازمند تأیید هویت است', 'manahel-rewards'); ?></div><?php endif; ?>
                        <div class="mr-reward-meta">
                            <?php if ('code'===$mode && !$out): ?><span><?php printf(esc_html__('کد آماده: %s', 'manahel-rewards'),esc_html(number_format_i18n((int)($item->code_available ?? 0)))); ?></span><?php elseif ($stock_total>0 && !$out): ?><span><?php printf(esc_html__('موجودی: %s', 'manahel-rewards'),esc_html(number_format_i18n(max(0,$stock_total-$stock_used)))); ?></span><?php endif; ?>
                            <?php if ($limit>0): ?><span><?php printf(esc_html__('سقف هر کاربر: %s', 'manahel-rewards'),esc_html(number_format_i18n($limit))); ?></span><?php endif; ?>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-redeem-form" data-mr-redeem-form data-reward-title="<?php echo esc_attr((string)$item->title); ?>" data-reward-cost="<?php echo (int)$cost; ?>" data-current-balance="<?php echo (int)$balance; ?>">
                            <input type="hidden" name="action" value="manahel_rewards_redeem">
                            <?php if (isset($_GET['tab']) && 'growth' === sanitize_key(wp_unslash($_GET['tab']))): ?><input type="hidden" name="mr_context" value="hamnoor"><?php endif; ?>
                            <input type="hidden" name="reward_id" value="<?php echo (int)$item->id; ?>">
                            <input type="hidden" name="redemption_token" value="<?php echo esc_attr(Manahel_Rewards_Redemption_Frontend::token($wp_user_id,(int)$item->id)); ?>">
                            <input type="hidden" name="idempotency_key" value="<?php echo esc_attr($request_key); ?>">
                            <?php if (trim((string)$item->note_label)!==''): ?><label><?php echo esc_html((string)$item->note_label); ?><textarea name="user_note" rows="2" required maxlength="1000"></textarea></label><?php endif; ?>
                            <?php if ($can): ?><button type="submit" class="mr-redeem-primary" data-mr-redeem-open><?php esc_html_e('دریافت جایزه', 'manahel-rewards'); ?></button><?php endif; ?>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="mr-reward-filter-empty" hidden><?php esc_html_e('در این دسته فعلاً جایزه‌ای نیست.', 'manahel-rewards'); ?></div>
            <div class="mr-redeem-dialog" data-mr-redeem-dialog hidden role="dialog" aria-modal="true" aria-labelledby="mr-redeem-dialog-title">
                <div class="mr-redeem-dialog__backdrop" data-mr-redeem-close></div>
                <div class="mr-redeem-dialog__sheet">
                    <button type="button" class="mr-redeem-dialog__close" data-mr-redeem-close aria-label="<?php esc_attr_e('بستن', 'manahel-rewards'); ?>">×</button>
                    <span class="mr-redeem-dialog__icon" aria-hidden="true">🎁</span>
                    <h3 id="mr-redeem-dialog-title" data-mr-dialog-title><?php esc_html_e('تأیید دریافت جایزه', 'manahel-rewards'); ?></h3>
                    <div class="mr-redeem-dialog__calc"><div><span><?php esc_html_e('هزینه', 'manahel-rewards'); ?></span><strong data-mr-dialog-cost>—</strong></div><div><span><?php esc_html_e('موجودی فعلی', 'manahel-rewards'); ?></span><strong data-mr-dialog-balance>—</strong></div><div><span><?php esc_html_e('بعد از دریافت', 'manahel-rewards'); ?></span><strong data-mr-dialog-after>—</strong></div></div>
                    <button type="button" class="mr-redeem-dialog__confirm" data-mr-redeem-confirm><?php esc_html_e('تأیید و دریافت', 'manahel-rewards'); ?></button>
                    <button type="button" class="mr-redeem-dialog__cancel" data-mr-redeem-close><?php esc_html_e('انصراف', 'manahel-rewards'); ?></button>
                </div>
            </div>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function redemptions_markup($rows) {
        $labels=array('pending'=>__('در انتظار تحویل','manahel-rewards'),'fulfilled'=>__('تحویل شده','manahel-rewards'),'refunded'=>__('لغو و امتیاز برگشت داده شد','manahel-rewards'));
        ob_start(); ?><div class="mr-redemption-history">
        <?php foreach ((array)$rows as $row): ?>
            <article class="mr-redemption-item">
                <div><strong><?php echo esc_html((string)$row->reward_title); ?></strong><small><?php echo esc_html(self::format_utc_for_user((string)$row->created_at)); ?></small></div>
                <div class="mr-redemption-item__status"><span><?php echo esc_html($labels[(string)$row->status] ?? (string)$row->status); ?></span><strong>-<?php echo esc_html(number_format_i18n((int)$row->points_cost)); ?></strong></div>
                <?php if ('fulfilled'===(string)$row->status && trim((string)$row->fulfillment_value)!==''): ?>
                    <div class="mr-fulfillment"><b><?php esc_html_e('اطلاعات دریافت:', 'manahel-rewards'); ?></b> <?php echo nl2br(esc_html((string)$row->fulfillment_value)); ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?></div><?php return (string)ob_get_clean();
    }

    private static function history_markup($items) {
        if (empty($items)) {
            return '<div class="mr-empty"><p>' . esc_html__('هنوز تراکنش امتیازی برای شما ثبت نشده است.', 'manahel-rewards') . '</p></div>';
        }
        ob_start(); ?>
        <div class="mr-history">
            <?php foreach ((array)$items as $item):
                $delta = (int)$item['delta'];
                $positive = $delta > 0;
                $time = !empty($item['occurred_at']) ? (string)$item['occurred_at'] : (string)$item['created_at'];
            ?>
                <article class="mr-history-item">
                    <div class="mr-history-item__delta <?php echo $positive ? 'is-positive' : 'is-negative'; ?>"><?php echo esc_html(($positive ? '+' : '') . number_format_i18n($delta)); ?></div>
                    <div class="mr-history-item__body">
                        <strong><?php echo esc_html((string)$item['label']); ?></strong>
                        <?php if ('adjustment' === (string)$item['entry_type'] && !empty($item['reason'])): ?><span><?php echo esc_html((string)$item['reason']); ?></span><?php endif; ?>
                        <small><?php echo esc_html(self::format_utc_for_user($time)); ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function decision_message($code) {
        $map = array(
            'no_matching_rule' => __('برای این فعالیت قانون امتیاز فعالی وجود نداشت.', 'manahel-rewards'),
            'limit.unique_entity' => __('این مشارکت قبلاً امتیاز گرفته است.', 'manahel-rewards'),
            'limit.cooldown' => __('هنوز زمان دریافت مجدد این پاداش نرسیده است.', 'manahel-rewards'),
            'limit.daily_awards' => __('سقف دفعات امتیاز امروز تکمیل شده است.', 'manahel-rewards'),
            'limit.daily_points' => __('سقف امتیاز امروز تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_awards' => __('سقف دفعات امتیاز این ماه تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_points' => __('سقف امتیاز این ماه تکمیل شده است.', 'manahel-rewards'),
            'policy_excluded:dhikr' => __('این فعالیت در سیاست فعلی مشمول امتیاز نیست.', 'manahel-rewards'),
            'duplicate_event' => __('این رویداد قبلاً ثبت شده است.', 'manahel-rewards'),
            'identity_unverified' => __('این رویداد مربوط به سیاست قدیمیِ پیش از نسخه ۰.۷ است و در آن زمان به‌دلیل عدم تأیید هویت امتیاز نگرفته است.', 'manahel-rewards'),
            'identity_unresolved' => __('هویت هم‌نور این حساب برای دریافت امتیاز قابل تشخیص نبود.', 'manahel-rewards'),
        );
        return $map[$code] ?? __('برای این فعالیت امتیازی اضافه نشد.', 'manahel-rewards');
    }

    private static function activity_label_from_event($item) {
        if ('quran_part' === (string)($item['entity_type'] ?? '')) { return __('سهم قرآن', 'manahel-rewards'); }
        if ('personal_quran' === (string)($item['entity_type'] ?? '')) { return __('ختم شخصی قرآن', 'manahel-rewards'); }
        if ('dua' === (string)($item['content_type'] ?? '')) { return __('دعا', 'manahel-rewards'); }
        if ('dhikr' === (string)($item['content_type'] ?? '')) { return __('ذکر', 'manahel-rewards'); }
        if ('referral_inviter' === (string)($item['entity_type'] ?? '')) { return __('دعوت موفق دوست', 'manahel-rewards'); }
        if ('referral_referred' === (string)($item['entity_type'] ?? '')) { return __('پاداش خوش‌آمد دعوت', 'manahel-rewards'); }
        if ('campaign_referrer' === (string)($item['entity_type'] ?? '')) { return __('جذب مشارکت‌کننده به پویش', 'manahel-rewards'); }
        return __('فعالیت', 'manahel-rewards');
    }

    private static function format_utc_for_user($mysql_utc) {
        if (!$mysql_utc) { return ''; }
        $timestamp = strtotime($mysql_utc . ' UTC');
        if (!$timestamp) { return $mysql_utc; }
        return wp_date(get_option('date_format') . '، ' . get_option('time_format'), $timestamp, wp_timezone());
    }

    private static function format_utc_for_policy($mysql_utc) {
        if (!$mysql_utc) { return ''; }
        $timestamp = strtotime($mysql_utc . ' UTC');
        if (!$timestamp) { return $mysql_utc; }
        try { $tz = new DateTimeZone(Manahel_Rewards_Limit_Evaluator::policy_timezone()); }
        catch (Exception $e) { $tz = wp_timezone(); }
        return wp_date(get_option('date_format') . '، ' . get_option('time_format'), $timestamp, $tz);
    }

    private static function timezone_label($timezone) {
        return sprintf(__('روز امتیازی: %s', 'manahel-rewards'), $timezone ?: 'UTC');
    }
}
