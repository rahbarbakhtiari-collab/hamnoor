<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Participant-facing read adapter for the canonical Contribution Ledger.
 *
 * Transport is available to WordPress guests because Hamnoor authentication is
 * cookie/session based and intentionally separate from wp-login. The handler
 * never accepts a user id: nonce + current Hamnoor session are authoritative.
 */
final class Hamnoor_Participant_Contribution_History {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_action('hamnoor_enqueue_frontend_integrations', array(__CLASS__, 'enqueue_assets'), 20);
        add_action('wp_ajax_hamnoor_get_contribution_history', array(__CLASS__, 'ajax_history'));
        add_action('wp_ajax_nopriv_hamnoor_get_contribution_history', array(__CLASS__, 'ajax_history'));
    }

    public static function enqueue_assets($context = array()) {
        wp_enqueue_style(
            'hamnoor-participant-contribution-history',
            HAMNOOR_URL . 'assets/css/hamnoor-participant-contribution-history.css',
            array('hamnoor-ui-v4'),
            Hamnoor_Helpers::asset_version('assets/css/hamnoor-participant-contribution-history.css')
        );
        wp_enqueue_script(
            'hamnoor-participant-contribution-history',
            HAMNOOR_URL . 'assets/js/hamnoor-participant-contribution-history.js',
            array('hamnoor-frontend'),
            Hamnoor_Helpers::asset_version('assets/js/hamnoor-participant-contribution-history.js'),
            true
        );
        wp_enqueue_script('hamnoor-unified-shares', HAMNOOR_URL . 'assets/js/hamnoor-unified-shares.js', array('hamnoor-participant-contribution-history'), Hamnoor_Helpers::asset_version('assets/js/hamnoor-unified-shares.js'), true);
        $nonce = is_array($context) && !empty($context['nonce']) ? (string)$context['nonce'] : wp_create_nonce('hamnoor_frontend');
        wp_localize_script('hamnoor-participant-contribution-history', 'HamnoorContributionHistory', array(
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'=>$nonce,
            'per_page'=>20,
            'labels'=>array(
                'tab'=>__('مشارکت‌های من', 'hamnoor'),
                'mobile'=>__('سابقه دعا و ذکر', 'hamnoor'),
                'title'=>__('مشارکت‌های من در دعا و ذکر', 'hamnoor'),
                'description'=>__('ثبت‌های انجام‌شده شما مستقیماً از دفتر مشارکت هم‌نور نمایش داده می‌شوند.', 'hamnoor'),
                'empty'=>__('هنوز مشارکتی در پویش‌های دعا یا ذکر ثبت نکرده‌اید.', 'hamnoor'),
                'error'=>__('دریافت سابقه مشارکت‌ها انجام نشد. دوباره تلاش کنید.', 'hamnoor'),
                'loading'=>__('در حال دریافت سابقه مشارکت‌ها…', 'hamnoor'),
                'previous'=>__('قبلی', 'hamnoor'),
                'next'=>__('بعدی', 'hamnoor'),
                'dua'=>__('دعا', 'hamnoor'),
                'dhikr'=>__('ذکر', 'hamnoor'),
                'completion'=>__('۱ قرائت کامل', 'hamnoor'),
                'times'=>__('مرتبه', 'hamnoor'),
                'total'=>__('مشارکت ثبت‌شده', 'hamnoor'),
                'pending'=>__('در انتظار قرائت', 'hamnoor'),
                'continue'=>__('ادامه و تکمیل قرائت', 'hamnoor'),
            ),
        ));
    }

    public static function ajax_history() {
        check_ajax_referer('hamnoor_frontend', 'nonce');
        if (!Hamnoor_Mobile_Access::current_user_id()) {
            wp_send_json_error(array('code'=>'hamnoor_auth_required'), 401);
        }
        $page = isset($_POST['page']) ? max(1, (int)Hamnoor_Validator::normalize_digits(wp_unslash($_POST['page']))) : 1;
        $history = Hamnoor_Contribution_Service::history_for_current_user($page, 20);
        $items = array();
        foreach ((array)$history['items'] as $row) {
            $items[] = array(
                'public_id'=>(string)$row->public_id,
                'campaign_public_id'=>(string)$row->campaign_public_id,
                'campaign_title'=>(string)$row->campaign_title,
                'content_type'=>(string)$row->content_type,
                'kind'=>(string)$row->kind,
                'status'=>(string)$row->status,
                'quantity'=>(int)$row->quantity,
                'occurred_at'=>(string)$row->occurred_at_utc,
                'display_date'=>Hamnoor_Jalali::to_jalali((string)$row->occurred_at_utc, true),
                'campaign_url'=>Hamnoor_Helpers::invite_url((string)$row->campaign_token),
            );
        }
        wp_send_json_success(array(
            'items'=>$items,
            'total'=>(int)$history['total'],
            'page'=>(int)$history['page'],
            'pages'=>(int)$history['pages'],
            'per_page'=>(int)$history['per_page'],
        ));
    }
}
