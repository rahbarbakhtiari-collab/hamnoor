<?php
if (!defined('ABSPATH')) { exit; }
$khatm = isset($khatm) ? $khatm : null;
if (!$khatm) { return; }
$errors = isset($errors) && is_array($errors) ? $errors : array();
$auto_open = !empty($errors);
$settings = Hamnoor_Helpers::settings();
$active_limit = !empty($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : (int)($settings['default_active_claim_limit'] ?? 3);
$window_enabled = !empty($settings['temporal_claim_quota_enabled']) && '1' === (string)$settings['temporal_claim_quota_enabled'] && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY !== (string)($khatm->access_mode ?? '');
?>
<div id="hamnoor-claim-modal" class="hamnoor-modal <?php echo esc_attr(Hamnoor_Helpers::visual_mode_class()); ?><?php echo $auto_open ? ' is-open' : ''; ?>" <?php echo $auto_open ? 'aria-hidden="false" data-hamnoor-auto-open="1"' : 'hidden aria-hidden="true"'; ?>>
    <div class="hamnoor-modal-backdrop" data-hamnoor-modal-close></div>
    <div class="hamnoor-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="hamnoor-claim-modal-title" aria-describedby="hamnoor-claim-modal-desc">
        <button type="button" class="hamnoor-modal-close" aria-label="<?php esc_attr_e('بستن', 'hamnoor'); ?>" data-hamnoor-modal-close>×</button>
        <div class="hamnoor-modal-body" data-hamnoor-modal-body>
            <h2 id="hamnoor-claim-modal-title"><?php esc_html_e('انتخاب سهم', 'hamnoor'); ?></h2>
            <p id="hamnoor-claim-modal-desc" class="hamnoor-selected-part-title"><?php echo esc_html(Hamnoor_Texts::get('claim_manual_desc', array('PART_TITLE' => ''))); ?></p>
            <div class="hamnoor-claim-quota" data-hamnoor-quota-state>
                <strong><?php esc_html_e('قواعد انتخاب سهم', 'hamnoor'); ?></strong>
                <p><?php echo esc_html($window_enabled ? sprintf(__('هم‌زمان حداکثر %1$d سهم ناتمام و در هر %2$d ساعت حداکثر %3$d انتخاب جدید مجاز است.', 'hamnoor'), $active_limit, (int)($settings['temporal_claim_window_hours'] ?? 24), (int)($settings['temporal_claim_limit'] ?? 3)) : sprintf(__('هم‌زمان حداکثر %d سهم ناتمام مجاز است.', 'hamnoor'), $active_limit)); ?></p>
                <p data-hamnoor-personal-quota aria-live="polite"></p>
            </div>
            <div class="hamnoor-modal-feedback hamnoor-ajax-message" aria-live="polite"></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-claim-form" novalidate data-hamnoor-identity-mode="loading">
                <?php if ($errors) : ?><div class="hamnoor-notice hamnoor-notice-error hamnoor-form-summary" aria-live="polite"><?php echo esc_html(Hamnoor_Texts::get('form_incomplete')); ?></div><?php endif; ?>
                <?php wp_nonce_field('hamnoor_claim_part'); ?>
                <input type="hidden" name="action" value="hamnoor_claim_part">
                <input type="hidden" name="khatm_token" value="<?php echo esc_attr($khatm->token); ?>">
                <input type="hidden" id="hamnoor_part_id" name="part_id" value="">
                <input type="hidden" id="hamnoor_claim_mode" name="claim_mode" value="manual">
                <input type="hidden" name="idempotency_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <input type="hidden" id="hamnoor_random_free" name="random_free" value="">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>">
                <p class="hamnoor-hp"><label>Website<input type="text" name="website" autocomplete="off"></label></p>
                <div class="hamnoor-claim-identity" data-hamnoor-claim-identity aria-live="polite">
                    <div class="hamnoor-identity-loading"><?php esc_html_e('در حال شناسایی پنل هم‌نور...', 'hamnoor'); ?></div>
                </div>
                <p class="hamnoor-help hamnoor-privacy-note"><?php esc_html_e('شماره موبایل برای اتصال سهم به داشبورد شما، بازیابی سهم‌ها و یادآوری‌های اختیاری استفاده می‌شود. شماره به‌صورت عمومی نمایش داده نمی‌شود و در نمای عادی سازندهٔ پویش نیز فقط به‌شکل پوشیده‌شده دیده می‌شود.', 'hamnoor'); ?></p>
                <div class="hamnoor-modal-actions">
                    <button class="hamnoor-btn hamnoor-btn-primary" type="submit" disabled data-submit-text="<?php echo esc_attr(Hamnoor_Texts::get('claim_submit')); ?>" data-loading-text="<?php esc_attr_e('در حال ثبت...', 'hamnoor'); ?>"><?php echo esc_html(Hamnoor_Texts::get('claim_submit')); ?></button>
                    <button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-hamnoor-modal-close><?php echo esc_html(Hamnoor_Texts::get('claim_cancel')); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
