<?php
if (!defined('ABSPATH')) { exit; }
$contact_raw = isset($contact_raw) ? $contact_raw : '';
$contact = isset($contact) ? $contact : '';
$contact_error = isset($contact_error) ? $contact_error : '';
$parts = isset($parts) && is_array($parts) ? $parts : array();
?>
<section class="hamnoor-track-page">
    <div class="hamnoor-card hamnoor-track-card">
        <h2><?php echo esc_html(Hamnoor_Texts::get('track_title')); ?></h2>
        <p><?php esc_html_e('اگر قبلاً سهمی انتخاب کرده‌اید، موبایل یا ایمیلی را که هنگام انتخاب سهم وارد کرده‌اید بنویسید تا سهم‌هایتان نمایش داده شود.', 'hamnoor'); ?></p>
        <form class="hamnoor-track-form hamnoor-form" method="get" data-hamnoor-track="1" novalidate>
            <label class="<?php echo esc_attr($contact_error ? 'hamnoor-field-has-error' : ''); ?>">
                <?php esc_html_e('موبایل یا ایمیل', 'hamnoor'); ?>
                <input type="text" name="hamnoor_contact" value="<?php echo esc_attr($contact_raw); ?>" placeholder="09123456789 یا example@email.com" inputmode="text" aria-invalid="<?php echo $contact_error ? 'true' : 'false'; ?>">
                <?php if ($contact_error) : ?><span class="hamnoor-field-error" aria-live="polite"><?php echo esc_html($contact_error); ?></span><?php endif; ?>
            </label>
            <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('نمایش سهم‌های من', 'hamnoor'); ?></button>
        </form>
    </div>
    <div class="hamnoor-track-results" data-hamnoor-track-results="1" aria-live="polite">
    <?php if ($contact_raw && !$contact_error) : include Hamnoor_Helpers::locate_template('frontend/track-results.php'); endif; ?>
    </div>
</section>
