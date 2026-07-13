<?php
if (!defined('ABSPATH')) { exit; }
$parts = isset($parts) && is_array($parts) ? $parts : array();
$contact = isset($contact) ? (string) $contact : '';
$has_open_parts = false;
foreach ($parts as $part) { if ('done' !== (string) $part->status) { $has_open_parts = true; break; } }
?>
<div class="hamnoor-track-results-inner">
<?php if ($parts) : ?>
    <div class="hamnoor-result-count"><?php echo esc_html(Hamnoor_Texts::get('track_found', array('COUNT' => count($parts)))); ?></div>
    <?php if ($has_open_parts) : ?><p class="hamnoor-help hamnoor-once-help"><?php echo esc_html(Hamnoor_Texts::get('parts_done_help')); ?></p><?php else : ?><p class="hamnoor-help hamnoor-once-help"><?php echo esc_html(Hamnoor_Texts::get('track_all_done')); ?></p><?php endif; ?>
    <div class="hamnoor-parts-grid hamnoor-my-parts-grid">
        <?php foreach ($parts as $part) : $khatm = Hamnoor_DB::get_khatm($part->khatm_token); if (!$khatm) { continue; } $context = 'track'; include Hamnoor_Helpers::locate_template('frontend/part-card.php'); endforeach; ?>
    </div>
<?php else : ?>
    <p class="hamnoor-empty"><?php echo esc_html(Hamnoor_Texts::get('track_empty_help')); ?></p>
<?php endif; ?>
</div>
