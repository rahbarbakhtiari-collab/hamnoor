<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Admin {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_hamnoor_save_settings', array($this, 'save_settings'));
        add_action('admin_post_hamnoor_save_texts', array($this, 'save_texts'));
        add_action('admin_post_hamnoor_reset_texts', array($this, 'reset_texts'));
        add_action('admin_post_hamnoor_admin_save_khatm', array($this, 'save_khatm'));
        add_action('admin_post_hamnoor_export_csv', array($this, 'export_csv'));
        add_action('admin_post_nopriv_hamnoor_export_csv', array($this, 'export_csv'));
        add_action('admin_post_hamnoor_export_xlsx', array($this, 'export_xlsx'));
        add_action('admin_post_nopriv_hamnoor_export_xlsx', array($this, 'export_xlsx'));
        add_action('admin_post_hamnoor_change_status', array($this, 'change_status'));
        add_action('admin_post_hamnoor_bulk_status', array($this, 'bulk_status'));
        add_action('admin_post_hamnoor_review_feature_request', array($this, 'review_feature_request'));
        add_action('admin_post_hamnoor_run_reminders_now', array($this, 'run_reminders_now'));
        add_action('admin_post_hamnoor_save_smart_reminder_settings', array($this, 'save_smart_reminder_settings'));
        add_action('admin_post_hamnoor_test_part_reminder', array($this, 'test_part_reminder'));
        add_action('admin_post_hamnoor_retry_notification_delivery', array($this, 'retry_notification_delivery'));
        add_action('admin_post_hamnoor_process_notification_queue', array($this, 'process_notification_queue_now'));
        add_action('admin_post_hamnoor_admin_participant_action', array($this, 'admin_participant_action'));
        add_action('admin_notices', array($this, 'health_notice'));
    }

    public function health_notice() {
        if (!current_user_can('manage_options')) { return; }
        $health = Hamnoor_DB::check_tables_health();
        if (empty($health['ok'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('هم‌نور: برخی جدول‌های ضروری افزونه ایجاد نشده‌اند. از صفحه سلامت افزونه وضعیت را بررسی کنید.', 'hamnoor') . ' <a href="' . esc_url(admin_url('admin.php?page=hamnoor-health')) . '">' . esc_html__('مشاهده سلامت افزونه', 'hamnoor') . '</a></p></div>';
        }
    }

    public function menu() {
        add_menu_page(__('هم‌نور', 'hamnoor'), __('هم‌نور', 'hamnoor'), 'manage_options', 'hamnoor', array($this, 'page_khatms'), 'dashicons-book-alt', 56);
        add_submenu_page('hamnoor', __('فهرست پویش‌ها', 'hamnoor'), __('فهرست پویش‌ها', 'hamnoor'), 'manage_options', 'hamnoor', array($this, 'page_khatms'));
        add_submenu_page('hamnoor', __('تنظیمات', 'hamnoor'), __('تنظیمات', 'hamnoor'), 'manage_options', 'hamnoor-settings', array($this, 'page_settings'));
        add_submenu_page('hamnoor', __('متن‌ها', 'hamnoor'), __('متن‌ها', 'hamnoor'), 'manage_options', 'hamnoor-texts', array($this, 'page_texts'));
        add_submenu_page('hamnoor', __('سلامت افزونه', 'hamnoor'), __('سلامت افزونه', 'hamnoor'), 'manage_options', 'hamnoor-health', array($this, 'page_health'));
        if (Hamnoor_Notifications::available()) {
            add_submenu_page('hamnoor', __('کانال‌های اعلان', 'hamnoor'), __('کانال‌های اعلان', 'hamnoor'), 'manage_options', 'hamnoor-notifications', array($this, 'page_notifications'));
        }
        add_submenu_page('hamnoor', __('مستندات', 'hamnoor'), __('مستندات', 'hamnoor'), 'manage_options', 'hamnoor-docs', array($this, 'page_docs'));
    }

    public function enqueue_assets($hook) {
        if (false === strpos((string) $hook, 'hamnoor')) {
            return;
        }
        wp_enqueue_style('hamnoor-admin', HAMNOOR_URL . 'assets/css/hamnoor-admin.css', array(), Hamnoor_Helpers::asset_version('assets/css/hamnoor-admin.css'));
        wp_enqueue_script('hamnoor-admin', HAMNOOR_URL . 'assets/js/hamnoor-admin.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-admin.js'), true);
    }

    public function page_khatms() {
        if (!current_user_can('manage_options')) { return; }
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
        $khatm_id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0;
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('هم‌نور: مدیریت پویش‌ها', 'hamnoor') . '</h1>' . Hamnoor_Helpers::current_notice();
        if ('edit' === $view && $khatm_id) { $this->render_edit($khatm_id); }
        elseif ('parts' === $view && $khatm_id) { $this->render_parts($khatm_id); }
        elseif ('participants' === $view && $khatm_id) { $this->render_participants($khatm_id); }
        elseif ('logs' === $view && $khatm_id) { $this->render_logs($khatm_id); }
        else { $this->render_list(); }
        echo '</div>';
    }

    private function filters() {
        return array(
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'privacy' => isset($_GET['privacy']) ? sanitize_key(wp_unslash($_GET['privacy'])) : '',
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'khatm_type' => isset($_GET['khatm_type']) ? sanitize_key(wp_unslash($_GET['khatm_type'])) : '',
            'featured_filter' => isset($_GET['featured_filter']) ? sanitize_key(wp_unslash($_GET['featured_filter'])) : '',
            'orderby' => isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'id',
            'order' => isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'DESC',
        );
    }

    private function render_list() {
        $filters = $this->filters();
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $settings = Hamnoor_Helpers::settings();
        $per_page = max(5, min(200, (int) $settings['admin_khatms_per_page']));
        $args = array_merge($filters, array('limit' => $per_page, 'offset' => ($paged - 1) * $per_page));
        $items = Hamnoor_DB::get_khatms($args);
        $total = Hamnoor_DB::count_khatms($filters);
        echo '<form method="get" class="hamnoor-admin-filters"><input type="hidden" name="page" value="hamnoor">';
        echo '<input type="search" name="s" value="' . esc_attr($filters['s']) . '" placeholder="' . esc_attr__('جستجو در عنوان، نیت، ایجادکننده و موبایل', 'hamnoor') . '">';
        $this->select('privacy', $filters['privacy'], array('' => 'همه دسترسی‌ها', 'public' => 'عمومی', 'private' => 'خصوصی'));
        $this->select('status', $filters['status'], array('' => 'همه وضعیت‌ها', 'pending_review' => 'در انتظار بررسی', 'needs_revision' => 'نیازمند اصلاح', 'rejected' => 'تأیید نشد', 'active' => 'فعال', 'paused' => 'توقف موقت', 'expired' => 'مهلت پایان‌یافته', 'completed' => 'تکمیل‌شده', 'cancelled' => 'لغوشده', 'archived' => 'آرشیو', 'failed' => 'خطای فنی'));
        $this->select('khatm_type', $filters['khatm_type'], array('' => 'همه انواع', 'page' => 'صفحه', 'juz' => 'جزء', 'surah' => 'سوره', 'repeated_surah' => 'تکراری سوره'));
        $this->select('featured_filter', $filters['featured_filter'], array('' => 'همه وضعیت‌های ویژه', 'normal' => 'عادی', 'featured' => 'ویژه', 'pinned' => 'پین‌شده', 'pending' => 'درخواست در انتظار'));
        submit_button(__('فیلتر', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-bulk-form" onsubmit="return window.confirm(&quot;' . esc_attr__('این عملیات وضعیت همه پویش‌های انتخاب‌شده را تغییر می‌دهد. از ادامه مطمئن هستید؟', 'hamnoor') . '&quot;);">';
        wp_nonce_field('hamnoor_bulk_status');
        echo '<input type="hidden" name="action" value="hamnoor_bulk_status"><select name="bulk_status"><option value="">' . esc_html__('تغییر وضعیت گروهی', 'hamnoor') . '</option><option value="active">' . esc_html__('انتشار / فعال‌سازی', 'hamnoor') . '</option><option value="paused">' . esc_html__('توقف موقت', 'hamnoor') . '</option><option value="cancelled">' . esc_html__('لغو', 'hamnoor') . '</option><option value="completed">' . esc_html__('تکمیل دستی', 'hamnoor') . '</option><option value="archived">' . esc_html__('آرشیو', 'hamnoor') . '</option></select> ';
        submit_button(__('اعمال', 'hamnoor'), 'secondary', 'submit', false);
        echo '<table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.hamnoor-row-check\').forEach(function(c){c.checked=event.target.checked;});"></th><th>' . esc_html__('عنوان', 'hamnoor') . '</th><th>' . esc_html__('ایجادکننده', 'hamnoor') . '</th><th>' . esc_html__('نوع', 'hamnoor') . '</th><th>' . esc_html__('دسترسی', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('ویژه بودن', 'hamnoor') . '</th><th>' . esc_html__('پیشرفت', 'hamnoor') . '</th><th>' . esc_html__('تاریخ', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody>';
        foreach ($items as $khatm) {
            $progress = Hamnoor_DB::get_progress((int) $khatm->id);
            $base = admin_url('admin.php?page=hamnoor&khatm_id=' . (int) $khatm->id);
            $feature_state = Hamnoor_Featured_Service::display_state($khatm);
            $request_status = Hamnoor_Featured_Service::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
            $feature_cell = '<span class="hamnoor-admin-featured-status hamnoor-admin-featured-status--' . esc_attr($feature_state) . '">' . esc_html(Hamnoor_Featured_Service::status_label($khatm)) . '</span>';
            if ('pending' === $request_status) {
                $approve_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_review_feature_request&khatm_id=' . (int) $khatm->id . '&decision=approve'), 'hamnoor_review_feature_request_' . (int) $khatm->id . '_approve');
                $reject_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_review_feature_request&khatm_id=' . (int) $khatm->id . '&decision=reject'), 'hamnoor_review_feature_request_' . (int) $khatm->id . '_reject');
                $feature_cell .= '<span class="hamnoor-admin-feature-request">' . esc_html__('درخواست ویژه شدن', 'hamnoor') . '</span><div class="row-actions"><a href="' . esc_url($approve_url) . '">' . esc_html__('تأیید', 'hamnoor') . '</a> | <a href="' . esc_url($reject_url) . '">' . esc_html__('رد', 'hamnoor') . '</a></div>';
            }
            echo '<tr><td><input class="hamnoor-row-check" type="checkbox" name="khatm_ids[]" value="' . (int) $khatm->id . '"></td><td><strong>' . esc_html($khatm->title) . '</strong><br><code>' . esc_html($khatm->token) . '</code></td><td>' . esc_html($khatm->owner_name) . '<br>' . esc_html($khatm->owner_phone) . '</td><td><span class="hamnoor-badge">' . esc_html(Hamnoor_Helpers::status_label($khatm->khatm_type, 'type')) . '</span></td><td><span class="hamnoor-badge">' . esc_html(Hamnoor_Helpers::status_label($khatm->privacy, 'privacy')) . '</span></td><td><span class="hamnoor-badge hamnoor-status-' . esc_attr($khatm->status) . '">' . esc_html(Hamnoor_Helpers::status_label($khatm->status, 'khatm')) . '</span></td><td>' . $feature_cell . '</td><td>' . esc_html($progress['percent']) . '%</td><td>' . esc_html(Hamnoor_Jalali::to_jalali($khatm->created_at, true)) . '</td><td><details class="hamnoor-admin-actions"><summary>' . esc_html__('عملیات', 'hamnoor') . '</summary>';
            echo '<a href="' . esc_url(Hamnoor_Helpers::khatm_url($khatm)) . '" target="_blank">' . esc_html__('مشاهده', 'hamnoor') . '</a> | ';
            echo '<a href="' . esc_url($base . '&view=edit') . '">' . esc_html__('ویرایش', 'hamnoor') . '</a> | ';
            echo '<a href="' . esc_url($base . '&view=parts') . '">' . esc_html__('سهم‌ها', 'hamnoor') . '</a> | ';
            echo '<a href="' . esc_url($base . '&view=participants') . '">' . esc_html__('شرکت‌کنندگان', 'hamnoor') . '</a> | ';
            echo '<a href="' . esc_url($base . '&view=logs') . '">' . esc_html__('لاگ', 'hamnoor') . '</a> | ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_csv&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)) . '">CSV</a> | ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_xlsx&khatm_id=' . (int) $khatm->id), 'hamnoor_export_' . (int) $khatm->id)) . '">XLSX</a> | ';
            foreach (array('active'=>('pending_review' === (string) $khatm->status ? __('انتشار','hamnoor') : __('فعال‌سازی','hamnoor')),'paused'=>__('توقف موقت','hamnoor'),'cancelled'=>__('لغو','hamnoor'),'completed'=>__('تکمیل دستی','hamnoor'),'archived'=>__('آرشیو','hamnoor')) as $st=>$label) {
                if ($st !== $khatm->status) {
                    $dangerous = in_array($st, array('paused','cancelled','completed','archived'), true);
                    $confirm = $dangerous ? ' onclick="return window.confirm(\'' . esc_js(sprintf(__('وضعیت پویش به «%s» تغییر کند؟ این تغییر می‌تواند بر مشارکت‌کنندگان اثر بگذارد.', 'hamnoor'), $label)) . '\');"' : '';
                    echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_change_status&khatm_id=' . (int) $khatm->id . '&status=' . $st), 'hamnoor_change_status_' . (int) $khatm->id)) . '"' . $confirm . '>' . esc_html($label) . '</a> ';
                }
            }
            echo '</details></td></tr>';
        }
        if (!$items) { echo '<tr><td colspan="10">' . esc_html__('پویشی یافت نشد.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table></form>';
        $pages = max(1, ceil($total / $per_page));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)) . '</div></div>';
        }
    }

    private function select($name, $current, $options) {
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function render_edit($khatm_id) {
        $khatm = Hamnoor_DB::get_khatm($khatm_id);
        if (!$khatm) { echo '<p>' . esc_html__('پویش پیدا نشد.', 'hamnoor') . '</p>'; return; }
        $feature_form = Hamnoor_Helpers::get_form_state('admin_featured_' . (int) $khatm_id);
        $feature_old = isset($feature_form['data']) && is_array($feature_form['data']) ? $feature_form['data'] : array();
        $feature_errors = isset($feature_form['errors']) && is_array($feature_form['errors']) ? $feature_form['errors'] : array();
        $featured_status = isset($feature_old['featured_status']) ? Hamnoor_Featured_Service::normalize_status($feature_old['featured_status']) : Hamnoor_Featured_Service::normalize_status(isset($khatm->featured_status) ? $khatm->featured_status : 'normal');
        $featured_label = isset($feature_old['featured_label']) ? $feature_old['featured_label'] : (isset($khatm->featured_label) ? $khatm->featured_label : '');
        $featured_starts = isset($feature_old['featured_starts_at_raw']) ? $feature_old['featured_starts_at_raw'] : (!empty($khatm->featured_starts_at) ? Hamnoor_Jalali::to_jalali($khatm->featured_starts_at, true) : '');
        $featured_ends = isset($feature_old['featured_ends_at_raw']) ? $feature_old['featured_ends_at_raw'] : (!empty($khatm->featured_until) ? Hamnoor_Jalali::to_jalali($khatm->featured_until, true) : '');
        $featured_weight = isset($feature_old['featured_weight']) ? (int) $feature_old['featured_weight'] : (isset($khatm->featured_weight) ? (int) $khatm->featured_weight : 0);
        $featured_reason = isset($feature_old['featured_reason']) ? $feature_old['featured_reason'] : (isset($khatm->featured_reason) ? $khatm->featured_reason : '');
        echo '<h2>' . esc_html__('ویرایش پویش', 'hamnoor') . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-admin-form">';
        wp_nonce_field('hamnoor_admin_save_khatm_' . (int) $khatm->id);
        echo '<input type="hidden" name="action" value="hamnoor_admin_save_khatm"><input type="hidden" name="khatm_id" value="' . (int) $khatm->id . '">';
        echo '<table class="form-table"><tbody>';
        $this->field('title', __('عنوان', 'hamnoor'), $khatm->title, 'text');
        $this->field('owner_name', __('نام ایجادکننده', 'hamnoor'), $khatm->owner_name, 'text');
        $this->field('owner_phone', __('موبایل ایجادکننده', 'hamnoor'), $khatm->owner_phone, 'text');
        echo '<tr><th>' . esc_html__('نیت', 'hamnoor') . '</th><td><textarea name="intention" rows="4" class="large-text">' . esc_textarea($khatm->intention) . '</textarea></td></tr>';
        echo '<tr><th>' . esc_html__('توضیحات', 'hamnoor') . '</th><td><textarea name="description" rows="5" class="large-text">' . esc_textarea($khatm->description) . '</textarea></td></tr>';
        echo '<tr><th>' . esc_html__('تصویر', 'hamnoor') . '</th><td><input type="file" name="cover_file" accept="image/*">'; if ($khatm->cover_url) { echo '<br><img class="hamnoor-admin-thumb" src="' . esc_url($khatm->cover_url) . '" alt=""><p class="description">' . esc_html__('اگر تصویر جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.', 'hamnoor') . '</p><label><input type="checkbox" name="remove_cover" value="1"> ' . esc_html__('حذف تصویر فعلی', 'hamnoor') . '</label>'; } echo '</td></tr>';
        echo '<tr><th>' . esc_html__('ساختار پویش', 'hamnoor') . '</th><td><code>' . esc_html($khatm->khatm_type) . '</code> — ' . esc_html(sprintf(__('تعداد دور کامل قرآن: %d، سهم هر دور: %d، کل سهم‌ها: %d', 'hamnoor'), (int)$khatm->khatm_count, (int)$khatm->parts_per_khatm, (int)$khatm->total_parts)) . '<p class="description">' . esc_html__('نوع پویش و تعداد دورها پس از ایجاد قابل تغییر نیست تا سهم‌های موجود دچار ناسازگاری نشوند.', 'hamnoor') . '</p></td></tr>';
        $this->field('max_khatm_count', __('حداکثر تعداد دور کامل قرآن در این پویش', 'hamnoor'), isset($khatm->max_khatm_count) ? $khatm->max_khatm_count : '', 'number');
        $this->field('max_repeat_count', __('حداکثر اختصاصی تکرار سوره برای این پویش', 'hamnoor'), isset($khatm->max_repeat_count) ? $khatm->max_repeat_count : '', 'number');
        $this->field('max_active_claims_per_participant', Hamnoor_Texts::get('active_claim_limit_label'), isset($khatm->max_active_claims_per_participant) ? (int)$khatm->max_active_claims_per_participant : 3, 'number');
        echo '<tr><th></th><td><p class="description">' . esc_html__('بازه مجاز ۱ تا ۵۰ سهم است. این مقدار فقط سقف سهم‌های ناتمام هم‌زمان هر مشارکت‌کننده در همین پویش را تعیین می‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('دسترسی', 'hamnoor') . '</th><td>'; $this->select('privacy', $khatm->privacy, array('public'=>'عمومی','private'=>'خصوصی')); echo '</td></tr>';
        echo '<tr><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><td>'; $this->select('status', $khatm->status, array('pending_review'=>'در انتظار بررسی','needs_revision'=>'نیازمند اصلاح','rejected'=>'تأیید نشد','active'=>'منتشرشده / فعال','paused'=>'توقف موقت','expired'=>'پایان پذیرش سهم جدید','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','archived'=>'آرشیو','failed'=>'خطای فنی')); echo '<p class="description">' . esc_html__('برای «نیازمند اصلاح» یا «تأیید نشد»، نوشتن دلیل در کادر زیر الزامی است.', 'hamnoor') . '</p></td></tr>';
        if ('paused' === (string)$khatm->status) {
            $pause_source_label = (isset($khatm->pause_source) && 'owner' === (string)$khatm->pause_source) ? __('سازنده پویش', 'hamnoor') : __('مدیر سایت', 'hamnoor');
            echo '<tr><th>' . esc_html__('منبع توقف فعلی', 'hamnoor') . '</th><td><strong>' . esc_html($pause_source_label) . '</strong>';
            if (!empty($khatm->paused_at)) { echo ' — <code>' . esc_html((string)$khatm->paused_at) . '</code>'; }
            if (!empty($khatm->pause_note)) { echo '<p class="description"><strong>' . esc_html__('دلیل توقف:', 'hamnoor') . '</strong> ' . esc_html((string)$khatm->pause_note) . '</p>'; }
            echo '</td></tr>';
        }
        echo '<tr><th><label for="pause_note">' . esc_html__('دلیل توقف مدیریتی', 'hamnoor') . '</label></th><td><textarea class="large-text" rows="3" maxlength="2000" id="pause_note" name="pause_note">' . esc_textarea(isset($khatm->pause_note) ? $khatm->pause_note : '') . '</textarea><p class="description">' . esc_html__('این متن فقط برای «توقف موقت» مدیر استفاده می‌شود و پس از ذخیره در همین کادر باقی می‌ماند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="review_note">' . esc_html__('نتیجه / دلیل بررسی', 'hamnoor') . '</label></th><td><textarea class="large-text" rows="4" maxlength="2000" id="review_note" name="review_note">' . esc_textarea(isset($khatm->review_note) ? $khatm->review_note : '') . '</textarea><p class="description">' . esc_html__('این متن فقط هنگام «نیازمند اصلاح» یا «تأیید نشد» برای سازنده نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('نمایش در نتایج گوگل', 'hamnoor') . '</th><td><label><input type="checkbox" name="indexable" value="1"' . checked((int) $khatm->indexable, 1, false) . '> ' . esc_html__('نمایش در نتایج گوگل در صورت عمومی بودن', 'hamnoor') . '</label></td></tr>';
        $this->field('starts_at_jalali', Hamnoor_Texts::get('participation_start_label'), Hamnoor_Jalali::to_jalali($khatm->starts_at, true), 'text');
        echo '<tr><th></th><td><p class="description">' . esc_html(Hamnoor_Texts::get('participation_start_help')) . '</p></td></tr>';
        $this->field('ends_at_jalali', Hamnoor_Texts::get('participation_end_label'), Hamnoor_Jalali::to_jalali($khatm->ends_at, true), 'text');
        echo '<tr><th></th><td><p class="description">' . esc_html(Hamnoor_Texts::get('participation_end_help')) . '</p></td></tr>';
        $this->field('completion_grace_days', Hamnoor_Texts::get('completion_grace_label'), (isset($khatm->completion_grace_days) && null !== $khatm->completion_grace_days) ? (int)$khatm->completion_grace_days : '', 'number');
        echo '<tr><th></th><td><p class="description">' . esc_html(Hamnoor_Texts::get('completion_grace_help')) . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('نیاز به ثبت قرائت', 'hamnoor') . '</th><td>'; $this->select('completion_mode', isset($khatm->completion_mode) ? $khatm->completion_mode : 'manual_done', array('manual_done'=>'پس از قرائت باید ثبت شود','auto_done_on_claim'=>'با انتخاب سهم انجام‌شده محسوب شود')); echo '</td></tr>';
        echo '<tr><th>' . esc_html(Hamnoor_Texts::get('release_policy_title')) . '</th><td>'; $this->select('auto_release_mode', isset($khatm->auto_release_mode) ? $khatm->auto_release_mode : 'none', array('none'=>Hamnoor_Texts::get('release_keep_until_manual'),'after_days'=>Hamnoor_Texts::get('release_after_days_option'))); echo '<p class="description">' . esc_html(Hamnoor_Texts::get('release_policy_help')) . '</p></td></tr>';
        $this->field('auto_release_after_days', Hamnoor_Texts::get('release_after_days_label'), isset($khatm->auto_release_after_days) ? $khatm->auto_release_after_days : '', 'number');
        echo '<tr class="hamnoor-admin-feature-section"><th colspan="2"><h3>' . esc_html__('تنظیمات ویژه بودن', 'hamnoor') . '</h3><p class="description">' . esc_html__('ویژه بودن فقط روی ترتیب و ظاهر نمایش اثر دارد و هیچ قانون سهمی را تغییر نمی‌دهد.', 'hamnoor') . '</p></th></tr>';
        echo '<tr><th><label for="featured_status">' . esc_html__('وضعیت نمایش', 'hamnoor') . '</label></th><td>'; $this->select('featured_status', $featured_status, array('normal'=>'عادی','featured'=>'ویژه','pinned'=>'پین‌شده')); echo '</td></tr>';
        echo '<tr><th><label for="featured_label">' . esc_html__('متن نشان', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_label" name="featured_label" maxlength="100" value="' . esc_attr($featured_label) . '"><p class="description">' . esc_html__('در صورت خالی بودن، متن پیش‌فرض «ویژه» یا «پین‌شده» نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_starts_at_jalali">' . esc_html__('شروع ویژه بودن', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_starts_at_jalali" name="featured_starts_at_jalali" value="' . esc_attr($featured_starts) . '" placeholder="۱۴۰۵/۰۱/۰۱ ۲۱:۳۰">' . $this->feature_field_error($feature_errors, 'featured_starts_at') . '<p class="description">' . esc_html__('اختیاری؛ اگر خالی باشد از زمان ذخیره فعال است.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_ends_at_jalali">' . esc_html__('پایان ویژه بودن', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_ends_at_jalali" name="featured_ends_at_jalali" value="' . esc_attr($featured_ends) . '" placeholder="۱۴۰۵/۰۱/۰۲ ۲۱:۳۰">' . $this->feature_field_error($feature_errors, 'featured_until') . '<p class="description">' . esc_html__('اختیاری؛ پس از این زمان نشان و اولویت ویژه غیرفعال می‌شود و داده حذف نمی‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_weight">' . esc_html__('وزن نمایش', 'hamnoor') . '</label></th><td><input class="small-text" type="number" id="featured_weight" name="featured_weight" min="-100000" max="100000" value="' . esc_attr($featured_weight) . '"><p class="description">' . esc_html__('عدد بالاتر در میان پویش‌های هم‌گروه، زودتر نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_reason">' . esc_html__('یادداشت داخلی مدیر', 'hamnoor') . '</label></th><td><textarea class="large-text" rows="3" maxlength="1000" id="featured_reason" name="featured_reason">' . esc_textarea($featured_reason) . '</textarea><p class="description">' . esc_html__('این متن در فرانت‌اند نمایش داده نمی‌شود.', 'hamnoor') . '</p></td></tr>';
        $request_status = Hamnoor_Featured_Service::normalize_request_status(isset($khatm->featured_request_status) ? $khatm->featured_request_status : 'none');
        echo '<tr><th>' . esc_html__('درخواست ایجادکننده', 'hamnoor') . '</th><td><span class="hamnoor-admin-featured-status hamnoor-admin-featured-status--' . esc_attr($request_status) . '">' . esc_html(Hamnoor_Featured_Service::request_status_label($request_status)) . '</span>';
        if (!empty($khatm->featured_request_message)) { echo '<p class="hamnoor-admin-feature-message">' . esc_html($khatm->featured_request_message) . '</p>'; }
        if ('pending' === $request_status) {
            $approve_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_review_feature_request&khatm_id=' . (int) $khatm->id . '&decision=approve'), 'hamnoor_review_feature_request_' . (int) $khatm->id . '_approve');
            $reject_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_review_feature_request&khatm_id=' . (int) $khatm->id . '&decision=reject'), 'hamnoor_review_feature_request_' . (int) $khatm->id . '_reject');
            echo '<p><a class="button button-primary" href="' . esc_url($approve_url) . '">' . esc_html__('تأیید و ویژه کردن', 'hamnoor') . '</a> <a class="button" href="' . esc_url($reject_url) . '">' . esc_html__('رد درخواست', 'hamnoor') . '</a></p>';
        }
        echo '</td></tr>';
        echo '</tbody></table>'; submit_button(__('ذخیره پویش', 'hamnoor')); echo '</form>';
    }

    private function field($name, $label, $value, $type = 'text') {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($name) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function feature_field_error($errors, $field) {
        if (empty($errors[$field])) { return ''; }
        return '<p class="hamnoor-field-error" role="alert">' . esc_html($errors[$field]) . '</p>';
    }

    private function feature_errors_from_wp_error($error) {
        $errors = array();
        if (!is_wp_error($error)) { return $errors; }
        foreach ($error->get_error_codes() as $code) {
            $data = $error->get_error_data($code);
            $field = is_array($data) && !empty($data['field']) ? sanitize_key($data['field']) : 'featured_status';
            if (empty($errors[$field])) { $errors[$field] = $error->get_error_message($code); }
        }
        return $errors;
    }

    private function render_parts($khatm_id) {
        $khatm = Hamnoor_DB::get_khatm($khatm_id);
        if (!$khatm) { echo '<p>' . esc_html__('پویش پیدا نشد.', 'hamnoor') . '</p>'; return; }
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $settings = Hamnoor_Helpers::settings();
        $per_page = max(5, min(200, (int) $settings['admin_parts_per_page']));
        $status = isset($_GET['part_status']) ? sanitize_key(wp_unslash($_GET['part_status'])) : '';
        $search = isset($_GET['part_s']) ? sanitize_text_field(wp_unslash($_GET['part_s'])) : '';
        $round = isset($_GET['round_number']) ? absint($_GET['round_number']) : 0;
        $args = array('limit' => $per_page, 'offset' => ($paged - 1) * $per_page);
        if ($search) { $args['search'] = $search; }
        if ($round) { $args['round_number'] = $round; }
        $parts = Hamnoor_DB::get_parts($khatm_id, $status, $args);
        $total = Hamnoor_DB::count_parts($khatm_id, $status, $args);
        $base_url = admin_url('admin.php?page=hamnoor&khatm_id=' . (int) $khatm_id);
        echo '<h2>' . esc_html(sprintf(__('سهم‌های پویش: %s', 'hamnoor'), $khatm->title)) . '</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=hamnoor')) . '">' . esc_html__('بازگشت به فهرست پویش‌ها', 'hamnoor') . '</a> <a class="button button-secondary" href="' . esc_url($base_url . '&view=participants') . '">' . esc_html__('شرکت‌کنندگان', 'hamnoor') . '</a></p>';
        echo '<form method="get" class="hamnoor-admin-filters"><input type="hidden" name="page" value="hamnoor"><input type="hidden" name="view" value="parts"><input type="hidden" name="khatm_id" value="' . (int) $khatm_id . '">';
        echo '<input type="search" name="part_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جستجو در عنوان سهم، نام یا موبایل', 'hamnoor') . '">';
        $this->select('part_status', $status, array('' => 'همه وضعیت‌ها', 'available' => 'آزاد', 'claimed' => 'رزروشده', 'done' => 'خوانده‌شده'));
        echo '<input type="number" min="1" name="round_number" value="' . esc_attr($round ? $round : '') . '" placeholder="' . esc_attr__('شماره پویش', 'hamnoor') . '" class="small-text"> ';
        submit_button(__('فیلتر سهم‌ها', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<table class="widefat striped hamnoor-admin-table"><thead><tr><th>' . esc_html__('شناسه', 'hamnoor') . '</th><th>' . esc_html__('سهم', 'hamnoor') . '</th><th>' . esc_html__('شماره پویش', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('شرکت‌کننده', 'hamnoor') . '</th><th>' . esc_html__('تماس', 'hamnoor') . '</th><th>' . esc_html__('زمان‌ها', 'hamnoor') . '</th></tr></thead><tbody>';
        foreach ($parts as $part) {
            $status_label = Hamnoor_Helpers::status_label($part->status, 'part');
            $contact = trim((string) $part->participant_phone);
            echo '<tr><td>' . (int) $part->id . '</td><td><strong>' . esc_html($part->title) . '</strong><br><small>' . esc_html($part->part_type . ' #' . $part->part_number) . '</small></td><td>' . (int) $part->round_number . '</td><td><span class="hamnoor-badge hamnoor-status-' . esc_attr($part->status) . '">' . esc_html($status_label) . '</span></td><td>' . esc_html($part->participant_name) . '</td><td>' . esc_html($contact) . '</td><td><small>' . esc_html__('رزرو:', 'hamnoor') . ' ' . esc_html(Hamnoor_Jalali::to_jalali($part->claimed_at, true)) . '<br>' . esc_html__('انجام:', 'hamnoor') . ' ' . esc_html(Hamnoor_Jalali::to_jalali($part->done_at, true)) . '</small></td></tr>';
        }
        if (!$parts) { echo '<tr><td colspan="7">' . esc_html__('سهمی با این فیلترها یافت نشد.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table>';
        $pages = max(1, (int) ceil($total / $per_page));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)) . '</div></div>';
        }
    }

    private function render_participants($khatm_id) {
        $khatm = Hamnoor_DB::get_khatm($khatm_id);
        if (!$khatm) { echo '<p>' . esc_html__('پویش پیدا نشد.', 'hamnoor') . '</p>'; return; }
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $settings = Hamnoor_Helpers::settings();
        $per_page = max(5, min(200, (int) $settings['admin_participants_per_page']));
        $search = isset($_GET['participant_s']) ? sanitize_text_field(wp_unslash($_GET['participant_s'])) : '';
        $args = array('limit' => $per_page, 'offset' => ($paged - 1) * $per_page);
        if ($search) { $args['search'] = $search; }
        $rows = Hamnoor_DB::get_participants($khatm_id, $args);
        $total = Hamnoor_DB::count_participants($khatm_id, $args);
        $selected_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;
        $base_url = admin_url('admin.php?page=hamnoor&khatm_id=' . (int) $khatm_id);
        echo '<h2>' . esc_html(sprintf(__('شرکت‌کنندگان پویش: %s', 'hamnoor'), $khatm->title)) . '</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url($base_url . '&view=parts') . '">' . esc_html__('مشاهده سهم‌ها', 'hamnoor') . '</a> <a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=hamnoor')) . '">' . esc_html__('بازگشت', 'hamnoor') . '</a></p>';
        echo '<form method="get" class="hamnoor-admin-filters"><input type="hidden" name="page" value="hamnoor"><input type="hidden" name="view" value="participants"><input type="hidden" name="khatm_id" value="' . (int) $khatm_id . '">';
        echo '<input type="search" name="participant_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جستجو در نام یا موبایل', 'hamnoor') . '"> ';
        submit_button(__('جستجوی شرکت‌کننده', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<table class="widefat striped hamnoor-admin-table"><thead><tr><th>' . esc_html__('نام', 'hamnoor') . '</th><th>' . esc_html__('موبایل', 'hamnoor') . '</th><th>' . esc_html__('ناتمام', 'hamnoor') . '</th><th>' . esc_html__('خوانده‌شده', 'hamnoor') . '</th><th>' . esc_html__('انتخاب‌های اخیر', 'hamnoor') . '</th><th>' . esc_html__('وضعیت انتخاب', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $parts_link = add_query_arg(array('page'=>'hamnoor','view'=>'participants','khatm_id'=>(int)$khatm_id,'participant_id'=>(int)$row->participant_id), admin_url('admin.php'));
            $participant_user = !empty($row->user_id) ? Hamnoor_Mobile_Access::get_user((int)$row->user_id) : null;
            $global_blocked = $participant_user && !empty($participant_user->claim_blocked);
            $campaign_blocked = !empty($row->claim_blocked);
            $nonce = wp_create_nonce('hamnoor_admin_participant_' . (int)$khatm_id . '_' . (int)$row->participant_id);
            $action_base = admin_url('admin-post.php');
            $campaign_action = $campaign_blocked ? 'unblock_campaign' : 'block_campaign';
            $global_action = $global_blocked ? 'unblock_global' : 'block_global';
            $campaign_url = add_query_arg(array('action'=>'hamnoor_admin_participant_action','khatm_id'=>(int)$khatm_id,'participant_id'=>(int)$row->participant_id,'participant_action'=>$campaign_action,'_wpnonce'=>$nonce), $action_base);
            $global_url = add_query_arg(array('action'=>'hamnoor_admin_participant_action','khatm_id'=>(int)$khatm_id,'participant_id'=>(int)$row->participant_id,'participant_action'=>$global_action,'_wpnonce'=>$nonce), $action_base);
            $release_url = add_query_arg(array('action'=>'hamnoor_admin_participant_action','khatm_id'=>(int)$khatm_id,'participant_id'=>(int)$row->participant_id,'participant_action'=>'release_open','_wpnonce'=>$nonce), $action_base);
            $status_text = $global_blocked ? __('مسدود سراسری', 'hamnoor') : ($campaign_blocked ? __('مسدود در این پویش', 'hamnoor') : __('مجاز', 'hamnoor'));
            echo '<tr><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->phone) . '</td><td>' . (int)$row->claimed_parts . '</td><td>' . (int)$row->done_parts . '</td><td>' . (int)$row->recent_claims . '</td><td>' . esc_html($status_text) . '</td><td><a class="button button-small" href="' . esc_url($parts_link) . '">' . esc_html__('مشاهده سهم‌ها', 'hamnoor') . '</a> <a class="button button-small" href="' . esc_url($campaign_url) . '">' . esc_html($campaign_blocked ? __('رفع محدودیت پویش','hamnoor') : __('مسدود در پویش','hamnoor')) . '</a>' . (!empty($row->user_id) ? ' <a class="button button-small" href="' . esc_url($global_url) . '">' . esc_html($global_blocked ? __('رفع مسدودی سراسری','hamnoor') : __('مسدود سراسری','hamnoor')) . '</a>' : '') . ((int)$row->claimed_parts > 0 ? ' <a class="button button-small" href="' . esc_url($release_url) . '" onclick="return confirm(\'' . esc_js(__('همه سهم‌های ناتمام این شرکت‌کننده آزاد شوند؟','hamnoor')) . '\');">' . esc_html__('آزادسازی ناتمام‌ها','hamnoor') . '</a>' : '') . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="7">' . esc_html__('شرکت‌کننده‌ای یافت نشد.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table>';
        $pages = max(1, (int) ceil($total / $per_page));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)) . '</div></div>';
        }
        if ($selected_id) {
            $participant_parts = Hamnoor_DB::get_participant_parts($selected_id, 200, $khatm_id);
            echo '<h3>' . esc_html__('سهم‌های این شرکت‌کننده', 'hamnoor') . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__('سهم', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('شماره پویش', 'hamnoor') . '</th></tr></thead><tbody>';
            foreach ($participant_parts as $part) {
                echo '<tr><td>' . esc_html($part->title) . '</td><td><span class="hamnoor-badge hamnoor-status-' . esc_attr($part->status) . '">' . esc_html(Hamnoor_Helpers::status_label($part->status, 'part')) . '</span></td><td>' . (int) $part->round_number . '</td></tr>';
            }
            if (!$participant_parts) { echo '<tr><td colspan="3">' . esc_html__('برای این شرکت‌کننده سهمی ثبت نشده است.', 'hamnoor') . '</td></tr>'; }
            echo '</tbody></table>';
        }
    }

    private function render_logs($khatm_id) {
        $rows = Hamnoor_DB::get_logs($khatm_id);
        echo '<h2>' . esc_html__('لاگ عملیات پویش', 'hamnoor') . '</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>عملیات</th><th>پیام</th><th>IP</th></tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr><td>' . esc_html(Hamnoor_Jalali::to_jalali($row->created_at, true)) . '</td><td>' . esc_html($row->action) . '</td><td>' . esc_html($row->message) . '</td><td>' . esc_html($row->ip_address) . '</td></tr>'; }
        echo '</tbody></table>';
    }

    public function admin_participant_action() {
        if (!current_user_can('manage_options') && !current_user_can('hamnoor_manage_all_khatms')) { wp_die('forbidden'); }
        $khatm_id = isset($_GET['khatm_id']) ? absint($_GET['khatm_id']) : 0;
        $participant_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;
        $operation = isset($_GET['participant_action']) ? sanitize_key(wp_unslash($_GET['participant_action'])) : '';
        check_admin_referer('hamnoor_admin_participant_' . $khatm_id . '_' . $participant_id);
        $redirect = admin_url('admin.php?page=hamnoor&view=participants&khatm_id=' . $khatm_id);
        $participant = Hamnoor_DB::get_participant($participant_id);
        if (!$participant || (int)$participant->khatm_id !== $khatm_id) { Hamnoor_Helpers::redirect_with_notice($redirect, __('شرکت‌کننده معتبر نیست.', 'hamnoor'), 'error'); }
        $actor = Hamnoor_Mobile_Access::actor_id();
        if ('block_campaign' === $operation) { $result = Hamnoor_DB::set_participant_claim_blocked($participant_id, true, $actor, __('توسط مدیر سایت', 'hamnoor')); $message = __('انتخاب سهم جدید این کاربر در پویش متوقف شد.', 'hamnoor'); }
        elseif ('unblock_campaign' === $operation) { $result = Hamnoor_DB::set_participant_claim_blocked($participant_id, false, $actor); $message = __('محدودیت این پویش برداشته شد.', 'hamnoor'); }
        elseif ('release_open' === $operation) { $result = Hamnoor_DB::release_participant_open_parts($participant_id, $actor); $message = is_wp_error($result) ? '' : sprintf(__('%d سهم ناتمام آزاد شد.', 'hamnoor'), (int)$result); }
        elseif ('block_global' === $operation && !empty($participant->user_id)) { $result = Hamnoor_DB::set_user_claim_blocked((int)$participant->user_id, true, $actor, __('توسط مدیر سایت', 'hamnoor')); $message = __('انتخاب سهم جدید این کاربر در کل هم‌نور متوقف شد.', 'hamnoor'); }
        elseif ('unblock_global' === $operation && !empty($participant->user_id)) { $result = Hamnoor_DB::set_user_claim_blocked((int)$participant->user_id, false, $actor); $message = __('مسدودی سراسری کاربر برداشته شد.', 'hamnoor'); }
        else { $result = new WP_Error('hamnoor_participant_action_invalid', __('عملیات معتبر نیست.', 'hamnoor')); $message=''; }
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($redirect, $message);
    }

    public function page_settings() {
        if (!current_user_can('manage_options')) { return; }
        $s = Hamnoor_Helpers::settings();
        $active = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $tabs = array(
            'general' => __('عمومی', 'hamnoor'),
            'links' => __('لینک‌ها و صفحات', 'hamnoor'),
            'create' => __('ایجاد پویش', 'hamnoor'),
            'export' => __('خروجی و گزارش', 'hamnoor'),
            'seo' => __('سئو', 'hamnoor'),
            'appearance' => __('ظاهر و رنگ‌ها', 'hamnoor'),
            'reminders' => __('هشدارهای سهم', 'hamnoor'),
            'claims' => __('کنترل انتخاب سهم', 'hamnoor'),
            'danger' => __('تنظیمات حساس', 'hamnoor'),
        );
        if (!isset($tabs[$active])) { $active = 'general'; }
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('تنظیمات هم‌نور', 'hamnoor') . '</h1>' . Hamnoor_Helpers::current_notice();
        echo '<nav class="hamnoor-admin-tabs" aria-label="' . esc_attr__('تب‌های تنظیمات', 'hamnoor') . '">';
        foreach ($tabs as $key => $label) { echo '<a href="' . esc_url(add_query_arg(array('page'=>'hamnoor-settings','tab'=>$key), admin_url('admin.php'))) . '" class="' . esc_attr($active === $key ? 'nav-tab-active' : '') . '">' . esc_html($label) . '</a>'; }
        echo '</nav><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_save_settings');
        echo '<input type="hidden" name="action" value="hamnoor_save_settings"><input type="hidden" name="tab" value="' . esc_attr($active) . '">';
        echo '<div class="hamnoor-admin-panel"><table class="form-table"><tbody>';
        if ('general' === $active) {
            $this->setting('dashboard_my_parts_per_page', 'تعداد سهم‌های مشارکت من در داشبورد فرانت‌اند', $s['dashboard_my_parts_per_page']);
            $this->setting('dashboard_my_khatms_per_page', 'تعداد پویش‌های ایجادشده توسط من در داشبورد فرانت‌اند', $s['dashboard_my_khatms_per_page']);
            $this->setting('admin_khatms_per_page', 'تعداد پویش‌ها در هر صفحه مدیریت', $s['admin_khatms_per_page']);
            $this->setting('admin_parts_per_page', 'تعداد سهم‌ها در هر صفحه پویش و مدیریت', $s['admin_parts_per_page']);
            $this->setting('admin_participants_per_page', 'تعداد شرکت‌کنندگان در هر صفحه مدیریت', $s['admin_participants_per_page']);
            echo '<tr><th>نمایش نوار مدیریت</th><td><label><input type="hidden" name="hide_admin_bar" value="0"><input type="checkbox" name="hide_admin_bar" value="1"' . checked(isset($s['hide_admin_bar']) ? $s['hide_admin_bar'] : '0', '1', false) . '> نوار مدیریت وردپرس در صفحات هم‌نور برای کاربران غیرمدیر پنهان باشد</label><p class="description">کاربران هم‌نور مستقل از کاربران وردپرس نگهداری می‌شوند.</p></td></tr>';
            echo '<tr><th>تنظیمات پیشرفته</th><td><details><summary>' . esc_html__('نمایش تنظیمات فنی', 'hamnoor') . '</summary><div style="margin-top:12px"><label><input type="hidden" name="rest_api_enabled" value="0"><input type="checkbox" name="rest_api_enabled" value="1"' . checked($s['rest_api_enabled'], '1', false) . '> ' . esc_html__('دسترسی برنامه‌ها به رابط فنی هم‌نور فعال باشد', 'hamnoor') . '</label><br><label><input type="hidden" name="ajax_debug_admin" value="0"><input type="checkbox" name="ajax_debug_admin" value="1"' . checked(isset($s['ajax_debug_admin']) ? $s['ajax_debug_admin'] : '0', '1', false) . '> ' . esc_html__('جزئیات خطاهای ارتباطی برای مدیر ثبت شود', 'hamnoor') . '</label></div></details></td></tr>';
        } elseif ('links' === $active) {
            echo '<tr><th>لینک دعوت</th><td><p class="description">لینک هر پویش با یک کد پنج‌کاراکتری مستقیماً از ریشه دامنه ساخته می‌شود؛ نمونه: <code>/a7k2m/</code>.</p></td></tr>';
            $pages = get_option('hamnoor_pages', array());
            $list_id = is_array($pages) && !empty($pages['list']) ? (int) $pages['list'] : 0;
            $dashboard_id = is_array($pages) && !empty($pages['dashboard']) ? (int) $pages['dashboard'] : 0;
            $create_id = is_array($pages) && !empty($pages['create']) ? (int) $pages['create'] : 0;
            echo '<tr><th>برگه‌های هم‌نور</th><td><p>' . esc_html__('هم‌نور سه برگه اصلی برای فهرست پویش‌ها، داشبورد و فرم مستقل ایجاد پویش دارد.', 'hamnoor') . '</p>';
            if ($list_id) { echo '<p><a href="' . esc_url(get_permalink($list_id)) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('پویش‌های قرآن', 'hamnoor') . '</a></p>'; }
            if ($dashboard_id) { echo '<p><a href="' . esc_url(get_permalink($dashboard_id)) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('داشبورد هم‌نور', 'hamnoor') . '</a></p>'; }
            if ($create_id) { echo '<p><a href="' . esc_url(get_permalink($create_id)) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('ایجاد پویش جدید', 'hamnoor') . '</a></p>'; }
            echo '</td></tr>';
            echo '<tr><th><label for="help_page_id">' . esc_html__('برگه راهنمای هم‌نور', 'hamnoor') . '</label></th><td>';
            wp_dropdown_pages(array('name'=>'help_page_id','id'=>'help_page_id','selected'=>absint($s['help_page_id'] ?? 0),'show_option_none'=>__('— انتخاب نشده —','hamnoor'),'option_none_value'=>'0'));
            echo '<p class="description">' . esc_html__('این برگه مقصد اصلی «راهنما» در پنل کاربران است و محتوای آن با ویرایشگر معمول وردپرس مدیریت می‌شود.', 'hamnoor') . '</p></td></tr>';
            $this->setting('help_custom_url', __('نشانی جایگزین راهنما', 'hamnoor'), isset($s['help_custom_url']) ? $s['help_custom_url'] : '');
            echo '<tr><th></th><td><p class="description">' . esc_html__('فقط وقتی برگه‌ای انتخاب نشده باشد استفاده می‌شود. اگر هر دو خالی باشند، راهنمای کوتاه داخلی نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
        } elseif ('create' === $active) {
            echo '<tr><th>' . esc_html__('امکان ایجاد پویش جدید', 'hamnoor') . '</th><td>'; $this->select('new_khatm_creation_enabled', isset($s['new_khatm_creation_enabled']) ? $s['new_khatm_creation_enabled'] : '1', array('1'=>'فعال','0'=>'غیرفعال موقت')); echo '<p class="description">در حالت غیرفعال، ایجاد پویش جدید برای کاربران عادی بسته می‌شود؛ مدیر سایت همچنان می‌تواند پویش بسازد و ویرایش پویش‌های موجود ادامه دارد.</p></td></tr>';
            echo '<tr><th>' . esc_html__('اختیارات سازنده پس از انتشار', 'hamnoor') . '</th><td><fieldset>';
            echo '<label style="display:block;margin-bottom:8px"><input type="hidden" name="owner_pause_enabled" value="0"><input type="checkbox" name="owner_pause_enabled" value="1"' . checked(isset($s['owner_pause_enabled']) ? $s['owner_pause_enabled'] : '1', '1', false) . '> ' . esc_html__('اجازه توقف موقت و ازسرگیری پویش توسط سازنده', 'hamnoor') . '</label>';
            echo '<label style="display:block"><input type="hidden" name="owner_cancel_enabled" value="0"><input type="checkbox" name="owner_cancel_enabled" value="1"' . checked(isset($s['owner_cancel_enabled']) ? $s['owner_cancel_enabled'] : '1', '1', false) . '> ' . esc_html__('اجازه لغو پویش توسط سازنده', 'hamnoor') . '</label>';
            echo '<p class="description">هر دو گزینه به‌صورت پیش‌فرض فعال‌اند. در صورت غیرفعال‌کردن، دکمه مربوط از داشبورد سازنده حذف می‌شود و درخواست مستقیم نیز در سمت سرور رد خواهد شد. اختیارات مدیر سایت مستقل از این تنظیمات باقی می‌ماند.</p></fieldset></td></tr>';
            $this->setting('max_khatm_count', 'حداکثر تعداد دور کامل قرآن در هر پویش', $s['max_khatm_count']);
            $this->setting('max_repeat_count', 'حداکثر عمومی تعداد تکرار سوره', isset($s['max_repeat_count']) ? $s['max_repeat_count'] : '1000');
            $this->setting('max_parts_per_khatm', 'حداکثر تعداد سهم قابل پردازش هم‌زمان', isset($s['max_parts_per_khatm']) ? $s['max_parts_per_khatm'] : '12000');
            $this->setting('cover_max_size_kb', 'حداکثر حجم تصویر KB', $s['cover_max_size_kb']);
            echo '<tr><th>اجباری بودن تصویر پویش</th><td>'; $this->select('cover_required_mode', isset($s['cover_required_mode']) ? $s['cover_required_mode'] : 'optional', array('optional'=>'همیشه اختیاری باشد','public'=>'برای پویش عمومی الزامی باشد','always'=>'همیشه الزامی باشد')); echo '<p class="description">اگر تصویر اختیاری باشد، کارت پویش از طرح پیش‌فرض هماهنگ با سبک انتخاب‌شده استفاده می‌کند.</p></td></tr>';
            $this->setting('default_khatm_image_url', 'تصویر پیش‌فرض پویش', isset($s['default_khatm_image_url']) ? $s['default_khatm_image_url'] : '');
            echo '<tr><th></th><td><p class="description">نشانی تصویر پیشنهادی با نسبت ۱۲۰۰×۶۳۰ وارد شود. اگر کاربر تصویر انتخاب نکند، این تصویر در کارت و صفحه پویش استفاده می‌شود.</p></td></tr>';
            echo '<tr><th>نمایش نام شرکت‌کنندگان</th><td>'; $this->select('participant_name_display', $s['participant_name_display'], array('full'=>'کامل','first'=>'نام کوچک','hidden'=>'مخفی')); echo '</td></tr>';
            echo '<tr><th>روش نمایش صفحات پویش</th><td>'; $this->select('khatm_render_mode', isset($s['khatm_render_mode']) ? $s['khatm_render_mode'] : 'plugin', array('theme'=>'سازگار با قالب سایت','plugin'=>'قالب اختصاصی هم‌نور')); echo '<p class="description">حالت اختصاصی، صفحه پویش را کاملاً مستقل از هدر، فوتر و CSS قالب سایت نمایش می‌دهد؛ حالت سازگار با قالب، هدر و فوتر سایت را حفظ می‌کند. در قالب‌های بلوکی مانند Twenty Twenty-Five، هم‌نور قالب اختصاصی «صفحه پویش هم‌نور» را به‌کار می‌گیرد تا عنوان «بلاگ» و عنوان تکراری نوشته نمایش داده نشوند.</p></td></tr>';
            echo '<tr><th>' . esc_html__('حمایت مالی داوطلبانه', 'hamnoor') . '</th><td><label><input type="hidden" name="support_section_enabled" value="0"><input type="checkbox" name="support_section_enabled" value="1"' . checked(isset($s['support_section_enabled']) ? $s['support_section_enabled'] : '0', '1', false) . '> ' . esc_html__('نمایش بخش حمایت در مرحله پایانی ایجاد پویش', 'hamnoor') . '</label><p class="description">' . esc_html__('این بخش اختیاری است و ایجاد پویش به پرداخت وابسته نمی‌شود.', 'hamnoor') . '</p></td></tr>';
            $this->setting('support_section_title', 'عنوان بخش حمایت', isset($s['support_section_title']) ? $s['support_section_title'] : '');
            echo '<tr><th><label for="support_section_description">' . esc_html__('متن توضیحی حمایت', 'hamnoor') . '</label></th><td><textarea id="support_section_description" name="support_section_description" class="large-text" rows="4">' . esc_textarea(isset($s['support_section_description']) ? $s['support_section_description'] : '') . '</textarea></td></tr>';
            echo '<tr><th><label for="support_payment_info">' . esc_html__('اطلاعات واریز یا روش حمایت', 'hamnoor') . '</label></th><td><textarea id="support_payment_info" name="support_payment_info" class="large-text" rows="4">' . esc_textarea(isset($s['support_payment_info']) ? $s['support_payment_info'] : '') . '</textarea><p class="description">' . esc_html__('فقط اطلاعات لازم برای روش حمایت را وارد کنید و از درج اطلاعات بانکی غیرضروری خودداری کنید.', 'hamnoor') . '</p></td></tr>';
            $this->setting('support_button_text', 'متن دکمه حمایت', isset($s['support_button_text']) ? $s['support_button_text'] : '');
            $this->setting('support_button_url', 'لینک دکمه حمایت', isset($s['support_button_url']) ? $s['support_button_url'] : '');
            echo '<tr><th>' . esc_html__('ارتباط برای افزایش تکرار', 'hamnoor') . '</th><td><label><input type="hidden" name="repeat_contact_enabled" value="0"><input type="checkbox" name="repeat_contact_enabled" value="1"' . checked(isset($s['repeat_contact_enabled']) ? $s['repeat_contact_enabled'] : '0', '1', false) . '> ' . esc_html__('نمایش دکمه ارتباط با مدیر زیر تنظیم تکرار سوره', 'hamnoor') . '</label></td></tr>';
            $this->setting('repeat_contact_button_text', 'متن دکمه ارتباط', isset($s['repeat_contact_button_text']) ? $s['repeat_contact_button_text'] : '');
            $this->setting('repeat_contact_url', 'لینک ارتباط با مدیر', isset($s['repeat_contact_url']) ? $s['repeat_contact_url'] : '');
            $this->setting('repeat_contact_help', 'متن راهنمای درخواست افزایش', isset($s['repeat_contact_help']) ? $s['repeat_contact_help'] : '');
        } elseif ('export' === $active) {
            echo '<tr><th>مجوز خروجی برای ایجادکننده پویش</th><td><select name="owner_export_mode"><option value="disabled"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'disabled', false) . '>غیرفعال</option><option value="limited"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'limited', false) . '>خروجی محدود: بدون شماره موبایل</option><option value="full"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'full', false) . '>خروجی کامل: شامل شماره موبایل</option></select></td></tr>';
        } elseif ('seo' === $active) {
            echo '<tr><th>نمایش در نتایج گوگل</th><td><label><input type="hidden" name="index_public_khatms" value="0"><input type="checkbox" name="index_public_khatms" value="1"' . checked($s['index_public_khatms'], '1', false) . '> پویش‌های عمومی در صورت مناسب بودن در نتایج گوگل دیده شوند</label><p class="description">از نظر فنی این گزینه meta index/noindex را کنترل می‌کند؛ پویش خصوصی و داشبورد هم‌نور noindex می‌مانند.</p></td></tr>';
            echo '<tr><th><label for="share_text_template">متن پیش‌فرض اشتراک‌گذاری</label></th><td><textarea id="share_text_template" name="share_text_template" class="large-text" rows="8">' . esc_textarea($s['share_text_template']) . '</textarea><p class="description">متغیرها: {KHATM_TITLE}، {KHATM_INTENTION}، {KHATM_URL}، {AVAILABLE_PARTS}، {DONE_PARTS}، {TOTAL_PARTS}، {PROGRESS_PERCENT}</p></td></tr>';
            echo '<tr><th>اشتراک‌گذاری خصوصی</th><td><label><input type="hidden" name="allow_private_share" value="0"><input type="checkbox" name="allow_private_share" value="1"' . checked($s['allow_private_share'], '1', false) . '> نمایش دکمه اشتراک‌گذاری عمومی برای پویش خصوصی</label></td></tr>';
        } elseif ('appearance' === $active) {
            echo '<tr class="hamnoor-visual-mode-row"><th scope="row">' . esc_html__('سبک نمایش افزونه', 'hamnoor') . '</th><td><fieldset class="hamnoor-visual-mode-options"><legend class="screen-reader-text">' . esc_html__('سبک نمایش افزونه', 'hamnoor') . '</legend>';
            $visual_modes = array(
                'theme_aware' => array(
                    'label' => __('هماهنگ با قالب سایت', 'hamnoor'),
                    'description' => __('افزونه فونت، عرض و رنگ‌های اصلی را تا حد امکان از قالب سایت می‌گیرد و فقط اجزای اختصاصی هم‌نور را مرتب و خوانا می‌کند.', 'hamnoor'),
                ),
                'hamnoor' => array(
                    'label' => __('استایل اختصاصی هم‌نور', 'hamnoor'),
                    'description' => __('ظاهر صفحات هم‌نور با طراحی اختصاصی‌تر، کارت‌های برجسته‌تر و رنگ‌بندی مشخص‌تر نمایش داده می‌شود.', 'hamnoor'),
                ),
                'minimal' => array(
                    'label' => __('مینیمال', 'hamnoor'),
                    'description' => __('استایل افزونه به حداقل می‌رسد تا قالب سایت کنترل بیشتری روی ظاهر صفحات داشته باشد.', 'hamnoor'),
                ),
            );
            foreach ($visual_modes as $value => $mode) {
                echo '<label class="hamnoor-visual-mode-option"><input type="radio" name="visual_mode" value="' . esc_attr($value) . '"' . checked(isset($s['visual_mode']) ? $s['visual_mode'] : 'theme_aware', $value, false) . '><span><strong>' . esc_html($mode['label']) . '</strong><small>' . esc_html($mode['description']) . '</small></span></label>';
            }
            echo '</fieldset></td></tr>';
            foreach (array('primary_color'=>'رنگ اصلی','secondary_color'=>'رنگ مکمل','accent_color'=>'رنگ تأکیدی','background_color'=>'رنگ زمینه','card_background'=>'رنگ کارت','text_color'=>'رنگ متن','muted_color'=>'رنگ متن کم‌رنگ','border_color'=>'رنگ کادر','success_color'=>'رنگ موفقیت','warning_color'=>'رنگ هشدار','danger_color'=>'رنگ خطا') as $key=>$label) { $this->color($key, $label, $s[$key]); }
            $this->setting('border_radius', 'گردی کارت‌ها', $s['border_radius']);
            $this->setting('max_width', 'حداکثر عرض محتوا', $s['max_width']);
            $this->setting('parts_min_width', 'حداقل عرض کارت سهم', $s['parts_min_width']);
            $this->setting('reading_arabic_size', 'اندازه پیش‌فرض خط قرآن (۲۸ تا ۵۶ پیکسل)', isset($s['reading_arabic_size']) ? $s['reading_arabic_size'] : '38');
            $this->setting('reading_translation_size', 'اندازه پیش‌فرض ترجمه (۱۴ تا ۲۸ پیکسل)', isset($s['reading_translation_size']) ? $s['reading_translation_size'] : '18');
            echo '<tr><th>' . esc_html__('تنظیمات پیشرفته ظاهر', 'hamnoor') . '</th><td><details><summary>' . esc_html__('نمایش تنظیمات فنی ظاهر', 'hamnoor') . '</summary><div style="margin-top:12px">';
            echo '<label for="font_family" style="display:block;margin-bottom:5px">' . esc_html__('نام خانواده فونت', 'hamnoor') . '</label><input class="regular-text" id="font_family" type="text" name="font_family" value="' . esc_attr($s['font_family']) . '">';
            echo '<p class="description">' . esc_html__('فقط در صورت آشنایی با نام فونت‌های نصب‌شده سایت تغییر دهید.', 'hamnoor') . '</p></div></details></td></tr>';
        } elseif ('reminders' === $active) {
            echo '<tr><th>' . esc_html__('هشدار پیش از آزادسازی سهم', 'hamnoor') . '</th><td>';
            $this->select('release_warning_mode', isset($s['release_warning_mode']) ? $s['release_warning_mode'] : 'smart', array(
                'smart' => __('هوشمند (پیشنهادی)', 'hamnoor'),
                'fixed' => __('زمان ثابت پیش از آزادسازی', 'hamnoor'),
                'disabled' => __('غیرفعال', 'hamnoor'),
            ));
            echo '<p class="description">' . esc_html__('حالت هوشمند هشدار را در یک‌چهارم پایانی مهلت سهم می‌فرستد، با سقف ۲۴ ساعت پیش از آزادسازی. نمونه: مهلت ۱ روزه = ۶ ساعت قبل، ۲ روزه = ۱۲ ساعت، ۳ روزه = ۱۸ ساعت، ۴ روز یا بیشتر = ۲۴ ساعت.', 'hamnoor') . '</p></td></tr>';
            $this->setting('release_warning_fixed_hours', __('در حالت ثابت؛ چند ساعت پیش از آزادسازی؟', 'hamnoor'), isset($s['release_warning_fixed_hours']) ? $s['release_warning_fixed_hours'] : '24');
            echo '<tr><th></th><td><p class="description">' . esc_html__('فقط وقتی حالت «زمان ثابت» انتخاب شده استفاده می‌شود. بازه مجاز ۱ تا ۷۲۰ ساعت است.', 'hamnoor') . '</p></td></tr>';
            $this->setting('release_warning_batch_limit', __('حداکثر هشدار آزادسازی در هر اجرای Scheduler', 'hamnoor'), isset($s['release_warning_batch_limit']) ? $s['release_warning_batch_limit'] : '100');
            echo '<tr><th></th><td><p class="description">' . esc_html__('برای جلوگیری از ورود ناگهانی تعداد بسیار زیاد هشدار به صف است. بازه مجاز ۱ تا ۵۰۰. این مقدار جایگزین محدودیت‌ها و اعتبار خود پیام‌رسان‌ها نیست.', 'hamnoor') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('قواعد ثابت', 'hamnoor') . '</th><td><ul style="margin:0 0 0 18px;list-style:disc"><li>' . esc_html__('برای هر چرخه رزرو سهم حداکثر یک هشدار آزادسازی ساخته می‌شود.', 'hamnoor') . '</li><li>' . esc_html__('اگر سهم پیش از موعد تکمیل شود، هشدار آزادسازی ارسال نمی‌شود.', 'hamnoor') . '</li><li>' . esc_html__('اگر پایان پذیرش سهم جدید پیش از موعد آزادسازی باشد، نه سهم آزاد می‌شود و نه هشدار آزادسازی برای آن ساخته می‌شود.', 'hamnoor') . '</li></ul></td></tr>';
            echo '<tr><th>' . esc_html__('متن پیام', 'hamnoor') . '</th><td><p class="description">' . sprintf(esc_html__('عنوان و متن خود پیام از منوی %s قابل ویرایش است.', 'hamnoor'), '<strong>' . esc_html__('هم‌نور ← متن‌ها', 'hamnoor') . '</strong>') . '</p></td></tr>';
            $release_warning_last = get_option('hamnoor_release_warning_last_run', array());
            if (is_array($release_warning_last) && !empty($release_warning_last['at'])) {
                $last_mode_labels = array('smart'=>__('هوشمند','hamnoor'),'fixed'=>__('ثابت','hamnoor'),'disabled'=>__('غیرفعال','hamnoor'));
                $last_mode = isset($release_warning_last['mode']) ? sanitize_key((string)$release_warning_last['mode']) : '';
                echo '<tr><th>' . esc_html__('آخرین اجرای هشدار آزادسازی', 'hamnoor') . '</th><td><code>' . esc_html((string)$release_warning_last['at']) . '</code> — ' . esc_html(isset($last_mode_labels[$last_mode]) ? $last_mode_labels[$last_mode] : $last_mode) . ' — ' . esc_html__('هشدار ساخته‌شده:', 'hamnoor') . ' <strong>' . (int)($release_warning_last['created'] ?? 0) . '</strong></td></tr>';
            }
        } elseif ('claims' === $active) {
            $this->setting('default_active_claim_limit', __('سقف پیش‌فرض سهم ناتمام هم‌زمان هر نفر در هر پویش', 'hamnoor'), isset($s['default_active_claim_limit']) ? $s['default_active_claim_limit'] : '3');
            echo '<tr><th></th><td><p class="description">' . esc_html__('سازنده هر پویش می‌تواند این مقدار را برای همان پویش تغییر دهد. بازه مجاز ۱ تا ۵۰ است.', 'hamnoor') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('سهمیه زمانی انتخاب سهم', 'hamnoor') . '</th><td><label><input type="hidden" name="temporal_claim_quota_enabled" value="0"><input type="checkbox" name="temporal_claim_quota_enabled" value="1"' . checked(isset($s['temporal_claim_quota_enabled']) ? $s['temporal_claim_quota_enabled'] : '1', '1', false) . '> ' . esc_html__('Rolling Window سراسری برای پویش‌های اشتراکی فعال باشد', 'hamnoor') . '</label><p class="description">' . esc_html__('این سیاست از سقف سهم ناتمام مستقل است. پویش‌های شخصی owner-only سهمیه زمانی را مصرف نمی‌کنند.', 'hamnoor') . '</p></td></tr>';
            $this->setting('temporal_claim_limit', __('حداکثر انتخاب جدید در بازه زمانی', 'hamnoor'), isset($s['temporal_claim_limit']) ? $s['temporal_claim_limit'] : '3');
            $this->setting('temporal_claim_window_hours', __('طول Rolling Window، ساعت', 'hamnoor'), isset($s['temporal_claim_window_hours']) ? $s['temporal_claim_window_hours'] : '24');
            echo '<tr><th></th><td><p class="description">' . esc_html__('تکمیل، لغو یا آزادشدن سهم، سهمیه زمانی مصرف‌شده را برنمی‌گرداند. مقادیر فقط روی درخواست‌های جدید اعمال می‌شوند.', 'hamnoor') . '</p></td></tr>';
            $this->setting('participant_suspicious_claim_limit', __('آستانه انتخاب سریع یک حساب در بازه زمانی', 'hamnoor'), isset($s['participant_suspicious_claim_limit']) ? $s['participant_suspicious_claim_limit'] : '8');
            $this->setting('claim_abuse_ip_limit', __('آستانه انتخاب موفق از یک IP در همان پویش', 'hamnoor'), isset($s['claim_abuse_ip_limit']) ? $s['claim_abuse_ip_limit'] : '30');
            $this->setting('claim_abuse_window_minutes', __('طول بازه تشخیص رفتار مشکوک، دقیقه', 'hamnoor'), isset($s['claim_abuse_window_minutes']) ? $s['claim_abuse_window_minutes'] : '10');
            echo '<tr><th>' . esc_html__('منطق حفاظتی', 'hamnoor') . '</th><td><p class="description">' . esc_html__('انتخاب سهم عادی هرگز OTP ایجاد نمی‌کند. عبور از آستانه‌های سوءاستفاده با Rate Limit و مسدودی Server-side کنترل می‌شود؛ OTP فقط برای عملیات حساس هویتی و مدیریت پویش است.', 'hamnoor') . '</p></td></tr>';
        } elseif ('danger' === $active) {
            echo '<tr><th>حذف داده‌ها هنگام uninstall</th><td><div class="notice notice-warning inline"><p>این گزینه خطرناک است و فقط در زمان تست افزونه استفاده شود.</p></div><label><input type="hidden" name="delete_data_on_uninstall" value="0"><input type="checkbox" name="delete_data_on_uninstall" value="1"' . checked($s['delete_data_on_uninstall'], '1', false) . '> حذف داده‌ها هنگام حذف افزونه</label></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="hamnoor-settings-save"><p class="description">' . esc_html__('تغییرات این بخش فقط پس از انتخاب دکمه زیر ذخیره می‌شوند.', 'hamnoor') . '</p>';
        submit_button(__('ذخیره تغییرات', 'hamnoor'), 'primary', 'submit', false);
        echo '</div></form></div>';
    }

    private function setting($name, $label, $value) { echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($name) . '" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>'; }
    private function color($name, $label, $value) { echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input id="' . esc_attr($name) . '" type="color" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>'; }


    public function page_texts() {
        if (!current_user_can('manage_options')) { return; }
        $defaults = Hamnoor_Texts::defaults();
        $texts = Hamnoor_Texts::all();
        $meta = array(
            'create_form_title'=>array('group'=>'فرم ایجاد پویش','label'=>'عنوان فرم ایجاد پویش','desc'=>'تیتر بالای فرم ویزارد.'),
            'step_basic_desc'=>array('group'=>'فرم ایجاد پویش','label'=>'توضیح مرحله اطلاعات','desc'=>'متن کوتاه مرحله اطلاعات اصلی.'),
            'step_type_desc'=>array('group'=>'فرم ایجاد پویش','label'=>'توضیح مرحله نوع پویش','desc'=>'متن راهنمای انتخاب نوع پویش.'),
            'khatm_count_help'=>array('group'=>'فرم ایجاد پویش','label'=>'راهنمای تعداد دور کامل','desc'=>'زیر فیلد تعداد دور کامل قرآن نمایش داده می‌شود.','vars'=>'{MAX}'),
            'khatm_count_error'=>array('group'=>'پیام‌های خطا','label'=>'خطای تعداد دور کامل','desc'=>'وقتی تعداد دور کامل قرآن از حد مجاز بیشتر است.','vars'=>'{MAX}'),
            'create_success'=>array('group'=>'پیام‌های موفقیت','label'=>'پیام موفقیت ایجاد پویش','desc'=>'بعد از ساخت پویش نمایش داده می‌شود.'),
            'share_text_template'=>array('group'=>'متن دعوت','label'=>'متن پیش‌فرض دعوت','desc'=>'متن قابل کپی برای دعوت دیگران.','vars'=>'{KHATM_TITLE}, {KHATM_URL}, {KHATM_INTENTION}, {AVAILABLE_PARTS}, {DONE_PARTS}, {TOTAL_PARTS}, {PROGRESS_PERCENT}'),
            'mobile_invalid'=>array('group'=>'پیام‌های خطا','label'=>'خطای موبایل نامعتبر','desc'=>'برای فرم‌های موبایل.'),
            'parts_done_help'=>array('group'=>'صفحه پویش','label'=>'راهنمای ثبت قرائت','desc'=>'فقط یک‌بار بالای لیست سهم‌ها نمایش داده می‌شود.'),
            'claim_next_button'=>array('group'=>'انتخاب سهم','label'=>'دکمه انتخاب به ترتیب','desc'=>'دکمه اصلی صفحه پویش.'),
            'claim_random_button'=>array('group'=>'انتخاب سهم','label'=>'دکمه انتخاب تصادفی','desc'=>'دکمه فرعی صفحه پویش.'),
            'claim_intro'=>array('group'=>'انتخاب سهم','label'=>'توضیح بخش انتخاب سهم','desc'=>'زیر عنوان بخش اقدام اصلی.'),
            'claim_next_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب به ترتیب','desc'=>'در پاپ‌آپ انتخاب سهم.'),
            'claim_random_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب تصادفی','desc'=>'در پاپ‌آپ انتخاب سهم.'),
            'claim_manual_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب دستی','desc'=>'در پاپ‌آپ انتخاب سهم.','vars'=>'{PART_TITLE}'),
            'claim_submit'=>array('group'=>'انتخاب سهم','label'=>'دکمه ثبت انتخاب','desc'=>'دکمه اصلی پاپ‌آپ.'),
            'claim_cancel'=>array('group'=>'انتخاب سهم','label'=>'دکمه انصراف','desc'=>'دکمه بستن پاپ‌آپ.'),
            'complete_button'=>array('group'=>'صفحه پویش','label'=>'دکمه قرائت انجام شد','desc'=>'برای کارت سهم.'),
            'complete_success'=>array('group'=>'پیام‌های موفقیت','label'=>'پیام موفقیت ثبت قرائت','desc'=>'بعد از ثبت قرائت.'),
            'complete_inline_success'=>array('group'=>'پیام‌های موفقیت','label'=>'پیام کوتاه ثبت قرائت','desc'=>'بعد از ثبت دستی قرائت روی کارت سهم.'),
            'reading_start_button'=>array('group'=>'قرآن هم‌نور','label'=>'دکمه شروع قرائت سهم','desc'=>'ورود به قرآن داخلی هم‌نور.'),
            'reading_continue_button'=>array('group'=>'قرآن هم‌نور','label'=>'دکمه ادامه قرائت','desc'=>'وقتی قرائت داخلی قبلاً شروع شده است.'),
            'reading_finish_button'=>array('group'=>'قرآن هم‌نور','label'=>'دکمه ثبت پایان سهم','desc'=>'وقتی آخرین آیه سهم در قرآن داخلی ثبت شده است.'),
            'reading_review_button'=>array('group'=>'قرآن هم‌نور','label'=>'دکمه مرور سهم','desc'=>'برای سهم تکمیل‌شده در قرآن داخلی.'),
            'khatm_completed_message'=>array('group'=>'صفحه پویش','label'=>'پیام تکمیل پویش','desc'=>'وقتی همه سهم‌ها خوانده شدند.'),
            'badge_active'=>array('group'=>'badgeها','label'=>'برچسب فعال','desc'=>'وضعیت پویش فعال.'),
            'badge_completed'=>array('group'=>'badgeها','label'=>'برچسب تکمیل شد','desc'=>'وضعیت پویش تکمیل‌شده.'),
            'badge_available'=>array('group'=>'badgeها','label'=>'برچسب آزاد','desc'=>'وضعیت سهم آزاد.'),
            'badge_claimed'=>array('group'=>'badgeها','label'=>'برچسب رزروشده','desc'=>'وضعیت سهم رزروشده.'),
            'badge_done'=>array('group'=>'badgeها','label'=>'برچسب خوانده‌شده','desc'=>'وضعیت سهم خوانده‌شده.'),
            'part_already_claimed'=>array('group'=>'پیام‌های خطا','label'=>'خطای سهم قبلاً انتخاب‌شده','desc'=>'وقتی سهم در همان لحظه توسط دیگری انتخاب شده.'),
            'no_free_part'=>array('group'=>'پیام‌های خطا','label'=>'خطای نبود سهم آزاد','desc'=>'وقتی سهم آزادی وجود ندارد.'),
            'claim_window_closed_card'=>array('group'=>'پویش‌های عمومی','label'=>'پیام کارت پویش پس از پایان پذیرش','desc'=>'روی کارت پویش عمومی وقتی مهلت انتخاب سهم تمام شده است.'),
            'claim_window_paused_card'=>array('group'=>'پویش‌های عمومی','label'=>'پیام کارت پویش در توقف موقت','desc'=>'روی کارت پویش عمومی وقتی پذیرش سهم موقتاً متوقف شده است.'),
            'claim_window_closed_part'=>array('group'=>'انتخاب سهم','label'=>'پیام کارت سهم پس از پایان پذیرش','desc'=>'روی سهم آزاد وقتی مهلت انتخاب سهم جدید پایان یافته است.'),
            'claim_window_paused_part'=>array('group'=>'انتخاب سهم','label'=>'پیام کارت سهم در توقف موقت','desc'=>'روی سهم آزاد وقتی پذیرش سهم موقتاً متوقف شده است.'),
            'active_claim_limit_title'=>array('group'=>'کنترل انتخاب سهم','label'=>'عنوان کنترل تعداد سهم','desc'=>'در مرحله تنظیمات قرائت فرم پویش.'),
            'active_claim_limit_label'=>array('group'=>'کنترل انتخاب سهم','label'=>'برچسب سقف سهم ناتمام','desc'=>'سقف هم‌زمان هر مشارکت‌کننده.'),
            'active_claim_limit_help'=>array('group'=>'کنترل انتخاب سهم','label'=>'راهنمای سقف سهم ناتمام','desc'=>'توضیح زیر فیلد سقف.'),
            'active_claim_limit_reached'=>array('group'=>'کنترل انتخاب سهم','label'=>'پیام رسیدن به سقف سهم','desc'=>'وقتی کاربر سهم ناتمام کافی دارد.','vars'=>'{COUNT}, {MAX}'),
            'claim_campaign_blocked'=>array('group'=>'کنترل انتخاب سهم','label'=>'پیام مسدودی در پویش','desc'=>'وقتی سازنده یا مدیر کاربر را فقط در همان پویش محدود کرده است.'),
            'claim_globally_blocked'=>array('group'=>'کنترل انتخاب سهم','label'=>'پیام مسدودی سراسری','desc'=>'وقتی مدیر سایت کاربر را از انتخاب سهم در همه پویش‌ها منع کرده است.'),
            'participant_blocked_badge'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'نشان مسدود بودن انتخاب سهم','desc'=>'در داشبورد سازنده.'),
            'participant_suspicious_badge'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'نشان رفتار انتخاب سریع','desc'=>'برای مشارکت‌کننده‌ای که در بازه کوتاه سهم‌های زیادی انتخاب کرده است.'),
            'participant_block_button'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'دکمه جلوگیری از انتخاب سهم جدید','desc'=>'عملیات سازنده روی مشارکت‌کننده.'),
            'participant_unblock_button'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'دکمه رفع محدودیت انتخاب','desc'=>'عملیات سازنده روی مشارکت‌کننده.'),
            'participant_release_open_button'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'دکمه آزادسازی همه ناتمام‌ها','desc'=>'عملیات گروهی روی سهم‌های ناتمام یک مشارکت‌کننده.'),
            'participant_block_confirm'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'تأیید مسدودسازی در پویش','desc'=>'پیش از جلوگیری از انتخاب سهم جدید.'),
            'participant_unblock_confirm'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'تأیید رفع محدودیت','desc'=>'پیش از بازکردن انتخاب سهم.'),
            'participant_release_open_confirm'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'تأیید آزادسازی گروهی','desc'=>'پیش از آزادسازی همه سهم‌های ناتمام.'),
            'participant_block_success'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'پیام موفقیت مسدودسازی','desc'=>'پس از محدودسازی در پویش.'),
            'participant_unblock_success'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'پیام موفقیت رفع محدودیت','desc'=>'پس از رفع محدودیت.'),
            'participant_release_open_success'=>array('group'=>'مدیریت مشارکت‌کنندگان','label'=>'پیام آزادسازی گروهی','desc'=>'پس از آزادسازی ناتمام‌ها.','vars'=>'%d'),
            'owner_pause_button'=>array('group'=>'مدیریت پویش','label'=>'دکمه توقف موقت سازنده','desc'=>'در عملیات پویش فعال در داشبورد سازنده.'),
            'owner_resume_button'=>array('group'=>'مدیریت پویش','label'=>'دکمه ازسرگیری سازنده','desc'=>'برای پویشی که خود سازنده متوقف کرده است.'),
            'owner_cancel_button'=>array('group'=>'مدیریت پویش','label'=>'دکمه لغو پویش','desc'=>'عملیات نهایی لغو توسط سازنده.'),
            'owner_pause_confirm'=>array('group'=>'مدیریت پویش','label'=>'تأیید توقف موقت','desc'=>'پیام تأیید پیش از توقف توسط سازنده.'),
            'owner_resume_confirm'=>array('group'=>'مدیریت پویش','label'=>'تأیید ازسرگیری','desc'=>'پیام تأیید پیش از فعال‌سازی دوباره.'),
            'owner_cancel_confirm'=>array('group'=>'مدیریت پویش','label'=>'تأیید لغو پویش','desc'=>'هشدار جدی پیش از لغو نهایی توسط سازنده.'),
            'owner_pause_success'=>array('group'=>'مدیریت پویش','label'=>'پیام موفقیت توقف','desc'=>'پس از توقف توسط سازنده.'),
            'owner_resume_success'=>array('group'=>'مدیریت پویش','label'=>'پیام موفقیت ازسرگیری','desc'=>'پس از ازسرگیری توسط سازنده.'),
            'owner_cancel_success'=>array('group'=>'مدیریت پویش','label'=>'پیام موفقیت لغو','desc'=>'پس از لغو توسط سازنده.'),
            'owner_pause_notice'=>array('group'=>'مدیریت پویش','label'=>'پیام توقف توسط سازنده','desc'=>'در کارت پویش سازنده وقتی توقف توسط خودش انجام شده است.'),
            'admin_pause_notice'=>array('group'=>'مدیریت پویش','label'=>'پیام توقف توسط مدیر','desc'=>'در کارت پویش سازنده وقتی مدیر سایت پویش را متوقف کرده است.'),
            'admin_pause_reason_prefix'=>array('group'=>'مدیریت پویش','label'=>'عنوان دلیل توقف مدیر','desc'=>'پیش از متن دلیل توقف مدیریتی.'),
            'admin_pause_resume_blocked'=>array('group'=>'مدیریت پویش','label'=>'پیام عدم امکان ازسرگیری توقف مدیر','desc'=>'وقتی سازنده مجاز به برداشتن توقف مدیریتی نیست.'),
            'form_incomplete'=>array('group'=>'پیام‌های خطا','label'=>'خطای فرم ناقص','desc'=>'پیام خلاصه خطای فرم.'),
            'release_policy_title'=>array('group'=>'تنظیمات قرائت','label'=>'عنوان مدت نگهداری سهم','desc'=>'عنوان بخش تعیین زمان نگهداری سهم.'),
            'release_keep_until_manual'=>array('group'=>'تنظیمات قرائت','label'=>'متن نگهداری سهم تا تکمیل یا آزادسازی دستی','desc'=>'گزینه اول مدت نگهداری سهم.'),
            'release_after_days_option'=>array('group'=>'تنظیمات قرائت','label'=>'متن آزادسازی پس از چند روز','desc'=>'گزینه دوم مدت نگهداری سهم.'),
            'release_after_days_label'=>array('group'=>'تنظیمات قرائت','label'=>'برچسب تعداد روز نگهداری','desc'=>'بالای فیلد تعداد روز.'),
            'release_policy_help'=>array('group'=>'تنظیمات قرائت','label'=>'راهنمای آزادسازی سهم','desc'=>'توضیح ارتباط آزادسازی با بازبودن پذیرش.'),
            'schedule_section_title'=>array('group'=>'زمان‌بندی پویش','label'=>'عنوان زمان‌بندی اختیاری','desc'=>'عنوان بخش جمع‌شونده زمان‌بندی.'),
            'schedule_section_help'=>array('group'=>'زمان‌بندی پویش','label'=>'راهنمای زمان‌بندی','desc'=>'توضیح اینکه در شرایط عادی نیازی به تاریخ نیست.'),
            'participation_start_label'=>array('group'=>'زمان‌بندی پویش','label'=>'برچسب شروع پذیرش سهم','desc'=>'عنوان فیلد شروع پذیرش.'),
            'participation_start_help'=>array('group'=>'زمان‌بندی پویش','label'=>'راهنمای شروع پذیرش','desc'=>'توضیح رفتار فیلد خالی.'),
            'participation_end_label'=>array('group'=>'زمان‌بندی پویش','label'=>'برچسب پایان پذیرش سهم جدید','desc'=>'عنوان فیلد پایان پذیرش.'),
            'participation_end_help'=>array('group'=>'زمان‌بندی پویش','label'=>'راهنمای پایان پذیرش','desc'=>'توضیح رفتار فیلد خالی.'),
            'completion_grace_label'=>array('group'=>'زمان‌بندی پویش','label'=>'برچسب مهلت تکمیل سهم‌های قبلی','desc'=>'پس از پایان پذیرش سهم جدید.'),
            'completion_grace_help'=>array('group'=>'زمان‌بندی پویش','label'=>'راهنمای مهلت تکمیل','desc'=>'خالی یعنی بدون محدودیت زمانی.'),
            'part_release_warning_title'=>array('group'=>'اعلان‌های سهم','label'=>'عنوان هشدار پیش از آزادسازی','desc'=>'عنوان اعلان پیش از آزادسازی خودکار.'),
            'part_release_warning_message'=>array('group'=>'اعلان‌های سهم','label'=>'متن هشدار پیش از آزادسازی','desc'=>'متن اعلان موعددار.','vars'=>'{DATE}'),
            'part_release_warning_fallback'=>array('group'=>'اعلان‌های سهم','label'=>'متن جایگزین هشدار آزادسازی','desc'=>'اگر زمان دقیق در دسترس نبود.'),
            'featured_badge_default'=>array('group'=>'پویش‌های ویژه','label'=>'نشان پیش‌فرض ویژه','desc'=>'وقتی مدیر متن نشان را خالی گذاشته است.'),
            'pinned_badge_default'=>array('group'=>'پویش‌های ویژه','label'=>'نشان پیش‌فرض پین‌شده','desc'=>'برای پویش پین‌شده بدون متن سفارشی.'),
            'featured_notice_single'=>array('group'=>'پویش‌های ویژه','label'=>'پیام صفحه پویش ویژه','desc'=>'در صفحه پویش ویژه فعال نمایش داده می‌شود.'),
            'pinned_notice_single'=>array('group'=>'پویش‌های ویژه','label'=>'پیام صفحه پویش پین‌شده','desc'=>'در صفحه پویش پین‌شده فعال نمایش داده می‌شود.'),
            'feature_request_button'=>array('group'=>'پویش‌های ویژه','label'=>'دکمه درخواست ویژه شدن','desc'=>'در داشبورد ایجادکننده پویش.'),
            'feature_request_pending'=>array('group'=>'پویش‌های ویژه','label'=>'وضعیت درخواست در انتظار','desc'=>'پس از ثبت درخواست نمایش داده می‌شود.'),
            'feature_request_success'=>array('group'=>'پویش‌های ویژه','label'=>'پیام موفقیت درخواست','desc'=>'پس از ثبت موفق درخواست نمایش داده می‌شود.'),
            'feature_request_rejected'=>array('group'=>'پویش‌های ویژه','label'=>'پیام درخواست ردشده','desc'=>'برای درخواست ردشده نمایش داده می‌شود.'),
        );
        $groups = array();
        foreach ($defaults as $key => $default) {
            $info = isset($meta[$key]) ? $meta[$key] : array('group'=>'سایر متن‌ها','label'=>$key,'desc'=>'');
            $groups[$info['group']][$key] = $info;
        }
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('متن‌های هم‌نور', 'hamnoor') . '</h1><p>' . esc_html__('متن‌های مهم افزونه را می‌توانید بدون ویرایش کد تغییر دهید. کلید فنی فقط برای ارجاع نمایش داده می‌شود.', 'hamnoor') . '</p>';
        echo '<input type="search" class="regular-text hamnoor-text-search" placeholder="' . esc_attr__('جستجو در متن‌ها...', 'hamnoor') . '">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_save_texts');
        echo '<input type="hidden" name="action" value="hamnoor_save_texts">';
        foreach ($groups as $group => $items) {
            echo '<section class="hamnoor-text-group"><h2>' . esc_html($group) . '</h2>';
            foreach ($items as $key => $info) {
                $default = isset($defaults[$key]) ? $defaults[$key] : '';
                $value = isset($texts[$key]) ? $texts[$key] : $default;
                $rows = ('share_text_template' === $key || strlen((string) $value) > 120) ? 6 : 2;
                echo '<div class="hamnoor-text-item" data-search="' . esc_attr($key . ' ' . $info['label'] . ' ' . $info['desc']) . '"><label for="hamnoor_text_' . esc_attr($key) . '"><strong>' . esc_html($info['label']) . '</strong><code>' . esc_html($key) . '</code></label>';
                if (!empty($info['desc'])) { echo '<p class="hamnoor-text-desc">' . esc_html($info['desc']) . '</p>'; }
                if (!empty($info['vars'])) { echo '<p class="hamnoor-text-vars">' . esc_html__('متغیرهای مجاز:', 'hamnoor') . ' <code>' . esc_html($info['vars']) . '</code></p>'; }
                echo '<textarea id="hamnoor_text_' . esc_attr($key) . '" name="hamnoor_texts[' . esc_attr($key) . ']" class="large-text" rows="' . (int) $rows . '">' . esc_textarea($value) . '</textarea></div>';
            }
            echo '</section>';
        }
        submit_button(__('ذخیره متن‌ها', 'hamnoor'));
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('متن‌ها به پیش‌فرض برگردند؟', 'hamnoor')) . '\');">';
        wp_nonce_field('hamnoor_reset_texts');
        echo '<input type="hidden" name="action" value="hamnoor_reset_texts">';
        submit_button(__('بازگردانی همه متن‌ها به پیش‌فرض', 'hamnoor'), 'delete');
        echo '</form></div>';
    }

    public function save_texts() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_save_texts');
        Hamnoor_Texts::update(isset($_POST['hamnoor_texts']) && is_array($_POST['hamnoor_texts']) ? $_POST['hamnoor_texts'] : array());
        Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor-texts'), __('متن‌ها ذخیره شد.', 'hamnoor'));
    }

    public function reset_texts() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_reset_texts');
        Hamnoor_Texts::reset();
        Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor-texts'), __('متن‌ها به پیش‌فرض برگشت.', 'hamnoor'));
    }

    public function page_health() {
        if (!current_user_can('manage_options')) { return; }
        $health = Hamnoor_DB::check_tables_health();
        $channel_diagnostics = apply_filters('hamnoor_channel_admin_diagnostics', array());
        $web_push_admin = !empty($channel_diagnostics['web_push']) && is_array($channel_diagnostics['web_push']) ? $channel_diagnostics['web_push'] : array();
        $eitaa_admin = !empty($channel_diagnostics['eitaa']) && is_array($channel_diagnostics['eitaa']) ? $channel_diagnostics['eitaa'] : array();
        $bale_admin = !empty($channel_diagnostics['bale']) && is_array($channel_diagnostics['bale']) ? $channel_diagnostics['bale'] : array();
        $rubika_admin = !empty($channel_diagnostics['rubika']) && is_array($channel_diagnostics['rubika']) ? $channel_diagnostics['rubika'] : array();
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('سلامت افزونه هم‌نور', 'hamnoor') . '</h1>';
        if (!empty($health['ok'])) { echo '<div class="notice notice-success"><p>' . esc_html__('ساختار اصلی افزونه سالم است.', 'hamnoor') . '</p></div>'; }
        else { echo '<div class="notice notice-error"><p>' . esc_html__('در ساختار افزونه خطا یا کمبود مشاهده شد.', 'hamnoor') . '</p></div>'; }
        $rows = array(
            'Plugin Version' => HAMNOOR_VERSION,
            'Stored Plugin Version' => (string)get_option('hamnoor_plugin_version', ''),
            'Safe Upgrade Backup' => is_array(get_option('hamnoor_upgrade_backup', array())) && !empty(get_option('hamnoor_upgrade_backup', array())['created_at']) ? (string)get_option('hamnoor_upgrade_backup', array())['created_at'] : 'Not created yet',
            'DB Version' => get_option('hamnoor_db_version'),
            'PHP' => PHP_VERSION,
            'WordPress' => get_bloginfo('version'),
            'Missing Tables' => empty($health['missing']) ? 'OK' : implode(', ', $health['missing']),
            'Missing Columns' => empty($health['columns_missing']) ? 'OK' : implode(', ', $health['columns_missing']),
            'Rewrite Signature' => isset($health['rewrite_signature']) ? $health['rewrite_signature'] : '',
            'Essential Pages' => !empty($health['pages']) ? implode(', ', array_keys($health['pages'])) : __('ثبت نشده', 'hamnoor'),
            'ZipArchive' => !empty($health['ziparchive']) ? 'OK' : 'Not available',
            'REST API' => !empty($health['rest_enabled']) ? 'Enabled' : 'Disabled',
            'Uploads' => (isset($health['uploads']['error']) && $health['uploads']['error']) ? $health['uploads']['error'] : 'OK',
            'Public Khatm Index' => !empty($health['index_public_khatms']) ? 'Enabled' : 'Disabled',
            'Web Push HTTPS' => is_ssl() ? 'OK' : 'Required',
            'Web Push OpenSSL' => extension_loaded('openssl') ? 'OK' : 'Missing',
            'Web Push Add-on' => !empty($web_push_admin['installed']) ? 'Installed' : 'Not installed',
            'Web Push Subscriptions' => isset($web_push_admin['subscriptions']) ? (int)$web_push_admin['subscriptions'] : 0,
            'Eitaa Add-on' => !empty($eitaa_admin['installed']) ? 'Installed' : 'Not installed',
            'Eitaa Public Token' => !empty($eitaa_admin['public_configured']) ? 'Configured' : 'Not configured',
            'Eitaa Program Token' => !empty($eitaa_admin['program_configured']) ? 'Configured' : 'Not configured',
            'Eitaa Bridge' => !empty($eitaa_admin['bridge_url']) ? (string)$eitaa_admin['bridge_url'] : 'Missing',
            'Bale Add-on' => !empty($bale_admin['installed']) ? 'Installed' : 'Not installed',
            'Bale Token' => !empty($bale_admin['configured']) ? 'Configured' : 'Not configured',
            'Bale Webhook' => !empty($bale_admin['webhook_url']) ? (string)$bale_admin['webhook_url'] : 'Missing',
            'Bale Connected Users' => isset($bale_admin['connected_users']) ? (int)$bale_admin['connected_users'] : 0,
            'Rubika Add-on' => !empty($rubika_admin['installed']) ? 'Installed' : 'Not installed',
            'Rubika Token' => !empty($rubika_admin['configured']) ? 'Configured' : 'Not configured',
            'Rubika Endpoint' => !empty($rubika_admin['webhook_url']) ? (string)$rubika_admin['webhook_url'] : 'Missing',
            'Rubika Connected Users' => isset($rubika_admin['connected_users']) ? (int)$rubika_admin['connected_users'] : 0,
        );
        echo '<h2>' . esc_html__('بررسی‌ها', 'hamnoor') . '</h2><table class="widefat striped"><tbody>';
        foreach ($rows as $k=>$v) { echo '<tr><td>' . esc_html($k) . '</td><td><code>' . esc_html((string)$v) . '</code></td></tr>'; }
        echo '</tbody></table></div>';
    }

    public function page_notifications() {
        if (!current_user_can('manage_options')) { return; }

        $channel_diagnostics = apply_filters('hamnoor_channel_admin_diagnostics', array());
        $web_push_admin = !empty($channel_diagnostics['web_push']) && is_array($channel_diagnostics['web_push']) ? $channel_diagnostics['web_push'] : array();
        $eitaa_admin = !empty($channel_diagnostics['eitaa']) && is_array($channel_diagnostics['eitaa']) ? $channel_diagnostics['eitaa'] : array();
        $bale_admin = !empty($channel_diagnostics['bale']) && is_array($channel_diagnostics['bale']) ? $channel_diagnostics['bale'] : array();
        $rubika_admin = !empty($channel_diagnostics['rubika']) && is_array($channel_diagnostics['rubika']) ? $channel_diagnostics['rubika'] : array();

        $tabs = array(
            'overview' => __('نمای کلی', 'hamnoor'),
            'deliveries' => __('گزارش ارسال', 'hamnoor'),
        );
        $tabs = apply_filters('hamnoor_notification_admin_tabs', $tabs);
        $tabs = is_array($tabs) ? $tabs : array();
        $active = isset($_GET['channel']) ? sanitize_key(wp_unslash($_GET['channel'])) : 'overview';
        if (!isset($tabs[$active])) { $active = 'overview'; }

        $push_ready = !empty($web_push_admin['available']);
        $priority = Hamnoor_Notifications::available() ? Hamnoor_Notifications::priority() : array();
        $labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن');

        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('مدیریت اعلان‌ها', 'hamnoor') . '</h1>' . Hamnoor_Helpers::current_notice();
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>$key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . esc_attr($active === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ('overview' === $active) {
            echo '<p>' . esc_html__('اعلان مرورگر در صورت امکان ارسال می‌شود. سپس هم‌نور پیام‌رسان‌های فعال را به‌ترتیب بررسی می‌کند و پیام را از نخستین کانال در دسترس می‌فرستد. ارسال‌های ناموفق نیز به‌صورت خودکار دوباره بررسی می‌شوند.', 'hamnoor') . '</p>';
            echo '<h2>' . esc_html__('اولویت ارسال اعلان‌ها', 'hamnoor') . '</h2><ol>';
            foreach ($priority as $key) {
                if (!isset($tabs[$key])) { continue; }
                if ('web_push' === $key) { $state = $push_ready ? __('آماده', 'hamnoor') : __('نیازمند بررسی', 'hamnoor'); }
                elseif ('eitaa' === $key) { $state = !empty($eitaa_admin['available']) ? __('اتصال خصوصی فعال', 'hamnoor') : __('افزونه مستقل نصب یا پیکربندی نشده است', 'hamnoor'); }
                elseif ('bale' === $key) { $state = !empty($bale_admin['available']) ? __('اتصال واقعی بازو فعال', 'hamnoor') : __('افزونه مستقل نصب یا پیکربندی نشده است', 'hamnoor'); }
                elseif ('rubika' === $key) { $state = !empty($rubika_admin['available']) ? __('اتصال واقعی Bot API فعال', 'hamnoor') : __('افزونه مستقل نصب یا پیکربندی نشده است', 'hamnoor'); }
                else { $state = __('در حال حاضر در دسترس نیست', 'hamnoor'); }
                $tab_url = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>$key), admin_url('admin.php'));
                echo '<li><a href="' . esc_url($tab_url) . '"><strong>' . esc_html(isset($labels[$key]) ? $labels[$key] : $key) . '</strong></a> — ' . esc_html($state) . '</li>';
            }
            echo '</ol>';
            $reconcile = get_option('hamnoor_notification_state_reconcile_last', array());
            if (is_array($reconcile) && !empty($reconcile['at'])) {
                echo '<details style="margin-top:24px"><summary><strong>' . esc_html__('گزارش فنی سازگاری کانال‌ها', 'hamnoor') . '</strong></summary><h2>' . esc_html__('بررسی وضعیت اتصال‌های قدیمی', 'hamnoor') . '</h2>';
                echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
                echo '<tr><th>' . esc_html__('آخرین اجرا', 'hamnoor') . '</th><td><code>' . esc_html((string)$reconcile['at']) . '</code></td></tr>';
                echo '<tr><th>' . esc_html__('رکوردهای بررسی‌شده', 'hamnoor') . '</th><td>' . (int)($reconcile['scanned'] ?? 0) . '</td></tr>';
                echo '<tr><th>' . esc_html__('رکوردهای اصلاح‌شده', 'hamnoor') . '</th><td><strong>' . (int)($reconcile['changed'] ?? 0) . '</strong></td></tr>';
                echo '<tr><th>' . esc_html__('وضعیت خطای واقعی حفظ‌شده', 'hamnoor') . '</th><td>' . (int)($reconcile['preserved_error_state'] ?? 0) . '</td></tr>';
                if (!empty($reconcile['rules']) && is_array($reconcile['rules'])) {
                    $safe_rules = array();
                    foreach ($reconcile['rules'] as $rule=>$count_rule) { $safe_rules[] = sanitize_key((string)$rule) . '=' . (int)$count_rule; }
                    echo '<tr><th>' . esc_html__('جزئیات بررسی', 'hamnoor') . '</th><td><code>' . esc_html(implode(' | ', $safe_rules)) . '</code></td></tr>';
                }
                echo '</tbody></table></details>';
            }
            $this->render_reminder_runtime_status();
        } elseif ('deliveries' === $active) {
            $this->render_delivery_report();
        } elseif (has_action('hamnoor_render_notification_admin_tab_' . $active)) {
            $active_diagnostics = !empty($channel_diagnostics[$active]) && is_array($channel_diagnostics[$active]) ? $channel_diagnostics[$active] : array();
            do_action('hamnoor_render_notification_admin_tab_' . $active, $active_diagnostics);
        } else {
            echo '<div class="card" style="max-width:820px"><h2>' . esc_html($tabs[$active]) . '</h2><p>' . esc_html__('زیرساخت این کانال در ترتیب اعلان‌ها رزرو شده است، اما اتصال عملیاتی آن هنوز در این نسخه فعال نشده است.', 'hamnoor') . '</p></div>';
        }
        echo '</div>';
    }

    private function render_reminder_runtime_status() {
        $next = wp_next_scheduled('hamnoor_internal_reminders');
        $last = get_option('hamnoor_reminder_last_run', array());
        $last = is_array($last) ? $last : array();
        $settings = class_exists('Hamnoor_Engagement') ? Hamnoor_Engagement::smart_reminder_settings() : array();
        echo '<h2>' . esc_html__('یادآوری خودکار سهم‌های ناتمام', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('هم‌نور با توجه به وضعیت هر سهم، زمان مناسب یادآوری را انتخاب می‌کند. اعلان مرورگر در صورت امکان ارسال می‌شود و پیام‌رسان‌های فعال نیز به‌ترتیب بررسی می‌شوند.', 'hamnoor') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="card" style="max-width:1000px;padding:16px 20px;margin:16px 0">';
        wp_nonce_field('hamnoor_save_smart_reminder_settings');
        echo '<input type="hidden" name="action" value="hamnoor_save_smart_reminder_settings">';
        echo '<h3 style="margin-top:0">' . esc_html__('قواعد یادآوری خودکار', 'hamnoor') . '</h3>';
        echo '<p><label><input type="checkbox" name="smart[enabled]" value="1" ' . checked(!empty($settings['enabled']), true, false) . '> <strong>' . esc_html__('یادآوری خودکار فعال باشد', 'hamnoor') . '</strong></label></p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">';
        $fields = array(
            'not_started_hours'=>array(__('شروع‌نشده؛ پس از چند ساعت؟','hamnoor'),1,720),
            'stalled_hours'=>array(__('بدون پیشرفت؛ پس از چند ساعت؟','hamnoor'),1,720),
            'repeat_hours'=>array(__('حداقل فاصله دو یادآوری (ساعت)','hamnoor'),1,720),
            'max_auto'=>array(__('سقف یادآوری خودکار هر سهم','hamnoor'),1,20),
        );
        foreach ($fields as $key=>$meta) {
            echo '<label><span style="display:block;margin-bottom:5px">' . esc_html($meta[0]) . '</span><input type="number" name="smart[' . esc_attr($key) . ']" min="' . (int)$meta[1] . '" max="' . (int)$meta[2] . '" value="' . esc_attr((string)($settings[$key] ?? '')) . '" style="width:100%"></label>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__('پیش‌فرض: شروع‌نشده ۲۴ ساعت، توقف قرائت ۴۸ ساعت، تکرار ۴۸ ساعت و حداکثر ۳ یادآوری خودکار. هر پیشرفت تازه، ساعت بی‌تحرکی را از نو محاسبه می‌کند؛ اما سقف ۳ یادآوری در طول همان سهم حفظ می‌شود.', 'hamnoor') . '</p>';
        submit_button(__('ذخیره تنظیمات یادآوری', 'hamnoor'), 'primary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
        echo '<tr><th>' . esc_html__('اجرای بعدی زمان‌بندی', 'hamnoor') . '</th><td><code>' . esc_html($next ? wp_date('Y-m-d H:i:s', $next) : __('زمان‌بندی نشده', 'hamnoor')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('آخرین اجرا', 'hamnoor') . '</th><td><code>' . esc_html(!empty($last['at']) ? (string)$last['at'] : __('هنوز اجرا نشده', 'hamnoor')) . '</code></td></tr>';
        if (!empty($last['disabled'])) {
            echo '<tr><th>' . esc_html__('نتیجه آخرین اجرا', 'hamnoor') . '</th><td><strong>' . esc_html__('موتور یادآوری خودکار غیرفعال است.', 'hamnoor') . '</strong></td></tr>';
        } elseif ($last) {
            $summary = sprintf(
                __('بررسی‌شده: %1$d — یادآوری: %2$d — ارسال موفق: %3$d — در انتظار تلاش دوباره: %4$d — ناموفق: %5$d — فقط در داشبورد: %6$d', 'hamnoor'),
                (int)($last['scanned'] ?? 0),(int)($last['reminders_created'] ?? 0),(int)($last['external_sent'] ?? 0),(int)($last['queued_retry'] ?? 0),(int)($last['external_failed'] ?? 0),(int)($last['internal_only'] ?? 0)
            );
            echo '<tr><th>' . esc_html__('نتیجه آخرین اجرا', 'hamnoor') . '</th><td>' . esc_html($summary) . '</td></tr>';
            $smart = sprintf(
                __('موعدها: شروع‌نشده %1$d، درحال‌قرائت %2$d، نزدیک پایان %3$d، آماده ثبت پایان %4$d — ردشده: غیرفعال %5$d، سقف پر %6$d، هنوز زود %7$d، فاصله تکرار %8$d', 'hamnoor'),
                (int)($last['due_not_started'] ?? 0),(int)($last['due_reading'] ?? 0),(int)($last['due_near_completion'] ?? 0),(int)($last['due_ready'] ?? 0),(int)($last['skipped_disabled'] ?? 0),(int)($last['skipped_max'] ?? 0),(int)($last['skipped_not_due'] ?? 0),(int)($last['skipped_repeat'] ?? 0)
            );
            echo '<tr><th>' . esc_html__('نتیجه بررسی خودکار', 'hamnoor') . '</th><td>' . esc_html($smart) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('برای سهم تکمیل‌شده، آزادشده یا سهمی که یادآوری آن خاموش شده باشد، پیامی ارسال نمی‌شود.', 'hamnoor') . '</p>';
        $confirm = "return confirm('" . esc_js(__('بررسی هوشمند یادآوری‌ها همین حالا اجرا شود؟ فقط سهم‌هایی که همه شرایط زمانی و ساعات مجاز را دارند پیام دریافت می‌کنند.', 'hamnoor')) . "');";
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px" onsubmit="' . esc_attr($confirm) . '">';
        wp_nonce_field('hamnoor_run_reminders_now');
        echo '<input type="hidden" name="action" value="hamnoor_run_reminders_now">';
        submit_button(__('بررسی یادآوری‌ها در همین لحظه', 'hamnoor'), 'secondary', 'submit', false);
        echo '</form>';
        $this->render_operational_reminder_test();
    }

    private function render_operational_reminder_test() {
        $candidates = class_exists('Hamnoor_Engagement') ? Hamnoor_Engagement::operational_test_candidates(150) : array();
        echo '<hr style="margin:28px 0">';
        echo '<details style="margin-top:24px"><summary><strong>' . esc_html__('ابزارهای بررسی و عیب‌یابی اعلان‌ها', 'hamnoor') . '</strong></summary><h2>' . esc_html__('بررسی ارسال یادآوری', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('این ابزار یک پیام واقعی برای کاربر انتخاب‌شده می‌فرستد، اما زمان‌بندی و تعداد یادآوری‌های سهم او را تغییر نمی‌دهد.', 'hamnoor') . '</p>';
        if ($candidates) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1000px">';
            wp_nonce_field('hamnoor_test_part_reminder');
            echo '<input type="hidden" name="action" value="hamnoor_test_part_reminder">';
            echo '<label for="hamnoor-test-part"><strong>' . esc_html__('انتخاب سهم', 'hamnoor') . '</strong></label><br>';
            echo '<select id="hamnoor-test-part" name="part_id" required style="min-width:min(760px,100%);margin:7px 0 10px">';
            echo '<option value="">' . esc_html__('یک سهم در حال قرائت را انتخاب کنید…', 'hamnoor') . '</option>';
            $channel_labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن');
            foreach ((array)$candidates as $row) {
                $name = trim((string)$row->participant_name);
                if (!$name) { $name = trim((string)$row->display_name); }
                $phone = trim((string)$row->participant_phone);
                if (!$phone) { $phone = trim((string)$row->user_phone); }
                $eligible_labels = array();
                if (Hamnoor_Notifications::available()) {
                    $diag = Hamnoor_Notifications::route_diagnostics((int)$row->user_id);
                    foreach ((array)($diag['rows'] ?? array()) as $diag_row) {
                        if (empty($diag_row['eligible'])) { continue; }
                        $key = isset($diag_row['channel']) ? sanitize_key((string)$diag_row['channel']) : '';
                        if ($key) { $eligible_labels[] = isset($channel_labels[$key]) ? $channel_labels[$key] : $key; }
                    }
                }
                $channels_text = $eligible_labels ? implode('، ', $eligible_labels) : __('بدون کانال بیرونی متصل', 'hamnoor');
                $label = sprintf('#%1$d — %2$s — %3$s — %4$s%5$s — [%6$s]', (int)$row->part_id, (string)$row->part_title, (string)$row->khatm_title, $name ?: __('کاربر هم‌نور','hamnoor'), $phone ? ' — ' . $phone : '', $channels_text);
                echo '<option value="' . (int)$row->part_id . '">' . esc_html($label) . '</option>';
            }
            echo '</select><br>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
            echo '<button type="submit" class="button button-primary" name="test_mode" value="priority" onclick="return confirm(\'' . esc_js(__('یک پیام واقعی از نخستین کانال در دسترس برای این کاربر ارسال شود؟ زمان‌بندی سهم تغییر نمی‌کند.', 'hamnoor')) . '\');">' . esc_html__('ارسال از مسیر عادی', 'hamnoor') . '</button>';
            echo '<button type="submit" class="button button-secondary" name="test_mode" value="all" onclick="return confirm(\'' . esc_js(__('یک پیام واقعی به همه کانال‌های فعال این کاربر فرستاده شود؟ ممکن است چند پیام مشابه دریافت کند. ادامه می‌دهید؟', 'hamnoor')) . '\');">' . esc_html__('بررسی همه کانال‌های متصل', 'hamnoor') . '</button>';
            echo '</div>';
            echo '<p class="description" style="margin-top:8px">' . esc_html__('گزینه اول مانند ارسال عادی عمل می‌کند. گزینه دوم فقط برای بررسی اتصال‌هاست و ممکن است پیام را از چند کانال بفرستد.', 'hamnoor') . '</p>';
            echo '<div class="notice notice-info inline" style="margin:10px 0 0"><p><strong>' . esc_html__('نکته مهم:', 'hamnoor') . '</strong> ' . esc_html__('کانال‌های تست متعلق به کاربرِ همان سهم انتخاب‌شده هستند؛ نه حساب مدیر وردپرس و نه همه کانال‌هایی که در افزونه به‌صورت سراسری تنظیم شده‌اند. نام کانال‌های واجد شرایط اکنون داخل فهرست انتخاب سهم نمایش داده می‌شود.', 'hamnoor') . '</p></div>';
            echo '<p class="description">' . esc_html__('بررسی اعلان مرورگر در داشبورد فقط همان مرورگر را می‌آزماید؛ ابزارهای این بخش برای کاربر سهم انتخاب‌شده پیام واقعی می‌فرستند.', 'hamnoor') . '</p>';
            echo '</form>';
        } else {
            echo '<div class="notice inline notice-warning"><p>' . esc_html__('سهم در حال قرائتِ متصل به پنل کاربر و دارای یادآوری فعال برای تست پیدا نشد.', 'hamnoor') . '</p></div>';
        }
        $this->render_last_operational_test();
        $this->render_last_all_channels_test();
        echo '</details>';
    }

    private function render_last_operational_test() {
        $last = get_option('hamnoor_operational_reminder_test_last', array());
        if (!is_array($last) || empty($last['at'])) { return; }
        $labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن','internal'=>__('داخلی','hamnoor'));
        $delivery = isset($last['delivery']) && is_array($last['delivery']) ? $last['delivery'] : array();
        $route = isset($last['route']) && is_array($last['route']) ? $last['route'] : array();
        $status = isset($delivery['status']) ? sanitize_key((string)$delivery['status']) : 'pending';
        $status_labels = array('sent'=>__('موفق','hamnoor'),'retrying'=>__('در صف تلاش مجدد','hamnoor'),'failed'=>__('ناموفق','hamnoor'),'internal_only'=>__('فقط داخلی','hamnoor'),'pending'=>__('در انتظار','hamnoor'));
        $channel = isset($delivery['channel']) ? sanitize_key((string)$delivery['channel']) : '';
        echo '<h3 style="margin-top:24px">' . esc_html__('نتیجه آخرین تست عملیاتی', 'hamnoor') . '</h3>';
        echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
        echo '<tr><th>' . esc_html__('زمان', 'hamnoor') . '</th><td><code>' . esc_html((string)$last['at']) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('سهم / کاربر', 'hamnoor') . '</th><td>#' . (int)$last['part_id'] . ' — ' . esc_html((string)$last['part_title']) . ' — ' . esc_html((string)$last['participant_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('نتیجه ارسال', 'hamnoor') . '</th><td><strong>' . esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status) . '</strong>' . ($channel ? ' — ' . esc_html(isset($labels[$channel]) ? $labels[$channel] : $channel) : '') . '</td></tr>';
        echo '<tr><th>' . esc_html__('اعلان / تلاش‌ها', 'hamnoor') . '</th><td>#' . (int)$last['notification_id'] . ' — ' . (int)(isset($delivery['attempts']) ? $delivery['attempts'] : 0) . '</td></tr>';
        echo '<tr><th>' . esc_html__('زمان‌بندی سهم', 'hamnoor') . '</th><td><strong>' . esc_html(!empty($last['schedule_unchanged']) ? __('بدون تغییر ✓','hamnoor') : __('نیازمند بررسی','hamnoor')) . '</strong></td></tr>';
        echo '</tbody></table>';

        if (!empty($route['rows']) && is_array($route['rows'])) {
            echo '<h4>' . esc_html__('مسیر تصمیم Dispatcher پیش از ارسال', 'hamnoor') . '</h4>';
            echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('کانال','hamnoor') . '</th><th>' . esc_html__('ثبت‌شده','hamnoor') . '</th><th>' . esc_html__('آماده','hamnoor') . '</th><th>' . esc_html__('متصل','hamnoor') . '</th><th>' . esc_html__('فعال برای کاربر','hamnoor') . '</th><th>' . esc_html__('واجد شرایط','hamnoor') . '</th><th>' . esc_html__('علت تصمیم','hamnoor') . '</th></tr></thead><tbody>';
            foreach ($route['rows'] as $row) {
                $key = isset($row['channel']) ? sanitize_key((string)$row['channel']) : '';
                echo '<tr><td>' . esc_html(isset($labels[$key]) ? $labels[$key] : $key) . '</td>';
                foreach (array('registered','available','connected','enabled','eligible') as $field) { echo '<td>' . (!empty($row[$field]) ? '✓' : '—') . '</td>'; }
                $reason = isset($row['reason']) ? sanitize_key((string)$row['reason']) : '';
                $reason_labels = array(
                    'eligible'=>__('واجد شرایط','hamnoor'),
                    'not_registered'=>__('در این نسخه ثبت نشده','hamnoor'),
                    'not_available'=>__('کانال سراسری آماده نیست','hamnoor'),
                    'not_connected'=>__('کاربر اتصال فعال ندارد','hamnoor'),
                    'not_active_for_user'=>__('برای این کاربر فعال نیست','hamnoor'),
                    'provider_exception'=>__('خطا هنگام بررسی Adapter','hamnoor'),
                );
                echo '<td><code>' . esc_html(isset($reason_labels[$reason]) ? $reason_labels[$reason] : ($reason ?: '—')) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function render_last_all_channels_test() {
        $last = get_option('hamnoor_all_channels_test_last', array());
        if (!is_array($last) || empty($last['at'])) { return; }
        $labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن','internal'=>__('داخلی','hamnoor'));
        $dispatch = isset($last['dispatch']) && is_array($last['dispatch']) ? $last['dispatch'] : array();
        $status = isset($dispatch['status']) ? sanitize_key((string)$dispatch['status']) : 'pending';
        $status_labels = array('sent'=>__('همه موفق','hamnoor'),'partial'=>__('بخشی موفق','hamnoor'),'failed'=>__('همه ناموفق','hamnoor'),'internal_only'=>__('بدون کانال بیرونی','hamnoor'),'pending'=>__('در انتظار','hamnoor'));
        echo '<h3 style="margin-top:28px">' . esc_html__('نتیجه آخرین تست همه کانال‌ها', 'hamnoor') . '</h3>';
        echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
        echo '<tr><th>' . esc_html__('زمان', 'hamnoor') . '</th><td><code>' . esc_html((string)$last['at']) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('سهم / کاربر', 'hamnoor') . '</th><td>#' . (int)$last['part_id'] . ' — ' . esc_html((string)$last['part_title']) . ' — ' . esc_html((string)$last['participant_name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('نتیجه کلی', 'hamnoor') . '</th><td><strong>' . esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status) . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__('کانال‌های آزمایش‌شده', 'hamnoor') . '</th><td>' . (int)(isset($dispatch['attempted']) ? $dispatch['attempted'] : 0) . ' — ' . esc_html__('موفق:', 'hamnoor') . ' ' . (int)(isset($dispatch['sent']) ? $dispatch['sent'] : 0) . ' — ' . esc_html__('ناموفق:', 'hamnoor') . ' ' . (int)(isset($dispatch['failed']) ? $dispatch['failed'] : 0) . '</td></tr>';
        echo '<tr><th>' . esc_html__('زمان‌بندی سهم', 'hamnoor') . '</th><td><strong>' . esc_html(!empty($last['schedule_unchanged']) ? __('بدون تغییر ✓','hamnoor') : __('نیازمند بررسی','hamnoor')) . '</strong></td></tr>';
        echo '</tbody></table>';

        $route = isset($last['route']) && is_array($last['route']) ? $last['route'] : array();
        if (!empty($route['rows']) && is_array($route['rows'])) {
            echo '<h4>' . esc_html__('وضعیت اتصال کانال‌ها برای همین کاربر', 'hamnoor') . '</h4>';
            echo '<p class="description">' . esc_html__('«متصل» نتیجه قرارداد فعال Adapter برای همان کاربر است. ستون «فعال برای کاربر» وضعیت رکورد داخلی را برای تشخیص ناسازگاری داده‌های قدیمی نشان می‌دهد؛ تصمیم نهایی در ستون «واجد شرایط» و «علت تصمیم» مشخص است.', 'hamnoor') . '</p>';
            echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('کانال','hamnoor') . '</th><th>' . esc_html__('ثبت‌شده','hamnoor') . '</th><th>' . esc_html__('آماده','hamnoor') . '</th><th>' . esc_html__('متصل به این کاربر','hamnoor') . '</th><th>' . esc_html__('فعال برای کاربر','hamnoor') . '</th><th>' . esc_html__('واجد شرایط ارسال','hamnoor') . '</th><th>' . esc_html__('علت تصمیم','hamnoor') . '</th></tr></thead><tbody>';
            foreach ($route['rows'] as $row) {
                $key = isset($row['channel']) ? sanitize_key((string)$row['channel']) : '';
                echo '<tr><td>' . esc_html(isset($labels[$key]) ? $labels[$key] : $key) . '</td>';
                foreach (array('registered','available','connected','enabled','eligible') as $field) { echo '<td>' . (!empty($row[$field]) ? '✓' : '—') . '</td>'; }
                $reason = isset($row['reason']) ? sanitize_key((string)$row['reason']) : '';
                $reason_labels = array(
                    'eligible'=>__('واجد شرایط','hamnoor'),
                    'not_registered'=>__('در این نسخه ثبت نشده','hamnoor'),
                    'not_available'=>__('کانال سراسری آماده نیست','hamnoor'),
                    'not_connected'=>__('کاربر اتصال فعال ندارد','hamnoor'),
                    'not_active_for_user'=>__('برای این کاربر فعال نیست','hamnoor'),
                    'provider_exception'=>__('خطا هنگام بررسی Adapter','hamnoor'),
                );
                echo '<td><code>' . esc_html(isset($reason_labels[$reason]) ? $reason_labels[$reason] : ($reason ?: '—')) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($dispatch['rows']) && is_array($dispatch['rows'])) {
            echo '<h4>' . esc_html__('نتیجه تفکیکی کانال‌های واقعاً آزمایش‌شده', 'hamnoor') . '</h4>';
            echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__('کانال','hamnoor') . '</th><th>' . esc_html__('وضعیت','hamnoor') . '</th><th>' . esc_html__('خطا','hamnoor') . '</th></tr></thead><tbody>';
            foreach ($dispatch['rows'] as $row) {
                $key = isset($row['channel']) ? sanitize_key((string)$row['channel']) : '';
                $row_status = isset($row['status']) ? sanitize_key((string)$row['status']) : '';
                echo '<tr><td>' . esc_html(isset($labels[$key]) ? $labels[$key] : $key) . '</td><td><strong>' . esc_html('sent' === $row_status ? __('موفق','hamnoor') : __('ناموفق','hamnoor')) . '</strong></td><td>' . esc_html(isset($row['error']) ? (string)$row['error'] : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    public function save_smart_reminder_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_save_smart_reminder_settings');
        $raw = isset($_POST['smart']) && is_array($_POST['smart']) ? wp_unslash($_POST['smart']) : array();
        $settings = class_exists('Hamnoor_Engagement') ? Hamnoor_Engagement::sanitize_smart_reminder_settings($raw) : array();
        update_option('hamnoor_smart_reminder_settings', $settings, false);
        Hamnoor_Helpers::redirect_with_notice(add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'overview'), admin_url('admin.php')), __('قواعد یادآوری هوشمند ذخیره شد.', 'hamnoor'), 'success');
    }

    public function run_reminders_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_run_reminders_now');
        Hamnoor_Engagement::run_internal_reminders();
        $last = get_option('hamnoor_reminder_last_run', array());
        $last = is_array($last) ? $last : array();
        if (!empty($last['disabled'])) {
            $message = __('موتور یادآوری هوشمند غیرفعال است؛ پیامی ارسال نشد.', 'hamnoor');
        } else {
            $message = sprintf(
                __('بررسی هوشمند اجرا شد: %1$d سهم بررسی، %2$d یادآوری ایجاد، %3$d ارسال بیرونی موفق، %4$d در صف Retry، %5$d ناموفق، %6$d فقط داخلی.', 'hamnoor'),
                (int)($last['scanned'] ?? 0),(int)($last['reminders_created'] ?? 0),(int)($last['external_sent'] ?? 0),(int)($last['queued_retry'] ?? 0),(int)($last['external_failed'] ?? 0),(int)($last['internal_only'] ?? 0)
            );
        }
        Hamnoor_Helpers::redirect_with_notice(add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'overview'), admin_url('admin.php')), $message);
    }

    public function test_part_reminder() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_test_part_reminder');
        $part_id = isset($_POST['part_id']) ? (int)wp_unslash($_POST['part_id']) : 0;
        $redirect = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'overview'), admin_url('admin.php'));
        if (!$part_id) {
            Hamnoor_Helpers::redirect_with_notice($redirect, __('یک سهم برای تست انتخاب کنید.', 'hamnoor'), 'error');
        }
        $mode = isset($_POST['test_mode']) ? sanitize_key((string)wp_unslash($_POST['test_mode'])) : 'priority';
        if ('all' === $mode) {
            $result = Hamnoor_Engagement::send_all_channels_test($part_id);
            if (is_wp_error($result)) {
                update_option('hamnoor_all_channels_test_last', array('at'=>current_time('mysql'),'part_id'=>$part_id,'error'=>$result->get_error_message()), false);
                Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error');
            }
            $dispatch = isset($result['dispatch']) && is_array($result['dispatch']) ? $result['dispatch'] : array();
            $safe_rows = array();
            foreach ((array)($dispatch['rows'] ?? array()) as $row) {
                $safe_rows[] = array(
                    'channel'=>isset($row['channel']) ? sanitize_key((string)$row['channel']) : '',
                    'status'=>isset($row['status']) ? sanitize_key((string)$row['status']) : '',
                    'error'=>isset($row['error']) ? sanitize_text_field((string)$row['error']) : '',
                );
            }
            $safe_route_rows = array();
            $raw_route = isset($result['route']) && is_array($result['route']) ? $result['route'] : array();
            foreach ((array)($raw_route['rows'] ?? array()) as $route_row) {
                $safe_route_rows[] = array(
                    'channel'=>isset($route_row['channel']) ? sanitize_key((string)$route_row['channel']) : '',
                    'registered'=>!empty($route_row['registered']) ? 1 : 0,
                    'available'=>!empty($route_row['available']) ? 1 : 0,
                    'connected'=>!empty($route_row['connected']) ? 1 : 0,
                    'enabled'=>!empty($route_row['enabled']) ? 1 : 0,
                    'eligible'=>!empty($route_row['eligible']) ? 1 : 0,
                    'reason'=>isset($route_row['reason']) ? sanitize_key((string)$route_row['reason']) : '',
                );
            }
            $safe = array(
                'at'=>(string)$result['at'],
                'part_id'=>(int)$result['part_id'],
                'user_id'=>(int)$result['user_id'],
                'participant_name'=>sanitize_text_field((string)$result['participant_name']),
                'participant_phone'=>sanitize_text_field((string)$result['participant_phone']),
                'part_title'=>sanitize_text_field((string)$result['part_title']),
                'notification_id'=>(int)$result['notification_id'],
                'route'=>array(
                    'winner'=>isset($raw_route['winner']) ? sanitize_key((string)$raw_route['winner']) : '',
                    'supplemental'=>array_values(array_filter(array_map('sanitize_key', (array)($raw_route['supplemental'] ?? array())))),
                    'rows'=>$safe_route_rows,
                ),
                'dispatch'=>array(
                    'status'=>isset($dispatch['status']) ? sanitize_key((string)$dispatch['status']) : 'pending',
                    'attempted'=>isset($dispatch['attempted']) ? (int)$dispatch['attempted'] : 0,
                    'sent'=>isset($dispatch['sent']) ? (int)$dispatch['sent'] : 0,
                    'failed'=>isset($dispatch['failed']) ? (int)$dispatch['failed'] : 0,
                    'rows'=>$safe_rows,
                ),
                'schedule_unchanged'=>!empty($result['schedule_unchanged']) ? 1 : 0,
            );
            update_option('hamnoor_all_channels_test_last', $safe, false);
            $attempted = (int)$safe['dispatch']['attempted'];
            $sent = (int)$safe['dispatch']['sent'];
            $failed = (int)$safe['dispatch']['failed'];
            if (!$attempted) {
                $message = __('تست همه کانال‌ها اجرا شد، اما برای کاربرِ سهم انتخاب‌شده هیچ کانال بیرونی متصل و فعالی وجود نداشت. آماده‌بودن سراسری یک پیام‌رسان به‌معنای اتصال آن به این کاربر نیست.', 'hamnoor');
                $notice = 'warning';
            } elseif ($failed && !$sent && 1 === $attempted) {
                $only_channel = !empty($safe['dispatch']['rows'][0]['channel']) ? sanitize_key((string)$safe['dispatch']['rows'][0]['channel']) : '';
                $labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن');
                $only_label = isset($labels[$only_channel]) ? $labels[$only_channel] : $only_channel;
                $message = sprintf(__('برای کاربرِ این سهم فقط «%s» واجد شرایط بود و همان کانال ناموفق شد. سایر پیام‌رسان‌ها برای این کاربر متصل/فعال نیستند؛ جدول مسیر تصمیم را پایین صفحه ببینید.', 'hamnoor'), $only_label ?: __('یک کانال','hamnoor'));
                $notice = 'error';
            } elseif ($failed && $sent) {
                $message = sprintf(__('تست همه کانال‌ها تمام شد: %1$d کانال موفق و %2$d کانال ناموفق. جزئیات پایین صفحه و در «گزارش ارسال» ثبت شده است.', 'hamnoor'), $sent, $failed);
                $notice = 'warning';
            } elseif ($failed) {
                $message = sprintf(__('تست همه کانال‌ها انجام شد، اما هر %d کانال آزمایش‌شده ناموفق بودند. جزئیات در گزارش ارسال ثبت شده است.', 'hamnoor'), $failed);
                $notice = 'error';
            } else {
                $message = sprintf(__('تست همه کانال‌ها موفق بود: پیام واقعی به هر %d کانال متصل و فعال ارسال شد و زمان‌بندی سهم تغییر نکرد.', 'hamnoor'), $sent);
                $notice = 'success';
            }
            Hamnoor_Helpers::redirect_with_notice($redirect, $message, $notice);
        }

        $result = Hamnoor_Engagement::send_operational_test($part_id);
        if (is_wp_error($result)) {
            update_option('hamnoor_operational_reminder_test_last', array(
                'at'=>current_time('mysql'),'part_id'=>$part_id,'error'=>$result->get_error_message()
            ), false);
            Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error');
        }
        // Keep only scalar/boolean diagnostics and the compact delivery summary;
        // no tokens, chat IDs or provider secrets are stored here.
        $raw_route = isset($result['route']) && is_array($result['route']) ? $result['route'] : array();
        $safe_route_rows = array();
        foreach ((array)($raw_route['rows'] ?? array()) as $route_row) {
            $safe_route_rows[] = array(
                'channel'=>isset($route_row['channel']) ? sanitize_key((string)$route_row['channel']) : '',
                'registered'=>!empty($route_row['registered']) ? 1 : 0,
                'available'=>!empty($route_row['available']) ? 1 : 0,
                'connected'=>!empty($route_row['connected']) ? 1 : 0,
                'enabled'=>!empty($route_row['enabled']) ? 1 : 0,
                'eligible'=>!empty($route_row['eligible']) ? 1 : 0,
                'reason'=>isset($route_row['reason']) ? sanitize_key((string)$route_row['reason']) : '',
            );
        }
        $safe = array(
            'at'=>(string)$result['at'],
            'part_id'=>(int)$result['part_id'],
            'user_id'=>(int)$result['user_id'],
            'participant_name'=>sanitize_text_field((string)$result['participant_name']),
            'participant_phone'=>sanitize_text_field((string)$result['participant_phone']),
            'part_title'=>sanitize_text_field((string)$result['part_title']),
            'notification_id'=>(int)$result['notification_id'],
            'route'=>array(
                'winner'=>isset($raw_route['winner']) ? sanitize_key((string)$raw_route['winner']) : '',
                'supplemental'=>array_values(array_filter(array_map('sanitize_key', (array)($raw_route['supplemental'] ?? array())))),
                'rows'=>$safe_route_rows,
            ),
            'delivery'=>array(
                'status'=>isset($result['delivery']['status']) ? sanitize_key((string)$result['delivery']['status']) : 'pending',
                'channel'=>isset($result['delivery']['channel']) ? sanitize_key((string)$result['delivery']['channel']) : '',
                'error'=>isset($result['delivery']['error']) ? sanitize_text_field((string)$result['delivery']['error']) : '',
                'attempts'=>isset($result['delivery']['attempts']) ? (int)$result['delivery']['attempts'] : 0,
            ),
            'schedule_before'=>isset($result['schedule_before']) && is_array($result['schedule_before']) ? $result['schedule_before'] : array(),
            'schedule_after'=>isset($result['schedule_after']) && is_array($result['schedule_after']) ? $result['schedule_after'] : array(),
            'schedule_unchanged'=>!empty($result['schedule_unchanged']) ? 1 : 0,
        );
        update_option('hamnoor_operational_reminder_test_last', $safe, false);
        $status = (string)$safe['delivery']['status'];
        $channel = (string)$safe['delivery']['channel'];
        if ('sent' === $status) {
            $message = sprintf(__('تست عملیاتی با موفقیت از کانال %s ارسال شد و زمان‌بندی اصلی سهم تغییر نکرد.', 'hamnoor'), Hamnoor_Engagement::channel_label_for_admin($channel));
        } elseif ('retrying' === $status) {
            $message = __('تست عملیاتی اجرا شد؛ ارسال موقتاً ناموفق بود و در صف تلاش مجدد قرار گرفت. زمان‌بندی اصلی سهم تغییر نکرد.', 'hamnoor');
        } elseif ('failed' === $status) {
            $message = __('تست عملیاتی اجرا شد اما ارسال بیرونی پس از تلاش‌ها ناموفق بود؛ جزئیات در «گزارش ارسال» ثبت شده است.', 'hamnoor');
        } else {
            $message = __('تست عملیاتی ثبت شد، اما برای این کاربر کانال بیرونی واجد شرایط وجود نداشت.', 'hamnoor');
        }
        Hamnoor_Helpers::redirect_with_notice($redirect, $message, 'sent' === $status ? 'success' : 'warning');
    }

    public function retry_notification_delivery() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی کافی ندارید.', 'hamnoor')); }
        $delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;
        check_admin_referer('hamnoor_retry_notification_delivery_' . $delivery_id);
        $redirect = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'deliveries'), admin_url('admin.php'));
        $result = Hamnoor_Notifications::available() ? Hamnoor_Notifications::retry_delivery($delivery_id) : new WP_Error('missing', __('هسته اعلان در دسترس نیست.', 'hamnoor'));
        if (is_wp_error($result)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error');
        }
        Hamnoor_Helpers::redirect_with_notice($redirect, __('ارسال دوباره وارد صف شد و یک تلاش فوری انجام شد.', 'hamnoor'), 'success');
    }

    public function process_notification_queue_now() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی کافی ندارید.', 'hamnoor')); }
        check_admin_referer('hamnoor_process_notification_queue');
        $redirect = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'deliveries'), admin_url('admin.php'));
        $processed = Hamnoor_Notifications::available() ? Hamnoor_Notifications::process_queue(100) : 0;
        Hamnoor_Helpers::redirect_with_notice($redirect, sprintf(__('صف اعلان پردازش شد؛ %d ردیف بررسی شد.', 'hamnoor'), (int)$processed), 'success');
    }

    private function render_delivery_report() {
        $stats = Hamnoor_Notifications::available() ? Hamnoor_Notifications::delivery_stats(7) : array();
        $queue = Hamnoor_Notifications::available() ? Hamnoor_Notifications::queue_stats() : array();
        $rows = Hamnoor_Notifications::available() ? Hamnoor_Notifications::recent_deliveries(100) : array();
        $last_run = get_option('hamnoor_notification_queue_last_run', array());
        $last_run = is_array($last_run) ? $last_run : array();
        $next_cron = wp_next_scheduled('hamnoor_notification_queue_worker');

        echo '<h2>' . esc_html__('گزارش تحویل و صف اعلان‌ها', 'hamnoor') . '</h2>';
        echo '<p>' . esc_html__('Web Push مستقل و مکمل است. پیام‌رسان‌های ماندگار با اولین ارسال موفق متوقف می‌شوند. خطاهای موقت در صف باقی می‌مانند و طبق برنامه ۱۵ دقیقه، ۱ ساعت و ۶ ساعت دوباره امتحان می‌شوند.', 'hamnoor') . '</p>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 18px">';
        $pending = isset($queue['pending']) ? (int)$queue['pending'] : 0;
        $cards = array(
            __('۷ روز اخیر', 'hamnoor') => isset($stats['total']) ? (int)$stats['total'] : 0,
            __('ارسال موفق', 'hamnoor') => isset($stats['sent']) ? (int)$stats['sent'] : 0,
            __('در صف / تلاش مجدد', 'hamnoor') => $pending,
            __('ناموفق نهایی', 'hamnoor') => isset($stats['failed']) ? (int)$stats['failed'] : 0,
            __('فقط داخلی', 'hamnoor') => isset($stats['internal_only']) ? (int)$stats['internal_only'] : 0,
        );
        foreach ($cards as $label=>$value) {
            echo '<div class="card" style="min-width:150px;margin:0;padding:14px 18px"><div style="font-size:24px;font-weight:700">' . (int)$value . '</div><div>' . esc_html($label) . '</div></div>';
        }
        echo '</div>';

        $process_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_process_notification_queue'), 'hamnoor_process_notification_queue');
        echo '<div class="card" style="max-width:1100px;margin:0 0 18px;padding:14px 18px">';
        echo '<strong>' . esc_html__('وضعیت Worker صف', 'hamnoor') . '</strong><br>';
        echo esc_html__('آخرین اجرا:', 'hamnoor') . ' <code>' . esc_html(!empty($last_run['at']) ? (string)$last_run['at'] : __('هنوز اجرا نشده', 'hamnoor')) . '</code> &nbsp; ';
        echo esc_html__('اجرای بعدی:', 'hamnoor') . ' <code>' . esc_html($next_cron ? wp_date('Y-m-d H:i:s', $next_cron) : __('زمان‌بندی نشده', 'hamnoor')) . '</code> &nbsp; ';
        if (!empty($queue['next_attempt'])) { echo esc_html__('نزدیک‌ترین تلاش:', 'hamnoor') . ' <code>' . esc_html((string)$queue['next_attempt']) . '</code> &nbsp; '; }
        echo '<a class="button button-secondary" href="' . esc_url($process_url) . '">' . esc_html__('پردازش صف اکنون', 'hamnoor') . '</a>';
        echo '</div>';

        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';
        foreach (array(__('زمان','hamnoor'),__('کاربر','hamnoor'),__('اعلان','hamnoor'),__('کانال','hamnoor'),__('وضعیت','hamnoor'),__('تلاش','hamnoor'),__('تلاش بعدی','hamnoor'),__('جزئیات','hamnoor'),__('عملیات','hamnoor')) as $head) { echo '<th>' . esc_html($head) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ((array)$rows as $row) {
            $status = sanitize_key((string)$row->status);
            $status_labels = array(
                'sent'=>__('موفق','hamnoor'),'failed'=>__('ناموفق نهایی','hamnoor'),'sending'=>__('در حال ارسال','hamnoor'),
                'internal_only'=>__('فقط داخلی','hamnoor'),'queued'=>__('در صف','hamnoor'),'retry_wait'=>__('منتظر تلاش مجدد','hamnoor'),
                'cancelled'=>__('لغوشده برای جلوگیری از تکرار','hamnoor'),
            );
            $channel_labels = array('web_push'=>'Web Push','eitaa'=>'ایتا','bale'=>'بله','rubika'=>'روبیکا','whatsapp'=>'واتساپ','telegram'=>'تلگرام','app_push'=>'Push اپلیکیشن','internal'=>__('داخلی','hamnoor'));
            $channel = sanitize_key((string)$row->channel);
            $detail = '';
            if (!empty($row->error_message)) { $detail = (string)$row->error_message; }
            elseif (!empty($row->sent_at)) { $detail = sprintf(__('ارسال: %s','hamnoor'), (string)$row->sent_at); }
            $user = trim((string)$row->display_name);
            if (!empty($row->phone)) { $user .= ($user ? ' — ' : '') . (string)$row->phone; }
            $notification = trim((string)$row->notification_title);
            if (!empty($row->related_type) && !empty($row->related_id)) { $notification .= ' #' . (int)$row->related_id; }
            $attempt_text = (int)$row->attempt_count;
            if (isset($row->max_attempts) && (int)$row->max_attempts > 0) { $attempt_text .= ' / ' . (int)$row->max_attempts; }
            $action = '—';
            if (in_array($status, array('failed','retry_wait'), true) && 'internal' !== $channel) {
                $retry_url = wp_nonce_url(admin_url('admin-post.php?action=hamnoor_retry_notification_delivery&delivery_id=' . (int)$row->id), 'hamnoor_retry_notification_delivery_' . (int)$row->id);
                $action = '<a class="button button-small" href="' . esc_url($retry_url) . '">' . esc_html__('تلاش مجدد', 'hamnoor') . '</a>';
            }
            echo '<tr>';
            echo '<td><code>' . esc_html((string)$row->created_at) . '</code></td>';
            echo '<td>' . esc_html($user ?: ('#' . (int)$row->user_id)) . '</td>';
            echo '<td>' . esc_html($notification ?: ('#' . (int)$row->notification_id)) . '</td>';
            echo '<td>' . esc_html(isset($channel_labels[$channel]) ? $channel_labels[$channel] : $channel) . '</td>';
            echo '<td><strong>' . esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status) . '</strong></td>';
            echo '<td>' . esc_html((string)$attempt_text) . '</td>';
            echo '<td><code>' . esc_html(!empty($row->next_attempt_at) ? (string)$row->next_attempt_at : '—') . '</code></td>';
            echo '<td style="max-width:360px">' . esc_html($detail ?: '—') . '</td>';
            echo '<td>' . $action . '</td>';
            echo '</tr>';
        }
        if (!$rows) { echo '<tr><td colspan="9">' . esc_html__('هنوز گزارشی ثبت نشده است.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public function page_docs() {
        echo '<div class="wrap hamnoor-admin-wrap"><h1>راهنمای هم‌نور</h1><div class="hamnoor-docs"><h2>برگه‌های اصلی</h2><ul><li><code>[hamnoor_khatm_list]</code> فهرست پویش‌های عمومی</li><li><code>[hamnoor_dashboard]</code> داشبورد کاربران</li><li><code>[hamnoor_create_khatm]</code> صفحه ایجاد یا ویرایش پویش</li></ul><h2>فرایند کاربر</h2><p>پنل شخصی کاربر هنگام نخستین مشارکت یا ورود از یکی از پیام‌رسان‌های متصل ساخته می‌شود. در مرورگر تازه، انتخاب سهم بدون پیامک ممکن است؛ اما ورود به داشبورد با کد یک‌بارمصرف یا هویت معتبر پیام‌رسان انجام می‌شود.</p><h2>تأیید شماره</h2><p>مشارکت اولیه به دریافت پیامک نیاز ندارد. مشاهده سوابق حساب و عملیات حساس پس از اثبات مالکیت شماره یا ورود معتبر پیام‌رسان در دسترس قرار می‌گیرد. شماره موبایل در نشانی صفحات قرار نمی‌گیرد.</p><h2>صفحات</h2><p>افزونه سه برگه «پویش‌های قرآن»، «داشبورد هم‌نور» و «ایجاد پویش جدید» را به‌صورت خودکار می‌سازد.</p></div></div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه ذخیره تنظیمات هم‌نور را ندارید.', 'hamnoor'), esc_html__('دسترسی غیرمجاز', 'hamnoor'), array('response'=>403));
        }
        check_admin_referer('hamnoor_save_settings');
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'general';
        $allowed_tabs = array('general','links','create','export','seo','appearance','reminders','claims','danger');
        if (!in_array($tab, $allowed_tabs, true)) { $tab = 'general'; }
        $redirect = add_query_arg('tab', $tab, admin_url('admin.php?page=hamnoor-settings'));
        $validated = self::validate_settings_input($tab, $_POST);
        if (is_wp_error($validated)) {
            Hamnoor_Helpers::redirect_with_notice($redirect, $validated->get_error_message(), 'error');
        }
        Hamnoor_Helpers::update_settings($validated);
        Hamnoor_Helpers::redirect_with_notice($redirect, __('تنظیمات با موفقیت ذخیره شد.', 'hamnoor'), 'success');
    }

    public static function validate_settings_input($tab, $data) {
        $tab = sanitize_key((string)$tab);
        $data = is_array($data) ? $data : array();
        if ('links' !== $tab) { return $data; }

        $help_page_id = isset($data['help_page_id']) ? absint(wp_unslash($data['help_page_id'])) : 0;
        if ($help_page_id && ('page' !== get_post_type($help_page_id) || 'publish' !== get_post_status($help_page_id))) {
            return new WP_Error('hamnoor_invalid_help_page', __('برگه راهنمای انتخاب‌شده معتبر یا منتشرشده نیست؛ هیچ تغییری ذخیره نشد.', 'hamnoor'));
        }
        $raw_help_url = isset($data['help_custom_url']) ? trim((string)wp_unslash($data['help_custom_url'])) : '';
        $safe_help_url = esc_url_raw($raw_help_url, array('http','https'));
        $scheme = $safe_help_url ? strtolower((string)wp_parse_url($safe_help_url, PHP_URL_SCHEME)) : '';
        $host = $safe_help_url ? (string)wp_parse_url($safe_help_url, PHP_URL_HOST) : '';
        if ('' !== $raw_help_url && (!$safe_help_url || !in_array($scheme, array('http','https'), true) || '' === $host)) {
            return new WP_Error('hamnoor_invalid_help_url', __('نشانی جایگزین راهنما معتبر نیست؛ یک نشانی کامل با http یا https وارد کنید. هیچ تغییری ذخیره نشد.', 'hamnoor'));
        }
        $data['help_page_id'] = $help_page_id;
        $data['help_custom_url'] = $safe_help_url;
        return $data;
    }

    public function save_khatm() {
        $khatm_id = isset($_POST['khatm_id']) ? (int) $_POST['khatm_id'] : 0;
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_admin_save_khatm_' . $khatm_id);
        $edit_url = admin_url('admin.php?page=hamnoor&view=edit&khatm_id=' . $khatm_id);
        $featured_data = Hamnoor_Featured_Service::sanitize_admin_input($_POST);
        $featured_valid = Hamnoor_Featured_Service::validate_admin_data($featured_data);
        if (is_wp_error($featured_valid)) {
            Hamnoor_Helpers::redirect_with_form($edit_url, $featured_valid->get_error_message(), 'error', 'admin_featured_' . $khatm_id, $featured_data, $this->feature_errors_from_wp_error($featured_valid));
        }
        $existing_khatm = Hamnoor_DB::get_khatm($khatm_id);
        $requested_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : ($existing_khatm ? (string)$existing_khatm->status : 'pending_review');
        $review_note = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';
        $pause_note = isset($_POST['pause_note']) ? sanitize_textarea_field(wp_unslash($_POST['pause_note'])) : '';
        $data = Hamnoor_DB::sanitize_khatm_data($_POST);
        // Status transitions are handled centrally so review notes, notifications
        // and audit events cannot be bypassed by the generic update path.
        if ($existing_khatm) { $data['status'] = (string)$existing_khatm->status; }
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $upload->get_error_message(), 'error'); }
        if ($upload) { $data = array_merge($data, $upload); }
        $result = Hamnoor_DB::update_khatm($khatm_id, $data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $result->get_error_message(), 'error'); }
        if ($existing_khatm && 'paused' === (string)$existing_khatm->status && 'paused' === $requested_status && isset($existing_khatm->pause_source) && 'admin' === (string)$existing_khatm->pause_source && (string)$existing_khatm->pause_note !== (string)$pause_note) {
            global $wpdb;
            $wpdb->update(Hamnoor_DB::table('khatms'), array('pause_note'=>$pause_note ?: null,'updated_at'=>current_time('mysql')), array('id'=>$khatm_id));
            Hamnoor_DB::log($khatm_id, 0, 0, Hamnoor_Mobile_Access::actor_id(), 'admin_pause_note_updated', __('دلیل توقف مدیریتی ویرایش شد.', 'hamnoor'));
        }
        if ($existing_khatm && $requested_status !== (string)$existing_khatm->status) {
            $state_note = 'paused' === $requested_status ? $pause_note : $review_note;
            $state_result = Hamnoor_Khatm_Service::change_status($khatm_id, $requested_status, $state_note, 'admin');
            if (is_wp_error($state_result)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $state_result->get_error_message(), 'error'); }
        }
        $feature_result = Hamnoor_Featured_Service::save_admin_settings($khatm_id, $featured_data, get_current_user_id());
        if (is_wp_error($feature_result)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $feature_result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($edit_url, __('پویش و تنظیمات ویژه بودن ذخیره شد.', 'hamnoor'));
    }

    public function review_feature_request() {
        $khatm_id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0;
        $decision = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : '';
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        if (!in_array($decision, array('approve', 'reject'), true)) { wp_die(esc_html__('تصمیم بررسی درخواست معتبر نیست.', 'hamnoor')); }
        check_admin_referer('hamnoor_review_feature_request_' . $khatm_id . '_' . $decision);
        $result = 'approve' === $decision ? Hamnoor_Featured_Service::approve_request($khatm_id, get_current_user_id()) : Hamnoor_Featured_Service::reject_request($khatm_id, get_current_user_id());
        $redirect = admin_url('admin.php?page=hamnoor');
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($redirect, $result->get_error_message(), 'error'); }
        $message = 'approve' === $decision ? __('درخواست تأیید شد و پویش ویژه شد.', 'hamnoor') : __('درخواست ویژه شدن رد شد.', 'hamnoor');
        Hamnoor_Helpers::redirect_with_notice($redirect, $message);
    }

    public function export_csv() { $this->check_export(); Hamnoor_Export::download_csv((int) $_GET['khatm_id']); }
    public function export_xlsx() { $this->check_export(); Hamnoor_Export::download_xlsx((int) $_GET['khatm_id']); }
    private function check_export() { $id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0; check_admin_referer('hamnoor_export_' . $id); $actor = Hamnoor_Mobile_Access::current_user_id(); $can = apply_filters('hamnoor_can_export_khatm', Hamnoor_Export::can_export($id, $actor), $id, $actor); if (!$can) { wp_die(esc_html__('دسترسی خروجی این پویش را ندارید.', 'hamnoor')); } }
    public function bulk_status() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bulk_status');
        $status = isset($_POST['bulk_status']) ? sanitize_key(wp_unslash($_POST['bulk_status'])) : '';
        if (!in_array($status, array('pending_review','active','paused','expired','completed','cancelled','archived','failed'), true)) {
            Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), __('وضعیت انتخاب‌شده برای عملیات گروهی معتبر نیست.', 'hamnoor'), 'error');
        }
        $ids = isset($_POST['khatm_ids']) && is_array($_POST['khatm_ids']) ? array_map('intval', wp_unslash($_POST['khatm_ids'])) : array();
        $ok = 0; $failed = 0;
        foreach ($ids as $id) {
            if (!$id) { continue; }
            $result = Hamnoor_Khatm_Service::change_status($id, $status, '', 'admin');
            if (is_wp_error($result)) { $failed++; } else { $ok++; }
        }
        $message = sprintf(__('عملیات گروهی پایان یافت. موفق: %1$d، ناموفق: %2$d', 'hamnoor'), (int)$ok, (int)$failed);
        Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), $message, $failed ? 'warning' : 'success');
    }

    public function change_status() {
        $id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0;
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_change_status_' . $id);
        $result = Hamnoor_Khatm_Service::change_status($id, $status, '', 'admin');
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), __('وضعیت پویش تغییر کرد.', 'hamnoor'));
    }
}
