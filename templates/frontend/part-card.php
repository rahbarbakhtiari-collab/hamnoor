<?php
if (!defined('ABSPATH')) { exit; }
$part = isset($part) ? $part : null;
if (!$part) { return; }
$khatm = isset($khatm) ? $khatm : null;
$context = isset($context) ? (string) $context : 'single';
$claimable = !empty($claimable);
$contact = isset($contact) ? (string) $contact : '';
$card_data = apply_filters('hamnoor_part_card_data', array('part' => $part, 'khatm' => $khatm, 'context' => $context, 'claimable' => $claimable, 'contact' => $contact), $part, $khatm);
if (is_array($card_data)) {
    if (!empty($card_data['part']) && is_object($card_data['part'])) { $part = $card_data['part']; }
    if (array_key_exists('khatm', $card_data) && (is_object($card_data['khatm']) || null === $card_data['khatm'])) { $khatm = $card_data['khatm']; }
    if (isset($card_data['context'])) { $context = sanitize_key($card_data['context']); }
    if (isset($card_data['claimable'])) { $claimable = (bool) $card_data['claimable']; }
    if (isset($card_data['contact'])) { $contact = sanitize_text_field($card_data['contact']); }
}
$status_label = Hamnoor_Helpers::status_label($part->status, 'part');
$is_done = 'done' === (string) $part->status;
$is_available = 'available' === (string) $part->status;
$name_display_enabled = 'hidden' !== Hamnoor_Helpers::settings()['participant_name_display'];
$show_name = !$is_available && $name_display_enabled && ($is_done || !empty($part->participant_name));
$participant_name = !empty($part->participant_name) ? Hamnoor_Helpers::display_name($part->participant_name) : __('شرکت‌کننده', 'hamnoor');
$is_claimed = 'claimed' === (string) $part->status;
$auto_done_mode = $khatm && isset($khatm->completion_mode) && 'auto_done_on_claim' === (string) $khatm->completion_mode;
$can_manage_part = $khatm && Hamnoor_DB::user_can_manage_khatm((int) $khatm->id);
$can_release = $is_claimed && $can_manage_part;
$can_participant_release = $is_claimed && !$is_done && (('track' === $context && $contact) || ('dashboard' === $context && !empty($part->tracking_token)));
$can_manager_complete = $is_claimed && !$is_done && $can_manage_part && 'single' === $context;
?>
<div class="hamnoor-part-card hamnoor-part-<?php echo esc_attr($part->status); ?>" data-part-card="<?php echo (int) $part->id; ?>" data-status="<?php echo esc_attr($part->status); ?>" data-search="<?php echo esc_attr($part->title . ' ' . $part->part_number); ?>">
    <div class="hamnoor-part-card-main">
        <?php if (in_array($context, array('track','dashboard'), true) && $khatm) : ?>
            <strong class="hamnoor-part-khatm-title"><a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php echo esc_html($part->khatm_title); ?></a></strong>
        <?php endif; ?>
        <div class="hamnoor-part-title"><?php echo esc_html($part->title); ?></div>
        <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($part->status); ?>"><?php echo esc_html($status_label); ?></span>
        <?php if ($show_name) : ?><span class="hamnoor-participant<?php echo $is_done ? ' hamnoor-reader-name' : ''; ?>"><?php echo $is_done ? esc_html(sprintf(__('خوانده‌شده توسط: %s', 'hamnoor'), $participant_name)) : esc_html($participant_name); ?></span><?php endif; ?>
    </div>
    <div class="hamnoor-part-actions">
        <?php if (!empty($part->quran_url)) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-quran-link" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($part->quran_url); ?>"><?php esc_html_e('مشاهده قرآن', 'hamnoor'); ?></a><?php endif; ?>
        <?php if ('single' === $context && $is_available && $claimable) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-choose-part" data-part-id="<?php echo (int) $part->id; ?>" data-part-title="<?php echo esc_attr($part->title); ?>"><?php esc_html_e('انتخاب این سهم', 'hamnoor'); ?></button>
        <?php elseif ('track' === $context && !$is_done && !$auto_done_mode && $contact) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hamnoor_complete_contact_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_contact_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="contact" value="<?php echo esc_attr($contact); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
        <?php elseif ('dashboard' === $context && !$is_done && !$auto_done_mode && !empty($part->tracking_token)) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hamnoor_complete_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="access" value="<?php echo esc_attr($part->tracking_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::page_url('dashboard')); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
        <?php elseif ($can_manager_complete && !$auto_done_mode) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hamnoor_complete_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url($khatm ? Hamnoor_Helpers::invite_url($khatm) : Hamnoor_Helpers::page_url('dashboard')); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
        <?php endif; ?>
        <?php if ($can_release || $can_participant_release) : ?>
            <form method="post" class="hamnoor-release-form" data-hamnoor-release="1" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <?php if ('track' === $context && $contact) : ?><input type="hidden" name="contact" value="<?php echo esc_attr($contact); ?>"><?php endif; ?>
                <?php if ('dashboard' === $context && !empty($part->tracking_token)) : ?><input type="hidden" name="access" value="<?php echo esc_attr($part->tracking_token); ?>"><?php endif; ?>
                <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('آزاد کردن سهم', 'hamnoor'); ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
