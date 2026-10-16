<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistence and rules for one personal Quran completion from beginning to end.
 *
 * There are no parts, claims, user-selected divisions or participant rows here.
 * The only mutable reading fact is the owner's monotonically increasing global
 * verse position for this single Quran completion.
 */
final class Hamnoor_Personal_Quran_Progress_Service {
    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_READING     = 'reading';
    const STATUS_READY       = 'ready';
    const STATUS_COMPLETED   = 'completed';

    public static function get($campaign_id) {
        global $wpdb;
        $table = Hamnoor_DB::table('personal_quran_progress');
        if (!$table) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE campaign_id=%d LIMIT 1", (int)$campaign_id));
    }

    public static function initialize($campaign_id, $owner_user_id) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $owner_user_id = (int)$owner_user_id;
        $campaign = Hamnoor_Campaign_Repository::get_by_id($campaign_id);
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) {
            return new WP_Error('hamnoor_personal_quran_invalid_campaign', __('این رکورد یک ختم شخصی قرآن معتبر نیست.', 'hamnoor'));
        }
        if (!$owner_user_id || $owner_user_id !== (int)$campaign->owner_user_id) {
            return new WP_Error('hamnoor_personal_quran_owner_required', __('فقط مالک ختم شخصی به آن دسترسی دارد.', 'hamnoor'));
        }
        $existing = self::get($campaign_id);
        if ($existing) { return $existing; }
        $now = gmdate('Y-m-d H:i:s');
        $table = Hamnoor_DB::table('personal_quran_progress');
        $inserted = $wpdb->insert($table, array(
            'campaign_id'=>$campaign_id,
            'user_id'=>$owner_user_id,
            'start_global_index'=>Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX,
            'end_global_index'=>Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX,
            'last_global_index'=>0,
            'reading_status'=>self::STATUS_NOT_STARTED,
            'created_at_utc'=>$now,
            'updated_at_utc'=>$now,
        ), array('%d','%d','%d','%d','%d','%s','%s','%s'));
        if (false === $inserted) {
            $retry = self::get($campaign_id);
            return $retry ? $retry : new WP_Error('hamnoor_personal_quran_progress_create_failed', __('ساخت پرونده پیشرفت ختم شخصی انجام نشد.', 'hamnoor'));
        }
        return self::get($campaign_id);
    }

    private static function assert_owner($campaign, $user_id) {
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) {
            return new WP_Error('hamnoor_personal_quran_invalid_campaign', __('این رکورد یک ختم شخصی قرآن معتبر نیست.', 'hamnoor'));
        }
        if (!(int)$user_id || (int)$campaign->owner_user_id !== (int)$user_id) {
            return new WP_Error('hamnoor_personal_quran_owner_required', __('فقط مالک ختم شخصی به آن دسترسی دارد.', 'hamnoor'));
        }
        return true;
    }

    public static function save_position($campaign_id, $user_id, $global_index) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $user_id = (int)$user_id;
        $global_index = (int)$global_index;
        $table = Hamnoor_DB::table('personal_quran_progress');
        $campaigns = Hamnoor_DB::table('khatms');

        $wpdb->query('START TRANSACTION');
        try {
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d LIMIT 1 FOR UPDATE", $campaign_id));
            $allowed = self::assert_owner($campaign, $user_id);
            if (is_wp_error($allowed)) { throw new Hamnoor_Personal_Quran_Domain_Exception($allowed); }
            if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status) {
                throw new Hamnoor_Personal_Quran_Domain_Exception(new WP_Error('hamnoor_personal_quran_not_active', __('این ختم شخصی در وضعیت فعال نیست. ابتدا آن را از سر بگیرید.', 'hamnoor')));
            }

            $progress = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE campaign_id=%d LIMIT 1 FOR UPDATE", $campaign_id));
            if (!$progress) {
                $created = self::initialize($campaign_id, $user_id);
                if (is_wp_error($created)) { throw new Hamnoor_Personal_Quran_Domain_Exception($created); }
                $progress = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE campaign_id=%d LIMIT 1 FOR UPDATE", $campaign_id));
            }
            $start = (int)$progress->start_global_index;
            $end = (int)$progress->end_global_index;
            if ($global_index < $start || $global_index > $end) {
                throw new Hamnoor_Personal_Quran_Domain_Exception(new WP_Error('hamnoor_personal_quran_position_invalid', __('محل قرائت خارج از محدوده قرآن است.', 'hamnoor')));
            }
            if (self::STATUS_COMPLETED === (string)$progress->reading_status) {
                $wpdb->query('COMMIT');
                return $progress;
            }

            // Saved position is monotonic: returning to an earlier ayah for review
            // must never move the persisted completion backwards.
            $next = max((int)$progress->last_global_index, $global_index);
            $now = gmdate('Y-m-d H:i:s');
            $status = $next >= $end ? self::STATUS_READY : self::STATUS_READING;
            $started_at = !empty($progress->started_at_utc) ? (string)$progress->started_at_utc : $now;
            $updated = $wpdb->update($table, array(
                'last_global_index'=>$next,
                'reading_status'=>$status,
                'started_at_utc'=>$started_at,
                'last_read_at_utc'=>$now,
                'updated_at_utc'=>$now,
            ), array('campaign_id'=>$campaign_id), array('%d','%s','%s','%s','%s'), array('%d'));
            if (false === $updated) { throw new RuntimeException($wpdb->last_error ?: 'personal_quran_progress_update_failed'); }

            if (empty($campaign->first_participation_at)) {
                $wpdb->update($campaigns, array('first_participation_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('id'=>$campaign_id), array('%s','%s'), array('%d'));
            }
            $wpdb->query('COMMIT');
            $saved = self::get($campaign_id);
            $summary = self::progress($campaign);
            do_action('hamnoor_personal_quran_progress_saved', $campaign_id, $next, $summary, $user_id);
            return $saved;
        } catch (Hamnoor_Personal_Quran_Domain_Exception $e) {
            $wpdb->query('ROLLBACK');
            return $e->error();
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[Hamnoor] Personal Quran progress save failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_personal_quran_progress_save_failed', __('ذخیره پیشرفت ختم شخصی انجام نشد.', 'hamnoor'));
        }
    }

    public static function complete($campaign_id, $user_id) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $user_id = (int)$user_id;
        $table = Hamnoor_DB::table('personal_quran_progress');
        $campaigns = Hamnoor_DB::table('khatms');
        $wpdb->query('START TRANSACTION');
        try {
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE id=%d LIMIT 1 FOR UPDATE", $campaign_id));
            $allowed = self::assert_owner($campaign, $user_id);
            if (is_wp_error($allowed)) { throw new Hamnoor_Personal_Quran_Domain_Exception($allowed); }
            $progress = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE campaign_id=%d LIMIT 1 FOR UPDATE", $campaign_id));
            if (!$progress) { throw new Hamnoor_Personal_Quran_Domain_Exception(new WP_Error('hamnoor_personal_quran_progress_missing', __('پیشرفت این ختم شخصی پیدا نشد.', 'hamnoor'))); }
            if (self::STATUS_COMPLETED === (string)$progress->reading_status) {
                $wpdb->query('COMMIT');
                return $progress;
            }
            if (Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE !== (string)$campaign->lifecycle_status) {
                throw new Hamnoor_Personal_Quran_Domain_Exception(new WP_Error('hamnoor_personal_quran_not_active', __('این ختم شخصی در وضعیت فعال نیست. ابتدا آن را از سر بگیرید.', 'hamnoor')));
            }
            if ((int)$progress->last_global_index < (int)$progress->end_global_index) {
                throw new Hamnoor_Personal_Quran_Domain_Exception(new WP_Error('hamnoor_personal_quran_not_finished', __('هنوز به پایان قرآن نرسیده‌اید.', 'hamnoor')));
            }
            $now_utc = gmdate('Y-m-d H:i:s');
            if (false === $wpdb->update($table, array('reading_status'=>self::STATUS_COMPLETED,'completed_at_utc'=>$now_utc,'updated_at_utc'=>$now_utc), array('campaign_id'=>$campaign_id), array('%s','%s','%s'), array('%d'))) {
                throw new RuntimeException($wpdb->last_error ?: 'personal_quran_complete_progress_failed');
            }
            $now = current_time('mysql');
            if (false === $wpdb->update($campaigns, array(
                'lifecycle_status'=>Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED,
                'status'=>'completed',
                'completed_at'=>$now,
                'updated_at'=>$now,
            ), array('id'=>$campaign_id), array('%s','%s','%s','%s'), array('%d'))) {
                throw new RuntimeException($wpdb->last_error ?: 'personal_quran_complete_campaign_failed');
            }
            $wpdb->query('COMMIT');
            $done = self::get($campaign_id);
            Hamnoor_Domain_Events::personal_quran_completed($campaign_id, $user_id);
            do_action('hamnoor_personal_quran_completed', $campaign_id, $user_id);
            return $done;
        } catch (Hamnoor_Personal_Quran_Domain_Exception $e) {
            $wpdb->query('ROLLBACK');
            return $e->error();
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[Hamnoor] Personal Quran completion failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_personal_quran_complete_failed', __('ثبت پایان ختم شخصی انجام نشد.', 'hamnoor'));
        }
    }

    public static function progress($campaign) {
        if (!$campaign || !Hamnoor_Campaign_Domain::is_personal_quran($campaign)) {
            return new WP_Error('hamnoor_personal_quran_invalid_campaign', __('این رکورد یک ختم شخصی قرآن معتبر نیست.', 'hamnoor'));
        }
        $row = self::get((int)$campaign->id);
        $start = $row ? (int)$row->start_global_index : Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX;
        $end = $row ? (int)$row->end_global_index : Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX;
        $last = $row ? (int)$row->last_global_index : 0;
        $target = max(1, $end - $start + 1);
        $current = $last >= $start ? min($target, $last - $start + 1) : 0;
        $percentage = min(100, round(($current / $target) * 100, 2));
        return array(
            'current'=>$current,
            'target'=>$target,
            'percentage'=>$percentage,
            'is_completed'=>$row && self::STATUS_COMPLETED === (string)$row->reading_status,
            'reading_status'=>$row ? (string)$row->reading_status : self::STATUS_NOT_STARTED,
            'last_global_index'=>$last,
            'resume_global_index'=>$last >= $start ? min($end, $last + 1) : $start,
        );
    }
}

/** Lightweight carrier so domain WP_Error values can cross an SQL transaction catch boundary. */
final class Hamnoor_Personal_Quran_Domain_Exception extends RuntimeException {
    private $domain_error;
    public function __construct(WP_Error $error) { parent::__construct($error->get_error_message()); $this->domain_error = $error; }
    public function error() { return $this->domain_error; }
}
