<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Frontend {
    const OPTION_MY_REFERRALS_PAGE_ID = 'manahel_referral_my_referrals_page_id';

    public static function init() {
        add_shortcode('manahel_referral_invite', array(__CLASS__, 'shortcode_invite'));
        add_shortcode('manahel_referral_my_referrals', array(__CLASS__, 'shortcode_my_referrals'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
    }

    public static function register_assets() {
        wp_register_style('manahel-referral-frontend', MANAHEL_REFERRAL_URL . 'assets/css/frontend.css', array(), MANAHEL_REFERRAL_VERSION);
        wp_register_script('manahel-referral-frontend', MANAHEL_REFERRAL_URL . 'assets/js/frontend.js', array(), MANAHEL_REFERRAL_VERSION, true);

        // Dashboard integration renders after wp_head, so register/enqueue its
        // stylesheet early whenever the current page owns the Hamnoor dashboard.
        if (is_singular()) {
            $post = get_post();
            $content = $post && isset($post->post_content) ? (string)$post->post_content : '';
            if ($content && (has_shortcode($content, 'manahel_referral_invite') || has_shortcode($content, 'manahel_referral_my_referrals') || has_shortcode($content, 'hamnoor_dashboard'))) {
                wp_enqueue_style('manahel-referral-frontend');
            }
        }
    }

    public static function viewer_wp_user_id() {
        $wp_user_id = (int)get_current_user_id();
        return max(0, (int)apply_filters('manahel_referral_viewer_wp_user_id', $wp_user_id));
    }

    public static function my_referrals_url() {
        $page_id = (int)get_option(self::OPTION_MY_REFERRALS_PAGE_ID, 0);
        $url = '';
        if ($page_id > 0 && 'publish' === get_post_status($page_id) && 'page' === get_post_type($page_id)) {
            $url = (string)get_permalink($page_id);
        }
        return (string)apply_filters('manahel_referral_my_referrals_url', $url, $page_id);
    }

    public static function shortcode_invite($atts = array()) {
        self::protect_personalized_page();
        self::enqueue_assets(true);
        $wp_user_id = self::viewer_wp_user_id();
        if ($wp_user_id <= 0) { return self::guest_markup(__('دعوت دوستان', 'manahel-referral')); }
        return self::invite_markup($wp_user_id, false);
    }

    public static function shortcode_my_referrals($atts = array()) {
        self::protect_personalized_page();
        self::enqueue_assets(true);
        $wp_user_id = self::viewer_wp_user_id();
        if ($wp_user_id <= 0) { return self::guest_markup(__('دعوت‌های من', 'manahel-referral')); }

        $summary = Manahel_Referral_User_Summary_Service::get_for_wp_user($wp_user_id);
        if (is_wp_error($summary)) {
            return '<section class="manahel-referral-app" dir="rtl"><div class="mrf-empty">' . esc_html($summary->get_error_message()) . '</div></section>';
        }

        ob_start(); ?>
        <section class="manahel-referral-app" dir="rtl" aria-labelledby="mrf-page-title">
            <header class="mrf-hero">
                <span class="mrf-hero__eyebrow" id="mrf-page-title"><?php esc_html_e('دعوت‌های من', 'manahel-referral'); ?></span>
                <strong><?php esc_html_e('هم‌نور را با دوستانتان به اشتراک بگذارید', 'manahel-referral'); ?></strong>
                <p><?php esc_html_e('امتیاز وقتی ثبت می‌شود که لینک شما واقعاً به مشارکت منجر شود؛ تأیید شماره برای کسب امتیاز لازم نیست.', 'manahel-referral'); ?></p>
            </header>

            <?php echo self::invite_markup($wp_user_id, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <section class="mrf-section" aria-labelledby="mrf-stats-heading">
                <div class="mrf-section__head"><h2 id="mrf-stats-heading"><?php esc_html_e('وضعیت دعوت‌ها', 'manahel-referral'); ?></h2></div>
                <div class="mrf-stats-grid">
                    <?php echo self::stat_card('qualified', __('دعوت موفق', 'manahel-referral'), (int)$summary['successful']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::stat_card('pending', __('در انتظار تکمیل فعالیت', 'manahel-referral'), (int)$summary['pending']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo self::stat_card('expired', __('منقضی‌شده', 'manahel-referral'), (int)$summary['expired']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <?php if (!empty($summary['rewards_available']) && null !== $summary['earned_points']) : ?>
                    <div class="mrf-earned"><span><?php esc_html_e('امتیاز واقعی کسب‌شده از دعوت‌ها', 'manahel-referral'); ?></span><strong>+<?php echo esc_html(number_format_i18n((int)$summary['earned_points'])); ?></strong></div>
                <?php endif; ?>
            </section>

            <section class="mrf-section" aria-labelledby="mrf-history-heading">
                <div class="mrf-section__head"><h2 id="mrf-history-heading"><?php esc_html_e('آخرین دعوت‌ها', 'manahel-referral'); ?></h2></div>
                <?php echo self::history_markup((array)$summary['recent_referrals'], !empty($summary['rewards_available'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </section>

            <p class="mrf-policy-note"><?php esc_html_e('کپی، کلیک یا اشتراک‌گذاری به‌تنهایی امتیاز ندارد. امتیاز برای جذب و مشارکت واقعی ثبت می‌شود و هر کاربر جدید فقط یک معرف اصلی دارد.', 'manahel-referral'); ?></p>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function invite_markup($wp_user_id, $compact = false) {
        $url = Manahel_Referral_Code_Service::invite_url((int)$wp_user_id);
        if (is_wp_error($url)) { return '<div class="manahel-referral-invite mrf-empty" dir="rtl">' . esc_html($url->get_error_message()) . '</div>'; }
        $id = 'mrf-link-' . wp_rand(1000, 999999);
        $share_text = __('با این لینک به هم‌نور بپیوندید و در پویش‌های قرآن و دعا همراه شوید.', 'manahel-referral');
        ob_start(); ?>
        <section class="manahel-referral-invite <?php echo $compact ? 'manahel-referral-invite--inside' : ''; ?>" dir="rtl" aria-labelledby="<?php echo esc_attr($id); ?>-title">
            <div class="mrf-invite-head">
                <div><span><?php esc_html_e('لینک اختصاصی شما', 'manahel-referral'); ?></span><h<?php echo $compact ? '2' : '3'; ?> id="<?php echo esc_attr($id); ?>-title"><?php esc_html_e('دوستانتان را دعوت کنید', 'manahel-referral'); ?></h<?php echo $compact ? '2' : '3'; ?>></div>
                <span class="mrf-invite-badge"><?php esc_html_e('۳۰ روز فرصت مشارکت', 'manahel-referral'); ?></span>
            </div>
            <p><?php esc_html_e('این لینک را از هر روشی که می‌خواهید به اشتراک بگذارید. صرف بازکردن لینک امتیاز ندارد؛ باید به مشارکت واقعی منجر شود.', 'manahel-referral'); ?></p>
            <div class="mrf-link-box">
                <input id="<?php echo esc_attr($id); ?>" type="text" readonly value="<?php echo esc_attr($url); ?>" aria-label="<?php esc_attr_e('لینک دعوت', 'manahel-referral'); ?>">
            </div>
            <div class="mrf-invite-actions">
                <button type="button" class="mrf-btn mrf-referral-share" data-target="<?php echo esc_attr($id); ?>" data-share-title="<?php echo esc_attr__('دعوت به هم‌نور', 'manahel-referral'); ?>" data-share-text="<?php echo esc_attr($share_text); ?>"><?php esc_html_e('اشتراک‌گذاری', 'manahel-referral'); ?></button>
                <button type="button" class="mrf-btn mrf-btn--secondary mrf-referral-copy" data-target="<?php echo esc_attr($id); ?>"><?php esc_html_e('کپی لینک', 'manahel-referral'); ?></button>
            </div>
            <p class="mrf-referral-copy-status" role="status" aria-live="polite"></p>
        </section>
        <?php return (string)ob_get_clean();
    }

    private static function stat_card($status, $label, $count) {
        return '<article class="mrf-stat mrf-stat--' . esc_attr($status) . '"><strong>' . esc_html(number_format_i18n((int)$count)) . '</strong><span>' . esc_html($label) . '</span></article>';
    }

    private static function history_markup(array $items, $show_points) {
        if (!$items) { return '<div class="mrf-empty">' . esc_html__('هنوز دعوتی ثبت نشده است.', 'manahel-referral') . '</div>'; }
        ob_start(); ?><div class="mrf-history">
        <?php foreach ($items as $item) :
            $status = sanitize_key((string)($item['status'] ?? 'attributed'));
            $label = self::status_label($status);
            $message = self::status_message($status);
            $date = 'qualified' === $status && !empty($item['qualified_at']) ? (string)$item['qualified_at'] : (string)($item['attributed_at'] ?? '');
            $points = (int)($item['awarded_points'] ?? 0);
            ?>
            <article class="mrf-history-item mrf-history-item--<?php echo esc_attr($status); ?>">
                <div class="mrf-history-item__main"><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html($message); ?></span><small><?php echo esc_html(self::format_utc($date)); ?></small></div>
                <div class="mrf-history-item__side">
                    <span class="mrf-status-badge"><?php echo esc_html($label); ?></span>
                    <?php if ($show_points && $points > 0) : ?><strong class="mrf-history-points">+<?php echo esc_html(number_format_i18n($points)); ?> <?php esc_html_e('امتیاز', 'manahel-referral'); ?></strong><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?></div><?php return (string)ob_get_clean();
    }

    private static function status_label($status) {
        if ('qualified' === $status) { return __('دعوت موفق', 'manahel-referral'); }
        if ('expired' === $status) { return __('منقضی‌شده', 'manahel-referral'); }
        return __('در انتظار', 'manahel-referral');
    }

    private static function status_message($status) {
        if ('qualified' === $status) { return __('کاربر جدید یک فعالیت معتبر را تکمیل کرده است.', 'manahel-referral'); }
        if ('expired' === $status) { return __('بازه ۳۰ روزه بدون تکمیل فعالیت معتبر پایان یافته است.', 'manahel-referral'); }
        return __('هنوز فعالیت معتبر قرآن یا دعا تکمیل نشده است.', 'manahel-referral');
    }

    private static function format_utc($mysql) {
        $mysql = trim((string)$mysql);
        if (!$mysql) { return '—'; }
        try {
            $utc = new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            return wp_date(get_option('date_format') . '، ' . get_option('time_format'), $utc->getTimestamp(), $tz);
        } catch (Exception $e) { return $mysql; }
    }

    private static function guest_markup($title) {
        return '<section class="manahel-referral-app" dir="rtl"><div class="mrf-empty"><strong>' . esc_html($title) . '</strong><p>' . esc_html__('برای مشاهده لینک و وضعیت دعوت‌های خود، ابتدا هویت شما باید مشخص باشد.', 'manahel-referral') . '</p></div></section>';
    }

    private static function enqueue_assets($script = false) {
        if (!wp_style_is('manahel-referral-frontend', 'registered')) { self::register_assets(); }
        wp_enqueue_style('manahel-referral-frontend');
        if ($script) { wp_enqueue_script('manahel-referral-frontend'); }
    }

    private static function protect_personalized_page() {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (class_exists('Hamnoor_Mobile_Access') && method_exists('Hamnoor_Mobile_Access', 'protect_session_from_page_cache')) {
            Hamnoor_Mobile_Access::protect_session_from_page_cache();
        }
        if (!headers_sent()) { nocache_headers(); }
    }
}
