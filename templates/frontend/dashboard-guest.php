<?php
if (!defined('ABSPATH')) { exit; }
$list_url = isset($list_url) ? $list_url : Hamnoor_Helpers::page_url('list');
$create_url = isset($create_url) ? $create_url : Hamnoor_Helpers::page_url('create');
$dashboard_url = Hamnoor_Helpers::page_url('dashboard');
$help_url = Hamnoor_Helpers::help_url();
$active_tab = isset($active_tab) ? sanitize_key($active_tab) : 'summary';
if ('contributions' === $active_tab) { $active_tab = 'my-parts'; }
$zero_copy = array(
    'my-parts'=>array(__('سهم‌های من','hamnoor'),__('هنوز سهمی در پویش‌های قرآن، دعا یا ذکر ندارید.','hamnoor')),
    'notifications'=>array(__('اعلان‌ها','hamnoor'),__('هنوز اعلانی ندارید.','hamnoor')),
    'my-khatms'=>array(__('پویش‌های من','hamnoor'),__('هنوز پویشی ایجاد نکرده‌اید.','hamnoor')),
    'progress'=>array(__('پیشرفت پویش‌ها','hamnoor'),__('برای مشاهده پیشرفت، ابتدا یک پویش ایجاد کنید.','hamnoor')),
    'reminders'=>array(__('یادآوری‌ها','hamnoor'),__('یادآوری‌ها پس از شروع فعالیت در دسترس قرار می‌گیرند.','hamnoor')),
    'profile'=>array(__('حساب و تنظیمات','hamnoor'),__('مشخصات شما هنگام نخستین اقدام هویتی ثبت می‌شود.','hamnoor')),
    'help'=>array(__('راهنما','hamnoor'),__('برای شروع می‌توانید پویش‌های عمومی را ببینید یا پویش تازه‌ای ایجاد کنید.','hamnoor')),
);
?>
<section class="hamnoor-dashboard hamnoor-dashboard-zero-state" data-active-tab="<?php echo esc_attr($active_tab); ?>" data-hamnoor-dashboard="1" aria-labelledby="hamnoor-zero-title">
    <header class="hamnoor-mobile-ui hamnoor-mobile-appbar"><a class="hamnoor-mobile-brand-logo" href="<?php echo esc_url('https://hamnoor.ir/'); ?>" aria-label="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور','hamnoor'); ?>"></a></header>
    <aside class="hamnoor-desktop-ui hamnoor-desktop-brand-panel" aria-label="<?php esc_attr_e('ناوبری پنل هم‌نور','hamnoor'); ?>">
        <a class="hamnoor-desktop-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>"><span class="hamnoor-desktop-brand-mark"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt=""></span><span><strong><?php esc_html_e('هم‌نور','hamnoor'); ?></strong><small><?php esc_html_e('پنل پویش و قرائت قرآن','hamnoor'); ?></small></span></a>
        <a class="hamnoor-desktop-create-shortcut" href="<?php echo esc_url($create_url); ?>">＋ <?php esc_html_e('ایجاد پویش جدید','hamnoor'); ?></a>
        <nav class="hamnoor-zero-navigation" aria-label="<?php esc_attr_e('بخش‌های پنل','hamnoor'); ?>">
            <a class="is-active" href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('امروز من','hamnoor'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab','my-parts',$dashboard_url)); ?>"><?php esc_html_e('سهم‌های من','hamnoor'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab','notifications',$dashboard_url)); ?>"><?php esc_html_e('اعلان‌ها','hamnoor'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab','my-khatms',$dashboard_url)); ?>"><?php esc_html_e('پویش‌های من','hamnoor'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab','profile',$dashboard_url)); ?>"><?php esc_html_e('حساب و تنظیمات','hamnoor'); ?></a>
            <a href="<?php echo esc_url($help_url); ?>"><?php esc_html_e('راهنما','hamnoor'); ?></a>
        </nav>
    </aside>
    <div class="hamnoor-dashboard-head hamnoor-desktop-ui"><div><h1 id="hamnoor-zero-title"><?php esc_html_e('داشبورد هم‌نور','hamnoor'); ?></h1><p><?php esc_html_e('برای دیدن داشبورد نیازی به ورود نیست؛ هویت فقط هنگام انجام کار لازم درخواست می‌شود.','hamnoor'); ?></p></div></div>
    <div class="hamnoor-dashboard-panels hamnoor-zero-state-panels">
        <?php if ('summary' !== $active_tab && isset($zero_copy[$active_tab])) : ?>
        <section class="hamnoor-card hamnoor-zero-route-state" data-tab-panel="<?php echo esc_attr($active_tab); ?>"><h2><?php echo esc_html($zero_copy[$active_tab][0]); ?></h2><p><?php echo esc_html($zero_copy[$active_tab][1]); ?></p><?php if ('my-parts' === $active_tab) : ?><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('مشاهده پویش‌ها','hamnoor'); ?></a><?php elseif ('my-khatms' === $active_tab) : ?><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش','hamnoor'); ?></a><?php endif; ?></section>
        <?php else : ?>
        <section class="hamnoor-card hamnoor-zero-welcome"><h2><?php esc_html_e('هنوز فعالیتی ندارید','hamnoor'); ?></h2><p><?php esc_html_e('می‌توانید یک پویش را ببینید و سهم بگیرید، یا پویش تازه‌ای ایجاد کنید.','hamnoor'); ?></p><div class="hamnoor-action-buttons"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('مشاهده پویش‌ها','hamnoor'); ?></a><a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش','hamnoor'); ?></a></div></section>
        <div class="hamnoor-zero-grid">
            <section class="hamnoor-card"><h3><?php esc_html_e('سهم‌های من','hamnoor'); ?></h3><p><?php esc_html_e('همه سهم‌های قرآن، دعا و ذکر شما اینجا نمایش داده می‌شوند.','hamnoor'); ?></p><a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('مشاهده پویش‌ها','hamnoor'); ?></a></section>
            <section class="hamnoor-card"><h3><?php esc_html_e('پویش‌های من','hamnoor'); ?></h3><p><?php esc_html_e('هنوز پویشی ایجاد نکرده‌اید.','hamnoor'); ?></p><a href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش','hamnoor'); ?></a></section>
            <section class="hamnoor-card"><h3><?php esc_html_e('اعلان‌ها','hamnoor'); ?></h3><p><?php esc_html_e('هنوز اعلانی ندارید.','hamnoor'); ?></p></section>
        </div>
        <?php endif; ?>
        <details class="hamnoor-card hamnoor-existing-account-entry"><summary><?php esc_html_e('قبلاً در هم‌نور فعالیت داشته‌اید؟','hamnoor'); ?></summary><?php echo Hamnoor_Mobile_Access::recovery_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></details>
    </div>
    <nav class="hamnoor-mobile-ui hamnoor-mobile-bottom-nav hamnoor-mobile-bottom-nav--guest" aria-label="<?php esc_attr_e('ناوبری اصلی هم‌نور','hamnoor'); ?>">
        <a class="<?php echo 'summary' === $active_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url($dashboard_url); ?>" <?php echo 'summary' === $active_tab ? 'aria-current="page"' : ''; ?> aria-label="<?php esc_attr_e('خانه','hamnoor'); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/></svg><span><?php esc_html_e('خانه','hamnoor'); ?></span>
        </a>
        <a href="<?php echo esc_url($list_url); ?>" aria-label="<?php esc_attr_e('پویش‌ها','hamnoor'); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span><?php esc_html_e('پویش‌ها','hamnoor'); ?></span>
        </a>
        <a href="<?php echo esc_url($create_url); ?>" aria-label="<?php esc_attr_e('ایجاد پویش','hamnoor'); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/></svg><span><?php esc_html_e('ایجاد','hamnoor'); ?></span>
        </a>
        <a href="<?php echo esc_url($help_url); ?>" aria-label="<?php esc_attr_e('راهنما','hamnoor'); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 0 1 4.6 1c0 1.8-2.4 2-2.4 4M12 17.2h.01"/></svg><span><?php esc_html_e('راهنما','hamnoor'); ?></span>
        </a>
        <button type="button" data-hamnoor-mobile-more-toggle aria-expanded="false" aria-label="<?php esc_attr_e('بیشتر','hamnoor'); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span><?php esc_html_e('بیشتر','hamnoor'); ?></span>
        </button>
    </nav>

    <div class="hamnoor-mobile-ui hamnoor-mobile-more-layer" data-hamnoor-mobile-more hidden>
        <button type="button" class="hamnoor-mobile-more-backdrop" data-hamnoor-mobile-more-close aria-label="<?php esc_attr_e('بستن منو','hamnoor'); ?>"></button>
        <section class="hamnoor-mobile-more-sheet" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('گزینه‌های بیشتر','hamnoor'); ?>">
            <div class="hamnoor-mobile-sheet-handle" aria-hidden="true"></div>
            <div class="hamnoor-mobile-sheet-head"><strong><?php esc_html_e('بیشتر','hamnoor'); ?></strong><button type="button" data-hamnoor-mobile-more-close aria-label="<?php esc_attr_e('بستن','hamnoor'); ?>">×</button></div>
            <div class="hamnoor-mobile-more-sections">
                <section class="hamnoor-mobile-more-section" aria-labelledby="hamnoor-guest-more-activity">
                    <strong id="hamnoor-guest-more-activity" class="hamnoor-mobile-more-section-title"><?php esc_html_e('فعالیت‌های من','hamnoor'); ?></strong>
                    <div class="hamnoor-mobile-more-grid">
                        <a href="<?php echo esc_url(add_query_arg('tab','my-parts',$dashboard_url)); ?>"><span>▤</span><?php esc_html_e('سهم‌های من','hamnoor'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg('tab','my-khatms',$dashboard_url)); ?>"><span>◇</span><?php esc_html_e('پویش‌های من','hamnoor'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg('tab','notifications',$dashboard_url)); ?>"><span>◉</span><?php esc_html_e('اعلان‌ها','hamnoor'); ?></a>
                    </div>
                </section>
                <section class="hamnoor-mobile-more-section" aria-labelledby="hamnoor-guest-more-tools">
                    <strong id="hamnoor-guest-more-tools" class="hamnoor-mobile-more-section-title"><?php esc_html_e('ابزارها و حساب','hamnoor'); ?></strong>
                    <div class="hamnoor-mobile-more-grid">
                        <a href="<?php echo esc_url(add_query_arg('tab','progress',$dashboard_url)); ?>"><span>↗</span><?php esc_html_e('پیشرفت پویش‌ها','hamnoor'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg('tab','reminders',$dashboard_url)); ?>"><span>⌁</span><?php esc_html_e('یادآوری‌ها','hamnoor'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg('tab','profile',$dashboard_url)); ?>"><span>♙</span><?php esc_html_e('حساب و تنظیمات','hamnoor'); ?></a>
                    </div>
                </section>
            </div>
        </section>
    </div>
</section>
