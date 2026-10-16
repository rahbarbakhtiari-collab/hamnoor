<?php
if (!defined('ABSPATH')) { exit; }
$part = isset($part) ? $part : null;
if (!$part) { return; }
$khatm = isset($khatm) ? $khatm : null;
$context = isset($context) ? (string) $context : 'single';
$claimable = !empty($claimable);
$contact = isset($contact) ? (string) $contact : '';
$access = isset($access) ? Hamnoor_Helpers::sanitize_tracking_token((string) $access) : '';
$card_data = apply_filters('hamnoor_part_card_data', array('part' => $part, 'khatm' => $khatm, 'context' => $context, 'claimable' => $claimable, 'contact' => $contact, 'access' => $access), $part, $khatm);
if (is_array($card_data)) {
    if (!empty($card_data['part']) && is_object($card_data['part'])) { $part = $card_data['part']; }
    if (array_key_exists('khatm', $card_data) && (is_object($card_data['khatm']) || null === $card_data['khatm'])) { $khatm = $card_data['khatm']; }
    if (isset($card_data['context'])) { $context = sanitize_key($card_data['context']); }
    if (isset($card_data['claimable'])) { $claimable = (bool) $card_data['claimable']; }
    if (isset($card_data['contact'])) { $contact = sanitize_text_field($card_data['contact']); }
    if (isset($card_data['access'])) { $access = Hamnoor_Helpers::sanitize_tracking_token((string) $card_data['access']); }
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
$is_khatm_published = !$khatm || in_array((string) $khatm->status, array('published','active','paused','expired','completed'), true);
$current_user_id = Hamnoor_Mobile_Access::current_user_id();
$is_current_user_part = $current_user_id && !empty($part->participant_user_id) && (int) $part->participant_user_id === (int) $current_user_id;
$reading_context_allowed = in_array($context, array('dashboard','track'), true) || ('single' === $context && $is_current_user_part);
$reading_access = (!Hamnoor_Mobile_Access::is_logged_in() && $access) ? $access : '';
$reading_state = ($reading_context_allowed && class_exists('Hamnoor_Reading')) ? Hamnoor_Reading::card_state($part, $reading_access) : null;
$show_reading_progress = in_array($context, array('dashboard','track'), true);
$reading_progress = ($reading_state && !empty($reading_state['progress_record'])) ? $reading_state['progress_record'] : null;
$reminder_enabled = !$reading_progress || !isset($reading_progress->reminder_enabled) ? true : (bool) $reading_progress->reminder_enabled;
$hamnoor_filter_status = $is_done ? 'done' : (($reading_state && !empty($reading_state['ready'])) ? 'ready' : 'active');
$hamnoor_claimed_ts = !empty($part->claimed_at) ? strtotime((string)$part->claimed_at) : 0;
$hamnoor_activity_ts = !empty($part->updated_at) ? strtotime((string)$part->updated_at) : $hamnoor_claimed_ts;
if ($reading_progress && !empty($reading_progress->updated_at)) { $hamnoor_activity_ts = max($hamnoor_activity_ts, strtotime((string)$reading_progress->updated_at)); }
$hamnoor_deadline_ts = 0;
if (!$is_done && $khatm) {
    $hamnoor_acceptance_end_ts = !empty($khatm->ends_at) ? strtotime((string)$khatm->ends_at) : 0;
    if ($hamnoor_claimed_ts && isset($khatm->auto_release_mode) && 'after_days' === (string)$khatm->auto_release_mode && !empty($khatm->auto_release_after_days)) {
        $hamnoor_release_due_ts = $hamnoor_claimed_ts + ((int)$khatm->auto_release_after_days * DAY_IN_SECONDS);
        if (!$hamnoor_acceptance_end_ts || $hamnoor_release_due_ts <= $hamnoor_acceptance_end_ts) { $hamnoor_deadline_ts = $hamnoor_release_due_ts; }
    }
    if ($hamnoor_acceptance_end_ts && !empty($khatm->completion_grace_days)) {
        $hamnoor_completion_deadline_ts = $hamnoor_acceptance_end_ts + ((int)$khatm->completion_grace_days * DAY_IN_SECONDS);
        if (!$hamnoor_deadline_ts || $hamnoor_completion_deadline_ts < $hamnoor_deadline_ts) { $hamnoor_deadline_ts = $hamnoor_completion_deadline_ts; }
    }
}
$hamnoor_khatm_id = $khatm ? (int)$khatm->id : 0;
$hamnoor_card_search = trim((string)$part->title . ' ' . (string)$part->part_number . ' ' . ($khatm ? (string)$khatm->title : ''));
$hamnoor_context_class = 'hamnoor-part-card-context-' . sanitize_html_class($context);
?>
<div class="hamnoor-part-card hamnoor-part-card--unified <?php echo esc_attr($hamnoor_context_class); ?> hamnoor-part-<?php echo esc_attr($part->status); ?><?php echo $is_done ? ' hamnoor-part-card--completed' : ' hamnoor-part-card--active'; ?><?php echo $reading_state && !empty($reading_state['ready']) ? ' hamnoor-part-card--ready' : ''; ?><?php echo ('single' === $context && $is_current_user_part) ? ' hamnoor-part-card--mine' : ''; ?>" data-share-card-type="quran" data-part-card="<?php echo (int) $part->id; ?>" data-status="<?php echo esc_attr($part->status); ?>" data-filter-status="<?php echo esc_attr($hamnoor_filter_status); ?>" data-khatm-id="<?php echo (int)$hamnoor_khatm_id; ?>" data-claimed-ts="<?php echo (int)$hamnoor_claimed_ts; ?>" data-deadline-ts="<?php echo (int)$hamnoor_deadline_ts; ?>" data-activity-ts="<?php echo (int)$hamnoor_activity_ts; ?>" data-search="<?php echo esc_attr($hamnoor_card_search); ?>">
    <div class="hamnoor-part-card-main">
        <?php if (in_array($context, array('track','dashboard'), true) && $khatm) : ?>
            <strong class="hamnoor-part-khatm-title">
                <?php if ($is_khatm_published) : ?><a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php echo esc_html($part->khatm_title); ?></a><?php else : ?><?php echo esc_html($part->khatm_title); ?><?php endif; ?>
            </strong>
        <?php endif; ?>
        <div class="hamnoor-part-title"><?php echo esc_html($part->title); ?></div>
        <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($part->status); ?>"><?php echo esc_html($status_label); ?></span>
        <?php if ($show_name) : ?><span class="hamnoor-participant<?php echo $is_done ? ' hamnoor-reader-name' : ''; ?>"><?php echo esc_html($participant_name); ?></span><?php endif; ?>
        <?php if ($reading_state && $show_reading_progress) : ?>
            <div class="hamnoor-part-reading-state">
                <div class="hamnoor-part-reading-progress"><span style="width:<?php echo esc_attr($reading_state['percent']); ?>%"></span></div>
                <small><?php echo esc_html($reading_state['completed'] ? __('✓ قرائت تکمیل شد', 'hamnoor') : (!empty($reading_state['ready']) ? __('آخرین آیه ثبت شد؛ پایان سهم را ثبت کنید.', 'hamnoor') : sprintf(__('پیشرفت: %s٪', 'hamnoor'), $reading_state['percent']))); ?></small>
            </div>
        <?php endif; ?>
        <?php if ('dashboard' === $context && $reading_state && !$is_done) : ?>
            <div class="hamnoor-part-engagement">
                <label class="hamnoor-reminder-toggle"><input type="checkbox" data-part-reminder-toggle data-part-id="<?php echo (int)$part->id; ?>" <?php checked($reminder_enabled); ?>> <span><?php esc_html_e('یادآوری سهم ناتمام', 'hamnoor'); ?></span></label>
            </div>
        <?php endif; ?>
    </div>
    <div class="hamnoor-part-actions">
        <?php if (!$is_khatm_published) : ?>
            <span class="hamnoor-status hamnoor-status-pending_review"><?php esc_html_e('در انتظار تأیید مدیر', 'hamnoor'); ?></span>
        <?php else : ?>
        <?php if ($reading_state) : ?><a class="hamnoor-btn hamnoor-btn-primary hamnoor-reading-link" href="<?php echo esc_url($reading_state['url']); ?>"><?php echo esc_html($reading_state['completed'] ? Hamnoor_Texts::get('reading_review_button') : (!empty($reading_state['ready']) ? Hamnoor_Texts::get('reading_finish_button') : ($reading_state['started'] ? Hamnoor_Texts::get('reading_continue_button') : Hamnoor_Texts::get('reading_start_button')))); ?></a><?php endif; ?>
        <?php if ('single' === $context && $is_available && $claimable) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-choose-part" data-part-id="<?php echo (int) $part->id; ?>" data-part-title="<?php echo esc_attr($part->title); ?>"><span class="hamnoor-claim-label-desktop"><?php esc_html_e('انتخاب این سهم', 'hamnoor'); ?></span><span class="hamnoor-claim-label-mobile"><?php esc_html_e('انتخاب', 'hamnoor'); ?></span></button>
        <?php elseif ('single' === $context && $is_available && !$claimable) : ?>
            <?php $closed_part_text = ($khatm && 'paused' === (string)$khatm->status) ? Hamnoor_Texts::get('claim_window_paused_part') : Hamnoor_Texts::get('claim_window_closed_part'); ?>
            <small class="hamnoor-part-claim-closed-note"><?php echo esc_html($closed_part_text); ?></small>
        <?php elseif ('track' === $context && !$is_done && !$auto_done_mode && $contact) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" data-completion-source="manual" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hamnoor_complete_contact_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_contact_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="contact" value="<?php echo esc_attr($contact); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
        <?php elseif ('dashboard' === $context && !$is_done && !$auto_done_mode && !empty($part->tracking_token)) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" data-completion-source="manual" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hamnoor_complete_part_' . (int) $part->id); ?>
                <input type="hidden" name="action" value="hamnoor_complete_part">
                <input type="hidden" name="part_id" value="<?php echo (int) $part->id; ?>">
                <input type="hidden" name="access" value="<?php echo esc_attr($part->tracking_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url(Hamnoor_Helpers::page_url('dashboard')); ?>">
                <button class="hamnoor-btn hamnoor-btn-success" type="submit"><?php echo esc_html(Hamnoor_Texts::get('complete_button')); ?></button>
            </form>
        <?php elseif ($can_manager_complete && !$auto_done_mode) : ?>
            <form method="post" class="hamnoor-complete-form" data-hamnoor-complete="1" data-completion-source="manual" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
        <?php endif; ?>
    </div>
</div>
