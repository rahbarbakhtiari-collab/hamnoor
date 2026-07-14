<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Router {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('init', array($this, 'register_rewrite_rules'));
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 99);
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'prepare_khatm_request'));
        add_filter('pre_get_shortlink', array($this, 'filter_shortlink'), 10, 4);
        add_filter('the_content', array($this, 'inject_khatm_content'), 20);
        add_filter('template_include', array($this, 'template_include'));
        add_action('admin_notices', array($this, 'empty_base_notice'));
    }

    public function link_base() {
        $settings = Hamnoor_Helpers::settings();
        $base = isset($settings['link_base']) ? trim(sanitize_title($settings['link_base']), '/') : 'khatm';
        return '' === $base ? 'h' : $base;
    }

    public function render_mode() {
        $settings = Hamnoor_Helpers::settings();
        return !empty($settings['khatm_render_mode']) && 'plugin' === $settings['khatm_render_mode'] ? 'plugin' : 'theme';
    }

    private function uses_theme_template() {
        return 'theme' === $this->render_mode() || (function_exists('wp_is_block_theme') && wp_is_block_theme());
    }

    public function register_rewrite_rules() {
        $bases = array_unique(array_filter(array($this->link_base(), 'h', 'khatm')));
        foreach ($bases as $base) {
            $base = trim(sanitize_title($base), '/');
            if ('' === $base) { $base = 'h'; }
            add_rewrite_rule('^' . preg_quote($base, '#') . '/([A-Za-z0-9_-]{3,80})/?$', 'index.php?hamnoor_khatm=$matches[1]', 'top');
            add_rewrite_rule('^' . preg_quote($base, '#') . '/([A-Za-z0-9_-]{3,80})/([^/]+)/?$', 'index.php?hamnoor_khatm=$matches[1]&hamnoor_slug=$matches[2]', 'top');
        }
        add_rewrite_tag('%hamnoor_khatm%', '([^&]+)');
        add_rewrite_tag('%hamnoor_slug%', '([^&]+)');
    }

    public function maybe_flush_rewrite_rules() {
        $signature = HAMNOOR_VERSION . '|h+khatm+' . $this->link_base() . '|' . $this->render_mode();
        if (get_option('hamnoor_rewrite_version') !== $signature) {
            $this->register_rewrite_rules();
            flush_rewrite_rules(false);
            update_option('hamnoor_rewrite_version', $signature, false);
        }
    }

    public function query_vars($vars) {
        $vars[] = 'hamnoor_khatm';
        $vars[] = 'hamnoor_slug';
        return $vars;
    }

    public function is_hamnoor_request() {
        return (bool) get_query_var('hamnoor_khatm');
    }

    public function filter_shortlink($shortlink, $id, $context, $allow_slugs) {
        if (false !== $shortlink || 'query' !== $context || !$this->is_hamnoor_request()) {
            return $shortlink;
        }

        $khatm = isset($GLOBALS['hamnoor_current_khatm']) ? $GLOBALS['hamnoor_current_khatm'] : null;
        if (!is_object($khatm) || empty($khatm->token)) {
            return $shortlink;
        }

        $requested_token = Hamnoor_Helpers::sanitize_token(get_query_var('hamnoor_khatm'));
        $khatm_token = Hamnoor_Helpers::sanitize_token($khatm->token);
        if (!$requested_token || !$khatm_token || $requested_token !== $khatm_token) {
            return $shortlink;
        }

        $url = Hamnoor_Helpers::khatm_url($khatm);
        return $url ? $url : $shortlink;
    }

    public function prepare_khatm_request() {
        $token = Hamnoor_Helpers::sanitize_token(get_query_var('hamnoor_khatm'));
        if (!$token) {
            return;
        }
        $khatm = Hamnoor_DB::get_khatm($token);
        if (!$khatm) {
            $GLOBALS['hamnoor_not_found'] = true;
            status_header(404);
            return;
        }
        $GLOBALS['hamnoor_current_khatm'] = $khatm;
        $GLOBALS['hamnoor_unpublished_restricted'] = !Hamnoor_Permissions::can_preview_unpublished_khatm($khatm);
        $GLOBALS['hamnoor_requested_slug'] = sanitize_title(get_query_var('hamnoor_slug'));
        global $wp_query, $post;
        if ($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            $wp_query->is_single = false;
            $wp_query->is_singular = true;
            $wp_query->queried_object = $this->virtual_post($khatm);
            $wp_query->queried_object_id = -1;
            $wp_query->posts = array($wp_query->queried_object);
            $wp_query->post = $wp_query->queried_object;
            $wp_query->post_count = 1;
            $wp_query->found_posts = 1;
        }
        $post = $wp_query ? $wp_query->post : $this->virtual_post($khatm);
        setup_postdata($post);
        status_header(200);
    }

    private function virtual_post($khatm) {
        $post = new stdClass();
        $post->ID = -1;
        $post->post_author = (int) $khatm->owner_user_id;
        $post->post_date = !empty($khatm->created_at) ? $khatm->created_at : current_time('mysql');
        $post->post_date_gmt = get_gmt_from_date($post->post_date);
        $post->post_content = '[hamnoor_virtual_khatm]';
        $restricted = !empty($GLOBALS['hamnoor_unpublished_restricted']);
        $post->post_title = $restricted ? __('ختم در انتظار انتشار', 'hamnoor') : $khatm->title;
        $post->post_excerpt = $restricted ? '' : wp_strip_all_tags((string) $khatm->intention);
        $post->post_status = 'publish';
        $post->comment_status = 'closed';
        $post->ping_status = 'closed';
        $post->post_password = '';
        $post->post_name = sanitize_title($khatm->slug ? $khatm->slug : $khatm->title);
        $post->to_ping = '';
        $post->pinged = '';
        $post->post_modified = !empty($khatm->updated_at) ? $khatm->updated_at : $post->post_date;
        $post->post_modified_gmt = get_gmt_from_date($post->post_modified);
        $post->post_content_filtered = '';
        $post->post_parent = 0;
        $post->guid = Hamnoor_Helpers::khatm_url($khatm);
        $post->menu_order = 0;
        $post->post_type = 'page';
        $post->post_mime_type = '';
        $post->comment_count = 0;
        $post->filter = 'raw';
        return new WP_Post($post);
    }

    public function inject_khatm_content($content) {
        if (is_admin() || !$this->is_hamnoor_request() || !$this->uses_theme_template()) {
            return $content;
        }
        if (empty($GLOBALS['hamnoor_current_khatm'])) {
            return '<div class="hamnoor-wrap ' . esc_attr(Hamnoor_Helpers::visual_mode_class()) . '" dir="rtl"><div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('ختم مورد نظر پیدا نشد.', 'hamnoor') . '</div></div>';
        }
        $khatm = $GLOBALS['hamnoor_current_khatm'];
        if (!empty($GLOBALS['hamnoor_unpublished_restricted'])) {
            return '<div class="hamnoor-wrap ' . esc_attr(Hamnoor_Helpers::visual_mode_class()) . '" dir="rtl"><div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این ختم هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor') . '</div></div>';
        }
        return Hamnoor_Frontend::instance()->wrap_public(Hamnoor_Frontend::instance()->render_khatm($khatm), Hamnoor_Helpers::design_class($khatm), Hamnoor_Helpers::khatm_inline_style($khatm));
    }

    public function template_include($template) {
        if (empty($GLOBALS['hamnoor_current_khatm']) && empty($GLOBALS['hamnoor_not_found'])) {
            return $template;
        }
        if ($this->uses_theme_template()) {
            return $template;
        }
        $plugin_template = Hamnoor_Helpers::locate_template('frontend/khatm-single.php');
        return $plugin_template ? $plugin_template : $template;
    }

    public function empty_base_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Hamnoor_Helpers::settings();
        if ('' === trim((string) $settings['link_base'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('هشدار هم‌نور: پایه لینک ختم خالی است. برای جلوگیری از تداخل URL، مسیر امن /h/TOKEN/ استفاده می‌شود. پیشنهاد بهتر: khatm', 'hamnoor') . '</p></div>';
        }
    }
}
