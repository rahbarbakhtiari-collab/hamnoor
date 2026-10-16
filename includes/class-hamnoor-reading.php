<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Internal Quran reading experience for claimed Hamnoor parts.
 *
 * Quran text and metadata are provided by Manahel Quran Core. Hamnoor stores
 * only the participant's reading position and completion state.
 */
class Hamnoor_Reading {
    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_READING     = 'reading';
    const STATUS_READY       = 'ready';
    const STATUS_COMPLETED   = 'completed';

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 20);
        add_action('wp_ajax_hamnoor_save_reading_progress', array(__CLASS__, 'ajax_save_progress'));
        add_action('wp_ajax_nopriv_hamnoor_save_reading_progress', array(__CLASS__, 'ajax_save_progress'));
        add_action('wp_ajax_hamnoor_finish_reading', array(__CLASS__, 'ajax_finish_reading'));
        add_action('wp_ajax_nopriv_hamnoor_finish_reading', array(__CLASS__, 'ajax_finish_reading'));
        add_action('wp_ajax_hamnoor_reading_state', array(__CLASS__, 'ajax_reading_state'));
        add_action('wp_ajax_nopriv_hamnoor_reading_state', array(__CLASS__, 'ajax_reading_state'));
        add_action('wp_ajax_hamnoor_save_audio_position', array(__CLASS__, 'ajax_save_audio_position'));
        add_action('wp_ajax_nopriv_hamnoor_save_audio_position', array(__CLASS__, 'ajax_save_audio_position'));
        add_action('wp_footer', array(__CLASS__, 'render_floating_continue'), 40);
        add_action('hamnoor_after_complete_part', array(__CLASS__, 'sync_completed_part'), 10, 4);
        add_action('hamnoor_after_release_part', array(__CLASS__, 'remove_released_progress'), 10, 5);
        add_action('admin_notices', array(__CLASS__, 'dependency_notice'));
    }

    public static function available() {
        static $available = null;
        if (null !== $available) { return $available; }
        if (!function_exists('manahel_quran_core')) { $available = false; return false; }
        $service = manahel_quran_core();
        $required = array('get_verse', 'get_verse_by_index', 'get_range_by_index', 'get_division', 'get_surah', 'progress_percent');
        if (!is_object($service)) { $available = false; return false; }
        foreach ($required as $method) {
            if (!method_exists($service, $method)) { $available = false; return false; }
        }
        $available = !empty($service->get_verse(1, 1));
        return $available;
    }

    public static function dependency_notice() {
        if (!current_user_can('manage_options') || self::available()) { return; }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('قرآن هم‌نور در دسترس نیست.', 'hamnoor') . '</strong> ' . esc_html__('افزونه «هسته قرآن مناهل» را نصب و فعال کنید و واردسازی آیات را کامل کنید.', 'hamnoor') . '</p></div>';
    }

    public static function enqueue_assets() {
        $is_context = Hamnoor_Frontend::instance()->is_shortcode_context() || Hamnoor_Router::instance()->is_hamnoor_request();
        $floating = !is_admin() && Hamnoor_Mobile_Access::is_logged_in() ? self::incomplete_parts() : array();
        if (!$is_context && !$floating) { return; }
        wp_enqueue_style('hamnoor-frontend', HAMNOOR_URL . 'assets/css/hamnoor-frontend.css', array(), Hamnoor_Helpers::asset_version('assets/css/hamnoor-frontend.css'));
        wp_add_inline_style('hamnoor-frontend', Hamnoor_Helpers::dynamic_css());
        wp_enqueue_style('hamnoor-reading', HAMNOOR_URL . 'assets/css/hamnoor-reading.css', array('hamnoor-frontend'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-reading.css'));
        if (!empty($_GET['hamnoor_reading'])) {
            wp_enqueue_style('hamnoor-reading-desktop', HAMNOOR_URL . 'assets/css/hamnoor-reading-desktop.css', array('hamnoor-reading'), Hamnoor_Helpers::asset_version('assets/css/hamnoor-reading-desktop.css'), '(min-width: 783px)');
        }
        if ($floating) {
            wp_enqueue_script('hamnoor-floating', HAMNOOR_URL . 'assets/js/hamnoor-floating.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-floating.js'), true);
        }
        if (empty($_GET['hamnoor_reading'])) { return; }
        wp_enqueue_script('hamnoor-reading', HAMNOOR_URL . 'assets/js/hamnoor-reading.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-reading.js'), true);
        $reading_access = isset($_GET['hamnoor_reading_access']) ? Hamnoor_Helpers::sanitize_tracking_token(wp_unslash($_GET['hamnoor_reading_access'])) : '';
        wp_localize_script('hamnoor-reading', 'HamnoorReading', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hamnoor_reading'),
            'access' => $reading_access,
            'dashboard_url' => add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard')),
            'actions' => array(
                'state' => 'hamnoor_reading_state',
            ),
            'texts' => array(
                'saving' => __('در حال ذخیره محل مطالعه...', 'hamnoor'),
                'saved' => __('محل مطالعه ذخیره شد.', 'hamnoor'),
                'error' => __('ذخیره محل مطالعه انجام نشد. دوباره تلاش می‌کنیم.', 'hamnoor'),
                'finishing' => __('در حال ثبت پایان قرائت...', 'hamnoor'),
                'finish_confirm' => __('قرائت این سهم به پایان رسیده است؟', 'hamnoor'),
                'finish_button' => __('قرائت تمام شد', 'hamnoor'),
                'ready' => __('به پایان سهم رسیدید؛ اگر قرائت کامل شده است، پایان آن را ثبت کنید.', 'hamnoor'),
                'offline' => __('محل مطالعه روی این دستگاه محفوظ است و پس از اتصال همگام می‌شود.', 'hamnoor'),
                'sync_pending' => __('محل مطالعه روی این دستگاه ثبت شده و در انتظار همگام‌سازی با سرور است.', 'hamnoor'),
                'synced_elsewhere' => __('پیشرفت قرائت از تب یا دستگاه دیگر همگام شد.', 'hamnoor'),
                'completed_elsewhere' => __('این سهم در تب یا دستگاه دیگری تکمیل شده است.', 'hamnoor'),
                'unavailable' => __('این سهم دیگر در وضعیت قابل قرائت نیست.', 'hamnoor'),
                'checking_state' => __('در حال بررسی آخرین وضعیت سهم…', 'hamnoor'),
                'state_check_error' => __('ارتباط با هم‌نور برقرار نشد. متن برای مطالعه در دسترس است؛ وضعیت سهم هنوز تأیید نشده است.', 'hamnoor'),
                'offline_status' => __('آفلاین؛ محل مطالعه روی این دستگاه محفوظ می‌ماند.', 'hamnoor'),
                'online_status' => __('اتصال برقرار شد؛ در حال همگام‌سازی…', 'hamnoor'),
                'audio_error' => __('پخش صوت این آیه ممکن نشد.', 'hamnoor'),
                'audio_paused_restore' => __('پخش صوت پس از بازگشت به صفحه متوقف مانده است؛ برای ادامه دوباره پخش را بزنید.', 'hamnoor'),
            ),
        ));
    }

    public static function reading_url($part_id, $tracking_token = '') {
        $args = array(
            'tab' => 'my-parts',
            'hamnoor_reading' => (int) $part_id,
        );
        $tracking_token = Hamnoor_Helpers::sanitize_tracking_token((string) $tracking_token);
        if ($tracking_token) { $args['hamnoor_reading_access'] = $tracking_token; }
        return add_query_arg($args, Hamnoor_Helpers::page_url('dashboard'));
    }

    public static function audio_url($surah, $ayah) {
        $surah = max(1, min(114, (int) $surah));
        $ayah = max(1, (int) $ayah);
        $url = sprintf('https://www.manahel.ir/tartil/parhizkar/%03d%03d.mp3', $surah, $ayah);
        return esc_url_raw(apply_filters('hamnoor_recitation_audio_url', $url, $surah, $ayah, 'parhizkar'));
    }

    public static function can_access_part($part, $tracking_token = '') {
        if (!$part) { return false; }
        $tracking_token = Hamnoor_Helpers::sanitize_tracking_token((string) $tracking_token);
        if ($tracking_token && !empty($part->tracking_token) && hash_equals((string) $part->tracking_token, $tracking_token)) { return true; }
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if ($user_id && !empty($part->participant_user_id) && (int) $part->participant_user_id === (int) $user_id) { return true; }
        return Hamnoor_DB::user_can_manage_khatm((int) $part->khatm_id, $user_id);
    }

    /**
     * Apply the exact campaign completion policy to mutable reading state.
     * Managers keep the same privileged grace behavior as complete_part().
     */
    private static function completion_policy_for_part($part) {
        if (!$part) {
            return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor'));
        }
        $khatm = Hamnoor_DB::get_khatm((int) $part->khatm_id);
        $privileged = Hamnoor_DB::user_can_manage_khatm((int) $part->khatm_id, Hamnoor_Mobile_Access::current_user_id());
        return Hamnoor_DB::khatm_accepts_completion($khatm, $privileged);
    }

    private static function posted_access_token() {
        return isset($_POST['access']) ? Hamnoor_Helpers::sanitize_tracking_token(wp_unslash($_POST['access'])) : '';
    }

    private static function rate_actor_key($tracking_token = '') {
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if ($user_id) { return 'u:' . (int) $user_id; }
        $tracking_token = Hamnoor_Helpers::sanitize_tracking_token((string) $tracking_token);
        return $tracking_token ? 't:' . substr(hash('sha256', $tracking_token), 0, 20) : 'guest';
    }

    public static function range_for_part($part) {
        if (!self::available()) {
            return new WP_Error('hamnoor_quran_core_missing', __('هسته قرآن مناهل فعال نیست.', 'hamnoor'));
        }
        if (!$part) {
            return new WP_Error('hamnoor_part_not_found', __('سهم مورد نظر پیدا نشد.', 'hamnoor'));
        }
        $service = manahel_quran_core();
        $type = sanitize_key((string) $part->part_type);
        $number = (int) $part->part_number;
        static $cache = array();
        $cache_key = $type . ':' . $number;
        if (isset($cache[$cache_key])) { return $cache[$cache_key]; }
        if (in_array($type, array('page', 'juz'), true)) {
            $range = $service->get_division($type, $number);
            if (!$range) {
                return new WP_Error('hamnoor_quran_range_missing', __('محدوده قرآنی این سهم پیدا نشد.', 'hamnoor'));
            }
            $cache[$cache_key] = array(
                'start_global_index' => (int) $range['start_global_index'],
                'end_global_index' => (int) $range['end_global_index'],
                'label' => 'page' === $type ? sprintf(__('صفحه %d', 'hamnoor'), $number) : sprintf(__('جزء %d', 'hamnoor'), $number),
            );
            return $cache[$cache_key];
        }
        if ('surah' === $type) {
            $surah = $service->get_surah($number);
            if (!$surah) {
                return new WP_Error('hamnoor_quran_surah_missing', __('سوره مربوط به این سهم پیدا نشد.', 'hamnoor'));
            }
            $start = (int) $surah['start_global_index'];
            $cache[$cache_key] = array(
                'start_global_index' => $start,
                'end_global_index' => $start + (int) $surah['ayah_count'] - 1,
                'label' => sprintf(__('سوره %s', 'hamnoor'), (string) $surah['arabic_name']),
            );
            return $cache[$cache_key];
        }
        return new WP_Error('hamnoor_quran_type_unsupported', __('امکان نمایش این نوع سهم در قرآن هم‌نور فراهم نیست.', 'hamnoor'));
    }

    private static function load_verses($start, $end) {
        $start = max(1, (int) $start);
        $end = max($start, (int) $end);
        $verses = array();
        $cursor = $start;
        while ($cursor <= $end) {
            $chunk_end = min($end, $cursor + 499);
            $chunk = manahel_quran_core()->get_range_by_index($cursor, $chunk_end, 500);
            if (!is_array($chunk) || !$chunk) { break; }
            foreach ($chunk as $verse) { $verses[] = $verse; }
            $last = end($chunk);
            $last_index = is_array($last) && isset($last['global_index']) ? (int) $last['global_index'] : 0;
            if ($last_index < $cursor) { break; }
            $cursor = $last_index + 1;
        }
        $expected = $end - $start + 1;
        return count($verses) === $expected ? $verses : array();
    }

    public static function get_progress($part_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('reading_progress');
        if (!$table) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE part_id=%d LIMIT 1", (int) $part_id));
    }

    public static function ensure_progress($part) {
        global $wpdb;
        $existing = self::get_progress((int) $part->id);
        if ($existing) { return $existing; }
        $range = self::range_for_part($part);
        if (is_wp_error($range)) { return $range; }
        $now = current_time('mysql');
        $status = 'done' === (string) $part->status ? self::STATUS_COMPLETED : self::STATUS_NOT_STARTED;
        $last = self::STATUS_COMPLETED === $status ? (int) $range['end_global_index'] : max(0, (int) $range['start_global_index'] - 1);
        $data = array(
            'part_id' => (int) $part->id,
            'participant_id' => (int) $part->participant_id,
            'user_id' => !empty($part->participant_user_id) ? (int) $part->participant_user_id : Hamnoor_Mobile_Access::current_user_id(),
            'khatm_id' => (int) $part->khatm_id,
            'start_global_index' => (int) $range['start_global_index'],
            'end_global_index' => (int) $range['end_global_index'],
            'last_global_index' => $last,
            'reading_status' => $status,
            'started_at' => null,
            'last_read_at' => null,
            'completed_at' => self::STATUS_COMPLETED === $status ? $now : null,
            'audio_global_index' => null,
            'audio_position_seconds' => 0,
            'audio_updated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        );
        $inserted = $wpdb->insert(Hamnoor_DB::table('reading_progress'), $data, array('%d','%d','%d','%d','%d','%d','%d','%s','%s','%s','%s','%d','%f','%s','%s','%s'));
        if (false === $inserted) {
            $retry = self::get_progress((int) $part->id);
            return $retry ? $retry : new WP_Error('hamnoor_reading_create_failed', __('ساخت پرونده پیشرفت قرائت انجام نشد.', 'hamnoor'));
        }
        return self::get_progress((int) $part->id);
    }

    public static function card_state($part, $tracking_token = '') {
        static $cache = array();
        $part_id = $part && isset($part->id) ? (int) $part->id : 0;
        $tracking_token = Hamnoor_Helpers::sanitize_tracking_token((string) $tracking_token);
        $cache_scope = Hamnoor_Mobile_Access::current_user_id() ? 'u:' . Hamnoor_Mobile_Access::current_user_id() : ($tracking_token ? 't:' . substr(hash('sha256', $tracking_token), 0, 20) : 'guest');
        $cache_key = $part_id . '|' . $cache_scope;
        if ($part_id && array_key_exists($cache_key, $cache)) { return $cache[$cache_key]; }
        if (!self::available() || !$part || !self::can_access_part($part, $tracking_token) || !in_array((string) $part->status, array('claimed', 'done'), true)) {
            if ($part_id) { $cache[$cache_key] = null; }
            return null;
        }
        if ('claimed' === (string) $part->status && is_wp_error(self::completion_policy_for_part($part))) {
            if ($part_id) { $cache[$cache_key] = null; }
            return null;
        }
        $range = self::range_for_part($part);
        if (is_wp_error($range)) { $cache[$cache_key] = null; return null; }
        $progress = self::get_progress($part_id);
        $last = $progress ? (int) $progress->last_global_index : ((int) $range['start_global_index'] - 1);
        if ('done' === (string) $part->status) { $last = (int) $range['end_global_index']; }
        $percent = manahel_quran_core()->progress_percent((int) $range['start_global_index'], (int) $range['end_global_index'], $last);
        $cache[$cache_key] = array(
            'percent' => $percent,
            'started' => $last >= (int) $range['start_global_index'],
            'ready' => 'done' !== (string) $part->status && $last >= (int) $range['end_global_index'],
            'completed' => 'done' === (string) $part->status,
            'url' => self::reading_url($part_id, $tracking_token),
            'last_global_index' => $last,
            'progress_record' => $progress,
        );
        return $cache[$cache_key];
    }

    public static function render($part_id, $tracking_token = '') {
        $tracking_token = Hamnoor_Helpers::sanitize_tracking_token((string) $tracking_token);
        if (!self::available()) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('هسته قرآن مناهل فعال یا آماده نیست.', 'hamnoor') . '</div>';
        }
        $part = Hamnoor_DB::get_part((int) $part_id);
        if (!$part || !self::can_access_part($part, $tracking_token)) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('به این سهم دسترسی ندارید.', 'hamnoor') . '</div>';
        }
        if (!in_array((string) $part->status, array('claimed', 'done'), true)) {
            return '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این سهم در وضعیت قابل قرائت نیست.', 'hamnoor') . '</div>';
        }
        if ('claimed' === (string) $part->status) {
            $completion_policy = self::completion_policy_for_part($part);
            if (is_wp_error($completion_policy)) {
                return '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html($completion_policy->get_error_message()) . '</div>';
            }
        }
        $range = self::range_for_part($part);
        if (is_wp_error($range)) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html($range->get_error_message()) . '</div>';
        }
        $progress = self::ensure_progress($part);
        if (is_wp_error($progress)) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html($progress->get_error_message()) . '</div>';
        }
        $verses = self::load_verses((int) $range['start_global_index'], (int) $range['end_global_index']);
        if (!$verses) {
            return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('آیات این سهم در هسته قرآن پیدا نشد.', 'hamnoor') . '</div>';
        }
        $last = (int) $progress->last_global_index;
        if ('done' === (string) $part->status) { $last = (int) $range['end_global_index']; }
        $percent = manahel_quran_core()->progress_percent((int) $range['start_global_index'], (int) $range['end_global_index'], $last);
        $resume = $last >= (int) $range['start_global_index'] && $last < (int) $range['end_global_index'] ? $last : (int) $range['start_global_index'];
        $audio_resume = !empty($progress->audio_global_index) ? (int) $progress->audio_global_index : $resume;
        if ($audio_resume < (int) $range['start_global_index'] || $audio_resume > (int) $range['end_global_index']) { $audio_resume = $resume; }
        $audio_position_seconds = !empty($progress->audio_position_seconds) ? (float) $progress->audio_position_seconds : 0;
        $khatm = Hamnoor_DB::get_khatm((int) $part->khatm_id);
        $reading_access = $tracking_token;
        $scoped_guest_access = !Hamnoor_Mobile_Access::is_logged_in() && (bool) $tracking_token;
        ob_start();
        $template = Hamnoor_Helpers::locate_template('frontend/reading.php');
        include $template;
        return ob_get_clean();
    }

    public static function ajax_save_progress() {
        check_ajax_referer('hamnoor_reading', 'nonce');
        $access = self::posted_access_token();
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $global_index = isset($_POST['global_index']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['global_index'])) : 0;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || !self::can_access_part($part, $access) || 'claimed' !== (string) $part->status) {
            wp_send_json_error(array('message' => __('این سهم برای ثبت پیشرفت در دسترس شما نیست.', 'hamnoor')));
        }
        $completion_policy = self::completion_policy_for_part($part);
        if (is_wp_error($completion_policy)) {
            wp_send_json_error(array('message' => $completion_policy->get_error_message()));
        }
        $progress = self::ensure_progress($part);
        if (is_wp_error($progress)) { wp_send_json_error(array('message' => $progress->get_error_message())); }
        $start = (int) $progress->start_global_index;
        $end = (int) $progress->end_global_index;
        if ($global_index < $start || $global_index > $end) {
            wp_send_json_error(array('message' => __('آیه انتخاب‌شده خارج از محدوده سهم است.', 'hamnoor')));
        }
        $rate = Hamnoor_Helpers::rate_limit('reading_progress', $part_id . '|' . self::rate_actor_key($access), 120, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message' => $rate->get_error_message())); }
        global $wpdb;
        // Keep the monotonic invariant inside one atomic SQL UPDATE. A PHP-side
        // read/max/write sequence can regress when two tabs or devices overlap.
        $now = current_time('mysql');
        $progress_table = Hamnoor_DB::table('reading_progress');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $progress_table
             SET last_global_index=GREATEST(COALESCE(last_global_index,start_global_index-1),%d),
                 reading_status=CASE WHEN GREATEST(COALESCE(last_global_index,start_global_index-1),%d)>=end_global_index THEN %s ELSE %s END,
                 started_at=COALESCE(started_at,%s),
                 last_read_at=%s,
                 updated_at=%s
             WHERE part_id=%d",
            $global_index,
            $global_index,
            self::STATUS_READY,
            self::STATUS_READING,
            $now,
            $now,
            $now,
            $part_id
        ));
        if (false === $updated) { wp_send_json_error(array('message' => __('ذخیره پیشرفت انجام نشد.', 'hamnoor'))); }
        $persisted = self::get_progress($part_id);
        if (!$persisted) { wp_send_json_error(array('message' => __('خواندن وضعیت ذخیره‌شده انجام نشد.', 'hamnoor'))); }
        $global_index = (int) $persisted->last_global_index;
        $end = (int) $persisted->end_global_index;
        $verse = manahel_quran_core()->get_verse_by_index($global_index);
        $percent = manahel_quran_core()->progress_percent($start, $end, $global_index);
        do_action('hamnoor_reading_progress_saved', $part_id, $global_index, $percent, Hamnoor_Mobile_Access::current_user_id());
        wp_send_json_success(array(
            'message' => $global_index >= $end ? __('به پایان سهم رسیدید؛ اکنون پایان قرائت را ثبت کنید.', 'hamnoor') : __('محل قرائت ذخیره شد.', 'hamnoor'),
            'part_id' => $part_id,
            'global_index' => $global_index,
            'verse_key' => $verse ? (string) $verse['verse_key'] : '',
            'percent' => $percent,
            'ready' => $global_index >= $end ? 1 : 0,
        ));
    }

    public static function ajax_reading_state() {
        check_ajax_referer('hamnoor_reading', 'nonce');
        $access = self::posted_access_token();
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $part = $part_id ? Hamnoor_DB::get_part($part_id) : null;
        $fallback_url = Hamnoor_Mobile_Access::is_logged_in()
            ? add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard'))
            : Hamnoor_Helpers::page_url('list');

        if (!$part || !self::can_access_part($part, $access)) {
            wp_send_json_success(array(
                'state' => 'unavailable',
                'completed' => 0,
                'ready' => 0,
                'message' => __('این سهم دیگر در وضعیت قابل قرائت نیست.', 'hamnoor'),
                'dashboard_url' => $fallback_url,
            ));
        }

        if (!in_array((string) $part->status, array('claimed', 'done'), true)) {
            wp_send_json_success(array(
                'state' => 'unavailable',
                'completed' => 0,
                'ready' => 0,
                'message' => __('وضعیت این سهم تغییر کرده و دیگر برای قرائت فعال نیست.', 'hamnoor'),
                'dashboard_url' => $fallback_url,
            ));
        }
        if ('claimed' === (string) $part->status) {
            $completion_policy = self::completion_policy_for_part($part);
            if (is_wp_error($completion_policy)) {
                wp_send_json_success(array(
                    'state' => 'unavailable',
                    'completed' => 0,
                    'ready' => 0,
                    'message' => $completion_policy->get_error_message(),
                    'dashboard_url' => $fallback_url,
                ));
            }
        }

        $range = self::range_for_part($part);
        if (is_wp_error($range)) { wp_send_json_error(array('message' => $range->get_error_message())); }
        $progress = self::get_progress($part_id);

        $start = (int) $range['start_global_index'];
        $end = (int) $range['end_global_index'];
        $last = $progress ? (int) $progress->last_global_index : ($start - 1);
        if ('done' === (string) $part->status) { $last = $end; }
        $percent = manahel_quran_core()->progress_percent($start, $end, $last);

        if ('done' === (string) $part->status) {
            $actions = self::post_completion_actions($part, $access);
            wp_send_json_success(array(
                'state' => self::STATUS_COMPLETED,
                'completed' => 1,
                'ready' => 1,
                'global_index' => $end,
                'percent' => 100,
                'message' => __('این سهم قبلاً تکمیل شده است.', 'hamnoor'),
                'next_action' => $actions['next_action'],
                'campaign_url' => $actions['campaign_url'],
                'dashboard_url' => $actions['dashboard_url'],
            ));
        }

        $ready = $last >= $end;
        $state = $ready ? self::STATUS_READY : ($last >= $start ? self::STATUS_READING : self::STATUS_NOT_STARTED);
        wp_send_json_success(array(
            'state' => $state,
            'completed' => 0,
            'ready' => $ready ? 1 : 0,
            'global_index' => $last,
            'percent' => $percent,
            'message' => '',
            'dashboard_url' => $fallback_url,
        ));
    }

    public static function ajax_finish_reading() {
        check_ajax_referer('hamnoor_reading', 'nonce');
        $access = self::posted_access_token();
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || !self::can_access_part($part, $access)) { wp_send_json_error(array('message' => __('به این سهم دسترسی ندارید.', 'hamnoor'))); }
        $finish_redirect = add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard'));
        if (!Hamnoor_Mobile_Access::is_logged_in() && $access) {
            $finish_khatm = Hamnoor_DB::get_khatm((int) $part->khatm_id);
            if ($finish_khatm) { $finish_redirect = Hamnoor_Helpers::part_access_url($finish_khatm, $part_id, $access); }
        }
        if ('done' === (string) $part->status) {
            $actions = self::post_completion_actions($part, $access);
            wp_send_json_success(array(
                'message' => __('پایان قرائت این سهم قبلاً ثبت شده است.', 'hamnoor'),
                'redirect' => $finish_redirect,
                'completed' => 1,
                'next_action' => $actions['next_action'],
                'campaign_url' => $actions['campaign_url'],
                'dashboard_url' => $actions['dashboard_url'],
            ));
        }
        $progress = self::ensure_progress($part);
        if (is_wp_error($progress)) { wp_send_json_error(array('message' => $progress->get_error_message())); }
        if ((int) $progress->last_global_index < (int) $progress->end_global_index) {
            wp_send_json_error(array('message' => __('ابتدا تا انتهای سهم پیش بروید تا وضعیت «آماده تکمیل» فعال شود.', 'hamnoor')));
        }
        $result = Hamnoor_DB::complete_part($part_id, $access, false);
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
        self::mark_progress_completed($part_id);
        $part = Hamnoor_DB::get_part($part_id);
        $actions = self::post_completion_actions($part, $access);
        $extension_feedback = $part ? Hamnoor_Domain_Events::completion_extension_feedback(
            'quran_part',
            Hamnoor_Domain_Events::stable_entity_id(isset($part->public_id) ? $part->public_id : '', 'part', (int)$part->id)
        ) : array();
        wp_send_json_success(array(
            'message' => __('قرائت سهم با موفقیت تکمیل و ثبت شد. خداوند قبول فرماید.', 'hamnoor'),
            'redirect' => $finish_redirect,
            'completed' => 1,
            'next_action' => $actions['next_action'],
            'campaign_url' => $actions['campaign_url'],
            'dashboard_url' => $actions['dashboard_url'],
            'extension_feedback' => $extension_feedback,
        ));
    }

    private static function post_completion_actions($part, $access = '') {
        $campaign_url = Hamnoor_Helpers::page_url('list');
        if ($part) {
            $khatm = Hamnoor_DB::get_khatm((int) $part->khatm_id);
            if ($khatm) { $campaign_url = Hamnoor_Helpers::khatm_url($khatm); }
        }

        $dashboard_url = add_query_arg('tab', 'my-parts', Hamnoor_Helpers::page_url('dashboard'));
        if (!Hamnoor_Mobile_Access::is_logged_in() && $part && $access) {
            $khatm = isset($khatm) ? $khatm : Hamnoor_DB::get_khatm((int) $part->khatm_id);
            if ($khatm) { $dashboard_url = Hamnoor_Helpers::part_access_url($khatm, (int) $part->id, $access); }
        }

        $next_action = array(
            'url' => $campaign_url,
            'label' => __('انتخاب سهم بعدی', 'hamnoor'),
            'type' => 'choose',
        );
        if (Hamnoor_Mobile_Access::is_logged_in()) {
            foreach (self::incomplete_parts() as $item) {
                if ($part && (int) $item['id'] === (int) $part->id) { continue; }
                $next_action = array(
                    'url' => (string) $item['url'],
                    'label' => !empty($item['ready']) ? __('ثبت پایان سهم بعدی', 'hamnoor') : (!empty($item['started']) ? __('ادامه سهم بعدی', 'hamnoor') : __('شروع سهم بعدی', 'hamnoor')),
                    'type' => !empty($item['ready']) ? 'finish_reserved' : (!empty($item['started']) ? 'continue_reserved' : 'start_reserved'),
                );
                break;
            }
        }

        return array(
            'next_action' => $next_action,
            'campaign_url' => $campaign_url,
            'dashboard_url' => $dashboard_url,
        );
    }

    public static function ajax_save_audio_position() {
        check_ajax_referer('hamnoor_reading', 'nonce');
        $access = self::posted_access_token();
        $part_id = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $global_index = isset($_POST['global_index']) ? (int) Hamnoor_Validator::normalize_digits(wp_unslash($_POST['global_index'])) : 0;
        $seconds = isset($_POST['seconds']) ? max(0, min(86400, (float) wp_unslash($_POST['seconds']))) : 0;
        $part = Hamnoor_DB::get_part($part_id);
        if (!$part || !self::can_access_part($part, $access) || !in_array((string) $part->status, array('claimed','done'), true)) {
            wp_send_json_error(array('message' => __('این سهم برای ذخیره موقعیت صوت در دسترس نیست.', 'hamnoor')));
        }
        if ('claimed' === (string) $part->status) {
            $completion_policy = self::completion_policy_for_part($part);
            if (is_wp_error($completion_policy)) {
                wp_send_json_error(array('message' => $completion_policy->get_error_message()));
            }
        }
        $progress = self::ensure_progress($part);
        if (is_wp_error($progress)) { wp_send_json_error(array('message' => $progress->get_error_message())); }
        if ($global_index < (int) $progress->start_global_index || $global_index > (int) $progress->end_global_index) {
            wp_send_json_error(array('message' => __('آیه صوتی خارج از محدوده سهم است.', 'hamnoor')));
        }
        $rate = Hamnoor_Helpers::rate_limit('reading_audio', $part_id . '|' . self::rate_actor_key($access), 150, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { wp_send_json_error(array('message' => $rate->get_error_message())); }
        global $wpdb;
        $updated = $wpdb->update(Hamnoor_DB::table('reading_progress'), array(
            'audio_global_index' => $global_index,
            'audio_position_seconds' => $seconds,
            'audio_updated_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ), array('part_id'=>$part_id), array('%d','%f','%s','%s'), array('%d'));
        if (false === $updated) { wp_send_json_error(array('message' => __('موقعیت صوت ذخیره نشد.', 'hamnoor'))); }
        wp_send_json_success(array('global_index'=>$global_index, 'seconds'=>$seconds));
    }

    public static function incomplete_parts() {
        static $cache = null;
        if (null !== $cache) { return $cache; }
        $cache = array();
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        if (!$user_id || !self::available()) { return $cache; }
        global $wpdb;
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $khatms = Hamnoor_DB::table('khatms');
        $progress = Hamnoor_DB::table('reading_progress');
        $sql = $wpdb->prepare(
            "SELECT p.*, pa.user_id AS participant_user_id, k.title AS khatm_title, rp.last_global_index, rp.start_global_index, rp.end_global_index, rp.reading_status, rp.last_read_at
             FROM $parts p
             INNER JOIN $participants pa ON pa.id=p.participant_id
             INNER JOIN $khatms k ON k.id=p.khatm_id
             LEFT JOIN $progress rp ON rp.part_id=p.id
             WHERE p.status='claimed' AND pa.user_id=%d AND k.status IN ('active','published','paused','expired','completed')
             ORDER BY COALESCE(rp.last_read_at,p.claimed_at) DESC, p.id DESC
             LIMIT 20",
            $user_id
        );
        foreach ((array) $wpdb->get_results($sql) as $row) {
            if (is_wp_error(self::completion_policy_for_part($row))) { continue; }
            $range = self::range_for_part($row);
            if (is_wp_error($range)) { continue; }
            $last = null !== $row->last_global_index ? (int) $row->last_global_index : ((int) $range['start_global_index'] - 1);
            $percent = manahel_quran_core()->progress_percent((int) $range['start_global_index'], (int) $range['end_global_index'], $last);
            $cache[] = array(
                'id'=>(int)$row->id,
                'part_title'=>(string)$row->title,
                'khatm_title'=>(string)$row->khatm_title,
                'percent'=>(int)$percent,
                'started'=>$last >= (int)$range['start_global_index'],
                'ready'=>$last >= (int)$range['end_global_index'],
                'url'=>self::reading_url((int)$row->id),
            );
        }
        return $cache;
    }

    public static function render_floating_continue() {
        if (is_admin() || !Hamnoor_Mobile_Access::is_logged_in() || !empty($_GET['hamnoor_reading'])) { return; }
        $items = self::incomplete_parts();
        if (!$items) { return; }
        $count = count($items);
        $first = $items[0];
        if (1 === $count) {
            $label = $first['ready'] ? __('ثبت پایان سهم', 'hamnoor') : ($first['started'] ? __('ادامه قرائت', 'hamnoor') : __('شروع قرائت سهم', 'hamnoor'));
            echo '<a class="hamnoor-floating-continue" href="' . esc_url($first['url']) . '"><span>' . esc_html($label) . '</span><small>' . esc_html($first['percent'] . '٪') . '</small></a>';
            return;
        }
        echo '<div class="hamnoor-floating-wrap" data-hamnoor-floating><button type="button" class="hamnoor-floating-continue" data-hamnoor-floating-toggle aria-expanded="false"><span>' . esc_html(sprintf(__('ادامه قرائت‌ها (%d)', 'hamnoor'), $count)) . '</span></button><div class="hamnoor-floating-panel" data-hamnoor-floating-panel hidden>';
        echo '<strong>' . esc_html__('سهم‌های ناتمام شما', 'hamnoor') . '</strong>';
        foreach (array_slice($items, 0, 6) as $item) {
            $action = $item['ready'] ? __('ثبت پایان', 'hamnoor') : ($item['started'] ? __('ادامه', 'hamnoor') : __('شروع', 'hamnoor'));
            echo '<a href="' . esc_url($item['url']) . '"><span>' . esc_html($item['part_title']) . '<small>' . esc_html($item['khatm_title']) . '</small></span><b>' . esc_html($action . ' · ' . $item['percent'] . '٪') . '</b></a>';
        }
        if ($count > 6) { echo '<a class="hamnoor-floating-all" href="' . esc_url(add_query_arg('tab','my-parts',Hamnoor_Helpers::page_url('dashboard'))) . '">' . esc_html__('مشاهده همه سهم‌ها', 'hamnoor') . '</a>'; }
        echo '</div></div>';
    }

    public static function mark_progress_completed($part_id) {
        global $wpdb;
        $progress = self::get_progress((int) $part_id);
        if (!$progress) { return; }
        $now = current_time('mysql');
        $wpdb->update(Hamnoor_DB::table('reading_progress'), array(
            'last_global_index' => (int) $progress->end_global_index,
            'reading_status' => self::STATUS_COMPLETED,
            'last_read_at' => $now,
            'completed_at' => $now,
            'updated_at' => $now,
        ), array('part_id' => (int) $part_id), array('%d','%s','%s','%s','%s'), array('%d'));
    }

    public static function sync_completed_part($part_id, $participant_id, $khatm_id, $context) {
        $part = Hamnoor_DB::get_part((int) $part_id);
        if (!$part || !self::available()) { return; }
        $progress = self::ensure_progress($part);
        if (!is_wp_error($progress)) { self::mark_progress_completed((int) $part_id); }
    }

    public static function remove_released_progress($part_id, $khatm_id, $participant_id, $reason, $user_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('reading_progress');
        if ($table) { $wpdb->delete($table, array('part_id' => (int) $part_id), array('%d')); }
    }
}
