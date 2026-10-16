<?php
if (!defined('ABSPATH')) { exit; }
$khatms = isset($khatms) && is_array($khatms) ? $khatms : array();
$create_url = Hamnoor_Helpers::page_url('create');
$featured_count = 0;
$active_count = 0;
foreach ($khatms as $listed_khatm) {
    if (Hamnoor_Featured_Service::badge_data($listed_khatm)) { $featured_count++; }
    if ('active' === (string) $listed_khatm->status) { $active_count++; }
}
$featured_position = 0;
$default_filter = $active_count > 0 ? 'active' : 'all';
?>
<header class="hamnoor-desktop-ui hamnoor-desktop-page-head">
    <a class="hamnoor-desktop-page-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>">
        <span><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></span>
        <div><strong><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></strong><small><?php esc_html_e('پویش‌های فعال، پایان‌یافته و تکمیل‌شده', 'hamnoor'); ?></small></div>
    </a>
    <nav class="hamnoor-desktop-page-actions hamnoor-desktop-primary-nav" aria-label="<?php esc_attr_e('ناوبری هم‌نور', 'hamnoor'); ?>"><a href="<?php echo esc_url(Hamnoor_Helpers::page_url('dashboard')); ?>"><?php esc_html_e('پنل من', 'hamnoor'); ?></a><a class="is-active" href="<?php echo esc_url(Hamnoor_Helpers::page_url('list')); ?>"><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></a><a class="is-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a></nav>
</header>
<header class="hamnoor-mobile-ui hamnoor-mobile-pagebar">
    <a class="hamnoor-mobile-brand-logo" href="<?php echo esc_url('https://hamnoor.ir/'); ?>" aria-label="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></a>
    <strong><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></strong>
</header>
<section class="hamnoor-section-head hamnoor-list-head">
    <div>
        <h2><?php esc_html_e('پویش‌های عمومی', 'hamnoor'); ?></h2>
        <p><?php esc_html_e('پویش‌های قرآن، دعا و ذکر را پیدا کنید و متناسب با نوع هر پویش همراه شوید.', 'hamnoor'); ?></p>
    </div>
</section>
<section class="hamnoor-list-tools" data-hamnoor-list-tools data-default-filter="<?php echo esc_attr($default_filter); ?>" aria-label="<?php esc_attr_e('جستجو و فیلتر پویش‌ها', 'hamnoor'); ?>">
    <label class="hamnoor-list-search">
        <span><?php esc_html_e('جستجو در پویش‌ها', 'hamnoor'); ?></span>
        <input type="search" data-hamnoor-list-search placeholder="<?php echo esc_attr__('عنوان، نیت یا نام ایجادکننده', 'hamnoor'); ?>" autocomplete="off">
    </label>
    <div class="hamnoor-list-filters" role="group" aria-label="<?php esc_attr_e('وضعیت پویش', 'hamnoor'); ?>">
        <button type="button" class="hamnoor-list-filter<?php echo 'active' === $default_filter ? ' is-active' : ''; ?>" data-khatm-filter="active" aria-pressed="<?php echo 'active' === $default_filter ? 'true' : 'false'; ?>"><?php esc_html_e('فعال', 'hamnoor'); ?></button>
        <button type="button" class="hamnoor-list-filter" data-khatm-filter="ended" aria-pressed="false"><?php esc_html_e('پایان‌یافته', 'hamnoor'); ?></button>
        <button type="button" class="hamnoor-list-filter<?php echo 'all' === $default_filter ? ' is-active' : ''; ?>" data-khatm-filter="all" aria-pressed="<?php echo 'all' === $default_filter ? 'true' : 'false'; ?>"><?php esc_html_e('همه', 'hamnoor'); ?></button>
    </div>
    <div class="hamnoor-list-filters hamnoor-list-type-filters" role="group" aria-label="<?php esc_attr_e('نوع پویش', 'hamnoor'); ?>">
        <button type="button" class="hamnoor-list-filter is-active" data-khatm-type="all" aria-pressed="true"><?php esc_html_e('همه نوع‌ها', 'hamnoor'); ?></button>
        <button type="button" class="hamnoor-list-filter" data-khatm-type="quran" aria-pressed="false"><?php esc_html_e('قرآن', 'hamnoor'); ?></button>
        <button type="button" class="hamnoor-list-filter" data-khatm-type="dua" aria-pressed="false"><?php esc_html_e('دعا', 'hamnoor'); ?></button>
        <button type="button" class="hamnoor-list-filter" data-khatm-type="dhikr" aria-pressed="false"><?php esc_html_e('ذکر', 'hamnoor'); ?></button>
    </div>
    <p class="hamnoor-list-results-status" data-hamnoor-list-status aria-live="polite"></p>
</section>
<div class="hamnoor-khatm-grid hamnoor-featured-count-<?php echo (int) min(3, $featured_count); ?>" data-hamnoor-khatm-grid>
    <?php foreach ($khatms as $khatm) :
        $progress = Hamnoor_DB::get_progress((int) $khatm->id);
        $is_completed = 'completed' === (string) $khatm->status;
        $card_context_class = '';
        if (Hamnoor_Featured_Service::badge_data($khatm)) {
            $featured_position++;
            $card_context_class = 'hamnoor-featured-position-' . min(4, $featured_position);
        }
        include Hamnoor_Helpers::locate_template('frontend/khatm-card.php');
        unset($card_context_class);
    endforeach; ?>
    <?php if (!$khatms) : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز پویش عمومی ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
    <?php if ($khatms) : ?><p class="hamnoor-list-no-results" data-hamnoor-list-empty hidden><?php esc_html_e('پویشی با این جستجو و فیلتر پیدا نشد.', 'hamnoor'); ?></p><?php endif; ?>
</div>
