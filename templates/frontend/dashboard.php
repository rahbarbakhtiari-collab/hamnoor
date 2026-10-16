<?php
if (!defined('ABSPATH')) { exit; }
$user = isset($user) && is_object($user) ? $user : Hamnoor_Mobile_Access::current_user();
$owned = isset($owned) && is_array($owned) ? $owned : array();
$parts = isset($parts) && is_array($parts) ? $parts : array();
$active_tab = isset($active_tab) ? (string) $active_tab : 'summary';
if ('contributions' === $active_tab) { $active_tab = 'my-parts'; }
$owned_total = isset($owned_total) ? (int) $owned_total : count($owned);
$parts_total = isset($parts_total) ? (int) $parts_total : count($parts);
$open_parts = isset($open_parts) ? (int) $open_parts : 0;
$completed_archive = isset($completed_archive) && is_array($completed_archive) ? $completed_archive : array();
$participants_khatm = isset($participants_khatm) ? $participants_khatm : null;
$participants_rows = isset($participants_rows) && is_array($participants_rows) ? $participants_rows : array();
$participants_selected = isset($participants_selected) ? $participants_selected : null;
$participants_selected_parts = isset($participants_selected_parts) && is_array($participants_selected_parts) ? $participants_selected_parts : array();
$participants_error = isset($participants_error) ? (string) $participants_error : '';
$notifications = isset($notifications) && is_array($notifications) ? $notifications : array();
$unread_notifications = isset($unread_notifications) ? (int) $unread_notifications : 0;
$personal_quran = isset($personal_quran) && is_object($personal_quran) ? $personal_quran : null;
$personal_quran_state = isset($personal_quran_state) && is_array($personal_quran_state) ? $personal_quran_state : null;
$personal_quran_reminder_state = isset($personal_quran_reminder_state) && is_array($personal_quran_reminder_state) ? $personal_quran_reminder_state : null;
$progress_owned = isset($progress_owned) && is_array($progress_owned) ? $progress_owned : array();
$progress_khatm = isset($progress_khatm) ? $progress_khatm : null;
$progress_rows = isset($progress_rows) && is_array($progress_rows) ? $progress_rows : array();
$progress_summary = isset($progress_summary) && is_array($progress_summary) ? $progress_summary : array('total'=>0,'available'=>0,'not_started'=>0,'reading'=>0,'ready'=>0,'done'=>0);
$progress_page = isset($progress_page) ? (int)$progress_page : 1;
$progress_pages = isset($progress_pages) ? (int)$progress_pages : 1;
$create_url = Hamnoor_Helpers::page_url('create');
$list_url = Hamnoor_Helpers::page_url('list');
$dashboard_url = Hamnoor_Helpers::page_url('dashboard');
$help_url = Hamnoor_Helpers::help_url();

// Mobile presentation data: same dashboard logic/data, independent mobile view.
$mobile_focus_part = null;
$mobile_focus_state = null;
$mobile_focus_khatm = null;
$mobile_open_previews = array();
if (class_exists('Hamnoor_Reading')) {
    foreach ($parts as $candidate_part) {
        if (!is_object($candidate_part) || 'done' === (string) $candidate_part->status) { continue; }
        $candidate_khatm = !empty($candidate_part->khatm_token) ? Hamnoor_DB::get_khatm($candidate_part->khatm_token) : null;
        if (!$candidate_khatm || !in_array((string) $candidate_khatm->status, array('published','active','paused','expired','completed'), true)) { continue; }
        $candidate_state = Hamnoor_Reading::card_state($candidate_part);
        if (!$candidate_state || empty($candidate_state['url'])) { continue; }
        if (!$mobile_focus_part) {
            $mobile_focus_part = $candidate_part;
            $mobile_focus_state = $candidate_state;
            $mobile_focus_khatm = $candidate_khatm;
        }
        if (count($mobile_open_previews) < 3) {
            $mobile_open_previews[] = array('part'=>$candidate_part, 'state'=>$candidate_state, 'khatm'=>$candidate_khatm);
        }
    }
}
$mobile_name = $user && !empty($user->display_name) ? (string) $user->display_name : __('همراه گرامی', 'hamnoor');
$dashboard_groups = array(
    'today' => array(
        'label' => __('امروز من', 'hamnoor'),
        'description' => __('سهم‌ها، ادامه قرائت و اعلان‌های امروز', 'hamnoor'),
        'default' => 'summary',
        'tabs' => array(
            'summary' => __('نمای امروز', 'hamnoor'),
            'my-parts' => __('سهم‌های من', 'hamnoor'),
            'notifications' => __('اعلان‌ها', 'hamnoor'),
        ),
    ),
    'campaigns' => array(
        'label' => __('پویش‌های من', 'hamnoor'),
        'description' => __('ساخت، مدیریت و پیگیری پویش‌ها', 'hamnoor'),
        'default' => 'my-khatms',
        'tabs' => array(
            'my-khatms' => __('پویش‌ها', 'hamnoor'),
            'progress' => __('پیشرفت', 'hamnoor'),
        ),
    ),
    'account' => array(
        'label' => __('حساب و تنظیمات', 'hamnoor'),
        'description' => __('یادآوری‌ها، مشخصات و راهنما', 'hamnoor'),
        'default' => 'reminders',
        'tabs' => array(
            'reminders' => __('یادآوری‌ها', 'hamnoor'),
            'profile' => __('مشخصات من', 'hamnoor'),
            'help' => __('راهنما', 'hamnoor'),
        ),
    ),
);
$dashboard_groups = (array) apply_filters('hamnoor_dashboard_groups', $dashboard_groups, $user, $active_tab);
$tab_to_group = array();
foreach ($dashboard_groups as $group_key => $group) {
    if (empty($group['tabs']) || !is_array($group['tabs'])) { continue; }
    foreach ($group['tabs'] as $tab_key => $tab_label) { $tab_to_group[sanitize_key((string)$tab_key)] = sanitize_key((string)$group_key); }
}
$active_group = isset($tab_to_group[$active_tab]) ? $tab_to_group[$active_tab] : 'today';
?>
<section class="hamnoor-dashboard" data-active-tab="<?php echo esc_attr($active_tab); ?>" data-hamnoor-dashboard="1">
    <header class="hamnoor-mobile-ui hamnoor-mobile-appbar">
        <div class="hamnoor-mobile-appbar-copy">
            <a class="hamnoor-mobile-brand-logo" href="<?php echo esc_url('https://hamnoor.ir/'); ?>" aria-label="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></a>
        </div>
        <button type="button" class="hamnoor-mobile-icon-button" data-tab-target="notifications" aria-label="<?php esc_attr_e('اعلان‌ها', 'hamnoor'); ?>" aria-selected="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <?php if ($unread_notifications) : ?><span class="hamnoor-mobile-badge hamnoor-notification-badge"><?php echo (int) $unread_notifications; ?></span><?php endif; ?>
        </button>
    </header>
    <aside class="hamnoor-desktop-ui hamnoor-desktop-brand-panel" aria-label="<?php esc_attr_e('ناوبری پنل هم‌نور', 'hamnoor'); ?>">
        <a class="hamnoor-desktop-brand" href="<?php echo esc_url('https://hamnoor.ir/'); ?>">
            <span class="hamnoor-desktop-brand-mark"><img src="<?php echo esc_url('https://hamnoor.ir/wp-content/uploads/2026/08/hamnoor-logo-white-transparent-scaled.webp'); ?>" alt="<?php echo esc_attr__('هم‌نور', 'hamnoor'); ?>"></span>
            <span><strong><?php esc_html_e('هم‌نور', 'hamnoor'); ?></strong><small><?php esc_html_e('پنل پویش و قرائت قرآن', 'hamnoor'); ?></small></span>
        </a>
        <a class="hamnoor-desktop-create-shortcut" href="<?php echo esc_url($create_url); ?>"><span>＋</span><?php esc_html_e('ایجاد پویش جدید', 'hamnoor'); ?></a>

        <nav class="hamnoor-dashboard-primary-groups" aria-label="<?php esc_attr_e('بخش‌های اصلی پنل', 'hamnoor'); ?>">
            <?php foreach ($dashboard_groups as $group_key => $group) : ?>
                <button type="button" class="hamnoor-dashboard-group-button <?php echo esc_attr($active_group === $group_key ? 'is-active' : ''); ?>" data-dashboard-group-target="<?php echo esc_attr($group_key); ?>" data-dashboard-default-tab="<?php echo esc_attr($group['default']); ?>" aria-expanded="<?php echo $active_group === $group_key ? 'true' : 'false'; ?>" aria-controls="hamnoor-dashboard-subnav-<?php echo esc_attr($group_key); ?>">
                    <strong><?php echo esc_html($group['label']); ?><?php if ('today' === $group_key && $unread_notifications) : ?><span class="hamnoor-notification-badge"><?php echo (int)$unread_notifications; ?></span><?php endif; ?></strong>
                    <small><?php echo esc_html($group['description']); ?></small>
                </button>
                <nav id="hamnoor-dashboard-subnav-<?php echo esc_attr($group_key); ?>" class="hamnoor-dashboard-nav hamnoor-dashboard-tabs hamnoor-dashboard-subnav" data-dashboard-subnav="<?php echo esc_attr($group_key); ?>" aria-label="<?php echo esc_attr($group['label']); ?>" role="tablist" <?php echo $active_group === $group_key ? '' : 'hidden'; ?>>
                    <?php foreach ($group['tabs'] as $key => $tab_config) : ?>
                        <?php
                        $key = sanitize_key((string)$key);
                        $label = is_array($tab_config) ? (string)($tab_config['label'] ?? $key) : (string)$tab_config;
                        $custom_href = is_array($tab_config) ? (string)($tab_config['href'] ?? '') : '';
                        $hamnoor_tab_id = 'hamnoor-tab-' . sanitize_html_class($key);
                        $hamnoor_panel_id = 'hamnoor-panel-' . sanitize_html_class($key);
                        ?>
                        <?php if ($custom_href) : ?>
                            <a id="<?php echo esc_attr($hamnoor_tab_id); ?>" class="<?php echo esc_attr($active_tab === $key ? 'is-active' : ''); ?>" data-dashboard-tab-group="<?php echo esc_attr($group_key); ?>" href="<?php echo esc_url($custom_href); ?>" role="tab" aria-controls="<?php echo esc_attr($hamnoor_panel_id); ?>" aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $active_tab === $key ? '0' : '-1'; ?>"><?php echo esc_html($label); ?></a>
                        <?php elseif ('help' === $key && $help_url !== add_query_arg('tab', 'help', $dashboard_url)) : ?>
                            <a id="<?php echo esc_attr($hamnoor_tab_id); ?>" href="<?php echo esc_url($help_url); ?>"><?php echo esc_html($label); ?></a>
                        <?php elseif ('progress' === $key) : ?>
                            <a id="<?php echo esc_attr($hamnoor_tab_id); ?>" class="<?php echo esc_attr($active_tab === $key ? 'is-active' : ''); ?>" data-dashboard-tab-group="<?php echo esc_attr($group_key); ?>" href="<?php echo esc_url(add_query_arg('tab','progress',Hamnoor_Helpers::page_url('dashboard'))); ?>" role="tab" aria-controls="<?php echo esc_attr($hamnoor_panel_id); ?>" aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $active_tab === $key ? '0' : '-1'; ?>"><?php echo esc_html($label); ?></a>
                        <?php else : ?>
                            <button id="<?php echo esc_attr($hamnoor_tab_id); ?>" type="button" class="<?php echo esc_attr($active_tab === $key ? 'is-active' : ''); ?>" data-tab-target="<?php echo esc_attr($key); ?>" data-dashboard-tab-group="<?php echo esc_attr($group_key); ?>" role="tab" aria-controls="<?php echo esc_attr($hamnoor_panel_id); ?>" aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $active_tab === $key ? '0' : '-1'; ?>"><?php echo esc_html($label); ?><?php if ('notifications' === $key && $unread_notifications) : ?><span class="hamnoor-notification-badge"><?php echo (int) $unread_notifications; ?></span><?php endif; ?></button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="hamnoor-dashboard-head hamnoor-desktop-ui">
        <div><h2><?php echo esc_html(sprintf(__('سلام، %s', 'hamnoor'), $user && !empty($user->display_name) ? $user->display_name : __('همراه گرامی', 'hamnoor'))); ?></h2><p><?php esc_html_e('اینجا مرکز قرائت، ختم شخصی، سهم‌ها، پویش‌ها و مشخصات شماست.', 'hamnoor'); ?></p></div>
        <div class="hamnoor-dashboard-actions"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش جدید', 'hamnoor'); ?></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form"><?php wp_nonce_field('hamnoor_mobile_logout'); ?><input type="hidden" name="action" value="hamnoor_mobile_logout"><button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('خروج از پنل', 'hamnoor'); ?></button></form></div>
    </div>
    <div class="hamnoor-dashboard-panels">
        <section id="hamnoor-panel-summary" class="hamnoor-dashboard-panel <?php echo $active_tab === 'summary' ? 'is-active' : ''; ?>" data-tab-panel="summary" role="tabpanel" aria-labelledby="hamnoor-tab-summary" <?php echo $active_tab === 'summary' ? '' : 'hidden'; ?>>
            <div class="hamnoor-desktop-ui hamnoor-desktop-summary">
                <?php if ($personal_quran && $personal_quran_state) : $personal_progress = $personal_quran_state['progress']; ?>
                    <section class="hamnoor-personal-quran-card hamnoor-personal-quran-card--desktop">
                        <div class="hamnoor-personal-quran-card-copy">
                            <span class="hamnoor-personal-quran-kicker"><?php esc_html_e('ختم شخصی من', 'hamnoor'); ?></span>
                            <h3><?php esc_html_e('یک ختم کامل قرآن، از ابتدا تا انتها', 'hamnoor'); ?></h3>
                            <p><?php echo esc_html(!empty($personal_quran_state['started']) ? __('محل قرائت شما ذخیره شده است؛ از همان‌جا ادامه دهید.', 'hamnoor') : __('آماده‌اید از ابتدای قرآن شروع کنید.', 'hamnoor')); ?></p>
                            <div class="hamnoor-personal-quran-progress"><span style="width:<?php echo esc_attr((float)$personal_quran_state['percent']); ?>%"></span></div>
                            <div class="hamnoor-personal-quran-meta"><strong><?php echo esc_html(rtrim(rtrim(number_format_i18n((float)$personal_quran_state['percent'], 2), '0'), '٫.')); ?>٪</strong><span><?php echo esc_html(sprintf(__('صفحه ادامه: %d', 'hamnoor'), (int)$personal_quran_state['resume_page'])); ?></span></div>
                            <?php if ($personal_quran_reminder_state && !empty($personal_quran_reminder_state['enabled'])) : ?><small class="hamnoor-personal-reminder-badge">⏰ <?php echo esc_html(sprintf(__('یادآوری %s', 'hamnoor'), $personal_quran_reminder_state['local_time'])); ?></small><?php endif; ?>
                        </div>
                        <div class="hamnoor-personal-quran-card-actions">
                            <a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($personal_quran_state['url']); ?>"><?php echo esc_html(!empty($personal_quran_state['started']) ? __('ادامه ختم', 'hamnoor') : __('شروع قرائت', 'hamnoor')); ?></a>
                            <details><summary><?php esc_html_e('مدیریت', 'hamnoor'); ?></summary><div>
                                <?php if (!empty($personal_quran_state['paused'])) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="active"><button type="submit"><?php esc_html_e('ازسرگیری ختم', 'hamnoor'); ?></button></form>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="paused"><button type="submit"><?php esc_html_e('توقف موقت', 'hamnoor'); ?></button></form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('این ختم شخصی لغو شود؟ پیشرفت آن در سابقه باقی می‌ماند.', 'hamnoor')); ?>');"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="cancelled"><button type="submit" class="is-danger"><?php esc_html_e('لغو ختم', 'hamnoor'); ?></button></form>
                            </div></details>
                        </div>
                    </section>
                <?php endif; ?>
                <div class="hamnoor-desktop-summary-top">
                    <?php if ($mobile_focus_part && $mobile_focus_state) : ?>
                        <a class="hamnoor-desktop-focus-card" href="<?php echo esc_url($mobile_focus_state['url']); ?>">
                            <span class="hamnoor-desktop-focus-kicker"><?php echo esc_html(!empty($mobile_focus_state['ready']) ? __('آماده ثبت پایان', 'hamnoor') : (!empty($mobile_focus_state['started']) ? __('ادامه قرائت', 'hamnoor') : __('سهم بعدی شما', 'hamnoor'))); ?></span>
                            <div class="hamnoor-desktop-focus-copy">
                                <small><?php esc_html_e('ادامه مسیر قرائت', 'hamnoor'); ?></small>
                                <strong><?php echo esc_html($mobile_focus_part->title); ?></strong>
                                <span><?php echo esc_html($mobile_focus_khatm ? $mobile_focus_khatm->title : $mobile_focus_part->khatm_title); ?></span>
                            </div>
                            <div class="hamnoor-desktop-focus-progress"><span style="width:<?php echo esc_attr((int) $mobile_focus_state['percent']); ?>%"></span></div>
                            <div class="hamnoor-desktop-focus-foot"><b><?php echo esc_html(sprintf(__('%d٪ پیشرفت', 'hamnoor'), (int) $mobile_focus_state['percent'])); ?></b><span><?php echo esc_html(!empty($mobile_focus_state['ready']) ? __('ثبت پایان سهم', 'hamnoor') : (!empty($mobile_focus_state['started']) ? __('ادامه قرائت', 'hamnoor') : __('شروع قرائت', 'hamnoor'))); ?> ←</span></div>
                        </a>
                    <?php else : ?>
                        <section class="hamnoor-desktop-focus-card is-empty">
                            <span class="hamnoor-desktop-focus-kicker"><?php esc_html_e('امروز من', 'hamnoor'); ?></span>
                            <div class="hamnoor-desktop-focus-copy"><small><?php esc_html_e('وضعیت قرائت', 'hamnoor'); ?></small><strong><?php esc_html_e('سهم ناتمامی ندارید', 'hamnoor'); ?></strong><span><?php esc_html_e('می‌توانید به یک پویش فعال بپیوندید یا پویش تازه‌ای ایجاد کنید.', 'hamnoor'); ?></span></div>
                            <div class="hamnoor-desktop-focus-empty-actions"><a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('مشاهده پویش‌ها', 'hamnoor'); ?></a><a href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a></div>
                        </section>
                    <?php endif; ?>
                    <div class="hamnoor-desktop-stat-stack">
                        <button type="button" class="hamnoor-desktop-stat-card" data-tab-target="my-khatms"><span><?php esc_html_e('پویش‌های من', 'hamnoor'); ?></span><strong><?php echo (int) $owned_total; ?></strong><small><?php esc_html_e('پویش ایجادشده', 'hamnoor'); ?></small></button>
                        <button type="button" class="hamnoor-desktop-stat-card" data-tab-target="my-parts"><span><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></span><strong><?php echo (int) $parts_total; ?></strong><small><?php esc_html_e('سهم ثبت‌شده', 'hamnoor'); ?></small></button>
                        <button type="button" class="hamnoor-desktop-stat-card" data-tab-target="my-parts"><span><?php esc_html_e('در انتظار', 'hamnoor'); ?></span><strong><?php echo (int) $open_parts; ?></strong><small><?php esc_html_e('سهم ناتمام', 'hamnoor'); ?></small></button>
                        <button type="button" class="hamnoor-desktop-stat-card" data-tab-target="notifications"><span><?php esc_html_e('اعلان‌ها', 'hamnoor'); ?></span><strong><?php echo (int) $unread_notifications; ?></strong><small><?php esc_html_e('خوانده‌نشده', 'hamnoor'); ?></small></button>
                    </div>
                </div>
                <?php
                /**
                 * Generic read-only extension point for the dashboard summary.
                 *
                 * @param object|null $user    Current Hamnoor domain user.
                 * @param string      $surface Presentation surface: desktop|mobile.
                 */
                do_action('hamnoor_dashboard_summary_extension', $user, 'desktop');
                ?>
                <div class="hamnoor-desktop-summary-bottom">
                    <section class="hamnoor-card hamnoor-desktop-pending-card">
                        <div class="hamnoor-desktop-card-head"><div><h3><?php esc_html_e('سهم‌های در انتظار شما', 'hamnoor'); ?></h3><p><?php esc_html_e('برای ادامه، مستقیم وارد آخرین محل قرائت شوید.', 'hamnoor'); ?></p></div><button type="button" data-tab-target="my-parts"><?php esc_html_e('مشاهده همه', 'hamnoor'); ?></button></div>
                        <?php if ($mobile_open_previews) : ?><div class="hamnoor-desktop-pending-list">
                            <?php foreach ($mobile_open_previews as $preview) : $preview_part=$preview['part']; $preview_state=$preview['state']; $preview_khatm=$preview['khatm']; ?>
                                <a href="<?php echo esc_url($preview_state['url']); ?>"><div><strong><?php echo esc_html($preview_part->title); ?></strong><small><?php echo esc_html($preview_khatm->title); ?></small></div><span><?php echo (int) $preview_state['percent']; ?>٪</span></a>
                            <?php endforeach; ?>
                        </div><?php else : ?><p class="hamnoor-desktop-empty-note"><?php esc_html_e('فعلاً سهم ناتمامی در پنل شما وجود ندارد.', 'hamnoor'); ?></p><?php endif; ?>
                    </section>
                    <section class="hamnoor-card hamnoor-desktop-quick-card">
                        <div class="hamnoor-desktop-card-head"><div><h3><?php esc_html_e('دسترسی سریع', 'hamnoor'); ?></h3><p><?php esc_html_e('کارهای پرکاربرد هم‌نور', 'hamnoor'); ?></p></div></div>
                        <div class="hamnoor-desktop-quick-grid">
                            <?php if (!$personal_quran) : ?><form class="hamnoor-personal-quran-start-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_start_personal_quran'); ?><input type="hidden" name="action" value="hamnoor_start_personal_quran"><button type="submit"><b>☾</b><span><strong><?php esc_html_e('شروع ختم شخصی', 'hamnoor'); ?></strong><small><?php esc_html_e('یک ختم کامل برای خودم', 'hamnoor'); ?></small></span></button></form><?php endif; ?>
                            <a href="<?php echo esc_url($create_url); ?>"><b>＋</b><span><strong><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></strong><small><?php esc_html_e('ساخت پویش جدید', 'hamnoor'); ?></small></span></a>
                            <a href="<?php echo esc_url($list_url); ?>"><b>⌕</b><span><strong><?php esc_html_e('پویش‌های فعال', 'hamnoor'); ?></strong><small><?php esc_html_e('پیوستن به پویش عمومی', 'hamnoor'); ?></small></span></a>
                            <button type="button" data-tab-target="my-parts"><b>☰</b><span><strong><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></strong><small><?php esc_html_e('ادامه و تکمیل قرائت', 'hamnoor'); ?></small></span></button>
                            <button type="button" data-tab-target="my-khatms"><b>◇</b><span><strong><?php esc_html_e('پویش‌های من', 'hamnoor'); ?></strong><small><?php esc_html_e('مدیریت و دعوت', 'hamnoor'); ?></small></span></button>
                        </div>
                    </section>
                </div>
            </div>

            <div class="hamnoor-mobile-ui hamnoor-mobile-home">
                <section class="hamnoor-mobile-welcome">
                    <p><?php echo esc_html(sprintf(__('سلام %s 👋', 'hamnoor'), $mobile_name)); ?></p>
                    <span><?php esc_html_e('قرائت‌ها و پویش‌های شما یک‌جا در دسترس است.', 'hamnoor'); ?></span>
                </section>

                <?php if ($personal_quran && $personal_quran_state) : ?>
                    <section class="hamnoor-personal-quran-card hamnoor-personal-quran-card--mobile">
                        <span class="hamnoor-personal-quran-kicker"><?php esc_html_e('ختم شخصی من', 'hamnoor'); ?></span>
                        <strong><?php echo esc_html(!empty($personal_quran_state['started']) ? __('ادامه ختم قرآن', 'hamnoor') : __('شروع ختم قرآن', 'hamnoor')); ?></strong>
                        <small><?php echo esc_html(sprintf(__('%1$s٪ پیشرفت · ادامه از صفحه %2$d', 'hamnoor'), rtrim(rtrim(number_format_i18n((float)$personal_quran_state['percent'], 2), '0'), '٫.'), (int)$personal_quran_state['resume_page'])); ?></small>
                        <div class="hamnoor-personal-quran-progress"><span style="width:<?php echo esc_attr((float)$personal_quran_state['percent']); ?>%"></span></div>
                        <a class="hamnoor-personal-quran-main-action" href="<?php echo esc_url($personal_quran_state['url']); ?>"><?php echo esc_html(!empty($personal_quran_state['started']) ? __('ادامه قرائت', 'hamnoor') : __('شروع از ابتدای قرآن', 'hamnoor')); ?> ←</a>
                        <div class="hamnoor-personal-quran-card-actions"><details><summary><?php esc_html_e('مدیریت ختم', 'hamnoor'); ?></summary><div>
                            <?php if (!empty($personal_quran_state['paused'])) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="active"><button type="submit"><?php esc_html_e('ازسرگیری ختم', 'hamnoor'); ?></button></form>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="paused"><button type="submit"><?php esc_html_e('توقف موقت', 'hamnoor'); ?></button></form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('این ختم شخصی لغو شود؟ پیشرفت آن در سابقه باقی می‌ماند.', 'hamnoor')); ?>');"><?php wp_nonce_field('hamnoor_personal_quran_lifecycle_' . (int)$personal_quran->id); ?><input type="hidden" name="action" value="hamnoor_personal_quran_lifecycle"><input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>"><input type="hidden" name="target_status" value="cancelled"><button type="submit" class="is-danger"><?php esc_html_e('لغو ختم', 'hamnoor'); ?></button></form>
                        </div></details></div>
                    </section>
                <?php endif; ?>

                <?php if ($mobile_focus_part && $mobile_focus_state) : ?>
                    <a class="hamnoor-mobile-focus" href="<?php echo esc_url($mobile_focus_state['url']); ?>">
                        <span class="hamnoor-mobile-focus-kicker"><?php echo esc_html(!empty($mobile_focus_state['ready']) ? __('آماده ثبت پایان', 'hamnoor') : (!empty($mobile_focus_state['started']) ? __('ادامه قرائت', 'hamnoor') : __('سهم بعدی شما', 'hamnoor'))); ?></span>
                        <strong><?php echo esc_html($mobile_focus_part->title); ?></strong>
                        <small><?php echo esc_html($mobile_focus_khatm ? $mobile_focus_khatm->title : $mobile_focus_part->khatm_title); ?></small>
                        <div class="hamnoor-mobile-focus-progress"><span style="width:<?php echo esc_attr((int) $mobile_focus_state['percent']); ?>%"></span></div>
                        <div class="hamnoor-mobile-focus-foot"><span><?php echo esc_html(sprintf(__('%d٪ پیشرفت', 'hamnoor'), (int) $mobile_focus_state['percent'])); ?></span><b><?php echo esc_html(!empty($mobile_focus_state['ready']) ? __('ثبت پایان سهم', 'hamnoor') : (!empty($mobile_focus_state['started']) ? __('ادامه', 'hamnoor') : __('شروع قرائت', 'hamnoor'))); ?> ←</b></div>
                    </a>
                <?php else : ?>
                    <section class="hamnoor-mobile-focus hamnoor-mobile-focus-empty">
                        <span class="hamnoor-mobile-focus-kicker"><?php esc_html_e('امروز من', 'hamnoor'); ?></span>
                        <strong><?php esc_html_e('سهم ناتمامی ندارید', 'hamnoor'); ?></strong>
                        <small><?php esc_html_e('می‌توانید به یک پویش فعال بپیوندید یا پویش تازه‌ای بسازید.', 'hamnoor'); ?></small>
                        <div class="hamnoor-mobile-focus-empty-actions"><a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('مشاهده پویش‌ها', 'hamnoor'); ?></a><a href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a></div>
                    </section>
                <?php endif; ?>

                <div class="hamnoor-mobile-mini-stats" aria-label="<?php esc_attr_e('خلاصه وضعیت', 'hamnoor'); ?>">
                    <button type="button" data-tab-target="my-parts"><strong><?php echo (int) $open_parts; ?></strong><span><?php esc_html_e('سهم ناتمام', 'hamnoor'); ?></span></button>
                    <button type="button" data-tab-target="my-khatms"><strong><?php echo (int) $owned_total; ?></strong><span><?php esc_html_e('پویش من', 'hamnoor'); ?></span></button>
                    <button type="button" data-tab-target="notifications"><strong><?php echo (int) $unread_notifications; ?></strong><span><?php esc_html_e('اعلان جدید', 'hamnoor'); ?></span></button>
                </div>

                <nav class="hamnoor-mobile-quick-grid" aria-label="<?php esc_attr_e('دسترسی سریع', 'hamnoor'); ?>">
                    <?php if (!$personal_quran) : ?><form class="hamnoor-personal-quran-start-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('hamnoor_start_personal_quran'); ?><input type="hidden" name="action" value="hamnoor_start_personal_quran"><button type="submit"><span class="hamnoor-mobile-quick-icon">☾</span><strong><?php esc_html_e('ختم شخصی', 'hamnoor'); ?></strong><small><?php esc_html_e('شروع یک ختم کامل قرآن', 'hamnoor'); ?></small></button></form><?php endif; ?>
                    <button type="button" data-tab-target="my-parts"><span class="hamnoor-mobile-quick-icon">☰</span><strong><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></strong><small><?php esc_html_e('سهم‌ها و ادامه قرائت', 'hamnoor'); ?></small></button>
                    <a href="<?php echo esc_url($create_url); ?>"><span class="hamnoor-mobile-quick-icon">＋</span><strong><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></strong><small><?php esc_html_e('ساخت پویش جدید', 'hamnoor'); ?></small></a>
                    <button type="button" data-tab-target="my-khatms"><span class="hamnoor-mobile-quick-icon">◇</span><strong><?php esc_html_e('پویش‌های من', 'hamnoor'); ?></strong><small><?php esc_html_e('مدیریت پویش‌ها', 'hamnoor'); ?></small></button>
                    <button type="button" data-tab-target="notifications"><span class="hamnoor-mobile-quick-icon">◉</span><strong><?php esc_html_e('اعلان‌ها', 'hamnoor'); ?></strong><small><?php esc_html_e('پیام‌های مهم امروز', 'hamnoor'); ?></small></button>
                </nav>

                <?php if ($mobile_open_previews) : ?>
                    <section class="hamnoor-mobile-today-list">
                        <div class="hamnoor-mobile-section-head"><h3><?php esc_html_e('سهم‌های در انتظار شما', 'hamnoor'); ?></h3><button type="button" data-tab-target="my-parts"><?php esc_html_e('همه', 'hamnoor'); ?></button></div>
                        <div class="hamnoor-mobile-compact-list">
                            <?php foreach ($mobile_open_previews as $preview) : $preview_part=$preview['part']; $preview_state=$preview['state']; $preview_khatm=$preview['khatm']; ?>
                                <a href="<?php echo esc_url($preview_state['url']); ?>" class="hamnoor-mobile-compact-part">
                                    <div><strong><?php echo esc_html($preview_part->title); ?></strong><small><?php echo esc_html($preview_khatm->title); ?></small></div>
                                    <span><?php echo (int) $preview_state['percent']; ?>٪</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php
                /**
                 * Generic optional dashboard extensions are intentionally rendered after
                 * Hamnoor core mobile journey. Reading/continuing shares, campaign
                 * participation and core quick actions must remain visually prior to
                 * secondary growth/reward integrations on mobile and embedded app surfaces.
                 */
                do_action('hamnoor_dashboard_summary_extension', $user, 'mobile');
                ?>
            </div>
        </section>
        <section id="hamnoor-panel-my-khatms" class="hamnoor-dashboard-panel <?php echo $active_tab === 'my-khatms' ? 'is-active' : ''; ?>" data-tab-panel="my-khatms" role="tabpanel" aria-labelledby="hamnoor-tab-my-khatms" <?php echo $active_tab === 'my-khatms' ? '' : 'hidden'; ?>>
            <h3><?php esc_html_e('پویش‌های ایجادشده توسط من', 'hamnoor'); ?></h3>
            <?php if ($owned) : ?>
                <?php
                $hamnoor_owner_lifecycle_settings = Hamnoor_Helpers::settings();
                $hamnoor_owner_pause_allowed = '1' === (string)($hamnoor_owner_lifecycle_settings['owner_pause_enabled'] ?? '1');
                $hamnoor_owner_cancel_allowed = '1' === (string)($hamnoor_owner_lifecycle_settings['owner_cancel_enabled'] ?? '1');
                ?>
                <div class="hamnoor-dashboard-list">
                <?php foreach ($owned as $khatm) :
                    $hamnoor_personal_quran = Hamnoor_Campaign_Domain::is_personal_quran($khatm);
                    if ($hamnoor_personal_quran) {
                        $hamnoor_personal_progress = Hamnoor_Campaign_Repository::progress($khatm);
                        if (is_wp_error($hamnoor_personal_progress)) { $hamnoor_personal_progress = array('current'=>0,'target'=>0,'percentage'=>0,'is_completed'=>false); }
                        $progress = array('total'=>(int)$hamnoor_personal_progress['target'],'done'=>(int)$hamnoor_personal_progress['current'],'claimed'=>0,'available'=>0);
                        $hamnoor_owner_percent = min(100, max(0, (int)round((float)$hamnoor_personal_progress['percentage'])));
                    } else {
                        $progress = Hamnoor_DB::get_progress((int)$khatm->id);
                        $hamnoor_owner_percent = !empty($progress['total']) ? min(100, max(0, (int)round(((int)$progress['done'] / (int)$progress['total']) * 100))) : 0;
                    }
                ?>
                    <div class="hamnoor-dashboard-khatm-item">
                    <article class="hamnoor-row-card">
                        <div class="hamnoor-row-card-body">
                            <div class="hamnoor-owner-card-head">
                                <strong><?php if ($hamnoor_personal_quran) : ?><?php echo esc_html($khatm->title); ?><?php else : ?><a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php echo esc_html($khatm->title); ?></a><?php endif; ?></strong>
                                <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($khatm->status); ?>"><?php echo esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')); ?></span>
                            </div>
                            <div class="hamnoor-owner-progress" aria-label="<?php echo esc_attr(sprintf(__('%1$d درصد پیشرفت', 'hamnoor'), $hamnoor_owner_percent)); ?>">
                                <div class="hamnoor-owner-progress-meta"><span><?php echo esc_html($hamnoor_personal_quran ? __('پیشرفت ختم شخصی قرآن', 'hamnoor') : sprintf(__('%1$d از %2$d سهم تکمیل شده', 'hamnoor'), (int)$progress['done'], (int)$progress['total'])); ?></span><b><?php echo (int)$hamnoor_owner_percent; ?>٪</b></div>
                                <div class="hamnoor-owner-progress-track"><span style="width:<?php echo esc_attr($hamnoor_owner_percent); ?>%"></span></div>
                            </div>
                            <?php if (!$hamnoor_personal_quran && 'active' === (string)$khatm->status && 0 === ((int)$progress['claimed'] + (int)$progress['done'])) : ?><small class="hamnoor-owner-next-hint"><?php esc_html_e('هنوز مشارکتی ثبت نشده؛ لینک دعوت را برای اولین همراه بفرستید.', 'hamnoor'); ?></small><?php endif; ?>
                        </div>
                        <?php if (!$hamnoor_personal_quran) : ?>
                        <div class="hamnoor-owner-quick-actions" aria-label="<?php esc_attr_e('اقدام‌های اصلی پویش', 'hamnoor'); ?>">
                            <button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-owner-copy-link" data-hamnoor-copy-value="<?php echo esc_attr(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php esc_html_e('کپی لینک دعوت', 'hamnoor'); ?></button>
                            <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url(add_query_arg(array('tab'=>'my-khatms','participants_khatm'=>(int)$khatm->id), Hamnoor_Helpers::page_url('dashboard'))); ?>"><?php esc_html_e('شرکت‌کنندگان', 'hamnoor'); ?></a>
                            <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url(add_query_arg(array('tab'=>'progress','progress_khatm'=>(int)$khatm->id), Hamnoor_Helpers::page_url('dashboard'))); ?>"><?php esc_html_e('پیشرفت', 'hamnoor'); ?></a>
                        </div>
                        <details class="hamnoor-card-actions hamnoor-owner-more-actions">
                            <summary><?php esc_html_e('جزئیات و مدیریت', 'hamnoor'); ?></summary>
                            <a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php esc_html_e('مشاهده صفحه پویش', 'hamnoor'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('edit_khatm', (int) $khatm->id, $create_url)); ?>"><?php esc_html_e('ویرایش پویش', 'hamnoor'); ?></a>
                            <?php if (Hamnoor_Export::can_export((int) $khatm->id, Hamnoor_Mobile_Access::current_user_id())) : ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_xlsx&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)); ?>">Excel</a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_csv&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)); ?>">CSV</a>
                            <?php endif; ?>
                            <?php $hamnoor_pause_source = isset($khatm->pause_source) ? sanitize_key((string)$khatm->pause_source) : ''; ?>
                            <?php if ('active' === (string)$khatm->status) : ?>
                                <?php if ($hamnoor_owner_pause_allowed) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-owner-status-form" onsubmit="return window.confirm('<?php echo esc_js(Hamnoor_Texts::get('owner_pause_confirm')); ?>');">
                                        <input type="hidden" name="action" value="hamnoor_owner_khatm_status"><input type="hidden" name="khatm_id" value="<?php echo (int)$khatm->id; ?>"><input type="hidden" name="target_status" value="paused"><?php wp_nonce_field('hamnoor_owner_khatm_status_' . (int)$khatm->id); ?>
                                        <button type="submit" class="hamnoor-card-action-button"><?php echo esc_html(Hamnoor_Texts::get('owner_pause_button')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($hamnoor_owner_cancel_allowed) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-owner-status-form" onsubmit="return window.confirm('<?php echo esc_js(Hamnoor_Texts::get('owner_cancel_confirm')); ?>');">
                                        <input type="hidden" name="action" value="hamnoor_owner_khatm_status"><input type="hidden" name="khatm_id" value="<?php echo (int)$khatm->id; ?>"><input type="hidden" name="target_status" value="cancelled"><?php wp_nonce_field('hamnoor_owner_khatm_status_' . (int)$khatm->id); ?>
                                        <button type="submit" class="hamnoor-card-action-button is-danger"><?php echo esc_html(Hamnoor_Texts::get('owner_cancel_button')); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ('paused' === (string)$khatm->status && 'owner' === $hamnoor_pause_source) : ?>
                                <?php if ($hamnoor_owner_pause_allowed) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-owner-status-form" onsubmit="return window.confirm('<?php echo esc_js(Hamnoor_Texts::get('owner_resume_confirm')); ?>');">
                                        <input type="hidden" name="action" value="hamnoor_owner_khatm_status"><input type="hidden" name="khatm_id" value="<?php echo (int)$khatm->id; ?>"><input type="hidden" name="target_status" value="active"><?php wp_nonce_field('hamnoor_owner_khatm_status_' . (int)$khatm->id); ?>
                                        <button type="submit" class="hamnoor-card-action-button"><?php echo esc_html(Hamnoor_Texts::get('owner_resume_button')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($hamnoor_owner_cancel_allowed) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-owner-status-form" onsubmit="return window.confirm('<?php echo esc_js(Hamnoor_Texts::get('owner_cancel_confirm')); ?>');">
                                        <input type="hidden" name="action" value="hamnoor_owner_khatm_status"><input type="hidden" name="khatm_id" value="<?php echo (int)$khatm->id; ?>"><input type="hidden" name="target_status" value="cancelled"><?php wp_nonce_field('hamnoor_owner_khatm_status_' . (int)$khatm->id); ?>
                                        <button type="submit" class="hamnoor-card-action-button is-danger"><?php echo esc_html(Hamnoor_Texts::get('owner_cancel_button')); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        <div class="hamnoor-owner-secondary-divider" aria-hidden="true"></div>
                    <?php if ('pending_review' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-warning"><?php esc_html_e('این پویش در انتظار بررسی و انتشار مدیر سایت است.', 'hamnoor'); ?></div>
                    <?php elseif ('needs_revision' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-warning"><strong><?php esc_html_e('این پویش نیازمند اصلاح است.', 'hamnoor'); ?></strong><?php if (!empty($khatm->review_note)) : ?> <span><?php echo esc_html($khatm->review_note); ?></span><?php endif; ?> <a href="<?php echo esc_url(add_query_arg('edit_khatm', (int) $khatm->id, $create_url)); ?>"><?php esc_html_e('اصلاح پویش', 'hamnoor'); ?></a></div>
                    <?php elseif ('rejected' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-error"><strong><?php esc_html_e('این پویش تأیید نشد.', 'hamnoor'); ?></strong><?php if (!empty($khatm->review_note)) : ?> <span><?php echo esc_html($khatm->review_note); ?></span><?php endif; ?></div>
                    <?php elseif ('paused' === (string) $khatm->status) : ?>
                        <?php $hamnoor_pause_source = isset($khatm->pause_source) ? sanitize_key((string)$khatm->pause_source) : 'admin'; ?>
                        <div class="hamnoor-notice hamnoor-notice-info">
                            <?php echo esc_html('owner' === $hamnoor_pause_source ? Hamnoor_Texts::get('owner_pause_notice') : Hamnoor_Texts::get('admin_pause_notice')); ?>
                            <?php if ('owner' !== $hamnoor_pause_source && !empty($khatm->pause_note)) : ?><br><strong><?php echo esc_html(Hamnoor_Texts::get('admin_pause_reason_prefix')); ?></strong> <?php echo esc_html((string)$khatm->pause_note); ?><?php endif; ?>
                            <?php if ('owner' !== $hamnoor_pause_source) : ?><br><small><?php echo esc_html(Hamnoor_Texts::get('admin_pause_resume_blocked')); ?></small><?php endif; ?>
                        </div>
                    <?php elseif ('cancelled' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-warning"><?php esc_html_e('این پویش لغو شده است و سهم جدید یا ثبت تکمیل تازه نمی‌پذیرد.', 'hamnoor'); ?></div>
                    <?php elseif ('completed' === (string) $khatm->status) : ?>
                        <?php
                        $hamnoor_completed_participants = Hamnoor_DB::count_participants((int)$khatm->id);
                        $hamnoor_completed_duration = '';
                        if (!empty($khatm->created_at) && !empty($khatm->completed_at)) {
                            $hamnoor_start_ts = strtotime((string)$khatm->created_at);
                            $hamnoor_end_ts = strtotime((string)$khatm->completed_at);
                            if ($hamnoor_start_ts && $hamnoor_end_ts && $hamnoor_end_ts >= $hamnoor_start_ts) { $hamnoor_completed_duration = human_time_diff($hamnoor_start_ts, $hamnoor_end_ts); }
                        }
                        $hamnoor_thanks_sent = Hamnoor_DB::khatm_thanks_sent((int)$khatm->id);
                        $hamnoor_can_thank_sensitive = current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms') || Hamnoor_Mobile_Access::has_sensitive_access();
                        ?>
                        <div class="hamnoor-notice hamnoor-notice-success"><strong><?php esc_html_e('این پویش با موفقیت تکمیل شده است.', 'hamnoor'); ?></strong> <?php esc_html_e('سابقه آن در پنل شما باقی می‌ماند.', 'hamnoor'); ?></div>
                        <div class="hamnoor-completion-summary" aria-label="<?php esc_attr_e('گزارش پایان پویش', 'hamnoor'); ?>">
                            <strong><?php esc_html_e('گزارش پایان', 'hamnoor'); ?></strong>
                            <span><?php echo esc_html(sprintf(__('%d مشارکت‌کننده', 'hamnoor'), (int)$hamnoor_completed_participants)); ?></span>
                            <span><?php echo esc_html(sprintf(__('%d سهم تکمیل‌شده', 'hamnoor'), (int)$progress['done'])); ?></span>
                            <?php if ($hamnoor_completed_duration) : ?><span><?php echo esc_html(sprintf(__('مدت اجرا: %s', 'hamnoor'), $hamnoor_completed_duration)); ?></span><?php endif; ?>
                        </div>
                        <?php if ($hamnoor_thanks_sent) : ?>
                            <p class="hamnoor-help"><?php esc_html_e('پیام تشکر این پویش قبلاً برای کاربران متصل ثبت شده است.', 'hamnoor'); ?></p>
                        <?php elseif ($hamnoor_can_thank_sensitive) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form" onsubmit="return window.confirm('<?php echo esc_js(__('پیام تشکر یک‌بار برای همه مشارکت‌کنندگانِ دارای حساب هم‌نور ارسال شود؟', 'hamnoor')); ?>');">
                                <input type="hidden" name="action" value="hamnoor_thank_participants">
                                <input type="hidden" name="khatm_id" value="<?php echo (int)$khatm->id; ?>">
                                <?php wp_nonce_field('hamnoor_thank_participants_' . (int)$khatm->id); ?>
                                <button type="submit" class="hamnoor-btn hamnoor-btn-secondary"><?php esc_html_e('ارسال پیام تشکر', 'hamnoor'); ?></button>
                            </form>
                        <?php else : ?>
                            <p class="hamnoor-help"><a href="<?php echo esc_url(add_query_arg('tab', 'profile', Hamnoor_Helpers::page_url('dashboard'))); ?>"><?php esc_html_e('برای ارسال پیام تشکر، ابتدا هویت این نشست را تأیید کنید.', 'hamnoor'); ?></a></p>
                        <?php endif; ?>
                    <?php elseif ('archived' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-info"><?php esc_html_e('این پویش بایگانی شده و فقط برای مشاهده سابقه نگهداری می‌شود.', 'hamnoor'); ?></div>
                    <?php elseif ('active' === (string) $khatm->status && !empty($khatm->published_at)) : ?>
                        <div class="hamnoor-notice hamnoor-notice-success"><?php esc_html_e('پویش شما توسط مدیر سایت منتشر شده است و لینک دعوت اکنون فعال است.', 'hamnoor'); ?></div>
                    <?php endif; ?>
                    <?php if ('active' === (string)$khatm->status && 0 === ((int)$progress['claimed'] + (int)$progress['done'])) : ?>
                        <?php $hamnoor_first_share_text = Hamnoor_Helpers::share_text($khatm, $progress, 'clipboard'); ?>
                        <div class="hamnoor-notice hamnoor-notice-info hamnoor-first-participant-guide">
                            <div class="hamnoor-first-participant-head"><div><strong><?php esc_html_e('پویش آماده است؛ حالا اولین همراه را دعوت کنید.', 'hamnoor'); ?></strong><p><?php esc_html_e('برای شروع فقط کافی است لینک یا متن دعوت را برای خانواده، دوستان یا گروه موردنظر بفرستید.', 'hamnoor'); ?></p></div><span class="hamnoor-first-participant-badge"><?php esc_html_e('قدم بعدی', 'hamnoor'); ?></span></div>
                            <ol class="hamnoor-mini-journey" aria-label="<?php esc_attr_e('شروع مشارکت در پویش', 'hamnoor'); ?>"><li class="is-done"><span>۱</span><?php esc_html_e('پویش منتشر شد', 'hamnoor'); ?></li><li class="is-current"><span>۲</span><?php esc_html_e('دعوت را ارسال کنید', 'hamnoor'); ?></li><li><span>۳</span><?php esc_html_e('اولین سهم انتخاب می‌شود', 'hamnoor'); ?></li></ol>
                            <div class="hamnoor-share-box"><input readonly value="<?php echo esc_attr(Hamnoor_Helpers::invite_url($khatm)); ?>" aria-label="<?php esc_attr_e('لینک دعوت پویش', 'hamnoor'); ?>"><button type="button" class="hamnoor-btn hamnoor-btn-primary hamnoor-copy"><?php esc_html_e('کپی لینک دعوت', 'hamnoor'); ?></button></div>
                            <div class="hamnoor-share-actions hamnoor-first-participant-actions" data-hamnoor-clipboard="<?php echo esc_attr($hamnoor_first_share_text); ?>"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy-invite"><?php esc_html_e('کپی متن دعوت', 'hamnoor'); ?></button><a class="hamnoor-link-button" href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php esc_html_e('مشاهده صفحه پویش', 'hamnoor'); ?></a></div>
                        </div>
                    <?php endif; ?>
                    <?php
                    $feature_request_status = Hamnoor_Featured_Service::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
                    $feature_badge = Hamnoor_Featured_Service::badge_data($khatm);
                    $can_request_feature = Hamnoor_Featured_Service::can_request($khatm, Hamnoor_Mobile_Access::current_user_id());
                    ?>
                    <div class="hamnoor-feature-request">
                        <?php if ($feature_badge) : ?>
                            <span class="hamnoor-featured-badge"><?php echo esc_html($feature_badge['text']); ?></span>
                        <?php elseif (Hamnoor_Featured_Service::is_configured($khatm)) : ?>
                            <span class="hamnoor-feature-request-status"><?php echo esc_html(Hamnoor_Featured_Service::status_label($khatm)); ?></span>
                        <?php elseif ('pending' === $feature_request_status) : ?>
                            <span class="hamnoor-feature-request-status is-pending"><?php echo esc_html(Hamnoor_Texts::get('feature_request_pending')); ?></span>
                        <?php else : ?>
                            <?php if ('rejected' === $feature_request_status) : ?><p class="hamnoor-feature-request-rejected"><?php echo esc_html(Hamnoor_Texts::get('feature_request_rejected')); ?></p><?php endif; ?>
                            <?php if ($can_request_feature) : ?>
                                <details>
                                    <summary class="hamnoor-btn hamnoor-btn-secondary"><?php echo esc_html(Hamnoor_Texts::get('feature_request_button')); ?></summary>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-feature-request-form">
                                        <?php wp_nonce_field('hamnoor_request_feature_' . (int) $khatm->id); ?>
                                        <input type="hidden" name="action" value="hamnoor_request_feature">
                                        <input type="hidden" name="khatm_id" value="<?php echo (int) $khatm->id; ?>">
                                        <label><?php esc_html_e('دلیل درخواست ویژه شدن، اختیاری', 'hamnoor'); ?><textarea name="featured_request_message" rows="3" maxlength="500"></textarea></label>
                                        <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php echo esc_html(Hamnoor_Texts::get('feature_request_button')); ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ('approved' === $feature_request_status) : ?>
                            <span class="hamnoor-feature-request-status is-approved"><?php echo esc_html(Hamnoor_Featured_Service::request_status_label($feature_request_status)); ?></span>
                        <?php endif; ?>
                        <?php if ('none' !== $feature_request_status && !empty($khatm->featured_request_message)) : ?>
                            <p class="hamnoor-feature-request-message"><strong><?php esc_html_e('توضیح درخواست:', 'hamnoor'); ?></strong> <?php echo esc_html($khatm->featured_request_message); ?></p>
                        <?php endif; ?>
                    </div>
                        </details>
                        <?php endif; ?>
                    </article>
                    </div>
                <?php endforeach; ?></div><?php else : ?><div class="hamnoor-action-empty-state hamnoor-owner-empty-state"><span class="hamnoor-empty-eyebrow"><?php esc_html_e('شروع از اینجا', 'hamnoor'); ?></span><h3><?php esc_html_e('اولین پویش خود را ایجاد کنید', 'hamnoor'); ?></h3><p><?php esc_html_e('عنوان و نیت را مشخص کنید؛ هم‌نور تقسیم سهم، لینک دعوت و پیگیری پیشرفت را برای شما آماده می‌کند.', 'hamnoor'); ?></p><div class="hamnoor-empty-actions"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد اولین پویش', 'hamnoor'); ?></a><a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('دیدن پویش‌های عمومی', 'hamnoor'); ?></a></div></div><?php endif; ?>
            <?php if ($participants_error || $participants_khatm) : ?>
                <div class="hamnoor-card hamnoor-dashboard-participants">
                    <h4><?php esc_html_e('شرکت‌کنندگان پویش', 'hamnoor'); ?><?php if ($participants_khatm) : ?>: <?php echo esc_html($participants_khatm->title); ?><?php endif; ?></h4>
                    <?php if ($participants_error) : ?>
                        <p class="hamnoor-empty"><?php echo esc_html($participants_error); ?></p>
                    <?php elseif (!$participants_rows) : ?>
                        <p class="hamnoor-empty"><?php esc_html_e('هنوز شرکت‌کننده‌ای برای این پویش ثبت نشده است.', 'hamnoor'); ?></p>
                    <?php else : ?>
                        <div class="hamnoor-mobile-cards">
                            <?php $hamnoor_claim_settings = Hamnoor_Helpers::settings(); $hamnoor_recent_limit = max(3, (int)($hamnoor_claim_settings['participant_suspicious_claim_limit'] ?? 8)); ?>
                            <?php foreach ($participants_rows as $row) : ?>
                                <div class="hamnoor-row-card hamnoor-participant-manage-card">
                                    <strong><?php echo esc_html($row->name); ?></strong>
                                    <span><?php echo esc_html(Hamnoor_Helpers::mask_phone($row->phone)); ?></span>
                                    <small><?php echo esc_html(sprintf(__('ناتمام: %1$d / تکمیل‌شده: %2$d / کل ثبت‌شده: %3$d', 'hamnoor'), (int)$row->claimed_parts, (int)$row->done_parts, (int)$row->total_parts)); ?></small>
                                    <?php if (!empty($row->claim_blocked)) : ?><span class="hamnoor-status hamnoor-status-paused"><?php echo esc_html(Hamnoor_Texts::get('participant_blocked_badge')); ?></span><?php endif; ?>
                                    <?php if ((int)$row->recent_claims >= $hamnoor_recent_limit) : ?><span class="hamnoor-status hamnoor-status-warning"><?php echo esc_html(Hamnoor_Texts::get('participant_suspicious_badge')); ?></span><?php endif; ?>
                                    <div class="hamnoor-participant-actions">
                                        <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url(add_query_arg(array('tab'=>'my-khatms','participants_khatm'=>(int)$participants_khatm->id,'participant_id'=>(int)$row->participant_id), Hamnoor_Helpers::page_url('dashboard'))); ?>"><?php esc_html_e('مشاهده سهم‌ها', 'hamnoor'); ?></a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(!empty($row->claim_blocked) ? Hamnoor_Texts::get('participant_unblock_confirm') : Hamnoor_Texts::get('participant_block_confirm')); ?>');">
                                            <input type="hidden" name="action" value="hamnoor_owner_participant_action"><input type="hidden" name="khatm_id" value="<?php echo (int)$participants_khatm->id; ?>"><input type="hidden" name="participant_id" value="<?php echo (int)$row->participant_id; ?>"><input type="hidden" name="participant_action" value="<?php echo !empty($row->claim_blocked) ? 'unblock' : 'block'; ?>"><?php wp_nonce_field('hamnoor_owner_participant_' . (int)$participants_khatm->id . '_' . (int)$row->participant_id); ?>
                                            <button type="submit" class="hamnoor-card-action-button<?php echo empty($row->claim_blocked) ? ' is-danger' : ''; ?>"><?php echo esc_html(!empty($row->claim_blocked) ? Hamnoor_Texts::get('participant_unblock_button') : Hamnoor_Texts::get('participant_block_button')); ?></button>
                                        </form>
                                        <?php if ((int)$row->claimed_parts > 0) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(Hamnoor_Texts::get('participant_release_open_confirm')); ?>');"><input type="hidden" name="action" value="hamnoor_owner_participant_action"><input type="hidden" name="khatm_id" value="<?php echo (int)$participants_khatm->id; ?>"><input type="hidden" name="participant_id" value="<?php echo (int)$row->participant_id; ?>"><input type="hidden" name="participant_action" value="release_open"><?php wp_nonce_field('hamnoor_owner_participant_' . (int)$participants_khatm->id . '_' . (int)$row->participant_id); ?><button type="submit" class="hamnoor-card-action-button is-danger"><?php echo esc_html(Hamnoor_Texts::get('participant_release_open_button')); ?></button></form><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($participants_selected) : ?>
                            <div class="hamnoor-card hamnoor-participant-parts-detail"><h5><?php echo esc_html(sprintf(__('سهم‌های %s', 'hamnoor'), $participants_selected->name)); ?></h5>
                                <?php if (!$participants_selected_parts) : ?><p class="hamnoor-empty"><?php esc_html_e('سهم فعالی یا سابقه‌ای برای این شرکت‌کننده باقی نمانده است.', 'hamnoor'); ?></p><?php else : ?>
                                    <div class="hamnoor-mobile-cards">
                                    <?php foreach ($participants_selected_parts as $managed_part) : ?><div class="hamnoor-row-card"><strong><?php echo esc_html($managed_part->title); ?></strong><span><?php echo esc_html(Hamnoor_Helpers::status_label($managed_part->status, 'part')); ?></span><?php if ('claimed' === (string)$managed_part->status) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(__('این سهم آزاد شود و دوباره در اختیار دیگران قرار گیرد؟', 'hamnoor')); ?>');"><input type="hidden" name="action" value="hamnoor_owner_participant_action"><input type="hidden" name="khatm_id" value="<?php echo (int)$participants_khatm->id; ?>"><input type="hidden" name="participant_id" value="<?php echo (int)$participants_selected->id; ?>"><input type="hidden" name="part_id" value="<?php echo (int)$managed_part->id; ?>"><input type="hidden" name="participant_action" value="release_part"><?php wp_nonce_field('hamnoor_owner_participant_' . (int)$participants_khatm->id . '_' . (int)$participants_selected->id); ?><button type="submit" class="hamnoor-card-action-button is-danger"><?php esc_html_e('آزاد کردن این سهم', 'hamnoor'); ?></button></form><?php endif; ?></div><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <section id="hamnoor-panel-progress" class="hamnoor-dashboard-panel <?php echo $active_tab === 'progress' ? 'is-active' : ''; ?>" data-tab-panel="progress" role="tabpanel" aria-labelledby="hamnoor-tab-progress" <?php echo $active_tab === 'progress' ? '' : 'hidden'; ?>>
            <div class="hamnoor-progress-center-head">
                <div><h3><?php esc_html_e('مرکز پیشرفت پویش', 'hamnoor'); ?></h3><p class="hamnoor-help"><?php esc_html_e('وضعیت سهم‌ها، آخرین فعالیت و یادآوری‌های داخلی را از اینجا پیگیری کنید.', 'hamnoor'); ?></p></div>
                <?php if ($progress_owned) : ?>
                    <form method="get" action="<?php echo esc_url(Hamnoor_Helpers::page_url('dashboard')); ?>" class="hamnoor-progress-khatm-select">
                        <input type="hidden" name="tab" value="progress">
                        <select name="progress_khatm" onchange="this.form.submit()">
                            <?php foreach ($progress_owned as $owned_khatm) : ?><option value="<?php echo (int)$owned_khatm->id; ?>" <?php selected($progress_khatm ? (int)$progress_khatm->id : 0, (int)$owned_khatm->id); ?>><?php echo esc_html($owned_khatm->title); ?></option><?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$progress_owned) : ?>
                <div class="hamnoor-action-empty-state hamnoor-progress-empty-state"><h3><?php esc_html_e('هنوز پویشی برای پیگیری ندارید', 'hamnoor'); ?></h3><p><?php esc_html_e('بعد از ساخت اولین پویش، وضعیت سهم‌ها و مشارکت‌کنندگان در همین بخش نمایش داده می‌شود.', 'hamnoor'); ?></p><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a></div>
            <?php elseif (!$progress_khatm) : ?>
                <p class="hamnoor-empty"><?php esc_html_e('پویش مورد نظر برای نمایش پیشرفت پیدا نشد.', 'hamnoor'); ?></p>
            <?php else : ?>
                <div class="hamnoor-progress-summary-grid">
                    <div><strong><?php echo (int)$progress_summary['available']; ?></strong><span><?php esc_html_e('آزاد', 'hamnoor'); ?></span></div>
                    <div><strong><?php echo (int)$progress_summary['not_started']; ?></strong><span><?php esc_html_e('شروع‌نشده', 'hamnoor'); ?></span></div>
                    <div><strong><?php echo (int)$progress_summary['reading']; ?></strong><span><?php esc_html_e('در حال قرائت', 'hamnoor'); ?></span></div>
                    <div><strong><?php echo (int)$progress_summary['ready']; ?></strong><span><?php esc_html_e('آماده پایان', 'hamnoor'); ?></span></div>
                    <div><strong><?php echo (int)$progress_summary['done']; ?></strong><span><?php esc_html_e('تکمیل‌شده', 'hamnoor'); ?></span></div>
                </div>
                <?php if ($progress_rows) : ?>
                    <div class="hamnoor-mobile-ui hamnoor-mobile-progress-list">
                        <?php foreach ($progress_rows as $row) : ?>
                            <article class="hamnoor-mobile-progress-card">
                                <div class="hamnoor-mobile-progress-head">
                                    <strong><?php echo esc_html($row->title); ?></strong>
                                    <span class="hamnoor-status hamnoor-status-<?php echo esc_attr($row->progress_status); ?>"><?php echo esc_html($row->progress_status_label); ?></span>
                                </div>
                                <?php if (!empty($row->participant_name)) : ?>
                                    <div class="hamnoor-mobile-progress-person"><span><?php esc_html_e('مشارکت‌کننده', 'hamnoor'); ?></span><b><?php echo esc_html($row->participant_name); ?></b></div>
                                <?php endif; ?>
                                <div class="hamnoor-mobile-progress-bar"><span style="width:<?php echo esc_attr((int)$row->progress_percent); ?>%"></span></div>
                                <div class="hamnoor-mobile-progress-meta">
                                    <span><?php echo esc_html(sprintf(__('%d٪ پیشرفت', 'hamnoor'), (int)$row->progress_percent)); ?></span>
                                    <?php if (!empty($row->last_activity)) : ?><span><?php echo esc_html(Hamnoor_Jalali::to_jalali($row->last_activity, true)); ?></span><?php endif; ?>
                                </div>
                                <?php if ('claimed' === (string)$row->status && !empty($row->participant_user_id)) : ?>
                                    <div class="hamnoor-mobile-progress-action">
                                        <?php if (isset($row->reminder_enabled) && 0 === (int)$row->reminder_enabled) : ?>
                                            <small><?php esc_html_e('یادآوری توسط مشارکت‌کننده غیرفعال شده است.', 'hamnoor'); ?></small>
                                        <?php else : ?>
                                            <button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-owner-reminder data-part-id="<?php echo (int)$row->part_id; ?>"><?php esc_html_e('ارسال یادآوری', 'hamnoor'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="hamnoor-progress-table-wrap hamnoor-desktop-ui">
                        <table class="hamnoor-progress-table">
                            <thead><tr><th><?php esc_html_e('سهم', 'hamnoor'); ?></th><th><?php esc_html_e('مشارکت‌کننده', 'hamnoor'); ?></th><th><?php esc_html_e('وضعیت', 'hamnoor'); ?></th><th><?php esc_html_e('پیشرفت', 'hamnoor'); ?></th><th><?php esc_html_e('آخرین فعالیت', 'hamnoor'); ?></th><th><?php esc_html_e('یادآوری', 'hamnoor'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($progress_rows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->title); ?></strong></td>
                                    <td><?php echo !empty($row->participant_name) ? esc_html($row->participant_name) : '—'; ?><?php if (!empty($row->participant_phone)) : ?><small><?php echo esc_html(Hamnoor_Helpers::mask_phone($row->participant_phone)); ?></small><?php endif; ?></td>
                                    <td><span class="hamnoor-status hamnoor-status-<?php echo esc_attr($row->progress_status); ?>"><?php echo esc_html($row->progress_status_label); ?></span></td>
                                    <td><div class="hamnoor-progress-mini"><span style="width:<?php echo esc_attr((int)$row->progress_percent); ?>%"></span></div><small><?php echo (int)$row->progress_percent; ?>٪</small></td>
                                    <td><?php echo !empty($row->last_activity) ? esc_html(Hamnoor_Jalali::to_jalali($row->last_activity, true)) : '—'; ?></td>
                                    <td>
                                        <?php if ('claimed' === (string)$row->status && !empty($row->participant_user_id)) : ?>
                                            <?php if (isset($row->reminder_enabled) && 0 === (int)$row->reminder_enabled) : ?>
                                                <small><?php esc_html_e('مشارکت‌کننده یادآوری را غیرفعال کرده است.', 'hamnoor'); ?></small>
                                            <?php else : ?>
                                                <button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-reminder-small" data-owner-reminder data-part-id="<?php echo (int)$row->part_id; ?>"><?php esc_html_e('یادآوری', 'hamnoor'); ?></button>
                                                <?php if (!empty($row->last_reminder_at)) : ?><small><?php echo esc_html(sprintf(__('آخرین: %s', 'hamnoor'), Hamnoor_Jalali::to_jalali($row->last_reminder_at, true))); ?></small><?php endif; ?>
                                            <?php endif; ?>
                                        <?php else : ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($progress_pages > 1) : ?>
                        <nav class="hamnoor-parts-pagination hamnoor-progress-pagination" aria-label="<?php esc_attr_e('صفحه‌بندی مرکز پیشرفت', 'hamnoor'); ?>">
                            <?php
                            echo wp_kses_post(paginate_links(array(
                                'base'=>str_replace('%25%23%25','%#%',add_query_arg(array('tab'=>'progress','progress_khatm'=>(int)$progress_khatm->id,'progress_page'=>'%#%'), Hamnoor_Helpers::page_url('dashboard'))),
                                'current'=>$progress_page,
                                'total'=>$progress_pages,
                                'type'=>'list',
                                'prev_text'=>__('قبلی','hamnoor'),
                                'next_text'=>__('بعدی','hamnoor'),
                            )));
                            ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <section id="hamnoor-panel-my-parts" class="hamnoor-dashboard-panel <?php echo $active_tab === 'my-parts' ? 'is-active' : ''; ?>" data-tab-panel="my-parts" role="tabpanel" aria-labelledby="hamnoor-tab-my-parts" <?php echo $active_tab === 'my-parts' ? '' : 'hidden'; ?>>
            <div class="hamnoor-my-parts-heading">
                <div>
                    <h3><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></h3>
                    <p><?php esc_html_e('همه همراهی‌های شما در پویش‌های قرآن، دعا و ذکر در یک مسیر نمایش داده می‌شوند.', 'hamnoor'); ?></p>
                </div>
                <?php if ($open_parts) : ?><span class="hamnoor-my-parts-open-badge"><?php echo esc_html(sprintf(_n('%d سهم ناتمام قرآن', '%d سهم ناتمام قرآن', (int)$open_parts, 'hamnoor'), (int)$open_parts)); ?></span><?php endif; ?>
            </div>
            <div class="hamnoor-unified-share-filters" role="group" aria-label="<?php esc_attr_e('فیلتر نوع سهم', 'hamnoor'); ?>" data-unified-share-filters><button type="button" class="is-active" data-share-type="all" aria-pressed="true"><?php esc_html_e('همه', 'hamnoor'); ?></button><button type="button" data-share-type="quran" aria-pressed="false"><?php esc_html_e('قرآن', 'hamnoor'); ?></button><button type="button" data-share-type="dua" aria-pressed="false"><?php esc_html_e('دعا', 'hamnoor'); ?></button><button type="button" data-share-type="dhikr" aria-pressed="false"><?php esc_html_e('ذکر', 'hamnoor'); ?></button></div>
            <div data-share-type-region="quran">
            <?php if ($open_parts) : ?><p class="hamnoor-help hamnoor-once-help"><?php echo esc_html(Hamnoor_Texts::get('dashboard_help')); ?></p><?php endif; ?>
            <?php if ($parts) : ?>
                <?php
                $hamnoor_my_parts_khatms = array();
                foreach ($parts as $hamnoor_filter_part) {
                    if (empty($hamnoor_filter_part->khatm_token)) { continue; }
                    $hamnoor_filter_khatm = Hamnoor_DB::get_khatm($hamnoor_filter_part->khatm_token);
                    if ($hamnoor_filter_khatm) { $hamnoor_my_parts_khatms[(int)$hamnoor_filter_khatm->id] = (string)$hamnoor_filter_khatm->title; }
                }
                ?>
                <div class="hamnoor-my-parts-tools" data-my-parts-controls data-default-status="<?php echo esc_attr($open_parts ? 'open' : 'all'); ?>" hidden>
                    <div class="hamnoor-my-parts-status-filters" role="group" aria-label="<?php echo esc_attr__('فیلتر وضعیت سهم‌ها', 'hamnoor'); ?>">
                        <?php if ($open_parts) : ?><button type="button" class="is-active" data-my-parts-status-button="open" aria-pressed="true"><?php esc_html_e('ناتمام', 'hamnoor'); ?></button><?php endif; ?>
                        <button type="button" data-my-parts-status-button="ready" aria-pressed="false"><span class="hamnoor-filter-label-desktop"><?php esc_html_e('نیازمند ثبت پایان', 'hamnoor'); ?></span><span class="hamnoor-filter-label-mobile"><?php esc_html_e('ثبت پایان', 'hamnoor'); ?></span></button>
                        <button type="button" data-my-parts-status-button="done" aria-pressed="false"><?php esc_html_e('تکمیل‌شده', 'hamnoor'); ?></button>
                        <button type="button" class="<?php echo $open_parts ? '' : 'is-active'; ?>" data-my-parts-status-button="all" aria-pressed="<?php echo $open_parts ? 'false' : 'true'; ?>"><?php esc_html_e('همه', 'hamnoor'); ?></button>
                    </div>
                    <div class="hamnoor-my-parts-inline-filters">
                        <?php if (count($hamnoor_my_parts_khatms) > 1) : ?>
                            <label class="hamnoor-my-parts-compact-select hamnoor-my-parts-khatm-filter">
                                <span class="screen-reader-text"><?php esc_html_e('پویش', 'hamnoor'); ?></span>
                                <select data-my-parts-khatm aria-label="<?php echo esc_attr__('فیلتر بر اساس پویش', 'hamnoor'); ?>">
                                    <option value="all"><?php esc_html_e('همه پویش‌ها', 'hamnoor'); ?></option>
                                    <?php foreach ($hamnoor_my_parts_khatms as $hamnoor_filter_khatm_id => $hamnoor_filter_khatm_title) : ?>
                                        <option value="<?php echo (int)$hamnoor_filter_khatm_id; ?>"><?php echo esc_html($hamnoor_filter_khatm_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="hamnoor-my-parts-compact-select hamnoor-my-parts-sort-filter">
                            <span class="screen-reader-text"><?php esc_html_e('مرتب‌سازی', 'hamnoor'); ?></span>
                            <select data-my-parts-sort aria-label="<?php echo esc_attr__('مرتب‌سازی سهم‌ها', 'hamnoor'); ?>">
                                <option value="priority"><?php esc_html_e('اولویت انجام', 'hamnoor'); ?></option>
                                <option value="activity"><?php esc_html_e('آخرین فعالیت', 'hamnoor'); ?></option>
                                <option value="deadline"><?php esc_html_e('نزدیک‌ترین مهلت', 'hamnoor'); ?></option>
                                <option value="newest"><?php esc_html_e('جدیدترین', 'hamnoor'); ?></option>
                                <option value="oldest"><?php esc_html_e('قدیمی‌ترین', 'hamnoor'); ?></option>
                            </select>
                        </label>
                        <small class="hamnoor-my-parts-result-count" data-my-parts-count aria-live="polite"></small>
                    </div>
                </div>
                <div class="hamnoor-my-parts-row-head" aria-hidden="true"><span><?php esc_html_e('پویش و سهم', 'hamnoor'); ?></span><span><?php esc_html_e('وضعیت و پیشرفت', 'hamnoor'); ?></span><span><?php esc_html_e('عملیات', 'hamnoor'); ?></span></div>
                <div class="hamnoor-parts-grid hamnoor-my-parts-grid" data-my-parts-list><?php foreach ($parts as $part) : $khatm = Hamnoor_DB::get_khatm($part->khatm_token); if (!$khatm) { continue; } $context = 'dashboard'; include Hamnoor_Helpers::locate_template('frontend/part-card.php'); endforeach; ?></div>
                <p class="hamnoor-empty" data-my-parts-empty hidden><?php esc_html_e('سهمی مطابق این فیلتر پیدا نشد.', 'hamnoor'); ?></p>
            <?php else : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز سهمی برای شما ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
            <?php if ($completed_archive) : ?>
                <div class="hamnoor-completed-archive">
                    <h4><?php esc_html_e('آرشیو پویش‌های تکمیل‌شده', 'hamnoor'); ?></h4>
                    <?php foreach ($completed_archive as $item) : $archive_khatm = $item['khatm']; $archive_parts = $item['parts']; ?>
                        <details>
                            <summary><?php echo esc_html($archive_khatm->title); ?> <span class="hamnoor-status hamnoor-status-completed"><?php esc_html_e('تکمیل شد', 'hamnoor'); ?></span> <small><?php echo esc_html(sprintf(_n('%d سهم شما', '%d سهم شما', count($archive_parts), 'hamnoor'), count($archive_parts))); ?></small></summary>
                            <?php if (!empty($archive_khatm->completed_at)) : ?><p class="hamnoor-help"><?php echo esc_html(sprintf(__('تاریخ تکمیل: %s', 'hamnoor'), Hamnoor_Jalali::to_jalali($archive_khatm->completed_at, true))); ?></p><?php endif; ?>
                            <div class="hamnoor-parts-grid hamnoor-my-parts-grid">
                                <?php foreach ($archive_parts as $part) : $khatm = $archive_khatm; $context = 'dashboard'; include Hamnoor_Helpers::locate_template('frontend/part-card.php'); endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
            <div class="hamnoor-card hamnoor-contribution-history-host" data-share-type-region="contribution"><div data-unified-contribution-history><p class="hamnoor-empty"><?php esc_html_e('در حال دریافت سهم‌های دعا و ذکر…', 'hamnoor'); ?></p></div></div>
        </section>
        <section id="hamnoor-panel-contributions" class="hamnoor-dashboard-panel <?php echo $active_tab === 'contributions' ? 'is-active' : ''; ?> hamnoor-legacy-contribution-panel" data-tab-panel="contributions" role="tabpanel" aria-labelledby="hamnoor-tab-contributions" <?php echo $active_tab === 'contributions' ? '' : 'hidden'; ?>>
            <div class="hamnoor-card hamnoor-contribution-history-host"><div data-contribution-history-panel="1"><p class="hamnoor-empty" data-contribution-history-loading><?php esc_html_e('در حال دریافت سابقه مشارکت‌ها…', 'hamnoor'); ?></p></div></div>
        </section>
        <section id="hamnoor-panel-notifications" class="hamnoor-dashboard-panel <?php echo $active_tab === 'notifications' ? 'is-active' : ''; ?>" data-tab-panel="notifications" role="tabpanel" aria-labelledby="hamnoor-tab-notifications" <?php echo $active_tab === 'notifications' ? '' : 'hidden'; ?>>
            <h3><?php esc_html_e('اعلان‌ها', 'hamnoor'); ?></h3>
            <?php if ($notifications) : ?>
                <div class="hamnoor-notification-list">
                    <?php foreach ($notifications as $notification) : ?>
                        <article class="hamnoor-card hamnoor-notification-item <?php echo empty($notification->read_at) ? 'is-unread' : ''; ?>">
                            <div><strong><?php echo esc_html($notification->title); ?></strong><?php if (!empty($notification->message)) : ?><p><?php echo esc_html($notification->message); ?></p><?php endif; ?>
                                <?php if ('part' === (string)$notification->related_type && !empty($notification->related_id)) : $notification_part = Hamnoor_DB::get_part((int)$notification->related_id); $notification_campaign = $notification_part ? Hamnoor_DB::get_khatm((int)$notification_part->khatm_id) : null; $notification_actionability = Hamnoor_Engagement::part_reminder_actionability($notification_part, $notification_campaign); if ($notification_part && (int)$notification_part->participant_user_id === (int)$user->id && !empty($notification_actionability['actionable'])) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-notification-action" href="<?php echo esc_url(Hamnoor_Reading::reading_url((int)$notification->related_id)); ?>"><?php esc_html_e('ادامه قرائت', 'hamnoor'); ?></a><?php endif; endif; ?>
                                <?php if ('khatm' === (string)$notification->related_type && !empty($notification->related_id)) : $notification_khatm = Hamnoor_DB::get_khatm((int)$notification->related_id); if ($notification_khatm) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-notification-action" href="<?php echo esc_url(Hamnoor_Helpers::invite_url($notification_khatm)); ?>"><?php esc_html_e('مشاهده پویش', 'hamnoor'); ?></a><?php endif; endif; ?>
                                <?php if ('personal_quran' === (string)$notification->related_type && !empty($notification->related_id)) : $notification_personal = Hamnoor_Campaign_Repository::get_by_id((int)$notification->related_id); if ($notification_personal && Hamnoor_Campaign_Domain::is_personal_quran($notification_personal) && (int)$notification_personal->owner_user_id === (int)$user->id) : ?><a class="hamnoor-btn hamnoor-btn-secondary hamnoor-notification-action" href="<?php echo esc_url(Hamnoor_Personal_Quran::reader_url((int)$notification_personal->id)); ?>"><?php esc_html_e('ادامه ختم شخصی', 'hamnoor'); ?></a><?php endif; endif; ?>
                            </div>
                            <time datetime="<?php echo esc_attr(mysql2date('c', $notification->created_at)); ?>"><?php echo esc_html(Hamnoor_Jalali::to_jalali($notification->created_at, true)); ?></time>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز اعلانی برای شما ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
        </section>
        <section id="hamnoor-panel-reminders" class="hamnoor-dashboard-panel <?php echo $active_tab === 'reminders' ? 'is-active' : ''; ?>" data-tab-panel="reminders" role="tabpanel" aria-labelledby="hamnoor-tab-reminders" <?php echo $active_tab === 'reminders' ? '' : 'hidden'; ?>>
            <?php if ($personal_quran && $personal_quran_reminder_state) :
                $hamnoor_reminder_mask = (int)$personal_quran_reminder_state['days_mask'];
                $hamnoor_reminder_paused = !empty($personal_quran_state['paused']);
                $hamnoor_next_reminder_label = !empty($personal_quran_reminder_state['next_due_at_utc']) ? Hamnoor_Personal_Reminders::local_datetime_label($personal_quran_reminder_state['next_due_at_utc'], $personal_quran_reminder_state['timezone']) : '';
                $hamnoor_snooze_label = !empty($personal_quran_reminder_state['snoozed_until_utc']) ? Hamnoor_Personal_Reminders::local_datetime_label($personal_quran_reminder_state['snoozed_until_utc'], $personal_quran_reminder_state['timezone']) : '';
            ?>
            <div class="hamnoor-card hamnoor-personal-reminder-settings">
                <div class="hamnoor-personal-reminder-head">
                    <div><span class="hamnoor-personal-reminder-icon">⏰</span><div><h3><?php esc_html_e('یادآوری ختم شخصی', 'hamnoor'); ?></h3><p><?php esc_html_e('اگر یادآوری را فعال کنید، هم‌نور زمان مناسب را با توجه به تنظیمات فعلی شما انتخاب می‌کند.', 'hamnoor'); ?></p></div></div>
                    <span class="hamnoor-channel-status <?php echo !empty($personal_quran_reminder_state['enabled']) ? 'is-active' : ''; ?>"><?php echo esc_html(!empty($personal_quran_reminder_state['enabled']) ? ($hamnoor_reminder_paused ? __('فعال؛ همراه ختم متوقف است', 'hamnoor') : __('فعال', 'hamnoor')) : __('خاموش', 'hamnoor')); ?></span>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-personal-reminder-form">
                    <?php wp_nonce_field('hamnoor_personal_quran_reminder_' . (int)$personal_quran->id); ?>
                    <input type="hidden" name="action" value="hamnoor_save_personal_quran_reminder">
                    <input type="hidden" name="campaign_id" value="<?php echo (int)$personal_quran->id; ?>">
                    <input type="hidden" name="timezone" value="<?php echo esc_attr($personal_quran_reminder_state['timezone']); ?>" data-browser-timezone>
                    <input type="hidden" name="local_time" value="<?php echo esc_attr($personal_quran_reminder_state['local_time']); ?>">
                    <?php for ($hamnoor_day=0; $hamnoor_day<7; $hamnoor_day++) : if (Hamnoor_Reminder_Schedule_Service::day_enabled($hamnoor_reminder_mask, $hamnoor_day)) : ?><input type="hidden" name="days[]" value="<?php echo (int)$hamnoor_day; ?>"><?php endif; endfor; ?>
                    <label class="hamnoor-personal-reminder-switch"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($personal_quran_reminder_state['enabled'])); ?>><span><strong><?php esc_html_e('یادآوری فعال باشد', 'hamnoor'); ?></strong><small><?php esc_html_e('خاموش‌کردن یادآوری، ختم و پیشرفت شما را تغییر نمی‌دهد.', 'hamnoor'); ?></small></span></label>
                    <?php if ($hamnoor_next_reminder_label) : ?><p class="hamnoor-personal-reminder-next"><?php echo esc_html(sprintf(__('نوبت بعدی: %s', 'hamnoor'), $hamnoor_next_reminder_label)); ?></p><?php elseif (!empty($personal_quran_reminder_state['enabled']) && $hamnoor_reminder_paused) : ?><p class="hamnoor-personal-reminder-next"><?php esc_html_e('تا زمانی که ختم متوقف است، یادآوری ارسال نمی‌شود. با ازسرگیری ختم، زمان بعدی خودکار محاسبه می‌شود.', 'hamnoor'); ?></p><?php endif; ?>
                    <?php if ($hamnoor_snooze_label) : ?><p class="hamnoor-personal-reminder-snoozed"><?php echo esc_html(sprintf(__('تعویق فعال تا: %s', 'hamnoor'), $hamnoor_snooze_label)); ?></p><?php endif; ?>
                    <button type="submit" class="hamnoor-btn hamnoor-btn-primary"><?php esc_html_e('ذخیره یادآوری', 'hamnoor'); ?></button>
                </form>
                <p class="hamnoor-help"><?php esc_html_e('یادآوری ابتدا در مرکز اعلان‌های هم‌نور ثبت می‌شود و اگر کانال بیرونی فعالی داشته باشید، موتور اعلان همان مسیر معمول را برای ارسال انتخاب می‌کند.', 'hamnoor'); ?></p>
            </div>
            <?php else : ?>
            <div class="hamnoor-card hamnoor-personal-reminder-empty"><h3><?php esc_html_e('یادآوری ختم شخصی', 'hamnoor'); ?></h3><p><?php esc_html_e('پس از شروع یک ختم شخصی قرآن، می‌توانید ساعت و روزهای یادآوری آن را اینجا تنظیم کنید.', 'hamnoor'); ?></p></div>
            <?php endif; ?>
            <div class="hamnoor-card hamnoor-reminder-settings">
                <h3><?php esc_html_e('یادآوری‌های بیرون از سایت', 'hamnoor'); ?></h3>
                <?php $hamnoor_web_push_available = (bool)apply_filters('hamnoor_web_push_ui_available', false); ?>
                <p><?php echo esc_html($hamnoor_web_push_available
                    ? __('اعلان داخلی فقط وقتی دیده می‌شود که دوباره به هم‌نور برگردید. Web Push می‌تواند حتی زمانی که سایت باز نیست، یادآوری سهم ناتمام را در مرورگر نشان دهد.', 'hamnoor')
                    : __('اعلان داخلی هنگام بازگشت به هم‌نور دیده می‌شود و پیام‌رسان‌های متصل می‌توانند یادآوری‌های بیرون از سایت را دریافت کنند.', 'hamnoor'));
                ?></p>
                <?php if ($hamnoor_web_push_available) : ?>
                <div data-hamnoor-webpush class="hamnoor-webpush-card">
                    <div class="hamnoor-channel-row">
                        <div><strong><?php esc_html_e('Web Push', 'hamnoor'); ?></strong><small><?php esc_html_e('اولویت اول اعلان‌های بیرونی', 'hamnoor'); ?></small></div>
                        <span class="hamnoor-channel-status" data-webpush-status><?php esc_html_e('در حال بررسی...', 'hamnoor'); ?></span>
                    </div>
                    <div class="hamnoor-ajax-message" data-webpush-message aria-live="polite"></div>
                    <div class="hamnoor-profile-actions">
                        <button type="button" class="hamnoor-btn hamnoor-btn-primary" data-webpush-enable><?php esc_html_e('فعال‌سازی Web Push', 'hamnoor'); ?></button>
                        <button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-webpush-test hidden><?php esc_html_e('تست Web Push این مرورگر', 'hamnoor'); ?></button>
                        <button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-webpush-disable hidden><?php esc_html_e('غیرفعال‌کردن در این دستگاه', 'hamnoor'); ?></button>
                    </div>
                    <p class="hamnoor-help"><?php esc_html_e('فعال‌سازی فقط با انتخاب خود شما انجام می‌شود و مرورگر نیز یک‌بار مجوز نمایش اعلان می‌خواهد.', 'hamnoor'); ?></p>
                </div>
                <?php endif; ?>
                <?php
                $hamnoor_eitaa_data = apply_filters('hamnoor_eitaa_dashboard_data', array(
                    'available' => false,
                    'connected' => false,
                    'identity_connected' => false,
                    'notification_consent' => false,
                    'needs_reconnect' => false,
                    'phone_binding_valid' => false,
                    'phone_last4' => '',
                    'url' => '',
                ), (int)$user->id);
                $hamnoor_eitaa_data = is_array($hamnoor_eitaa_data) ? $hamnoor_eitaa_data : array();
                $hamnoor_eitaa_available = !empty($hamnoor_eitaa_data['available']);
                $hamnoor_eitaa_connected = !empty($hamnoor_eitaa_data['connected']);
                $hamnoor_eitaa_identity = !empty($hamnoor_eitaa_data['identity_connected']) || $hamnoor_eitaa_connected;
                $hamnoor_eitaa_consent = array_key_exists('notification_consent', $hamnoor_eitaa_data) ? !empty($hamnoor_eitaa_data['notification_consent']) : $hamnoor_eitaa_connected;
                $hamnoor_eitaa_reconnect = !empty($hamnoor_eitaa_data['needs_reconnect']);
                $hamnoor_eitaa_url = !empty($hamnoor_eitaa_data['url']) ? esc_url_raw($hamnoor_eitaa_data['url']) : '';
                ?>
                <?php if ($hamnoor_eitaa_available) : ?>
                <div class="hamnoor-webpush-card hamnoor-eitaa-channel-card">
                    <div class="hamnoor-channel-row">
                        <div><strong><?php esc_html_e('ایتا', 'hamnoor'); ?></strong><small><?php esc_html_e('اولویت دوم اعلان‌های بیرونی', 'hamnoor'); ?></small></div>
                        <span class="hamnoor-channel-status <?php echo $hamnoor_eitaa_consent ? 'is-active' : ''; ?>"><?php echo esc_html($hamnoor_eitaa_reconnect ? __('نیازمند اتصال مجدد', 'hamnoor') : ($hamnoor_eitaa_consent ? __('متصل و فعال', 'hamnoor') : ($hamnoor_eitaa_identity ? __('متصل؛ اعلان خاموش', 'hamnoor') : __('متصل نیست', 'hamnoor')))); ?></span>
                    </div>
                    <?php if ($hamnoor_eitaa_reconnect) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('اتصال ایتا برای این حساب نیاز به بازیابی فنی دارد. شماره فعلی هم‌نور و حساب ایتا مستقل‌اند؛ تغییر شماره هم‌نور به‌تنهایی اتصال ایتا را قطع نمی‌کند.', 'hamnoor'); ?></p>
                        <?php if ($hamnoor_eitaa_url) : ?><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($hamnoor_eitaa_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال مجدد ایتا', 'hamnoor'); ?></a><?php endif; ?>
                    <?php elseif ($hamnoor_eitaa_connected) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('این حساب ایتا به حساب هم‌نور شما متصل است و می‌تواند برای ورود و اعلان استفاده شود. شماره فعلی هم‌نور ممکن است بعداً با پیامک تغییر کند و لازم نیست با شماره ایتا یکسان بماند.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_unsubscribe'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_unsubscribe">
                            <input type="hidden" name="channel" value="eitaa">
                            <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('انصراف از اعلان ایتا', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_eitaa_identity && !$hamnoor_eitaa_consent) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('هویت ایتای شما همچنان به همین پنل متصل است؛ فقط رضایت دریافت اعلان خاموش شده است.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_reenable'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_reenable"><input type="hidden" name="channel" value="eitaa">
                            <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('فعال‌سازی دوباره اعلان ایتا', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_eitaa_url) : ?>
                        <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($hamnoor_eitaa_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال ایتا', 'hamnoor'); ?></a>
                        <p class="hamnoor-help"><?php esc_html_e('اتصال اولیه داخل برنامک ایتا انجام می‌شود؛ هم‌نور با SDK رسمی ایتا هویت، اجازه ارسال پیام و شماره موبایل شما را به‌صورت امن تأیید می‌کند و نیازی به کد خصوصی یا OTP جداگانه نیست.', 'hamnoor'); ?></p>
                    <?php else : ?>
                        <p class="hamnoor-help"><?php esc_html_e('آدرس برنامک ایتا هنوز توسط مدیر سایت تنظیم نشده است.', 'hamnoor'); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php
                $hamnoor_bale_data = apply_filters('hamnoor_bale_dashboard_data', array(
                    'available' => false,
                    'connected' => false,
                    'identity_connected' => false,
                    'notification_consent' => false,
                    'needs_reconnect' => false,
                    'phone_binding_valid' => false,
                    'phone_last4' => '',
                    'url' => '',
                ), (int)$user->id);
                $hamnoor_bale_data = is_array($hamnoor_bale_data) ? $hamnoor_bale_data : array();
                $hamnoor_bale_available = !empty($hamnoor_bale_data['available']);
                $hamnoor_bale_connected = !empty($hamnoor_bale_data['connected']);
                $hamnoor_bale_identity = !empty($hamnoor_bale_data['identity_connected']) || $hamnoor_bale_connected;
                $hamnoor_bale_consent = array_key_exists('notification_consent', $hamnoor_bale_data) ? !empty($hamnoor_bale_data['notification_consent']) : $hamnoor_bale_connected;
                $hamnoor_bale_reconnect = !empty($hamnoor_bale_data['needs_reconnect']);
                $hamnoor_bale_url = !empty($hamnoor_bale_data['url']) ? esc_url_raw($hamnoor_bale_data['url']) : '';
                ?>
                <?php if ($hamnoor_bale_available) : ?>
                <div class="hamnoor-webpush-card hamnoor-bale-channel-card">
                    <div class="hamnoor-channel-row">
                        <div><strong><?php esc_html_e('بله', 'hamnoor'); ?></strong><small><?php esc_html_e('اولویت سوم اعلان‌های بیرونی', 'hamnoor'); ?></small></div>
                        <span class="hamnoor-channel-status <?php echo $hamnoor_bale_consent ? 'is-active' : ''; ?>"><?php echo esc_html($hamnoor_bale_reconnect ? __('نیازمند اتصال مجدد', 'hamnoor') : ($hamnoor_bale_consent ? __('متصل و فعال', 'hamnoor') : ($hamnoor_bale_identity ? __('متصل؛ اعلان خاموش', 'hamnoor') : __('متصل نیست', 'hamnoor')))); ?></span>
                    </div>
                    <?php if ($hamnoor_bale_reconnect) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('اتصال بله برای این حساب نیاز به بازیابی فنی دارد. شماره فعلی هم‌نور و حساب بله مستقل‌اند؛ تغییر شماره هم‌نور به‌تنهایی اتصال بله را قطع نمی‌کند.', 'hamnoor'); ?></p>
                        <?php if ($hamnoor_bale_url) : ?><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($hamnoor_bale_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال مجدد بله', 'hamnoor'); ?></a><?php endif; ?>
                    <?php elseif ($hamnoor_bale_connected) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('این حساب بله به حساب هم‌نور شما متصل است و می‌تواند برای ورود و اعلان استفاده شود. شماره فعلی هم‌نور ممکن است بعداً با پیامک تغییر کند و لازم نیست با شماره بله یکسان بماند.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_unsubscribe'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_unsubscribe">
                            <input type="hidden" name="channel" value="bale">
                            <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('انصراف از اعلان بله', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_bale_identity && !$hamnoor_bale_consent) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('هویت بله و شماره تأییدشده قبلی شما حفظ شده است؛ فقط اعلان بله خاموش است.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_reenable'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_reenable"><input type="hidden" name="channel" value="bale">
                            <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('فعال‌سازی دوباره اعلان بله', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_bale_url) : ?>
                        <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($hamnoor_bale_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال بله', 'hamnoor'); ?></a>
                        <p class="hamnoor-help"><?php esc_html_e('بازوی بله را باز کنید، «شروع» را بزنید و سپس فقط دکمه «ارسال شماره موبایل من» را انتخاب کنید. شماره از خود بله دریافت و با پنل هم‌نور تطبیق داده می‌شود؛ پیامک لازم نیست.', 'hamnoor'); ?></p>
                    <?php else : ?>
                        <p class="hamnoor-help"><?php esc_html_e('بازوی بله هنوز توسط مدیر سایت پیکربندی نشده است.', 'hamnoor'); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php
                $hamnoor_rubika_data = apply_filters('hamnoor_rubika_dashboard_data', array(
                    'available' => false,
                    'connected' => false,
                    'identity_connected' => false,
                    'notification_consent' => false,
                    'needs_reconnect' => false,
                    'phone_binding_valid' => false,
                    'phone_last4' => '',
                    'url' => '',
                ), (int)$user->id);
                $hamnoor_rubika_data = is_array($hamnoor_rubika_data) ? $hamnoor_rubika_data : array();
                $hamnoor_rubika_available = !empty($hamnoor_rubika_data['available']);
                $hamnoor_rubika_connected = !empty($hamnoor_rubika_data['connected']);
                $hamnoor_rubika_identity = !empty($hamnoor_rubika_data['identity_connected']) || $hamnoor_rubika_connected;
                $hamnoor_rubika_consent = array_key_exists('notification_consent', $hamnoor_rubika_data) ? !empty($hamnoor_rubika_data['notification_consent']) : $hamnoor_rubika_connected;
                $hamnoor_rubika_reconnect = !empty($hamnoor_rubika_data['needs_reconnect']);
                $hamnoor_rubika_url = !empty($hamnoor_rubika_data['url']) ? esc_url_raw($hamnoor_rubika_data['url']) : '';
                ?>
                <?php if ($hamnoor_rubika_available) : ?>
                <div class="hamnoor-webpush-card hamnoor-rubika-channel-card">
                    <div class="hamnoor-channel-row">
                        <div><strong><?php esc_html_e('روبیکا', 'hamnoor'); ?></strong><small><?php esc_html_e('اولویت چهارم اعلان‌های بیرونی', 'hamnoor'); ?></small></div>
                        <span class="hamnoor-channel-status <?php echo $hamnoor_rubika_consent ? 'is-active' : ''; ?>"><?php echo esc_html($hamnoor_rubika_reconnect ? __('نیازمند اتصال مجدد', 'hamnoor') : ($hamnoor_rubika_consent ? __('متصل و فعال', 'hamnoor') : ($hamnoor_rubika_identity ? __('متصل؛ اعلان خاموش', 'hamnoor') : __('متصل نیست', 'hamnoor')))); ?></span>
                    </div>
                    <?php if ($hamnoor_rubika_reconnect) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('اتصال روبیکا برای این حساب نیاز به بازیابی فنی دارد. شماره فعلی هم‌نور و حساب روبیکا مستقل‌اند؛ تغییر شماره هم‌نور به‌تنهایی اتصال روبیکا را قطع نمی‌کند.', 'hamnoor'); ?></p>
                        <?php if ($hamnoor_rubika_url) : ?><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($hamnoor_rubika_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال مجدد روبیکا', 'hamnoor'); ?></a><?php endif; ?>
                    <?php elseif ($hamnoor_rubika_connected) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('این حساب روبیکا به حساب هم‌نور شما متصل است و می‌تواند برای ورود و اعلان استفاده شود. شماره فعلی هم‌نور ممکن است بعداً با پیامک تغییر کند و لازم نیست با شماره روبیکا یکسان بماند.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_unsubscribe'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_unsubscribe">
                            <input type="hidden" name="channel" value="rubika">
                            <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('انصراف از اعلان روبیکا', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_rubika_identity && !$hamnoor_rubika_consent) : ?>
                        <p class="hamnoor-help"><?php esc_html_e('هویت روبیکای شما همچنان به همین پنل متصل است؛ فقط اعلان روبیکا خاموش شده است.', 'hamnoor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form">
                            <?php wp_nonce_field('hamnoor_notification_reenable'); ?>
                            <input type="hidden" name="action" value="hamnoor_notification_reenable"><input type="hidden" name="channel" value="rubika">
                            <button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('فعال‌سازی دوباره اعلان روبیکا', 'hamnoor'); ?></button>
                        </form>
                    <?php elseif ($hamnoor_rubika_url) : ?>
                        <a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url($hamnoor_rubika_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('اتصال روبیکا', 'hamnoor'); ?></a>
                        <p class="hamnoor-help"><?php esc_html_e('بات روبیکا را باز کنید و شروع کنید؛ سپس فقط دکمه «ارسال شماره موبایل من» را بزنید. شماره از قابلیت رسمی روبیکا دریافت و با همان پنل هم‌نور تطبیق داده می‌شود؛ پیامک لازم نیست.', 'hamnoor'); ?></p>
                    <?php else : ?>
                        <p class="hamnoor-help"><?php esc_html_e('بات روبیکا هنوز توسط مدیر سایت پیکربندی نشده است.', 'hamnoor'); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="hamnoor-webpush-card hamnoor-all-notifications-card">
                    <div class="hamnoor-channel-row">
                        <div><strong><?php esc_html_e('همه اعلان‌های بیرونی', 'hamnoor'); ?></strong><small><?php esc_html_e('Web Push و تمام پیام‌رسان‌های متصل', 'hamnoor'); ?></small></div>
                    </div>
                    <p class="hamnoor-help"><?php esc_html_e('با این انتخاب، همه ارسال‌های بیرونی و پیام‌های در صف متوقف می‌شوند؛ حساب، تأیید شماره، سهم‌ها و سوابق شما حذف نمی‌شوند.', 'hamnoor'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form" onsubmit="return window.confirm('<?php echo esc_js(__('همه اعلان‌های بیرونی متوقف شوند؟', 'hamnoor')); ?>');">
                        <?php wp_nonce_field('hamnoor_notification_unsubscribe'); ?>
                        <input type="hidden" name="action" value="hamnoor_notification_unsubscribe">
                        <input type="hidden" name="channel" value="all">
                        <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('انصراف از همه اعلان‌های بیرونی', 'hamnoor'); ?></button>
                    </form>
                </div>
            </div>
            <details class="hamnoor-card hamnoor-channel-priority-card hamnoor-advanced-notification-details">
                <summary><?php esc_html_e('جزئیات روش‌های دریافت اعلان', 'hamnoor'); ?></summary>
                <h3><?php esc_html_e('اولویت کانال‌های هم‌نور', 'hamnoor'); ?></h3>
                <ol class="hamnoor-channel-priority">
                    <li><strong><?php esc_html_e('Web Push', 'hamnoor'); ?></strong><span><?php echo esc_html($hamnoor_web_push_available ? __('افزونه مستقل فعال', 'hamnoor') : __('افزونه مستقل نصب نیست', 'hamnoor')); ?></span></li>
                    <li><strong><?php esc_html_e('ایتا', 'hamnoor'); ?></strong><span><?php esc_html_e('اتصال خصوصی فعال در این نسخه', 'hamnoor'); ?></span></li>
                    <li><strong><?php esc_html_e('بله', 'hamnoor'); ?></strong><span><?php esc_html_e('اتصال خصوصی بازو فعال در این نسخه', 'hamnoor'); ?></span></li>
                    <li><strong><?php esc_html_e('روبیکا', 'hamnoor'); ?></strong><span><?php esc_html_e('اتصال Bot API رسمی فعال در این نسخه', 'hamnoor'); ?></span></li>
                </ol>
                <p class="hamnoor-help"><?php esc_html_e('Web Push اعلان مکمل است. پس از آن، اولین پیام‌رسان ماندگارِ موفق انتخاب می‌شود تا پیام هم ماندگار باشد و هم در چند پیام‌رسان تکرار نشود.', 'hamnoor'); ?></p>
            </details>
        </section>
        <section id="hamnoor-panel-profile" class="hamnoor-dashboard-panel <?php echo $active_tab === 'profile' ? 'is-active' : ''; ?>" data-tab-panel="profile" role="tabpanel" aria-labelledby="hamnoor-tab-profile" <?php echo $active_tab === 'profile' ? '' : 'hidden'; ?>>
            <div class="hamnoor-card">
                <h3><?php esc_html_e('ویرایش مشخصات', 'hamnoor'); ?></h3>
                <?php $hamnoor_profile_sensitive = Hamnoor_Mobile_Access::has_sensitive_access((int)$user->id); ?>
                <?php if (!$hamnoor_profile_sensitive) : ?>
                    <?php echo Hamnoor_Mobile_Access::verification_box('required', add_query_arg('tab', 'profile', Hamnoor_Helpers::page_url('dashboard'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
                <div class="hamnoor-ajax-message" aria-live="polite"></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-profile-form" data-hamnoor-profile="1">
                    <?php wp_nonce_field('hamnoor_update_profile'); ?>
                    <input type="hidden" name="action" value="hamnoor_update_profile">
                    <label><?php esc_html_e('نام نمایشی', 'hamnoor'); ?><input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>"></label>
                    <label><?php esc_html_e('شماره موبایل پنل', 'hamnoor'); ?><input type="tel" value="<?php echo esc_attr($user->phone); ?>" readonly aria-readonly="true"></label>
                    <div class="hamnoor-profile-actions">
                        <button class="hamnoor-btn hamnoor-btn-primary" type="submit" <?php echo $hamnoor_profile_sensitive ? '' : 'disabled aria-disabled="true"'; ?>><?php esc_html_e('ذخیره نام', 'hamnoor'); ?></button>
                        <button class="hamnoor-btn hamnoor-btn-secondary" type="button" data-hamnoor-open-verify="#hamnoor-change-mobile-modal"><?php esc_html_e('تغییر شماره موبایل', 'hamnoor'); ?></button>
                    </div>
                    <p class="hamnoor-help"><?php esc_html_e('شماره موبایل در فرم‌های رزرو سهم قابل تغییر نیست. برای تغییر آن باید شماره جدید را با کد پیامکی تأیید کنید.', 'hamnoor'); ?></p>
                </form>
                <?php echo Hamnoor_Mobile_Access::change_mobile_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <details class="hamnoor-card hamnoor-session-management">
                <summary><?php esc_html_e('امنیت و دستگاه‌های دیگر', 'hamnoor'); ?></summary>
                <p class="hamnoor-help"><?php esc_html_e('در صورت نیاز می‌توانید دسترسی هم‌نور را در دستگاه‌های دیگر ببندید. اطلاعات فنی دستگاه یا نشانی IP ذخیره نمی‌شود.', 'hamnoor'); ?></p>
                <?php $hamnoor_active_sessions = Hamnoor_Mobile_Access::active_sessions((int)$user->id); ?>
                <?php if ($hamnoor_active_sessions) : ?><div class="hamnoor-session-list">
                    <?php foreach ($hamnoor_active_sessions as $hamnoor_session) :
                        $hamnoor_last_seen_ts = !empty($hamnoor_session->last_seen_at) ? strtotime((string)$hamnoor_session->last_seen_at . ' UTC') : 0;
                        $hamnoor_last_seen = $hamnoor_last_seen_ts ? sprintf(__('%s پیش', 'hamnoor'), human_time_diff($hamnoor_last_seen_ts, time())) : __('نامشخص', 'hamnoor');
                    ?>
                        <div class="hamnoor-session-row">
                            <div><strong><?php echo !empty($hamnoor_session->is_current) ? esc_html__('این نشست', 'hamnoor') : esc_html__('نشست دیگر', 'hamnoor'); ?></strong><small><?php echo esc_html(sprintf(__('آخرین فعالیت: %s', 'hamnoor'), $hamnoor_last_seen)); ?></small></div>
                            <?php if (empty($hamnoor_session->is_current)) : ?>
                                <?php if ($hamnoor_profile_sensitive) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-inline-form" onsubmit="return window.confirm('<?php echo esc_js(__('این نشست از دستگاه دیگر بسته شود؟', 'hamnoor')); ?>');">
                                    <input type="hidden" name="action" value="hamnoor_revoke_session"><input type="hidden" name="session_id" value="<?php echo (int)$hamnoor_session->id; ?>">
                                    <?php wp_nonce_field('hamnoor_revoke_session_' . (int)$hamnoor_session->id); ?>
                                    <button class="hamnoor-btn hamnoor-btn-secondary" type="submit"><?php esc_html_e('بستن نشست', 'hamnoor'); ?></button>
                                </form>
                                <?php else : ?><small><?php esc_html_e('برای بستن، ابتدا هویت این نشست را تأیید کنید.', 'hamnoor'); ?></small><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </details>
        </section>
        <section id="hamnoor-panel-help" class="hamnoor-dashboard-panel <?php echo $active_tab === 'help' ? 'is-active' : ''; ?>" data-tab-panel="help" role="tabpanel" aria-labelledby="hamnoor-tab-help" <?php echo $active_tab === 'help' ? '' : 'hidden'; ?>><div class="hamnoor-card"><h3><?php esc_html_e('راهنمای کوتاه هم‌نور', 'hamnoor'); ?></h3><dl class="hamnoor-help-list"><dt><?php esc_html_e('پویش چیست؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('پویش گروهی برای مشارکت چند نفر است و با ختم شخصی شما تفاوت دارد.', 'hamnoor'); ?></dd><dt><?php esc_html_e('ختم شخصی چیست؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('یک‌بار کل قرآن را خودتان از ابتدا تا انتها می‌خوانید؛ سهم، تقسیم‌بندی و مشارکت‌کننده دیگری وجود ندارد.', 'hamnoor'); ?></dd><dt><?php esc_html_e('چگونه پویش ایجاد کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('دکمه «ایجاد پویش جدید» در بالای داشبورد را بزنید تا فرم کامل در صفحه‌ای مستقل باز شود.', 'hamnoor'); ?></dd><dt><?php esc_html_e('چگونه سهم انتخاب کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('در صفحه پویش می‌توانید سهم بعدی آزاد یا یک سهم تصادفی را انتخاب کنید.', 'hamnoor'); ?></dd><dt><?php esc_html_e('بعداً چگونه ثبت قرائت کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('در تب «سهم‌های من»، دکمه «شروع/ادامه قرائت» را بزنید. آخرین آیه خوانده‌شده ذخیره می‌شود و می‌توانید بعداً از همان‌جا ادامه دهید.', 'hamnoor'); ?></dd></dl></div></section>
        <?php do_action('hamnoor_dashboard_extension_panels', $user, $active_tab); ?>
    </div>

    <nav class="hamnoor-mobile-ui hamnoor-mobile-bottom-nav" aria-label="<?php esc_attr_e('ناوبری اصلی هم‌نور', 'hamnoor'); ?>">
        <button type="button" data-tab-target="summary" aria-label="<?php esc_attr_e('خانه', 'hamnoor'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/></svg><span><?php esc_html_e('خانه', 'hamnoor'); ?></span></button>
        <a href="<?php echo esc_url($list_url); ?>" aria-label="<?php esc_attr_e('پویش‌های عمومی', 'hamnoor'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span><?php esc_html_e('پویش‌ها', 'hamnoor'); ?></span></a>
        <button type="button" data-tab-target="my-parts" aria-label="<?php esc_attr_e('سهم‌های من', 'hamnoor'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg><span><?php esc_html_e('سهم‌های من', 'hamnoor'); ?></span></button>
        <button type="button" data-tab-target="notifications" aria-label="<?php esc_attr_e('اعلان‌ها', 'hamnoor'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span><?php esc_html_e('اعلان‌ها', 'hamnoor'); ?></span><?php if ($unread_notifications) : ?><i class="hamnoor-mobile-badge hamnoor-notification-badge"><?php echo (int)$unread_notifications; ?></i><?php endif; ?></button>
        <button type="button" data-hamnoor-mobile-more-toggle aria-expanded="false" aria-label="<?php esc_attr_e('بیشتر', 'hamnoor'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span><?php esc_html_e('بیشتر', 'hamnoor'); ?></span></button>
    </nav>

    <div class="hamnoor-mobile-ui hamnoor-mobile-more-layer" data-hamnoor-mobile-more hidden>
        <button type="button" class="hamnoor-mobile-more-backdrop" data-hamnoor-mobile-more-close aria-label="<?php esc_attr_e('بستن منو', 'hamnoor'); ?>"></button>
        <section class="hamnoor-mobile-more-sheet" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('گزینه‌های بیشتر', 'hamnoor'); ?>">
            <div class="hamnoor-mobile-sheet-handle" aria-hidden="true"></div>
            <div class="hamnoor-mobile-sheet-head"><strong><?php esc_html_e('بیشتر', 'hamnoor'); ?></strong><button type="button" data-hamnoor-mobile-more-close aria-label="<?php esc_attr_e('بستن', 'hamnoor'); ?>">×</button></div>
            <div class="hamnoor-mobile-more-sections">
                <section class="hamnoor-mobile-more-section" aria-labelledby="hamnoor-more-campaigns">
                    <strong id="hamnoor-more-campaigns" class="hamnoor-mobile-more-section-title"><?php esc_html_e('پویش‌های من', 'hamnoor'); ?></strong>
                    <div class="hamnoor-mobile-more-grid">
                        <button type="button" data-tab-target="my-khatms"><span>◇</span><?php esc_html_e('مدیریت پویش‌ها', 'hamnoor'); ?></button>
                        <a href="<?php echo esc_url($create_url); ?>"><span>＋</span><?php esc_html_e('ایجاد پویش', 'hamnoor'); ?></a>
                        <a href="<?php echo esc_url(add_query_arg('tab','progress',$dashboard_url)); ?>"><span>↗</span><?php esc_html_e('پیشرفت پویش‌ها', 'hamnoor'); ?></a>
                    </div>
                </section>
                <?php do_action('hamnoor_mobile_more_extension', $user, $active_tab); ?>
                <section class="hamnoor-mobile-more-section" aria-labelledby="hamnoor-more-account">
                    <strong id="hamnoor-more-account" class="hamnoor-mobile-more-section-title"><?php esc_html_e('حساب و تنظیمات', 'hamnoor'); ?></strong>
                    <div class="hamnoor-mobile-more-grid">
                        <button type="button" data-tab-target="reminders"><span>◉</span><?php esc_html_e('یادآوری‌ها', 'hamnoor'); ?></button>
                        <button type="button" data-tab-target="profile"><span>♙</span><?php esc_html_e('مشخصات من', 'hamnoor'); ?></button>
                        <button type="button" data-tab-target="help"><span>؟</span><?php esc_html_e('راهنما', 'hamnoor'); ?></button>
                    </div>
                </section>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-mobile-logout"><?php wp_nonce_field('hamnoor_mobile_logout'); ?><input type="hidden" name="action" value="hamnoor_mobile_logout"><button type="submit"><?php esc_html_e('خروج از پنل', 'hamnoor'); ?></button></form>
        </section>
    </div>
</section>
