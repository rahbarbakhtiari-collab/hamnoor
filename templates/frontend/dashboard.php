<?php
if (!defined('ABSPATH')) { exit; }
$user = isset($user) && $user instanceof WP_User ? $user : wp_get_current_user();
$owned = isset($owned) && is_array($owned) ? $owned : array();
$parts = isset($parts) && is_array($parts) ? $parts : array();
$active_tab = isset($active_tab) ? (string) $active_tab : 'summary';
$owned_total = isset($owned_total) ? (int) $owned_total : count($owned);
$parts_total = isset($parts_total) ? (int) $parts_total : count($parts);
$open_parts = isset($open_parts) ? (int) $open_parts : 0;
$completed_archive = isset($completed_archive) && is_array($completed_archive) ? $completed_archive : array();
$participants_khatm = isset($participants_khatm) ? $participants_khatm : null;
$participants_rows = isset($participants_rows) && is_array($participants_rows) ? $participants_rows : array();
$participants_error = isset($participants_error) ? (string) $participants_error : '';
$create_html = isset($create_html) ? $create_html : '';
$track_html = isset($track_html) ? $track_html : '';
$create_url = Hamnoor_Helpers::page_url('create');
$track_url = Hamnoor_Helpers::page_url('track');
$tabs = array(
    'summary' => __('خلاصه', 'hamnoor'),
    'my-khatms' => __('ختم‌های من', 'hamnoor'),
    'my-parts' => __('مشارکت‌های من', 'hamnoor'),
    'profile' => __('ویرایش مشخصات', 'hamnoor'),
    'help' => __('راهنما', 'hamnoor'),
);
?>
<section class="hamnoor-dashboard" data-active-tab="<?php echo esc_attr($active_tab); ?>" data-hamnoor-dashboard="1">
    <div class="hamnoor-dashboard-head">
        <div><h2><?php echo esc_html(sprintf(__('سلام، %s', 'hamnoor'), $user->display_name ? $user->display_name : $user->user_login)); ?></h2><p><?php esc_html_e('اینجا مرکز مدیریت ختم‌ها، سهم‌ها و مشخصات شماست.', 'hamnoor'); ?></p></div>
        <div class="hamnoor-dashboard-actions"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد ختم جدید', 'hamnoor'); ?></a><a class="hamnoor-btn hamnoor-btn-secondary" href="<?php echo esc_url(wp_logout_url(Hamnoor_Helpers::page_url('login'))); ?>"><?php esc_html_e('خروج از حساب', 'hamnoor'); ?></a></div>
    </div>
    <nav class="hamnoor-dashboard-nav hamnoor-dashboard-tabs" aria-label="<?php esc_attr_e('تب‌های داشبورد', 'hamnoor'); ?>" role="tablist">
        <?php foreach ($tabs as $key => $label) : ?>
            <button type="button" class="<?php echo esc_attr($active_tab === $key ? 'is-active' : ''); ?>" data-tab-target="<?php echo esc_attr($key); ?>" role="tab" aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </nav>
    <div class="hamnoor-dashboard-panels">
        <section class="hamnoor-dashboard-panel <?php echo $active_tab === 'summary' ? 'is-active' : ''; ?>" data-tab-panel="summary" <?php echo $active_tab === 'summary' ? '' : 'hidden'; ?>>
            <div class="hamnoor-stat-grid">
                <div class="hamnoor-stat-card"><strong><?php echo (int) $owned_total; ?></strong><span><?php esc_html_e('ختم ایجادشده', 'hamnoor'); ?></span></div>
                <div class="hamnoor-stat-card"><strong><?php echo (int) $parts_total; ?></strong><span><?php esc_html_e('سهم مشارکت', 'hamnoor'); ?></span></div>
                <div class="hamnoor-stat-card"><strong><?php echo (int) $open_parts; ?></strong><span><?php esc_html_e('سهم خوانده‌نشده', 'hamnoor'); ?></span></div>
            </div>
            <div class="hamnoor-action-buttons"><a class="hamnoor-btn hamnoor-btn-primary" href="<?php echo esc_url($create_url); ?>"><?php esc_html_e('ایجاد ختم جدید', 'hamnoor'); ?></a></div>
        </section>
        <section class="hamnoor-dashboard-panel <?php echo $active_tab === 'my-khatms' ? 'is-active' : ''; ?>" data-tab-panel="my-khatms" <?php echo $active_tab === 'my-khatms' ? '' : 'hidden'; ?>>
            <h3><?php esc_html_e('ختم‌های ایجادشده توسط من', 'hamnoor'); ?></h3>
            <?php if ($owned) : ?><div class="hamnoor-dashboard-list">
                <?php foreach ($owned as $khatm) : $progress = Hamnoor_DB::get_progress((int) $khatm->id); ?>
                    <div class="hamnoor-dashboard-khatm-item">
                    <article class="hamnoor-row-card <?php echo esc_attr(Hamnoor_Helpers::design_class($khatm)); ?>"><div class="hamnoor-row-card-body"><strong><a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php echo esc_html($khatm->title); ?></a></strong><span class="hamnoor-status hamnoor-status-<?php echo esc_attr($khatm->status); ?>"><?php echo esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')); ?></span><small><?php echo esc_html(sprintf(__('%1$d از %2$d سهم خوانده شده است', 'hamnoor'), (int)$progress['done'], (int)$progress['total'])); ?></small><code><?php echo esc_html(Hamnoor_Helpers::invite_url($khatm)); ?></code></div><details class="hamnoor-card-actions"><summary><?php esc_html_e('عملیات', 'hamnoor'); ?></summary><a href="<?php echo esc_url(Hamnoor_Helpers::invite_url($khatm)); ?>"><?php esc_html_e('مشاهده', 'hamnoor'); ?></a><a href="<?php echo esc_url(add_query_arg('edit_khatm', (int) $khatm->id, $create_url)); ?>"><?php esc_html_e('ویرایش', 'hamnoor'); ?></a><a href="<?php echo esc_url(add_query_arg('participants_khatm', (int) $khatm->id, Hamnoor_Helpers::page_url('dashboard'))); ?>"><?php esc_html_e('شرکت‌کنندگان', 'hamnoor'); ?></a><?php if (Hamnoor_Export::can_export((int) $khatm->id, get_current_user_id())) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_xlsx&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)); ?>">Excel</a><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_csv&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)); ?>">CSV</a><?php endif; ?></details></article>
                    <?php if ('pending_review' === (string) $khatm->status) : ?>
                        <div class="hamnoor-notice hamnoor-notice-warning"><?php esc_html_e('این ختم در انتظار بررسی و انتشار مدیر سایت است.', 'hamnoor'); ?></div>
                    <?php elseif (!empty($khatm->published_at)) : ?>
                        <div class="hamnoor-notice hamnoor-notice-success"><?php esc_html_e('ختم شما توسط مدیر سایت منتشر شده است و لینک دعوت اکنون فعال است.', 'hamnoor'); ?></div>
                    <?php endif; ?>
                    <?php
                    $feature_request_status = Hamnoor_Featured_Service::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
                    $feature_badge = Hamnoor_Featured_Service::badge_data($khatm);
                    $can_request_feature = Hamnoor_Featured_Service::can_request($khatm, get_current_user_id());
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
                    </div>
                <?php endforeach; ?></div><?php else : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز ختمی ایجاد نکرده‌اید.', 'hamnoor'); ?></p><?php endif; ?>
            <?php if ($participants_error || $participants_khatm) : ?>
                <div class="hamnoor-card hamnoor-dashboard-participants">
                    <h4><?php esc_html_e('شرکت‌کنندگان ختم', 'hamnoor'); ?><?php if ($participants_khatm) : ?>: <?php echo esc_html($participants_khatm->title); ?><?php endif; ?></h4>
                    <?php if ($participants_error) : ?>
                        <p class="hamnoor-empty"><?php echo esc_html($participants_error); ?></p>
                    <?php elseif (!$participants_rows) : ?>
                        <p class="hamnoor-empty"><?php esc_html_e('هنوز شرکت‌کننده‌ای برای این ختم ثبت نشده است.', 'hamnoor'); ?></p>
                    <?php else : ?>
                        <div class="hamnoor-mobile-cards">
                            <?php foreach ($participants_rows as $row) : ?>
                                <div class="hamnoor-row-card"><strong><?php echo esc_html($row->name); ?></strong><span><?php echo esc_html(trim($row->phone . ' ' . $row->email)); ?></span><small><?php echo esc_html(sprintf(__('سهم‌ها: %d / خوانده‌شده: %d', 'hamnoor'), (int) $row->total_parts, (int) $row->done_parts)); ?></small></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="hamnoor-dashboard-panel <?php echo $active_tab === 'my-parts' ? 'is-active' : ''; ?>" data-tab-panel="my-parts" <?php echo $active_tab === 'my-parts' ? '' : 'hidden'; ?>>
            <h3><?php esc_html_e('مشارکت‌های من', 'hamnoor'); ?></h3>
            <?php if ($open_parts) : ?><p class="hamnoor-help hamnoor-once-help"><?php echo esc_html(Hamnoor_Texts::get('dashboard_help')); ?></p><?php endif; ?>
            <?php if ($parts) : ?><div class="hamnoor-parts-grid hamnoor-my-parts-grid"><?php foreach ($parts as $part) : $khatm = Hamnoor_DB::get_khatm($part->khatm_token); if (!$khatm) { continue; } $context = 'dashboard'; include Hamnoor_Helpers::locate_template('frontend/part-card.php'); endforeach; ?></div><?php else : ?><p class="hamnoor-empty"><?php esc_html_e('هنوز سهمی برای شما ثبت نشده است.', 'hamnoor'); ?></p><?php endif; ?>
            <?php if ($completed_archive) : ?>
                <div class="hamnoor-completed-archive">
                    <h4><?php esc_html_e('آرشیو ختم‌های تکمیل‌شده', 'hamnoor'); ?></h4>
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
        </section>
        <section class="hamnoor-dashboard-panel <?php echo $active_tab === 'profile' ? 'is-active' : ''; ?>" data-tab-panel="profile" <?php echo $active_tab === 'profile' ? '' : 'hidden'; ?>>
            <div class="hamnoor-card"><h3><?php esc_html_e('ویرایش مشخصات', 'hamnoor'); ?></h3><div class="hamnoor-ajax-message" aria-live="polite"></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hamnoor-form hamnoor-profile-form" data-hamnoor-profile="1"><?php wp_nonce_field('hamnoor_update_profile'); ?><input type="hidden" name="action" value="hamnoor_update_profile"><label><?php esc_html_e('نام نمایشی', 'hamnoor'); ?><input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>"></label><label><?php esc_html_e('موبایل', 'hamnoor'); ?><input type="tel" name="phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'hamnoor_phone', true)); ?>" placeholder="09123456789"></label><label><?php esc_html_e('ایمیل', 'hamnoor'); ?><input type="email" name="email" value="<?php echo esc_attr($user->user_email); ?>"></label><label><?php esc_html_e('رمز عبور جدید، اختیاری', 'hamnoor'); ?><input type="password" name="password" minlength="8"></label><button class="hamnoor-btn hamnoor-btn-primary" type="submit"><?php esc_html_e('ذخیره مشخصات', 'hamnoor'); ?></button></form></div>
        </section>
        <section class="hamnoor-dashboard-panel <?php echo $active_tab === 'help' ? 'is-active' : ''; ?>" data-tab-panel="help" <?php echo $active_tab === 'help' ? '' : 'hidden'; ?>><div class="hamnoor-card"><h3><?php esc_html_e('راهنمای کوتاه هم‌نور', 'hamnoor'); ?></h3><dl class="hamnoor-help-list"><dt><?php esc_html_e('ختم چیست؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('ختم گروهی یعنی قرآن یا یک سوره بین چند نفر تقسیم می‌شود تا هر نفر سهمی را بخواند.', 'hamnoor'); ?></dd><dt><?php esc_html_e('چگونه ختم ایجاد کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('از صفحه ایجاد ختم جدید، مرحله‌ها را کامل کنید و در پایان لینک دعوت بگیرید.', 'hamnoor'); ?></dd><dt><?php esc_html_e('چگونه سهم انتخاب کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('در صفحه ختم می‌توانید سهم بعدی آزاد یا یک سهم تصادفی را انتخاب کنید.', 'hamnoor'); ?></dd><dt><?php esc_html_e('بعداً چگونه ثبت قرائت کنم؟', 'hamnoor'); ?></dt><dd><?php esc_html_e('از صفحه پیگیری سهم‌ها، موبایل یا ایمیل خود را وارد کنید و روی قرائت انجام شد بزنید.', 'hamnoor'); ?></dd></dl></div></section>
    </div>
</section>
