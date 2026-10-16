<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
if (!$khatm) { return; }
$content_type = !empty($khatm->content_type) && in_array((string) $khatm->content_type, array('quran', 'dua', 'dhikr'), true) ? (string) $khatm->content_type : 'quran';
$progress = isset($progress) && is_array($progress) ? $progress : Hamnoor_DB::get_progress((int) $khatm->id);
if (in_array($content_type, array('dua', 'dhikr'), true)) {
    $canonical_progress = Hamnoor_Campaign_Repository::progress($khatm);
    if (!is_wp_error($canonical_progress)) {
        $progress = array_merge($progress, array(
            'total' => max(0, (int)($canonical_progress['target'] ?? 0)),
            'done' => max(0, (int)($canonical_progress['current'] ?? 0)),
            'percent' => max(0, min(100, (float)($canonical_progress['percentage'] ?? 0))),
            'available' => 0,
            'claimed' => 0,
        ));
    }
}
$card_data = apply_filters('hamnoor_khatm_card_data', array('khatm' => $khatm, 'progress' => $progress), $khatm);
if (is_array($card_data)) {
    if (!empty($card_data['khatm']) && is_object($card_data['khatm'])) { $khatm = $card_data['khatm']; }
    if (isset($card_data['progress']) && is_array($card_data['progress'])) { $progress = $card_data['progress']; }
}
$is_completed = 'completed' === (string) $khatm->status;
$is_expired = 'expired' === (string) $khatm->status;
$is_paused = 'paused' === (string) $khatm->status;
$is_active_without_free_parts = ('quran' === $content_type && 'active' === (string) $khatm->status && empty($progress['available']) && (int) $progress['done'] < (int) $progress['total']);
$featured_badge = Hamnoor_Featured_Service::badge_data($khatm);
$url = Hamnoor_Helpers::invite_url($khatm);
$cover_url = Hamnoor_Helpers::cover_url($khatm);
$creator_name = !empty($khatm->owner_name) ? Hamnoor_Helpers::display_name($khatm->owner_name) : __('کاربر هم‌نور', 'hamnoor');
$card_context_class = isset($card_context_class) ? sanitize_html_class($card_context_class) : '';
$content_label = array('quran' => __('قرآن', 'hamnoor'), 'dua' => __('دعا', 'hamnoor'), 'dhikr' => __('ذکر', 'hamnoor'))[$content_type];
$purpose_label = array('quran' => __('انتخاب سهم قرآن', 'hamnoor'), 'dua' => __('مشارکت در دعا', 'hamnoor'), 'dhikr' => __('مشارکت در ذکر', 'hamnoor'))[$content_type];
$purpose_hint = array('quran' => __('یک بخش آزاد را برای قرائت انتخاب کنید.', 'hamnoor'), 'dua' => __('دعای این پویش را بخوانید و مشارکتتان را ثبت کنید.', 'hamnoor'), 'dhikr' => __('تعداد ذکر دلخواهتان را به جمع پویش اضافه کنید.', 'hamnoor'))[$content_type];
$card_class = trim('hamnoor-khatm-card' . ($is_completed ? ' hamnoor-khatm-completed' : '') . ($is_active_without_free_parts ? ' hamnoor-khatm-no-free-parts' : '') . ($featured_badge ? ' ' . $featured_badge['class'] : '') . ($card_context_class ? ' ' . $card_context_class : ''));
?>
<article class="<?php echo esc_attr($card_class); ?>" data-hamnoor-khatm-card data-status="<?php echo esc_attr((string) $khatm->status); ?>" data-campaign-type="<?php echo esc_attr($content_type); ?>" data-search="<?php echo esc_attr(wp_strip_all_tags($khatm->title . ' ' . (string) $khatm->intention . ' ' . $creator_name)); ?>">
    <?php if (!empty($cover_url)) : ?>
        <a class="hamnoor-khatm-card-media" href="<?php echo esc_url($url); ?>">
            <img class="hamnoor-khatm-card-cover" src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($khatm->title); ?>" width="1200" height="630">
        </a>
    <?php endif; ?>
    <div class="hamnoor-khatm-card-body">
        <div class="hamnoor-card-topline">
            <span class="hamnoor-content-type-label hamnoor-content-type-<?php echo esc_attr($content_type); ?>"><?php echo esc_html($content_label); ?></span>
            <?php if ($featured_badge) : ?><span class="hamnoor-featured-badge"><?php echo esc_html($featured_badge['text']); ?></span><?php endif; ?>
            <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($khatm->status); ?>"><?php echo esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')); ?></span>
            <?php if ($is_active_without_free_parts) : ?><span class="hamnoor-status hamnoor-status-no-free"><?php esc_html_e('همه سهم‌ها انتخاب شده‌اند', 'hamnoor'); ?></span><?php endif; ?>
            <?php if ('private' === (string) $khatm->privacy) : ?><span class="hamnoor-status hamnoor-status-private"><?php esc_html_e('خصوصی', 'hamnoor'); ?></span><?php endif; ?>
        </div>
        <h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($khatm->title); ?></a></h3>
        <p class="hamnoor-khatm-purpose"><?php echo esc_html($purpose_hint); ?></p>
        <?php if (!empty($khatm->intention)) : ?><p class="hamnoor-khatm-card-intention"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($khatm->intention), 22)); ?></p><?php endif; ?>
        <div class="hamnoor-khatm-card-creator"><span><?php esc_html_e('ایجادکننده:', 'hamnoor'); ?></span> <strong><?php echo esc_html($creator_name); ?></strong></div>
        <?php
        $progress_percent_label = rtrim(rtrim(number_format((float)$progress['percent'], 2, '.', ''), '0'), '.');
        $progress_unit = 'dhikr' === $content_type ? __('مرتبه', 'hamnoor') : ('dua' === $content_type ? __('قرائت کامل', 'hamnoor') : __('سهم', 'hamnoor'));
        ?>
        <div class="hamnoor-progress" aria-label="<?php echo esc_attr(sprintf(__('پیشرفت %s درصد', 'hamnoor'), $progress_percent_label)); ?>"><span style="width:<?php echo esc_attr((float)$progress['percent']); ?>%"></span></div>
        <div class="hamnoor-card-meta"><strong><?php echo esc_html(sprintf(__('%s٪ انجام شده', 'hamnoor'), $progress_percent_label)); ?></strong><span><?php echo esc_html(sprintf(__('%1$d از %2$d %3$s', 'hamnoor'), (int) $progress['done'], (int) $progress['total'], $progress_unit)); ?></span></div>
        <?php if ($is_expired) : ?>
            <p class="hamnoor-khatm-card-state-note"><?php echo esc_html(Hamnoor_Texts::get('claim_window_closed_card')); ?></p>
            <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('مشاهده پویش', 'hamnoor'); ?></a>
        <?php elseif ($is_paused) : ?>
            <p class="hamnoor-khatm-card-state-note"><?php echo esc_html(Hamnoor_Texts::get('claim_window_paused_card')); ?></p>
            <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('مشاهده پویش', 'hamnoor'); ?></a>
        <?php elseif ($is_active_without_free_parts) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-secondary is-disabled" disabled aria-disabled="true"><?php esc_html_e('سهمی برای انتخاب باقی نمانده', 'hamnoor'); ?></button>
        <?php else : ?>
            <a class="hamnoor-btn <?php echo esc_attr($is_completed ? 'hamnoor-btn-secondary' : 'hamnoor-btn-primary'); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($is_completed ? __('مشاهده پویش', 'hamnoor') : $purpose_label); ?></a>
        <?php endif; ?>
    </div>
</article>
