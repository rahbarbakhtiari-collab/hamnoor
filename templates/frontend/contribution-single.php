<?php
if (!defined('ABSPATH')) { exit; }
$khatm = !empty($GLOBALS['hamnoor_current_khatm']) ? $GLOBALS['hamnoor_current_khatm'] : null;
$dashboard_url = Hamnoor_Helpers::page_url('dashboard');
$list_url = Hamnoor_Helpers::page_url('list');
$back_url = Hamnoor_Mobile_Access::is_logged_in() && $dashboard_url ? $dashboard_url : ($list_url ? $list_url : home_url('/'));
$back_label = Hamnoor_Mobile_Access::is_logged_in() ? __('پنل من', 'hamnoor') : __('پویش‌های فعال', 'hamnoor');
?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body class="hamnoor-standalone-page hamnoor-public-khatm-page hamnoor-contribution-route-page">
<?php wp_body_open(); ?>
<div class="hamnoor-app-shell">
    <header class="hamnoor-standalone-topbar">
        <div class="hamnoor-standalone-topbar-inner">
            <a class="hamnoor-standalone-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>" aria-label="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>">
                <span class="hamnoor-standalone-brand-plate"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></span>
                <span class="hamnoor-standalone-brand-copy"><strong><?php esc_html_e('هم‌نور', 'hamnoor'); ?></strong><small><?php esc_html_e('صفحه پویش', 'hamnoor'); ?></small></span>
            </a>
            <nav class="hamnoor-desktop-ui hamnoor-standalone-desktop-nav" aria-label="<?php esc_attr_e('ناوبری هم‌نور', 'hamnoor'); ?>">
                <?php if ($dashboard_url) : ?><a href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('پنل من', 'hamnoor'); ?></a><?php endif; ?>
                <?php if ($list_url) : ?><a class="is-active" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></a><?php endif; ?>
                <a class="is-primary" href="<?php echo esc_url(Hamnoor_Helpers::page_url('create')); ?>"><?php esc_html_e('ایجاد پویش جدید', 'hamnoor'); ?></a>
            </nav>
            <a class="hamnoor-mobile-ui hamnoor-standalone-back" href="<?php echo esc_url($back_url); ?>"><span><?php echo esc_html($back_label); ?></span><span aria-hidden="true">←</span></a>
        </div>
    </header>
    <main id="hamnoor-khatm-app" class="hamnoor-standalone-main">
        <div class="hamnoor-wrap <?php echo esc_attr(Hamnoor_Helpers::visual_mode_class()); ?>" dir="rtl">
            <?php if ($khatm && $list_url) : ?>
                <div class="hamnoor-desktop-ui hamnoor-public-backline" aria-label="<?php esc_attr_e('مسیر صفحه پویش', 'hamnoor'); ?>">
                    <a href="<?php echo esc_url($list_url); ?>"><span aria-hidden="true">→</span> <?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></a><span class="hamnoor-public-backline-sep" aria-hidden="true">/</span><span class="hamnoor-public-backline-current"><?php echo esc_html($khatm->title); ?></span>
                </div>
            <?php endif; ?>
            <?php
            if (!empty($GLOBALS['hamnoor_not_found'])) {
                echo '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('پویش مورد نظر پیدا نشد.', 'hamnoor') . '</div>';
            } elseif (!empty($GLOBALS['hamnoor_unpublished_restricted'])) {
                echo '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این پویش هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor') . '</div>';
            } elseif ($khatm) {
                echo Hamnoor_Contribution_Frontend::render($khatm); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
            <?php echo Hamnoor_Frontend::instance()->render_mobile_navigation(''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
