<?php
if (!defined('ABSPATH')) { exit; }
$redirect = isset($redirect) ? $redirect : Hamnoor_Helpers::page_url('dashboard');
$old = isset($old) && is_array($old) ? $old : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$field_error = function($key) use ($errors) { return isset($errors[$key]) ? '<span class="hamnoor-field-error" aria-live="polite">' . esc_html($errors[$key]) . '</span>' : ''; };
$field_class = function($key) use ($errors) { return isset($errors[$key]) ? ' hamnoor-field-has-error' : ''; };
?>
<section class="hamnoor-auth-card hamnoor-register-page">
    <h2><?php esc_html_e('ثبت‌نام در هم‌نور', 'hamnoor'); ?></h2>
    <p><?php echo esc_html(Hamnoor_Texts::get('register_desc')); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-auth-form" novalidate>
        <?php if ($errors) : ?><div class="hamnoor-notice hamnoor-notice-error hamnoor-form-summary"><?php echo esc_html(Hamnoor_Texts::get('form_incomplete')); ?></div><?php endif; ?>
        <?php wp_nonce_field('hamnoor_register_user'); ?>
        <input type="hidden" name="action" value="hamnoor_register_user">
        <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">
        <p class="hamnoor-hp"><label>Website<input type="text" name="website" autocomplete="off"></label></p>
        <label class="<?php echo esc_attr($field_class('display_name')); ?>"><?php esc_html_e('نام و نام خانوادگی', 'hamnoor'); ?><input required type="text" name="display_name" autocomplete="name" value="<?php echo esc_attr(isset($old['display_name']) ? $old['display_name'] : ''); ?>"><?php echo $field_error('display_name'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        <label class="<?php echo esc_attr($field_class('phone')); ?>"><?php esc_html_e('موبایل', 'hamnoor'); ?><input required type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="09123456789" value="<?php echo esc_attr(isset($old['phone']) ? $old['phone'] : ''); ?>"><?php echo $field_error('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        <label class="<?php echo esc_attr($field_class('email')); ?>"><?php esc_html_e('ایمیل', 'hamnoor'); ?><input required type="email" name="email" autocomplete="email" value="<?php echo esc_attr(isset($old['email']) ? $old['email'] : ''); ?>"><?php echo $field_error('email'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        <label class="<?php echo esc_attr($field_class('password')); ?>"><?php esc_html_e('رمز عبور', 'hamnoor'); ?><input required type="password" name="password" minlength="8" autocomplete="new-password"><?php echo $field_error('password'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        <label class="<?php echo esc_attr($field_class('password_confirm')); ?>"><?php esc_html_e('تکرار رمز عبور', 'hamnoor'); ?><input required type="password" name="password_confirm" minlength="8" autocomplete="new-password"><?php echo $field_error('password_confirm'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('ثبت‌نام و ورود', 'hamnoor'); ?></button>
        <p class="hamnoor-auth-switch"><a href="<?php echo esc_url(add_query_arg('redirect_to', rawurlencode($redirect), Hamnoor_Helpers::page_url('login'))); ?>"><?php esc_html_e('قبلاً ثبت‌نام کرده‌اید؟ وارد شوید', 'hamnoor'); ?></a></p>
    </form>
</section>
