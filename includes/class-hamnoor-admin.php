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
        add_action('admin_post_hamnoor_export_xlsx', array($this, 'export_xlsx'));
        add_action('admin_post_hamnoor_change_status', array($this, 'change_status'));
        add_action('admin_post_hamnoor_bulk_status', array($this, 'bulk_status'));
        add_action('admin_post_hamnoor_review_feature_request', array($this, 'review_feature_request'));
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
        add_submenu_page('hamnoor', __('فهرست ختم‌ها', 'hamnoor'), __('فهرست ختم‌ها', 'hamnoor'), 'manage_options', 'hamnoor', array($this, 'page_khatms'));
        add_submenu_page('hamnoor', __('تنظیمات', 'hamnoor'), __('تنظیمات', 'hamnoor'), 'manage_options', 'hamnoor-settings', array($this, 'page_settings'));
        add_submenu_page('hamnoor', __('متن‌ها', 'hamnoor'), __('متن‌ها', 'hamnoor'), 'manage_options', 'hamnoor-texts', array($this, 'page_texts'));
        add_submenu_page('hamnoor', __('سلامت افزونه', 'hamnoor'), __('سلامت افزونه', 'hamnoor'), 'manage_options', 'hamnoor-health', array($this, 'page_health'));
        add_submenu_page('hamnoor', __('مستندات', 'hamnoor'), __('مستندات', 'hamnoor'), 'manage_options', 'hamnoor-docs', array($this, 'page_docs'));
    }

    public function enqueue_assets($hook) {
        if (false === strpos((string) $hook, 'hamnoor')) {
            return;
        }
        wp_enqueue_style('hamnoor-admin', HAMNOOR_URL . 'assets/css/hamnoor-admin.css', array(), HAMNOOR_VERSION);
        wp_enqueue_script('hamnoor-admin', HAMNOOR_URL . 'assets/js/hamnoor-admin.js', array(), HAMNOOR_VERSION, true);
    }

    public function page_khatms() {
        if (!current_user_can('manage_options')) { return; }
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
        $khatm_id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0;
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('هم‌نور: مدیریت ختم‌ها', 'hamnoor') . '</h1>' . Hamnoor_Helpers::current_notice();
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
        echo '<input type="search" name="s" value="' . esc_attr($filters['s']) . '" placeholder="' . esc_attr__('جستجو در عنوان، نیت، ایجادکننده، موبایل و ایمیل', 'hamnoor') . '">';
        $this->select('privacy', $filters['privacy'], array('' => 'همه دسترسی‌ها', 'public' => 'عمومی', 'private' => 'خصوصی'));
        $this->select('status', $filters['status'], array('' => 'همه وضعیت‌ها', 'pending_review' => 'در انتظار بررسی', 'active' => 'فعال', 'completed' => 'تکمیل‌شده', 'closed' => 'بسته', 'archived' => 'آرشیو', 'failed' => 'ناموفق'));
        $this->select('khatm_type', $filters['khatm_type'], array('' => 'همه انواع', 'page' => 'صفحه', 'juz' => 'جزء', 'surah' => 'سوره', 'repeated_surah' => 'تکراری سوره'));
        $this->select('featured_filter', $filters['featured_filter'], array('' => 'همه وضعیت‌های ویژه', 'normal' => 'عادی', 'featured' => 'ویژه', 'pinned' => 'پین‌شده', 'pending' => 'درخواست در انتظار'));
        submit_button(__('فیلتر', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-bulk-form">';
        wp_nonce_field('hamnoor_bulk_status');
        echo '<input type="hidden" name="action" value="hamnoor_bulk_status"><select name="bulk_status"><option value="">' . esc_html__('تغییر وضعیت گروهی', 'hamnoor') . '</option><option value="active">' . esc_html__('انتشار / فعال‌سازی', 'hamnoor') . '</option><option value="closed">' . esc_html__('بستن', 'hamnoor') . '</option><option value="completed">' . esc_html__('تکمیل دستی', 'hamnoor') . '</option><option value="archived">' . esc_html__('آرشیو', 'hamnoor') . '</option></select> ';
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
            foreach (array('active'=>('pending_review' === (string) $khatm->status ? __('انتشار','hamnoor') : __('فعال‌سازی','hamnoor')),'closed'=>__('بستن','hamnoor'),'completed'=>__('تکمیل دستی','hamnoor'),'archived'=>__('آرشیو','hamnoor')) as $st=>$label) {
                if ($st !== $khatm->status) { echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnoor_change_status&khatm_id=' . (int) $khatm->id . '&status=' . $st), 'hamnoor_change_status_' . (int) $khatm->id)) . '">' . esc_html($label) . '</a> '; }
            }
            echo '</details></td></tr>';
        }
        if (!$items) { echo '<tr><td colspan="10">' . esc_html__('ختمی یافت نشد.', 'hamnoor') . '</td></tr>'; }
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
        if (!$khatm) { echo '<p>' . esc_html__('ختم پیدا نشد.', 'hamnoor') . '</p>'; return; }
        $feature_form = Hamnoor_Helpers::get_form_state('admin_featured_' . (int) $khatm_id);
        $feature_old = isset($feature_form['data']) && is_array($feature_form['data']) ? $feature_form['data'] : array();
        $feature_errors = isset($feature_form['errors']) && is_array($feature_form['errors']) ? $feature_form['errors'] : array();
        $featured_status = isset($feature_old['featured_status']) ? Hamnoor_Featured_Service::normalize_status($feature_old['featured_status']) : Hamnoor_Featured_Service::normalize_status(isset($khatm->featured_status) ? $khatm->featured_status : 'normal');
        $featured_label = isset($feature_old['featured_label']) ? $feature_old['featured_label'] : (isset($khatm->featured_label) ? $khatm->featured_label : '');
        $featured_starts = isset($feature_old['featured_starts_at_raw']) ? $feature_old['featured_starts_at_raw'] : (!empty($khatm->featured_starts_at) ? Hamnoor_Jalali::to_jalali($khatm->featured_starts_at, true) : '');
        $featured_ends = isset($feature_old['featured_ends_at_raw']) ? $feature_old['featured_ends_at_raw'] : (!empty($khatm->featured_until) ? Hamnoor_Jalali::to_jalali($khatm->featured_until, true) : '');
        $featured_weight = isset($feature_old['featured_weight']) ? (int) $feature_old['featured_weight'] : (isset($khatm->featured_weight) ? (int) $khatm->featured_weight : 0);
        $featured_reason = isset($feature_old['featured_reason']) ? $feature_old['featured_reason'] : (isset($khatm->featured_reason) ? $khatm->featured_reason : '');
        echo '<h2>' . esc_html__('ویرایش ختم', 'hamnoor') . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="hamnoor-admin-form">';
        wp_nonce_field('hamnoor_admin_save_khatm_' . (int) $khatm->id);
        echo '<input type="hidden" name="action" value="hamnoor_admin_save_khatm"><input type="hidden" name="khatm_id" value="' . (int) $khatm->id . '">';
        echo '<table class="form-table"><tbody>';
        $this->field('title', __('عنوان', 'hamnoor'), $khatm->title, 'text');
        $this->field('owner_name', __('نام ایجادکننده', 'hamnoor'), $khatm->owner_name, 'text');
        $this->field('owner_phone', __('موبایل ایجادکننده', 'hamnoor'), $khatm->owner_phone, 'text');
        $this->field('owner_email', __('ایمیل ایجادکننده', 'hamnoor'), $khatm->owner_email, 'email');
        echo '<tr><th>' . esc_html__('نیت', 'hamnoor') . '</th><td><textarea name="intention" rows="4" class="large-text">' . esc_textarea($khatm->intention) . '</textarea></td></tr>';
        echo '<tr><th>' . esc_html__('توضیحات', 'hamnoor') . '</th><td><textarea name="description" rows="5" class="large-text">' . esc_textarea($khatm->description) . '</textarea></td></tr>';
        echo '<tr><th>' . esc_html__('تصویر', 'hamnoor') . '</th><td><input type="file" name="cover_file" accept="image/*">'; if ($khatm->cover_url) { echo '<br><img class="hamnoor-admin-thumb" src="' . esc_url($khatm->cover_url) . '" alt=""><p class="description">' . esc_html__('اگر تصویر جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.', 'hamnoor') . '</p><label><input type="checkbox" name="remove_cover" value="1"> ' . esc_html__('حذف تصویر فعلی', 'hamnoor') . '</label>'; } echo '</td></tr>';
        $settings = Hamnoor_Helpers::settings();
        if (!empty($settings['allow_external_cover_url']) && '1' === (string) $settings['allow_external_cover_url']) {
            $current_external_cover = empty($khatm->cover_id) && !empty($khatm->cover_url) ? $khatm->cover_url : '';
            echo '<tr><th><label for="cover_url">' . esc_html__('نشانی تصویر خارجی، اختیاری', 'hamnoor') . '</label></th><td><input class="regular-text" type="url" id="cover_url" name="cover_url" value="' . esc_attr($current_external_cover) . '"><p class="description">' . esc_html__('نشانی مستقیم یک تصویر با پروتکل HTTPS یا HTTP را وارد کنید. می‌توانید به‌جای بارگذاری فایل از این گزینه استفاده کنید.', 'hamnoor') . '</p></td></tr>';
        }
        echo '<tr><th>' . esc_html__('ساختار ختم', 'hamnoor') . '</th><td><code>' . esc_html($khatm->khatm_type) . '</code> — ' . esc_html(sprintf(__('تعداد ختم: %d، سهم هر ختم: %d، کل سهم‌ها: %d', 'hamnoor'), (int)$khatm->khatm_count, (int)$khatm->parts_per_khatm, (int)$khatm->total_parts)) . '<p class="description">' . esc_html__('نوع ختم و تعداد دوره‌ها پس از ایجاد قابل تغییر نیست تا سهم‌های موجود دچار ناسازگاری نشوند.', 'hamnoor') . '</p></td></tr>';
        $this->field('max_khatm_count', __('حداکثر تعداد ختم قابل انتخاب برای این ختم', 'hamnoor'), isset($khatm->max_khatm_count) ? $khatm->max_khatm_count : '', 'number');
        $this->field('max_repeat_count', __('حداکثر اختصاصی تکرار سوره برای این ختم', 'hamnoor'), isset($khatm->max_repeat_count) ? $khatm->max_repeat_count : '', 'number');
        echo '<tr><th></th><td><p class="description">' . esc_html__('اگر خالی باشد، مقدار عمومی تنظیمات استفاده می‌شود. تغییر این حد، تعداد سهم‌های ختم‌های ایجادشده را بازنویسی نمی‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('دسترسی', 'hamnoor') . '</th><td>'; $this->select('privacy', $khatm->privacy, array('public'=>'عمومی','private'=>'خصوصی')); echo '</td></tr>';
        echo '<tr><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><td>'; $this->select('status', $khatm->status, array('pending_review'=>'در انتظار بررسی','active'=>'منتشرشده / فعال','completed'=>'تکمیل‌شده','closed'=>'بسته','archived'=>'آرشیو','failed'=>'ناموفق')); echo '</td></tr>';
        echo '<tr><th>' . esc_html__('نمایش در نتایج گوگل', 'hamnoor') . '</th><td><label><input type="checkbox" name="indexable" value="1"' . checked((int) $khatm->indexable, 1, false) . '> ' . esc_html__('نمایش در نتایج گوگل در صورت عمومی بودن', 'hamnoor') . '</label></td></tr>';
        $this->field('starts_at_jalali', __('تاریخ شروع شمسی', 'hamnoor'), Hamnoor_Jalali::to_jalali($khatm->starts_at, true), 'text');
        echo '<tr><th></th><td><p class="description">' . esc_html__('اختیاری؛ تا پیش از این زمان، ختم می‌تواند در فهرست و صفحه خود نمایش داده شود، اما انتخاب سهم و ثبت قرائت برای شرکت‌کنندگان امکان‌پذیر نیست.', 'hamnoor') . '</p></td></tr>';
        $this->field('ends_at_jalali', __('پایان مهلت مشارکت', 'hamnoor'), Hamnoor_Jalali::to_jalali($khatm->ends_at, true), 'text');
        echo '<tr><th></th><td><p class="description">' . esc_html__('اختیاری؛ پس از این زمان، انتخاب سهم جدید و ثبت قرائت توسط شرکت‌کنندگان متوقف می‌شود. این زمان وضعیت ختم را خودکار تکمیل یا بسته نمی‌کند و نمایش کارت ختم را نیز به‌تنهایی متوقف نمی‌کند.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('سبک طراحی', 'hamnoor') . '</th><td>'; $this->select('design_style', isset($khatm->design_style) ? $khatm->design_style : 'default', array('default'=>'ساده و رسمی','blue-quran'=>'قرآنی آبی','golden'=>'طلایی مناسبتی','memorial'=>'ختم اموات','ramadan'=>'ماه رمضان','heyati'=>'هیئتی/مسجدی')); echo '</td></tr>';
        echo '<tr class="hamnoor-admin-feature-section"><th colspan="2"><h3>' . esc_html__('تنظیمات ویژه بودن', 'hamnoor') . '</h3><p class="description">' . esc_html__('ویژه بودن فقط روی ترتیب و ظاهر نمایش اثر دارد و هیچ قانون سهمی را تغییر نمی‌دهد.', 'hamnoor') . '</p></th></tr>';
        echo '<tr><th><label for="featured_status">' . esc_html__('وضعیت نمایش', 'hamnoor') . '</label></th><td>'; $this->select('featured_status', $featured_status, array('normal'=>'عادی','featured'=>'ویژه','pinned'=>'پین‌شده')); echo '</td></tr>';
        echo '<tr><th><label for="featured_label">' . esc_html__('متن نشان', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_label" name="featured_label" maxlength="100" value="' . esc_attr($featured_label) . '"><p class="description">' . esc_html__('در صورت خالی بودن، متن پیش‌فرض «ویژه» یا «پین‌شده» نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_starts_at_jalali">' . esc_html__('شروع ویژه بودن', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_starts_at_jalali" name="featured_starts_at_jalali" value="' . esc_attr($featured_starts) . '" placeholder="۱۴۰۵/۰۱/۰۱ ۲۱:۳۰">' . $this->feature_field_error($feature_errors, 'featured_starts_at') . '<p class="description">' . esc_html__('اختیاری؛ اگر خالی باشد از زمان ذخیره فعال است.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_ends_at_jalali">' . esc_html__('پایان ویژه بودن', 'hamnoor') . '</label></th><td><input class="regular-text" id="featured_ends_at_jalali" name="featured_ends_at_jalali" value="' . esc_attr($featured_ends) . '" placeholder="۱۴۰۵/۰۱/۰۲ ۲۱:۳۰">' . $this->feature_field_error($feature_errors, 'featured_until') . '<p class="description">' . esc_html__('اختیاری؛ پس از این زمان نشان و اولویت ویژه غیرفعال می‌شود و داده حذف نمی‌شود.', 'hamnoor') . '</p></td></tr>';
        echo '<tr><th><label for="featured_weight">' . esc_html__('وزن نمایش', 'hamnoor') . '</label></th><td><input class="small-text" type="number" id="featured_weight" name="featured_weight" min="-100000" max="100000" value="' . esc_attr($featured_weight) . '"><p class="description">' . esc_html__('عدد بالاتر در میان ختم‌های هم‌گروه، زودتر نمایش داده می‌شود.', 'hamnoor') . '</p></td></tr>';
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
        echo '</tbody></table>'; submit_button(__('ذخیره ختم', 'hamnoor')); echo '</form>';
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
        if (!$khatm) { echo '<p>' . esc_html__('ختم پیدا نشد.', 'hamnoor') . '</p>'; return; }
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
        echo '<h2>' . esc_html(sprintf(__('سهم‌های ختم: %s', 'hamnoor'), $khatm->title)) . '</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=hamnoor')) . '">' . esc_html__('بازگشت به فهرست ختم‌ها', 'hamnoor') . '</a> <a class="button button-secondary" href="' . esc_url($base_url . '&view=participants') . '">' . esc_html__('شرکت‌کنندگان', 'hamnoor') . '</a></p>';
        echo '<form method="get" class="hamnoor-admin-filters"><input type="hidden" name="page" value="hamnoor"><input type="hidden" name="view" value="parts"><input type="hidden" name="khatm_id" value="' . (int) $khatm_id . '">';
        echo '<input type="search" name="part_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جستجو در عنوان سهم، نام، موبایل یا ایمیل', 'hamnoor') . '">';
        $this->select('part_status', $status, array('' => 'همه وضعیت‌ها', 'available' => 'آزاد', 'claimed' => 'رزروشده', 'done' => 'خوانده‌شده'));
        echo '<input type="number" min="1" name="round_number" value="' . esc_attr($round ? $round : '') . '" placeholder="' . esc_attr__('شماره ختم', 'hamnoor') . '" class="small-text"> ';
        submit_button(__('فیلتر سهم‌ها', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<table class="widefat striped hamnoor-admin-table"><thead><tr><th>' . esc_html__('شناسه', 'hamnoor') . '</th><th>' . esc_html__('سهم', 'hamnoor') . '</th><th>' . esc_html__('شماره ختم', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('شرکت‌کننده', 'hamnoor') . '</th><th>' . esc_html__('تماس', 'hamnoor') . '</th><th>' . esc_html__('زمان‌ها', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody>';
        foreach ($parts as $part) {
            $status_label = Hamnoor_Helpers::status_label($part->status, 'part');
            $contact = trim((string) $part->participant_phone . ' ' . (string) $part->participant_email);
            echo '<tr><td>' . (int) $part->id . '</td><td><strong>' . esc_html($part->title) . '</strong><br><small>' . esc_html($part->part_type . ' #' . $part->part_number) . '</small></td><td>' . (int) $part->round_number . '</td><td><span class="hamnoor-badge hamnoor-status-' . esc_attr($part->status) . '">' . esc_html($status_label) . '</span></td><td>' . esc_html($part->participant_name) . '</td><td>' . esc_html($contact) . '</td><td><small>' . esc_html__('رزرو:', 'hamnoor') . ' ' . esc_html(Hamnoor_Jalali::to_jalali($part->claimed_at, true)) . '<br>' . esc_html__('انجام:', 'hamnoor') . ' ' . esc_html(Hamnoor_Jalali::to_jalali($part->done_at, true)) . '</small></td><td>';
            if (!empty($part->quran_url)) { echo '<a class="button button-small" target="_blank" href="' . esc_url($part->quran_url) . '">' . esc_html__('مشاهده قرآن', 'hamnoor') . '</a>'; }
            echo '</td></tr>';
        }
        if (!$parts) { echo '<tr><td colspan="8">' . esc_html__('سهمی با این فیلترها یافت نشد.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table>';
        $pages = max(1, (int) ceil($total / $per_page));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)) . '</div></div>';
        }
    }

    private function render_participants($khatm_id) {
        $khatm = Hamnoor_DB::get_khatm($khatm_id);
        if (!$khatm) { echo '<p>' . esc_html__('ختم پیدا نشد.', 'hamnoor') . '</p>'; return; }
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
        echo '<h2>' . esc_html(sprintf(__('شرکت‌کنندگان ختم: %s', 'hamnoor'), $khatm->title)) . '</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url($base_url . '&view=parts') . '">' . esc_html__('مشاهده سهم‌ها', 'hamnoor') . '</a> <a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=hamnoor')) . '">' . esc_html__('بازگشت', 'hamnoor') . '</a></p>';
        echo '<form method="get" class="hamnoor-admin-filters"><input type="hidden" name="page" value="hamnoor"><input type="hidden" name="view" value="participants"><input type="hidden" name="khatm_id" value="' . (int) $khatm_id . '">';
        echo '<input type="search" name="participant_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جستجو در نام، موبایل یا ایمیل', 'hamnoor') . '"> ';
        submit_button(__('جستجوی شرکت‌کننده', 'hamnoor'), 'secondary', '', false);
        echo '</form>';
        echo '<table class="widefat striped hamnoor-admin-table"><thead><tr><th>' . esc_html__('نام', 'hamnoor') . '</th><th>' . esc_html__('موبایل', 'hamnoor') . '</th><th>' . esc_html__('ایمیل', 'hamnoor') . '</th><th>' . esc_html__('سهم‌ها', 'hamnoor') . '</th><th>' . esc_html__('خوانده‌شده', 'hamnoor') . '</th><th>' . esc_html__('عملیات', 'hamnoor') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $parts_link = add_query_arg(array('page'=>'hamnoor','view'=>'participants','khatm_id'=>(int)$khatm_id,'participant_id'=>(int)$row->participant_id), admin_url('admin.php'));
            echo '<tr><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->phone) . '</td><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->total_parts) . '</td><td>' . esc_html($row->done_parts) . '</td><td><a class="button button-small" href="' . esc_url($parts_link) . '">' . esc_html__('مشاهده سهم‌ها', 'hamnoor') . '</a></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="6">' . esc_html__('شرکت‌کننده‌ای یافت نشد.', 'hamnoor') . '</td></tr>'; }
        echo '</tbody></table>';
        $pages = max(1, (int) ceil($total / $per_page));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)) . '</div></div>';
        }
        if ($selected_id) {
            $participant_parts = Hamnoor_DB::get_participant_parts($selected_id, 200, $khatm_id);
            echo '<h3>' . esc_html__('سهم‌های این شرکت‌کننده', 'hamnoor') . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__('سهم', 'hamnoor') . '</th><th>' . esc_html__('وضعیت', 'hamnoor') . '</th><th>' . esc_html__('شماره ختم', 'hamnoor') . '</th><th>' . esc_html__('لینک قرآن', 'hamnoor') . '</th></tr></thead><tbody>';
            foreach ($participant_parts as $part) {
                echo '<tr><td>' . esc_html($part->title) . '</td><td><span class="hamnoor-badge hamnoor-status-' . esc_attr($part->status) . '">' . esc_html(Hamnoor_Helpers::status_label($part->status, 'part')) . '</span></td><td>' . (int) $part->round_number . '</td><td>' . (!empty($part->quran_url) ? '<a target="_blank" href="' . esc_url($part->quran_url) . '">' . esc_html__('مشاهده', 'hamnoor') . '</a>' : '') . '</td></tr>';
            }
            if (!$participant_parts) { echo '<tr><td colspan="4">' . esc_html__('برای این شرکت‌کننده سهمی ثبت نشده است.', 'hamnoor') . '</td></tr>'; }
            echo '</tbody></table>';
        }
    }

    private function render_logs($khatm_id) {
        $rows = Hamnoor_DB::get_logs($khatm_id);
        echo '<h2>' . esc_html__('لاگ عملیات ختم', 'hamnoor') . '</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>عملیات</th><th>پیام</th><th>IP</th></tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr><td>' . esc_html(Hamnoor_Jalali::to_jalali($row->created_at, true)) . '</td><td>' . esc_html($row->action) . '</td><td>' . esc_html($row->message) . '</td><td>' . esc_html($row->ip_address) . '</td></tr>'; }
        echo '</tbody></table>';
    }

    public function page_settings() {
        if (!current_user_can('manage_options')) { return; }
        $s = Hamnoor_Helpers::settings();
        $active = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $tabs = array(
            'general' => __('عمومی', 'hamnoor'),
            'links' => __('لینک‌ها و صفحات', 'hamnoor'),
            'create' => __('ایجاد ختم', 'hamnoor'),
            'track' => __('پیگیری سهم‌ها', 'hamnoor'),
            'export' => __('خروجی و گزارش', 'hamnoor'),
            'seo' => __('سئو', 'hamnoor'),
            'appearance' => __('ظاهر و رنگ‌ها', 'hamnoor'),
            'danger' => __('تنظیمات حساس', 'hamnoor'),
        );
        if (!isset($tabs[$active])) { $active = 'general'; }
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('تنظیمات هم‌نور', 'hamnoor') . '</h1>';
        echo '<nav class="hamnoor-admin-tabs" aria-label="' . esc_attr__('تب‌های تنظیمات', 'hamnoor') . '">';
        foreach ($tabs as $key => $label) { echo '<a href="' . esc_url(add_query_arg(array('page'=>'hamnoor-settings','tab'=>$key), admin_url('admin.php'))) . '" class="' . esc_attr($active === $key ? 'nav-tab-active' : '') . '">' . esc_html($label) . '</a>'; }
        echo '</nav><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_save_settings');
        echo '<input type="hidden" name="action" value="hamnoor_save_settings"><input type="hidden" name="tab" value="' . esc_attr($active) . '">';
        echo '<div class="hamnoor-admin-panel"><table class="form-table"><tbody>';
        if ('general' === $active) {
            echo '<tr><th>مجوز ایجاد ختم</th><td>'; $this->select('creation_policy', $s['creation_policy'], array('all'=>'همه کاربران واردشده بتوانند ختم بسازند','approved'=>'فقط کاربران تأییدشده/نقش هم‌نور','admins'=>'فقط مدیران سایت')); echo '<p class="description">برای شروع عمومی، گزینه اول ساده‌ترین مسیر کاربر است.</p></td></tr>';
            $this->setting('dashboard_my_parts_per_page', 'تعداد سهم‌های مشارکت من در داشبورد فرانت‌اند', $s['dashboard_my_parts_per_page']);
            $this->setting('dashboard_my_khatms_per_page', 'تعداد ختم‌های ایجادشده توسط من در داشبورد فرانت‌اند', $s['dashboard_my_khatms_per_page']);
            $this->setting('admin_khatms_per_page', 'تعداد ختم‌ها در هر صفحه مدیریت', $s['admin_khatms_per_page']);
            $this->setting('admin_parts_per_page', 'تعداد سهم‌ها در هر صفحه مدیریت', $s['admin_parts_per_page']);
            $this->setting('admin_participants_per_page', 'تعداد شرکت‌کنندگان در هر صفحه مدیریت', $s['admin_participants_per_page']);
            echo '<tr><th>ثبت‌نام و API</th><td><label><input type="hidden" name="allow_frontend_registration" value="0"><input type="checkbox" name="allow_frontend_registration" value="1"' . checked($s['allow_frontend_registration'], '1', false) . '> ثبت‌نام فرانت‌اند فعال باشد</label><br><label><input type="hidden" name="auto_grant_manager" value="0"><input type="checkbox" name="auto_grant_manager" value="1"' . checked($s['auto_grant_manager'], '1', false) . '> اعطای خودکار نقش ایجادکننده هم‌نور بعد از ثبت‌نام</label><br><label><input type="hidden" name="rest_api_enabled" value="0"><input type="checkbox" name="rest_api_enabled" value="1"' . checked($s['rest_api_enabled'], '1', false) . '> REST API هم‌نور فعال باشد</label><br><label><input type="hidden" name="hide_admin_bar" value="0"><input type="checkbox" name="hide_admin_bar" value="1"' . checked(isset($s['hide_admin_bar']) ? $s['hide_admin_bar'] : '0', '1', false) . '> پنهان‌سازی نوار مدیریت برای کاربران غیرمدیر در صفحات هم‌نور</label><br><label><input type="hidden" name="ajax_debug_admin" value="0"><input type="checkbox" name="ajax_debug_admin" value="1"' . checked(isset($s['ajax_debug_admin']) ? $s['ajax_debug_admin'] : '0', '1', false) . '> فعال‌سازی گزارش خطای AJAX برای مدیر</label><p class="description">در حالت فعال، خطاهای AJAX فقط برای مدیر سایت با prefix [Hamnoor AJAX] در console ثبت می‌شود.</p></td></tr>';
        } elseif ('links' === $active) {
            $this->setting('link_base', 'پایه لینک ختم', $s['link_base']);
            echo '<tr><th>لینک دعوت</th><td><p class="description">لینک دعوت همیشه کوتاه و بدون اسلاگ ساخته می‌شود؛ نمونه: <code>/h/TOKEN/</code>.</p></td></tr>';
            $this->setting('quran_page_url_pattern', 'فرمت لینک صفحه قرآن', $s['quran_page_url_pattern']);
            $this->setting('quran_juz_url_pattern', 'فرمت لینک جزء قرآن', $s['quran_juz_url_pattern']);
            $this->setting('quran_surah_url_pattern', 'فرمت لینک سوره', $s['quran_surah_url_pattern']);
            echo '<tr><th>برگه‌ها</th><td><label><input type="hidden" name="auto_create_track_page" value="0"><input type="checkbox" name="auto_create_track_page" value="1"' . checked($s['auto_create_track_page'], '1', false) . '> ساخت خودکار برگه پیگیری سهم‌ها</label><br><label><input type="hidden" name="auto_create_create_page" value="0"><input type="checkbox" name="auto_create_create_page" value="1"' . checked($s['auto_create_create_page'], '1', false) . '> ساخت برگه مستقل ایجاد ختم</label></td></tr>';
            $participation_guide_id = !empty($s['participation_guide_page_id']) ? (int) $s['participation_guide_page_id'] : 0;
            $create_guide_id = !empty($s['create_khatm_guide_page_id']) ? (int) $s['create_khatm_guide_page_id'] : 0;
            echo '<tr><th>' . esc_html__('برگه‌های راهنما', 'hamnoor') . '</th><td>';
            echo ($participation_guide_id && 'publish' === get_post_status($participation_guide_id)) ? '<p><a href="' . esc_url(get_permalink($participation_guide_id)) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('راهنمای ثبت و شرکت در ختم', 'hamnoor') . '</a></p>' : '<p>' . esc_html__('برگه راهنمای ثبت و شرکت در ختم یافت نشد.', 'hamnoor') . '</p>';
            echo ($create_guide_id && 'publish' === get_post_status($create_guide_id)) ? '<p><a href="' . esc_url(get_permalink($create_guide_id)) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('راهنمای ایجاد ختم', 'hamnoor') . '</a></p>' : '<p>' . esc_html__('برگه راهنمای ایجاد ختم یافت نشد.', 'hamnoor') . '</p>';
            echo '</td></tr>';
        } elseif ('create' === $active) {
            echo '<tr><th>' . esc_html__('امکان ایجاد ختم جدید', 'hamnoor') . '</th><td>'; $this->select('new_khatm_creation_enabled', isset($s['new_khatm_creation_enabled']) ? $s['new_khatm_creation_enabled'] : '1', array('1'=>'فعال','0'=>'غیرفعال موقت')); echo '<p class="description">در حالت غیرفعال، ایجاد ختم جدید برای کاربران عادی بسته می‌شود؛ مدیر سایت همچنان می‌تواند ختم بسازد و ویرایش ختم‌های موجود ادامه دارد.</p></td></tr>';
            $this->setting('max_khatm_count', 'حداکثر تعداد ختم کامل قابل انتخاب', $s['max_khatm_count']);
            $this->setting('max_repeat_count', 'حداکثر عمومی تعداد تکرار سوره', isset($s['max_repeat_count']) ? $s['max_repeat_count'] : '1000');
            $this->setting('max_parts_per_khatm', 'حداکثر تعداد سهم قابل پردازش هم‌زمان', isset($s['max_parts_per_khatm']) ? $s['max_parts_per_khatm'] : '12000');
            $this->setting('cover_max_size_kb', 'حداکثر حجم تصویر KB', $s['cover_max_size_kb']);
            echo '<tr><th>اجباری بودن تصویر ختم</th><td>'; $this->select('cover_required_mode', isset($s['cover_required_mode']) ? $s['cover_required_mode'] : 'optional', array('optional'=>'همیشه اختیاری باشد','public'=>'برای ختم عمومی الزامی باشد','always'=>'همیشه الزامی باشد')); echo '<p class="description">اگر تصویر اختیاری باشد، کارت ختم از طرح پیش‌فرض هماهنگ با سبک انتخاب‌شده استفاده می‌کند.</p></td></tr>';
            $this->setting('default_khatm_image_url', 'تصویر پیش‌فرض ختم', isset($s['default_khatm_image_url']) ? $s['default_khatm_image_url'] : '');
            echo '<tr><th></th><td><p class="description">نشانی تصویر پیشنهادی با نسبت ۱۲۰۰×۶۳۰ وارد شود. اگر کاربر تصویر انتخاب نکند، این تصویر در کارت و صفحه ختم استفاده می‌شود.</p></td></tr>';
            echo '<tr><th>نمایش نام شرکت‌کنندگان</th><td>'; $this->select('participant_name_display', $s['participant_name_display'], array('full'=>'کامل','first'=>'نام کوچک','hidden'=>'مخفی')); echo '</td></tr>';
            echo '<tr><th>روش نمایش صفحات ختم</th><td>'; $this->select('khatm_render_mode', isset($s['khatm_render_mode']) ? $s['khatm_render_mode'] : 'plugin', array('theme'=>'سازگار با قالب سایت','plugin'=>'قالب اختصاصی هم‌نور')); echo '<p class="description">حالت اختصاصی برای نصب تازه ظاهر کامل‌تری دارد؛ حالت سازگار با قالب، هدر و فوتر قالب سایت را حفظ می‌کند.</p></td></tr>';
            echo '<tr><th>تصویر خارجی</th><td><label><input type="hidden" name="allow_external_cover_url" value="0"><input type="checkbox" name="allow_external_cover_url" value="1"' . checked($s['allow_external_cover_url'], '1', false) . '> نشانی تصویر خارجی مجاز باشد</label></td></tr>';
            echo '<tr><th>' . esc_html__('حمایت مالی داوطلبانه', 'hamnoor') . '</th><td><label><input type="hidden" name="support_section_enabled" value="0"><input type="checkbox" name="support_section_enabled" value="1"' . checked(isset($s['support_section_enabled']) ? $s['support_section_enabled'] : '0', '1', false) . '> ' . esc_html__('نمایش بخش حمایت در پنل موفقیت ایجاد ختم', 'hamnoor') . '</label><p class="description">' . esc_html__('این بخش اختیاری است و ایجاد ختم به پرداخت وابسته نمی‌شود.', 'hamnoor') . '</p></td></tr>';
            $this->setting('support_section_title', 'عنوان بخش حمایت', isset($s['support_section_title']) ? $s['support_section_title'] : '');
            echo '<tr><th><label for="support_section_description">' . esc_html__('متن توضیحی حمایت', 'hamnoor') . '</label></th><td><textarea id="support_section_description" name="support_section_description" class="large-text" rows="4">' . esc_textarea(isset($s['support_section_description']) ? $s['support_section_description'] : '') . '</textarea></td></tr>';
            echo '<tr><th><label for="support_payment_info">' . esc_html__('اطلاعات واریز یا روش حمایت', 'hamnoor') . '</label></th><td><textarea id="support_payment_info" name="support_payment_info" class="large-text" rows="4">' . esc_textarea(isset($s['support_payment_info']) ? $s['support_payment_info'] : '') . '</textarea><p class="description">' . esc_html__('فقط اطلاعات لازم برای روش حمایت را وارد کنید و از درج اطلاعات بانکی غیرضروری خودداری کنید.', 'hamnoor') . '</p></td></tr>';
            $this->setting('support_button_text', 'متن دکمه حمایت', isset($s['support_button_text']) ? $s['support_button_text'] : '');
            $this->setting('support_button_url', 'لینک دکمه حمایت', isset($s['support_button_url']) ? $s['support_button_url'] : '');
            echo '<tr><th>' . esc_html__('ارتباط با مدیر هنگام ایجاد ختم', 'hamnoor') . '</th><td><label><input type="hidden" name="repeat_contact_enabled" value="0"><input type="checkbox" name="repeat_contact_enabled" value="1"' . checked(isset($s['repeat_contact_enabled']) ? $s['repeat_contact_enabled'] : '0', '1', false) . '> ' . esc_html__('نمایش دکمه ارتباط با مدیر در مرحله انتخاب نوع ختم', 'hamnoor') . '</label></td></tr>';
            $this->setting('repeat_contact_button_text', 'متن دکمه ارتباط', isset($s['repeat_contact_button_text']) ? $s['repeat_contact_button_text'] : '');
            $this->setting('repeat_contact_url', 'لینک ارتباط با مدیر', isset($s['repeat_contact_url']) ? $s['repeat_contact_url'] : '');
            $this->setting('repeat_contact_help', 'متن راهنمای ارتباط', isset($s['repeat_contact_help']) ? $s['repeat_contact_help'] : '');
        } elseif ('track' === $active) {
            echo '<tr><th>پیگیری ساده</th><td><label><input type="hidden" name="track_requires_otp" value="0"><input type="checkbox" name="track_requires_otp" value="1"' . checked($s['track_requires_otp'], '1', false) . '> حالت تأیید OTP برای پیگیری سهم‌ها فعال باشد</label><p class="description">برای استفاده عمومی ساده، این گزینه خاموش بماند تا کاربر با موبایل یا ایمیل سهم‌هایش را ببیند.</p></td></tr>';
        } elseif ('export' === $active) {
            echo '<tr><th>مجوز خروجی برای ایجادکننده ختم</th><td><select name="owner_export_mode"><option value="disabled"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'disabled', false) . '>غیرفعال</option><option value="limited"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'limited', false) . '>خروجی محدود: بدون موبایل و ایمیل</option><option value="full"' . selected(isset($s['owner_export_mode']) ? $s['owner_export_mode'] : 'limited', 'full', false) . '>خروجی کامل: شامل موبایل و ایمیل</option></select></td></tr>';
        } elseif ('seo' === $active) {
            echo '<tr><th>نمایش در نتایج گوگل</th><td><label><input type="hidden" name="index_public_khatms" value="0"><input type="checkbox" name="index_public_khatms" value="1"' . checked($s['index_public_khatms'], '1', false) . '> ختم‌های عمومی در صورت مناسب بودن در نتایج گوگل دیده شوند</label><p class="description">از نظر فنی این گزینه meta index/noindex را کنترل می‌کند؛ ختم خصوصی، داشبورد، ورود، ثبت‌نام و پیگیری سهم‌ها noindex می‌مانند.</p></td></tr>';
            echo '<tr><th><label for="share_text_template">متن پیش‌فرض اشتراک‌گذاری</label></th><td><textarea id="share_text_template" name="share_text_template" class="large-text" rows="8">' . esc_textarea($s['share_text_template']) . '</textarea><p class="description">متغیرها: {KHATM_TITLE}، {KHATM_INTENTION}، {KHATM_URL}، {AVAILABLE_PARTS}، {DONE_PARTS}، {TOTAL_PARTS}، {PROGRESS_PERCENT}</p></td></tr>';
            echo '<tr><th>اشتراک‌گذاری خصوصی</th><td><label><input type="hidden" name="allow_private_share" value="0"><input type="checkbox" name="allow_private_share" value="1"' . checked($s['allow_private_share'], '1', false) . '> نمایش دکمه اشتراک‌گذاری عمومی برای ختم خصوصی</label></td></tr>';
        } elseif ('appearance' === $active) {
            echo '<tr><th colspan="2"><h3>' . esc_html__('اطلاعات کارت ختم', 'hamnoor') . '</h3></th></tr>';
            echo '<tr><th>' . esc_html__('اطلاعات کارت', 'hamnoor') . '</th><td><label><input type="hidden" name="card_show_part_type" value="0"><input type="checkbox" name="card_show_part_type" value="1"' . checked(isset($s['card_show_part_type']) ? $s['card_show_part_type'] : '1', '1', false) . '> ' . esc_html__('نمایش نوع تقسیم‌بندی سهم‌ها در کارت ختم', 'hamnoor') . '</label><br><label><input type="hidden" name="card_show_claimed_count" value="0"><input type="checkbox" name="card_show_claimed_count" value="1"' . checked(isset($s['card_show_claimed_count']) ? $s['card_show_claimed_count'] : '1', '1', false) . '> ' . esc_html__('نمایش تعداد سهم‌های رزروشده در کارت ختم', 'hamnoor') . '</label><br><label><input type="hidden" name="card_show_done_count" value="0"><input type="checkbox" name="card_show_done_count" value="1"' . checked(isset($s['card_show_done_count']) ? $s['card_show_done_count'] : '1', '1', false) . '> ' . esc_html__('نمایش تعداد سهم‌های خوانده‌شده در کارت ختم', 'hamnoor') . '</label></td></tr>';
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
            $this->setting('font_family', 'فونت CSS', $s['font_family']);
        } elseif ('danger' === $active) {
            echo '<tr><th>حذف داده‌ها هنگام uninstall</th><td><div class="notice notice-warning inline"><p>این گزینه خطرناک است و فقط در زمان تست افزونه استفاده شود.</p></div><label><input type="hidden" name="delete_data_on_uninstall" value="0"><input type="checkbox" name="delete_data_on_uninstall" value="1"' . checked($s['delete_data_on_uninstall'], '1', false) . '> حذف داده‌ها هنگام حذف افزونه</label></td></tr>';
        }
        echo '</tbody></table></div>'; submit_button(__('ذخیره تنظیمات', 'hamnoor')); echo '</form></div>';
    }

    private function setting($name, $label, $value) { echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($name) . '" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>'; }
    private function color($name, $label, $value) { echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input id="' . esc_attr($name) . '" type="color" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>'; }


    public function page_texts() {
        if (!current_user_can('manage_options')) { return; }
        $defaults = Hamnoor_Texts::defaults();
        $texts = Hamnoor_Texts::all();
        $meta = array(
            'create_form_title'=>array('group'=>'فرم ایجاد ختم','label'=>'عنوان فرم ایجاد ختم','desc'=>'تیتر بالای فرم ویزارد.'),
            'step_basic_desc'=>array('group'=>'فرم ایجاد ختم','label'=>'توضیح مرحله اطلاعات','desc'=>'متن کوتاه مرحله اطلاعات اصلی.'),
            'step_type_desc'=>array('group'=>'فرم ایجاد ختم','label'=>'توضیح مرحله نوع ختم','desc'=>'متن راهنمای انتخاب نوع ختم.'),
            'khatm_count_help'=>array('group'=>'فرم ایجاد ختم','label'=>'راهنمای تعداد ختم','desc'=>'زیر فیلد تعداد ختم نمایش داده می‌شود.','vars'=>'{MAX}'),
            'khatm_count_error'=>array('group'=>'پیام‌های خطا','label'=>'خطای تعداد ختم بیش از حد','desc'=>'وقتی تعداد ختم از حد مجاز بیشتر است.','vars'=>'{MAX}'),
            'create_success'=>array('group'=>'پیام‌های موفقیت','label'=>'پیام موفقیت ایجاد ختم','desc'=>'بعد از ساخت ختم نمایش داده می‌شود.'),
            'share_text_template'=>array('group'=>'متن دعوت','label'=>'متن پیش‌فرض دعوت','desc'=>'متن قابل کپی برای دعوت دیگران.','vars'=>'{KHATM_TITLE}, {KHATM_URL}, {KHATM_INTENTION}, {AVAILABLE_PARTS}, {DONE_PARTS}, {TOTAL_PARTS}, {PROGRESS_PERCENT}'),
            'track_title'=>array('group'=>'پیگیری سهم‌ها','label'=>'عنوان صفحه پیگیری','desc'=>'تیتر صفحه پیگیری سهم‌های من.'),
            'track_desc'=>array('group'=>'پیگیری سهم‌ها','label'=>'توضیح صفحه پیگیری','desc'=>'متن زیر عنوان فرم پیگیری.'),
            'track_found'=>array('group'=>'پیگیری سهم‌ها','label'=>'پیام پیدا شدن سهم‌ها','desc'=>'بعد از جستجو نمایش داده می‌شود.','vars'=>'{COUNT}'),
            'track_empty'=>array('group'=>'پیگیری سهم‌ها','label'=>'پیام نبود سهم','desc'=>'وقتی سهمی پیدا نشده است.'),
            'mobile_invalid'=>array('group'=>'پیام‌های خطا','label'=>'خطای موبایل نامعتبر','desc'=>'برای فرم‌های موبایل.'),
            'email_invalid'=>array('group'=>'پیام‌های خطا','label'=>'خطای ایمیل نامعتبر','desc'=>'برای فرم‌های ایمیل.'),
            'parts_done_help'=>array('group'=>'صفحه ختم','label'=>'راهنمای ثبت قرائت','desc'=>'فقط یک‌بار بالای لیست سهم‌ها نمایش داده می‌شود.'),
            'claim_next_button'=>array('group'=>'انتخاب سهم','label'=>'دکمه انتخاب به ترتیب','desc'=>'دکمه اصلی صفحه ختم.'),
            'claim_random_button'=>array('group'=>'انتخاب سهم','label'=>'دکمه انتخاب تصادفی','desc'=>'دکمه فرعی صفحه ختم.'),
            'claim_intro'=>array('group'=>'انتخاب سهم','label'=>'توضیح بخش انتخاب سهم','desc'=>'زیر عنوان بخش اقدام اصلی.'),
            'claim_next_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب به ترتیب','desc'=>'در پاپ‌آپ انتخاب سهم.'),
            'claim_random_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب تصادفی','desc'=>'در پاپ‌آپ انتخاب سهم.'),
            'claim_manual_desc'=>array('group'=>'انتخاب سهم','label'=>'توضیح انتخاب دستی','desc'=>'در پاپ‌آپ انتخاب سهم.','vars'=>'{PART_TITLE}'),
            'claim_submit'=>array('group'=>'انتخاب سهم','label'=>'دکمه ثبت انتخاب','desc'=>'دکمه اصلی پاپ‌آپ.'),
            'claim_cancel'=>array('group'=>'انتخاب سهم','label'=>'دکمه انصراف','desc'=>'دکمه بستن پاپ‌آپ.'),
            'complete_button'=>array('group'=>'صفحه ختم','label'=>'دکمه قرائت انجام شد','desc'=>'برای کارت سهم.'),
            'complete_success'=>array('group'=>'پیام‌های موفقیت','label'=>'پیام موفقیت ثبت قرائت','desc'=>'بعد از ثبت قرائت.'),
            'khatm_completed_message'=>array('group'=>'صفحه ختم','label'=>'پیام تکمیل ختم','desc'=>'وقتی همه سهم‌ها خوانده شدند.'),
            'badge_active'=>array('group'=>'badgeها','label'=>'برچسب فعال','desc'=>'وضعیت ختم فعال.'),
            'badge_completed'=>array('group'=>'badgeها','label'=>'برچسب تکمیل شد','desc'=>'وضعیت ختم تکمیل‌شده.'),
            'badge_available'=>array('group'=>'badgeها','label'=>'برچسب آزاد','desc'=>'وضعیت سهم آزاد.'),
            'badge_claimed'=>array('group'=>'badgeها','label'=>'برچسب رزروشده','desc'=>'وضعیت سهم رزروشده.'),
            'badge_done'=>array('group'=>'badgeها','label'=>'برچسب خوانده‌شده','desc'=>'وضعیت سهم خوانده‌شده.'),
            'part_already_claimed'=>array('group'=>'پیام‌های خطا','label'=>'خطای سهم قبلاً انتخاب‌شده','desc'=>'وقتی سهم در همان لحظه توسط دیگری انتخاب شده.'),
            'no_free_part'=>array('group'=>'پیام‌های خطا','label'=>'خطای نبود سهم آزاد','desc'=>'وقتی سهم آزادی وجود ندارد.'),
            'form_incomplete'=>array('group'=>'پیام‌های خطا','label'=>'خطای فرم ناقص','desc'=>'پیام خلاصه خطای فرم.'),
            'featured_badge_default'=>array('group'=>'ختم‌های ویژه','label'=>'نشان پیش‌فرض ویژه','desc'=>'وقتی مدیر متن نشان را خالی گذاشته است.'),
            'pinned_badge_default'=>array('group'=>'ختم‌های ویژه','label'=>'نشان پیش‌فرض پین‌شده','desc'=>'برای ختم پین‌شده بدون متن سفارشی.'),
            'featured_notice_single'=>array('group'=>'ختم‌های ویژه','label'=>'پیام صفحه ختم ویژه','desc'=>'در صفحه ختم ویژه فعال نمایش داده می‌شود.'),
            'pinned_notice_single'=>array('group'=>'ختم‌های ویژه','label'=>'پیام صفحه ختم پین‌شده','desc'=>'در صفحه ختم پین‌شده فعال نمایش داده می‌شود.'),
            'feature_request_button'=>array('group'=>'ختم‌های ویژه','label'=>'دکمه درخواست ویژه شدن','desc'=>'در داشبورد ایجادکننده ختم.'),
            'feature_request_pending'=>array('group'=>'ختم‌های ویژه','label'=>'وضعیت درخواست در انتظار','desc'=>'پس از ثبت درخواست نمایش داده می‌شود.'),
            'feature_request_success'=>array('group'=>'ختم‌های ویژه','label'=>'پیام موفقیت درخواست','desc'=>'پس از ثبت موفق درخواست نمایش داده می‌شود.'),
            'feature_request_rejected'=>array('group'=>'ختم‌های ویژه','label'=>'پیام درخواست ردشده','desc'=>'برای درخواست ردشده نمایش داده می‌شود.'),
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
        echo '<div class="wrap hamnoor-admin-wrap"><h1>' . esc_html__('سلامت افزونه هم‌نور', 'hamnoor') . '</h1>';
        if (!empty($health['ok'])) { echo '<div class="notice notice-success"><p>' . esc_html__('ساختار اصلی افزونه سالم است.', 'hamnoor') . '</p></div>'; }
        else { echo '<div class="notice notice-error"><p>' . esc_html__('در ساختار افزونه خطا یا کمبود مشاهده شد.', 'hamnoor') . '</p></div>'; }
        $rows = array(
            'Plugin Version' => HAMNOOR_VERSION,
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
        );
        echo '<h2>' . esc_html__('بررسی‌ها', 'hamnoor') . '</h2><table class="widefat striped"><tbody>';
        foreach ($rows as $k=>$v) { echo '<tr><td>' . esc_html($k) . '</td><td><code>' . esc_html((string)$v) . '</code></td></tr>'; }
        echo '</tbody></table></div>';
    }

    public function page_docs() {
        echo '<div class="wrap hamnoor-admin-wrap"><h1>مستندات هم‌نور</h1><div class="hamnoor-docs"><h2>شورتکدها</h2><ul><li><code>[hamnoor_create_khatm]</code> ایجاد ختم</li><li><code>[hamnoor_khatm_list]</code> فهرست ختم‌های عمومی</li><li><code>[hamnoor_login]</code> ورود اختصاصی</li><li><code>[hamnoor_register]</code> ثبت‌نام</li><li><code>[hamnoor_dashboard]</code> داشبورد کاربر</li><li><code>[hamnoor_track_parts]</code> پیگیری سهم‌ها با ایمیل یا موبایل</li></ul><h2>مسیرها</h2><p>لینک اصلی ختم‌ها به‌صورت پیش‌فرض <code>/h/TOKEN/</code> است؛ مسیر قدیمی <code>/khatm/TOKEN/</code> برای سازگاری همچنان کار می‌کند.</p><h2>امنیت</h2><p>ایجاد ختم فقط برای کاربران واردشده مجاز است و سطح دسترسی از تنظیمات کنترل می‌شود. انتخاب سهم برای مهمان مجاز است اما nonce، honeypot، اعتبارسنجی موبایل و ایمیل دارد.</p><h2>سئو</h2><p>ختم‌های عمومی، در صورت فعال بودن تنظیمات، دارای meta، canonical، Open Graph و Schema هستند. صفحات ورود، ثبت‌نام، داشبورد و پیگیری سهم‌ها noindex هستند.</p></div></div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_save_settings');
        Hamnoor_Helpers::update_settings($_POST);
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'general';
        Hamnoor_Helpers::redirect_with_notice(add_query_arg('tab', $tab, admin_url('admin.php?page=hamnoor-settings')), __('تنظیمات ذخیره شد.', 'hamnoor'));
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
        $data = Hamnoor_DB::sanitize_khatm_data($_POST);
        $upload = Hamnoor_Helpers::upload_cover('cover_file');
        if (is_wp_error($upload)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $upload->get_error_message(), 'error'); }
        if ($upload) { unset($data['_external_cover_disabled'], $data['_external_cover_invalid']); $data = array_merge($data, $upload); }
        $result = Hamnoor_DB::update_khatm($khatm_id, $data);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $result->get_error_message(), 'error'); }
        $feature_result = Hamnoor_Featured_Service::save_admin_settings($khatm_id, $featured_data, get_current_user_id());
        if (is_wp_error($feature_result)) { Hamnoor_Helpers::redirect_with_notice($edit_url, $feature_result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice($edit_url, __('ختم و تنظیمات ویژه بودن ذخیره شد.', 'hamnoor'));
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
        $message = 'approve' === $decision ? __('درخواست تأیید شد و ختم ویژه شد.', 'hamnoor') : __('درخواست ویژه شدن رد شد.', 'hamnoor');
        Hamnoor_Helpers::redirect_with_notice($redirect, $message);
    }

    public function export_csv() { $this->check_export(); Hamnoor_Export::download_csv((int) $_GET['khatm_id']); }
    public function export_xlsx() { $this->check_export(); Hamnoor_Export::download_xlsx((int) $_GET['khatm_id']); }
    private function check_export() { $id = isset($_GET['khatm_id']) ? (int) $_GET['khatm_id'] : 0; check_admin_referer('hamnoor_export_' . $id); $can = apply_filters('hamnoor_can_export_khatm', Hamnoor_Export::can_export($id, get_current_user_id()), $id, get_current_user_id()); if (!$can) { wp_die(esc_html__('دسترسی خروجی این ختم را ندارید.', 'hamnoor')); } }
    public function bulk_status() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_bulk_status');
        $status = isset($_POST['bulk_status']) ? sanitize_key(wp_unslash($_POST['bulk_status'])) : '';
        if (!in_array($status, array('pending_review','active','completed','closed','archived','failed'), true)) {
            Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), __('وضعیت انتخاب‌شده برای عملیات گروهی معتبر نیست.', 'hamnoor'), 'error');
        }
        $ids = isset($_POST['khatm_ids']) && is_array($_POST['khatm_ids']) ? array_map('intval', wp_unslash($_POST['khatm_ids'])) : array();
        $ok = 0; $failed = 0;
        foreach ($ids as $id) {
            if (!$id) { continue; }
            $result = Hamnoor_Khatm_Service::change_status($id, $status);
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
        $result = Hamnoor_Khatm_Service::change_status($id, $status);
        if (is_wp_error($result)) { Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), $result->get_error_message(), 'error'); }
        Hamnoor_Helpers::redirect_with_notice(admin_url('admin.php?page=hamnoor'), __('وضعیت ختم تغییر کرد.', 'hamnoor'));
    }
}
