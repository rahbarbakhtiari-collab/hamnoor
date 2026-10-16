<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Stable Campaign API. V2 never exposes internal database IDs as public identity.
 * V1 remains a compatibility adapter for the existing Quran frontend/integrations.
 */
final class Hamnoor_REST_API_V2 {
    const NAMESPACE = 'hamnoor/v2';

    public static function init() { add_action('rest_api_init', array(__CLASS__, 'register_routes')); }
    public static function enabled() { return Hamnoor_REST_API::enabled(); }

    public static function register_routes() {
        if (!self::enabled()) { return; }
        register_rest_route(self::NAMESPACE, '/campaigns', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array(__CLASS__, 'list_campaigns'),
            'permission_callback'=>'__return_true',
            'args'=>array(
                'search'=>array('type'=>'string','sanitize_callback'=>'sanitize_text_field'),
                'content_type'=>array('type'=>'string','sanitize_callback'=>'sanitize_key'),
                'page'=>array('type'=>'integer','sanitize_callback'=>'absint','default'=>1),
                'per_page'=>array('type'=>'integer','sanitize_callback'=>'absint','default'=>20),
            ),
        ));
        register_rest_route(self::NAMESPACE, '/campaigns/(?P<id>CMP-[A-Za-z0-9]{12,28})', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array(__CLASS__, 'get_campaign'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/me/campaigns', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array(__CLASS__, 'me_campaigns'),
            'permission_callback'=>array(__CLASS__, 'can_use_low_assurance'),
        ));
        register_rest_route(self::NAMESPACE, '/me/claims', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array(__CLASS__, 'me_claims'),
            'permission_callback'=>array(__CLASS__, 'can_use_low_assurance'),
        ));
        register_rest_route(self::NAMESPACE, '/docs', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array(__CLASS__, 'docs'),
            'permission_callback'=>'__return_true',
        ));
    }

    public static function can_use_low_assurance() {
        return Hamnoor_Mobile_Access::is_logged_in() && Hamnoor_Access_Policy::allows('campaign_view');
    }

    private static function envelope($data, $meta = array()) {
        return rest_ensure_response(array(
            'success'=>true,
            'api_version'=>'2.0',
            'schema'=>'campaign-v2',
            'data'=>$data,
            'meta'=>(object)$meta,
        ));
    }

    private static function visible_to_current_subject($campaign) {
        if (!$campaign) { return false; }
        $is_owner = Hamnoor_DB::user_can_manage_khatm((int)$campaign->id);
        if ($is_owner || current_user_can('manage_options') || current_user_can('hamnoor_manage_all_khatms')) { return true; }
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)$campaign->access_mode) { return false; }
        if (Hamnoor_Campaign_Domain::REVIEW_APPROVED !== (string)$campaign->review_status) { return false; }
        return in_array((string)$campaign->lifecycle_status, array(
            Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,
            Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED,
            Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,
        ), true);
    }

    private static function campaign_dto($campaign, $owner_context = false) {
        $progress = Hamnoor_Campaign_Repository::progress($campaign);
        if (is_wp_error($progress)) { $progress = array('current'=>0,'target'=>0,'percentage'=>0,'is_completed'=>false); }
        $dto = array(
            'id'=>(string)$campaign->campaign_public_id,
            'content_type'=>(string)$campaign->content_type,
            'participation_strategy'=>(string)$campaign->participation_strategy,
            'access_mode'=>(string)$campaign->access_mode,
            'participation_policy'=>(string)$campaign->participation_policy,
            'lifecycle_status'=>(string)$campaign->lifecycle_status,
            'review_status'=>(string)$campaign->review_status,
            'title'=>(string)$campaign->title,
            'intention'=>(string)$campaign->intention,
            'description'=>(string)$campaign->description,
            'progress'=>array(
                'current'=>(int)($progress['current'] ?? 0),
                'target'=>(int)($progress['target'] ?? 0),
                'percentage'=>(float)($progress['percentage'] ?? 0),
                'is_completed'=>!empty($progress['is_completed']),
            ),
            'starts_at'=>!empty($campaign->starts_at) ? mysql_to_rfc3339((string)$campaign->starts_at) : null,
            'ends_at'=>!empty($campaign->ends_at) ? mysql_to_rfc3339((string)$campaign->ends_at) : null,
            'created_at'=>!empty($campaign->created_at) ? mysql_to_rfc3339((string)$campaign->created_at) : null,
        );
        if ($owner_context) {
            $dto['first_participation_at'] = !empty($campaign->first_participation_at) ? mysql_to_rfc3339((string)$campaign->first_participation_at) : null;
            $dto['structure_locked'] = !empty($campaign->first_participation_at);
        }
        return $dto;
    }

    private static function claim_dto($row) {
        return array(
            'id'=>(string)$row->public_id,
            'campaign_id'=>(string)$row->campaign_public_id,
            'status'=>(string)$row->status,
            'title'=>(string)$row->title,
            'part_type'=>(string)$row->part_type,
            'part_number'=>(int)$row->part_number,
            'round_number'=>(int)$row->round_number,
            'claimed_at'=>!empty($row->claimed_at) ? mysql_to_rfc3339((string)$row->claimed_at) : null,
            'completed_at'=>!empty($row->done_at) ? mysql_to_rfc3339((string)$row->done_at) : null,
        );
    }

    public static function list_campaigns($request) {
        $page = max(1, (int)$request->get_param('page'));
        $per_page = max(1, min(50, (int)$request->get_param('per_page')));
        $type = sanitize_key((string)$request->get_param('content_type'));
        if ($type && !in_array($type, Hamnoor_Campaign_Domain::content_types(), true)) { return new WP_Error('hamnoor_v2_content_type_invalid', __('نوع محتوا معتبر نیست.', 'hamnoor'), array('status'=>400)); }
        // Public discovery deliberately excludes unlisted/owner-only campaigns.
        $rows = Hamnoor_Campaign_Repository::query(array(
            'content_type'=>$type,
            'access_mode'=>Hamnoor_Campaign_Domain::ACCESS_PUBLIC,
            'review_status'=>Hamnoor_Campaign_Domain::REVIEW_APPROVED,
            'lifecycle_status'=>Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,
            'search'=>$request->get_param('search'),
            'limit'=>$per_page,
            'offset'=>($page-1)*$per_page,
        ));
        return self::envelope(array_values(array_map(function($row) { return self::campaign_dto($row, false); }, $rows)), array('page'=>$page,'per_page'=>$per_page));
    }

    public static function get_campaign($request) {
        $campaign = Hamnoor_Campaign_Repository::get_by_public_id((string)$request['id']);
        if (!$campaign || !self::visible_to_current_subject($campaign)) { return new WP_Error('hamnoor_v2_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor'), array('status'=>404)); }
        $owner = Hamnoor_DB::user_can_manage_khatm((int)$campaign->id);
        return self::envelope(self::campaign_dto($campaign, $owner));
    }

    public static function me_campaigns() {
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $rows = Hamnoor_Campaign_Repository::query(array('owner_user_id'=>$user_id,'limit'=>100));
        return self::envelope(array_values(array_map(function($row) { return self::campaign_dto($row, true); }, $rows)));
    }

    public static function me_claims() {
        global $wpdb;
        $user_id = Hamnoor_Mobile_Access::current_user_id();
        $parts = Hamnoor_DB::table('parts');
        $participants = Hamnoor_DB::table('participants');
        $campaigns = Hamnoor_DB::table('khatms');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.public_id,p.status,p.title,p.part_type,p.part_number,p.round_number,p.claimed_at,p.done_at,k.campaign_public_id
             FROM $parts p INNER JOIN $participants r ON r.id=p.participant_id INNER JOIN $campaigns k ON k.id=p.khatm_id
             WHERE r.user_id=%d ORDER BY COALESCE(p.claimed_at,p.created_at) DESC LIMIT 200",
            (int)$user_id
        ));
        return self::envelope(array_values(array_map(array(__CLASS__, 'claim_dto'), (array)$rows)));
    }

    public static function docs() {
        return self::envelope(array(
            'identifier'=>'All Campaign v2 resources use stable public IDs. Internal WordPress/MySQL integer IDs are implementation details.',
            'writes'=>'Public REST mutations are intentionally not exposed in the foundation release. The stable mutation boundary is Hamnoor_Command_Contracts v1 plus canonical domain services; REST/AJAX adapters must not write tables directly.',
            'command_contract_version'=>Hamnoor_Command_Contracts::VERSION,
            'auth'=>'Normal /me reads accept the low-assurance Hamnoor session. Sensitive future writes must pass Hamnoor_Access_Policy step-up checks.',
            'routes'=>array('/hamnoor/v2/campaigns','/hamnoor/v2/campaigns/{CMP-public-id}','/hamnoor/v2/me/campaigns','/hamnoor/v2/me/claims'),
        ));
    }
}
