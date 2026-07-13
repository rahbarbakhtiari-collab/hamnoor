<?php
if (!defined('ABSPATH')) { exit; }
$khatms = isset($khatms) && is_array($khatms) ? $khatms : array();
?>
<section class="hamnoor-section-head hamnoor-list-head">
    <h2><?php esc_html_e('ختم‌های عمومی قرآن', 'hamnoor'); ?></h2>
    <p><?php esc_html_e('در ختم‌های فعال شرکت کنید یا ختم‌های تکمیل‌شده را مشاهده کنید.', 'hamnoor'); ?></p>
</section>
<div class="hamnoor-khatm-grid">
    <?php foreach ($khatms as $khatm) : $progress = Hamnoor_DB::get_progress((int) $khatm->id); $is_completed = 'completed' === (string) $khatm->status; include Hamnoor_Helpers::locate_template('frontend/khatm-card.php'); endforeach; ?>
    <?php if (!$khatms) : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز ختم عمومی ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
</div>
