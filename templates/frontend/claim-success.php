<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
$part = isset($part) ? $part : null;
$tracking_token = isset($tracking_token) ? (string) $tracking_token : '';
if (!$khatm || !$part) { return; }
$auto_done_on_claim = (isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode) || ('done' === (string) $part->status);
$release_warning = __('سهم برای شما رزرو شد. لطفاً پس از قرائت، آن را از صفحه پیگیری سهم‌ها یا داشبورد هم‌نور ثبت کنید.', 'hamnoor');
if ($khatm && isset($khatm->auto_release_mode) && 'after_days' === (string) $khatm->auto_release_mode && !empty($khatm->auto_release_after_days)) {
    $release_warning = sprintf(__('سهم برای شما رزرو شد. لطفاً پس از قرائت، آن را از صفحه پیگیری سهم‌ها یا داشبورد ثبت کنید. اگر تا %d روز پس از انتخاب سهم، قرائت ثبت نشود، این سهم ممکن است دوباره آزاد شود.', 'hamnoor'), (int) $khatm->auto_release_after_days);
} elseif ($khatm && isset($khatm->auto_release_mode) && 'fixed_date' === (string) $khatm->auto_release_mode && !empty($khatm->auto_release_at)) {
    $release_warning = sprintf(__('سهم برای شما رزرو شد. لطفاً پس از قرائت، آن را از صفحه پیگیری سهم‌ها یا داشبورد ثبت کنید. اگر تا تاریخ %s قرائت ثبت نشود، این سهم ممکن است دوباره آزاد شود.', 'hamnoor'), Hamnoor_Jalali::to_jalali($khatm->auto_release_at, true));
}
?>
<div class="hamnoor-claim-success hamnoor-claim-success-card" data-part-id="<?php echo (int) $part->id; ?>">
    <h3><?php echo esc_html($auto_done_on_claim ? __('سهم شما ثبت و انجام‌شده محسوب شد. خداوند قبول فرماید.', 'hamnoor') : Hamnoor_Texts::get('claim_success_title')); ?></h3>
    <dl>
        <dt><?php esc_html_e('نام ختم', 'hamnoor'); ?></dt><dd><?php echo esc_html($khatm->title); ?></dd>
        <dt><?php esc_html_e('سهم شما', 'hamnoor'); ?></dt><dd><?php echo esc_html($part->title); ?></dd>
    </dl>
    <?php if ($auto_done_on_claim) : ?>
        <p class="hamnoor-help"><?php esc_html_e('این سهم با انتخاب شما خوانده‌شده محسوب شد و نیازی به ثبت جداگانه قرائت نیست.', 'hamnoor'); ?></p>
    <?php else : ?>
        <p class="hamnoor-help"><?php esc_html_e('بعد از انتخاب سهم، می‌توانید پس از قرائت همین‌جا دکمه «قرائت انجام شد» را بزنید. اگر بعداً خواستید ثبت کنید، به صفحه پیگیری سهم‌ها بروید و با موبایل یا ایمیلی که وارد کرده‌اید سهم خود را پیدا کنید.', 'hamnoor'); ?></p>
    <?php endif; ?>
    <div class="hamnoor-ajax-message" aria-live="polite"></div>
    <div class="hamnoor-modal-actions">
        <?php if (!empty($part->quran_url)) : ?><a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($part->quran_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('مشاهده قرآن', 'hamnoor'); ?></a><?php endif; ?>
        <?php if (!$auto_done_on_claim) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-complete-form" data-hamnoor-complete="1">
                <?php wp_nonce_field('hamnoor_complete_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="access" value="<?php echo esc_attr($tracking_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::part_access_url($khatm, (int) $part->id, $tracking_token)); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
            <button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-hamnoor-complete-later data-warning="<?php echo esc_attr($release_warning); ?>"><?php esc_html_e('بعداً قرائت و ثبت می‌کنم', 'hamnoor'); ?></button>
        <?php endif; ?>
    </div>
    <?php if (!$auto_done_on_claim) : ?><p class="hamnoor-help"><?php echo esc_html(Hamnoor_Texts::get('claim_success_help')); ?></p><?php endif; ?>
</div>
