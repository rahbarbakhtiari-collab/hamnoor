<?php
if (!defined('ABSPATH')) { exit; }
$redirect = isset($redirect) ? $redirect : Hamnoor_Helpers::page_url('dashboard');
?>
<section class="hamnoor-auth-card hamnoor-login-page">
    <h2><?php esc_html_e('ورود به هم‌نور', 'hamnoor'); ?></h2>
    <p><?php echo esc_html(Hamnoor_Texts::get('login_desc')); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-auth-form" novalidate>
        <?php wp_nonce_field('hamnoor_login_user'); ?>
        <input type="hidden" name="action" value="hamnoor_login_user">
        <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">
        <label><?php esc_html_e('موبایل یا ایمیل', 'hamnoor'); ?><input required type="text" name="log" inputmode="text" autocomplete="username" placeholder="09123456789 یا example@email.com"></label>
        <label><?php esc_html_e('رمز عبور', 'hamnoor'); ?><input required type="password" name="pwd" autocomplete="current-password"></label>
        <label class="hamnoor-check"><input type="checkbox" name="remember" value="1"> <?php esc_html_e('مرا به خاطر بسپار', 'hamnoor'); ?></label>
        <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('ورود', 'hamnoor'); ?></button>
        <p class="hamnoor-auth-switch"><a href="<?php echo esc_url(add_query_arg('redirect_to', rawurlencode($redirect), Hamnoor_Helpers::page_url('register'))); ?>"><?php esc_html_e('حساب ندارید؟ ثبت‌نام کنید', 'hamnoor'); ?></a></p>
    </form>
</section>
