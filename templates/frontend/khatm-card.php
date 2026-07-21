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
$creator_name = !empty($khatm->owner_name) ? Hamnoor_Helpers::display_name($khatm->owner_name) : __('کاربر هم‌نور', 'hamnoor');
$settings = Hamnoor_Helpers::settings();
$card_meta = array();
if (!empty($settings['card_show_part_type']) && '1' === (string) $settings['card_show_part_type']) {
    $type_labels = array('page'=>__('صفحه', 'hamnoor'), 'juz'=>__('جزء', 'hamnoor'), 'surah'=>__('سوره', 'hamnoor'), 'repeated_surah'=>__('تکرار سوره', 'hamnoor'));
    $type_label = isset($type_labels[$khatm->khatm_type]) ? $type_labels[$khatm->khatm_type] : Hamnoor_Helpers::status_label($khatm->khatm_type, 'type');
    if (in_array((string) $khatm->khatm_type, array('page','juz','surah'), true) && !empty($khatm->khatm_count) && (int) $khatm->khatm_count > 1) { $type_label .= ' × ' . sprintf(__('%d ختم', 'hamnoor'), (int) $khatm->khatm_count); }
    $card_meta[] = sprintf(__('تقسیم‌بندی: %s', 'hamnoor'), $type_label);
}
if (!empty($settings['card_show_claimed_count']) && '1' === (string) $settings['card_show_claimed_count']) { $card_meta[] = sprintf(__('رزروشده: %d', 'hamnoor'), (int) $progress['claimed']); }
if (!empty($settings['card_show_done_count']) && '1' === (string) $settings['card_show_done_count']) { $card_meta[] = sprintf(__('خوانده‌شده: %1$d از %2$d', 'hamnoor'), (int) $progress['done'], (int) $progress['total']); }
$card_context_class = isset($card_context_class) ? sanitize_html_class($card_context_class) : '';
$card_class = trim('hamnoor-khatm-card ' . Hamnoor_Helpers::design_class($khatm) . ($is_completed ? ' hamnoor-khatm-completed' : '') . ($is_active_without_free_parts ? ' hamnoor-khatm-no-free-parts' : '') . ($featured_badge ? ' ' . $featured_badge['class'] : '') . ($card_context_class ? ' ' . $card_context_class : ''));
?>
<article class="<?php echo esc_attr($card_class); ?>">
    <?php if (!empty($cover_url)) : ?>
        <a class="hamnoor-khatm-card-media" href="<?php echo esc_url($url); ?>">
            <img class="hamnoor-khatm-card-cover" src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($khatm->title); ?>" width="1200" height="630">
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
        <div class="hamnoor-khatm-card-creator"><span><?php esc_html_e('ایجادکننده:', 'hamnoor'); ?></span> <strong><?php echo esc_html($creator_name); ?></strong></div>
        <div class="hamnoor-progress" aria-label="<?php echo esc_attr(sprintf(__('پیشرفت %d درصد', 'hamnoor'), (int) $progress['percent'])); ?>"><span style="width:<?php echo esc_attr($progress['percent']); ?>%"></span></div>
        <?php if ($card_meta) : ?><div class="hamnoor-card-meta"><?php echo esc_html(implode(' | ', $card_meta)); ?></div><?php endif; ?>
        <?php if ($is_active_without_free_parts) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-secondary is-disabled" disabled aria-disabled="true"><?php esc_html_e('سهمی برای انتخاب باقی نمانده', 'hamnoor'); ?></button>
        <?php else : ?>
            <a class="hamnoor-btn <?php echo esc_attr($is_completed ? 'hamnoor-btn-secondary' : 'hamnoor-btn-primary'); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($is_completed ? __('مشاهده ختم', 'hamnoor') : __('انتخاب سهم', 'hamnoor')); ?></a>
        <?php endif; ?>
    </div>
</article>
