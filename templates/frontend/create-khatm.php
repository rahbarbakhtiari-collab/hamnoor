<?php
if (!defined('ABSPATH')) { exit; }
// قالب واقعی فرم ایجاد/ویرایش ختم. منطق فرم در کلاس آماده می‌شود و این template امکان override قالب را فراهم می‌کند.
$form_html = isset($form_html) ? $form_html : '';
?>
<section class="hamnoor-create-template <?php echo !empty($editing) ? 'is-editing' : 'is-creating'; ?>">
    <?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- prepared and escaped before rendering. ?>
</section>
