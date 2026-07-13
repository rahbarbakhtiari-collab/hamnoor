<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
if (!$khatm) { return; }
$name = isset($name) ? $name : '';
$phone = isset($phone) ? $phone : '';
$email = isset($email) ? $email : '';
$errors = isset($errors) && is_array($errors) ? $errors : array();
$auto_open = !empty($errors);
$field_error = function($key) use ($errors) { return isset($errors[$key]) ? '<span class="hamnoor-field-error" data-server-error="1" aria-live="polite">' . esc_html($errors[$key]) . '</span>' : ''; };
$field_class = function($key) use ($errors) { return isset($errors[$key]) ? ' hamnoor-field-has-error' : ''; };
?>
<div id="hamnoor-claim-modal" class="hamnoor-modal <?php echo esc_attr(Hamnoor_Helpers::visual_mode_class()); ?><?php echo $auto_open ? ' is-open' : ''; ?>" <?php echo $auto_open ? 'aria-hidden="false" data-hamnoor-auto-open="1"' : 'hidden aria-hidden="true"'; ?>>
    <div class="hamnoor-modal-backdrop" data-hamnoor-modal-close></div>
    <div class="hamnoor-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="hamnoor-claim-modal-title" aria-describedby="hamnoor-claim-modal-desc">
        <button type="button" class="hamnoor-modal-close" aria-label="<?php esc_attr_e('بستن', 'hamnoor'); ?>" data-hamnoor-modal-close>×</button>
        <div class="hamnoor-modal-body" data-hamnoor-modal-body>
            <h2 id="hamnoor-claim-modal-title"><?php esc_html_e('انتخاب سهم', 'hamnoor'); ?></h2>
            <p id="hamnoor-claim-modal-desc" class="hamnoor-selected-part-title"><?php echo esc_html(Hamnoor_Texts::get('claim_manual_desc', array('PART_TITLE' => ''))); ?></p>
            <div class="hamnoor-modal-feedback hamnoor-ajax-message" aria-live="polite"></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-claim-form" novalidate>
                <?php if ($errors) : ?><div class="hamnoor-notice hamnoor-notice-error hamnoor-form-summary" aria-live="polite"><?php echo esc_html(Hamnoor_Texts::get('form_incomplete')); ?></div><?php endif; ?>
                <?php wp_nonce_field('hamnoor_claim_part'); ?>
                <input type="hidden" name="action" value="hamnoor_claim_part">
                <input type="hidden" name="khatm_token" value="<?php echo esc_attr($khatm->token); ?>">
                <input type="hidden" id="hamnoor_part_id" name="part_id" value="">
                <input type="hidden" id="hamnoor_claim_mode" name="claim_mode" value="manual">
                <input type="hidden" id="hamnoor_random_free" name="random_free" value="">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>">
                <p class="hamnoor-hp"><label>Website<input type="text" name="website" autocomplete="off"></label></p>
                <label class="<?php echo esc_attr($field_class('name')); ?>"><?php esc_html_e('نام و نام خانوادگی', 'hamnoor'); ?><input required type="text" name="name" value="<?php echo esc_attr($name); ?>" autocomplete="name"<?php echo isset($errors['name']) ? ' aria-invalid="true"' : ''; ?>><?php echo $field_error('name'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
                <label class="<?php echo esc_attr($field_class('phone')); ?>"><?php esc_html_e('شماره موبایل', 'hamnoor'); ?><input required type="tel" name="phone" value="<?php echo esc_attr($phone); ?>" placeholder="09123456789" inputmode="tel" autocomplete="tel"<?php echo isset($errors['phone']) ? ' aria-invalid="true"' : ''; ?>><?php echo $field_error('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
                <label class="<?php echo esc_attr($field_class('email')); ?>"><?php esc_html_e('ایمیل اختیاری', 'hamnoor'); ?><input type="email" name="email" value="<?php echo esc_attr($email); ?>" autocomplete="email"<?php echo isset($errors['email']) ? ' aria-invalid="true"' : ''; ?>><?php echo $field_error('email'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
                <div class="hamnoor-modal-actions"><button class="hamnoor-btn hamnoor-btn-primary" type="submit" data-submit-text="<?php echo esc_attr(Hamnoor_Texts::get('claim_submit')); ?>" data-loading-text="<?php esc_attr_e('در حال ثبت...', 'hamnoor'); ?>"><?php echo esc_html(Hamnoor_Texts::get('claim_submit')); ?></button><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-hamnoor-modal-close><?php echo esc_html(Hamnoor_Texts::get('claim_cancel')); ?></button></div>
            </form>
        </div>
    </div>
</div>
