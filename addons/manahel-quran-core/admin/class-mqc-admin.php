<?php
if (!defined('ABSPATH')) { exit; }

final class MQC_Admin {
    public static function register() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_mqc_import_batch', array(__CLASS__, 'ajax_import'));
        add_action('wp_ajax_mqc_reset_import', array(__CLASS__, 'ajax_reset'));
        add_action('wp_ajax_mqc_test_verse', array(__CLASS__, 'ajax_test'));
    }
    public static function menu() {
        add_management_page('هسته قرآن مناهل','هسته قرآن مناهل','manage_options','manahel-quran-core',array(__CLASS__,'page'));
    }
    public static function assets($hook) {
        if ('tools_page_manahel-quran-core' !== $hook) { return; }
        wp_enqueue_script('mqc-admin', MQC_URL.'assets/admin.js', array('jquery'), MQC_VERSION, true);
        wp_localize_script('mqc-admin','MQCAdmin',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('mqc_admin'),'batch'=>250));
        wp_enqueue_style('mqc-admin', MQC_URL.'assets/admin.css', array(), MQC_VERSION);
    }
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s=MQC_Installer::status();
        ?>
        <div class="wrap mqc-wrap" dir="rtl">
          <h1>هسته قرآن مناهل</h1>
          <p>این افزونه فقط هسته داده و API قرآن را فراهم می‌کند و هیچ برگه عمومی ایجاد نمی‌کند.</p>
          <div class="mqc-grid">
            <div class="mqc-card"><strong id="mqc-verses"><?php echo esc_html($s['verses']); ?></strong><span>آیه واردشده از ۶۲۳۶</span></div>
            <div class="mqc-card"><strong><?php echo esc_html($s['surahs']); ?></strong><span>سوره از ۱۱۴</span></div>
            <div class="mqc-card"><strong><?php echo esc_html($s['divisions']); ?></strong><span>رکورد تقسیم‌بندی</span></div>
          </div>
          <div class="mqc-panel">
            <h2>واردسازی متن و ترجمه</h2>
            <div class="mqc-progress"><i id="mqc-bar" style="width:<?php echo esc_attr(min(100,($s['verses']/6236)*100)); ?>%"></i></div>
            <p id="mqc-message"><?php echo $s['verses']>=6236 ? 'واردسازی کامل است.' : 'برای آغاز یا ادامه واردسازی دکمه زیر را بزنید.'; ?></p>
            <button class="button button-primary" id="mqc-import" <?php disabled($s['verses']>=6236); ?>>شروع/ادامه واردسازی</button>
            <button class="button" id="mqc-reset">بازنشانی داده آزمایشی</button>
          </div>
          <div class="mqc-panel">
            <h2>آزمایش یک آیه</h2>
            <label>سوره <input id="mqc-test-surah" type="number" min="1" max="114" value="2"></label>
            <label>آیه <input id="mqc-test-ayah" type="number" min="1" value="255"></label>
            <button class="button" id="mqc-test">نمایش</button>
            <div id="mqc-test-result"></div>
          </div>
          <div class="mqc-panel">
            <h2>نمونه API</h2>
            <code><?php echo esc_html(rest_url('manahel-quran/v1/verse/2/255')); ?></code>
          </div>
        </div>
        <?php
    }
    private static function guard() { check_ajax_referer('mqc_admin','nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>'دسترسی غیرمجاز.'),403); } }
    public static function ajax_import() {
        self::guard();
        $offset=(int)get_option('mqc_import_offset',0);
        $result=MQC_Installer::import_verses_batch($offset, isset($_POST['limit'])?absint($_POST['limit']):250);
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message())); }
        wp_send_json_success($result);
    }
    public static function ajax_reset() { self::guard(); MQC_Installer::reset_verse_import(); wp_send_json_success(array('message'=>'داده‌های آزمایشی پاک شد.')); }
    public static function ajax_test() {
        self::guard();
        $v=manahel_quran_core()->get_verse(absint($_POST['surah']??0),absint($_POST['ayah']??0));
        if (!$v) { wp_send_json_error(array('message'=>'آیه پیدا نشد.')); }
        wp_send_json_success($v);
    }
}
