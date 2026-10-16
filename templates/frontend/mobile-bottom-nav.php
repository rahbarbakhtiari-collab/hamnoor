<?php
if (!defined('ABSPATH')) { exit; }
$nav = isset($mobile_nav) && is_array($mobile_nav) ? $mobile_nav : array();
$active = isset($nav['active']) ? (string) $nav['active'] : '';
$unread = isset($nav['unread']) ? (int) $nav['unread'] : 0;
$home_url = !empty($nav['home_url']) ? $nav['home_url'] : home_url('/');
$parts_url = !empty($nav['parts_url']) ? $nav['parts_url'] : $home_url;
$notifications_url = !empty($nav['notifications_url']) ? $nav['notifications_url'] : $home_url;
$more_url = !empty($nav['more_url']) ? $nav['more_url'] : $home_url;
?>
<div class="hamnoor-mobile-ui hamnoor-mobile-nav-spacer" aria-hidden="true"></div>
<nav class="hamnoor-mobile-ui hamnoor-mobile-bottom-nav hamnoor-mobile-page-nav" aria-label="<?php esc_attr_e('ناوبری اصلی هم‌نور', 'hamnoor'); ?>">
    <a href="<?php echo esc_url($home_url); ?>" class="<?php echo 'home' === $active ? 'is-active' : ''; ?>" aria-current="<?php echo 'home' === $active ? 'page' : 'false'; ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/></svg><span><?php esc_html_e('خانه', 'hamnoor'); ?></span>
    </a>
    <a href="<?php echo esc_url(Hamnoor_Helpers::page_url('list')); ?>" class="<?php echo 'list' === $active ? 'is-active' : ''; ?>" aria-current="<?php echo 'list' === $active ? 'page' : 'false'; ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span><?php esc_html_e('پویش‌ها', 'hamnoor'); ?></span>
    </a>
    <a href="<?php echo esc_url($parts_url); ?>" class="<?php echo 'parts' === $active ? 'is-active' : ''; ?>" aria-current="<?php echo 'parts' === $active ? 'page' : 'false'; ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg><span><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></span>
    </a>
    <a href="<?php echo esc_url($notifications_url); ?>" class="<?php echo 'notifications' === $active ? 'is-active' : ''; ?>" aria-current="<?php echo 'notifications' === $active ? 'page' : 'false'; ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span><?php esc_html_e('اعلان‌ها', 'hamnoor'); ?></span><?php if ($unread) : ?><i class="hamnoor-mobile-badge hamnoor-notification-badge"><?php echo (int) $unread; ?></i><?php endif; ?>
    </a>
    <a href="<?php echo esc_url($more_url); ?>" class="<?php echo 'more' === $active ? 'is-active' : ''; ?>" aria-current="<?php echo 'more' === $active ? 'page' : 'false'; ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span><?php esc_html_e('بیشتر', 'hamnoor'); ?></span>
    </a>
</nav>
