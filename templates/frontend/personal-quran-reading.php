<?php
if (!defined('ABSPATH')) { exit; }
$dashboard_url = Hamnoor_Helpers::page_url('dashboard');
$previous_surah = 0;
$resume_on_page = $resume >= (int)$range['start_global_index'] && $resume <= (int)$range['end_global_index'];
?>
<section class="hamnoor-reading hamnoor-personal-quran-reading<?php echo $is_paused ? ' is-paused' : ''; ?><?php echo $is_done ? ' is-completed' : ''; ?>" data-hamnoor-reading="1" data-reading-mode="personal_quran" data-entity-id="<?php echo (int)$campaign->id; ?>" data-start-index="<?php echo (int)Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX; ?>" data-end-index="<?php echo (int)Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX; ?>" data-page-start-index="<?php echo (int)$range['start_global_index']; ?>" data-page-end-index="<?php echo (int)$range['end_global_index']; ?>" data-last-index="<?php echo (int)$last; ?>" data-resume-index="<?php echo (int)$resume; ?>" data-default-arabic-size="<?php echo (int)$default_arabic_size; ?>" data-default-translation-size="<?php echo (int)$default_translation_size; ?>">
    <button type="button" class="hamnoor-reading-focus-exit" data-reading-focus-exit hidden aria-label="<?php esc_attr_e('خروج از حالت مطالعه بدون مزاحمت', 'hamnoor'); ?>">
        <span aria-hidden="true">×</span><span><?php esc_html_e('خروج از مطالعه بدون مزاحمت', 'hamnoor'); ?></span>
    </button>

    <header class="hamnoor-reading-header hamnoor-personal-quran-reader-head">
        <div class="hamnoor-personal-quran-identity">
            <a class="hamnoor-reading-back" href="<?php echo esc_url($dashboard_url); ?>" aria-label="<?php esc_attr_e('بازگشت به داشبورد ختم شخصی', 'hamnoor'); ?>">← <?php esc_html_e('بازگشت', 'hamnoor'); ?></a>
            <p class="hamnoor-reading-khatm"><?php esc_html_e('ختم شخصی قرآن', 'hamnoor'); ?></p>
            <h1><?php echo esc_html((string)$campaign->title); ?></h1>
            <p class="hamnoor-personal-quran-location"><?php echo esc_html(sprintf(__('اکنون صفحه %1$d از %2$d', 'hamnoor'), (int)$page, (int)Hamnoor_Personal_Quran::TOTAL_PAGES)); ?></p>
        </div>
        <div class="hamnoor-reading-status">
            <strong data-reading-percent><?php echo esc_html(rtrim(rtrim(number_format_i18n($percent, 2), '0'), '٫.')); ?>٪</strong>
            <span><?php echo $is_done ? esc_html__('ختم تکمیل‌شده', 'hamnoor') : esc_html__('پیشرفت کل قرآن', 'hamnoor'); ?></span>
        </div>
    </header>

    <div class="hamnoor-reading-progress" aria-label="<?php esc_attr_e('پیشرفت کل ختم شخصی', 'hamnoor'); ?>">
        <span data-reading-progress-bar style="width:<?php echo esc_attr($percent); ?>%"></span>
    </div>

    <div class="hamnoor-personal-quran-page-nav" aria-label="<?php esc_attr_e('جابجایی بین صفحات قرآن', 'hamnoor'); ?>">
        <?php if ($previous_page_url) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-personal-page-prev" href="<?php echo esc_url($previous_page_url); ?>">→ <?php esc_html_e('صفحه قبل', 'hamnoor'); ?></a><?php else : ?><span></span><?php endif; ?>
        <?php if (!$is_done && !$resume_on_page && $resume_page !== $page) : ?><a class="hamnoor-personal-quran-resume-page" href="<?php echo esc_url($resume_url); ?>"><?php esc_html_e('بازگشت به محل ادامه', 'hamnoor'); ?></a><?php else : ?><span class="hamnoor-personal-quran-page-number"><?php echo esc_html(sprintf(__('%1$d / %2$d', 'hamnoor'), (int)$page, (int)Hamnoor_Personal_Quran::TOTAL_PAGES)); ?></span><?php endif; ?>
        <?php if ($next_page_url) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-personal-page-next" href="<?php echo esc_url($next_page_url); ?>"><?php esc_html_e('صفحه بعد', 'hamnoor'); ?> ←</a><?php else : ?><span></span><?php endif; ?>
    </div>

    <div class="hamnoor-reading-toolbar">
        <?php if (!$is_done && $resume_on_page && $last >= Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX && !$ready) : ?>
            <a class="hamnoor-btn hamnoor-btn-primary" href="#hamnoor-verse-<?php echo (int)$resume; ?>" data-reading-resume><?php esc_html_e('ادامه از محل قبلی', 'hamnoor'); ?></a>
        <?php elseif (!$is_done && 1 === (int)$page && $last < Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX) : ?>
            <a class="hamnoor-btn hamnoor-btn-primary" href="#hamnoor-verse-<?php echo (int)$range['start_global_index']; ?>" data-reading-resume><?php esc_html_e('شروع از ابتدای قرآن', 'hamnoor'); ?></a>
        <?php endif; ?>
        <?php if (!$is_done) : ?>
            <button type="button" class="hamnoor-btn hamnoor-btn-success hamnoor-reading-finish-primary" data-reading-finish <?php disabled(!$ready); ?><?php echo $ready ? '' : ' hidden'; ?>><?php esc_html_e('ثبت پایان ختم قرآن', 'hamnoor'); ?></button>
        <?php endif; ?>
        <a class="hamnoor-reading-dashboard-shortcut" href="<?php echo esc_url($dashboard_url); ?>" aria-label="<?php esc_attr_e('بازگشت به داشبورد', 'hamnoor'); ?>" title="<?php esc_attr_e('بازگشت به داشبورد', 'hamnoor'); ?>">
            <span aria-hidden="true">←</span><span class="hamnoor-reading-dashboard-label"><?php esc_html_e('داشبورد', 'hamnoor'); ?></span>
        </a>
        <div class="hamnoor-reading-settings" data-reading-settings>
            <button type="button" class="hamnoor-reading-settings-toggle" data-reading-settings-toggle aria-expanded="false" aria-haspopup="dialog" aria-label="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>" title="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>">
                <span aria-hidden="true">⚙</span><span class="screen-reader-text"><?php esc_html_e('تنظیمات مطالعه', 'hamnoor'); ?></span>
            </button>
            <div class="hamnoor-reading-settings-panel" data-reading-settings-panel hidden role="dialog" aria-modal="false" aria-label="<?php esc_attr_e('تنظیمات مطالعه', 'hamnoor'); ?>">
                <div class="hamnoor-reading-settings-head"><strong><?php esc_html_e('تنظیمات مطالعه', 'hamnoor'); ?></strong><button type="button" data-reading-settings-close aria-label="<?php esc_attr_e('بستن تنظیمات', 'hamnoor'); ?>">×</button></div>
                <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="translation" checked> <span><?php esc_html_e('نمایش ترجمه', 'hamnoor'); ?></span></label>
                <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="auto-scroll" checked> <span><?php esc_html_e('اسکرول خودکار آیه', 'hamnoor'); ?></span></label>
                <label class="hamnoor-reading-switch"><input type="checkbox" data-reading-pref="dark"> <span><?php esc_html_e('حالت تاریک', 'hamnoor'); ?></span></label>
                <label class="hamnoor-reading-size-control"><span><?php esc_html_e('اندازه متن عربی', 'hamnoor'); ?></span><input type="range" min="28" max="56" step="2" value="<?php echo (int)$default_arabic_size; ?>" data-reading-pref="arabic-size"></label>
                <label class="hamnoor-reading-size-control"><span><?php esc_html_e('اندازه ترجمه', 'hamnoor'); ?></span><input type="range" min="14" max="28" step="1" value="<?php echo (int)$default_translation_size; ?>" data-reading-pref="translation-size"></label>
                <button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-reading-focus-action" data-reading-focus><?php esc_html_e('مطالعه بدون مزاحمت', 'hamnoor'); ?></button>
                <div class="hamnoor-reading-settings-actions"><button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-reading-settings-done><?php esc_html_e('انجام شد', 'hamnoor'); ?></button><button type="button" class="hamnoor-link-button" data-reading-reset><?php esc_html_e('بازنشانی', 'hamnoor'); ?></button></div>
            </div>
        </div>
        <span class="hamnoor-reading-message" data-reading-message aria-live="polite"></span>
    </div>

    <?php if ($is_paused) : ?>
        <div class="hamnoor-notice hamnoor-notice-info"><?php esc_html_e('این ختم در حالت توقف موقت است. می‌توانید آن را از داشبورد از سر بگیرید.', 'hamnoor'); ?></div>
    <?php elseif ($is_done) : ?>
        <div class="hamnoor-notice hamnoor-notice-success"><?php esc_html_e('این ختم شخصی تکمیل شده است. صفحات برای مرور دوباره در دسترس هستند.', 'hamnoor'); ?></div>
    <?php else : ?>
        <div class="hamnoor-notice hamnoor-notice-info hamnoor-personal-quran-autosave-note"><?php esc_html_e('محل قرائت هنگام پیش‌روی به‌صورت خودکار ذخیره می‌شود؛ تکمیل ختم همچنان با تأیید شما ثبت خواهد شد.', 'hamnoor'); ?></div>
    <?php endif; ?>

    <div class="hamnoor-reading-verses" data-reading-content tabindex="-1">
        <?php foreach ($verses as $verse) :
            $global_index = (int)$verse['global_index'];
            $surah_number = (int)$verse['surah'];
            $ayah_number = (int)$verse['ayah'];
            if ($surah_number !== $previous_surah) :
                $surah_meta = manahel_quran_core()->get_surah($surah_number);
                $previous_surah = $surah_number;
        ?>
            <div class="hamnoor-reading-surah-heading"><span><?php esc_html_e('سوره', 'hamnoor'); ?></span><strong><?php echo esc_html($surah_meta ? $surah_meta['arabic_name'] : $surah_number); ?></strong></div>
        <?php endif; ?>
            <article id="hamnoor-verse-<?php echo $global_index; ?>" class="hamnoor-reading-verse<?php echo $global_index <= $last ? ' is-read' : ''; ?><?php echo $global_index === $resume ? ' is-resume' : ''; ?>" data-verse-index="<?php echo $global_index; ?>" data-surah="<?php echo $surah_number; ?>" data-ayah="<?php echo $ayah_number; ?>" data-audio="<?php echo esc_url(Hamnoor_Reading::audio_url($surah_number, $ayah_number)); ?>">
                <div class="hamnoor-reading-verse-meta">
                    <span><?php echo esc_html(sprintf(__('%1$d:%2$d', 'hamnoor'), $surah_number, $ayah_number)); ?></span>
                    <span class="hamnoor-reading-saved-badge<?php echo $global_index <= $last ? ' is-visible' : ''; ?>" data-saved-badge aria-hidden="<?php echo $global_index <= $last ? 'false' : 'true'; ?>"><?php esc_html_e('خوانده‌شده', 'hamnoor'); ?></span>
                    <button type="button" class="hamnoor-verse-audio" data-audio-play aria-label="<?php echo esc_attr(sprintf(__('پخش صوت آیه %1$d سوره %2$d', 'hamnoor'), $ayah_number, $surah_number)); ?>">▶ <span><?php esc_html_e('پخش', 'hamnoor'); ?></span></button>
                </div>
                <p class="hamnoor-reading-arabic" lang="ar" dir="rtl"><?php echo esc_html($verse['text_arabic']); ?> <span class="hamnoor-reading-ayah-number">﴿<?php echo esc_html($ayah_number); ?>﴾</span></p>
                <p class="hamnoor-reading-translation"><?php echo esc_html($verse['translation_makarem']); ?></p>
            </article>
        <?php endforeach; ?>
        <span data-reading-end-marker aria-hidden="true"></span>
    </div>

    <div class="hamnoor-audio-player" data-audio-player hidden>
        <button type="button" data-audio-prev aria-label="<?php esc_attr_e('آیه قبل', 'hamnoor'); ?>">⏮</button>
        <button type="button" class="hamnoor-audio-main" data-audio-main aria-label="<?php esc_attr_e('پخش یا توقف', 'hamnoor'); ?>">▶</button>
        <button type="button" data-audio-next aria-label="<?php esc_attr_e('آیه بعد', 'hamnoor'); ?>">⏭</button>
        <div class="hamnoor-audio-info"><strong data-audio-title><?php esc_html_e('ترتیل استاد پرهیزکار', 'hamnoor'); ?></strong><div class="hamnoor-audio-timeline" data-audio-timeline><span data-audio-progress></span></div><small><span data-audio-time>۰:۰۰</span> / <span data-audio-duration>۰:۰۰</span></small></div>
        <button type="button" data-audio-repeat aria-pressed="false" aria-label="<?php esc_attr_e('تکرار آیه', 'hamnoor'); ?>">↻</button>
        <button type="button" data-audio-close aria-label="<?php esc_attr_e('بستن پخش‌کننده', 'hamnoor'); ?>">✕</button>
    </div>

    <div class="hamnoor-personal-quran-page-nav hamnoor-personal-quran-page-nav--bottom">
        <?php if ($previous_page_url) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-personal-page-prev" href="<?php echo esc_url($previous_page_url); ?>">→ <?php esc_html_e('صفحه قبل', 'hamnoor'); ?></a><?php else : ?><span></span><?php endif; ?>
        <?php if ($next_page_url) : ?><a class="hamnoor-btn hamnoor-btn-primary hamnoor-personal-page-next" href="<?php echo esc_url($next_page_url); ?>"><?php esc_html_e('صفحه بعد', 'hamnoor'); ?> ←</a><?php elseif (!$is_done) : ?><button type="button" class="hamnoor-btn hamnoor-btn-success" data-reading-finish <?php disabled(!$ready); ?><?php echo $ready ? '' : ' hidden'; ?>><?php esc_html_e('ثبت پایان ختم قرآن', 'hamnoor'); ?></button><?php endif; ?>
    </div>
</section>
