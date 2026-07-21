<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Helpers {
    public static function defaults() {
        return array(
            'link_base' => 'h',
            'quran_page_url_pattern' => 'https://www.manahel.ir/quran-page{unit}/',
            'quran_juz_url_pattern' => 'https://www.manahel.ir/quran-juz-{unit}/',
            'quran_surah_url_pattern' => 'https://www.manahel.ir/{slug}/',
            'allow_frontend_registration' => '1',
            'creation_policy' => 'all',
            'new_khatm_creation_enabled' => '1',
            'auto_grant_manager' => '0',
            'rest_api_enabled' => '1',
            'track_requires_otp' => '0',
            'index_public_khatms' => '1',
            'participant_name_display' => 'first',
            'cover_max_size_kb' => '512',
            'cover_required_mode' => 'optional',
            'default_khatm_image_url' => '',
            'allow_external_cover_url' => '0',
            'card_show_part_type' => '1',
            'card_show_claimed_count' => '1',
            'card_show_done_count' => '1',
            'delete_data_on_uninstall' => '0',
            'auto_create_track_page' => '1',
            'auto_create_create_page' => '0',
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
            'support_section_description' => __('در صورت تمایل می‌توانید برای توسعه، نگهداری و استمرار خدمات هم‌نور از این سامانه حمایت کنید. ایجاد ختم کاملاً رایگان است و به پرداخت وابسته نیست.', 'hamnoor'),
            'support_payment_info' => '',
            'support_button_text' => __('حمایت از هم‌نور', 'hamnoor'),
            'support_button_url' => '',
            'repeat_contact_enabled' => '0',
            'repeat_contact_button_text' => __('ارتباط با مدیر سایت', 'hamnoor'),
            'repeat_contact_url' => '',
            'repeat_contact_help' => __('برای هماهنگی درباره ایجاد ختم، ظرفیت سهم‌ها یا درخواست‌های ویژه، با مدیر سایت در ارتباط باشید.', 'hamnoor'),
            'share_text_template' => "📖 {KHATM_TITLE}

🕊 نیت ختم: {KHATM_INTENTION}

✨ برای مشارکت در این ختم قرآن کریم، از لینک زیر وارد شوید:

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
            'font_family' => 'inherit',
            'button_style' => 'solid',
            'card_shadow' => 'soft',
            'ajax_debug_admin' => '0',
            'participation_guide_page_id' => 0,
            'create_khatm_guide_page_id' => 0,
        );
    }

    public static function settings() {
        $settings = get_option('hamnoor_settings', array());
        return wp_parse_args(is_array($settings) ? $settings : array(), self::defaults());
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
        $settings['link_base'] = array_key_exists('link_base', $data) ? sanitize_title(wp_unslash($data['link_base'])) : $old['link_base'];
        foreach (array('quran_page_url_pattern','quran_juz_url_pattern','quran_surah_url_pattern') as $key) {
            $settings[$key] = esc_url_raw(isset($data[$key]) ? wp_unslash($data[$key]) : $old[$key]);
        }
        if (false === strpos($settings['quran_page_url_pattern'], '{unit}')) { $settings['quran_page_url_pattern'] = self::defaults()['quran_page_url_pattern']; }
        if (false === strpos($settings['quran_juz_url_pattern'], '{unit}')) { $settings['quran_juz_url_pattern'] = self::defaults()['quran_juz_url_pattern']; }
        if (false === strpos($settings['quran_surah_url_pattern'], '{slug}')) { $settings['quran_surah_url_pattern'] = self::defaults()['quran_surah_url_pattern']; }
        $settings['default_khatm_image_url'] = isset($data['default_khatm_image_url']) ? esc_url_raw(wp_unslash($data['default_khatm_image_url'])) : (isset($old['default_khatm_image_url']) ? $old['default_khatm_image_url'] : '');
        foreach (array('allow_frontend_registration','auto_grant_manager','rest_api_enabled','track_requires_otp','index_public_khatms','allow_external_cover_url','delete_data_on_uninstall','auto_create_track_page','auto_create_create_page','allow_private_share','hide_admin_bar','ajax_debug_admin','new_khatm_creation_enabled','support_section_enabled','repeat_contact_enabled','card_show_part_type','card_show_claimed_count','card_show_done_count') as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = !empty($data[$key]) ? '1' : '0';
            } else {
                $settings[$key] = isset($old[$key]) ? $old[$key] : (isset(self::defaults()[$key]) ? self::defaults()[$key] : '0');
            }
        }
        $settings['auto_create_track_page'] = '1';
        $settings['owner_export_mode'] = in_array(isset($data['owner_export_mode']) ? sanitize_key($data['owner_export_mode']) : '', array('disabled','limited','full'), true) ? sanitize_key($data['owner_export_mode']) : (isset($old['owner_export_mode']) ? $old['owner_export_mode'] : 'limited');
        $settings['allow_owner_contact_export'] = 'full' === $settings['owner_export_mode'] ? '1' : '0';
        $settings['khatm_render_mode'] = in_array(isset($data['khatm_render_mode']) ? sanitize_key($data['khatm_render_mode']) : '', array('theme','plugin'), true) ? sanitize_key($data['khatm_render_mode']) : (isset($old['khatm_render_mode']) ? $old['khatm_render_mode'] : 'plugin');
        $visual_mode = isset($data['visual_mode']) ? sanitize_key(wp_unslash($data['visual_mode'])) : (isset($old['visual_mode']) ? $old['visual_mode'] : self::defaults()['visual_mode']);
        $settings['visual_mode'] = in_array($visual_mode, array('theme_aware','hamnoor','minimal'), true) ? $visual_mode : self::defaults()['visual_mode'];
        $settings['creation_policy'] = in_array(isset($data['creation_policy']) ? sanitize_key($data['creation_policy']) : '', array('all','approved','admins'), true) ? sanitize_key($data['creation_policy']) : $old['creation_policy'];
        $settings['share_text_template'] = isset($data['share_text_template']) ? wp_kses_post(wp_unslash($data['share_text_template'])) : $old['share_text_template'];
        $settings['participant_name_display'] = in_array(isset($data['participant_name_display']) ? sanitize_key($data['participant_name_display']) : '', array('full','first','hidden'), true) ? sanitize_key($data['participant_name_display']) : 'first';
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
        foreach (array('support_button_url','repeat_contact_url') as $key) {
            $settings[$key] = isset($data[$key]) ? esc_url_raw(wp_unslash($data[$key])) : (isset($old[$key]) ? $old[$key] : self::defaults()[$key]);
        }
        foreach (array('primary_color','secondary_color','accent_color','background_color','card_background','text_color','muted_color','border_color','success_color','warning_color','danger_color') as $key) {
            $color = isset($data[$key]) ? sanitize_hex_color(wp_unslash($data[$key])) : $old[$key];
            $settings[$key] = $color ? $color : self::defaults()[$key];
        }
        $settings['border_radius'] = max(0, min(40, (int) (isset($data['border_radius']) ? Hamnoor_Validator::normalize_digits($data['border_radius']) : $old['border_radius'])));
        $settings['max_width'] = max(720, min(1600, (int) (isset($data['max_width']) ? Hamnoor_Validator::normalize_digits($data['max_width']) : $old['max_width'])));
        $settings['parts_min_width'] = max(130, min(320, (int) (isset($data['parts_min_width']) ? Hamnoor_Validator::normalize_digits($data['parts_min_width']) : $old['parts_min_width'])));
        $settings['font_family'] = isset($data['font_family']) ? sanitize_text_field(wp_unslash($data['font_family'])) : $old['font_family'];
        $settings['button_style'] = in_array(isset($data['button_style']) ? sanitize_key($data['button_style']) : '', array('solid','soft','outline'), true) ? sanitize_key($data['button_style']) : 'solid';
        $settings['card_shadow'] = in_array(isset($data['card_shadow']) ? sanitize_key($data['card_shadow']) : '', array('none','soft','deep'), true) ? sanitize_key($data['card_shadow']) : 'soft';
        $settings['participation_guide_page_id'] = !empty($old['participation_guide_page_id']) ? (int) $old['participation_guide_page_id'] : 0;
        $settings['create_khatm_guide_page_id'] = !empty($old['create_khatm_guide_page_id']) ? (int) $old['create_khatm_guide_page_id'] : 0;
        update_option('hamnoor_settings', $settings, false);
        if (array_key_exists('creation_policy', $data)) {
            update_option('hamnoor_creation_policy_admin_set', '1', false);
        }
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

    public static function default_pages($auto_only = false) {
        $pages = array(
            'list' => array('title'=>'ختم‌های قرآن','slug'=>'khatm-list','shortcode'=>'[hamnoor_khatm_list]','auto'=>true),
            'login' => array('title'=>'ورود به هم‌نور','slug'=>'hamnoor-login','shortcode'=>'[hamnoor_login]','auto'=>true),
            'register' => array('title'=>'ثبت‌نام در هم‌نور','slug'=>'hamnoor-register','shortcode'=>'[hamnoor_register]','auto'=>true),
            'dashboard' => array('title'=>'داشبورد هم‌نور','slug'=>'hamnoor-dashboard','shortcode'=>'[hamnoor_dashboard]','auto'=>true),
            'account' => array('title'=>'حساب هم‌نور','slug'=>'hamnoor-account','shortcode'=>'[hamnoor_account]','auto'=>false),
            'create' => array('title'=>'ایجاد ختم قرآن','slug'=>'create-khatm','shortcode'=>'[hamnoor_create_khatm]','auto'=>true),
            'track' => array('title'=>'پیگیری سهم‌های ختم','slug'=>'hamnoor-track-parts','shortcode'=>'[hamnoor_track_parts]','auto'=>true),
            'participation_guide' => array('title'=>'راهنمای ثبت و شرکت در ختم','slug'=>'hamnoor-participation-guide','content'=>self::participation_guide_content(),'auto'=>true),
            'create_guide' => array('title'=>'راهنمای ایجاد ختم','slug'=>'hamnoor-create-khatm-guide','content'=>self::create_khatm_guide_content(),'auto'=>true),
        );
        $s = self::settings();
        // صفحه پیگیری سهم‌ها از نسخه 2.3.0 در نصب اولیه همیشه ساخته می‌شود.
        if ('1' === $s['auto_create_create_page']) { $pages['create']['auto'] = true; }
        return $auto_only ? array_filter($pages, function($p){ return !empty($p['auto']); }) : $pages;
    }

    private static function participation_guide_content() {
        return '<h2>۱. هم‌نور چیست؟</h2><p>هم‌نور سامانه‌ای برای برگزاری ختم جمعی قرآن است. هر ختم به سهم‌های مشخص تقسیم می‌شود تا افراد بتوانند سهمی را انتخاب، قرائت و در صورت نیاز انجام‌شدن آن را ثبت کنند.</p>'
            . '<h2>۲. چگونه وارد صفحه ختم شویم؟</h2><p>لینک دعوتی را که ایجادکننده ختم برای شما فرستاده است باز کنید. در صفحه ختم، عنوان، نیت، میزان پیشرفت، تعداد سهم‌های آزاد و فهرست سهم‌ها دیده می‌شود. اگر ختم هنوز در انتظار بررسی مدیر باشد، تا زمان انتشار امکان مشارکت عمومی وجود ندارد.</p>'
            . '<h2>۳. چگونه سهم انتخاب کنیم؟</h2><ol><li>روی «انتخاب سهم به ترتیب» یا دکمه انتخاب یک سهم آزاد بزنید.</li><li>نام و شماره موبایل معتبر را وارد کنید و در صورت تمایل ایمیل خود را نیز بنویسید.</li><li>انتخاب را تأیید کنید.</li><li>عنوان سهم و لینک «مشاهده قرآن» را برای قرائت نگه دارید.</li></ol><p>اطلاعات تماس برای پیگیری سهم استفاده می‌شود و شماره موبایل یا ایمیل کامل در کارت عمومی نمایش داده نمی‌شود.</p>'
            . '<h2>۴. تفاوت انتخاب سهم به ترتیب و انتخاب دستی چیست؟</h2><p>در انتخاب به ترتیب، سامانه نخستین سهم آزاد مناسب را برای شما ثبت می‌کند. در انتخاب دستی، خودتان از میان کارت‌های آزاد سهم دلخواه را انتخاب می‌کنید. در انواع ختمی که دکمه تصادفی دارند، آن گزینه یک سهم آزاد را بدون ترتیب مشخص انتخاب می‌کند. در ختم «تکرار یک سوره» دکمه تصادفی نمایش داده نمی‌شود، زیرا همه سهم‌ها مربوط به همان سوره هستند.</p>'
            . '<h2>۵. اگر دکمه «قرائت انجام شد» وجود داشت چه کنیم؟</h2><p>ابتدا سهم را کامل بخوانید و سپس روی «قرائت انجام شد» بزنید. بعد از ثبت، وضعیت کارت «خوانده‌شده» می‌شود و نام قرائت‌کننده مطابق تنظیم حریم خصوصی سایت روی کارت باقی می‌ماند.</p>'
            . '<h2>۶. اگر با انتخاب سهم، قرائت انجام‌شده محسوب شد یعنی چه؟</h2><p>در بعضی ختم‌ها ایجادکننده حالت ثبت خودکار را انتخاب کرده است. در این حالت، همان لحظه‌ای که سهم را انتخاب می‌کنید، سامانه آن را خوانده‌شده در نظر می‌گیرد و دکمه جداگانه «قرائت انجام شد» نمایش داده نمی‌شود. مسئولیت قرائت سهم همچنان بر عهده انتخاب‌کننده است.</p>'
            . '<h2>۷. چگونه بعداً سهم خود را پیگیری کنیم؟</h2><p>به برگه «پیگیری سهم‌های ختم» بروید و همان موبایل یا ایمیلی را وارد کنید که هنگام انتخاب سهم ثبت کرده‌اید. اگر عضو سایت و وارد حساب خود باشید، سهم‌ها در داشبورد هم‌نور نیز قابل مشاهده‌اند.</p>'
            . '<h2>۸. چگونه قرائت سهم را ثبت کنیم؟</h2><ol><li>سهم موردنظر را در صفحه پیگیری، داشبورد یا لینک اختصاصی سهم پیدا کنید.</li><li>پس از قرائت کامل، روی «قرائت انجام شد» بزنید.</li><li>پیام موفقیت و وضعیت «خوانده‌شده» را بررسی کنید.</li></ol><p>اگر ثبت انجام نشد، صفحه را تازه‌سازی و دوباره تلاش کنید یا با مدیر سایت تماس بگیرید.</p>'
            . '<h2>۹. چگونه سهم را آزاد کنیم؟</h2><p>اگر پیش از قرائت متوجه شدید نمی‌توانید سهم را انجام دهید، آن را از صفحه پیگیری یا داشبورد باز کنید و روی «آزاد کردن سهم» بزنید. آزادسازی به دیگران اجازه می‌دهد همان سهم را انتخاب کنند.</p>'
            . '<h2>۱۰. اگر سهم آزاد شد یعنی چه؟</h2><p>سهم آزاد دیگر به نام شما رزرو نیست و شخص دیگری می‌تواند آن را انتخاب کند. ممکن است شما آن را دستی آزاد کرده باشید یا موعد آزادسازی خودکار تعیین‌شده برای ختم فرا رسیده باشد. اگر هنوز مایل به مشارکت هستید، یک سهم آزاد دیگر انتخاب کنید.</p>'
            . '<h2>۱۱. نکات اخلاقی و شرعی مشارکت در ختم</h2><ul><li>سهمی را انتخاب کنید که توان و فرصت قرائت آن را دارید.</li><li>قرائت را با نیت اعلام‌شده ختم و با دقت انجام دهید.</li><li>تا پیش از قرائت کامل، دکمه «قرائت انجام شد» را نزنید.</li><li>اگر قادر به انجام سهم نیستید، آن را زودتر آزاد کنید تا ختم معطل نماند.</li><li>در مسائل شرعی ویژه، به نظر مرجع تقلید خود مراجعه کنید.</li></ul>'
            . '<h2>۱۲. پرسش‌های متداول</h2><p><strong>آیا برای شرکت باید عضو سایت باشم؟</strong></p><p>خیر؛ اگر سایت محدودیت دیگری تعیین نکرده باشد، با نام و اطلاعات تماس معتبر می‌توانید سهم انتخاب کنید.</p><p><strong>چرا نام من کامل دیده نمی‌شود؟</strong></p><p>نمایش نام تابع تنظیم حریم خصوصی مدیر سایت است و ممکن است فقط نام کوچک یا هیچ نامی نمایش داده نشود.</p><p><strong>چرا سهم من دوباره آزاد شده است؟</strong></p><p>احتمالاً زمان آزادسازی خودکار سهم خوانده‌نشده فرا رسیده یا سهم به‌صورت دستی آزاد شده است.</p><p><strong>چرا دکمه قرائت انجام شد را نمی‌بینم؟</strong></p><p>ممکن است ختم در حالت «انجام‌شده با انتخاب سهم» باشد یا سهم پیش‌تر ثبت شده باشد.</p><p><strong>اطلاعات تماس من عمومی می‌شود؟</strong></p><p>خیر؛ شماره موبایل و ایمیل کامل روی کارت عمومی سهم نمایش داده نمی‌شود.</p>';
    }

    private static function create_khatm_guide_content() {
        return '<h2>۱. چه کسانی می‌توانند ختم ایجاد کنند؟</h2><p>کاربرانی که وارد حساب هم‌نور شده‌اند و طبق تنظیمات سایت مجوز ایجاد ختم دارند می‌توانند فرم را باز کنند. مدیر سایت می‌تواند ایجاد ختم جدید را موقتاً غیرفعال کند؛ در این حالت پیام مربوط در صفحه ایجاد ختم نمایش داده می‌شود.</p>'
            . '<h2>۲. مراحل ایجاد ختم</h2><ol><li>اطلاعات و نیت ختم را بنویسید.</li><li>نوع تقسیم‌بندی و تعداد ختم را انتخاب کنید.</li><li>ظاهر و تصویر را تنظیم کنید.</li><li>اطلاعات ایجادکننده را بررسی کنید.</li><li>نوع دسترسی و زمان‌بندی را تعیین کنید.</li><li>روش ثبت قرائت و آزادسازی را انتخاب کنید.</li><li>خلاصه نهایی را بررسی و درخواست را ثبت کنید.</li></ol>'
            . '<h2>۳. انتخاب نوع ختم</h2><ul><li><strong>صفحه‌ای:</strong> هر ختم کامل به ۶۰۴ سهم صفحه تقسیم می‌شود.</li><li><strong>جزء‌محور:</strong> هر ختم کامل ۳۰ سهم جزء دارد.</li><li><strong>سوره‌ای:</strong> سهم‌ها بر اساس ۱۱۴ سوره قرآن ساخته می‌شوند.</li><li><strong>تکرار یک سوره:</strong> یک سوره مشخص به تعداد دلخواه تکرار و میان شرکت‌کنندگان تقسیم می‌شود.</li></ul><p><strong>توجه:</strong> نوع ختم پس از ایجاد قابل تغییر نیست.</p>'
            . '<h2>۴. مفهوم سهم چیست؟</h2><p>سهم کوچک‌ترین واحد مشارکت در ختم است؛ برای نمونه یک صفحه، یک جزء، یک سوره یا یک نوبت قرائت سوره تکراری. هر شرکت‌کننده یک یا چند سهم آزاد را انتخاب می‌کند و وضعیت آن سهم جداگانه پیگیری می‌شود.</p>'
            . '<h2>۵. انتخاب تعداد ختم</h2><p>برای ختم‌های صفحه‌ای، جزء‌محور و سوره‌ای می‌توانید بیش از یک ختم کامل ایجاد کنید. تعداد کل سهم‌ها برابر تعداد ختم ضرب در سهم‌های هر ختم است. سقف انتخاب را مدیر سایت تعیین می‌کند و تعداد ختم پس از ایجاد قابل تغییر نیست.</p>'
            . '<h2>۶. انتخاب سوره در ختم تکرار یک سوره</h2><p>نام سوره را جستجو و سوره درست را از فهرست انتخاب کنید، سپس تعداد تکرار را بنویسید. هر تکرار یک سهم مستقل خواهد بود. چون همه سهم‌ها یکسان‌اند، در صفحه این نوع ختم دکمه انتخاب تصادفی نمایش داده نمی‌شود.</p>'
            . '<h2>۷. تنظیم نیاز یا عدم نیاز به ثبت قرائت</h2><p>در حالت «ثبت دستی»، شرکت‌کننده پس از خواندن باید دکمه «قرائت انجام شد» را بزند. در حالت «انجام‌شده با انتخاب»، سهم بلافاصله پس از انتخاب خوانده‌شده محسوب می‌شود. حالت دوم را فقط زمانی انتخاب کنید که روند ختم شما بر اعتماد و تعهد فوری شرکت‌کننده استوار است.</p>'
            . '<h2>۸. تنظیم آزادسازی سهم‌های خوانده‌نشده</h2><p>در حالت ثبت دستی می‌توانید آزادسازی را خاموش کنید، آزادسازی پس از چند روز را برگزینید یا یک تاریخ ثابت تعیین کنید. سهمی که تا موعد ثبت نشده باشد دوباره آزاد می‌شود تا فرد دیگری آن را انتخاب کند. این تنظیم بر سهم‌های انجام‌شده اثری ندارد.</p>'
            . '<h2>۹. انتخاب تصویر ختم</h2><p>تصویری افقی، واضح و متناسب با موضوع ختم انتخاب کنید. اندازه پیشنهادی ۱۲۰۰ در ۶۳۰ پیکسل است. اگر تصویر نگذارید، تصویر یا طرح پیش‌فرض سایت استفاده می‌شود. از تصویری استفاده کنید که حق انتشار آن را دارید.</p>'
            . '<h2>۱۰. تنظیم متن دعوت</h2><p>متن دعوت از عنوان، نیت و لینک ختم ساخته می‌شود و قالب آن تابع تنظیمات مدیر سایت است. عنوان و نیت را کوتاه و روشن بنویسید تا متن اشتراک‌گذاری قابل فهم باشد. پس از انتشار، متن دعوت و لینک را از صفحه ختم کپی کنید.</p>'
            . '<h2>۱۱. وضعیت پیش‌نویس و بررسی مدیر</h2><p>ختمی که کاربر عادی ثبت می‌کند ابتدا با وضعیت «در انتظار بررسی و انتشار مدیر» ذخیره می‌شود. در این مرحله مالک و مدیر می‌توانند پیش‌نمایش را ببینند، اما صفحه برای عموم محتوای کامل و امکان مشارکت فعال ندارد.</p>'
            . '<h2>۱۲. حق مدیر برای اصلاح تصویر و ظاهر ختم</h2><p>مدیر سایت می‌تواند پیش از انتشار، تصویر و برخی تنظیمات ظاهری ختم را برای هماهنگی با قوانین و ظاهر سایت اصلاح کند. اطلاعات ساختاری مانند نوع ختم و سهم‌های ساخته‌شده طبق قواعد سامانه ثابت می‌ماند.</p>'
            . '<h2>۱۳. دریافت لینک دعوت</h2><p>پس از بررسی و انتشار مدیر، لینک دعوت فعال می‌شود. انتشار در داشبورد قابل مشاهده است و اگر ایمیل معتبر و ارسال ایمیل سایت فعال باشد، پیام اطلاع‌رسانی نیز فرستاده می‌شود. سپس لینک را کپی و برای مخاطبان ارسال کنید.</p>'
            . '<h2>۱۴. مدیریت شرکت‌کنندگان</h2><p>از داشبورد هم‌نور، ختم موردنظر و بخش شرکت‌کنندگان را باز کنید. نام و آمار سهم‌های هر شرکت‌کننده قابل پیگیری است. اطلاعات تماس را فقط برای مدیریت همان ختم و با رعایت حریم خصوصی استفاده کنید.</p>'
            . '<h2>۱۵. پیگیری پیشرفت ختم</h2><p>نوار پیشرفت و شمار سهم‌های آزاد، رزروشده و خوانده‌شده در صفحه ختم به‌روزرسانی می‌شود. از داشبورد نیز می‌توانید پیشرفت ختم‌های خود را ببینید و در صورت نیاز اطلاعات یا ظاهر مجاز را ویرایش کنید.</p>'
            . '<h2>۱۶. نکات پیشنهادی برای ایجاد یک ختم موفق</h2><ul><li>عنوان و نیت روشن و محترمانه انتخاب کنید.</li><li>تقسیم‌بندی متناسب با تعداد احتمالی شرکت‌کنندگان باشد.</li><li>زمان پایان واقع‌بینانه تعیین کنید.</li><li>روش ثبت قرائت را در متن دعوت توضیح دهید.</li><li>پیشرفت ختم را پیگیری و در صورت لزوم یادآوری محترمانه ارسال کنید.</li><li>اطلاعات شخصی شرکت‌کنندگان را محرمانه نگه دارید.</li></ul>'
            . '<h2>۱۷. پرسش‌های متداول</h2><p><strong>چرا ختم من بلافاصله عمومی نشد؟</strong></p><p>ختم کاربران عادی ابتدا باید توسط مدیر سایت بررسی و منتشر شود.</p><p><strong>آیا پس از ایجاد می‌توانم نوع ختم را عوض کنم؟</strong></p><p>خیر؛ نوع ختم و تعداد ختم به ساختار سهم‌ها وابسته‌اند و پس از ایجاد قابل تغییر نیستند.</p><p><strong>چرا ایجاد ختم جدید بسته است؟</strong></p><p>مدیر سایت این امکان را موقتاً غیرفعال کرده است. بعداً دوباره مراجعه کنید.</p><p><strong>چه زمانی لینک دعوت را ارسال کنم؟</strong></p><p>پس از مشاهده پیام انتشار در داشبورد یا دریافت ایمیل، لینک برای مشارکت عمومی فعال است.</p><p><strong>آیا مدیر می‌تواند تصویر را تغییر دهد؟</strong></p><p>بله؛ مدیر پیش از انتشار می‌تواند تصویر و برخی تنظیمات ظاهری را اصلاح کند.</p>';
    }

    public static function page_url($key) {
        $key = sanitize_key($key);
        if ('account' === $key) { $key = is_user_logged_in() ? 'dashboard' : 'login'; }
        $pages = get_option('hamnoor_pages', array());
        $id = is_array($pages) && !empty($pages[$key]) ? (int) $pages[$key] : 0;
        if ($id && 'publish' === get_post_status($id)) { return get_permalink($id); }
        $defs = self::default_pages();
        if (isset($defs[$key])) { $page = get_page_by_path($defs[$key]['slug']); if ($page) { return get_permalink($page); } }
        return home_url('/');
    }


    public static function sanitize_token($token) {
        $token = (string) $token;
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return substr($token, 0, 80);
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
     * لینک اصلی دعوت همیشه کوتاه، تمیز و بدون slug است: /h/{token}/
     * مسیرهای دارای slug فقط برای سازگاری route پشتیبانی می‌شوند و لینک اصلی UI نیستند.
     */
    public static function invite_url($khatm) {
        $settings = self::settings();
        $base = isset($settings['link_base']) ? trim(sanitize_title((string) $settings['link_base']), '/') : 'h';
        $base = '' === $base ? 'h' : $base;
        $token = is_object($khatm) ? (string) $khatm->token : (string) $khatm;
        return home_url(user_trailingslashit($base . '/' . self::sanitize_token($token)));
    }

    public static function slug_khatm_url($khatm) {
        $url = self::invite_url($khatm);
        if (is_object($khatm)) {
            $slug = sanitize_title(!empty($khatm->slug) ? $khatm->slug : (isset($khatm->title) ? $khatm->title : ''));
            if ($slug) { return trailingslashit($url) . user_trailingslashit($slug); }
        }
        return $url;
    }

    public static function legacy_khatm_url($khatm) {
        $token = is_object($khatm) ? (string) $khatm->token : (string) $khatm;
        return home_url(user_trailingslashit('khatm/' . self::sanitize_token($token)));
    }

    public static function khatm_has_indexable_content($khatm) {
        if (!$khatm) { return false; }
        if ('public' !== (string) $khatm->privacy || 'active' !== (string) $khatm->status || empty($khatm->indexable)) { return false; }
        $text = trim(wp_strip_all_tags((string) $khatm->description . ' ' . (string) $khatm->intention));
        $word_count = str_word_count(str_replace(array('،','؛','.',':','/','-'), ' ', $text));
        return mb_strlen($text) >= 40 || $word_count >= 8;
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
        return '<div class="hamnoor-notice hamnoor-notice-' . esc_attr($type) . '">' . wp_kses($notice['message'], array('a'=>array('href'=>array(),'class'=>array(),'target'=>array(),'rel'=>array()), 'strong'=>array(), 'br'=>array())) . '</div>';
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
        $url = self::invite_url($khatm);
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
            if (preg_match('/^🕊\s*نیت ختم:\s*$/u', $line)) { return false; }
            return !preg_match('/^🔗?\s*(?:لینک|پیوند)(?:\s+(?:ختم|شرکت در ختم|دعوت))?\s*[:：]?$/u', $line);
        }));
        $text = trim(implode("\n\n", $lines));
        if ('' === $text) {
            $text = $title ? '📖 ' . $title : __('📖 دعوت به مشارکت در ختم قرآن کریم', 'hamnoor');
            if ($intention) { $text .= "\n\n🕊 " . sprintf(__('نیت ختم: %s', 'hamnoor'), $intention); }
            $text .= "\n\n" . __('✨ شما می‌توانید با انتخاب یک سهم، در این ختم نورانی مشارکت کنید.', 'hamnoor');
        }
        if ('web' === sanitize_key($mode)) { return $text; }
        return $text . "\n\n" . __('🔗 لینک ختم:', 'hamnoor') . "\n" . $url;
    }

    public static function csv_safe($value) { $value = (string) $value; return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value; }

    public static function upload_cover($field) {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) { return array(); }
        if (!function_exists('wp_handle_upload')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        if (!function_exists('wp_check_filetype_and_ext')) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
        $settings = self::settings(); $max = (int) $settings['cover_max_size_kb'] * 1024;
        if (!empty($_FILES[$field]['size']) && (int) $_FILES[$field]['size'] > $max) { return new WP_Error('hamnoor_cover_size', sprintf(__('حجم تصویر ختم بیش از حد مجاز است. لطفاً تصویری کمتر از %d کیلوبایت انتخاب کنید. سایر اطلاعات فرم حفظ شده است، اما فایل تصویر باید دوباره انتخاب شود.', 'hamnoor'), (int)$settings['cover_max_size_kb'])); }
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
        return array('hamnoor_login','hamnoor_register','hamnoor_dashboard','hamnoor_track_parts','hamnoor_account','hamnoor_create_khatm');
    }

    public static function mask_contact($value) {
        $value = (string) $value;
        if (is_email($value)) {
            list($name, $domain) = explode('@', $value, 2);
            return mb_substr($name, 0, 2) . '***@' . $domain;
        }
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
    public static function quran_url($type,$unit){$s=self::settings();$unit=(int)$unit;if('page'===$type){return str_replace('{unit}',$unit,$s['quran_page_url_pattern']);} if('juz'===$type){return str_replace('{unit}',$unit,$s['quran_juz_url_pattern']);} $surah=self::surah($unit); return str_replace('{slug}',$surah?$surah['slug']:'al-faatiha',$s['quran_surah_url_pattern']);}
    public static function design_class($khatm) {
        $style = is_object($khatm) && !empty($khatm->design_style) ? sanitize_key($khatm->design_style) : 'default';
        return 'hamnoor-style-' . sanitize_html_class($style);
    }

    public static function khatm_inline_style($khatm) {
        $rules = array();
        if (is_object($khatm) && !empty($khatm->custom_primary_color)) {
            $color = sanitize_hex_color($khatm->custom_primary_color);
            if ($color) { $rules[] = '--hamnoor-primary:' . $color; }
        }
        if (is_object($khatm) && !empty($khatm->custom_accent_color)) {
            $color = sanitize_hex_color($khatm->custom_accent_color);
            if ($color) { $rules[] = '--hamnoor-accent:' . $color; }
        }
        return $rules ? implode(';', $rules) . ';' : '';
    }


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
            'khatm' => array('pending_review'=>__('در انتظار بررسی و انتشار مدیر', 'hamnoor'), 'active'=>Hamnoor_Texts::get('badge_active'), 'completed'=>Hamnoor_Texts::get('badge_completed'), 'closed'=>__('بسته', 'hamnoor'), 'archived'=>__('آرشیو', 'hamnoor'), 'failed'=>__('ناموفق', 'hamnoor')),
            'privacy' => array('public'=>__('عمومی', 'hamnoor'), 'private'=>__('خصوصی', 'hamnoor')),
            'type' => array('page'=>__('صفحه‌ای', 'hamnoor'), 'juz'=>__('جزء‌محور', 'hamnoor'), 'surah'=>__('سوره‌ای', 'hamnoor'), 'repeated_surah'=>__('تکرار یک سوره', 'hamnoor')),
        );
        return isset($labels[$context][$status]) ? $labels[$context][$status] : $status;
    }
}
