<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_SEO {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_head', array($this, 'render_meta'), 2);
        add_filter('wp_robots', array($this, 'robots'));
        add_filter('document_title_parts', array($this, 'document_title'));
        add_filter('wp_sitemaps_posts_query_args', array($this, 'sitemap_posts_args'), 10, 2);
        add_filter('rank_math/frontend/robots', array($this, 'rank_math_robots'));
        add_filter('wpseo_robots', array($this, 'yoast_robots'));
        add_action('init', array($this, 'register_sitemap_provider'), 20);
    }

    private function current_khatm() {
        return !empty($GLOBALS['hamnoor_current_khatm']) ? $GLOBALS['hamnoor_current_khatm'] : null;
    }

    private function current_khatm_noindex() {
        $khatm = $this->current_khatm();
        if (!$khatm) { return false; }
        if (!Hamnoor_Helpers::khatm_has_indexable_content($khatm)) { return true; }
        if (!empty($_GET['part']) || !empty($_GET['access']) || !empty($_GET['hamnoor_round']) || !empty($_GET['hamnoor_free_only']) || !empty($_GET['hamnoor_part_search']) || !empty($_GET['hamnoor_parts_page']) || !empty($_GET['paged']) || !empty($_GET['filter']) || !empty($_GET['s'])) { return true; }
        $settings = Hamnoor_Helpers::settings();
        return empty($settings['index_public_khatms']) || '1' !== $settings['index_public_khatms'];
    }

    public function robots($robots) {
        $khatm = $this->current_khatm();
        if ($khatm) {
            if ($this->current_khatm_noindex()) {
                return array('noindex' => true, 'nofollow' => true);
            }
            return $robots;
        }
        if (is_page()) {
            $pages = get_option('hamnoor_pages', array());
            $noindex_keys = array('account', 'dashboard', 'track', 'create', 'login', 'register');
            foreach ($noindex_keys as $key) {
                if (is_array($pages) && !empty($pages[$key]) && (int) get_queried_object_id() === (int) $pages[$key]) {
                    return array('noindex' => true, 'nofollow' => true);
                }
            }
            global $post;
            if ($post) {
                foreach (Hamnoor_Helpers::noindex_shortcode_tags() as $tag) {
                    if (has_shortcode($post->post_content, $tag)) {
                        return array('noindex' => true, 'nofollow' => true);
                    }
                }
                if (has_shortcode($post->post_content, 'hamnoor_khatm_list') && (!empty($_GET['s']) || !empty($_GET['paged']) || !empty($_GET['filter']))) {
                    return array('noindex' => true, 'nofollow' => true);
                }
            }
        }
        return $robots;
    }

    public function document_title($parts) {
        $khatm = $this->current_khatm();
        if ($khatm) {
            $parts['title'] = !empty($GLOBALS['hamnoor_unpublished_restricted']) ? __('ختم در انتظار انتشار | هم‌نور', 'hamnoor') : sprintf(__('ختم قرآن %s | هم‌نور', 'hamnoor'), $khatm->title);
        }
        return $parts;
    }

    private function description($khatm) {
        $desc = wp_strip_all_tags($khatm->description ? $khatm->description : $khatm->intention);
        if (mb_strlen(trim($desc)) < 40) {
            $desc = sprintf(__('دعوت به مشارکت در ختم جمعی قرآن کریم با عنوان «%s» در سامانه هم‌نور.', 'hamnoor'), $khatm->title);
        }
        return wp_trim_words($desc, 28, '');
    }

    public function render_meta() {
        $khatm = $this->current_khatm();
        if (!$khatm) { return; }
        $url = Hamnoor_Helpers::khatm_url($khatm);
        if (!empty($GLOBALS['hamnoor_unpublished_restricted'])) {
            echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
            return;
        }
        $desc = $this->description($khatm);
        $title = sprintf(__('ختم قرآن %s', 'hamnoor'), $khatm->title);
        echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
        if (!empty($_GET['part']) || !empty($_GET['access'])) {
            echo '<meta name="referrer" content="no-referrer" />' . "\n";
        }
        echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        $image = Hamnoor_Helpers::share_image_data($khatm);
        if (!empty($image['url'])) {
            echo '<meta property="og:image" content="' . esc_url($image['url']) . '" />' . "\n";
            echo '<meta property="og:image:width" content="' . (int) $image['width'] . '" />' . "\n";
            echo '<meta property="og:image:height" content="' . (int) $image['height'] . '" />' . "\n";
        }
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        if (!empty($image['url'])) { echo '<meta name="twitter:image" content="' . esc_url($image['url']) . '" />' . "\n"; }
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'url' => $url,
            'description' => $desc,
            'breadcrumb' => array(
                '@type' => 'BreadcrumbList',
                'itemListElement' => array(
                    array('@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo('name'), 'item' => home_url('/')),
                    array('@type' => 'ListItem', 'position' => 2, 'name' => __('هم‌نور', 'hamnoor'), 'item' => Hamnoor_Helpers::page_url('list')),
                    array('@type' => 'ListItem', 'position' => 3, 'name' => $khatm->title, 'item' => $url),
                ),
            ),
        );
        if (!empty($khatm->starts_at) && !empty($khatm->ends_at) && strtotime($khatm->starts_at) && strtotime($khatm->ends_at) && strtotime($khatm->starts_at) < strtotime($khatm->ends_at)) {
            $schema['mainEntity'] = array('@type' => 'Event', 'name' => $khatm->title, 'startDate' => mysql2date('c', $khatm->starts_at), 'endDate' => mysql2date('c', $khatm->ends_at), 'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode', 'eventStatus' => 'https://schema.org/EventScheduled');
        }
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    public function register_sitemap_provider() {
        if (!function_exists('wp_sitemaps_add_provider') || !class_exists('WP_Sitemaps_Provider')) { return; }
        $settings = Hamnoor_Helpers::settings();
        if (empty($settings['index_public_khatms']) || '1' !== $settings['index_public_khatms']) { return; }
        $provider = new class extends WP_Sitemaps_Provider {
            public function __construct() { $this->name = 'hamnoor'; $this->object_type = 'hamnoor_khatm'; }
            public function get_url_list($page_num, $object_subtype = '') {
                $limit = 100;
                $items = Hamnoor_DB::get_khatms(array('privacy'=>'public','status'=>'active','limit'=>$limit,'offset'=>max(0, ((int)$page_num - 1) * $limit)));
                $urls = array();
                foreach ($items as $khatm) {
                    if (!Hamnoor_Helpers::khatm_has_indexable_content($khatm)) { continue; }
                    $urls[] = array('loc' => Hamnoor_Helpers::khatm_url($khatm), 'lastmod' => mysql2date('c', $khatm->updated_at ? $khatm->updated_at : $khatm->created_at));
                }
                return $urls;
            }
            public function get_max_num_pages($object_subtype = '') {
                $total = Hamnoor_DB::count_khatms(array('privacy'=>'public','status'=>'active'));
                return max(1, (int) ceil($total / 100));
            }
        };
        wp_sitemaps_add_provider('hamnoor', $provider);
    }

    public function sitemap_posts_args($args, $post_type) {
        if ('page' !== $post_type) { return $args; }
        $pages = get_option('hamnoor_pages', array());
        if (is_array($pages)) {
            $exclude = array();
            foreach (array('account','dashboard','track','create','login','register') as $key) {
                if (!empty($pages[$key])) { $exclude[] = (int) $pages[$key]; }
            }
            if ($exclude) { $args['post__not_in'] = array_merge(isset($args['post__not_in']) ? (array) $args['post__not_in'] : array(), $exclude); }
        }
        return $args;
    }

    public function rank_math_robots($robots) {
        $r = $this->robots(array());
        if (!empty($r['noindex'])) { return array('noindex' => 'noindex', 'nofollow' => 'nofollow'); }
        return $robots;
    }

    public function yoast_robots($robots) {
        $r = $this->robots(array());
        if (!empty($r['noindex'])) { return 'noindex,nofollow'; }
        return $robots;
    }
}
