<?php
if (!defined('ABSPATH')) { exit; }
$khatms = isset($khatms) && is_array($khatms) ? $khatms : array();
$create_url = Hamnoor_Helpers::page_url('create');
$featured_count = 0;
foreach ($khatms as $listed_khatm) {
    if (Hamnoor_Featured_Service::badge_data($listed_khatm)) { $featured_count++; }
}
$featured_position = 0;
?>
<section class="hamnoor-section-head hamnoor-list-head">
    <div>
        <h2><?php esc_html_e('ختم‌های عمومی قرآن', 'hamnoor'); ?></h2>
        <p><?php esc_html_e('در ختم‌های فعال شرکت کنید یا ختم‌های تکمیل‌شده را مشاهده کنید.', 'hamnoor'); ?></p>
    </div>
    <a class="hamnoor-btn hamnoor-btn-primary hamnoor-create-cta" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد ختم جدید', 'hamnoor'); ?></a>
</section>
<div class="hamnoor-khatm-grid hamnoor-featured-count-<?php echo (int) min(3, $featured_count); ?>">
    <?php foreach ($khatms as $khatm) :
        $progress = Hamnoor_DB::get_progress((int) $khatm->id);
        $is_completed = 'completed' === (string) $khatm->status;
        $card_context_class = '';
        if (Hamnoor_Featured_Service::badge_data($khatm)) {
            $featured_position++;
            $card_context_class = 'hamnoor-featured-position-' . min(4, $featured_position);
        }
        include Hamnoor_Helpers::locate_template('frontend/khatm-card.php');
        unset($card_context_class);
    endforeach; ?>
    <?php if (!$khatms) : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز ختم عمومی ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
</div>
