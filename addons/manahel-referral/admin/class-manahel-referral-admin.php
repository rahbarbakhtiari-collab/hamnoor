<?php
if (!defined('ABSPATH')) { exit; }

final class Manahel_Referral_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_manahel_referral_save_settings', array(__CLASS__, 'save_settings'));
    }

    public static function menu() {
        add_menu_page(__('Manahel Referral', 'manahel-referral'), __('دعوت مناهل', 'manahel-referral'), MANAHEL_REFERRAL_CAP, 'manahel-referral', array(__CLASS__, 'render_overview'), 'dashicons-networking', 59);
        add_submenu_page('manahel-referral', __('انتساب‌ها', 'manahel-referral'), __('انتساب‌ها', 'manahel-referral'), MANAHEL_REFERRAL_CAP, 'manahel-referral-attributions', array(__CLASS__, 'render_attributions'));
        add_submenu_page('manahel-referral', __('رویدادها', 'manahel-referral'), __('رویدادها', 'manahel-referral'), MANAHEL_REFERRAL_CAP, 'manahel-referral-events', array(__CLASS__, 'render_events'));
    }

    private static function guard() { if (!current_user_can(MANAHEL_REFERRAL_CAP)) { wp_die(esc_html__('دسترسی کافی ندارید.', 'manahel-referral')); } }

    public static function save_settings() {
        self::guard();
        check_admin_referer('manahel_referral_save_settings');
        $page_id = isset($_POST['my_referrals_page_id']) ? absint(wp_unslash($_POST['my_referrals_page_id'])) : 0;
        if ($page_id > 0 && ('page' !== get_post_type($page_id) || 'publish' !== get_post_status($page_id))) {
            $page_id = 0;
        }
        update_option(Manahel_Referral_Frontend::OPTION_MY_REFERRALS_PAGE_ID, $page_id, false);
        wp_safe_redirect(add_query_arg(array('page'=>'manahel-referral','settings-updated'=>'1'), admin_url('admin.php')));
        exit;
    }

    public static function render_overview() {
        self::guard(); global $wpdb;
        $codes = Manahel_Referral_DB::table('codes'); $attr = Manahel_Referral_DB::table('attributions'); $events = Manahel_Referral_DB::table('events');
        $counts = array(
            'codes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$codes}"),
            'attributed'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$attr} WHERE status='attributed'"),
            'qualified'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$attr} WHERE status='qualified'"),
            'expired'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$attr} WHERE status='expired'"),
            'events'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$events}"),
        ); ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('دعوت مناهل', 'manahel-referral'); ?></h1>
        <p><strong><?php echo esc_html(MANAHEL_REFERRAL_VERSION); ?></strong> — DB <?php echo esc_html(MANAHEL_REFERRAL_DB_VERSION); ?></p>
        <p><?php printf(esc_html__('کدها: %1$d — منتظر احراز: %2$d — احراز‌شده: %3$d — منقضی: %4$d — رویدادها: %5$d', 'manahel-referral'), $counts['codes'],$counts['attributed'],$counts['qualified'],$counts['expired'],$counts['events']); ?></p>
        <h2><?php esc_html_e('قرارداد نسخه ۱', 'manahel-referral'); ?></h2>
        <p><?php esc_html_e('کلیک امتیاز ندارد. انتساب فقط هنگام ایجاد هویت جدید هم‌نور تثبیت می‌شود و نخستین تکمیل معتبر قرآن یا دعا در بازه ۳۰ روزه، دعوت را Qualified می‌کند.', 'manahel-referral'); ?></p>
        <p><code>[manahel_referral_invite]</code> &nbsp; <code>[manahel_referral_my_referrals]</code></p>
        <?php if (!empty($_GET['settings-updated'])) : ?><div class="notice notice-success inline"><p><?php esc_html_e('تنظیمات ذخیره شد.', 'manahel-referral'); ?></p></div><?php endif; ?>
        <h2><?php esc_html_e('صفحه «دعوت‌های من»', 'manahel-referral'); ?></h2>
        <p><?php esc_html_e('صفحه منتشرشده‌ای را انتخاب کنید که شورت‌کد [manahel_referral_my_referrals] در آن قرار دارد. کارت دعوت در داشبورد هم‌نور به همین صفحه متصل می‌شود.', 'manahel-referral'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('manahel_referral_save_settings'); ?>
            <input type="hidden" name="action" value="manahel_referral_save_settings">
            <?php $selected = (int)get_option(Manahel_Referral_Frontend::OPTION_MY_REFERRALS_PAGE_ID, 0); ?>
            <?php wp_dropdown_pages(array('name'=>'my_referrals_page_id','selected'=>$selected,'show_option_none'=>__('— انتخاب نشده —','manahel-referral'),'option_none_value'=>0)); ?>
            <?php submit_button(__('ذخیره تنظیمات', 'manahel-referral'), 'primary', 'submit', false); ?>
        </form>
        </div><?php
    }

    public static function render_attributions() {
        self::guard(); global $wpdb; $table=Manahel_Referral_DB::table('attributions');
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200"); ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('انتساب‌های دعوت', 'manahel-referral'); ?></h1>
        <table class="widefat striped"><thead><tr><th>ID</th><th>Referral</th><th><?php esc_html_e('معرف', 'manahel-referral'); ?></th><th><?php esc_html_e('دعوت‌شده', 'manahel-referral'); ?></th><th><?php esc_html_e('وضعیت', 'manahel-referral'); ?></th><th><?php esc_html_e('انتساب', 'manahel-referral'); ?></th><th><?php esc_html_e('احراز', 'manahel-referral'); ?></th><th><?php esc_html_e('مبنای احراز', 'manahel-referral'); ?></th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="8"><?php esc_html_e('هنوز انتسابی ثبت نشده است.', 'manahel-referral'); ?></td></tr><?php endif; ?>
        <?php foreach((array)$rows as $r): ?><tr><td><?php echo (int)$r->id; ?></td><td><code><?php echo esc_html($r->public_id); ?></code></td><td>WP#<?php echo (int)$r->inviter_wp_user_id; ?></td><td>WP#<?php echo (int)$r->referred_wp_user_id; ?></td><td><strong><?php echo esc_html($r->status); ?></strong></td><td><?php echo esc_html($r->attributed_at); ?> UTC</td><td><?php echo $r->qualified_at?esc_html($r->qualified_at).' UTC':'—'; ?></td><td><code><?php echo esc_html($r->qualification_entity_type); ?></code><br><small><?php echo esc_html($r->qualification_entity_id); ?></small></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }

    public static function render_events() {
        self::guard(); global $wpdb; $table=Manahel_Referral_DB::table('events');
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200"); ?>
        <div class="wrap" dir="rtl"><h1><?php esc_html_e('رویدادهای دعوت', 'manahel-referral'); ?></h1>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('رویداد', 'manahel-referral'); ?></th><th>Entity</th><th><?php esc_html_e('زمان', 'manahel-referral'); ?></th><th><?php esc_html_e('Payload', 'manahel-referral'); ?></th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="5"><?php esc_html_e('رویدادی ثبت نشده است.', 'manahel-referral'); ?></td></tr><?php endif; ?>
        <?php foreach((array)$rows as $r): ?><tr><td><?php echo (int)$r->id; ?></td><td><code><?php echo esc_html($r->event_name); ?></code></td><td><code><?php echo esc_html($r->entity_id); ?></code></td><td><?php echo esc_html($r->occurred_at); ?> UTC</td><td><details><summary><?php esc_html_e('جزئیات', 'manahel-referral'); ?></summary><code style="white-space:pre-wrap"><?php echo esc_html($r->payload_json); ?></code></details></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }
}
