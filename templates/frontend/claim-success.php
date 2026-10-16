<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
$part = isset($part) ? $part : null;
$tracking_token = isset($tracking_token) ? (string) $tracking_token : '';
if (!$khatm || !$part) { return; }
$auto_done_on_claim = (isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode) || ('done' === (string) $part->status);
$internal_reading = !$auto_done_on_claim && class_exists('Hamnoor_Reading') && Hamnoor_Reading::available();
$internal_reading_url = $internal_reading ? Hamnoor_Reading::reading_url((int) $part->id, $tracking_token) : '';
$release_warning = __('سهم برای شما رزرو شد. لینک اختصاصی این سهم را نگه دارید؛ برای دیدن همه سهم‌ها و تنظیم یادآوری، با کد یک‌بارمصرف وارد داشبورد شوید.', 'hamnoor');
$reminders_url = add_query_arg(array('tab'=>'reminders','part'=>(int)$part->id), Hamnoor_Helpers::page_url('dashboard'));
if ($khatm && isset($khatm->auto_release_mode) && 'after_days' === (string) $khatm->auto_release_mode && !empty($khatm->auto_release_after_days)) {
    $release_warning = sprintf(__('سهم برای شما رزرو شد. لینک اختصاصی این سهم را نگه دارید. اگر تا %d روز پس از انتخاب سهم، قرائت ثبت نشود، این سهم ممکن است دوباره آزاد شود.', 'hamnoor'), (int) $khatm->auto_release_after_days);
}
?>
<div class="hamnoor-claim-success hamnoor-claim-success-card hamnoor-share-surface" data-part-id="<?php echo (int) $part->id; ?>">
    <h3><?php echo esc_html($auto_done_on_claim ? __('سهم شما ثبت و انجام‌شده محسوب شد. خداوند قبول فرماید.', 'hamnoor') : Hamnoor_Texts::get('claim_success_title')); ?></h3>
    <dl>
        <dt><?php esc_html_e('نام پویش', 'hamnoor'); ?></dt><dd><?php echo esc_html($khatm->title); ?></dd>
        <dt><?php esc_html_e('سهم شما', 'hamnoor'); ?></dt><dd><?php echo esc_html($part->title); ?></dd>
    </dl>
    <?php if ($auto_done_on_claim) : ?>
        <p class="hamnoor-help"><?php esc_html_e('این سهم با انتخاب شما خوانده‌شده محسوب شد و نیازی به ثبت جداگانه قرائت نیست.', 'hamnoor'); ?></p>
    <?php else : ?>
        <p class="hamnoor-help"><?php echo esc_html($internal_reading ? __('اکنون می‌توانید قرائت را در قرآن هم‌نور آغاز کنید؛ محل توقف شما ذخیره می‌شود و بعداً از همان آیه ادامه می‌دهید.', 'hamnoor') : __('بعد از انتخاب سهم، می‌توانید پس از قرائت همین‌جا دکمه «قرائت انجام شد» را بزنید. لینک اختصاصی این سهم نیز برای مراجعه بعدی قابل استفاده است.', 'hamnoor')); ?></p>
    <?php endif; ?>
    <div class="hamnoor-ajax-message" aria-live="polite"></div>
    <?php if (!$auto_done_on_claim) : ?>
        <div class="hamnoor-claim-next-step">
            <strong><?php esc_html_e('قدم بعدی', 'hamnoor'); ?></strong>
            <p><?php echo esc_html($internal_reading ? __('قرائت سهم را شروع کنید؛ هم‌نور محل توقف شما را نگه می‌دارد.', 'hamnoor') : __('سهم را در زمان مناسب بخوانید و پس از پایان، از همین لینک انجام قرائت را ثبت کنید.', 'hamnoor')); ?></p>
        </div>
    <?php endif; ?>
    <div class="hamnoor-modal-actions hamnoor-claim-success-actions">
        <?php if ($internal_reading) : ?><a class="hamnoor-btn hamnoor-btn-primary hamnoor-claim-reading-primary" href="<?php echo esc_url($internal_reading_url); ?>"><?php echo esc_html(Hamnoor_Texts::get('reading_start_button')); ?></a><?php endif; ?>
        <?php if (!$auto_done_on_claim) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-claim-later" data-hamnoor-complete-later data-warning="<?php echo esc_attr($release_warning); ?>"><?php echo esc_html($internal_reading ? __('قرائت را بعداً شروع می‌کنم', 'hamnoor') : __('سهم را نگه می‌دارم و بعداً می‌خوانم', 'hamnoor')); ?></button>
        <?php endif; ?>
    </div>
    <?php if (!$auto_done_on_claim) : ?>
        <details class="hamnoor-claim-complete-already">
            <summary><?php esc_html_e('این سهم را قبلاً خوانده‌ام', 'hamnoor'); ?></summary>
            <p class="hamnoor-help"><?php esc_html_e('فقط اگر قرائت این سهم واقعاً انجام شده است، آن را به‌عنوان انجام‌شده ثبت کنید.', 'hamnoor'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-complete-form" data-hamnoor-complete="1" data-completion-source="manual" data-hamnoor-confirm-complete="<?php echo esc_attr__('آیا مطمئنید قرائت این سهم را کامل انجام داده‌اید؟', 'hamnoor'); ?>">
                <?php wp_nonce_field('hamnoor_complete_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="access" value="<?php echo esc_attr($tracking_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::part_access_url($khatm, (int) $part->id, $tracking_token)); ?>">
                <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('ثبت قرائت انجام‌شده', 'hamnoor'); ?></button>
            </form>
        </details>
    <?php endif; ?>
    <?php if (!$auto_done_on_claim) : ?>
        <div class="hamnoor-notice hamnoor-notice-info hamnoor-claim-reminder-cta">
            <strong><?php esc_html_e('می‌خواهید اگر این سهم ناتمام ماند، هم‌نور به شما یادآوری کند؟', 'hamnoor'); ?></strong>
            <p><?php esc_html_e('یادآوری اختیاری است و کانال‌های دریافت آن را هر زمان می‌توانید از داشبورد تغییر دهید یا خاموش کنید.', 'hamnoor'); ?></p>
            <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($reminders_url); ?>"><?php esc_html_e('ورود امن و فعال‌کردن یادآوری', 'hamnoor'); ?></a>
        </div>
        <p class="hamnoor-help"><?php echo esc_html(Hamnoor_Texts::get('claim_success_help')); ?></p>
    <?php endif; ?>
</div>
