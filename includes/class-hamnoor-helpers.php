<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Helpers {
    public static function defaults() {
        return array(
            'new_khatm_creation_enabled' => '1',
            'rest_api_enabled' => '1',
            'index_public_khatms' => '1',
            'participant_name_display' => 'first',
            'cover_max_size_kb' => '512',
            'cover_required_mode' => 'optional',
            'default_khatm_image_url' => '',
            'allow_external_cover_url' => '0',
            'delete_data_on_uninstall' => '0',
            'hide_admin_bar' => '0',
            'owner_export_mode' => 'limited',
            'allow_owner_contact_export' => '0',
            'allow_private_share' => '0',
            'khatm_render_mode' => 'plugin',
            'visual_mode' => 'theme_aware',
            'dashboard_my_parts_per_page' => '10',
            'dashboard_my_khatms_per_page' => '10',
            'admin_khatms_per_page' => '20',
            'admin_parts_per_page' => '30',
            'admin_participants_per_page' => '30',
            'max_khatm_count' => '100',
            'max_repeat_count' => '1000',
            'max_parts_per_khatm' => '12000',
            'support_section_enabled' => '0',
            'support_section_title' => __('حمایت داوطلبانه از هم‌نور', 'hamnoor'),
            'support_section_description' => __('در صورت تمایل می‌توانید برای توسعه، نگهداری و استمرار خدمات هم‌نور از این سامانه حمایت کنید. ایجاد پویش کاملاً رایگان است و به پرداخت وابسته نیست.', 'hamnoor'),
            'support_payment_info' => '',
            'support_button_text' => __('حمایت از هم‌نور', 'hamnoor'),
            'support_button_url' => '',
            'repeat_contact_enabled' => '0',
            'repeat_contact_button_text' => __('ارتباط با مدیر سایت', 'hamnoor'),
            'repeat_contact_url' => '',
            'repeat_contact_help' => __('برای درخواست تعداد بیشتر تکرار سوره، با مدیر سایت در ارتباط باشید.', 'hamnoor'),
            'help_page_id' => '0',
            'help_custom_url' => '',
            'share_text_template' => "📖 {KHATM_TITLE}

🕊 نیت پویش: {KHATM_INTENTION}

✨ برای مشارکت در این پویش ختم قرآن کریم، از لینک زیر وارد شوید:

🔗 {KHATM_URL}",
            'primary_color' => '#14407F',
            'secondary_color' => '#1F63C3',
            'accent_color' => '#D7A642',
            'background_color' => '#F6F8FC',
            'card_background' => '#FFFFFF',
            'text_color' => '#1F2937',
            'muted_color' => '#667085',
            'border_color' => '#E5E7EB',
            'success_color' => '#16A34A',
            'warning_color' => '#D97706',
            'danger_color' => '#DC2626',
            'border_radius' => '20',
            'max_width' => '1120',
            'parts_min_width' => '170',
            'reading_arabic_size' => '38',
            'reading_translation_size' => '18',
            'font_family' => 'inherit',
            'button_style' => 'solid',
            'card_shadow' => 'soft',
            'ajax_debug_admin' => '0',
            // Release-warning policy is intentionally global. It controls when
            // Core creates the warning notification; channel-specific throttling
            // remains the responsibility of the Notifications suite.
            'release_warning_mode' => 'smart',
            'release_warning_fixed_hours' => '24',
            'release_warning_batch_limit' => '100',
            // Claim-abuse protection. Ordinary participation remains OTP-free;
            // these limits only constrain excessive simultaneous or burst claims.
            'default_active_claim_limit' => '3',
            // Temporal quota is implemented by the structured claim-event ledger.
            // Product policy: global rolling quota is enabled by default for shared campaigns.
            'temporal_claim_quota_enabled' => '1',
            'temporal_claim_limit' => '3',
            'temporal_claim_window_hours' => '24',
            'trusted_device_days' => '180',
            'recent_step_up_minutes' => '10',
            'participant_suspicious_claim_limit' => '8',
            'claim_abuse_ip_limit' => '30',
            'claim_abuse_window_minutes' => '10',
            // Owner lifecycle permissions are global switches controlled by the site admin.
            // Both are enabled by default; disabling them removes the controls from owner dashboards
            // and is also enforced server-side.
            'owner_pause_enabled' => '1',
            'owner_cancel_enabled' => '1',
        );
    }

    public static function settings() {
        $settings = get_option('hamnoor_settings', array());
        return wp_parse_args(is_array($settings) ? $settings : array(), self::defaults());
    }

    /**
     * Return a cache-busting asset version without changing the public plugin version.
     *
     * Hamnoor intentionally keeps the public release number stable during the current
     * design/testing phase, so using HAMNOOR_VERSION alone can leave browsers/CDNs
     * serving an older JS/CSS file after an in-place update.
     */
    public static function asset_version($relative_path) {
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        $path = trailingslashit(HAMNOOR_PATH) . $relative_path;
        $mtime = is_file($path) ? @filemtime($path) : false;
        return HAMNOOR_VERSION . ($mtime ? '.' . (string) $mtime : '');
    }

    public static function normalize_repeat_limit($value) {
        $value = Hamnoor_Validator::normalize_digits(trim((string) $value));
        if (!preg_match('/^\d+$/', $value)) { return 0; }
        $value = (int) $value;
        return $value >= 1 && $value <= 12000 ? $value : 0;
    }

    public static function effective_max_repeat_count($override = null) {
        if (is_object($override)) {
            $override = isset($override->max_repeat_count) ? $override->max_repeat_count : null;
        }
        $override = self::normalize_repeat_limit($override);
        if ($override) { return $override; }

        $settings = self::settings();
        $global = self::normalize_repeat_limit(isset($settings['max_repeat_count']) ? $settings['max_repeat_count'] : '');
        if ($global) { return $global; }

        return (int) self::defaults()['max_repeat_count'];
    }

    public static function update_settings($data) {
        $old = self::settings();
        $settings = array();
        $settings['default_khatm_image_url'] = isset($data['default_khatm_image_url']) ? esc_url_raw(wp_unslash($data['default_khatm_image_url'])) : (isset($old['default_khatm_image_url']) ? $old['default_khatm_image_url'] : '');
        foreach (array('rest_api_enabled','index_public_khatms','allow_external_cover_url','delete_data_on_uninstall','allow_private_share','hide_admin_bar','ajax_debug_admin','new_khatm_creation_enabled','support_section_enabled','repeat_contact_enabled','owner_pause_enabled','owner_cancel_enabled','temporal_claim_quota_enabled') as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = !empty($data[$key]) ? '1' : '0';
            } else {
                $settings[$key] = isset($old[$key]) ? $old[$key] : (isset(self::defaults()[$key]) ? self::defaults()[$key] : '0');
            }
        }
        $settings['owner_export_mode'] = in_array(isset($data['owner_export_mode']) ? sanitize_key($data['owner_export_mode']) : '', array('disabled','limited','full'), true) ? sanitize_key($data['owner_export_mode']) : (isset($old['owner_export_mode']) ? $old['owner_export_mode'] : 'limited');
        $settings['allow_owner_contact_export'] = 'full' === $settings['owner_export_mode'] ? '1' : '0';
        $settings['khatm_render_mode'] = in_array(isset($data['khatm_render_mode']) ? sanitize_key($data['khatm_render_mode']) : '', array('theme','plugin'), true) ? sanitize_key($data['khatm_render_mode']) : (isset($old['khatm_render_mode']) ? $old['khatm_render_mode'] : 'plugin');
        $visual_mode = isset($data['visual_mode']) ? sanitize_key(wp_unslash($data['visual_mode'])) : (isset($old['visual_mode']) ? $old['visual_mode'] : self::defaults()['visual_mode']);
        $settings['visual_mode'] = in_array($visual_mode, array('theme_aware','hamnoor','minimal'), true) ? $visual_mode : self::defaults()['visual_mode'];
        $settings['share_text_template'] = isset($data['share_text_template']) ? wp_kses_post(wp_unslash($data['share_text_template'])) : $old['share_text_template'];
        $participant_name_display = isset($data['participant_name_display']) ? sanitize_key(wp_unslash($data['participant_name_display'])) : (isset($old['participant_name_display']) ? $old['participant_name_display'] : self::defaults()['participant_name_display']);
        $settings['participant_name_display'] = in_array($participant_name_display, array('full','first','hidden'), true) ? $participant_name_display : self::defaults()['participant_name_display'];
        $warning_mode = isset($data['release_warning_mode']) ? sanitize_key(wp_unslash($data['release_warning_mode'])) : (isset($old['release_warning_mode']) ? sanitize_key($old['release_warning_mode']) : self::defaults()['release_warning_mode']);
        $settings['release_warning_mode'] = in_array($warning_mode, array('smart','fixed','disabled'), true) ? $warning_mode : self::defaults()['release_warning_mode'];
        $warning_hours = isset($data['release_warning_fixed_hours']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['release_warning_fixed_hours'])) : (isset($old['release_warning_fixed_hours']) ? $old['release_warning_fixed_hours'] : self::defaults()['release_warning_fixed_hours']);
        $settings['release_warning_fixed_hours'] = (string) max(1, min(720, (int)$warning_hours));
        $warning_batch = isset($data['release_warning_batch_limit']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['release_warning_batch_limit'])) : (isset($old['release_warning_batch_limit']) ? $old['release_warning_batch_limit'] : self::defaults()['release_warning_batch_limit']);
        $settings['release_warning_batch_limit'] = (string) max(1, min(500, (int)$warning_batch));
        $active_claim_limit = isset($data['default_active_claim_limit']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['default_active_claim_limit'])) : (isset($old['default_active_claim_limit']) ? $old['default_active_claim_limit'] : self::defaults()['default_active_claim_limit']);
        $settings['default_active_claim_limit'] = (string) max(1, min(50, (int)$active_claim_limit));
        $temporal_limit = isset($data['temporal_claim_limit']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['temporal_claim_limit'])) : ($old['temporal_claim_limit'] ?? self::defaults()['temporal_claim_limit']);
        $settings['temporal_claim_limit'] = (string) max(1, min(1000, (int)$temporal_limit));
        $temporal_hours = isset($data['temporal_claim_window_hours']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['temporal_claim_window_hours'])) : ($old['temporal_claim_window_hours'] ?? self::defaults()['temporal_claim_window_hours']);
        $settings['temporal_claim_window_hours'] = (string) max(1, min(720, (int)$temporal_hours));
        $trusted_days = isset($data['trusted_device_days']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['trusted_device_days'])) : ($old['trusted_device_days'] ?? self::defaults()['trusted_device_days']);
        $settings['trusted_device_days'] = (string) max(1, min(3650, (int)$trusted_days));
        $step_up_minutes = isset($data['recent_step_up_minutes']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['recent_step_up_minutes'])) : ($old['recent_step_up_minutes'] ?? self::defaults()['recent_step_up_minutes']);
        $settings['recent_step_up_minutes'] = (string) max(1, min(120, (int)$step_up_minutes));
        $suspicious_claim_limit = isset($data['participant_suspicious_claim_limit']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['participant_suspicious_claim_limit'])) : (isset($old['participant_suspicious_claim_limit']) ? $old['participant_suspicious_claim_limit'] : self::defaults()['participant_suspicious_claim_limit']);
        $settings['participant_suspicious_claim_limit'] = (string) max(3, min(100, (int)$suspicious_claim_limit));
        $ip_claim_limit = isset($data['claim_abuse_ip_limit']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['claim_abuse_ip_limit'])) : (isset($old['claim_abuse_ip_limit']) ? $old['claim_abuse_ip_limit'] : self::defaults()['claim_abuse_ip_limit']);
        $settings['claim_abuse_ip_limit'] = (string) max(10, min(500, (int)$ip_claim_limit));
        $claim_window = isset($data['claim_abuse_window_minutes']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['claim_abuse_window_minutes'])) : (isset($old['claim_abuse_window_minutes']) ? $old['claim_abuse_window_minutes'] : self::defaults()['claim_abuse_window_minutes']);
        $settings['claim_abuse_window_minutes'] = (string) max(1, min(120, (int)$claim_window));
        $settings['cover_max_size_kb'] = max(100, min(4096, (int) (isset($data['cover_max_size_kb']) ? Hamnoor_Validator::normalize_digits($data['cover_max_size_kb']) : $old['cover_max_size_kb'])));
        $settings['cover_required_mode'] = in_array(isset($data['cover_required_mode']) ? sanitize_key($data['cover_required_mode']) : '', array('optional','public','always'), true) ? sanitize_key($data['cover_required_mode']) : (isset($old['cover_required_mode']) ? $old['cover_required_mode'] : self::defaults()['cover_required_mode']);
        foreach (array(
            'dashboard_my_parts_per_page' => array(5, 200),
            'dashboard_my_khatms_per_page' => array(5, 200),
            'admin_khatms_per_page' => array(5, 200),
            'admin_parts_per_page' => array(5, 200),
            'admin_participants_per_page' => array(5, 200),
        ) as $key => $range) {
            $value = isset($data[$key]) ? Hamnoor_Validator::normalize_digits(wp_unslash($data[$key])) : (isset($old[$key]) ? $old[$key] : self::defaults()[$key]);
            $settings[$key] = (string) max((int) $range[0], min((int) $range[1], (int) $value));
        }
        $arabic_size = isset($data['reading_arabic_size']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['reading_arabic_size'])) : (isset($old['reading_arabic_size']) ? $old['reading_arabic_size'] : self::defaults()['reading_arabic_size']);
        $translation_size = isset($data['reading_translation_size']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['reading_translation_size'])) : (isset($old['reading_translation_size']) ? $old['reading_translation_size'] : self::defaults()['reading_translation_size']);
        $settings['reading_arabic_size'] = (string) max(28, min(56, (int)$arabic_size));
        $settings['reading_translation_size'] = (string) max(14, min(28, (int)$translation_size));
        $max_count = isset($data['max_khatm_count']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['max_khatm_count'])) : (isset($old['max_khatm_count']) ? $old['max_khatm_count'] : self::defaults()['max_khatm_count']);
        $settings['max_khatm_count'] = (string) max(1, min(1000, (int) $max_count));
        $max_repeat = isset($data['max_repeat_count']) ? self::normalize_repeat_limit(wp_unslash($data['max_repeat_count'])) : self::effective_max_repeat_count();
        $settings['max_repeat_count'] = (string) ($max_repeat ? $max_repeat : self::effective_max_repeat_count());
        $max_parts = isset($data['max_parts_per_khatm']) ? Hamnoor_Validator::normalize_digits(wp_unslash($data['max_parts_per_khatm'])) : (isset($old['max_parts_per_khatm']) ? $old['max_parts_per_khatm'] : self::defaults()['max_parts_per_khatm']);
        $settings['max_parts_per_khatm'] = (string) max(604, min(200000, (int) $max_parts));
        foreach (array('support_section_description','support_payment_info') as $key) {
            $settings[$key] = isset($data[$key]) ? wp_kses_post(wp_unslash($data[$key])) : (isset($old[$key]) ? $old[$key] : self::defaults()[$key]);
        }
        foreach (array('support_section_title','support_button_text','repeat_contact_button_text','repeat_contact_help') as $key) {
            $settings[$key] = isset($data[$key]) ? sanitize_text_field(wp_unslash($data[$key])) : (isset($old[$key]) ? $old[$key] : self::defaults()[$key]);
        }
        foreach (array('support_button_url','repeat_contact_url','help_custom_url') as $key) {
            $settings[$key] = isset($data[$key]) ? esc_url_raw(wp_unslash($data[$key])) : (isset($old[$key]) ? $old[$key] : self::defaults()[$key]);
        }
        $help_page_id = isset($data['help_page_id']) ? absint($data['help_page_id']) : absint($old['help_page_id'] ?? 0);
        $settings['help_page_id'] = $help_page_id && 'publish' === get_post_status($help_page_id) ? (string)$help_page_id : '0';
        foreach (array('primary_color','secondary_color','accent_color','background_color','card_background','text_color','muted_color','border_color','success_color','warning_color','danger_color') as $key) {
            $color = isset($data[$key]) ? sanitize_hex_color(wp_unslash($data[$key])) : $old[$key];
            $settings[$key] = $color ? $color : self::defaults()[$key];
        }
        $settings['border_radius'] = max(0, min(40, (int) (isset($data['border_radius']) ? Hamnoor_Validator::normalize_digits($data['border_radius']) : $old['border_radius'])));
        $settings['max_width'] = max(720, min(1600, (int) (isset($data['max_width']) ? Hamnoor_Validator::normalize_digits($data['max_width']) : $old['max_width'])));
        $settings['parts_min_width'] = max(130, min(320, (int) (isset($data['parts_min_width']) ? Hamnoor_Validator::normalize_digits($data['parts_min_width']) : $old['parts_min_width'])));
        $settings['font_family'] = isset($data['font_family']) ? sanitize_text_field(wp_unslash($data['font_family'])) : $old['font_family'];
        $button_style = isset($data['button_style']) ? sanitize_key(wp_unslash($data['button_style'])) : (isset($old['button_style']) ? $old['button_style'] : self::defaults()['button_style']);
        $settings['button_style'] = in_array($button_style, array('solid','soft','outline'), true) ? $button_style : self::defaults()['button_style'];
        $card_shadow = isset($data['card_shadow']) ? sanitize_key(wp_unslash($data['card_shadow'])) : (isset($old['card_shadow']) ? $old['card_shadow'] : self::defaults()['card_shadow']);
        $settings['card_shadow'] = in_array($card_shadow, array('none','soft','deep'), true) ? $card_shadow : self::defaults()['card_shadow'];
        update_option('hamnoor_settings', $settings, false);
        update_option('hamnoor_rewrite_version', '', false);
        return $settings;
    }

    public static function dynamic_css() {
        $s = self::settings();
        $shadow = 'none';
        if ('soft' === $s['card_shadow']) { $shadow = '0 10px 30px rgba(15,23,42,.08)'; }
        elseif ('deep' === $s['card_shadow']) { $shadow = '0 18px 50px rgba(15,23,42,.14)'; }
        return '.hamnoor-wrap,.hamnoor-modal{--hamnoor-config-primary:' . esc_attr($s['primary_color']) . ';--hamnoor-config-secondary:' . esc_attr($s['secondary_color']) . ';--hamnoor-config-accent:' . esc_attr($s['accent_color']) . ';--hamnoor-config-bg:' . esc_attr($s['background_color']) . ';--hamnoor-config-card:' . esc_attr($s['card_background']) . ';--hamnoor-config-text:' . esc_attr($s['text_color']) . ';--hamnoor-config-muted:' . esc_attr($s['muted_color']) . ';--hamnoor-config-border:' . esc_attr($s['border_color']) . ';--hamnoor-config-success:' . esc_attr($s['success_color']) . ';--hamnoor-config-warning:' . esc_attr($s['warning_color']) . ';--hamnoor-config-danger:' . esc_attr($s['danger_color']) . ';--hamnoor-config-radius:' . (int) $s['border_radius'] . 'px;--hamnoor-config-max:' . (int) $s['max_width'] . 'px;--hamnoor-part-min:' . (int) $s['parts_min_width'] . 'px;--hamnoor-config-font:' . esc_attr($s['font_family']) . ';--hamnoor-config-shadow:' . esc_attr($shadow) . ';}';
    }

    public static function visual_mode() {
        $settings = self::settings();
        $mode = isset($settings['visual_mode']) ? sanitize_key($settings['visual_mode']) : self::defaults()['visual_mode'];
        $mode = sanitize_key(apply_filters('hamnoor_visual_mode', $mode, $settings));
        return in_array($mode, array('theme_aware','hamnoor','minimal'), true) ? $mode : self::defaults()['visual_mode'];
    }

    public static function visual_mode_class() {
        return 'hamnoor-mode-' . str_replace('_', '-', self::visual_mode());
    }

    public static function help_url() {
        $settings = self::settings();
        $page_id = absint($settings['help_page_id'] ?? 0);
        if ($page_id && 'publish' === get_post_status($page_id)) {
            $url = get_permalink($page_id);
            if ($url) { return $url; }
        }
        $custom = esc_url_raw((string)($settings['help_custom_url'] ?? ''));
        if ($custom) { return $custom; }
        $dashboard = self::page_url('dashboard');
        return $dashboard ? add_query_arg('tab', 'help', $dashboard) : home_url('/');
    }

    public static function default_pages($auto_only = false) {
        $pages = array(
            'list' => array('title'=>'پویش‌های قرآن','slug'=>'khatm-list','shortcode'=>'[hamnoor_khatm_list]','auto'=>true),
            'dashboard' => array('title'=>'داشبورد هم‌نور','slug'=>'hamnoor-dashboard','shortcode'=>'[hamnoor_dashboard]','auto'=>true),
            'create' => array('title'=>'ایجاد پویش جدید','slug'=>'create-khatm','shortcode'=>'[hamnoor_create_khatm]','auto'=>true),
        );
        return $auto_only ? array_filter($pages, function($p){ return !empty($p['auto']); }) : $pages;
    }

    public static function page_url($key) {
        $key = sanitize_key($key);
        if (in_array($key, array('account','login','register','track'), true)) { $key = 'dashboard'; }
        $pages = get_option('hamnoor_pages', array());
        $id = is_array($pages) && !empty($pages[$key]) ? (int) $pages[$key] : 0;
        if ($id && 'publish' === get_post_status($id)) { return get_permalink($id); }
        $defs = self::default_pages();
        if (isset($defs[$key])) { $page = get_page_by_path($defs[$key]['slug']); if ($page) { return get_permalink($page); } }
        return home_url('/');
    }


    public static function sanitize_token($token) {
        $token = strtolower(trim((string) $token));
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        return substr($token, 0, 5);
    }

    public static function is_public_token($token) {
        $token = strtolower(trim((string) $token));
        return (bool) preg_match('/^[a-z0-9]{5}$/', $token);
    }

    public static function sanitize_tracking_token($token) {
        $token = strtolower(trim((string) $token));
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        return substr($token, 0, 64);
    }

    public static function safe_redirect_url($url, $fallback = '') {
        $fallback = $fallback ? $fallback : home_url('/');
        $url = esc_url_raw((string) $url);
        return wp_validate_redirect($url, $fallback);
    }

    public static function khatm_url($khatm) {
        return self::invite_url($khatm);
    }

    /**
     * URL used only for share/invite surfaces. The canonical public campaign
     * URL remains untouched; optional local add-ons (for example Referral) may
     * append attribution parameters to this share-only URL.
     */
    public static function share_invite_url($khatm, $mode = 'web') {
        $url = self::invite_url($khatm);
        return (string) apply_filters('hamnoor_share_invite_url', $url, $khatm, sanitize_key((string) $mode));
    }

    /**
     * نشانی عمومی پویش مستقیماً از ریشه دامنه و با کد پنج‌کاراکتری ساخته می‌شود.
     * نمونه: https://example.com/a7k2m/
     */
    public static function invite_url($khatm) {
        $token = is_object($khatm) ? (string) $khatm->token : (string) $khatm;
        $token = self::sanitize_token($token);
        return home_url(user_trailingslashit($token));
    }

    public static function khatm_has_indexable_content($khatm) {
        if (!$khatm) { return false; }
        if ('public' !== (string) $khatm->privacy || 'active' !== (string) $khatm->status || empty($khatm->indexable)) { return false; }
        $text = trim(wp_strip_all_tags((string) $khatm->description . ' ' . (string) $khatm->intention));
        $word_count = str_word_count(str_replace(array('،','؛','.',':','/','-'), ' ', $text));
        return Hamnoor_Validator::text_length($text) >= 40 || $word_count >= 8;
    }

    public static function part_access_url($khatm, $part_id, $tracking_token) {
        return add_query_arg(array('part'=>(int)$part_id,'access'=>rawurlencode($tracking_token)), self::khatm_url($khatm));
    }

    public static function redirect_with_notice($url, $message, $type = 'success') {
        $key = 'hamnoor_notice_' . wp_generate_password(12, false, false);
        set_transient($key, array('message'=>wp_kses_post($message),'type'=>sanitize_key($type)), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('hamnoor_notice', rawurlencode($key), $url)); exit;
    }

    public static function redirect_with_form($url, $message, $type, $form, $data, $errors = array()) {
        $key = 'hamnoor_form_' . wp_generate_password(12, false, false);
        set_transient($key, array('form'=>sanitize_key($form),'data'=>is_array($data)?$data:array(),'errors'=>is_array($errors)?$errors:array()), 15 * MINUTE_IN_SECONDS);
        $notice_key = 'hamnoor_notice_' . wp_generate_password(12, false, false);
        set_transient($notice_key, array('message'=>wp_kses_post($message),'type'=>sanitize_key($type)), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('hamnoor_form'=>rawurlencode($key),'hamnoor_notice'=>rawurlencode($notice_key)), $url)); exit;
    }

    public static function get_form_state($form) {
        if (empty($_GET['hamnoor_form'])) { return array('data'=>array(),'errors'=>array()); }
        $key = sanitize_key(wp_unslash($_GET['hamnoor_form']));
        $state = get_transient($key); delete_transient($key);
        if (!is_array($state) || empty($state['form']) || $state['form'] !== $form) { return array('data'=>array(),'errors'=>array()); }
        return array('data'=>isset($state['data']) && is_array($state['data']) ? $state['data'] : array(), 'errors'=>isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : array());
    }

    public static function current_notice() {
        if (empty($_GET['hamnoor_notice'])) { return ''; }
        $key = sanitize_key(wp_unslash($_GET['hamnoor_notice']));
        $notice = get_transient($key); delete_transient($key);
        if (!is_array($notice) || empty($notice['message'])) { return ''; }
        $type = !empty($notice['type']) ? sanitize_key($notice['type']) : 'info';
        if (!in_array($type, array('success','error','warning','info'), true)) { $type = 'info'; }
        $role = 'error' === $type ? 'alert' : 'status';
        return '<div class="notice notice-' . esc_attr($type) . ' is-dismissible hamnoor-notice hamnoor-notice-' . esc_attr($type) . '" role="' . esc_attr($role) . '" aria-live="' . ('error' === $type ? 'assertive' : 'polite') . '"><p>' . wp_kses($notice['message'], array('a'=>array('href'=>array(),'class'=>array(),'target'=>array(),'rel'=>array()), 'strong'=>array(), 'br'=>array())) . '</p></div>';
    }

    public static function share_title($khatm) {
        $title = is_object($khatm) && isset($khatm->title) ? (string) $khatm->title : '';
        $title = sanitize_text_field(wp_strip_all_tags($title));
        $title = preg_replace('~(?:https?://|www\.)[^\s<>]+~iu', '', $title);
        return Hamnoor_Validator::trim_len(trim((string) $title), 191);
    }

    public static function share_text($khatm, $progress = null, $mode = 'clipboard') {
        $settings = self::settings();
        $progress = is_array($progress) ? $progress : Hamnoor_DB::get_progress((int) $khatm->id);
        $template = isset($settings['share_text_template']) && '' !== trim((string) $settings['share_text_template']) ? (string) $settings['share_text_template'] : Hamnoor_Texts::get('share_text_template');
        $title = self::share_title($khatm);
        $intention = preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $khatm->intention));
        $intention = Hamnoor_Validator::trim_len(trim((string) $intention), 240);
        $url = self::share_invite_url($khatm, $mode);
        $url_marker = 'HAMNOOR_SHARE_URL_MARKER';
        $replacements = array(
            '{KHATM_TITLE}' => $title,
            '{KHATM_INTENTION}' => $intention,
            '{KHATM_URL}' => $url_marker,
            '{AVAILABLE_PARTS}' => isset($progress['available']) ? (string) $progress['available'] : '0',
            '{DONE_PARTS}' => isset($progress['done']) ? (string) $progress['done'] : '0',
            '{TOTAL_PARTS}' => isset($progress['total']) ? (string) $progress['total'] : '0',
            '{PROGRESS_PERCENT}' => isset($progress['percent']) ? (string) $progress['percent'] : '0',
        );
        $text = html_entity_decode(wp_strip_all_tags(strtr($template, $replacements)), ENT_QUOTES, get_bloginfo('charset'));
        $text = preg_replace('~(?:https?://|www\.)[^\s<>]+~iu', '', $text);
        $text = str_replace($url_marker, '', $text);
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $lines = array_values(array_filter(array_map('trim', $lines), function($line) {
            if ('' === $line) { return false; }
            if (preg_match('/^(?:🔗|📖)\s*$/u', $line)) { return false; }
            if (preg_match('/^🕊\s*نیت پویش:\s*$/u', $line)) { return false; }
            return !preg_match('/^🔗?\s*(?:لینک|پیوند)(?:\s+(?:پویش|شرکت در پویش|دعوت))?\s*[:：]?$/u', $line);
        }));
        $text = trim(implode("\n\n", $lines));
        if ('' === $text) {
            $text = $title ? '📖 ' . $title : __('📖 دعوت به مشارکت در پویش ختم قرآن کریم', 'hamnoor');
            if ($intention) { $text .= "\n\n🕊 " . sprintf(__('نیت پویش: %s', 'hamnoor'), $intention); }
            $text .= "\n\n" . __('✨ شما می‌توانید با انتخاب یک سهم، در این پویش نورانی مشارکت کنید.', 'hamnoor');
        }
        if ('web' === sanitize_key($mode)) { return $text; }
        return $text . "\n\n" . __('🔗 لینک پویش:', 'hamnoor') . "\n" . $url;
    }

    public static function csv_safe($value) { $value = (string) $value; return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value; }

    /** Mask a participant mobile for ordinary creator/public views. */
    public static function mask_phone($phone) {
        $phone = Hamnoor_Validator::normalize_phone((string) $phone);
        if (strlen($phone) < 7) { return $phone ? str_repeat('•', max(3, strlen($phone))) : ''; }
        return substr($phone, 0, 4) . '•••••' . substr($phone, -2);
    }

    public static function upload_cover($field) {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) { return array(); }
        if (!function_exists('wp_handle_upload')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        if (!function_exists('wp_check_filetype_and_ext')) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
        $settings = self::settings(); $max = (int) $settings['cover_max_size_kb'] * 1024;
        if (!empty($_FILES[$field]['size']) && (int) $_FILES[$field]['size'] > $max) { return new WP_Error('hamnoor_cover_size', sprintf(__('حجم تصویر پویش بیش از حد مجاز است. لطفاً تصویری کمتر از %d کیلوبایت انتخاب کنید. سایر اطلاعات فرم حفظ شده است، اما فایل تصویر باید دوباره انتخاب شود.', 'hamnoor'), (int)$settings['cover_max_size_kb'])); }
        $allowed = array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif');
        $check = wp_check_filetype_and_ext($_FILES[$field]['tmp_name'], $_FILES[$field]['name'], $allowed);
        if (empty($check['ext']) || empty($check['type'])) { return new WP_Error('hamnoor_cover_type', __('فرمت تصویر معتبر نیست. فقط JPG، PNG، WEBP و GIF پذیرفته می‌شود.', 'hamnoor')); }
        $uploaded = wp_handle_upload($_FILES[$field], array('test_form'=>false, 'mimes'=>$allowed));
        if (!empty($uploaded['error'])) { return new WP_Error('hamnoor_cover_upload', sanitize_text_field($uploaded['error'])); }
        $attachment_id = 0;
        if (!empty($uploaded['file'])) {
            $attachment_id = wp_insert_attachment(array('post_mime_type'=>$uploaded['type'], 'post_title'=>sanitize_file_name(basename($uploaded['file'])), 'post_content'=>'', 'post_status'=>'inherit'), $uploaded['file']);
            if (!is_wp_error($attachment_id) && $attachment_id) {
                if (!function_exists('wp_generate_attachment_metadata')) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
                $metadata = wp_generate_attachment_metadata((int) $attachment_id, $uploaded['file']);
                if (!is_wp_error($metadata) && is_array($metadata)) {
                    wp_update_attachment_metadata((int) $attachment_id, $metadata);
                }
            }
        }
        return array('cover_id'=>(int)$attachment_id, 'cover_url'=>esc_url_raw($uploaded['url']));
    }


    public static function rate_limit_key($scope, $identity = '') {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $identity = $identity ? sanitize_text_field((string) $identity) : 'public';
        return 'hamnoor_rl_' . md5(sanitize_key($scope) . '|' . $ip . '|' . strtolower($identity));
    }

    public static function rate_limit($scope, $identity = '', $limit = 5, $window = 300) {
        $key = self::rate_limit_key($scope, $identity);
        $count = (int) get_transient($key);
        if ($count >= (int) $limit) {
            return new WP_Error('hamnoor_rate_limited', __('تعداد درخواست‌ها زیاد است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.', 'hamnoor'));
        }
        set_transient($key, $count + 1, (int) $window);
        return true;
    }

    public static function noindex_shortcode_tags() {
        return array('hamnoor_dashboard');
    }

    public static function mask_contact($value) {
        $value = (string) $value;
        $phone = Hamnoor_Validator::normalize_phone($value);
        if ($phone && strlen($phone) >= 7) {
            return substr($phone, 0, 4) . '***' . substr($phone, -2);
        }
        return $value ? '***' : '';
    }


    public static function locate_template($template) {
        $template = ltrim((string) $template, '/');
        $theme_template = locate_template(array('hamnoor/' . $template, $template));
        $plugin_template = HAMNOOR_PATH . 'templates/' . $template;
        $located = $theme_template ? $theme_template : (file_exists($plugin_template) ? $plugin_template : '');
        $located = apply_filters('hamnoor_template_path', $located, $template);
        return is_string($located) && file_exists($located) ? $located : '';
    }

    public static function surahs() {
        static $surahs = null; if (null !== $surahs) { return $surahs; }
        $names_fa = array('فاتحه','بقره','آل عمران','نساء','مائده','انعام','اعراف','انفال','توبه','یونس','هود','یوسف','رعد','ابراهیم','حجر','نحل','اسراء','کهف','مریم','طه','انبیاء','حج','مؤمنون','نور','فرقان','شعراء','نمل','قصص','عنکبوت','روم','لقمان','سجده','احزاب','سبأ','فاطر','یاسین','صافات','صاد','زمر','غافر','فصلت','شوری','زخرف','دخان','جاثیه','احقاف','محمد','فتح','حجرات','قاف','ذاریات','طور','نجم','قمر','الرحمن','واقعه','حدید','مجادله','حشر','ممتحنه','صف','جمعه','منافقون','تغابن','طلاق','تحریم','ملک','قلم','حاقه','معارج','نوح','جن','مزمل','مدثر','قیامت','انسان','مرسلات','نبأ','نازعات','عبس','تکویر','انفطار','مطففین','انشقاق','بروج','طارق','اعلی','غاشیه','فجر','بلد','شمس','لیل','ضحی','شرح','تین','علق','قدر','بینه','زلزال','عادیات','قارعه','تکاثر','عصر','همزه','فیل','قریش','ماعون','کوثر','کافرون','نصر','مسد','اخلاص','فلق','ناس');
        $names_ar = array('الفاتحة','البقرة','آل عمران','النساء','المائدة','الأنعام','الأعراف','الأنفال','التوبة','يونس','هود','يوسف','الرعد','إبراهيم','الحجر','النحل','الإسراء','الكهف','مريم','طه','الأنبياء','الحج','المؤمنون','النور','الفرقان','الشعراء','النمل','القصص','العنكبوت','الروم','لقمان','السجدة','الأحزاب','سبأ','فاطر','يس','الصافات','ص','الزمر','غافر','فصلت','الشورى','الزخرف','الدخان','الجاثية','الأحقاف','محمد','الفتح','الحجرات','ق','الذاريات','الطور','النجم','القمر','الرحمن','الواقعة','الحديد','المجادلة','الحشر','الممتحنة','الصف','الجمعة','المنافقون','التغابن','الطلاق','التحريم','الملك','القلم','الحاقة','المعارج','نوح','الجن','المزمل','المدثر','القيامة','الإنسان','المرسلات','النبأ','النازعات','عبس','التكوير','الانفطار','المطففين','الانشقاق','البروج','الطارق','الأعلى','الغاشية','الفجر','البلد','الشمس','الليل','الضحى','الشرح','التين','العلق','القدر','البينة','الزلزلة','العاديات','القارعة','التكاثر','العصر','الهمزة','الفيل','قريش','الماعون','الكوثر','الكافرون','النصر','المسد','الإخلاص','الفلق','الناس');
        $slugs = array('al-faatiha','al-baqarah','aali-imran','an-nisaa','al-maaida','al-anaam','al-araaf','al-anfaal','at-tawba','yunus','hud','yusuf','ar-raad','ibrahim','al-hijr','an-nahl','al-israa','al-kahf','maryam','ta-ha','al-anbiyaa','al-hajj','al-muminoon','an-noor','al-furqaan','ash-shuaraa','an-naml','al-qasas','al-ankaboot','ar-room','luqman','as-sajda','al-ahzaab','saba','faatir','ya-seen','as-saaffaat','sad','az-zumar','ghaafir','fussilat','ash-shura','az-zukhruf','ad-dukhaan','al-jaathiya','al-ahqaaf','muhammad','al-fath','al-hujuraat','qaf','adh-dhaariyaat','at-tur','an-najm','al-qamar','ar-rahmaan','al-waqia','al-hadid','al-mujaadila','al-hashr','al-mumtahina','as-saff','al-jumua','al-munafiqun','at-taghaabun','at-talaaq','at-tahrim','al-mulk','al-qalam','al-haaaqqa','al-maaarij','nooh','al-jinn','al-muzzammil','al-muddaththir','al-qiyaama','al-insaan','al-mursalaat','an-naba','an-naaziaat','abasa','at-takwir','al-infitar','al-mutaffifin','al-inshiqaaq','al-burooj','at-taariq','al-alaa','al-ghaashiya','al-fajr','al-balad','ash-shams','al-lail','ad-duhaa','ash-sharh','at-tin','al-alaq','al-qadr','al-bayyina','az-zalzala','al-aadiyaat','al-qaariha','at-takaathur','al-asr','al-humaza','al-fil','quraish','al-maaun','al-kawthar','al-kaafiroon','an-nasr','al-masad','al-ikhlaas','al-falaq','an-naas');
        $surahs = array();
        foreach ($names_fa as $i => $name) {
            $number = $i + 1;
            $surahs[$number] = array('number'=>$number, 'fa'=>$name, 'name_fa'=>$name, 'name_ar'=>$names_ar[$i], 'slug'=>$slugs[$i]);
        }
        return $surahs;
    }
    public static function surah($number) { $s=self::surahs(); return isset($s[(int)$number]) ? $s[(int)$number] : null; }


    public static function cover_url($khatm) {
        if (is_object($khatm) && !empty($khatm->cover_id)) {
            $attachment_url = wp_get_attachment_image_url((int) $khatm->cover_id, 'full');
            if ($attachment_url) { return esc_url_raw($attachment_url); }
        }
        if (is_object($khatm) && !empty($khatm->cover_url)) {
            return esc_url_raw($khatm->cover_url);
        }
        $settings = self::settings();
        return !empty($settings['default_khatm_image_url']) ? esc_url_raw($settings['default_khatm_image_url']) : '';
    }

    public static function share_image_data($khatm) {
        $url = self::cover_url($khatm);
        if (!$url) { return array(); }
        if (0 === strpos($url, '//')) { $url = (is_ssl() ? 'https:' : 'http:') . $url; }
        elseif (!preg_match('#^https?://#i', $url)) { $url = home_url('/' . ltrim($url, '/')); }
        $url = esc_url_raw($url);
        if (!$url) { return array(); }
        $attachment_id = is_object($khatm) && !empty($khatm->cover_id) ? (int) $khatm->cover_id : 0;
        if (!$attachment_id && function_exists('attachment_url_to_postid')) { $attachment_id = (int) attachment_url_to_postid($url); }
        $width = 1200;
        $height = 630;
        $mime = '';
        if ($attachment_id) {
            $image = wp_get_attachment_image_src($attachment_id, 'full');
            if (is_array($image) && !empty($image[0])) {
                $url = esc_url_raw($image[0]);
                $width = !empty($image[1]) ? (int) $image[1] : $width;
                $height = !empty($image[2]) ? (int) $image[2] : $height;
            }
            $mime = (string) get_post_mime_type($attachment_id);
        }
        return array('url'=>$url, 'width'=>$width, 'height'=>$height, 'mime'=>sanitize_mime_type($mime));
    }

    public static function display_name($name){$mode=self::settings()['participant_name_display'];$name=trim((string)$name); if('hidden'===$mode){return __('مخفی', 'hamnoor');} if('first'===$mode){$parts=preg_split('/\s+/', $name); return $parts?reset($parts):$name;} return $name;}

    public static function status_label($status, $context = 'part') {
        $status = sanitize_key($status);
        $labels = array(
            'part' => array('available'=>Hamnoor_Texts::get('badge_available'), 'claimed'=>Hamnoor_Texts::get('badge_claimed'), 'done'=>Hamnoor_Texts::get('badge_done')),
            'khatm' => array('pending_review'=>__('در انتظار بررسی مدیر', 'hamnoor'), 'needs_revision'=>__('نیازمند اصلاح', 'hamnoor'), 'rejected'=>__('تأیید نشد', 'hamnoor'), 'active'=>Hamnoor_Texts::get('badge_active'), 'paused'=>__('متوقف موقت', 'hamnoor'), 'expired'=>__('پایان پذیرش سهم جدید', 'hamnoor'), 'completed'=>Hamnoor_Texts::get('badge_completed'), 'cancelled'=>__('لغوشده', 'hamnoor'), 'archived'=>__('آرشیو', 'hamnoor'), 'failed'=>__('خطای فنی', 'hamnoor')),
            'privacy' => array('public'=>__('عمومی', 'hamnoor'), 'private'=>__('خصوصی', 'hamnoor')),
            'type' => array('page'=>__('صفحه‌ای', 'hamnoor'), 'juz'=>__('جزء‌محور', 'hamnoor'), 'surah'=>__('سوره‌ای', 'hamnoor'), 'repeated_surah'=>__('تکرار یک سوره', 'hamnoor')),
        );
        return isset($labels[$context][$status]) ? $labels[$context][$status] : $status;
    }
}
