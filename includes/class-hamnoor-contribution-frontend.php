<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Public web adapter for repeatable Dua and numeric Dhikr campaigns.
 *
 * This class owns presentation and transport only. It deliberately does not
 * write campaign/contribution tables or duplicate target/idempotency rules.
 * All mutations pass through Hamnoor_Command_Contracts and the canonical
 * Hamnoor_Contribution_Service ledger.
 */
final class Hamnoor_Contribution_Frontend {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;

        // Hamnoor_Frontend registers the compatibility shortcode first. This
        // adapter replaces only the dispatch callback and delegates every
        // non-contribution campaign straight back to the established frontend.
        add_shortcode('hamnoor', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 30);
        add_action('wp_ajax_hamnoor_record_contribution', array(__CLASS__, 'ajax_record'));
        add_action('wp_ajax_nopriv_hamnoor_record_contribution', array(__CLASS__, 'ajax_record'));
    }

    public static function enqueue_assets() {
        $frontend = Hamnoor_Frontend::instance();
        if (!$frontend->is_shortcode_context() && !Hamnoor_Router::instance()->is_hamnoor_request()) { return; }
        wp_enqueue_style(
            'hamnoor-contributions',
            HAMNOOR_URL . 'assets/css/hamnoor-contributions.css',
            array('hamnoor-ui-v4'),
            Hamnoor_Helpers::asset_version('assets/css/hamnoor-contributions.css')
        );
        wp_enqueue_script(
            'hamnoor-contributions',
            HAMNOOR_URL . 'assets/js/hamnoor-contributions.js',
            array('hamnoor-frontend'),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-contributions.js'),
            true
        );
        wp_enqueue_script(
            'hamnoor-dua-audio',
            HAMNOOR_URL . 'assets/js/hamnoor-dua-audio.js',
            array(),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-dua-audio.js'),
            true
        );
        wp_enqueue_script('hamnoor-dua-reader', HAMNOOR_URL . 'assets/js/hamnoor-dua-reader.js', array(), Hamnoor_Helpers::asset_version('assets/js/hamnoor-dua-reader.js'), true);
    }

    private static function is_contribution_campaign($campaign) {
        if (!$campaign) { return false; }
        return in_array((string)$campaign->participation_strategy, array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true);
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array('token'=>'', 'id'=>''), $atts, 'hamnoor');
        $key = !empty($atts['id']) ? (int)$atts['id'] : Hamnoor_Helpers::sanitize_token($atts['token']);
        $campaign = $key ? Hamnoor_DB::get_khatm($key) : null;

        if (!$campaign || !self::is_contribution_campaign($campaign)) {
            return Hamnoor_Frontend::instance()->shortcode_khatm($atts);
        }
        // Preserve the exact established public visibility/review behavior.
        if (!Hamnoor_Permissions::can_view_khatm($campaign)
            || !Hamnoor_Permissions::can_preview_unpublished_khatm($campaign)) {
            return Hamnoor_Frontend::instance()->shortcode_khatm($atts);
        }

        $frontend = Hamnoor_Frontend::instance();
        return $frontend->wrap(self::render($campaign) . $frontend->render_mobile_navigation(''));
    }

    private static function normalized_progress($campaign) {
        $progress = Hamnoor_Campaign_Repository::progress($campaign);
        if (is_wp_error($progress)) {
            return array('current'=>0, 'target'=>max(0, (int)$campaign->target_quantity), 'percentage'=>0, 'is_completed'=>false);
        }
        return array(
            'current'=>max(0, (int)($progress['current'] ?? 0)),
            'target'=>max(0, (int)($progress['target'] ?? 0)),
            'percentage'=>max(0, min(100, (float)($progress['percentage'] ?? 0))),
            'is_completed'=>!empty($progress['is_completed']),
        );
    }

    private static function decode_payload($item) {
        if (!$item || null === $item->content_payload || '' === trim((string)$item->content_payload)) { return array(); }
        $decoded = json_decode((string)$item->content_payload, true);
        if (is_array($decoded) && JSON_ERROR_NONE === json_last_error()) { return $decoded; }
        return array('text'=>(string)$item->content_payload);
    }

    private static function dua_block_type($type) {
        $type = strtolower(trim((string)$type));
        $map = array(
            'text' => 'Text',
            'translate' => 'Translate',
            'translation' => 'Translate',
            'abouttext' => 'AboutText',
            'about' => 'AboutText',
            'heading' => 'Heading',
            'subheading' => 'Subheading',
            'other' => 'Other',
        );
        return isset($map[$type]) ? $map[$type] : '';
    }

    private static function reader_icon($name) {
        $paths = array(
            'moon'=>'<path d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.7 6.7 0 0 0 9.8 9.8Z"/>',
            'translation'=>'<path d="M4 5h9M8.5 3v2m1 0c-.6 4-2.8 7-6 8m2-5c1.5 2 3.2 3.5 5.5 4.5M14 20l4-10 4 10m-6.7-3h5.4"/>',
            'plus'=>'<path d="M12 5v14M5 12h14"/>',
            'minus'=>'<path d="M5 12h14"/>',
            'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'focus'=>'<path d="M8 3H3v5m13-5h5v5M8 21H3v-5m13 5h5v-5"/>',
        );
        $path = isset($paths[$name]) ? $paths[$name] : $paths['focus'];
        return '<svg class="hamnoor-dua-reader-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }

    private static function render_dua_semantic_block($node) {
        $type = self::dua_block_type($node['type'] ?? '');
        if (!$type || !array_key_exists('content', $node) || is_array($node['content']) || is_object($node['content'])) { return ''; }
        $raw = trim((string)$node['content']);
        if ('' === $raw) { return ''; }
        $segment_key = isset($node['segment_key']) ? sanitize_key((string)$node['segment_key']) : '';
        $segment_attr = $segment_key ? ' data-segment-key="' . esc_attr($segment_key) . '"' : '';

        if ('Heading' === $type || 'Subheading' === $type) {
            $tag = 'Heading' === $type ? 'h2' : 'h3';
            $class = 'hamnoor-dua-block hamnoor-dua-block-' . strtolower($type);
            return '<section class="' . esc_attr($class) . '"' . $segment_attr . '><' . $tag . '>' . esc_html(trim(wp_strip_all_tags($raw))) . '</' . $tag . '></section>';
        }

        $class = 'hamnoor-dua-block hamnoor-dua-block-' . strtolower($type);
        $lang = 'Text' === $type ? 'ar' : 'fa';
        $translate = 'Text' === $type ? ' translate="no"' : '';
        $body = wpautop(wp_kses_post($raw));
        return '<section class="' . esc_attr($class) . '"' . $segment_attr . ' lang="' . esc_attr($lang) . '" dir="rtl"' . $translate . '><div class="hamnoor-dua-block-body">' . $body . '</div></section>';
    }

    private static function render_payload_node($node, $depth = 0) {
        if (is_string($node) || is_numeric($node)) {
            $text = trim((string)$node);
            return '' === $text ? '' : '<p>' . wp_kses_post($text) . '</p>';
        }
        if (!is_array($node) || $depth > 8) { return ''; }

        if (isset($node['type']) && array_key_exists('content', $node)) {
            $semantic = self::render_dua_semantic_block($node);
            if ($semantic) { return $semantic; }
        }

        $is_list = function_exists('array_is_list') ? array_is_list($node) : (array_keys($node) === range(0, count($node) - 1));
        if ($is_list) {
            $html = '';
            foreach ($node as $item) {
                $chunk = self::render_payload_node($item, $depth + 1);
                if ($chunk) { $html .= $chunk; }
            }
            return $html;
        }

        $html = '';
        $recognized = false;
        $keys = array(
            'title'=>'h3',
            'arabic'=>'div',
            'text'=>'div',
            'content'=>'div',
            'body'=>'div',
            'translation'=>'div',
            'transliteration'=>'div',
            'description'=>'div',
            'note'=>'div',
        );
        foreach ($keys as $key=>$tag) {
            if (!array_key_exists($key, $node) || is_array($node[$key]) || is_object($node[$key])) { continue; }
            $value = trim((string)$node[$key]);
            if ('' === $value) { continue; }
            $recognized = true;
            $class = 'hamnoor-contribution-content-' . sanitize_html_class($key);
            if ('h3' === $tag) { $html .= '<h3 class="' . esc_attr($class) . '">' . esc_html($value) . '</h3>'; }
            else {
                $role = 'arabic' === $key ? ' hamnoor-dua-arabic' : (in_array($key, array('translation','description','note'), true) ? ' hamnoor-dua-persian' : '');
                $language = 'arabic' === $key ? ' lang="ar" dir="rtl"' : (in_array($key, array('translation','description','note'), true) ? ' lang="fa" dir="rtl"' : '');
                $html .= '<div class="' . esc_attr($class . $role) . '"' . $language . '>' . wp_kses_post(wpautop($value)) . '</div>';
            }
        }

        if (!empty($node['items']) && is_array($node['items'])) {
            $recognized = true;
            $html .= self::render_payload_node($node['items'], $depth + 1);
        }

        if (!$recognized) {
            $ignored = array('href','source','source_key','content_schema','type','id','audio','url','segment_key');
            foreach ($node as $key=>$value) {
                if (in_array((string)$key, $ignored, true)) { continue; }
                if (is_array($value)) {
                    $chunk = self::render_payload_node($value, $depth + 1);
                    if ($chunk) { $html .= $chunk; }
                } elseif (is_string($value) || is_numeric($value)) {
                    $text = trim((string)$value);
                    if ($text) { $html .= '<p>' . wp_kses_post($text) . '</p>'; }
                }
            }
        }
        return $html;
    }

    private static function content_html($item) {
        if (!$item) { return '<div class="hamnoor-notice hamnoor-notice-error">' . esc_html__('محتوای این پویش در دسترس نیست.', 'hamnoor') . '</div>'; }
        $payload = self::decode_payload($item);
        $content_source = !empty($payload['items']) && is_array($payload['items']) ? $payload['items'] : $payload;
        $html = self::render_payload_node($content_source);
        if (!$html) { $html = '<p class="hamnoor-empty">' . esc_html__('متن این محتوا هنوز ثبت نشده است.', 'hamnoor') . '</p>'; }
        $full_audio = self::audio_html($payload, 'full');
        $segment_audio = self::audio_html($payload, 'segment');
        return '<section class="hamnoor-contribution-content hamnoor-dua-reader-content" data-dua-reader-content aria-label="' . esc_attr__('متن دعا', 'hamnoor') . '"><h2>' . esc_html((string)$item->title) . '</h2>' . $full_audio . '<article class="hamnoor-dua-article-content">' . $html . '</article>' . $segment_audio . '</section>';
    }

    private static function audio_html($payload, $scope_filter = '') {
        $items = isset($payload['audio_items']) && is_array($payload['audio_items']) ? $payload['audio_items'] : array();
        $groups = array();
        foreach ($items as $raw) {
            $item = Hamnoor_Dua_Audio_Service::normalize_item($raw);
            if ($scope_filter && $item['scope'] !== $scope_filter) { continue; }
            $url = Hamnoor_Dua_Audio_Service::playback_url($item);
            if (!$url) { continue; }
            $key = 'segment' === $item['scope'] ? 'segment:' . ($item['segment_key'] ?: sanitize_key($item['segment_title'])) : 'full';
            $groups[$key][] = array(
                'url'=>$url,
                'reciter'=>$item['reciter'] ?: __('قاری دعا', 'hamnoor'),
                'scope'=>$item['scope'],
                'segment_title'=>$item['segment_title'],
                'layout_order'=>(int)$item['layout_order'],
            );
        }
        if (!$groups) { return ''; }

        ob_start();
        foreach ($groups as $choices) :
            usort($choices, static function($a, $b) {
                return ((int)$a['layout_order'] <=> (int)$b['layout_order']) ?: strcmp((string)$a['reciter'], (string)$b['reciter']);
            });
            $first = $choices[0];
            $segment = 'segment' === $first['scope'];
            $chooser = count($choices) > 1;
            $label = $segment ? ($first['segment_title'] ?: __('فراز دعا', 'hamnoor')) : __('قرائت کامل دعا', 'hamnoor');
            ?>
            <section class="hamnoor-dua-audio <?php echo $segment ? 'hamnoor-dua-audio-segment ' : ''; ?><?php echo $chooser ? 'hamnoor-dua-audio-chooser' : 'hamnoor-dua-audio-single'; ?>" data-dua-audio-player aria-label="<?php echo esc_attr($segment ? __('صوت این فراز', 'hamnoor') : __('صوت کامل دعا', 'hamnoor')); ?>">
                <div class="hamnoor-dua-audio-meta">
                    <small><?php echo esc_html($segment ? __('صوت این فراز', 'hamnoor') : __('صوت کامل دعا', 'hamnoor')); ?></small>
                    <strong><?php echo esc_html($label); ?></strong>
                    <span data-dua-audio-reciter-name><?php echo esc_html($first['reciter']); ?></span>
                </div>
                <?php if ($chooser) : ?>
                    <label class="hamnoor-dua-audio-reciter">
                        <span><?php esc_html_e('انتخاب خواننده', 'hamnoor'); ?></span>
                        <select data-dua-audio-select aria-label="<?php echo esc_attr(sprintf(__('انتخاب خواننده برای %s', 'hamnoor'), $label)); ?>">
                            <?php foreach ($choices as $i=>$choice) : ?><option value="<?php echo (int)$i; ?>"><?php echo esc_html($choice['reciter']); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <audio controls preload="none" data-dua-audio-element src="<?php echo esc_url($first['url']); ?>"></audio>
                <script type="application/json" data-dua-audio-data><?php echo wp_json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
                <p class="hamnoor-dua-audio-message" data-dua-audio-message aria-live="polite"></p>
            </section>
            <?php
        endforeach;
        return ob_get_clean();
    }

    private static function reader_toolbar($pending) {
        ob_start(); ?><div class="hamnoor-dua-reader-toolbar" data-dua-reader-toolbar role="toolbar" aria-label="<?php esc_attr_e('ابزارهای مطالعه دعا', 'hamnoor'); ?>">
            <button type="button" data-dua-reader-action="theme" aria-label="<?php esc_attr_e('تغییر حالت رنگ', 'hamnoor'); ?>"><?php echo self::reader_icon('moon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('حالت رنگ', 'hamnoor'); ?></span></button>
            <button type="button" data-dua-reader-action="translation" aria-pressed="true"><?php echo self::reader_icon('translation'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('ترجمه', 'hamnoor'); ?></span></button>
            <button type="button" data-dua-reader-action="font-down" aria-label="<?php esc_attr_e('کوچک کردن قلم', 'hamnoor'); ?>"><?php echo self::reader_icon('minus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('کوچک‌تر', 'hamnoor'); ?></span></button>
            <button type="button" data-dua-reader-action="font-up" aria-label="<?php esc_attr_e('بزرگ کردن قلم', 'hamnoor'); ?>"><?php echo self::reader_icon('plus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('بزرگ‌تر', 'hamnoor'); ?></span></button>
            <button type="button" data-dua-reader-action="focus" aria-pressed="false"><?php echo self::reader_icon('focus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('تمرکز', 'hamnoor'); ?></span></button>
            <button type="button" data-dua-reader-action="wake" aria-pressed="false"><?php echo self::reader_icon('sun'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e('روشن‌ماندن', 'hamnoor'); ?></span></button>
        </div><div class="hamnoor-dua-reading-progress" aria-hidden="true"><span></span></div>
        <?php if (!$pending) : ?><button type="button" class="hamnoor-dua-read-later-top" data-dua-pending-proxy><?php esc_html_e('برای بعد انتخاب می‌کنم', 'hamnoor'); ?></button><?php else : ?><span class="hamnoor-dua-read-later-top is-pending" data-dua-pending-proxy><?php esc_html_e('برای بعد انتخاب شده است', 'hamnoor'); ?></span><?php endif; ?>
        <?php return ob_get_clean();
    }

    private static function status_notice($campaign, $progress) {
        $status = (string)$campaign->lifecycle_status;
        if (!empty($progress['is_completed']) || Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED === $status) {
            return '<div class="hamnoor-notice hamnoor-notice-success" data-contribution-completed="1">' . esc_html__('هدف این پویش تکمیل شده است. خداوند از همه همراهان قبول فرماید.', 'hamnoor') . '</div>';
        }
        if (Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED === $status) {
            return '<div class="hamnoor-notice hamnoor-notice-info">' . esc_html__('ثبت مشارکت جدید در این پویش موقتاً متوقف است.', 'hamnoor') . '</div>';
        }
        if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== $status) {
            return '<div class="hamnoor-notice hamnoor-notice-warning">' . esc_html__('این پویش در حال حاضر مشارکت جدید نمی‌پذیرد.', 'hamnoor') . '</div>';
        }
        return '';
    }

    private static function contribution_form($campaign, $item, $progress) {
        if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status || !empty($progress['is_completed'])) { return ''; }
        $numeric = Hamnoor_Campaign_Domain::STRATEGY_NUMERIC === (string)$campaign->participation_strategy;
        $remaining = max(0, (int)$progress['target'] - (int)$progress['current']);
        $max = $remaining;
        if ($numeric && $item && !empty($item->max_contribution)) { $max = min($max, (int)$item->max_contribution); }
        $max = max(1, $max);
        $current_user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $pending_dua = !$numeric && $current_user_id ? Hamnoor_Contribution_Service::pending_dua_for_user((int)$campaign->id, $current_user_id) : null;

        ob_start(); ?>
        <section class="hamnoor-contribution-action-card" data-contribution-campaign="<?php echo (int)$campaign->id; ?>" data-contribution-strategy="<?php echo esc_attr((string)$campaign->participation_strategy); ?>">
            <div class="hamnoor-contribution-action-copy">
                <h2><?php echo esc_html($numeric ? __('مشارکت در ذکر', 'hamnoor') : __('ثبت قرائت دعا', 'hamnoor')); ?></h2>
                <p><?php echo esc_html($numeric ? __('تعداد انجام‌شده را وارد کنید. هم‌نور مقدار نهایی را با سقف مجاز و باقیمانده هدف هماهنگ می‌کند.', 'hamnoor') : __('هر ثبت در این پویش برابر یک قرائت کامل از دعای بالا است.', 'hamnoor')); ?></p>
            </div>
            <form class="hamnoor-contribution-form" novalidate>
                <input type="hidden" name="khatm_token" value="<?php echo esc_attr((string)$campaign->token); ?>">
                <input type="hidden" name="idempotency_key" value="">
                <input type="hidden" name="intent" value="<?php echo esc_attr($pending_dua ? 'complete_dua' : 'record'); ?>">
                <input type="hidden" name="contribution_public_id" value="<?php echo esc_attr($pending_dua ? (string)$pending_dua->public_id : ''); ?>">
                <input class="hamnoor-honeypot" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
                <div class="hamnoor-contribution-identity" data-contribution-identity aria-live="polite">
                    <div class="hamnoor-notice hamnoor-notice-info"><?php echo esc_html__('در حال شناسایی پنل هم‌نور…', 'hamnoor'); ?></div>
                </div>
                <?php if ($numeric) : ?>
                    <label class="hamnoor-contribution-quantity">
                        <span><?php echo esc_html__('تعداد این مشارکت', 'hamnoor'); ?></span>
                        <input type="number" name="quantity" min="1" max="<?php echo (int)$max; ?>" value="1" inputmode="numeric" required>
                        <small data-contribution-limit><?php echo esc_html(sprintf(__('حداکثر قابل ثبت در این نوبت: %d', 'hamnoor'), (int)$max)); ?></small>
                    </label>
                <?php else : ?>
                    <input type="hidden" name="quantity" value="1">
                <?php endif; ?>
                <div class="hamnoor-ajax-message" aria-live="polite"></div>
                <?php if (!$numeric && !$pending_dua) : ?>
                    <button type="submit" class="hamnoor-btn hamnoor-btn-secondary" data-contribution-intent="select_dua" data-dua-pending-authoritative disabled><?php esc_html_e('برای بعد انتخاب می‌کنم', 'hamnoor'); ?></button>
                <?php endif; ?>
                <button type="submit" class="hamnoor-btn hamnoor-btn-primary" data-contribution-intent="<?php echo esc_attr($pending_dua ? 'complete_dua' : 'record'); ?>" disabled>
                    <?php echo esc_html($numeric ? __('ثبت این تعداد', 'hamnoor') : ($pending_dua ? __('قرائت کامل شد؛ ثبت کن', 'hamnoor') : __('همین حالا کامل خواندم', 'hamnoor'))); ?>
                </button>
            </form>
        </section>
        <?php return ob_get_clean();
    }

    public static function render($campaign) {
        $progress = self::normalized_progress($campaign);
        $item = !empty($campaign->content_item_id) ? Hamnoor_Content_Repository::get((int)$campaign->content_item_id) : null;
        $numeric = Hamnoor_Campaign_Domain::STRATEGY_NUMERIC === (string)$campaign->participation_strategy;
        $unit = $numeric ? __('مرتبه', 'hamnoor') : __('قرائت کامل', 'hamnoor');
        $url = Hamnoor_Helpers::share_invite_url($campaign, 'web');
        $status = (string)$campaign->status;
        $reader_user_id = (int)Hamnoor_Mobile_Access::current_user_id();
        $reader_pending = !$numeric && $reader_user_id ? Hamnoor_Contribution_Service::pending_dua_for_user((int)$campaign->id, $reader_user_id) : null;

        ob_start(); ?>
        <div class="hamnoor-contribution-page<?php echo $numeric ? '' : ' hamnoor-dua-reader'; ?>" data-hamnoor-contribution-page="1"<?php echo $numeric ? '' : ' data-dua-reader="1" data-dua-theme="light"'; ?>>
            <section class="hamnoor-khatm-header">
                <?php $cover = Hamnoor_Helpers::cover_url($campaign); ?>
                <?php if ($cover) : ?><img class="hamnoor-cover" src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr((string)$campaign->title); ?>"><?php else : ?><div class="hamnoor-cover hamnoor-cover-placeholder" aria-hidden="true"></div><?php endif; ?>
                <div class="hamnoor-khatm-header-body">
                    <div class="hamnoor-status-row"><span class="hamnoor-badge hamnoor-status-<?php echo esc_attr($status); ?>" data-contribution-status><?php echo esc_html(Hamnoor_Helpers::status_label($status, 'khatm')); ?></span></div>
                    <h1><?php echo esc_html((string)$campaign->title); ?></h1>
                    <?php if (!empty($campaign->intention)) : ?><div class="hamnoor-intention"><?php echo wp_kses_post((string)$campaign->intention); ?></div><?php endif; ?>
                </div>
            </section>

            <section class="hamnoor-progress-card hamnoor-contribution-progress" aria-label="<?php echo esc_attr__('پیشرفت پویش', 'hamnoor'); ?>">
                <div class="hamnoor-progress-number"><strong data-contribution-percent><?php echo esc_html(rtrim(rtrim(number_format((float)$progress['percentage'], 2, '.', ''), '0'), '.')); ?>%</strong><span><?php echo esc_html__('پیشرفت', 'hamnoor'); ?></span></div>
                <div class="hamnoor-progress-body">
                    <div class="hamnoor-progress"><span data-contribution-bar style="width:<?php echo esc_attr((float)$progress['percentage']); ?>%"></span></div>
                    <div class="hamnoor-progress-meta"><span data-contribution-progress-text><?php echo esc_html(sprintf(__('%1$d از %2$d %3$s ثبت شده است', 'hamnoor'), (int)$progress['current'], (int)$progress['target'], $unit)); ?></span></div>
                </div>
            </section>

            <div data-contribution-status-notice><?php echo self::status_notice($campaign, $progress); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <?php if (!$numeric) { echo self::reader_toolbar((bool)$reader_pending); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::content_html($item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::contribution_form($campaign, $item, $progress); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if ('public' === (string)$campaign->privacy && in_array($status, array('active','paused','completed','expired'), true)) : ?>
                <section class="hamnoor-share-panel">
                    <h2><?php echo esc_html__('اشتراک‌گذاری پویش', 'hamnoor'); ?></h2>
                    <div class="hamnoor-share-box"><label><?php echo esc_html__('لینک دعوت', 'hamnoor'); ?></label><input readonly value="<?php echo esc_attr($url); ?>"><button type="button" class="hamnoor-btn hamnoor-btn-secondary hamnoor-copy"><?php echo esc_html__('کپی لینک', 'hamnoor'); ?></button></div>
                </section>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    private static function send_error($error, $status = 400) {
        if (is_wp_error($error)) {
            wp_send_json_error(array('message'=>$error->get_error_message(), 'code'=>$error->get_error_code()), $status);
        }
        wp_send_json_error(array('message'=>__('درخواست انجام نشد.', 'hamnoor')), $status);
    }

    public static function ajax_record() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        nocache_headers();
        if (!empty($_POST['website'])) { self::send_error(new WP_Error('hamnoor_contribution_invalid', __('درخواست نامعتبر است.', 'hamnoor'))); }

        $token = isset($_POST['khatm_token']) ? Hamnoor_Helpers::sanitize_token(wp_unslash($_POST['khatm_token'])) : '';
        $campaign = $token ? Hamnoor_Khatm_Service::get($token) : null;
        if (!$campaign || !self::is_contribution_campaign($campaign)) {
            self::send_error(new WP_Error('hamnoor_contribution_campaign_invalid', __('پویش مشارکتی معتبر نیست.', 'hamnoor')), 404);
        }
        if (!Hamnoor_Permissions::can_view_khatm($campaign) || !Hamnoor_Permissions::can_preview_unpublished_khatm($campaign)) {
            self::send_error(new WP_Error('hamnoor_contribution_campaign_hidden', __('این پویش برای مشارکت در دسترس نیست.', 'hamnoor')), 403);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $phone = isset($_POST['phone']) ? Hamnoor_Validator::normalize_phone(wp_unslash($_POST['phone'])) : '';
        $identity = Hamnoor_Mobile_Access::resolve_claim_identity($phone, $name);
        if (is_wp_error($identity)) { self::send_error($identity, 403); }
        $phone = (string)$identity['phone'];
        $name = (string)$identity['name'];

        $rate = Hamnoor_Helpers::rate_limit('contribution', (int)$campaign->id . '|' . $phone, 60, 10 * MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) { self::send_error($rate, 429); }

        $user = Hamnoor_Mobile_Access::ensure_and_login_for_claim($phone, $name);
        if (is_wp_error($user)) { self::send_error($user, 422); }

        $payload = array(
            'quantity'=>isset($_POST['quantity']) ? (int)Hamnoor_Validator::normalize_digits(wp_unslash($_POST['quantity'])) : 0,
            'idempotency_key'=>isset($_POST['idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['idempotency_key'])) : '',
        );
        $intent = isset($_POST['intent']) ? sanitize_key(wp_unslash($_POST['intent'])) : 'record';
        if ('select_dua' === $intent) {
            if (Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE !== (string)$campaign->participation_strategy) { self::send_error(new WP_Error('hamnoor_dua_selection_invalid', __('این انتخاب فقط برای پویش دعا در دسترس است.', 'hamnoor')), 422); }
            $row = Hamnoor_Contribution_Service::select_dua_for_later((int)$campaign->id, (int)$user->id, (string)$payload['idempotency_key'], array('source'=>'public_web'));
            if (is_wp_error($row)) { self::send_error($row, 409); }
            if (!empty($row->_created)) {
                Hamnoor_Reminder_Schedule_Service::schedule_pending_dua($row, (int)$user->id);
                Hamnoor_DB::add_notification((int)$user->id, 'dua_selected', __('دعا برای بعد ثبت شد', 'hamnoor'), __('این دعا در «مشارکت‌های من» آماده ادامه و تکمیل است.', 'hamnoor'), 'contribution', (int)$row->id);
            }
            wp_send_json_success(array('message'=>__('این دعا برای شما ثبت شد و می‌توانید بعداً از «مشارکت‌های من» ادامه دهید.', 'hamnoor'),'pending'=>1,'contribution_public_id'=>(string)$row->public_id,'user'=>array('display_name'=>(string)$user->display_name,'phone'=>(string)$user->phone)));
        }
        if ('complete_dua' === $intent) {
            $public_id = isset($_POST['contribution_public_id']) ? sanitize_text_field(wp_unslash($_POST['contribution_public_id'])) : '';
            $row = Hamnoor_Contribution_Service::complete_pending_dua($public_id, (int)$user->id);
            if (is_wp_error($row)) { self::send_error($row, 409); }
            Hamnoor_Reminder_Schedule_Service::close_pending_dua((int)$row->id, 'completed');
            $extension_feedback = Hamnoor_Domain_Events::completion_extension_feedback(
                'contribution',
                Hamnoor_Domain_Events::stable_entity_id(isset($row->public_id) ? $row->public_id : '', 'contribution', (int)$row->id)
            );
            if (!empty($row->_transitioned)) { Hamnoor_DB::add_notification((int)$user->id, 'dua_completed', __('قرائت دعا ثبت شد', 'hamnoor'), __('قرائت کامل دعای شما با موفقیت ثبت شد.', 'hamnoor'), 'contribution', (int)$row->id); }
            $updated = Hamnoor_Campaign_Repository::get_by_id((int)$campaign->id); $progress = self::normalized_progress($updated ?: $campaign);
            wp_send_json_success(array('message'=>__('قرائت کامل دعای شما با موفقیت ثبت شد. خداوند قبول فرماید.', 'hamnoor'),'progress'=>$progress,'completed'=>!empty($progress['is_completed']) ? 1 : 0,'pending'=>0,'recorded_quantity'=>1,'next_max'=>0,'user'=>array('display_name'=>(string)$user->display_name,'phone'=>(string)$user->phone),'extension_feedback'=>$extension_feedback));
        }
        $command = Hamnoor_Command_Contracts::contribution($campaign, $payload);
        if (is_wp_error($command)) { self::send_error($command, 422); }

        $row = Hamnoor_Contribution_Service::record(
            (int)$campaign->id,
            (int)$user->id,
            (int)$command['quantity'],
            (string)$command['kind'],
            (string)$command['idempotency_key'],
            0,
            array('source'=>'public_web', 'adapter'=>'contribution_frontend_v1')
        );
        if (is_wp_error($row)) { self::send_error($row, 409); }
        $extension_feedback = Hamnoor_Domain_Events::completion_extension_feedback(
            'contribution',
            Hamnoor_Domain_Events::stable_entity_id(isset($row->public_id) ? $row->public_id : '', 'contribution', (int)$row->id)
        );

        $numeric = Hamnoor_Campaign_Domain::STRATEGY_NUMERIC === (string)$campaign->participation_strategy;
        if (!empty($row->_created)) { Hamnoor_DB::add_notification((int)$user->id, $numeric ? 'dhikr_contributed' : 'dua_completed', $numeric ? __('مشارکت ذکر ثبت شد', 'hamnoor') : __('قرائت دعا ثبت شد', 'hamnoor'), $numeric ? sprintf(__('%d مرتبه با موفقیت ثبت شد.', 'hamnoor'), (int)$row->quantity) : __('قرائت کامل دعای شما با موفقیت ثبت شد.', 'hamnoor'), 'contribution', (int)$row->id); }

        $updated = Hamnoor_Campaign_Repository::get_by_id((int)$campaign->id);
        $progress = self::normalized_progress($updated ?: $campaign);
        $completed = !empty($progress['is_completed']) || ($updated && Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED === (string)$updated->lifecycle_status);
        $message = $numeric
            ? sprintf(__('%d مرتبه با موفقیت ثبت شد.', 'hamnoor'), (int)$row->quantity)
            : __('قرائت کامل شما با موفقیت ثبت شد. خداوند قبول فرماید.', 'hamnoor');
        if ($completed) { $message .= ' ' . __('هدف پویش نیز تکمیل شد.', 'hamnoor'); }

        $item = !empty($campaign->content_item_id) ? Hamnoor_Content_Repository::get((int)$campaign->content_item_id) : null;
        $remaining = max(0, (int)$progress['target'] - (int)$progress['current']);
        $next_max = $remaining;
        if ($numeric && $item && !empty($item->max_contribution)) { $next_max = min($next_max, (int)$item->max_contribution); }

        wp_send_json_success(array(
            'message'=>$message,
            'recorded_quantity'=>(int)$row->quantity,
            'progress'=>$progress,
            'completed'=>$completed ? 1 : 0,
            'status'=>$updated ? (string)$updated->status : (string)$campaign->status,
            'lifecycle_status'=>$updated ? (string)$updated->lifecycle_status : (string)$campaign->lifecycle_status,
            'next_max'=>max(0, (int)$next_max),
            'user'=>array('display_name'=>(string)$user->display_name, 'phone'=>(string)$user->phone),
            'extension_feedback'=>$extension_feedback,
        ));
    }
}
