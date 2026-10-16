<?php
if (!defined('ABSPATH')) { exit; }
$is_done = 'done' === (string) $part->status;
$ready = $is_done || $last >= (int) $range['end_global_index'];
$reading_access = isset($reading_access) ? Hamnoor_Helpers::sanitize_tracking_token((string) $reading_access) : '';
$scoped_guest_access = isset($scoped_guest_access) ? (bool) $scoped_guest_access : false;
$dashboard_url = add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard'));
if ($scoped_guest_access && $khatm && $reading_access) { $dashboard_url = Hamnoor_Helpers::part_access_url($khatm, (int) $part->id, $reading_access); }
$campaign_url = $khatm ? Hamnoor_Helpers::khatm_url($khatm) : Hamnoor_Helpers::page_url('list');
$back_label = $scoped_guest_access ? __('بازگشت به سهم من', 'hamnoor') : __('بازگشت به سهم‌های من', 'hamnoor');
$previous_surah = 0;
$reading_settings = Hamnoor_Helpers::settings();
$default_arabic_size = max(28, min(56, (int)($reading_settings['reading_arabic_size'] ?? 38)));
$default_translation_size = max(14, min(28, (int)($reading_settings['reading_translation_size'] ?? 18)));
$started = $last >= (int) $range['start_global_index'];
$verse_count = (int) $range['end_global_index'] - (int) $range['start_global_index'] + 1;
?>
<section class="hamnoor-reading hamnoor-reading-phase7<?php echo $is_done ? ' is-review-mode' : ''; ?>" data-hamnoor-reading="1" data-reading-mode="part" data-reading-state="<?php echo esc_attr($is_done ? 'completed' : ($ready ? 'ready' : ($started ? 'reading' : 'not_started'))); ?>" data-part-id="<?php echo (int) $part->id; ?>" data-start-index="<?php echo (int) $range['start_global_index']; ?>" data-end-index="<?php echo (int) $range['end_global_index']; ?>" data-last-index="<?php echo (int) $last; ?>" data-resume-index="<?php echo (int) $resume; ?>" data-audio-resume-index="<?php echo (int) $audio_resume; ?>" data-audio-position="<?php echo esc_attr($audio_position_seconds); ?>" data-default-arabic-size="<?php echo (int)$default_arabic_size; ?>" data-default-translation-size="<?php echo (int)$default_translation_size; ?>" aria-labelledby="hamnoor-reading-title">
    <a class="hamnoor-reading-skip-link" href="#hamnoor-reading-content"><?php esc_html_e('پرش به متن قرائت', 'hamnoor'); ?></a>
    <button type="button" class="hamnoor-reading-focus-exit" data-reading-focus-exit hidden aria-label="<?php esc_attr_e('خروج از حالت مطالعه بدون مزاحمت', 'hamnoor'); ?>">
        <span aria-hidden="true">×</span><span><?php esc_html_e('خروج از مطالعه بدون مزاحمت', 'hamnoor'); ?></span>
    </button>

    <div class="hamnoor-reading-chrome" data-reading-chrome>
        <header class="hamnoor-reading-header">
            <a class="hamnoor-reading-back" href="<?php echo esc_url($dashboard_url); ?>" aria-label="<?php echo esc_attr($back_label); ?>">
                <span aria-hidden="true">→</span><span class="hamnoor-reading-back-label"><?php echo esc_html($back_label); ?></span>
            </a>
            <div class="hamnoor-reading-heading">
                <span class="hamnoor-reading-khatm"><?php echo esc_html($khatm ? $khatm->title : __('پویش قرآن', 'hamnoor')); ?></span>
                <h1 id="hamnoor-reading-title"><?php echo esc_html($is_done ? __('مرور سهم', 'hamnoor') : __('سهم من', 'hamnoor')); ?></h1>
                <p><strong><?php echo esc_html($part->title); ?></strong><span aria-hidden="true"> · </span><?php echo esc_html($range['label']); ?></p>
            </div>
            <div class="hamnoor-reading-header-actions">
                <div class="hamnoor-reading-settings" data-reading-settings>
                    <button type="button" class="hamnoor-reading-settings-toggle" data-reading-settings-toggle aria-expanded="false" aria-haspopup="dialog" aria-label="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>" title="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>">
                        <span aria-hidden="true">Aa</span><span class="screen-reader-text"><?php esc_html_e('تنظیمات مطالعه', 'hamnoor'); ?></span>
                    </button>
                    <div class="hamnoor-reading-settings-panel" data-reading-settings-panel hidden role="dialog" aria-modal="true" tabindex="-1" aria-label="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>">
                        <div class="hamnoor-reading-settings-head"><strong><?php esc_html_e('تنظیمات مطالعه', 'hamnoor'); ?></strong><button type="button" data-reading-settings-close aria-label="<?php esc_attr_e('بستن تنظیمات', 'hamnoor'); ?>">×</button></div>
                        <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="translation" checked> <span><?php esc_html_e('نمایش ترجمه', 'hamnoor'); ?></span></label>
                        <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="auto-scroll" checked> <span><?php esc_html_e('رفتن خودکار به محل ادامه', 'hamnoor'); ?></span></label>
                        <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="dark"> <span><?php esc_html_e('حالت تاریک', 'hamnoor'); ?></span></label>
                        <label class="hamnoor-reading-size-control"><span><?php esc_html_e('اندازه متن عربی', 'hamnoor'); ?></span><input type="range" min="28" max="56" step="2" value="<?php echo (int)$default_arabic_size; ?>" data-reading-pref="arabic-size"></label>
                        <label class="hamnoor-reading-size-control"><span><?php esc_html_e('اندازه ترجمه', 'hamnoor'); ?></span><input type="range" min="14" max="28" step="1" value="<?php echo (int)$default_translation_size; ?>" data-reading-pref="translation-size"></label>
                        <button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-reading-focus-action" data-reading-focus><?php esc_html_e('مطالعه بدون مزاحمت', 'hamnoor'); ?></button>
                        <div class="hamnoor-reading-settings-actions"><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-reading-settings-done><?php esc_html_e('انجام شد', 'hamnoor'); ?></button><button type="button" class="hamnoor-link-button" data-reading-reset><?php esc_html_e('بازنشانی', 'hamnoor'); ?></button></div>
                    </div>
                </div>
            </div>
        </header>

        <div class="hamnoor-reading-progress-wrap">
            <div class="hamnoor-reading-progress-copy">
                <strong data-reading-percent><?php echo esc_html($percent); ?>٪</strong>
                <span data-reading-progress-label><?php echo $is_done ? esc_html__('تکمیل‌شده ✓', 'hamnoor') : esc_html(sprintf(__('%1$s · %2$d آیه', 'hamnoor'), $range['label'], $verse_count)); ?></span>
            </div>
            <div class="hamnoor-reading-progress" role="progressbar" aria-label="<?php esc_attr_e('پیشرفت همین سهم', 'hamnoor'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($percent); ?>">
                <span data-reading-progress-bar style="width:<?php echo esc_attr($percent); ?>%"></span>
            </div>
        </div>
    </div>

    <div class="hamnoor-reading-toolbar">
        <?php if (!$is_done && !$ready) : ?>
            <a class="hamnoor-btn hamnoor-btn-primary hamnoor-reading-resume-action" href="#hamnoor-verse-<?php echo (int) $resume; ?>" data-reading-resume><?php echo esc_html($started ? __('ادامه از محل ذخیره‌شده', 'hamnoor') : __('شروع قرائت', 'hamnoor')); ?></a>
        <?php endif; ?>
        <span class="hamnoor-reading-message" data-reading-message role="status" aria-live="polite" aria-atomic="true"></span>
        <span class="hamnoor-reading-network-status" data-reading-network-status role="status" aria-live="polite" aria-atomic="true" hidden></span>
        <?php if (!$is_done) : ?><small class="hamnoor-reading-autosave-hint" data-reading-autosave-hint><?php esc_html_e('محل مطالعه به‌صورت خودکار ذخیره می‌شود.', 'hamnoor'); ?></small><?php endif; ?>
    </div>

    <div class="hamnoor-reading-resilience-notice" data-reading-resilience-notice hidden role="status" aria-live="polite" aria-atomic="true">
        <span aria-hidden="true">↻</span>
        <p data-reading-resilience-copy><?php esc_html_e('در حال بررسی آخرین وضعیت سهم…', 'hamnoor'); ?></p>
        <button type="button" class="hamnoor-link-button" data-reading-retry-state hidden><?php esc_html_e('تلاش دوباره', 'hamnoor'); ?></button>
    </div>

    <div class="hamnoor-reading-state-notice" data-reading-state-notice hidden role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1">
        <strong data-reading-state-title><?php esc_html_e('وضعیت سهم تغییر کرده است', 'hamnoor'); ?></strong>
        <p data-reading-state-copy></p>
        <a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($dashboard_url); ?>" data-reading-state-action><?php echo esc_html($back_label); ?></a>
    </div>

    <?php if ($is_done) : ?>
        <div class="hamnoor-notice hamnoor-notice-success hamnoor-reading-review-note" role="status"><?php esc_html_e('این سهم قبلاً تکمیل شده است؛ اکنون در حالت مرور هستید و بازخوانی، ثبت پایان دیگری ایجاد نمی‌کند.', 'hamnoor'); ?></div>
    <?php endif; ?>

    <div id="hamnoor-reading-content" class="hamnoor-reading-content" data-reading-content role="region" aria-labelledby="hamnoor-reading-title" tabindex="-1">
    <div class="hamnoor-reading-boundary hamnoor-reading-boundary--start" data-reading-start-marker>
        <span aria-hidden="true">◉</span><div><strong><?php esc_html_e('شروع سهم شما', 'hamnoor'); ?></strong><small><?php echo esc_html($range['label']); ?></small></div>
    </div>

    <div class="hamnoor-reading-verses">
        <?php foreach ($verses as $verse) :
            $global_index = (int) $verse['global_index'];
            $surah_number = (int) $verse['surah'];
            $ayah_number = (int) $verse['ayah'];
            if ($surah_number !== $previous_surah) :
                $surah_meta = manahel_quran_core()->get_surah($surah_number);
                $previous_surah = $surah_number;
        ?>
            <div class="hamnoor-reading-surah-heading">
                <span><?php esc_html_e('سوره', 'hamnoor'); ?></span>
                <strong><?php echo esc_html($surah_meta ? $surah_meta['arabic_name'] : $surah_number); ?></strong>
            </div>
        <?php endif; ?>
            <article id="hamnoor-verse-<?php echo $global_index; ?>" class="hamnoor-reading-verse<?php echo $global_index <= $last ? ' is-read' : ''; ?><?php echo !$is_done && $started && $global_index === $resume ? ' is-resume' : ''; ?>" data-verse-index="<?php echo $global_index; ?>" data-surah="<?php echo $surah_number; ?>" data-ayah="<?php echo $ayah_number; ?>" data-audio="<?php echo esc_url(Hamnoor_Reading::audio_url($surah_number, $ayah_number)); ?>" aria-labelledby="hamnoor-verse-text-<?php echo $global_index; ?>">
                <?php if (!$is_done && $started && $global_index === $resume && !$ready) : ?><span class="hamnoor-reading-resume-cue" data-reading-resume-cue><?php esc_html_e('از اینجا ادامه دهید', 'hamnoor'); ?></span><?php endif; ?>
                <div class="hamnoor-reading-verse-meta">
                    <span><?php echo esc_html(sprintf(__('%1$d:%2$d', 'hamnoor'), $surah_number, $ayah_number)); ?></span>
                    <span class="hamnoor-reading-saved-badge<?php echo $global_index <= $last ? ' is-visible' : ''; ?>" data-saved-badge aria-hidden="<?php echo $global_index <= $last ? 'false' : 'true'; ?>"><?php esc_html_e('خوانده‌شده', 'hamnoor'); ?></span>
                    <button type="button" class="hamnoor-verse-audio" data-audio-play aria-pressed="false" aria-label="<?php echo esc_attr(sprintf(__('پخش صوت آیه %1$d سوره %2$d', 'hamnoor'), $ayah_number, $surah_number)); ?>">▶ <span><?php esc_html_e('پخش', 'hamnoor'); ?></span></button>
                </div>
                <p id="hamnoor-verse-text-<?php echo $global_index; ?>" class="hamnoor-reading-arabic" lang="ar" dir="rtl"><?php echo esc_html($verse['text_arabic']); ?> <span class="hamnoor-reading-ayah-number">﴿<?php echo esc_html($ayah_number); ?>﴾</span></p>
                <p class="hamnoor-reading-translation"><?php echo esc_html($verse['translation_makarem']); ?></p>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="hamnoor-reading-boundary hamnoor-reading-boundary--end" data-reading-end-marker>
        <span aria-hidden="true">✓</span><div><strong><?php esc_html_e('پایان سهم شما', 'hamnoor'); ?></strong><small><?php esc_html_e('رسیدن به این نقطه به‌تنهایی سهم را تکمیل نمی‌کند.', 'hamnoor'); ?></small></div>
    </div>
    </div>

    <?php if (!$is_done) : ?>
        <section class="hamnoor-reading-completion" data-reading-completion <?php echo $ready ? '' : 'hidden'; ?> role="region" aria-labelledby="hamnoor-reading-completion-title" aria-live="polite">
            <div data-reading-ready-view>
                <span class="hamnoor-reading-completion-icon" aria-hidden="true">✓</span>
                <div class="hamnoor-reading-completion-copy">
                    <span class="hamnoor-reading-completion-kicker"><?php esc_html_e('آماده تکمیل', 'hamnoor'); ?></span>
                    <h2 id="hamnoor-reading-completion-title"><?php esc_html_e('به پایان سهم رسیدید', 'hamnoor'); ?></h2>
                    <p><?php esc_html_e('اگر این سهم را کامل خوانده‌اید، پایان قرائت را ثبت کنید. رسیدن به انتهای متن بدون تأیید شما سهم را تکمیل نمی‌کند.', 'hamnoor'); ?></p>
                </div>
                <div class="hamnoor-reading-completion-actions">
                    <button type="button" class="hamnoor-btn hamnoor-btn-success" data-reading-finish><?php esc_html_e('قرائت تمام شد', 'hamnoor'); ?></button>
                    <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($dashboard_url); ?>" data-reading-later><?php esc_html_e('بعداً ادامه می‌دهم', 'hamnoor'); ?></a>
                </div>
            </div>
            <div class="hamnoor-reading-completion-success" data-reading-success-view hidden role="status" tabindex="-1">
                <span class="hamnoor-reading-completion-icon" aria-hidden="true">✓</span>
                <div class="hamnoor-reading-completion-copy">
                    <span class="hamnoor-reading-completion-kicker"><?php esc_html_e('ثبت شد', 'hamnoor'); ?></span>
                    <h2><?php esc_html_e('قرائت شما با موفقیت ثبت شد', 'hamnoor'); ?></h2>
                    <p data-reading-success-message><?php esc_html_e('این سهم تکمیل شد. خداوند قبول فرماید.', 'hamnoor'); ?></p>
                </div>
                <div class="hamnoor-reading-completion-actions">
                    <a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($campaign_url); ?>" data-reading-next-action><?php esc_html_e('انتخاب سهم بعدی', 'hamnoor'); ?></a>
                    <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($campaign_url); ?>" data-reading-campaign-action><?php esc_html_e('بازگشت به پویش', 'hamnoor'); ?></a>
                    <a class="hamnoor-link-button" href="<?php echo esc_url($dashboard_url); ?>" data-reading-dashboard-action><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></a>
                </div>
            </div>
        </section>
    <?php else : ?>
        <section class="hamnoor-reading-completion hamnoor-reading-completion--review" data-reading-review-completion>
            <span class="hamnoor-reading-completion-icon" aria-hidden="true">✓</span>
            <div class="hamnoor-reading-completion-copy"><span class="hamnoor-reading-completion-kicker"><?php esc_html_e('مرور', 'hamnoor'); ?></span><h2><?php esc_html_e('این سهم قبلاً تکمیل شده است', 'hamnoor'); ?></h2><p><?php esc_html_e('مرور دوباره آیات هیچ ثبت آماری یا تکمیل مجددی ایجاد نمی‌کند.', 'hamnoor'); ?></p></div>
            <div class="hamnoor-reading-completion-actions"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($campaign_url); ?>"><?php esc_html_e('بازگشت به پویش', 'hamnoor'); ?></a><a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></a></div>
        </section>
    <?php endif; ?>

    <noscript><div class="hamnoor-notice hamnoor-notice-warning"><?php esc_html_e('برای ذخیره خودکار محل قرائت، جاوااسکریپت مرورگر باید فعال باشد.', 'hamnoor'); ?></div></noscript>

    <div class="hamnoor-audio-player" data-audio-player hidden role="region" aria-label="<?php esc_attr_e('پخش صوت قرآن', 'hamnoor'); ?>">
        <button type="button" data-audio-prev aria-label="<?php esc_attr_e('آیه قبل', 'hamnoor'); ?>">⏮</button>
        <button type="button" class="hamnoor-audio-main" data-audio-main aria-pressed="false" aria-label="<?php esc_attr_e('پخش صوت', 'hamnoor'); ?>">▶</button>
        <button type="button" data-audio-next aria-label="<?php esc_attr_e('آیه بعد', 'hamnoor'); ?>">⏭</button>
        <div class="hamnoor-audio-info"><strong data-audio-title><?php esc_html_e('ترتیل استاد پرهیزکار', 'hamnoor'); ?></strong><button type="button" class="hamnoor-audio-timeline" data-audio-timeline role="slider" aria-label="<?php esc_attr_e('موقعیت پخش صوت', 'hamnoor'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="<?php esc_attr_e('ابتدای آیه', 'hamnoor'); ?>"><span data-audio-progress></span></button><small><span data-audio-time>۰:۰۰</span> / <span data-audio-duration>۰:۰۰</span></small></div>
        <button type="button" data-audio-repeat aria-pressed="false" aria-label="<?php esc_attr_e('تکرار آیه', 'hamnoor'); ?>">↻</button>
        <button type="button" data-audio-close aria-label="<?php esc_attr_e('بستن پخش‌کننده', 'hamnoor'); ?>">✕</button>
    </div>
</section>
