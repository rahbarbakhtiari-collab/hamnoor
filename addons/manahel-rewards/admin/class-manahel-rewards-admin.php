<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Rewards_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_post_manahel_rewards_save_rule', array(__CLASS__, 'handle_save_rule'));
        add_action('admin_post_manahel_rewards_update_rule', array(__CLASS__, 'handle_update_rule'));
        add_action('admin_post_manahel_rewards_toggle_rule', array(__CLASS__, 'handle_toggle_rule'));
        add_action('admin_post_manahel_rewards_save_policy_timezone', array(__CLASS__, 'handle_save_policy_timezone'));
        add_action('admin_post_manahel_rewards_save_display_page', array(__CLASS__, 'handle_save_display_page'));
        add_action('admin_post_manahel_rewards_manual_adjustment', array(__CLASS__, 'handle_manual_adjustment'));
        add_action('admin_post_manahel_rewards_retry_event', array(__CLASS__, 'handle_retry_event'));
        add_action('admin_post_manahel_rewards_rebuild_balance', array(__CLASS__, 'handle_rebuild_balance'));
        add_action('admin_post_manahel_rewards_save_reward', array(__CLASS__, 'handle_save_reward'));
        add_action('admin_post_manahel_rewards_update_reward', array(__CLASS__, 'handle_update_reward'));
        add_action('admin_post_manahel_rewards_fulfill_redemption', array(__CLASS__, 'handle_fulfill_redemption'));
        add_action('admin_post_manahel_rewards_refund_redemption', array(__CLASS__, 'handle_refund_redemption'));
    }

    public static function menu() {
        add_menu_page(__('امتیاز مناهل', 'manahel-rewards'), __('امتیاز مناهل', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards', array(__CLASS__, 'render_overview'), 'dashicons-awards', 58);
        add_submenu_page('manahel-rewards', __('نمای کلی', 'manahel-rewards'), __('نمای کلی', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards', array(__CLASS__, 'render_overview'));
        add_submenu_page('manahel-rewards', __('امتیاز فعالیت‌ها', 'manahel-rewards'), __('امتیاز فعالیت‌ها', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-rules', array(__CLASS__, 'render_rules'));
        add_submenu_page('manahel-rewards', __('گزارش فعالیت‌های امتیازی', 'manahel-rewards'), __('گزارش فعالیت‌های امتیازی', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-events', array(__CLASS__, 'render_events'));
        add_submenu_page('manahel-rewards', __('تاریخچه افزایش و کاهش امتیاز', 'manahel-rewards'), __('تاریخچه امتیاز', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-ledger', array(__CLASS__, 'render_ledger'));
        add_submenu_page('manahel-rewards', __('امتیاز کاربران', 'manahel-rewards'), __('امتیاز کاربران', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-accounts', array(__CLASS__, 'render_accounts'));
        add_submenu_page('manahel-rewards', __('جوایز قابل دریافت', 'manahel-rewards'), __('جوایز', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-catalog', array(__CLASS__, 'render_catalog'));
        add_submenu_page('manahel-rewards', __('درخواست‌های جایزه', 'manahel-rewards'), __('درخواست‌های جایزه', 'manahel-rewards'), MANAHEL_REWARDS_CAP, 'manahel-rewards-redemptions', array(__CLASS__, 'render_redemptions'));
    }

    public static function enqueue_admin_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (0 !== strpos($page, 'manahel-rewards')) { return; }
        wp_enqueue_style('manahel-rewards-admin', MANAHEL_REWARDS_URL . 'admin/assets/manahel-rewards-admin.css', array(), MANAHEL_REWARDS_VERSION);
        if ('manahel-rewards-rules' === $page) {
            wp_enqueue_script('manahel-rewards-admin-rules', MANAHEL_REWARDS_URL . 'admin/assets/manahel-rewards-admin-rules.js', array(), MANAHEL_REWARDS_VERSION, true);
        }
    }

    private static function guard() {
        if (!current_user_can(MANAHEL_REWARDS_CAP)) { wp_die(esc_html__('دسترسی کافی ندارید.', 'manahel-rewards')); }
    }

    private static function notice() {
        if (empty($_GET['mr_notice'])) { return; }
        $code = sanitize_key(wp_unslash($_GET['mr_notice']));
        $map = array(
            'rule_saved' => array('success', __('امتیاز سفارشی ذخیره شد.', 'manahel-rewards')),
            'rule_updated' => array('success', __('تغییرات امتیاز با موفقیت ذخیره شد.', 'manahel-rewards')),
            'rule_conflict' => array('warning', __('این امتیاز هم‌زمان در جای دیگری تغییر کرده است. صفحه را تازه کنید و دوباره ذخیره کنید.', 'manahel-rewards')),
            'rule_invalid_window' => array('error', __('بازه زمانی معتبر نیست؛ زمان پایان باید بعد از زمان شروع باشد.', 'manahel-rewards')),
            'rule_invalid_limits' => array('error', __('محدودیت واردشده معتبر نیست؛ سقف امتیاز نمی‌تواند از امتیاز یک بار انجام فعالیت کمتر باشد.', 'manahel-rewards')),
            'rule_toggled' => array('success', __('وضعیت امتیاز تغییر کرد.', 'manahel-rewards')),
            'settings_saved' => array('success', __('تنظیمات امتیاز ذخیره شد.', 'manahel-rewards')),
            'display_saved' => array('success', __('صفحه «امتیازهای من» ذخیره شد.', 'manahel-rewards')),
            'adjusted' => array('success', __('اصلاح دستی امتیاز ثبت شد.', 'manahel-rewards')),
            'retried' => array('success', __('پردازش مجدد رویداد انجام شد.', 'manahel-rewards')),
            'rebuilt' => array('success', __('موجودی کاربر از روی تاریخچه امتیازها دوباره محاسبه شد.', 'manahel-rewards')),
            'reward_saved' => array('success', __('جایزه ذخیره شد.', 'manahel-rewards')),
            'reward_updated' => array('success', __('تغییرات جایزه ذخیره شد.', 'manahel-rewards')),
            'reward_saved_codes' => array('success', __('جایزه و کدهای آماده ذخیره شدند.', 'manahel-rewards')),
            'reward_updated_codes' => array('success', __('تغییرات جایزه و کدهای جدید ذخیره شدند.', 'manahel-rewards')),
            'reward_codes_none_added' => array('warning', __('تغییرات جایزه ذخیره شد، اما هیچ کد جدیدی به مخزن اضافه نشد.', 'manahel-rewards')),
            'redemption_fulfilled' => array('success', __('تحویل جایزه ثبت شد.', 'manahel-rewards')),
            'redemption_refunded' => array('success', __('درخواست لغو شد و امتیاز به کاربر برگشت.', 'manahel-rewards')),
            'error' => array('error', __('عملیات انجام نشد. جزئیات را بررسی کنید.', 'manahel-rewards')),
        );
        if (!isset($map[$code])) { return; }
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p>', esc_attr($map[$code][0]), esc_html($map[$code][1]));
        if (in_array($code,array('reward_saved_codes','reward_updated_codes','reward_codes_none_added'),true)) {
            $added=isset($_GET['mr_codes_added'])?max(0,(int)$_GET['mr_codes_added']):0;
            $duplicates=isset($_GET['mr_codes_duplicates'])?max(0,(int)$_GET['mr_codes_duplicates']):0;
            $invalid=isset($_GET['mr_codes_invalid'])?max(0,(int)$_GET['mr_codes_invalid']):0;
            $available=isset($_GET['mr_codes_available'])?max(0,(int)$_GET['mr_codes_available']):0;
            printf('<p><strong>%1$s</strong> &nbsp; %2$s &nbsp; %3$s &nbsp; %4$s</p>',
                esc_html(sprintf(__('افزوده‌شده: %s','manahel-rewards'),number_format_i18n($added))),
                esc_html(sprintf(__('تکراری: %s','manahel-rewards'),number_format_i18n($duplicates))),
                esc_html(sprintf(__('نامعتبر: %s','manahel-rewards'),number_format_i18n($invalid))),
                esc_html(sprintf(__('اکنون آماده تحویل: %s','manahel-rewards'),number_format_i18n($available)))
            );
        }
        echo '</div>';
    }

    public static function render_overview() {
        self::guard();
        global $wpdb;
        $counts = array();
        foreach (array('accounts','events','rules','ledger') as $name) {
            $table = Manahel_Rewards_DB::table($name);
            $counts[$name] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
        $hamnoor_available = function_exists('hamnoor_core_api');
        $timezone = Manahel_Rewards_Limit_Evaluator::policy_timezone();
        $my_points_page_id = (int)get_option(Manahel_Rewards_Frontend::OPTION_MY_POINTS_PAGE_ID, 0);
        ?>
        <div class="wrap" dir="rtl">
            <h1><?php esc_html_e('مدیریت امتیاز مناهل', 'manahel-rewards'); ?></h1>
            <?php self::notice(); ?>
            <div class="notice notice-info inline"><p><strong><?php esc_html_e('نمایش برای کاربر:', 'manahel-rewards'); ?></strong> <code>[manahel_rewards_my_points]</code> &nbsp; <strong><?php esc_html_e('فقط موجودی:', 'manahel-rewards'); ?></strong> <code>[manahel_rewards_balance]</code></p></div>
            <p><?php esc_html_e('از این بخش می‌توانید صفحه نمایش امتیاز کاربران، منطقه زمانی محاسبه محدودیت‌ها و وضعیت کلی سیستم را مدیریت کنید.', 'manahel-rewards'); ?></p>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th><?php esc_html_e('نسخه افزونه', 'manahel-rewards'); ?></th><td><code><?php echo esc_html(MANAHEL_REWARDS_VERSION); ?></code></td></tr>
                <tr><th><?php esc_html_e('نسخه دیتابیس', 'manahel-rewards'); ?></th><td><code><?php echo esc_html((string)get_option('manahel_rewards_db_version')); ?></code></td></tr>
                <tr><th><?php esc_html_e('سلامت ذخیره‌سازی مالی', 'manahel-rewards'); ?></th><td><?php echo Manahel_Rewards_DB::storage_is_transactional() ? '<strong style="color:green">سالم</strong>' : '<strong style="color:#b32d2e">نیازمند بررسی</strong>'; ?></td></tr>
                <tr><th><?php esc_html_e('اتصال به هم‌نور', 'manahel-rewards'); ?></th><td><?php echo $hamnoor_available ? '<strong style="color:green">متصل</strong>' : '<strong>در دسترس نیست</strong>'; ?></td></tr>
                <tr><th><?php esc_html_e('دریافت فعالیت‌های هم‌نور', 'manahel-rewards'); ?></th><td><?php echo Manahel_Rewards_Hamnoor_Adapter::is_registered() ? '<strong style="color:green">فعال</strong>' : '<strong>غیرفعال</strong>'; ?></td></tr>
                <tr><th><?php esc_html_e('امتیاز ذکر', 'manahel-rewards'); ?></th><td><strong><?php esc_html_e('ذکر ثبت می‌شود اما برای جلوگیری از سوءاستفاده، امتیاز ندارد.', 'manahel-rewards'); ?></strong></td></tr>
            </tbody></table>

            <h2><?php esc_html_e('صفحه «امتیازهای من»', 'manahel-rewards'); ?></h2>
            <p><?php esc_html_e('صفحه‌ای را انتخاب کنید که شورتکد [manahel_rewards_my_points] داخل آن قرار دارد. کارت امتیاز داشبورد هم‌نور فقط در صورت تعیین این صفحه، لینک «مشاهده جزئیات» را نمایش می‌دهد.', 'manahel-rewards'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;border:1px solid #ccd0d4;padding:12px">
                <input type="hidden" name="action" value="manahel_rewards_save_display_page">
                <?php wp_nonce_field('manahel_rewards_save_display_page'); ?>
                <?php
                wp_dropdown_pages(array(
                    'name' => 'my_points_page_id',
                    'id' => 'mr_my_points_page_id',
                    'selected' => $my_points_page_id,
                    'show_option_none' => __('— انتخاب نشده —', 'manahel-rewards'),
                    'option_none_value' => '0',
                    'post_status' => array('publish'),
                ));
                ?>
                <?php submit_button(__('ذخیره صفحه', 'manahel-rewards'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('منطقه زمانی محاسبه محدودیت‌ها', 'manahel-rewards'); ?></h2>
            <p><?php esc_html_e('سقف‌های روزانه و ماهانه بر اساس این منطقه زمانی محاسبه می‌شوند. برای سایت ایران معمولاً Asia/Tehran مناسب است.', 'manahel-rewards'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;border:1px solid #ccd0d4;padding:12px">
                <input type="hidden" name="action" value="manahel_rewards_save_policy_timezone">
                <?php wp_nonce_field('manahel_rewards_save_policy_timezone'); ?>
                <label for="mr_policy_timezone"><strong><?php esc_html_e('منطقه زمانی', 'manahel-rewards'); ?></strong></label>
                <input class="regular-text" id="mr_policy_timezone" name="policy_timezone" value="<?php echo esc_attr($timezone); ?>" placeholder="Asia/Tehran" required>
                <?php submit_button(__('ذخیره منطقه زمانی', 'manahel-rewards'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('آمار', 'manahel-rewards'); ?></h2>
            <p><?php printf(esc_html__('حساب‌ها: %1$d — رویدادها: %2$d — قوانین: %3$d — تراکنش‌ها: %4$d', 'manahel-rewards'), $counts['accounts'], $counts['events'], $counts['rules'], $counts['ledger']); ?></p>
        </div>
        <?php
    }

    public static function render_rules() {
        self::guard();
        global $wpdb;
        $table = Manahel_Rewards_DB::table('rules');
        $rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY priority ASC,id ASC LIMIT 200");
        $edit_id = isset($_GET['edit_rule']) ? absint($_GET['edit_rule']) : 0;
        $edit_rule = $edit_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $edit_id)) : null;
        $policy_timezone = Manahel_Rewards_Limit_Evaluator::policy_timezone();
        $groups = array('hamnoor'=>array(), 'referral'=>array(), 'custom'=>array());
        foreach ($rules as $rule) { $groups[self::rule_group($rule)][] = $rule; }
        ?>
        <div class="wrap mr-rewards-admin mr-rules-page" dir="rtl">
            <h1><?php esc_html_e('امتیاز فعالیت‌ها', 'manahel-rewards'); ?></h1>
            <?php self::notice(); ?>
            <p class="mr-page-lead"><?php esc_html_e('در این صفحه مشخص می‌کنید هر فعالیت چند امتیاز داشته باشد و هر کاربر تا چه اندازه بتواند آن را دریافت کند. برای استفاده معمول نیازی به دانستن اصطلاحات فنی نیست.', 'manahel-rewards'); ?></p>
            <div class="mr-info-box"><strong><?php esc_html_e('نکته:', 'manahel-rewards'); ?></strong> <?php esc_html_e('تغییر امتیازها روی سوابق قبلی اثر نمی‌گذارد؛ فقط فعالیت‌هایی که بعد از ذخیره برای اولین بار پردازش می‌شوند با تنظیم جدید محاسبه خواهند شد.', 'manahel-rewards'); ?></div>

            <?php if ($edit_id > 0 && !$edit_rule): ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('فعالیت انتخاب‌شده پیدا نشد.', 'manahel-rewards'); ?></p></div>
            <?php endif; ?>

            <?php if ($edit_rule):
                $edit_limits = Manahel_Rewards_Limit_Evaluator::limits_from_rule($edit_rule);
                $cancel_url = add_query_arg(array('page'=>'manahel-rewards-rules'), admin_url('admin.php'));
                $presentation = self::rule_presentation($edit_rule);
                $cooldown_ui = self::cooldown_ui_parts((int)$edit_limits['cooldown_seconds']);
            ?>
            <section id="mr-edit-rule" class="mr-rule-editor">
                <div class="mr-editor-heading">
                    <div>
                        <span class="mr-eyebrow"><?php esc_html_e('ویرایش امتیاز', 'manahel-rewards'); ?></span>
                        <h2><?php echo esc_html($presentation['title']); ?></h2>
                        <p><?php echo esc_html($presentation['description']); ?></p>
                    </div>
                    <span class="mr-status-badge <?php echo $edit_rule->enabled ? 'is-active' : 'is-inactive'; ?>"><?php echo $edit_rule->enabled ? esc_html__('فعال', 'manahel-rewards') : esc_html__('غیرفعال', 'manahel-rewards'); ?></span>
                </div>
                <div id="mr-live-summary" class="mr-live-summary" aria-live="polite" data-action-label="<?php echo esc_attr($presentation['short_action']); ?>"><?php echo esc_html(self::rule_effect_summary($edit_rule, $edit_limits)); ?></div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-simple-form">
                    <input type="hidden" name="action" value="manahel_rewards_update_rule">
                    <input type="hidden" name="rule_id" value="<?php echo (int)$edit_rule->id; ?>">
                    <input type="hidden" name="original_updated_at" value="<?php echo esc_attr($edit_rule->updated_at); ?>">
                    <input type="hidden" name="priority" value="<?php echo (int)$edit_rule->priority; ?>">
                    <?php wp_nonce_field('manahel_rewards_update_rule_' . (int)$edit_rule->id); ?>

                    <div class="mr-form-grid">
                        <label class="mr-field mr-field-important">
                            <span><?php esc_html_e('امتیاز هر بار', 'manahel-rewards'); ?></span>
                            <input type="number" min="1" step="1" id="mr_edit_points" name="points" required value="<?php echo (int)$edit_rule->points; ?>">
                            <small><?php esc_html_e('مثلاً ۱۰ یعنی کاربر با هر بار انجام معتبر این فعالیت، ۱۰ امتیاز می‌گیرد.', 'manahel-rewards'); ?></small>
                        </label>
                        <label class="mr-field">
                            <span><?php esc_html_e('حداکثر دفعات دریافت در روز', 'manahel-rewards'); ?></span>
                            <input type="number" min="0" step="1" id="mr_edit_daily_awards" name="max_awards_per_day" value="<?php echo (int)$edit_limits['max_awards_per_day']; ?>">
                            <small><?php esc_html_e('۰ یعنی بدون محدودیت روزانه از نظر تعداد دفعات.', 'manahel-rewards'); ?></small>
                        </label>
                        <label class="mr-field">
                            <span><?php esc_html_e('حداکثر امتیاز در روز', 'manahel-rewards'); ?></span>
                            <input type="number" min="0" step="1" id="mr_edit_daily_points" name="max_points_per_day" value="<?php echo (int)$edit_limits['max_points_per_day']; ?>">
                            <small><?php esc_html_e('۰ یعنی بدون سقف روزانه از نظر مجموع امتیاز.', 'manahel-rewards'); ?></small>
                        </label>
                        <label class="mr-field">
                            <span><?php esc_html_e('حداکثر دفعات دریافت در ماه', 'manahel-rewards'); ?></span>
                            <input type="number" min="0" step="1" id="mr_edit_monthly_awards" name="max_awards_per_month" value="<?php echo (int)$edit_limits['max_awards_per_month']; ?>">
                            <small><?php esc_html_e('۰ یعنی بدون محدودیت ماهانه از نظر تعداد دفعات.', 'manahel-rewards'); ?></small>
                        </label>
                        <label class="mr-field">
                            <span><?php esc_html_e('حداکثر امتیاز در ماه', 'manahel-rewards'); ?></span>
                            <input type="number" min="0" step="1" id="mr_edit_monthly_points" name="max_points_per_month" value="<?php echo (int)$edit_limits['max_points_per_month']; ?>">
                            <small><?php esc_html_e('۰ یعنی بدون سقف ماهانه از نظر مجموع امتیاز.', 'manahel-rewards'); ?></small>
                        </label>
                        <label class="mr-field">
                            <span><?php esc_html_e('فاصله تا دریافت دوباره', 'manahel-rewards'); ?></span>
                            <select id="mr_edit_cooldown_preset" name="cooldown_preset">
                                <option value="none" <?php selected($cooldown_ui['preset'], 'none'); ?>><?php esc_html_e('بدون فاصله', 'manahel-rewards'); ?></option>
                                <option value="hour" <?php selected($cooldown_ui['preset'], 'hour'); ?>><?php esc_html_e('پس از ۱ ساعت', 'manahel-rewards'); ?></option>
                                <option value="day" <?php selected($cooldown_ui['preset'], 'day'); ?>><?php esc_html_e('پس از ۱ روز', 'manahel-rewards'); ?></option>
                                <option value="week" <?php selected($cooldown_ui['preset'], 'week'); ?>><?php esc_html_e('پس از ۷ روز', 'manahel-rewards'); ?></option>
                                <option value="month" <?php selected($cooldown_ui['preset'], 'month'); ?>><?php esc_html_e('پس از ۳۰ روز', 'manahel-rewards'); ?></option>
                                <option value="custom" <?php selected($cooldown_ui['preset'], 'custom'); ?>><?php esc_html_e('فاصله سفارشی', 'manahel-rewards'); ?></option>
                            </select>
                            <span id="mr-custom-cooldown" class="mr-inline-custom"<?php echo 'custom' === $cooldown_ui['preset'] ? '' : ' hidden'; ?>>
                                <input type="number" min="1" step="1" name="cooldown_custom_value" value="<?php echo (int)$cooldown_ui['value']; ?>" aria-label="<?php esc_attr_e('مقدار فاصله سفارشی', 'manahel-rewards'); ?>">
                                <select name="cooldown_custom_unit" aria-label="<?php esc_attr_e('واحد فاصله سفارشی', 'manahel-rewards'); ?>">
                                    <option value="seconds" <?php selected($cooldown_ui['unit'], 'seconds'); ?>><?php esc_html_e('ثانیه', 'manahel-rewards'); ?></option>
                                    <option value="minutes" <?php selected($cooldown_ui['unit'], 'minutes'); ?>><?php esc_html_e('دقیقه', 'manahel-rewards'); ?></option>
                                    <option value="hours" <?php selected($cooldown_ui['unit'], 'hours'); ?>><?php esc_html_e('ساعت', 'manahel-rewards'); ?></option>
                                    <option value="days" <?php selected($cooldown_ui['unit'], 'days'); ?>><?php esc_html_e('روز', 'manahel-rewards'); ?></option>
                                </select>
                            </span>
                        </label>
                    </div>

                    <div class="mr-switch-row">
                        <label><input type="checkbox" name="enabled" value="1" <?php checked((int)$edit_rule->enabled, 1); ?>> <strong><?php esc_html_e('این امتیاز فعال باشد', 'manahel-rewards'); ?></strong></label>
                        <small><?php esc_html_e('اگر خاموش باشد، فعالیت ثبت می‌شود اما برای آن امتیازی ایجاد نمی‌شود.', 'manahel-rewards'); ?></small>
                    </div>

                    <details class="mr-more-settings">
                        <summary><?php esc_html_e('تنظیمات بیشتر', 'manahel-rewards'); ?></summary>
                        <div class="mr-form-grid mr-form-grid-secondary">
                            <label class="mr-field">
                                <span><?php esc_html_e('عنوان نمایشی', 'manahel-rewards'); ?></span>
                                <input class="regular-text" id="mr_edit_title" name="title" maxlength="190" required value="<?php echo esc_attr(self::rule_display_title($edit_rule)); ?>">
                            </label>
                            <label class="mr-field mr-checkbox-field">
                                <span><?php esc_html_e('جلوگیری از امتیاز تکراری برای همان فعالیت', 'manahel-rewards'); ?></span>
                                <span><input type="checkbox" name="unique_per_entity" value="1" <?php checked(!empty($edit_limits['unique_per_entity'])); ?>> <?php esc_html_e('فعال باشد', 'manahel-rewards'); ?></span>
                                <small><?php esc_html_e('پیشنهاد می‌شود روشن بماند تا یک مشارکت مشخص دوبار امتیاز نگیرد.', 'manahel-rewards'); ?></small>
                            </label>
                            <label class="mr-field">
                                <span><?php esc_html_e('شروع اجرای این امتیاز', 'manahel-rewards'); ?></span>
                                <input type="datetime-local" name="starts_at" value="<?php echo esc_attr(self::utc_mysql_to_admin_datetime($edit_rule->starts_at)); ?>">
                                <small><?php printf(esc_html__('خالی = از همین حالا/بدون محدودیت شروع. منطقه زمانی: %s', 'manahel-rewards'), esc_html($policy_timezone)); ?></small>
                            </label>
                            <label class="mr-field">
                                <span><?php esc_html_e('پایان اجرای این امتیاز', 'manahel-rewards'); ?></span>
                                <input type="datetime-local" name="ends_at" value="<?php echo esc_attr(self::utc_mysql_to_admin_datetime($edit_rule->ends_at)); ?>">
                                <small><?php esc_html_e('خالی = بدون تاریخ پایان.', 'manahel-rewards'); ?></small>
                            </label>
                        </div>
                    </details>

                    <details class="mr-technical-details">
                        <summary><?php esc_html_e('اطلاعات فنی — فقط برای توسعه‌دهنده', 'manahel-rewards'); ?></summary>
                        <table class="widefat striped"><tbody>
                            <tr><th><?php esc_html_e('کد داخلی', 'manahel-rewards'); ?></th><td><code><?php echo esc_html($edit_rule->code); ?></code></td></tr>
                            <tr><th><?php esc_html_e('رویداد داخلی', 'manahel-rewards'); ?></th><td><code><?php echo esc_html($edit_rule->source . ':' . $edit_rule->event_name); ?></code></td></tr>
                            <tr><th><?php esc_html_e('نوع داخلی فعالیت', 'manahel-rewards'); ?></th><td><code><?php echo esc_html($edit_rule->entity_type ?: '*'); ?></code></td></tr>
                            <tr><th><?php esc_html_e('شرایط فنی', 'manahel-rewards'); ?></th><td><code class="mr-code-wrap"><?php echo esc_html($edit_rule->conditions_json); ?></code></td></tr>
                        </tbody></table>
                        <p class="description"><?php esc_html_e('این موارد از این صفحه قابل تغییر نیستند تا اتصال هم‌نور و سیستم دعوت ناخواسته خراب نشود.', 'manahel-rewards'); ?></p>
                    </details>

                    <div class="mr-form-actions">
                        <?php submit_button(__('ذخیره تغییرات', 'manahel-rewards'), 'primary', 'submit', false); ?>
                        <a class="button" href="<?php echo esc_url($cancel_url); ?>"><?php esc_html_e('انصراف', 'manahel-rewards'); ?></a>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <?php
            $group_labels = array(
                'hamnoor' => array(__('فعالیت‌های هم‌نور', 'manahel-rewards'), __('امتیازهایی که کاربر با انجام فعالیت‌های قرآنی و دعایی دریافت می‌کند.', 'manahel-rewards')),
                'referral' => array(__('دعوت دوستان', 'manahel-rewards'), __('پاداش دعوت‌کننده و هدیه کاربر جدید پس از یک دعوت معتبر.', 'manahel-rewards')),
                'custom' => array(__('قوانین سفارشی', 'manahel-rewards'), __('قوانینی که به‌صورت سفارشی توسط مدیر یا توسعه‌دهنده ساخته شده‌اند.', 'manahel-rewards')),
            );
            foreach ($group_labels as $group_key => $group_meta):
                if (empty($groups[$group_key])) { continue; }
            ?>
                <section class="mr-rule-group">
                    <div class="mr-group-heading"><h2><?php echo esc_html($group_meta[0]); ?></h2><p><?php echo esc_html($group_meta[1]); ?></p></div>
                    <div class="mr-rule-grid">
                    <?php foreach ($groups[$group_key] as $rule):
                        $limits = Manahel_Rewards_Limit_Evaluator::limits_from_rule($rule);
                        $presentation = self::rule_presentation($rule);
                        $edit_url = add_query_arg(array('page'=>'manahel-rewards-rules','edit_rule'=>(int)$rule->id), admin_url('admin.php')) . '#mr-edit-rule';
                    ?>
                        <article class="mr-rule-card<?php echo $rule->enabled ? ' is-active' : ' is-inactive'; ?><?php echo $edit_rule && (int)$edit_rule->id === (int)$rule->id ? ' is-editing' : ''; ?>">
                            <div class="mr-card-top">
                                <div><h3><?php echo esc_html($presentation['title']); ?></h3><p><?php echo esc_html($presentation['description']); ?></p></div>
                                <span class="mr-status-badge <?php echo $rule->enabled ? 'is-active' : 'is-inactive'; ?>"><?php echo $rule->enabled ? esc_html__('فعال', 'manahel-rewards') : esc_html__('غیرفعال', 'manahel-rewards'); ?></span>
                            </div>
                            <div class="mr-points-number"><strong><?php echo (int)$rule->points; ?></strong><span><?php esc_html_e('امتیاز هر بار', 'manahel-rewards'); ?></span></div>
                            <p class="mr-card-summary"><?php echo esc_html(self::rule_effect_summary($rule, $limits)); ?></p>
                            <div class="mr-limit-chips"><?php echo wp_kses_post(self::limit_chips_html($limits, $rule)); ?></div>
                            <div class="mr-card-actions">
                                <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('ویرایش امتیاز', 'manahel-rewards'); ?></a>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=manahel_rewards_toggle_rule&rule_id='.(int)$rule->id), 'manahel_rewards_toggle_rule_'.$rule->id)); ?>"><?php echo $rule->enabled ? esc_html__('غیرفعال کردن', 'manahel-rewards') : esc_html__('فعال کردن', 'manahel-rewards'); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <details class="mr-developer-tool">
                <summary><?php esc_html_e('ابزار توسعه‌دهنده: افزودن قانون سفارشی', 'manahel-rewards'); ?></summary>
                <div class="mr-warning-box"><?php esc_html_e('این بخش برای استفاده معمول لازم نیست. ساخت قانون سفارشی نیازمند شناخت ساختار رویدادهای هم‌نور است و تنظیم اشتباه می‌تواند باعث امتیازدهی ناخواسته شود.', 'manahel-rewards'); ?></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-technical-form">
                    <input type="hidden" name="action" value="manahel_rewards_save_rule">
                    <?php wp_nonce_field('manahel_rewards_save_rule'); ?>
                    <table class="form-table"><tbody>
                        <tr><th><label for="mr_code"><?php esc_html_e('کد داخلی قانون', 'manahel-rewards'); ?></label></th><td><input class="regular-text" id="mr_code" name="code" required placeholder="CUSTOM_QURAN_RULE"></td></tr>
                        <tr><th><label for="mr_title"><?php esc_html_e('عنوان', 'manahel-rewards'); ?></label></th><td><input class="regular-text" id="mr_title" name="title" maxlength="190" required></td></tr>
                        <tr><th><?php esc_html_e('نوع محتوا', 'manahel-rewards'); ?></th><td><select name="content_type" required><option value="quran">قرآن</option><option value="dua">دعا</option></select></td></tr>
                        <tr><th><?php esc_html_e('نوع داخلی فعالیت', 'manahel-rewards'); ?></th><td><select name="entity_type"><option value=""><?php esc_html_e('هر نوع مجاز', 'manahel-rewards'); ?></option><option value="quran_part">quran_part</option><option value="personal_quran">personal_quran</option><option value="contribution">contribution</option></select></td></tr>
                        <tr><th><?php esc_html_e('نوع رخداد', 'manahel-rewards'); ?></th><td><select name="kind"><option value=""><?php esc_html_e('بدون شرط', 'manahel-rewards'); ?></option><option value="completion">completion</option></select></td></tr>
                        <tr><th><?php esc_html_e('امتیاز', 'manahel-rewards'); ?></th><td><input type="number" min="1" step="1" name="points" required></td></tr>
                        <tr><th><?php esc_html_e('هر فعالیت فقط یک بار', 'manahel-rewards'); ?></th><td><label><input type="checkbox" name="unique_per_entity" value="1" checked> <?php esc_html_e('فعال', 'manahel-rewards'); ?></label></td></tr>
                        <tr><th><?php esc_html_e('حداکثر دفعات در روز', 'manahel-rewards'); ?></th><td><input type="number" min="0" step="1" name="max_awards_per_day" value="0"></td></tr>
                        <tr><th><?php esc_html_e('حداکثر امتیاز در روز', 'manahel-rewards'); ?></th><td><input type="number" min="0" step="1" name="max_points_per_day" value="0"></td></tr>
                        <tr><th><?php esc_html_e('حداکثر دفعات در ماه', 'manahel-rewards'); ?></th><td><input type="number" min="0" step="1" name="max_awards_per_month" value="0"></td></tr>
                        <tr><th><?php esc_html_e('حداکثر امتیاز در ماه', 'manahel-rewards'); ?></th><td><input type="number" min="0" step="1" name="max_points_per_month" value="0"></td></tr>
                        <tr><th><?php esc_html_e('فاصله دریافت مجدد (ثانیه)', 'manahel-rewards'); ?></th><td><input type="number" min="0" step="1" name="cooldown_seconds" value="0"></td></tr>
                        <tr><th><?php esc_html_e('اولویت فنی', 'manahel-rewards'); ?></th><td><input type="number" name="priority" value="10" step="1"></td></tr>
                        <tr><th><?php esc_html_e('شروع', 'manahel-rewards'); ?></th><td><input type="datetime-local" name="starts_at"></td></tr>
                        <tr><th><?php esc_html_e('پایان', 'manahel-rewards'); ?></th><td><input type="datetime-local" name="ends_at"></td></tr>
                    </tbody></table>
                    <?php submit_button(__('ساخت قانون سفارشی', 'manahel-rewards')); ?>
                </form>
            </details>
        </div>
        <?php
    }

    public static function render_events() {
        self::guard();
        global $wpdb;
        $table = Manahel_Rewards_DB::table('events');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200");
        ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('گزارش فعالیت‌های امتیازی', 'manahel-rewards'); ?></h1><?php self::notice(); ?>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('رویداد', 'manahel-rewards'); ?></th><th><?php esc_html_e('Entity', 'manahel-rewards'); ?></th><th><?php esc_html_e('محتوا', 'manahel-rewards'); ?></th><th><?php esc_html_e('وضعیت', 'manahel-rewards'); ?></th><th><?php esc_html_e('تصمیم پاداش', 'manahel-rewards'); ?></th><th><?php esc_html_e('زمان', 'manahel-rewards'); ?></th><th></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8"><?php esc_html_e('رویدادی دریافت نشده است.', 'manahel-rewards'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $row):
            $payload=json_decode((string)$row->payload_json,true); $content=$payload['context']['content_type']??'';
            $decision=!empty($row->decision_json)?json_decode((string)$row->decision_json,true):array();
            $decision_code=is_array($decision)?($decision['decision_code']??''):'';
            $points=is_array($decision)?(int)($decision['total_awarded']??0):0;
        ?>
        <tr>
            <td><?php echo (int)$row->id; ?></td>
            <td><code><?php echo esc_html($row->source.':'.$row->event_name); ?></code></td>
            <td><code><?php echo esc_html($row->entity_type); ?></code><br><small><?php echo esc_html($row->entity_id); ?></small></td>
            <td><?php echo esc_html($content); ?></td>
            <td><strong><?php echo esc_html($row->status); ?></strong></td>
            <td><?php echo esc_html(self::decision_label($decision_code, $points, (string)$row->error_code)); ?><?php
                $replay = is_array($decision) && !empty($decision['receipt_replay']) && is_array($decision['receipt_replay']) ? $decision['receipt_replay'] : array();
                if ($replay): ?>
                    <br><small><?php printf(esc_html__('تکرار دریافت: %1$s (تعداد: %2$d)', 'manahel-rewards'), esc_html(self::decision_label((string)($replay['decision_code']??'duplicate_event'), 0, '')), (int)($replay['count']??1)); ?></small>
                <?php endif; ?><?php if ($decision): ?><details><summary><?php esc_html_e('جزئیات', 'manahel-rewards'); ?></summary><code style="white-space:pre-wrap"><?php echo esc_html(wp_json_encode($decision, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></code></details><?php endif; ?></td>
            <td><?php echo esc_html($row->occurred_at); ?> UTC</td>
            <td><?php if (in_array($row->status,array('failed','received'),true)): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=manahel_rewards_retry_event&event_id='.(int)$row->id), 'manahel_rewards_retry_event_'.$row->id)); ?>">Retry</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?></tbody></table></div>
        <?php
    }

    public static function render_ledger() {
        self::guard();
        global $wpdb;
        $ledger = Manahel_Rewards_DB::table('ledger');
        $accounts = Manahel_Rewards_DB::table('accounts');
        $rows = $wpdb->get_results("SELECT l.*,a.wp_user_id FROM {$ledger} l LEFT JOIN {$accounts} a ON a.id=l.account_id ORDER BY l.id DESC LIMIT 200");
        ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('تاریخچه افزایش و کاهش امتیاز', 'manahel-rewards'); ?></h1><?php self::notice(); ?>
        <p><?php esc_html_e('این بخش تاریخچه قطعی تغییرات امتیاز را نشان می‌دهد. برای حفظ قابلیت حسابرسی، رکوردهای قبلی ویرایش نمی‌شوند و هر اصلاح به‌صورت یک تراکنش جدید ثبت می‌شود.', 'manahel-rewards'); ?></p>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('کاربر', 'manahel-rewards'); ?></th><th><?php esc_html_e('نوع', 'manahel-rewards'); ?></th><th>Delta</th><th><?php esc_html_e('علت', 'manahel-rewards'); ?></th><th>Event</th><th>Rule</th><th><?php esc_html_e('زمان', 'manahel-rewards'); ?></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8"><?php esc_html_e('هنوز تغییری در امتیاز کاربران ثبت نشده است.', 'manahel-rewards'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr><td><?php echo (int)$row->id; ?></td><td><?php echo $row->wp_user_id ? 'WP#'.(int)$row->wp_user_id : '—'; ?></td><td><?php echo esc_html($row->entry_type); ?></td><td><strong><?php echo ((int)$row->delta>0?'+':'').(int)$row->delta; ?></strong></td><td><?php echo esc_html($row->reason); ?></td><td><?php echo $row->event_id?(int)$row->event_id:'—'; ?></td><td><?php echo $row->rule_id?(int)$row->rule_id:'—'; ?></td><td><?php echo esc_html($row->created_at); ?> UTC</td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    public static function render_accounts() {
        self::guard();
        global $wpdb;
        $table = Manahel_Rewards_DB::table('accounts');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200");
        ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('امتیاز کاربران', 'manahel-rewards'); ?></h1><?php self::notice(); ?>
        <h2><?php esc_html_e('اصلاح دستی', 'manahel-rewards'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:850px">
            <input type="hidden" name="action" value="manahel_rewards_manual_adjustment"><?php wp_nonce_field('manahel_rewards_manual_adjustment'); ?>
            <label>WordPress User ID <input type="number" min="1" name="wp_user_id" required></label>
            &nbsp; <label>Delta <input type="number" step="1" name="delta" required></label>
            &nbsp; <label><?php esc_html_e('دلیل', 'manahel-rewards'); ?> <input type="text" name="reason" required style="min-width:280px"></label>
            <?php submit_button(__('ثبت اصلاح', 'manahel-rewards'), 'secondary', 'submit', false); ?>
        </form>
        <h2><?php esc_html_e('حساب‌ها', 'manahel-rewards'); ?></h2>
        <table class="widefat striped"><thead><tr><th>ID</th><th>WP User</th><th><?php esc_html_e('وضعیت', 'manahel-rewards'); ?></th><th><?php esc_html_e('موجودی', 'manahel-rewards'); ?></th><th></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="5"><?php esc_html_e('هنوز حسابی ساخته نشده است.', 'manahel-rewards'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr><td><?php echo (int)$row->id; ?></td><td><?php echo $row->wp_user_id ? 'WP#'.(int)$row->wp_user_id : '—'; ?></td><td><?php echo esc_html($row->status); ?></td><td><strong><?php echo (int)$row->balance; ?></strong></td><td><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=manahel_rewards_rebuild_balance&account_id='.(int)$row->id), 'manahel_rewards_rebuild_balance_'.$row->id)); ?>"><?php esc_html_e('محاسبه مجدد موجودی', 'manahel-rewards'); ?></a></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    public static function handle_save_rule() {
        self::guard(); check_admin_referer('manahel_rewards_save_rule');
        $code = strtoupper(sanitize_key(wp_unslash($_POST['code'] ?? '')));
        $title = self::truncate_text(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 190);
        $content = sanitize_key(wp_unslash($_POST['content_type'] ?? ''));
        $entity = sanitize_key(wp_unslash($_POST['entity_type'] ?? ''));
        $kind = sanitize_key(wp_unslash($_POST['kind'] ?? ''));
        $points = (int)($_POST['points'] ?? 0);
        $priority = self::sanitize_priority($_POST['priority'] ?? 10);
        if (!$code || !$title || !in_array($content,array('quran','dua'),true) || $points <= 0 || ($entity && !in_array($entity,array('quran_part','personal_quran','contribution'),true)) || ($kind && 'completion' !== $kind)) {
            self::redirect('manahel-rewards-rules','error');
        }
        $conditions = array('context.content_type' => $content);
        if ($kind) { $conditions['context.kind'] = $kind; }
        $limits = self::limits_from_request();
        if (!self::limits_valid_for_points($limits, $points)) { self::redirect('manahel-rewards-rules','rule_invalid_limits'); }
        $starts = self::admin_datetime_to_utc_mysql($_POST['starts_at'] ?? '');
        $ends = self::admin_datetime_to_utc_mysql($_POST['ends_at'] ?? '');
        if (false === $starts || false === $ends || ($starts && $ends && $starts >= $ends)) { self::redirect('manahel-rewards-rules','rule_invalid_window'); }
        $now = Manahel_Rewards_DB::utc_now_mysql();
        $insert = Manahel_Rewards_DB::quiet_insert(Manahel_Rewards_DB::table('rules'), array(
            'code'=>$code,'title'=>$title,'source'=>'hamnoor','event_name'=>'participation.completed','entity_type'=>$entity ?: null,
            'conditions_json'=>wp_json_encode($conditions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'limits_json'=>wp_json_encode($limits, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'points'=>$points,'enabled'=>1,'priority'=>$priority,
            'starts_at'=>$starts ?: null,'ends_at'=>$ends ?: null,'created_at'=>$now,'updated_at'=>$now
        ));
        self::redirect('manahel-rewards-rules', false === $insert['result'] ? 'error' : 'rule_saved');
    }

    public static function handle_update_rule() {
        self::guard();
        $id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        check_admin_referer('manahel_rewards_update_rule_' . $id);
        if ($id <= 0) { self::redirect('manahel-rewards-rules', 'error'); }

        global $wpdb;
        $table = Manahel_Rewards_DB::table('rules');
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id));
        if (!$rule) { self::redirect('manahel-rewards-rules', 'error'); }

        $original_updated_at = sanitize_text_field(wp_unslash($_POST['original_updated_at'] ?? ''));
        if (!$original_updated_at || !hash_equals((string)$rule->updated_at, $original_updated_at)) {
            self::redirect('manahel-rewards-rules', 'rule_conflict', array('edit_rule'=>$id));
        }

        $title = self::truncate_text(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 190);
        $points = (int)($_POST['points'] ?? 0);
        $priority = self::sanitize_priority($_POST['priority'] ?? 10);
        if (!$title || $points <= 0) { self::redirect('manahel-rewards-rules', 'error', array('edit_rule'=>$id)); }

        $limits = self::limits_from_request();
        if (!self::limits_valid_for_points($limits, $points)) {
            self::redirect('manahel-rewards-rules', 'rule_invalid_limits', array('edit_rule'=>$id));
        }

        $starts = self::admin_datetime_to_utc_mysql($_POST['starts_at'] ?? '');
        $ends = self::admin_datetime_to_utc_mysql($_POST['ends_at'] ?? '');
        if (false === $starts || false === $ends || ($starts && $ends && $starts >= $ends)) {
            self::redirect('manahel-rewards-rules', 'rule_invalid_window', array('edit_rule'=>$id));
        }

        // Deliberately immutable here: code, source, event_name, entity_type, conditions_json.
        // Those fields are integration identity, not reward-policy knobs.
        $ok = $wpdb->update($table, array(
            'title' => $title,
            'limits_json' => wp_json_encode($limits, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'points' => $points,
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'priority' => $priority,
            'starts_at' => $starts ?: null,
            'ends_at' => $ends ?: null,
            'updated_at' => Manahel_Rewards_DB::utc_now_mysql(),
        ), array('id'=>$id));

        self::redirect('manahel-rewards-rules', false === $ok ? 'error' : 'rule_updated', false === $ok ? array('edit_rule'=>$id) : array());
    }

    public static function handle_toggle_rule() {
        self::guard();
        $id=(int)($_GET['rule_id']??0); check_admin_referer('manahel_rewards_toggle_rule_'.$id);
        global $wpdb; $table=Manahel_Rewards_DB::table('rules');
        $enabled=$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$table} WHERE id=%d",$id));
        if (null === $enabled) { self::redirect('manahel-rewards-rules','error'); }
        $ok=$wpdb->update($table,array('enabled'=>(int)!$enabled,'updated_at'=>Manahel_Rewards_DB::utc_now_mysql()),array('id'=>$id));
        self::redirect('manahel-rewards-rules', false===$ok?'error':'rule_toggled');
    }

    public static function handle_save_policy_timezone() {
        self::guard(); check_admin_referer('manahel_rewards_save_policy_timezone');
        $timezone = sanitize_text_field(wp_unslash($_POST['policy_timezone'] ?? ''));
        if (!Manahel_Rewards_Limit_Evaluator::valid_timezone($timezone)) { self::redirect('manahel-rewards', 'error'); }
        update_option(Manahel_Rewards_Limit_Evaluator::OPTION_TIMEZONE, $timezone, false);
        self::redirect('manahel-rewards', 'settings_saved');
    }

    public static function handle_save_display_page() {
        self::guard(); check_admin_referer('manahel_rewards_save_display_page');
        $page_id = isset($_POST['my_points_page_id']) ? (int)$_POST['my_points_page_id'] : 0;
        if ($page_id > 0) {
            $post = get_post($page_id);
            if (!$post || 'page' !== $post->post_type || 'publish' !== $post->post_status) {
                self::redirect('manahel-rewards', 'error');
            }
        }
        update_option(Manahel_Rewards_Frontend::OPTION_MY_POINTS_PAGE_ID, $page_id, false);
        self::redirect('manahel-rewards', 'display_saved');
    }

    public static function handle_manual_adjustment() {
        self::guard(); check_admin_referer('manahel_rewards_manual_adjustment');
        $wp_user_id=(int)($_POST['wp_user_id']??0); $delta=(int)($_POST['delta']??0); $reason=sanitize_text_field(wp_unslash($_POST['reason']??''));
        $account=Manahel_Rewards_Account_Service::get_or_create($wp_user_id);
        if (is_wp_error($account)) { self::redirect('manahel-rewards-accounts','error'); }
        $result=Manahel_Rewards_Ledger_Service::manual_adjustment((int)$account->id,$delta,$reason,get_current_user_id());
        self::redirect('manahel-rewards-accounts', is_wp_error($result)?'error':'adjusted');
    }

    public static function handle_retry_event() {
        self::guard(); $id=(int)($_GET['event_id']??0); check_admin_referer('manahel_rewards_retry_event_'.$id);
        $result=Manahel_Rewards_Event_Processor::retry($id);
        self::redirect('manahel-rewards-events', is_wp_error($result)?'error':'retried');
    }

    public static function handle_rebuild_balance() {
        self::guard(); $id=(int)($_GET['account_id']??0); check_admin_referer('manahel_rewards_rebuild_balance_'.$id);
        $result=Manahel_Rewards_Account_Service::rebuild_balance($id);
        self::redirect('manahel-rewards-accounts', is_wp_error($result)?'error':'rebuilt');
    }

    private static function limits_from_request() {
        return Manahel_Rewards_Limit_Evaluator::sanitize_limits(array(
            'unique_per_entity' => !empty($_POST['unique_per_entity']),
            'max_awards_per_day' => (int)($_POST['max_awards_per_day'] ?? 0),
            'max_points_per_day' => (int)($_POST['max_points_per_day'] ?? 0),
            'max_awards_per_month' => (int)($_POST['max_awards_per_month'] ?? 0),
            'max_points_per_month' => (int)($_POST['max_points_per_month'] ?? 0),
            'cooldown_seconds' => self::cooldown_seconds_from_request(),
        ));
    }

    private static function cooldown_seconds_from_request() {
        if (!isset($_POST['cooldown_preset'])) {
            return max(0, (int)($_POST['cooldown_seconds'] ?? 0));
        }
        $preset = sanitize_key(wp_unslash($_POST['cooldown_preset'] ?? 'none'));
        $presets = array('none'=>0, 'hour'=>3600, 'day'=>86400, 'week'=>604800, 'month'=>2592000);
        if (isset($presets[$preset])) { return $presets[$preset]; }
        if ('custom' !== $preset) { return 0; }
        $value = max(1, min(1000000, (int)($_POST['cooldown_custom_value'] ?? 1)));
        $unit = sanitize_key(wp_unslash($_POST['cooldown_custom_unit'] ?? 'hours'));
        $multipliers = array('seconds'=>1, 'minutes'=>60, 'hours'=>3600, 'days'=>86400);
        $multiplier = $multipliers[$unit] ?? 3600;
        return min(315360000, $value * $multiplier);
    }

    private static function limits_valid_for_points($limits, $points) {
        $points = (int)$points;
        if ($points <= 0 || !is_array($limits)) { return false; }
        foreach (array('max_points_per_day','max_points_per_month') as $key) {
            $cap = isset($limits[$key]) ? (int)$limits[$key] : 0;
            if ($cap > 0 && $cap < $points) { return false; }
        }
        return true;
    }

    private static function sanitize_priority($value) {
        return max(-100000, min(100000, (int)$value));
    }

    private static function truncate_text($value, $max_chars) {
        $value = (string)$value;
        $max_chars = max(1, (int)$max_chars);
        if (function_exists('mb_substr')) { return mb_substr($value, 0, $max_chars); }
        if (preg_match_all('/./us', $value, $matches) && count($matches[0]) > $max_chars) {
            return implode('', array_slice($matches[0], 0, $max_chars));
        }
        return $value;
    }

    private static function admin_datetime_to_utc_mysql($value) {
        $value = sanitize_text_field(wp_unslash((string)$value));
        if (!$value) { return ''; }
        $timezone = Manahel_Rewards_Limit_Evaluator::policy_timezone();
        try {
            $tz = new DateTimeZone($timezone);
            foreach (array('!Y-m-d\\TH:i', '!Y-m-d\\TH:i:s') as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value, $tz);
                $errors = DateTimeImmutable::getLastErrors();
                if ($date && (false === $errors || (0 === (int)$errors['warning_count'] && 0 === (int)$errors['error_count']))) {
                    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        } catch (Exception $e) {}
        return false;
    }

    private static function utc_mysql_to_admin_datetime($value) {
        $value = trim((string)$value);
        if (!$value) { return ''; }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $date->setTimezone(new DateTimeZone(Manahel_Rewards_Limit_Evaluator::policy_timezone()))->format('Y-m-d\\TH:i');
        } catch (Exception $e) { return ''; }
    }

    private static function format_rule_window($rule) {
        if (!is_object($rule)) { return ''; }
        $timezone = Manahel_Rewards_Limit_Evaluator::policy_timezone();
        $start = self::utc_mysql_to_admin_datetime($rule->starts_at ?? '');
        $end = self::utc_mysql_to_admin_datetime($rule->ends_at ?? '');
        if (!$start && !$end) { return ''; }
        return sprintf('window: %s → %s (%s)', $start ?: '…', $end ?: '…', $timezone);
    }

    private static function format_limits($limits) {
        $parts = array();
        if (!empty($limits['unique_per_entity'])) { $parts[] = 'entity:1x'; }
        if (!empty($limits['max_awards_per_day'])) { $parts[] = 'day awards≤'.(int)$limits['max_awards_per_day']; }
        if (!empty($limits['max_points_per_day'])) { $parts[] = 'day points≤'.(int)$limits['max_points_per_day']; }
        if (!empty($limits['max_awards_per_month'])) { $parts[] = 'month awards≤'.(int)$limits['max_awards_per_month']; }
        if (!empty($limits['max_points_per_month'])) { $parts[] = 'month points≤'.(int)$limits['max_points_per_month']; }
        if (!empty($limits['cooldown_seconds'])) { $parts[] = 'cooldown='.(int)$limits['cooldown_seconds'].'s'; }
        return $parts ? implode(' | ', $parts) : 'بدون محدودیت اضافی';
    }

    private static function rule_group($rule) {
        $code = is_object($rule) ? (string)($rule->code ?? '') : '';
        if (0 === strpos($code, 'MANAHEL_REFERRAL_')) { return 'referral'; }
        if (0 === strpos($code, 'HAMNOOR_')) { return 'hamnoor'; }
        return 'custom';
    }

    private static function rule_display_title($rule) {
        if (!is_object($rule)) { return ''; }
        $code = (string)($rule->code ?? '');
        $stored = trim((string)($rule->title ?? ''));
        $map = array(
            'HAMNOOR_QURAN_PART_COMPLETED' => array('old'=>'تکمیل سهم عمومی قرآن', 'friendly'=>'خواندن و تکمیل یک سهم قرآن'),
            'HAMNOOR_PERSONAL_QURAN_COMPLETED' => array('old'=>'تکمیل ختم شخصی قرآن', 'friendly'=>'تکمیل قرآن شخصی'),
            'HAMNOOR_DUA_COMPLETED' => array('old'=>'تکمیل معتبر دعا', 'friendly'=>'خواندن و تکمیل یک دعا'),
            'MANAHEL_REFERRAL_QUALIFIED_INVITER' => array('old'=>'دعوت موفق کاربر جدید', 'friendly'=>'معرفی موفق یک دوست'),
            'MANAHEL_REFERRAL_QUALIFIED_REFERRED' => array('old'=>'پاداش خوش‌آمد دعوت معتبر', 'friendly'=>'هدیه ورود از طریق دعوت'),
            'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT' => array('old'=>'جذب مشارکت‌کننده به پویش', 'friendly'=>'جذب مشارکت‌کننده به پویش'),
        );
        if (isset($map[$code]) && ('' === $stored || $stored === $map[$code]['old'])) { return $map[$code]['friendly']; }
        return $stored ?: $code;
    }

    private static function rule_presentation($rule) {
        $code = is_object($rule) ? (string)($rule->code ?? '') : '';
        $default = array(
            'title' => self::rule_display_title($rule),
            'short_action' => self::rule_display_title($rule),
            'description' => __('پس از انجام معتبر این فعالیت، امتیاز مطابق محدودیت‌های تعیین‌شده ثبت می‌شود؛ برای کسب امتیاز تأیید شماره لازم نیست.', 'manahel-rewards'),
        );
        $map = array(
            'HAMNOOR_QURAN_PART_COMPLETED' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('تکمیل یک سهم قرآن', 'manahel-rewards'),
                'description'=>__('پس از اینکه کاربر یک سهم عمومی قرآن را به‌طور معتبر تکمیل کند، این امتیاز ثبت می‌شود.', 'manahel-rewards'),
            ),
            'HAMNOOR_PERSONAL_QURAN_COMPLETED' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('تکمیل قرآن شخصی', 'manahel-rewards'),
                'description'=>__('پس از رسیدن کاربر به پایان قرآن شخصی و ثبت تکمیل نهایی، این امتیاز ثبت می‌شود.', 'manahel-rewards'),
            ),
            'HAMNOOR_DUA_COMPLETED' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('تکمیل یک دعا', 'manahel-rewards'),
                'description'=>__('پس از تکمیل معتبر یک سهم دعا، این امتیاز برای کاربر ثبت می‌شود.', 'manahel-rewards'),
            ),
            'MANAHEL_REFERRAL_QUALIFIED_INVITER' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('یک دعوت موفق', 'manahel-rewards'),
                'description'=>__('وقتی فرد جدید از لینک دعوت وارد شود و یک مشارکت معتبر انجام دهد، دعوت‌کننده این امتیاز را می‌گیرد.', 'manahel-rewards'),
            ),
            'MANAHEL_REFERRAL_QUALIFIED_REFERRED' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('تکمیل شرایط دعوت', 'manahel-rewards'),
                'description'=>__('پس از معتبر شدن دعوت، این هدیه یک‌بار برای کاربر جدیدی که از طریق لینک دعوت آمده است ثبت می‌شود.', 'manahel-rewards'),
            ),
            'MANAHEL_REFERRAL_CAMPAIGN_PARTICIPANT' => array(
                'title'=>self::rule_display_title($rule),
                'short_action'=>__('جذب یک مشارکت‌کننده به پویش', 'manahel-rewards'),
                'description'=>__('اگر شخصی از لینک اختصاصی یک پویش وارد شود و در همان پویش مشارکت واقعی انجام دهد، معرف این امتیاز را می‌گیرد. کاربر جدیدی که همزمان دعوت اصلی را کامل می‌کند دوباره از این قانون امتیاز نمی‌سازد.', 'manahel-rewards'),
            ),
        );
        return $map[$code] ?? $default;
    }

    private static function rule_effect_summary($rule, $limits) {
        $points = is_object($rule) ? (int)($rule->points ?? 0) : 0;
        $presentation = self::rule_presentation($rule);
        $parts = array(sprintf(__('هر بار %1$s: %2$d امتیاز', 'manahel-rewards'), $presentation['short_action'], $points));
        $daily_awards = (int)($limits['max_awards_per_day'] ?? 0);
        $daily_points = (int)($limits['max_points_per_day'] ?? 0);
        $monthly_awards = (int)($limits['max_awards_per_month'] ?? 0);
        $monthly_points = (int)($limits['max_points_per_month'] ?? 0);
        if ($daily_awards > 0) { $parts[] = sprintf(__('حداکثر %d بار در روز', 'manahel-rewards'), $daily_awards); }
        if ($daily_points > 0) { $parts[] = sprintf(__('حداکثر %d امتیاز در روز', 'manahel-rewards'), $daily_points); }
        if ($monthly_awards > 0) { $parts[] = sprintf(__('حداکثر %d بار در ماه', 'manahel-rewards'), $monthly_awards); }
        if ($monthly_points > 0) { $parts[] = sprintf(__('حداکثر %d امتیاز در ماه', 'manahel-rewards'), $monthly_points); }
        if (!empty($limits['cooldown_seconds'])) { $parts[] = sprintf(__('فاصله دریافت مجدد: %s', 'manahel-rewards'), self::human_duration((int)$limits['cooldown_seconds'])); }
        if (1 === count($parts)) { $parts[] = __('بدون سقف روزانه یا ماهانه', 'manahel-rewards'); }
        return implode('؛ ', $parts) . '.';
    }

    private static function limit_chips_html($limits, $rule) {
        $chips = array();
        if (!empty($limits['unique_per_entity'])) { $chips[] = __('هر فعالیت مشخص فقط یک‌بار', 'manahel-rewards'); }
        if (!empty($limits['max_awards_per_day'])) { $chips[] = sprintf(__('روزانه تا %d بار', 'manahel-rewards'), (int)$limits['max_awards_per_day']); }
        if (!empty($limits['max_points_per_day'])) { $chips[] = sprintf(__('روزانه تا %d امتیاز', 'manahel-rewards'), (int)$limits['max_points_per_day']); }
        if (!empty($limits['max_awards_per_month'])) { $chips[] = sprintf(__('ماهانه تا %d بار', 'manahel-rewards'), (int)$limits['max_awards_per_month']); }
        if (!empty($limits['max_points_per_month'])) { $chips[] = sprintf(__('ماهانه تا %d امتیاز', 'manahel-rewards'), (int)$limits['max_points_per_month']); }
        if (!empty($limits['cooldown_seconds'])) { $chips[] = sprintf(__('فاصله: %s', 'manahel-rewards'), self::human_duration((int)$limits['cooldown_seconds'])); }
        if (is_object($rule) && (!empty($rule->starts_at) || !empty($rule->ends_at))) { $chips[] = __('دارای بازه زمانی', 'manahel-rewards'); }
        if (!$chips) { $chips[] = __('بدون محدودیت اضافی', 'manahel-rewards'); }
        return implode('', array_map(static function($label){ return '<span class="mr-limit-chip">' . esc_html($label) . '</span>'; }, $chips));
    }

    private static function human_duration($seconds) {
        $seconds = max(0, (int)$seconds);
        if (0 === $seconds) { return __('بدون فاصله', 'manahel-rewards'); }
        if (0 === $seconds % 86400) { return sprintf(__('%d روز', 'manahel-rewards'), (int)($seconds / 86400)); }
        if (0 === $seconds % 3600) { return sprintf(__('%d ساعت', 'manahel-rewards'), (int)($seconds / 3600)); }
        if (0 === $seconds % 60) { return sprintf(__('%d دقیقه', 'manahel-rewards'), (int)($seconds / 60)); }
        return sprintf(__('%d ثانیه', 'manahel-rewards'), $seconds);
    }

    private static function cooldown_ui_parts($seconds) {
        $seconds = max(0, (int)$seconds);
        $presets = array(0=>'none', 3600=>'hour', 86400=>'day', 604800=>'week', 2592000=>'month');
        if (isset($presets[$seconds])) { return array('preset'=>$presets[$seconds], 'value'=>1, 'unit'=>'hours'); }
        if ($seconds > 0 && 0 === $seconds % 86400) { return array('preset'=>'custom', 'value'=>(int)($seconds/86400), 'unit'=>'days'); }
        if ($seconds > 0 && 0 === $seconds % 3600) { return array('preset'=>'custom', 'value'=>(int)($seconds/3600), 'unit'=>'hours'); }
        if ($seconds > 0 && 0 === $seconds % 60) { return array('preset'=>'custom', 'value'=>(int)($seconds/60), 'unit'=>'minutes'); }
        return array('preset'=>'custom', 'value'=>max(1,$seconds), 'unit'=>'seconds');
    }

    private static function decision_label($code, $points, $fallback) {
        if ($points > 0) { return sprintf(__('پاداش داده شد: +%d', 'manahel-rewards'), $points); }
        $code = $code ?: $fallback;
        $map = array(
            'policy_excluded:dhikr' => __('مشارکت ذکر مشمول امتیاز نیست.', 'manahel-rewards'),
            'no_matching_rule' => __('قانون امتیاز منطبق وجود ندارد.', 'manahel-rewards'),
            'limit.unique_entity' => __('این مشارکت قبلاً برای این قانون امتیاز گرفته است.', 'manahel-rewards'),
            'limit.cooldown' => __('فاصله زمانی لازم برای پاداش بعدی هنوز تکمیل نشده است.', 'manahel-rewards'),
            'limit.daily_awards' => __('سقف دفعات امتیاز روزانه این قانون تکمیل شده است.', 'manahel-rewards'),
            'limit.daily_points' => __('سقف امتیاز روزانه این قانون تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_awards' => __('سقف دفعات امتیاز ماهانه این قانون تکمیل شده است.', 'manahel-rewards'),
            'limit.monthly_points' => __('سقف امتیاز ماهانه این قانون تکمیل شده است.', 'manahel-rewards'),
            'idempotent_existing_award' => __('این پاداش قبلاً ثبت شده است.', 'manahel-rewards'),
            'duplicate_event' => __('این رویداد قبلاً دریافت شده است.', 'manahel-rewards'),
            'account_resolution_failed' => __('حساب امتیاز کاربر قابل تشخیص نبود.', 'manahel-rewards'),
            'identity_unverified' => __('این رویداد با سیاست قدیمی پیش از نسخه ۰.۷ پردازش شده و به‌دلیل عدم تأیید هویت امتیاز نگرفته است.', 'manahel-rewards'),
            'identity_unresolved' => __('هویت معتبر هم‌نور برای دریافت امتیاز قابل تشخیص نبود.', 'manahel-rewards'),
            'processing_failed' => __('پردازش پاداش ناموفق بود.', 'manahel-rewards'),
        );
        return $map[$code] ?? ($code ?: '—');
    }

    private static function redirect($page,$notice,$extra=array()) {
        $args = array_merge(array('page'=>$page,'mr_notice'=>$notice), is_array($extra) ? $extra : array());
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
    }

    public static function render_catalog() {
        self::guard(); global $wpdb;
        $table=Manahel_Rewards_DB::table('catalog');
        $edit_id=isset($_GET['edit_reward'])?(int)$_GET['edit_reward']:0;
        $edit=$edit_id?Manahel_Rewards_Redemption_Service::get_catalog_item($edit_id,false):null;
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC,id DESC");
        ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('جوایز قابل دریافت','manahel-rewards'); ?></h1><?php self::notice(); ?>
        <p><?php esc_html_e('اینجا مشخص می‌کنید کاربر امتیازش را به چه چیزهایی تبدیل کند. برای کد/شارژ/ووچر، کدها را از قبل وارد کنید تا پس از درخواست کاربر همان لحظه و بدون تأیید مدیر تحویل شوند. تأیید هویت برای هر جایزه جداگانه و اختیاری است.','manahel-rewards'); ?></p>
        <div class="mr-admin-grid">
          <section class="mr-admin-panel"><h2><?php echo $edit?esc_html__('ویرایش جایزه','manahel-rewards'):esc_html__('افزودن جایزه','manahel-rewards'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo $edit?'manahel_rewards_update_reward':'manahel_rewards_save_reward'; ?>">
            <?php if($edit): ?><input type="hidden" name="reward_id" value="<?php echo (int)$edit->id; ?>"><?php endif; ?>
            <?php wp_nonce_field($edit?'manahel_rewards_update_reward_'.$edit->id:'manahel_rewards_save_reward'); ?>
            <table class="form-table"><tbody>
              <?php if(!$edit): ?><tr><th><label><?php esc_html_e('کد داخلی','manahel-rewards'); ?></label></th><td><input name="code" class="regular-text" required placeholder="INTERNET_5GB"></td></tr><?php endif; ?>
              <tr><th><label><?php esc_html_e('عنوان جایزه','manahel-rewards'); ?></label></th><td><input name="title" class="regular-text" required maxlength="190" value="<?php echo esc_attr($edit?(string)$edit->title:''); ?>"></td></tr>
              <tr><th><label><?php esc_html_e('توضیح کوتاه','manahel-rewards'); ?></label></th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea($edit?(string)$edit->description:''); ?></textarea></td></tr>
              <tr><th><?php esc_html_e('هزینه','manahel-rewards'); ?></th><td><input type="number" min="1" step="1" name="points_cost" required value="<?php echo esc_attr($edit?(int)$edit->points_cost:''); ?>"> <?php esc_html_e('امتیاز','manahel-rewards'); ?></td></tr>
              <tr><th><?php esc_html_e('روش تحویل','manahel-rewards'); ?></th><td><select name="fulfillment_mode"><option value="manual" <?php selected($edit?(string)$edit->fulfillment_mode:'manual','manual'); ?>><?php esc_html_e('تحویل دستی','manahel-rewards'); ?></option><option value="code" <?php selected($edit?(string)$edit->fulfillment_mode:'','code'); ?>><?php esc_html_e('کد / شارژ / ووچر — تحویل خودکار','manahel-rewards'); ?></option><option value="physical" <?php selected($edit?(string)$edit->fulfillment_mode:'','physical'); ?>><?php esc_html_e('محصول فیزیکی','manahel-rewards'); ?></option></select></td></tr>
              <tr><th><?php esc_html_e('افزودن کدهای جدید','manahel-rewards'); ?></th><td><textarea name="codes_bulk" rows="7" class="large-text code" placeholder="OFF-ABC123&#10;OFF-DEF456&#10;OFF-GHI789"></textarea><p class="description"><strong><?php esc_html_e('این باکس فقط برای افزودن کد جدید است؛ کدهای قبلی پاک نمی‌شوند و عمداً دوباره داخل این باکس قرار نمی‌گیرند.','manahel-rewards'); ?></strong> <?php esc_html_e('هر کد را در یک خط وارد کنید. کد تکراری دوباره ذخیره نمی‌شود.','manahel-rewards'); ?></p><?php if($edit && 'code'===(string)$edit->fulfillment_mode && class_exists('Manahel_Rewards_Code_Inventory')): $cs=Manahel_Rewards_Code_Inventory::stats((int)$edit->id); ?><div class="notice notice-info inline" style="margin:10px 0 0"><p><strong><?php esc_html_e('وضعیت مخزن کد:','manahel-rewards'); ?></strong> <?php printf(esc_html__('آماده: %1$s | تحویل‌شده: %2$s | کل: %3$s','manahel-rewards'),esc_html(number_format_i18n((int)$cs['available'])),esc_html(number_format_i18n((int)$cs['assigned'])),esc_html(number_format_i18n((int)$cs['total']))); ?></p></div><?php endif; ?></td></tr>
              <tr><th><?php esc_html_e('موجودی کل','manahel-rewards'); ?></th><td><input type="number" min="0" name="stock_total" value="<?php echo esc_attr($edit?(int)$edit->stock_total:0); ?>"> <small><?php esc_html_e('برای تحویل دستی/فیزیکی؛ در حالت کد، موجودی از مخزن کدها محاسبه می‌شود.','manahel-rewards'); ?></small></td></tr>
              <tr><th><?php esc_html_e('سقف هر کاربر','manahel-rewards'); ?></th><td><input type="number" min="0" name="max_per_user" value="<?php echo esc_attr($edit?(int)$edit->max_per_user:0); ?>"> <small><?php esc_html_e('۰ = نامحدود','manahel-rewards'); ?></small></td></tr>
              <tr><th><?php esc_html_e('اطلاعات لازم از کاربر','manahel-rewards'); ?></th><td><input name="note_label" class="regular-text" value="<?php echo esc_attr($edit?(string)$edit->note_label:''); ?>" placeholder="مثلاً شماره موبایل برای شارژ یا نشانی پستی"><p class="description"><?php esc_html_e('اگر خالی باشد، کاربر چیزی وارد نمی‌کند. اگر پر شود، این فیلد هنگام دریافت جایزه اجباری می‌شود.','manahel-rewards'); ?></p></td></tr>
              <tr><th><?php esc_html_e('تأیید هویت','manahel-rewards'); ?></th><td><label><input type="checkbox" name="requires_verification" value="1" <?php checked($edit?!empty($edit->requires_verification):false); ?>> <?php esc_html_e('فقط برای این جایزه لازم باشد','manahel-rewards'); ?></label></td></tr>
              <tr><th><?php esc_html_e('ترتیب نمایش','manahel-rewards'); ?></th><td><input type="number" name="sort_order" value="<?php echo esc_attr($edit?(int)$edit->sort_order:10); ?>"></td></tr>
              <tr><th><?php esc_html_e('وضعیت','manahel-rewards'); ?></th><td><label><input type="checkbox" name="active" value="1" <?php checked($edit?'active'===(string)$edit->status:false); ?>> <?php esc_html_e('برای کاربران فعال باشد','manahel-rewards'); ?></label></td></tr>
            </tbody></table>
            <?php submit_button($edit?__('ذخیره تغییرات','manahel-rewards'):__('افزودن جایزه','manahel-rewards')); ?>
          </form>
          <?php if($edit && 'code'===(string)$edit->fulfillment_mode && class_exists('Manahel_Rewards_Code_Inventory')): $inventory_rows=Manahel_Rewards_Code_Inventory::list_codes((int)$edit->id,100); ?>
            <hr style="margin:20px 0">
            <h3><?php esc_html_e('مخزن کدهای این جایزه','manahel-rewards'); ?></h3>
            <p class="description"><?php esc_html_e('کدهای قبلی اینجا نگهداری می‌شوند. «آماده» یعنی هنوز به کاربری تحویل نشده و «تحویل‌شده» یعنی قبلاً تخصیص یافته است.','manahel-rewards'); ?></p>
            <div style="max-height:320px;overflow:auto"><table class="widefat striped"><thead><tr><th><?php esc_html_e('کد','manahel-rewards'); ?></th><th><?php esc_html_e('وضعیت','manahel-rewards'); ?></th><th><?php esc_html_e('زمان','manahel-rewards'); ?></th></tr></thead><tbody>
            <?php if(!$inventory_rows): ?><tr><td colspan="3"><?php esc_html_e('هنوز کدی برای این جایزه ثبت نشده است.','manahel-rewards'); ?></td></tr><?php endif; ?>
            <?php foreach((array)$inventory_rows as $code_row): ?><tr><td><code><?php echo esc_html((string)$code_row->code_value); ?></code></td><td><?php echo 'available'===(string)$code_row->status?'<strong style="color:#008a20">'.esc_html__('آماده','manahel-rewards').'</strong>':('assigned'===(string)$code_row->status?esc_html__('تحویل‌شده','manahel-rewards'):esc_html__('باطل','manahel-rewards')); ?><?php if(!empty($code_row->redemption_id)): ?> <small>#<?php echo (int)$code_row->redemption_id; ?></small><?php endif; ?></td><td><?php echo esc_html((string)('assigned'===(string)$code_row->status && $code_row->assigned_at?$code_row->assigned_at:$code_row->created_at)); ?> UTC</td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php if((int)$cs['total']>count((array)$inventory_rows)): ?><p class="description"><?php printf(esc_html__('در این صفحه آخرین %s کد نمایش داده می‌شود.','manahel-rewards'),esc_html(number_format_i18n(count((array)$inventory_rows)))); ?></p><?php endif; ?>
          <?php endif; ?>
          </section>
          <section class="mr-admin-panel"><h2><?php esc_html_e('جوایز فعلی','manahel-rewards'); ?></h2>
          <table class="widefat striped"><thead><tr><th><?php esc_html_e('جایزه','manahel-rewards'); ?></th><th><?php esc_html_e('هزینه','manahel-rewards'); ?></th><th><?php esc_html_e('موجودی','manahel-rewards'); ?></th><th><?php esc_html_e('وضعیت','manahel-rewards'); ?></th><th></th></tr></thead><tbody>
          <?php if(!$rows): ?><tr><td colspan="5"><?php esc_html_e('هنوز جایزه‌ای تعریف نشده است.','manahel-rewards'); ?></td></tr><?php endif; ?>
          <?php foreach((array)$rows as $row): $code_stats=('code'===(string)$row->fulfillment_mode && class_exists('Manahel_Rewards_Code_Inventory'))?Manahel_Rewards_Code_Inventory::stats((int)$row->id):null; ?><tr><td><strong><?php echo esc_html((string)$row->title); ?></strong><br><code><?php echo esc_html((string)$row->code); ?></code><?php if($code_stats): ?><br><small><?php esc_html_e('تحویل خودکار','manahel-rewards'); ?></small><?php endif; ?></td><td><?php echo esc_html(number_format_i18n((int)$row->points_cost)); ?></td><td><?php echo $code_stats?esc_html(number_format_i18n((int)$code_stats['available'])):((int)$row->stock_total>0?esc_html(number_format_i18n(max(0,(int)$row->stock_total-(int)$row->stock_used))):esc_html__('نامحدود','manahel-rewards')); ?></td><td><?php echo 'active'===(string)$row->status?esc_html__('فعال','manahel-rewards'):esc_html__('غیرفعال','manahel-rewards'); ?></td><td><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'manahel-rewards-catalog','edit_reward'=>(int)$row->id),admin_url('admin.php'))); ?>"><?php esc_html_e('ویرایش','manahel-rewards'); ?></a></td></tr><?php endforeach; ?>
          </tbody></table></section>
        </div></div><?php
    }

    public static function render_redemptions() {
        self::guard(); global $wpdb;
        $r=Manahel_Rewards_DB::table('redemptions'); $c=Manahel_Rewards_DB::table('catalog');
        $rows=$wpdb->get_results("SELECT x.*,c.title reward_title,c.fulfillment_mode FROM {$r} x INNER JOIN {$c} c ON c.id=x.reward_id ORDER BY x.id DESC LIMIT 300");
        ?><div class="wrap" dir="rtl"><h1><?php esc_html_e('درخواست‌های جایزه','manahel-rewards'); ?></h1><?php self::notice(); ?>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('کاربر','manahel-rewards'); ?></th><th><?php esc_html_e('جایزه','manahel-rewards'); ?></th><th><?php esc_html_e('امتیاز','manahel-rewards'); ?></th><th><?php esc_html_e('اطلاعات کاربر','manahel-rewards'); ?></th><th><?php esc_html_e('وضعیت / تحویل','manahel-rewards'); ?></th><th><?php esc_html_e('زمان','manahel-rewards'); ?></th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="7"><?php esc_html_e('هنوز درخواست جایزه‌ای ثبت نشده است.','manahel-rewards'); ?></td></tr><?php endif; ?>
        <?php foreach((array)$rows as $row): $user=get_user_by('id',(int)$row->wp_user_id); ?><tr>
          <td><?php echo (int)$row->id; ?><br><small><?php echo esc_html((string)$row->public_id); ?></small></td>
          <td><?php echo esc_html($user?$user->display_name:('#'.(int)$row->wp_user_id)); ?><br><small>#<?php echo (int)$row->wp_user_id; ?></small></td>
          <td><strong><?php echo esc_html((string)$row->reward_title); ?></strong></td>
          <td>-<?php echo esc_html(number_format_i18n((int)$row->points_cost)); ?></td>
          <td><?php echo $row->user_note?nl2br(esc_html((string)$row->user_note)):'—'; ?></td>
          <td><strong><?php echo esc_html((string)$row->status); ?></strong>
          <?php if('pending'===(string)$row->status): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-inline-admin-form"><input type="hidden" name="action" value="manahel_rewards_fulfill_redemption"><input type="hidden" name="redemption_id" value="<?php echo (int)$row->id; ?>"><?php wp_nonce_field('manahel_rewards_fulfill_redemption_'.$row->id); ?><textarea name="fulfillment_value" rows="2" placeholder="<?php echo esc_attr__('کد، شماره پیگیری یا توضیح تحویل','manahel-rewards'); ?>"></textarea><button class="button button-primary"><?php esc_html_e('ثبت تحویل','manahel-rewards'); ?></button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-inline-admin-form"><input type="hidden" name="action" value="manahel_rewards_refund_redemption"><input type="hidden" name="redemption_id" value="<?php echo (int)$row->id; ?>"><?php wp_nonce_field('manahel_rewards_refund_redemption_'.$row->id); ?><input name="reason" placeholder="<?php echo esc_attr__('دلیل لغو','manahel-rewards'); ?>"><button class="button" onclick="return confirm('<?php echo esc_js(__('امتیاز به کاربر برگردد؟','manahel-rewards')); ?>');"><?php esc_html_e('لغو و بازگشت امتیاز','manahel-rewards'); ?></button></form>
          <?php elseif('fulfilled'===(string)$row->status): ?><div><?php echo nl2br(esc_html((string)$row->fulfillment_value)); ?></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mr-inline-admin-form"><input type="hidden" name="action" value="manahel_rewards_refund_redemption"><input type="hidden" name="redemption_id" value="<?php echo (int)$row->id; ?>"><?php wp_nonce_field('manahel_rewards_refund_redemption_'.$row->id); ?><input name="reason" placeholder="<?php echo esc_attr__('دلیل بازپرداخت','manahel-rewards'); ?>"><button class="button"><?php esc_html_e('بازگشت امتیاز','manahel-rewards'); ?></button></form>
          <?php endif; ?></td>
          <td><?php echo esc_html((string)$row->created_at); ?> UTC</td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php
    }

    public static function handle_save_reward() {
        self::guard(); check_admin_referer('manahel_rewards_save_reward');
        $result=Manahel_Rewards_Redemption_Service::create_catalog_item(self::reward_form_data());
        if (is_wp_error($result)) { self::redirect('manahel-rewards-catalog','error'); }
        $bulk=isset($_POST['codes_bulk'])?wp_unslash($_POST['codes_bulk']):'';
        if (trim((string)$bulk)!=='' && class_exists('Manahel_Rewards_Code_Inventory')) {
            $imp=Manahel_Rewards_Code_Inventory::import_codes((int)$result,$bulk);
            if (is_wp_error($imp)) { self::redirect('manahel-rewards-catalog','error'); }
            $extra=array('mr_codes_added'=>(int)$imp['added'],'mr_codes_duplicates'=>(int)$imp['duplicates'],'mr_codes_invalid'=>(int)$imp['invalid'],'mr_codes_available'=>(int)$imp['stats']['available']);
            self::redirect('manahel-rewards-catalog',!empty($imp['added'])?'reward_saved_codes':'reward_codes_none_added',$extra);
        }
        self::redirect('manahel-rewards-catalog','reward_saved');
    }

    public static function handle_update_reward() {
        self::guard(); $id=isset($_POST['reward_id'])?(int)$_POST['reward_id']:0; check_admin_referer('manahel_rewards_update_reward_'.$id);
        $result=Manahel_Rewards_Redemption_Service::update_catalog_item($id,self::reward_form_data());
        if (is_wp_error($result)) { self::redirect('manahel-rewards-catalog','error'); }
        $bulk=isset($_POST['codes_bulk'])?wp_unslash($_POST['codes_bulk']):'';
        if (trim((string)$bulk)!=='' && class_exists('Manahel_Rewards_Code_Inventory')) {
            $imp=Manahel_Rewards_Code_Inventory::import_codes($id,$bulk);
            if (is_wp_error($imp)) { self::redirect('manahel-rewards-catalog','error'); }
            $extra=array('edit_reward'=>$id,'mr_codes_added'=>(int)$imp['added'],'mr_codes_duplicates'=>(int)$imp['duplicates'],'mr_codes_invalid'=>(int)$imp['invalid'],'mr_codes_available'=>(int)$imp['stats']['available']);
            self::redirect('manahel-rewards-catalog',!empty($imp['added'])?'reward_updated_codes':'reward_codes_none_added',$extra);
        }
        self::redirect('manahel-rewards-catalog','reward_updated',array('edit_reward'=>$id));
    }

    private static function reward_form_data() {
        return array(
            'code'=>isset($_POST['code'])?wp_unslash($_POST['code']):'',
            'title'=>isset($_POST['title'])?wp_unslash($_POST['title']):'',
            'description'=>isset($_POST['description'])?wp_unslash($_POST['description']):'',
            'points_cost'=>isset($_POST['points_cost'])?(int)$_POST['points_cost']:0,
            'fulfillment_mode'=>isset($_POST['fulfillment_mode'])?wp_unslash($_POST['fulfillment_mode']):'manual',
            'stock_total'=>isset($_POST['stock_total'])?(int)$_POST['stock_total']:0,
            'max_per_user'=>isset($_POST['max_per_user'])?(int)$_POST['max_per_user']:0,
            'note_label'=>isset($_POST['note_label'])?wp_unslash($_POST['note_label']):'',
            'requires_verification'=>!empty($_POST['requires_verification']),
            'sort_order'=>isset($_POST['sort_order'])?(int)$_POST['sort_order']:10,
            'active'=>!empty($_POST['active']),
        );
    }

    public static function handle_fulfill_redemption() {
        self::guard(); $id=isset($_POST['redemption_id'])?(int)$_POST['redemption_id']:0; check_admin_referer('manahel_rewards_fulfill_redemption_'.$id);
        $value=isset($_POST['fulfillment_value'])?wp_unslash($_POST['fulfillment_value']):'';
        $result=Manahel_Rewards_Redemption_Service::fulfill($id,$value,'',get_current_user_id());
        self::redirect('manahel-rewards-redemptions',is_wp_error($result)?'error':'redemption_fulfilled');
    }

    public static function handle_refund_redemption() {
        self::guard(); $id=isset($_POST['redemption_id'])?(int)$_POST['redemption_id']:0; check_admin_referer('manahel_rewards_refund_redemption_'.$id);
        $reason=isset($_POST['reason'])?wp_unslash($_POST['reason']):'';
        $result=Manahel_Rewards_Redemption_Service::refund($id,$reason,get_current_user_id());
        self::redirect('manahel-rewards-redemptions',is_wp_error($result)?'error':'redemption_refunded');
    }

}
