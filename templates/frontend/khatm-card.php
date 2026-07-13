<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
if (!$khatm) { return; }
$progress = isset($progress) && is_array($progress) ? $progress : Hamnoor_DB::get_progress((int) $khatm->id);
$card_data = apply_filters('hamnoor_khatm_card_data', array('khatm' => $khatm, 'progress' => $progress), $khatm);
if (is_array($card_data)) {
    if (!empty($card_data['khatm']) && is_object($card_data['khatm'])) { $khatm = $card_data['khatm']; }
    if (isset($card_data['progress']) && is_array($card_data['progress'])) { $progress = $card_data['progress']; }
}
$is_completed = 'completed' === (string) $khatm->status;
$is_active_without_free_parts = ('active' === (string) $khatm->status && empty($progress['available']) && (int) $progress['done'] < (int) $progress['total']);
$featured_badge = Hamnoor_Featured_Service::badge_data($khatm);
$url = Hamnoor_Helpers::invite_url($khatm);
$cover_url = Hamnoor_Helpers::cover_url($khatm);
$card_class = trim('hamnoor-khatm-card ' . Hamnoor_Helpers::design_class($khatm) . ($is_completed ? ' hamnoor-khatm-completed' : '') . ($is_active_without_free_parts ? ' hamnoor-khatm-no-free-parts' : '') . ($featured_badge ? ' ' . $featured_badge['class'] : ''));
?>
<article class="<?php echo esc_attr($card_class); ?>">
    <?php if (!empty($cover_url)) : ?>
        <a class="hamnoor-khatm-card-media" href="<?php echo esc_url($url); ?>">
            <img class="hamnoor-khatm-card-cover" src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($khatm->title); ?>">
        </a>
    <?php endif; ?>
    <div class="hamnoor-khatm-card-body">
        <div class="hamnoor-card-topline">
            <?php if ($featured_badge) : ?><span class="hamnoor-featured-badge"><?php echo esc_html($featured_badge['text']); ?></span><?php endif; ?>
            <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($khatm->status); ?>"><?php echo esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')); ?></span>
            <?php if ($is_active_without_free_parts) : ?><span class="hamnoor-status hamnoor-status-no-free"><?php esc_html_e('همه سهم‌ها انتخاب شده‌اند', 'hamnoor'); ?></span><?php endif; ?>
            <?php if ('private' === (string) $khatm->privacy) : ?><span class="hamnoor-status hamnoor-status-private"><?php esc_html_e('خصوصی', 'hamnoor'); ?></span><?php endif; ?>
        </div>
        <h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($khatm->title); ?></a></h3>
        <?php if (!empty($khatm->intention)) : ?>
            <p class="hamnoor-khatm-card-intention"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($khatm->intention), 22)); ?></p>
        <?php endif; ?>
        <div class="hamnoor-progress" aria-label="<?php echo esc_attr(sprintf(__('پیشرفت %d درصد', 'hamnoor'), (int) $progress['percent'])); ?>"><span style="width:<?php echo esc_attr($progress['percent']); ?>%"></span></div>
        <div class="hamnoor-card-meta"><?php echo esc_html(sprintf(__('%1$d از %2$d سهم خوانده شده است', 'hamnoor'), (int) $progress['done'], (int) $progress['total'])); ?></div>
        <?php if ($is_active_without_free_parts) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-secondary is-disabled" disabled aria-disabled="true"><?php esc_html_e('سهمی برای انتخاب باقی نمانده', 'hamnoor'); ?></button>
        <?php else : ?>
            <a class="hamnoor-btn <?php echo esc_attr($is_completed ? 'hamnoor-btn-secondary' : 'hamnoor-btn-primary'); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($is_completed ? __('مشاهده ختم', 'hamnoor') : __('انتخاب سهم', 'hamnoor')); ?></a>
        <?php endif; ?>
    </div>
</article>
