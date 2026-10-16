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
        add_action('init', array($this, 'register_campaign_block_template'), 20);
        add_filter('request', array($this, 'resolve_root_token'), 1);
        add_filter('redirect_canonical', array($this, 'disable_canonical_redirect'), 10, 2);
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 99);
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'prepare_khatm_request'));
        add_filter('pre_get_shortlink', array($this, 'filter_shortlink'), 10, 4);
        add_filter('the_content', array($this, 'inject_khatm_content'), 20);
        add_filter('template_include', array($this, 'template_include'));
        add_filter('body_class', array($this, 'body_class'));
        add_action('wp_enqueue_scripts', array($this, 'isolate_standalone_assets'), 999);
    }


    public function render_mode() {
        $settings = Hamnoor_Helpers::settings();
        return !empty($settings['khatm_render_mode']) && 'plugin' === $settings['khatm_render_mode'] ? 'plugin' : 'theme';
    }

    private function uses_theme_template() {
        // Render mode must be authoritative. Older builds forced block themes
        // (including Twenty Twenty-Five) back through the site theme even when
        // the administrator selected the dedicated Hamnoor template. That made
        // public khatm pages impossible to control reliably on mobile.
        return 'theme' === $this->render_mode();
    }

    /**
     * Register a dedicated block template for virtual campaign URLs.
     *
     * WordPress 6.7+ provides a first-party plugin template registry. Using it
     * keeps the active block theme's Header and Footer while preventing generic
     * theme templates (for example Twenty Twenty-Five's Blog/Home template)
     * from adding their own query title or duplicate post title around Hamnoor.
     * Themes/site owners can still customize or override this registered
     * template through the Site Editor.
     */
    public function register_campaign_block_template() {
        if (!function_exists('register_block_template')) {
            return;
        }

        $template_file = HAMNOOR_PATH . 'templates/block/hamnoor-campaign.html';
        if (!is_readable($template_file)) {
            return;
        }

        $content = file_get_contents($template_file);
        if (!is_string($content) || '' === trim($content)) {
            return;
        }

        $result = register_block_template('hamnoor//hamnoor-campaign', array(
            'title'       => __('صفحه پویش هم‌نور', 'hamnoor'),
            'description' => __('قالب اختصاصی صفحات مجازی پویش؛ هدر و فوتر قالب سایت را نگه می‌دارد و فقط محتوای خود هم‌نور را بین آن‌ها نمایش می‌دهد.', 'hamnoor'),
            'content'     => $content,
            'plugin'      => 'hamnoor',
        ));

        // Registration can legitimately be attempted more than once by unusual
        // bootstrap/test environments. A duplicate must never break front-end
        // rendering, so only persist diagnostics for non-duplicate failures.
        if (is_wp_error($result) && 'block_template_already_registered' !== $result->get_error_code()) {
            update_option('hamnoor_last_block_template_error', array(
                'code'    => sanitize_key((string) $result->get_error_code()),
                'message' => sanitize_text_field((string) $result->get_error_message()),
                'at'      => current_time('mysql'),
            ), false);
        } elseif (!is_wp_error($result)) {
            delete_option('hamnoor_last_block_template_error');
        }
    }

    /**
     * Force the registered campaign block template only for Hamnoor virtual
     * campaign requests in theme-compatible mode.
     */
    private function locate_campaign_theme_template($fallback) {
        if (!function_exists('register_block_template') || !function_exists('locate_block_template')) {
            return $fallback;
        }
        if (!current_theme_supports('block-templates')) {
            return $fallback;
        }

        // Use a fresh hierarchy instead of the incoming template path. On block
        // themes the incoming path can already be template-canvas.php with the
        // wrong Blog/Home content loaded into Core globals.
        $templates = array('hamnoor-campaign.php', 'index.php');
        $php_template = locate_template($templates);
        $located = locate_block_template($php_template, 'hamnoor-campaign', $templates);
        return $located ? $located : $fallback;
    }

    public function isolate_standalone_assets() {
        if (!$this->is_hamnoor_request() || 'plugin' !== $this->render_mode()) {
            return;
        }

        global $wp_styles, $wp_scripts;
        $style_allow = array('hamnoor-frontend', 'hamnoor-mobile', 'hamnoor-standalone', 'admin-bar', 'dashicons');
        $core_theme_styles = array('global-styles', 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles');
        $theme_paths = array_filter(array(
            '/themes/' . get_stylesheet() . '/',
            '/themes/' . get_template() . '/',
        ));

        if ($wp_styles instanceof WP_Styles) {
            foreach ((array) $wp_styles->queue as $handle) {
                if (in_array($handle, $style_allow, true)) {
                    continue;
                }
                $src = isset($wp_styles->registered[$handle]) ? (string) $wp_styles->registered[$handle]->src : '';
                $from_theme = false;
                foreach ($theme_paths as $path) {
                    if ($src && false !== strpos($src, $path)) { $from_theme = true; break; }
                }
                if ($from_theme || in_array($handle, $core_theme_styles, true)) {
                    wp_dequeue_style($handle);
                }
            }
        }

        if ($wp_scripts instanceof WP_Scripts) {
            foreach ((array) $wp_scripts->queue as $handle) {
                if (0 === strpos((string) $handle, 'hamnoor-') || 'admin-bar' === $handle) {
                    continue;
                }
                $src = isset($wp_scripts->registered[$handle]) ? (string) $wp_scripts->registered[$handle]->src : '';
                foreach ($theme_paths as $path) {
                    if ($src && false !== strpos($src, $path)) {
                        wp_dequeue_script($handle);
                        break;
                    }
                }
            }
        }
    }

    public function register_rewrite_rules() {
        add_rewrite_tag('%hamnoor_khatm%', '([a-z0-9]{5})');
    }

    public function resolve_root_token($query_vars) {
        if (is_admin() || empty($_SERVER['REQUEST_URI'])) { return $query_vars; }
        $request_path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $request_path = trim($request_path, '/');
        $home_path = trim($home_path, '/');
        if ($home_path) {
            if (0 !== strpos($request_path . '/', $home_path . '/')) { return $query_vars; }
            $request_path = trim(substr($request_path, strlen($home_path)), '/');
        }
        if (!preg_match('/^([a-z0-9]{5})$/i', $request_path, $matches)) { return $query_vars; }
        $token = Hamnoor_Helpers::sanitize_token($matches[1]);
        if (!Hamnoor_Helpers::is_public_token($token) || !Hamnoor_DB::get_khatm($token)) { return $query_vars; }
        return array('hamnoor_khatm' => $token);
    }

    public function disable_canonical_redirect($redirect_url, $requested_url) {
        return $this->is_hamnoor_request() ? false : $redirect_url;
    }

    public function maybe_flush_rewrite_rules() {
        $signature = HAMNOOR_VERSION . '|root-five|external-integrations|' . $this->render_mode();
        if (get_option('hamnoor_rewrite_version') !== $signature) {
            $this->register_rewrite_rules();
            do_action('hamnoor_register_integration_rewrites');
            flush_rewrite_rules(false);
            update_option('hamnoor_rewrite_version', $signature, false);
            // Remove stale guest HTML generated before the custom Hamnoor
            // session cookie was registered with LiteSpeed.
            if (has_action('litespeed_purge_url') || defined('LSCWP_V')) {
                foreach (array('dashboard','create','list') as $hamnoor_page_key) {
                    $hamnoor_page_url = Hamnoor_Helpers::page_url($hamnoor_page_key);
                    if ($hamnoor_page_url) { do_action('litespeed_purge_url', $hamnoor_page_url); }
                }
            }
        }
    }

    public function query_vars($vars) {
        $vars[] = 'hamnoor_khatm';
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
        if (!$khatm || !Hamnoor_Permissions::can_view_khatm($khatm)) {
            $GLOBALS['hamnoor_not_found'] = true;
            status_header(404);
            return;
        }
        $GLOBALS['hamnoor_current_khatm'] = $khatm;
        $GLOBALS['hamnoor_unpublished_restricted'] = !Hamnoor_Permissions::can_preview_unpublished_khatm($khatm);
        global $wp_query, $post;
        if ($wp_query) {
            // A five-character virtual campaign URL contains no native WP post.
            // WP_Query can therefore retain is_home=true from the initial parse,
            // which makes block themes select their Blog/Home template even after
            // we mark the request as singular. Reset incompatible query flags so
            // WordPress and SEO/theme integrations see one coherent virtual page.
            $wp_query->is_404 = false;
            $wp_query->is_home = false;
            $wp_query->is_archive = false;
            $wp_query->is_post_type_archive = false;
            $wp_query->is_search = false;
            $wp_query->is_feed = false;
            $wp_query->is_category = false;
            $wp_query->is_tag = false;
            $wp_query->is_tax = false;
            $wp_query->is_author = false;
            $wp_query->is_date = false;
            $wp_query->is_year = false;
            $wp_query->is_month = false;
            $wp_query->is_day = false;
            $wp_query->is_time = false;
            $wp_query->is_attachment = false;
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
        $post->post_title = $restricted ? __('پویش در انتظار انتشار', 'hamnoor') : $khatm->title;
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
            return '<div class="hamnoor-wrap ' . esc_attr(Hamnoor_Helpers::visual_mode_class()) . '" dir="rtl"><div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('پویش مورد نظر پیدا نشد.', 'hamnoor') . '</div></div>';
        }
        $khatm = $GLOBALS['hamnoor_current_khatm'];
        if (!empty($GLOBALS['hamnoor_unpublished_restricted'])) {
            return '<div class="hamnoor-wrap ' . esc_attr(Hamnoor_Helpers::visual_mode_class()) . '" dir="rtl"><div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این پویش هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor') . '</div></div>';
        }
        return Hamnoor_Frontend::instance()->wrap_public(Hamnoor_Frontend::instance()->render_khatm($khatm));
    }

    public function template_include($template) {
        if (empty($GLOBALS['hamnoor_current_khatm']) && empty($GLOBALS['hamnoor_not_found'])) {
            return $template;
        }
        if ($this->uses_theme_template()) {
            return $this->locate_campaign_theme_template($template);
        }
        $plugin_template = Hamnoor_Helpers::locate_template('frontend/khatm-single.php');
        return $plugin_template ? $plugin_template : $template;
    }

    public function body_class($classes) {
        if (!$this->is_hamnoor_request() || !$this->uses_theme_template()) {
            return $classes;
        }
        if (!in_array('hamnoor-public-khatm-page', $classes, true)) {
            $classes[] = 'hamnoor-public-khatm-page';
        }
        return $classes;
    }

}
