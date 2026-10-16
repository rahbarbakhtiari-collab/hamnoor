<?php
if (!defined('ABSPATH')) { exit; }
// قالب واقعی فرم ایجاد/ویرایش پویش. منطق فرم در کلاس آماده می‌شود و این template امکان override قالب را فراهم می‌کند.
$form_html = isset($form_html) ? $form_html : '';
?>
<header class="hamnoor-desktop-ui hamnoor-desktop-page-head">
    <a class="hamnoor-desktop-page-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>">
        <span><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></span>
        <div><strong><?php echo esc_html(!empty($editing) ? __('ویرایش پویش', 'hamnoor') : __('ایجاد پویش جدید', 'hamnoor')); ?></strong><small><?php esc_html_e('فرم مرحله‌ای ساخت و تنظیم پویش', 'hamnoor'); ?></small></div>
    </a>
    <nav class="hamnoor-desktop-page-actions hamnoor-desktop-primary-nav" aria-label="<?php esc_attr_e('ناوبری هم‌نور', 'hamnoor'); ?>"><a href="<?php echo esc_url(Hamnoor_Helpers::page_url('dashboard')); ?>"><?php esc_html_e('پنل من', 'hamnoor'); ?></a><a href="<?php echo esc_url(Hamnoor_Helpers::page_url('list')); ?>"><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></a><a class="is-active" href="<?php echo esc_url(Hamnoor_Helpers::page_url('create')); ?>"><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a></nav>
</header>
<header class="hamnoor-mobile-ui hamnoor-mobile-pagebar">
    <a class="hamnoor-mobile-brand-logo" href="<?php echo esc_url('https://hamnoor.ir/'); ?>" aria-label="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></a>
    <strong><?php echo esc_html(!empty($editing) ? __('ویرایش پویش', 'hamnoor') : __('ایجاد پویش', 'hamnoor')); ?></strong>
</header>
<section class="hamnoor-create-template <?php echo !empty($editing) ? 'is-editing' : 'is-creating'; ?>">
    <?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- prepared and escaped before rendering. ?>
</section>
