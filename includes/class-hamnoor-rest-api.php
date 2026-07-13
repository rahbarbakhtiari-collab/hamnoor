<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_REST_API {
    public static function init() { add_action('rest_api_init', array(__CLASS__, 'register_routes')); }

    public static function enabled() {
        $settings = Hamnoor_Helpers::settings();
        return apply_filters('hamnoor_android_api_enabled', !empty($settings['rest_api_enabled']) && '1' === $settings['rest_api_enabled']);
    }

    public static function register_routes() {
        if (!self::enabled()) { return; }
        register_rest_route('hamnoor/v1', '/khatms', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_khatms'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'search' => array('type'=>'string', 'sanitize_callback'=>'sanitize_text_field'),
                    'page' => array('type'=>'integer', 'sanitize_callback'=>'absint', 'default'=>1),
                    'per_page' => array('type'=>'integer', 'sanitize_callback'=>'absint', 'default'=>20),
                ),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'create_khatm'),
                'permission_callback' => array(__CLASS__, 'can_create'),
                'args' => self::khatm_args_schema(),
            ),
        ));
        register_rest_route('hamnoor/v1', '/khatms/(?P<token>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'get_khatm'),
            'permission_callback' => '__return_true',
            'args' => array('token'=>array('sanitize_callback'=>array('Hamnoor_Helpers','sanitize_token'))),
        ));
        register_rest_route('hamnoor/v1', '/parts/(?P<id>\d+)/claim', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'claim_part'),
            'permission_callback' => '__return_true',
            'args' => self::claim_args_schema(),
        ));
        register_rest_route('hamnoor/v1', '/khatms/(?P<id>\d+)/claim-random', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'claim_random'),
            'permission_callback' => '__return_true',
            'args' => self::claim_args_schema(true),
        ));
        register_rest_route('hamnoor/v1', '/khatms/(?P<id>\d+)/claim-next', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'claim_next'),
            'permission_callback' => '__return_true',
            'args' => self::claim_args_schema(true),
        ));
        register_rest_route('hamnoor/v1', '/parts/(?P<id>\d+)/complete', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'complete_part'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array('sanitize_callback'=>'absint'),
                'tracking_token' => array('type'=>'string', 'sanitize_callback'=>'sanitize_text_field'),
            ),
        ));
        register_rest_route('hamnoor/v1', '/me/parts', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'me_parts'), 'permission_callback' => array(__CLASS__, 'is_logged_in')));
        register_rest_route('hamnoor/v1', '/me/khatms', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'me_khatms'), 'permission_callback' => array(__CLASS__, 'is_logged_in')));
        register_rest_route('hamnoor/v1', '/khatms/(?P<id>\d+)/participants', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'participants'), 'permission_callback' => array(__CLASS__, 'can_manage_khatm'), 'args'=>array('id'=>array('sanitize_callback'=>'absint'))));
        register_rest_route('hamnoor/v1', '/khatms/(?P<id>\d+)/export', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'export_link'), 'permission_callback' => array(__CLASS__, 'can_manage_khatm'), 'args'=>array('id'=>array('sanitize_callback'=>'absint'))));
        register_rest_route('hamnoor/v1', '/docs', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'docs'), 'permission_callback' => '__return_true'));
    }

    private static function khatm_args_schema() {
        return array(
            'title'=>array('required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'),
            'owner_phone'=>array('required'=>true,'type'=>'string','sanitize_callback'=>array('Hamnoor_Validator','normalize_phone')),
            'owner_email'=>array('required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_email'),
            'owner_name'=>array('type'=>'string','sanitize_callback'=>'sanitize_text_field'),
            'khatm_type'=>array('type'=>'string','sanitize_callback'=>'sanitize_key'),
            'khatm_count'=>array('type'=>'integer','sanitize_callback'=>'absint'),
            'selected_surah_number'=>array('type'=>'integer','sanitize_callback'=>'absint'),
            'repeat_count'=>array('type'=>'integer','sanitize_callback'=>'absint'),
            'privacy'=>array('type'=>'string','sanitize_callback'=>'sanitize_key'),
            'indexable'=>array('type'=>'boolean'),
            'intention'=>array('type'=>'string','sanitize_callback'=>'wp_kses_post'),
            'description'=>array('type'=>'string','sanitize_callback'=>'wp_kses_post'),
        );
    }

    private static function claim_args_schema($random = false) {
        $args = array(
            'id'=>array('sanitize_callback'=>'absint'),
            'name'=>array('required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'),
            'phone'=>array('required'=>true,'type'=>'string','sanitize_callback'=>array('Hamnoor_Validator','normalize_phone')),
            'email'=>array('type'=>'string','sanitize_callback'=>'sanitize_email'),
        );
        if ($random) { $args['round_number'] = array('type'=>'integer','sanitize_callback'=>'absint'); }
        return $args;
    }

    private static function ok($data = array(), $message = '') { return rest_ensure_response(array('success'=>true,'version'=>'v1','message'=>$message,'data'=>$data)); }
    private static function rate($scope, $identity = '', $limit = 20) { return Hamnoor_Helpers::rate_limit('api_' . sanitize_key($scope), $identity, $limit, 10 * MINUTE_IN_SECONDS); }
    public static function is_logged_in() { return is_user_logged_in(); }
    public static function can_create() { return is_user_logged_in() && Hamnoor_Permissions::can_create_khatm() && Hamnoor_Permissions::new_khatm_creation_allowed(); }
    public static function can_manage_khatm($request) { return Hamnoor_DB::user_can_manage_khatm((int) $request['id']); }

    private static function khatm_dto($khatm, $include_parts = false) {
        if (!$khatm) { return null; }
        $progress = Hamnoor_DB::get_progress((int) $khatm->id);
        $dto = array(
            'id' => (int) $khatm->id,
            'token' => (string) $khatm->token,
            'slug' => sanitize_title($khatm->slug),
            'title' => (string) $khatm->title,
            'intention' => wp_strip_all_tags((string) $khatm->intention),
            'description' => wp_strip_all_tags((string) $khatm->description),
            'cover_url' => !empty($khatm->cover_url) ? esc_url_raw($khatm->cover_url) : '',
            'khatm_type' => (string) $khatm->khatm_type,
            'khatm_count' => (int) $khatm->khatm_count,
            'max_khatm_count' => !empty($khatm->max_khatm_count) ? (int) $khatm->max_khatm_count : (int) Hamnoor_Helpers::settings()['max_khatm_count'],
            'parts_per_khatm' => (int) $khatm->parts_per_khatm,
            'total_parts' => (int) $khatm->total_parts,
            'privacy' => (string) $khatm->privacy,
            'status' => (string) $khatm->status,
            'starts_at' => !empty($khatm->starts_at) ? (string) $khatm->starts_at : null,
            'ends_at' => !empty($khatm->ends_at) ? (string) $khatm->ends_at : null,
            'url' => Hamnoor_Helpers::khatm_url($khatm),
            'progress' => $progress,
        );
        if ($include_parts) {
            $parts = Hamnoor_DB::get_parts((int) $khatm->id, '', array('limit'=>200));
            $dto['parts'] = array_map(function($part) { return self::part_dto($part, false); }, $parts);
        }
        return $dto;
    }

    private static function part_dto($part, $include_access = false) {
        $khatm = !empty($part->khatm_id) ? Hamnoor_DB::get_khatm((int) $part->khatm_id) : null;
        return array(
            'id' => (int) $part->id,
            'khatm_id' => (int) $part->khatm_id,
            'round_number' => (int) $part->round_number,
            'part_type' => (string) $part->part_type,
            'part_number' => (int) $part->part_number,
            'title' => (string) $part->title,
            'quran_url' => esc_url_raw((string) $part->quran_url),
            'status' => (string) $part->status,
            'status_label' => Hamnoor_Export::status_label($part->status),
            'participant_name' => !empty($part->participant_name) ? Hamnoor_Helpers::display_name($part->participant_name) : '',
            'access_url' => ($include_access && !empty($part->tracking_token) && $khatm) ? Hamnoor_Helpers::part_access_url($khatm, (int) $part->id, $part->tracking_token) : '',
        );
    }

    private static function participant_dto($row, $include_contacts = false) {
        $dto = array(
            'participant_id' => isset($row->participant_id) ? (int) $row->participant_id : (int) $row->id,
            'name' => (string) $row->name,
            'total_parts' => isset($row->total_parts) ? (int) $row->total_parts : 0,
            'done_parts' => isset($row->done_parts) ? (int) $row->done_parts : 0,
            'last_claimed' => !empty($row->last_claimed) ? (string) $row->last_claimed : null,
        );
        if ($include_contacts) {
            $dto['phone'] = (string) $row->phone;
            $dto['email'] = (string) $row->email;
        }
        return $dto;
    }

    public static function list_khatms($request) {
        $page = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(50, (int) $request->get_param('per_page')));
        $args = array('privacy'=>'public','status__in'=>array('active','completed'),'limit'=>$per_page,'offset'=>($page-1)*$per_page);
        if ($request->get_param('search')) { $args['s'] = sanitize_text_field($request->get_param('search')); }
        $items = Hamnoor_Khatm_Service::query($args);
        return self::ok(array_values(array_map(array(__CLASS__, 'khatm_dto'), $items)));
    }

    public static function create_khatm($request) {
        if (!Hamnoor_Permissions::new_khatm_creation_allowed()) { return new WP_Error('hamnoor_creation_disabled', __('ایجاد ختم جدید در حال حاضر موقتاً غیرفعال است. لطفاً بعداً مراجعه کنید.', 'hamnoor'), array('status'=>403)); }
        $data = Hamnoor_DB::sanitize_khatm_data($request->get_params());
        $r = Hamnoor_Khatm_Service::create($data);
        return is_wp_error($r) ? $r : self::ok(self::khatm_dto(Hamnoor_Khatm_Service::get($r)), __('ختم ایجاد شد.', 'hamnoor'));
    }

    public static function get_khatm($request) {
        $token = Hamnoor_Helpers::sanitize_token($request['token']);
        $k = Hamnoor_Khatm_Service::get($token);
        if (!$k) { return new WP_Error('hamnoor_not_found', __('ختم پیدا نشد.', 'hamnoor'), array('status'=>404)); }
        if (!Hamnoor_Permissions::can_preview_unpublished_khatm($k)) { return new WP_Error('hamnoor_pending_review', __('این ختم هنوز توسط مدیر سایت منتشر نشده است.', 'hamnoor'), array('status'=>403)); }
        if ('private' === $k->privacy && !Hamnoor_DB::user_can_manage_khatm((int)$k->id)) { return new WP_Error('hamnoor_private', __('این ختم خصوصی است.', 'hamnoor'), array('status'=>403)); }
        return self::ok(self::khatm_dto($k, true));
    }

    public static function claim_part($request) {
        $data = array('name'=>$request->get_param('name'), 'phone'=>$request->get_param('phone'), 'email'=>$request->get_param('email'));
        $rate = self::rate('claim_part', (int) $request['id'] . '|' . Hamnoor_Validator::normalize_phone($data['phone']), 30);
        if (is_wp_error($rate)) { return $rate; }
        $r = Hamnoor_Part_Service::claim((int)$request['id'], $data);
        if (is_wp_error($r)) { return $r; }
        $part = Hamnoor_Part_Service::get((int) $request['id']);
        return self::ok(self::part_dto($part, true), __('سهم رزرو شد.', 'hamnoor'));
    }

    public static function claim_random($request) {
        $data = array('name'=>$request->get_param('name'), 'phone'=>$request->get_param('phone'), 'email'=>$request->get_param('email'));
        $rate = self::rate('claim_random', (int) $request['id'] . '|' . Hamnoor_Validator::normalize_phone($data['phone']), 30);
        if (is_wp_error($rate)) { return $rate; }
        $r = Hamnoor_Part_Service::claim_random((int)$request['id'], $data, (int) $request->get_param('round_number'));
        if (is_wp_error($r)) { return $r; }
        $part = Hamnoor_Part_Service::get((int) $r['part_id']);
        return self::ok(self::part_dto($part, true), __('یک سهم آزاد تصادفی رزرو شد.', 'hamnoor'));
    }

    public static function claim_next($request) {
        $data = array('name'=>$request->get_param('name'), 'phone'=>$request->get_param('phone'), 'email'=>$request->get_param('email'));
        $rate = self::rate('claim_next', (int) $request['id'] . '|' . Hamnoor_Validator::normalize_phone($data['phone']), 30);
        if (is_wp_error($rate)) { return $rate; }
        $r = Hamnoor_Part_Service::claim_next((int)$request['id'], $data, (int) $request->get_param('round_number'));
        if (is_wp_error($r)) { return $r; }
        $part = Hamnoor_Part_Service::get((int) $r['part_id']);
        return self::ok(self::part_dto($part, true), __('سهم آزاد بعدی رزرو شد.', 'hamnoor'));
    }

    public static function complete_part($request) {
        $token = Hamnoor_Helpers::sanitize_token((string) $request->get_param('tracking_token'));
        $identity = $token ? $token : ('part_' . (int) $request['id']);
        $rate = self::rate('complete_part', $identity, 20); if (is_wp_error($rate)) { return $rate; }
        $r = Hamnoor_Part_Service::complete((int)$request['id'], $token, false);
        return is_wp_error($r) ? $r : self::ok(array('done'=>true), __('قرائت سهم ثبت شد.', 'hamnoor'));
    }

    public static function me_parts() {
        $parts = Hamnoor_Participant_Service::get_parts_by_user(get_current_user_id());
        return self::ok(array_map(function($part) { return self::part_dto($part, true); }, $parts));
    }

    public static function me_khatms() {
        $items = Hamnoor_Khatm_Service::query(array('owner_user_id'=>get_current_user_id(), 'limit'=>100));
        return self::ok(array_map(array(__CLASS__, 'khatm_dto'), $items));
    }

    public static function participants($request) {
        $id = (int) $request['id'];
        $rows = Hamnoor_Participant_Service::get_by_khatm($id, array('limit'=>200));
        $include_contacts = Hamnoor_Export::can_include_contacts($id, get_current_user_id());
        return self::ok(array_map(function($row) use ($include_contacts) { return self::participant_dto($row, $include_contacts); }, $rows));
    }
    public static function export_link($request) { $id=(int)$request['id']; return self::ok(array('csv'=>wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_csv&khatm_id='.$id),'hamnoor_export_'.$id), 'xlsx'=>wp_nonce_url(admin_url('admin-post.php?action=hamnoor_export_xlsx&khatm_id='.$id),'hamnoor_export_'.$id))); }
    public static function docs() { return self::ok(array('auth'=>'Public list/get return safe DTOs only. Claim returns a scoped access URL. Complete via REST requires tracking token, authenticated owner, or khatm manager. The frontend tracking page can complete by matching phone/email contact.', 'routes'=>array('/hamnoor/v1/khatms','/hamnoor/v1/khatms/{token}','/hamnoor/v1/khatms/{id}/claim-random','/hamnoor/v1/khatms/{id}/claim-next','/hamnoor/v1/parts/{id}/claim','/hamnoor/v1/parts/{id}/complete'))); }
}
