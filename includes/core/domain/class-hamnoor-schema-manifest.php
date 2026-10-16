<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Single machine-readable contract for the persistence surface that new
 * Hamnoor modules may rely on. Keeping this outside Hamnoor_DB prevents
 * health checks/tests from drifting away from the actual domain model.
 */
final class Hamnoor_Schema_Manifest {
    public static function tables() {
        return array(
            'users' => array(
                'columns'=>array('id','wp_user_id','public_id','display_name','phone','mobile_verified','claim_blocked','status','created_at','updated_at'),
                'indexes'=>array('PRIMARY','public_id','phone','wp_user_id'),
            ),
            'sessions' => array(
                'columns'=>array('id','user_id','token_hash','expires_at','last_seen_at','revoked_at','assurance_level','trusted_until','step_up_until','assurance_source'),
                'indexes'=>array('PRIMARY','token_hash','user_active','user_assurance'),
            ),
            'notifications' => array('columns'=>array('id','user_id','type','title','message','read_at','created_at')),
            'notification_channels' => array('columns'=>array('id','user_id','channel','enabled','status','connected_at','created_at','updated_at')),
            'rubika_links' => array('columns'=>array('id','user_id','code','target_hash','target_url','secret_hash','use_count','last_used_at','created_at','updated_at')),
            'push_subscriptions' => array('columns'=>array('id','user_id','endpoint_hash','endpoint','p256dh','auth','revoked_at','created_at','updated_at')),
            'notification_deliveries' => array('columns'=>array('id','notification_id','user_id','channel','provider','status','attempt_count','max_attempts','context_json','next_attempt_at','created_at','updated_at')),
            'khatms' => array(
                'columns'=>array('id','token','campaign_public_id','title','owner_user_id','owner_phone','content_type','participation_strategy','access_mode','participation_policy','lifecycle_status','review_status','content_item_id','target_quantity','first_participation_at','domain_version','khatm_type','khatm_count','parts_per_khatm','total_parts','privacy','status','starts_at','ends_at','created_at','updated_at'),
                'indexes'=>array('PRIMARY','token','campaign_public_id','content_strategy','lifecycle_review','owner_status'),
            ),
            'parts' => array(
                'columns'=>array('id','public_id','khatm_id','participant_id','round_number','part_type','part_number','status','claimed_at','done_at','released_at','released_by','release_reason'),
                'indexes'=>array('PRIMARY','public_id','khatm_status_round','participant_id'),
            ),
            'participants' => array(
                'columns'=>array('id','public_id','khatm_id','user_id','name','phone','normalized_phone','tracking_token','claim_blocked'),
                'indexes'=>array('PRIMARY','public_id','user_khatm','khatm_phone'),
            ),
            'content_items' => array(
                'columns'=>array('id','public_id','content_type','source_type','source_key','title','content_payload','source_release','source_href','status','campaign_enabled','max_contribution','updated_by_wp_user_id','created_at_utc','updated_at_utc'),
                'indexes'=>array('PRIMARY','public_id','source_identity','type_enabled'),
            ),
            'contributions' => array(
                'columns'=>array('id','public_id','campaign_id','user_id','participant_id','kind','quantity','status','idempotency_key','parent_contribution_id','metadata_json','occurred_at_utc','created_at_utc'),
                'indexes'=>array('PRIMARY','public_id','idempotency_key','campaign_time','user_time'),
            ),
            'claim_events' => array(
                'columns'=>array('id','public_id','campaign_id','part_id','participant_id','user_id','event_type','quota_delta','idempotency_key','metadata_json','occurred_at_utc'),
                'indexes'=>array('PRIMARY','public_id','idempotency_key','user_quota_window','campaign_time'),
            ),
            'domain_events' => array(
                'columns'=>array('id','event_key','event_name','schema_version','subject_user_id','entity_type','entity_id','payload_json','context_json','occurred_at_utc','created_at_utc'),
                'indexes'=>array('PRIMARY','event_key','event_stream','subject_event','entity_lookup'),
            ),
            'migrations' => array(
                'columns'=>array('id','migration_key','from_version','to_version','status','error_message','applied_at_utc'),
                'indexes'=>array('PRIMARY','migration_key','status'),
            ),
            'logs' => array('columns'=>array('id','khatm_id','action','message','created_at')),
            'otps' => array('columns'=>array('id','contact','normalized_contact','otp_hash','purpose','expires_at')),
            'reading_progress' => array('columns'=>array('id','part_id','participant_id','user_id','khatm_id','start_global_index','end_global_index','last_global_index','reading_status','last_read_at','completed_at','reminder_enabled')),
            'personal_quran_progress' => array(
                'columns'=>array('id','campaign_id','user_id','start_global_index','end_global_index','last_global_index','reading_status','started_at_utc','last_read_at_utc','completed_at_utc','created_at_utc','updated_at_utc'),
                'indexes'=>array('PRIMARY','campaign_id','user_status','last_read_at_utc'),
            ),
            'retention_rollups' => array(
                'columns'=>array('id','scope_type','scope_id','rollup_key','payload_json','source_max_part_id','source_max_participant_id','source_max_claim_event_id','source_max_contribution_id','source_max_log_id','generated_at_utc','updated_at_utc'),
                'indexes'=>array('PRIMARY','scope_rollup','generated_at_utc'),
            ),
            'reminder_schedules' => array(
                'columns'=>array('id','public_id','user_id','scope_type','scope_id','enabled','local_time','timezone','days_mask','snoozed_until_utc','next_due_at_utc','last_sent_at_utc','last_occurrence_key','send_count','disabled_reason','metadata_json','created_at_utc','updated_at_utc'),
                'indexes'=>array('PRIMARY','public_id','user_scope','due','scope'),
            ),
        );
    }

    public static function table_names() { return array_keys(self::tables()); }
    public static function for_table($name) {
        $all = self::tables();
        return isset($all[$name]) ? $all[$name] : array('columns'=>array(),'indexes'=>array());
    }
}
