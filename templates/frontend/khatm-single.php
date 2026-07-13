<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
$khatm = !empty($GLOBALS['hamnoor_current_khatm']) ? $GLOBALS['hamnoor_current_khatm'] : null;
$design_class = $khatm ? Hamnoor_Helpers::design_class($khatm) : 'hamnoor-style-default';
$inline_style = $khatm ? Hamnoor_Helpers::khatm_inline_style($khatm) : '';
?>
<main id="primary" class="site-main hamnoor-template-main">
    <div class="hamnoor-wrap <?php echo esc_attr(Hamnoor_Helpers::visual_mode_class() . ' ' . $design_class); ?>" dir="rtl"<?php echo $inline_style ? ' style="' . esc_attr($inline_style) . '"' : ''; ?>>
        <?php
        if (!empty($GLOBALS['hamnoor_not_found'])) {
            echo '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('ختم مورد نظر پیدا نشد.', 'hamnoor') . '</div>';
        } elseif (!empty($GLOBALS['hamnoor_unpublished_restricted'])) {
            echo '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این ختم هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor') . '</div>';
        } elseif ($khatm) {
            echo Hamnoor_Frontend::instance()->render_khatm($khatm);
        }
        ?>
    </div>
</main>
<?php
get_footer();
