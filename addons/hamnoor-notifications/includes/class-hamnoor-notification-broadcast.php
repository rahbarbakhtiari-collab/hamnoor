<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin broadcast messaging for Hamnoor Notifications.
 *
 * - Every active Hamnoor user receives one durable in-app notification.
 * - External delivery is optional and always respects each user's existing
 *   connected/enabled channel state through Notification Dispatcher 2.0.
 * - Large audiences are processed in small resumable batches by WP-Cron.
 * - Idempotency is enforced by (broadcast_id,user_id) against notifications.
 * - WordPress posts/postmeta store broadcast jobs, so no DB migration is needed.
 */
class Hamnoor_Notification_Broadcast {
    const POST_TYPE = 'hamnoor_broadcast';
    const TYPE = 'admin_broadcast';
    const CRON_HOOK = 'hamnoor_notification_broadcast_worker';
    const CRON_SCHEDULE = 'hamnoor_broadcast_minute';
    const LOCK_KEY = 'hamnoor_notification_broadcast_lock';
    const DEFAULT_BATCH = 20;

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'), 5);
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 30);
        add_action(self::CRON_HOOK, array(__CLASS__, 'process_queue'));
        // Fallback: if the dedicated minute schedule is missed, the existing
        // notification worker also advances one broadcast batch.
        add_action(Hamnoor_Notification_Dispatcher::CRON_HOOK, array(__CLASS__, 'process_queue'), 30);

        add_filter('hamnoor_important_notification_types', array(__CLASS__, 'register_internal_type'));
        add_filter('hamnoor_external_notification_types', array(__CLASS__, 'register_external_type'), 10, 2);
        add_filter('hamnoor_notification_admin_tabs', array(__CLASS__, 'admin_tab'));
        add_action('hamnoor_render_notification_admin_tab_broadcast', array(__CLASS__, 'render_admin_tab'));

        add_action('admin_post_hamnoor_broadcast_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_hamnoor_broadcast_cancel', array(__CLASS__, 'handle_cancel'));
        add_action('admin_post_hamnoor_broadcast_run_now', array(__CLASS__, 'handle_run_now'));
    }

    public static function activate() {
        self::register_post_type();
        self::ensure_schedule(true);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_transient(self::LOCK_KEY);
    }

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array('name'=>__('پیام‌های همگانی', 'hamnoor-notifications')),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array('title','editor','author'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display' => __('هر دقیقه (پیام همگانی هم‌نور)', 'hamnoor-notifications'),
            );
        }
        return $schedules;
    }

    public static function ensure_schedule($fail_hard = false) {
        if (wp_next_scheduled(self::CRON_HOOK)) { return true; }
        $result = wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK, array(), true);
        if (is_wp_error($result) || true !== $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown scheduling failure';
            update_option('hamnoor_broadcast_cron_last_error', substr($message, 0, 500), false);
            if ($fail_hard) { throw new RuntimeException('Hamnoor Broadcast queue schedule failed: ' . $message); }
            return false;
        }
        delete_option('hamnoor_broadcast_cron_last_error');
        return true;
    }

    public static function register_internal_type($types) {
        $types = is_array($types) ? $types : array();
        if (!in_array(self::TYPE, $types, true)) { $types[] = self::TYPE; }
        return $types;
    }

    public static function register_external_type($types, $notification = null) {
        $types = is_array($types) ? $types : array();
        if (!in_array(self::TYPE, $types, true)) { $types[] = self::TYPE; }
        return $types;
    }

    public static function admin_tab($tabs) {
        $tabs = is_array($tabs) ? $tabs : array();
        $tabs['broadcast'] = __('پیام همگانی', 'hamnoor-notifications');
        return $tabs;
    }

    private static function users_table() {
        return class_exists('Hamnoor_DB') ? Hamnoor_DB::table('users') : '';
    }

    private static function notifications_table() {
        return class_exists('Hamnoor_DB') ? Hamnoor_DB::table('notifications') : '';
    }

    public static function active_user_count($max_id = 0) {
        global $wpdb;
        $table = self::users_table();
        if (!$table) { return 0; }
        if ($max_id > 0) {
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status='active' AND id<=%d", (int)$max_id));
        }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='active'");
    }

    public static function max_active_user_id() {
        global $wpdb;
        $table = self::users_table();
        if (!$table) { return 0; }
        return (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM $table WHERE status='active'");
    }

    private static function meta($id, $key, $default = '') {
        $value = get_post_meta((int)$id, '_hamnoor_broadcast_' . $key, true);
        return '' === $value ? $default : $value;
    }

    private static function set_meta($id, $key, $value) {
        return update_post_meta((int)$id, '_hamnoor_broadcast_' . $key, $value);
    }

    public static function render_admin_tab() {
        if (!current_user_can('manage_options')) { return; }
        $count = self::active_user_count();
        $next = wp_next_scheduled(self::CRON_HOOK);
        $cron_error = (string)get_option('hamnoor_broadcast_cron_last_error', '');
        $notice = isset($_GET['broadcast_notice']) ? sanitize_key(wp_unslash($_GET['broadcast_notice'])) : '';
        if ('created' === $notice) {
            echo '<div class="notice notice-success"><p>' . esc_html__('پیام در صف ارسال قرار گرفت و به‌صورت دسته‌ای پردازش می‌شود.', 'hamnoor-notifications') . '</p></div>';
        } elseif ('cancelled' === $notice) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('ارسال پیام متوقف شد. پیام‌هایی که پیش‌تر پردازش شده‌اند باقی می‌مانند.', 'hamnoor-notifications') . '</p></div>';
        } elseif ('ran' === $notice) {
            echo '<div class="notice notice-success"><p>' . esc_html__('یک نوبت از صف پیام‌های همگانی اجرا شد.', 'hamnoor-notifications') . '</p></div>';
        }

        echo '<div class="card" style="max-width:920px;padding:22px">';
        echo '<h2 style="margin-top:0">' . esc_html__('ارسال پیام سفارشی به همه کاربران', 'hamnoor-notifications') . '</h2>';
        echo '<p>' . sprintf(
            esc_html__('این پیام برای همه کاربران فعال هم‌نور ارسال می‌شود. در حال حاضر %s کاربر فعال در جامعه هدف هستند.', 'hamnoor-notifications'),
            '<strong>' . number_format_i18n($count) . '</strong>'
        ) . '</p>';
        echo '<p class="description">' . esc_html__('همه کاربران یک اعلان داخل هم‌نور دریافت می‌کنند. اگر ارسال بیرونی را فعال کنید، فقط کاربرانی که ایتا، بله، روبیکا یا Web Push متصل و فعال دارند از کانال موجود خود نیز پیام می‌گیرند؛ انتخاب‌های لغو اعلان کاربر محترم شمرده می‌شود.', 'hamnoor-notifications') . '</p>';

        if ($cron_error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('زمان‌بندی صف پیام همگانی خطا دارد:', 'hamnoor-notifications') . ' <code>' . esc_html($cron_error) . '</code></p></div>';
        } elseif ($next) {
            echo '<p class="description">' . esc_html__('صف خودکار فعال است. اجرای بعدی:', 'hamnoor-notifications') . ' <code>' . esc_html(wp_date('Y-m-d H:i:s', (int)$next, wp_timezone())) . '</code></p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hamnoor_broadcast_create');
        echo '<input type="hidden" name="action" value="hamnoor_broadcast_create">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label for="hamnoor_broadcast_title">' . esc_html__('عنوان پیام', 'hamnoor-notifications') . '</label></th><td><input required maxlength="120" type="text" class="regular-text" style="width:100%;max-width:680px" id="hamnoor_broadcast_title" name="title" placeholder="' . esc_attr__('مثلاً: پویش جدید هم‌نور', 'hamnoor-notifications') . '"></td></tr>';
        echo '<tr><th><label for="hamnoor_broadcast_message">' . esc_html__('متن پیام', 'hamnoor-notifications') . '</label></th><td><textarea required maxlength="1200" rows="7" class="large-text" style="max-width:680px" id="hamnoor_broadcast_message" name="message" placeholder="' . esc_attr__('متن دلخواهی که کاربران باید ببینند…', 'hamnoor-notifications') . '"></textarea><p class="description">' . esc_html__('حداکثر ۱۲۰۰ نویسه. از درج اطلاعات محرمانه یا کدهای ورود خودداری کنید.', 'hamnoor-notifications') . '</p></td></tr>';
        echo '<tr><th><label for="hamnoor_broadcast_url">' . esc_html__('لینک اختیاری داخل پیام', 'hamnoor-notifications') . '</label></th><td><input type="url" class="regular-text ltr" style="width:100%;max-width:680px" id="hamnoor_broadcast_url" name="url" placeholder="https://..."><p class="description"><strong>' . esc_html__('فقط همین لینکی که شما وارد می‌کنید به پیام افزوده می‌شود.', 'hamnoor-notifications') . '</strong> ' . esc_html__('اگر این کادر خالی باشد، هیچ لینک یا دکمه خودکار هم‌نور به پیام اضافه نمی‌شود.', 'hamnoor-notifications') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('شیوه ارسال', 'hamnoor-notifications') . '</th><td>';
        echo '<label style="display:block;margin:0 0 8px"><input type="radio" name="mode" value="external" checked> <strong>' . esc_html__('داخل هم‌نور + کانال‌های متصل', 'hamnoor-notifications') . '</strong></label>';
        echo '<label style="display:block"><input type="radio" name="mode" value="internal"> ' . esc_html__('فقط داخل هم‌نور', 'hamnoor-notifications') . '</label>';
        echo '<p class="description">' . esc_html__('ارسال بیرونی از همان اولویت و Fallback فعلی اعلان‌ها استفاده می‌کند و برای یک کاربر چند پیام‌رسان را هم‌زمان بمباران نمی‌کند.', 'hamnoor-notifications') . '</p></td></tr>';
        echo '</tbody></table>';
        echo '<div style="background:#fff8e5;border:1px solid #dba617;padding:14px 16px;border-radius:6px;max-width:760px;margin:18px 0">';
        echo '<label><input required type="checkbox" name="confirm" value="1"> <strong>' . sprintf(esc_html__('تأیید می‌کنم این پیام برای %s کاربر فعال ارسال شود.', 'hamnoor-notifications'), number_format_i18n($count)) . '</strong></label>';
        echo '</div>';
        submit_button(__('قرار دادن پیام در صف ارسال', 'hamnoor-notifications'), 'primary', 'submit', false);
        echo '</form></div>';

        self::render_history();
    }

    private static function render_history() {
        $posts = get_posts(array(
            'post_type'=>self::POST_TYPE,
            'post_status'=>'any',
            'numberposts'=>20,
            'orderby'=>'ID',
            'order'=>'DESC',
        ));
        echo '<h2 style="margin-top:30px">' . esc_html__('سوابق پیام‌های همگانی', 'hamnoor-notifications') . '</h2>';
        if (!$posts) {
            echo '<p>' . esc_html__('هنوز پیام همگانی ارسال نشده است.', 'hamnoor-notifications') . '</p>';
            return;
        }
        $labels = array(
            'queued'=>__('در صف', 'hamnoor-notifications'),
            'running'=>__('در حال ارسال', 'hamnoor-notifications'),
            'completed'=>__('تکمیل‌شده', 'hamnoor-notifications'),
            'cancelled'=>__('متوقف‌شده', 'hamnoor-notifications'),
            'failed'=>__('خطادار', 'hamnoor-notifications'),
        );
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('پیام', 'hamnoor-notifications') . '</th><th>' . esc_html__('وضعیت', 'hamnoor-notifications') . '</th><th>' . esc_html__('پیشرفت', 'hamnoor-notifications') . '</th><th>' . esc_html__('نوع ارسال', 'hamnoor-notifications') . '</th><th>' . esc_html__('زمان', 'hamnoor-notifications') . '</th><th>' . esc_html__('عملیات', 'hamnoor-notifications') . '</th></tr></thead><tbody>';
        foreach ($posts as $post) {
            $id = (int)$post->ID;
            $status = sanitize_key((string)self::meta($id, 'status', 'queued'));
            $total = (int)self::meta($id, 'total', 0);
            $processed = (int)self::meta($id, 'processed', 0);
            $mode = sanitize_key((string)self::meta($id, 'mode', 'external'));
            $last_error = (string)self::meta($id, 'last_error', '');
            echo '<tr><td><strong>' . esc_html(get_the_title($id)) . '</strong><br><span class="description">' . esc_html(wp_trim_words((string)$post->post_content, 18, '…')) . '</span></td>';
            echo '<td><strong>' . esc_html(isset($labels[$status]) ? $labels[$status] : $status) . '</strong>' . ($last_error ? '<br><code>' . esc_html($last_error) . '</code>' : '') . '</td>';
            echo '<td>' . number_format_i18n($processed) . ' / ' . number_format_i18n($total) . '</td>';
            echo '<td>' . esc_html('internal' === $mode ? __('فقط داخلی', 'hamnoor-notifications') : __('داخلی + بیرونی', 'hamnoor-notifications')) . '</td>';
            echo '<td><code>' . esc_html(get_the_date('Y-m-d H:i:s', $id)) . '</code></td><td>';
            if (in_array($status, array('queued','running'), true)) {
                $cancel = wp_nonce_url(add_query_arg(array('action'=>'hamnoor_broadcast_cancel','broadcast_id'=>$id), admin_url('admin-post.php')), 'hamnoor_broadcast_cancel_' . $id);
                echo '<a class="button button-secondary" href="' . esc_url($cancel) . '" onclick="return confirm(\'' . esc_js(__('ارسال این پیام متوقف شود؟ پیام‌های قبلاً ارسال‌شده قابل بازگشت نیستند.', 'hamnoor-notifications')) . '\')">' . esc_html__('توقف', 'hamnoor-notifications') . '</a> ';
            }
            if ('queued' === $status || 'running' === $status) {
                $run = wp_nonce_url(add_query_arg(array('action'=>'hamnoor_broadcast_run_now','broadcast_id'=>$id), admin_url('admin-post.php')), 'hamnoor_broadcast_run_' . $id);
                echo '<a class="button" href="' . esc_url($run) . '">' . esc_html__('اجرای یک نوبت الآن', 'hamnoor-notifications') . '</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('جزئیات موفق/ناموفق هر کانال در تب «گزارش ارسال» قابل مشاهده است.', 'hamnoor-notifications') . '</p>';
    }

    public static function handle_create() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('hamnoor_broadcast_create');
        if (empty($_POST['confirm'])) { wp_die(esc_html__('تأیید ارسال همگانی الزامی است.', 'hamnoor-notifications')); }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $title = function_exists('mb_substr') ? mb_substr(trim($title), 0, 120) : substr(trim($title), 0, 120);
        $message = function_exists('mb_substr') ? mb_substr(trim($message), 0, 1200) : substr(trim($message), 0, 1200);
        $mode = isset($_POST['mode']) && 'internal' === sanitize_key(wp_unslash($_POST['mode'])) ? 'internal' : 'external';
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url']), array('http','https')) : '';
        if ($url && !wp_http_validate_url($url)) { $url = ''; }
        if ('' === $title || '' === $message) { wp_die(esc_html__('عنوان و متن پیام الزامی است.', 'hamnoor-notifications')); }

        $max_user_id = self::max_active_user_id();
        $total = self::active_user_count($max_user_id);
        if ($total < 1) { wp_die(esc_html__('هیچ کاربر فعالی برای ارسال وجود ندارد.', 'hamnoor-notifications')); }

        $post_id = wp_insert_post(array(
            'post_type'=>self::POST_TYPE,
            'post_status'=>'private',
            'post_title'=>$title,
            'post_content'=>$message,
            'post_author'=>get_current_user_id(),
        ), true);
        if (is_wp_error($post_id)) { wp_die(esc_html($post_id->get_error_message())); }

        self::set_meta($post_id, 'status', 'queued');
        self::set_meta($post_id, 'mode', $mode);
        self::set_meta($post_id, 'url', $url);
        self::set_meta($post_id, 'cursor', 0);
        self::set_meta($post_id, 'max_user_id', $max_user_id);
        self::set_meta($post_id, 'total', $total);
        self::set_meta($post_id, 'processed', 0);
        self::set_meta($post_id, 'notifications_created', 0);
        self::set_meta($post_id, 'external_dispatch_attempts', 0);
        self::set_meta($post_id, 'created_at', current_time('mysql'));
        self::set_meta($post_id, 'last_error', '');

        self::ensure_schedule();
        // Start a small first batch immediately so the admin gets prompt feedback.
        self::process_broadcast((int)$post_id, min(5, self::batch_size()));
        self::redirect('created');
    }

    public static function handle_cancel() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $id = isset($_GET['broadcast_id']) ? (int)$_GET['broadcast_id'] : 0;
        check_admin_referer('hamnoor_broadcast_cancel_' . $id);
        if ($id && self::POST_TYPE === get_post_type($id)) {
            $status = sanitize_key((string)self::meta($id, 'status', ''));
            if (in_array($status, array('queued','running'), true)) {
                self::set_meta($id, 'status', 'cancelled');
                self::set_meta($id, 'completed_at', current_time('mysql'));
            }
        }
        self::redirect('cancelled');
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $id = isset($_GET['broadcast_id']) ? (int)$_GET['broadcast_id'] : 0;
        check_admin_referer('hamnoor_broadcast_run_' . $id);
        if ($id && self::POST_TYPE === get_post_type($id)) { self::process_broadcast($id, self::batch_size()); }
        self::redirect('ran');
    }

    private static function redirect($notice) {
        $url = add_query_arg(array('page'=>'hamnoor-notifications','channel'=>'broadcast','broadcast_notice'=>sanitize_key($notice)), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function batch_size() {
        return max(1, min(50, (int)apply_filters('hamnoor_broadcast_batch_size', self::DEFAULT_BATCH)));
    }

    public static function process_queue() {
        if (get_transient(self::LOCK_KEY)) { return 0; }
        set_transient(self::LOCK_KEY, '1', 55);
        $processed = 0;
        try {
            $jobs = get_posts(array(
                'post_type'=>self::POST_TYPE,
                'post_status'=>'any',
                'numberposts'=>3,
                'orderby'=>'ID',
                'order'=>'ASC',
                'meta_query'=>array(array(
                    'key'=>'_hamnoor_broadcast_status',
                    'value'=>array('queued','running'),
                    'compare'=>'IN',
                )),
            ));
            foreach ((array)$jobs as $job) {
                $processed += self::process_broadcast((int)$job->ID, self::batch_size());
                // Avoid a single cron request doing unbounded external HTTP work.
                if ($processed >= self::batch_size()) { break; }
            }
            update_option('hamnoor_broadcast_worker_last_run', array('at'=>current_time('mysql'),'processed'=>$processed), false);
        } catch (Throwable $e) {
            update_option('hamnoor_broadcast_worker_last_error', substr($e->getMessage(), 0, 500), false);
        }
        delete_transient(self::LOCK_KEY);
        return $processed;
    }

    public static function process_broadcast($broadcast_id, $limit = 0) {
        global $wpdb;
        $broadcast_id = (int)$broadcast_id;
        if (!$broadcast_id || self::POST_TYPE !== get_post_type($broadcast_id)) { return 0; }
        $status = sanitize_key((string)self::meta($broadcast_id, 'status', ''));
        if (!in_array($status, array('queued','running'), true)) { return 0; }

        $post = get_post($broadcast_id);
        if (!$post) { return 0; }
        $limit = $limit > 0 ? max(1, min(50, (int)$limit)) : self::batch_size();
        $cursor = (int)self::meta($broadcast_id, 'cursor', 0);
        $max_user_id = (int)self::meta($broadcast_id, 'max_user_id', 0);
        $mode = sanitize_key((string)self::meta($broadcast_id, 'mode', 'external'));
        $url = (string)self::meta($broadcast_id, 'url', '');
        $users_table = self::users_table();
        if (!$users_table || !$max_user_id) {
            self::set_meta($broadcast_id, 'status', 'failed');
            self::set_meta($broadcast_id, 'last_error', 'audience_unavailable');
            return 0;
        }

        self::set_meta($broadcast_id, 'status', 'running');
        if (!self::meta($broadcast_id, 'started_at', '')) { self::set_meta($broadcast_id, 'started_at', current_time('mysql')); }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $users_table WHERE status='active' AND id>%d AND id<=%d ORDER BY id ASC LIMIT %d",
            $cursor, $max_user_id, $limit
        ));
        if (!$ids) {
            self::set_meta($broadcast_id, 'status', 'completed');
            self::set_meta($broadcast_id, 'completed_at', current_time('mysql'));
            return 0;
        }

        $processed = 0;
        $created = (int)self::meta($broadcast_id, 'notifications_created', 0);
        $external_attempts = (int)self::meta($broadcast_id, 'external_dispatch_attempts', 0);
        foreach ($ids as $user_id) {
            $user_id = (int)$user_id;
            try {
                $notification = self::notification_for_user($broadcast_id, $user_id);
                if (!$notification) {
                    $notification_id = Hamnoor_DB::add_notification(
                        $user_id,
                        self::TYPE,
                        (string)$post->post_title,
                        (string)$post->post_content,
                        'broadcast',
                        $broadcast_id,
                        false
                    );
                    if ($notification_id) {
                        $created++;
                        $notification = self::get_notification((int)$notification_id);
                    }
                }
                if ($notification && 'external' === $mode && class_exists('Hamnoor_Notification_Dispatcher')) {
                    // Existing dispatcher handles consent, channel priority,
                    // fallback and retry. No direct provider API is called here.
                    Hamnoor_Notification_Dispatcher::dispatch($notification, $url ? array('url'=>$url,'broadcast_id'=>$broadcast_id) : array('broadcast_id'=>$broadcast_id));
                    $external_attempts++;
                }
                self::set_meta($broadcast_id, 'last_error', '');
            } catch (Throwable $e) {
                // A channel/provider problem must not stop the broadcast cursor.
                self::set_meta($broadcast_id, 'last_error', substr($e->getMessage(), 0, 500));
            }
            $cursor = $user_id;
            $processed++;
            self::set_meta($broadcast_id, 'cursor', $cursor);
            self::set_meta($broadcast_id, 'processed', (int)self::meta($broadcast_id, 'processed', 0) + 1);
            self::set_meta($broadcast_id, 'notifications_created', $created);
            self::set_meta($broadcast_id, 'external_dispatch_attempts', $external_attempts);
        }

        // Complete immediately when this batch reached the snapshot's max ID.
        if ($cursor >= $max_user_id) {
            self::set_meta($broadcast_id, 'status', 'completed');
            self::set_meta($broadcast_id, 'completed_at', current_time('mysql'));
        }
        return $processed;
    }

    private static function notification_for_user($broadcast_id, $user_id) {
        global $wpdb;
        $table = self::notifications_table();
        if (!$table) { return null; }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id=%d AND type=%s AND related_type='broadcast' AND related_id=%d ORDER BY id ASC LIMIT 1",
            (int)$user_id, self::TYPE, (int)$broadcast_id
        ));
    }

    private static function get_notification($notification_id) {
        global $wpdb;
        $table = self::notifications_table();
        if (!$table) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int)$notification_id));
    }
}
