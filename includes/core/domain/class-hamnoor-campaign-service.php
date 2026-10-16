<?php
if (!defined('ABSPATH')) { exit; }

/** Domain service for Campaign capabilities. Existing Quran group UI remains a v1 adapter. */
final class Hamnoor_Campaign_Service {
    public static function validate_definition($data, $for_create = true) {
        $type = sanitize_key((string)($data['content_type'] ?? ''));
        $strategy = sanitize_key((string)($data['participation_strategy'] ?? ''));
        $access = sanitize_key((string)($data['access_mode'] ?? Hamnoor_Campaign_Domain::ACCESS_PUBLIC));
        $participation = sanitize_key((string)($data['participation_policy'] ?? Hamnoor_Campaign_Domain::PARTICIPATION_ANYONE));
        if (!in_array($type, Hamnoor_Campaign_Domain::content_types(), true)) { return new WP_Error('hamnoor_campaign_content_type_invalid', __('نوع محتوای پویش معتبر نیست.', 'hamnoor')); }
        if (!in_array($strategy, Hamnoor_Campaign_Domain::strategies(), true)) { return new WP_Error('hamnoor_campaign_strategy_invalid', __('مدل مشارکت پویش معتبر نیست.', 'hamnoor')); }
        if (!Hamnoor_Campaign_Domain::strategy_allowed_for_content($type, $strategy)) { return new WP_Error('hamnoor_campaign_strategy_mismatch', __('مدل مشارکت با نوع محتوای پویش سازگار نیست.', 'hamnoor')); }
        if (!in_array($access, Hamnoor_Campaign_Domain::access_modes(), true)) { return new WP_Error('hamnoor_campaign_access_invalid', __('سطح دسترسی پویش معتبر نیست.', 'hamnoor')); }
        if (!in_array($participation, Hamnoor_Campaign_Domain::participation_policies(), true)) { return new WP_Error('hamnoor_campaign_participation_policy_invalid', __('سیاست مشارکت پویش معتبر نیست.', 'hamnoor')); }
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $access && Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY !== $participation) {
            return new WP_Error('hamnoor_campaign_owner_policy_required', __('پویش شخصی باید فقط برای مالک قابل استفاده باشد.', 'hamnoor'));
        }
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY !== $access && Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY === $participation) {
            return new WP_Error('hamnoor_campaign_owner_access_required', __('مشارکت فقط مالک تنها برای پویش شخصی مجاز است.', 'hamnoor'));
        }

        // Current product scope: personal means exactly one full Quran completion.
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $access) {
            if (Hamnoor_Campaign_Domain::CONTENT_QURAN !== $type || Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR !== $strategy) {
                return new WP_Error('hamnoor_campaign_personal_quran_only', __('در نسخه فعلی، پویش شخصی فقط یک ختم کامل قرآن از ابتدا تا انتها است.', 'hamnoor'));
            }
        } elseif (Hamnoor_Campaign_Domain::CONTENT_QURAN === $type && Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR === $strategy) {
            return new WP_Error('hamnoor_campaign_personal_access_required', __('پیشرفت خطی قرآن فقط برای ختم شخصی مالک مجاز است.', 'hamnoor'));
        }

        if (Hamnoor_Campaign_Domain::CONTENT_QURAN !== $type) {
            $item_id = (int)($data['content_item_id'] ?? 0);
            $target = (int)($data['target_quantity'] ?? 0);
            if (!$item_id || $target < 1) { return new WP_Error('hamnoor_campaign_content_reference_required', __('محتوا و هدف پویش باید مشخص باشند.', 'hamnoor')); }
            $item = Hamnoor_Content_Repository::get($item_id);
            if (!$item || (string)$item->content_type !== $type) { return new WP_Error('hamnoor_campaign_content_not_found', __('محتوای مرجع پویش معتبر نیست.', 'hamnoor')); }
            if ($for_create && (1 !== (int)$item->campaign_enabled || 'active' !== (string)$item->status)) { return new WP_Error('hamnoor_campaign_content_disabled', __('این محتوا برای ایجاد پویش فعال نیست.', 'hamnoor')); }
        }
        return true;
    }

    public static function assert_mutable($campaign, $fields) {
        if (!$campaign || empty($campaign->id)) { return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        if (empty($campaign->first_participation_at)) { return true; }
        $locked = Hamnoor_Campaign_Domain::structural_fields();
        $attempted = array_values(array_intersect(array_keys((array)$fields), $locked));
        if ($attempted) {
            return new WP_Error('hamnoor_campaign_structure_locked', __('پس از اولین مشارکت، ساختار و هدف پویش قابل تغییر نیست.', 'hamnoor'), array('locked_fields'=>$attempted));
        }
        return true;
    }


    /**
     * Canonical schedule normalization and validation boundary.
     * Adapters may validate earlier for field-level UX, but internal callers
     * must not bypass malformed-date or date-order rules.
     */
    public static function normalize_schedule($starts_at, $ends_at) {
        $start_raw = null === $starts_at ? '' : trim((string)$starts_at);
        $end_raw = null === $ends_at ? '' : trim((string)$ends_at);

        $start = '' === $start_raw ? null : Hamnoor_Validator::normalize_datetime($start_raw, false);
        if ('' !== $start_raw && !$start) {
            return new WP_Error(
                'hamnoor_campaign_date_invalid',
                __('فرمت زمان شروع معتبر نیست.', 'hamnoor'),
                array('field'=>'starts_at')
            );
        }

        $end = '' === $end_raw ? null : Hamnoor_Validator::normalize_datetime($end_raw, true);
        if ('' !== $end_raw && !$end) {
            return new WP_Error(
                'hamnoor_campaign_date_invalid',
                __('فرمت زمان پایان معتبر نیست.', 'hamnoor'),
                array('field'=>'ends_at')
            );
        }

        $date_check = Hamnoor_Validator::validate_dates($start ?: '', $end ?: '');
        if (is_wp_error($date_check)) {
            return $date_check;
        }

        return array('starts_at'=>$start, 'ends_at'=>$end);
    }

    public static function update($campaign_id, $fields) {
        $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id);
        if (!$campaign) { return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor')); }
        if (!Hamnoor_Access_Policy::allows('campaign_edit', $campaign)) { return new WP_Error('hamnoor_campaign_edit_denied', __('برای ویرایش این پویش، مالکیت و تأیید این نشست لازم است.', 'hamnoor')); }
        $mutable = self::assert_mutable($campaign, (array)$fields);
        if (is_wp_error($mutable)) { return $mutable; }
        $allowed = array('title','intention','description','starts_at','ends_at','access_mode','indexable');
        $clean = array();
        foreach ((array)$fields as $key=>$value) {
            if (!in_array($key, $allowed, true)) { continue; }
            if ('title' === $key) { $clean[$key] = Hamnoor_Validator::trim_len(sanitize_text_field((string)$value), 191); }
            elseif (in_array($key, array('intention','description'), true)) { $clean[$key] = wp_kses_post((string)$value); }
            elseif ('access_mode' === $key) {
                $access = sanitize_key((string)$value);
                if (!in_array($access, array(Hamnoor_Campaign_Domain::ACCESS_PUBLIC,Hamnoor_Campaign_Domain::ACCESS_UNLISTED), true) || Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === (string)$campaign->access_mode) { continue; }
                $clean[$key] = $access;
                $clean['privacy'] = Hamnoor_Campaign_Domain::ACCESS_UNLISTED === $access ? 'private' : 'public';
            } elseif ('indexable' === $key) { $clean[$key] = Hamnoor_Campaign_Domain::ACCESS_PUBLIC === ($clean['access_mode'] ?? (string)$campaign->access_mode) && !empty($value) ? 1 : 0; }
            else {
                    $normalized = $value ? Hamnoor_Validator::normalize_datetime((string)$value, 'ends_at' === $key) : null;
                    if ($value && !$normalized) {
                        return new WP_Error(
                            'hamnoor_campaign_date_invalid',
                            __('فرمت تاریخ پویش معتبر نیست.', 'hamnoor'),
                            array('field'=>$key)
                        );
                    }
                    $clean[$key] = $normalized;
                }
        }

        if (array_key_exists('starts_at', $clean) || array_key_exists('ends_at', $clean)) {
            $effective_start = array_key_exists('starts_at', $clean)
                ? $clean['starts_at']
                : (!empty($campaign->starts_at) ? (string)$campaign->starts_at : '');
            $effective_end = array_key_exists('ends_at', $clean)
                ? $clean['ends_at']
                : (!empty($campaign->ends_at) ? (string)$campaign->ends_at : '');

            $date_check = Hamnoor_Validator::validate_dates(
                $effective_start ?: '',
                $effective_end ?: ''
            );
            if (is_wp_error($date_check)) { return $date_check; }
        }

        return Hamnoor_Campaign_Repository::update_fields((int)$campaign->id, $clean) ? Hamnoor_Campaign_Repository::get_by_id((int)$campaign->id) : new WP_Error('hamnoor_campaign_update_failed', __('ویرایش پویش انجام نشد.', 'hamnoor'));
    }

    public static function transition_lifecycle($campaign_id, $to) {
        global $wpdb;
        $campaign_id = (int)$campaign_id;
        $to = sanitize_key((string)$to);
        $wpdb->query('START TRANSACTION');
        try {
            // Lifecycle validation must observe the latest committed state while
            // holding the campaign row. Without this lock, two requests can both
            // validate against ACTIVE and a stale PAUSE may resurrect CANCELLED.
            $campaign = Hamnoor_Campaign_Repository::get_by_id($campaign_id, true);
            if (!$campaign) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_campaign_not_found', __('پویش پیدا نشد.', 'hamnoor'));
            }
            if (Hamnoor_Campaign_Domain::is_personal_quran($campaign) && Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED === $to) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_personal_quran_complete_via_progress', __('پایان ختم شخصی فقط پس از رسیدن پیشرفت قرائت به انتهای قرآن ثبت می‌شود.', 'hamnoor'));
            }
            $allowed = Hamnoor_Campaign_State_Machine::assert_lifecycle((string)$campaign->lifecycle_status, $to);
            if (is_wp_error($allowed)) {
                $wpdb->query('ROLLBACK');
                return $allowed;
            }
            $policy_action = Hamnoor_Campaign_Domain::is_personal_quran($campaign) ? 'personal_quran_lifecycle' : 'campaign_edit';
            if (!Hamnoor_Access_Policy::allows($policy_action, $campaign)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('hamnoor_campaign_edit_denied', __('اجازه مدیریت این پویش را ندارید.', 'hamnoor'));
            }
            $legacy_map = array(
                Hamnoor_Campaign_Domain::LIFECYCLE_DRAFT=>'pending_review',
                Hamnoor_Campaign_Domain::LIFECYCLE_SCHEDULED=>'active',
                Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE=>'active',
                Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED=>'paused',
                Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED=>'completed',
                Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED=>'expired',
                Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED=>'cancelled',
                Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED=>'archived',
                Hamnoor_Campaign_Domain::LIFECYCLE_FAILED=>'failed',
            );
            $fields = array('lifecycle_status'=>$to);
            if (isset($legacy_map[$to])) { $fields['status']=$legacy_map[$to]; }
            if (Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED === $to) { $fields['completed_at']=current_time('mysql'); }
            if (!Hamnoor_Campaign_Repository::update_fields($campaign_id, $fields)) {
                throw new RuntimeException('campaign_lifecycle_update_failed');
            }
            $previous_lifecycle = (string)$campaign->lifecycle_status;
            $personal_quran = Hamnoor_Campaign_Domain::is_personal_quran($campaign);
            $owner_user_id = (int)$campaign->owner_user_id;
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[Hamnoor] Campaign lifecycle transition failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_campaign_transition_failed', __('تغییر وضعیت پویش انجام نشد.', 'hamnoor'));
        }
        $updated = Hamnoor_Campaign_Repository::get_by_id($campaign_id);
        if ($personal_quran && $updated) {
            do_action('hamnoor_personal_quran_lifecycle_changed', $campaign_id, $previous_lifecycle, $to, $owner_user_id);
        }
        return $updated;
    }

    /** Canonical group Quran creator. This path always generates shareable parts. */
    private static function create_segmented_quran($data, $owner_user_id) {
        $quran = isset($data['quran']) && is_array($data['quran']) ? $data['quran'] : array();
        $unit = sanitize_key((string)($quran['unit'] ?? 'page'));
        if (!in_array($unit, array('page','juz','surah','repeated_surah'), true)) { return new WP_Error('hamnoor_campaign_quran_unit_invalid', __('نوع تقسیم قرآن معتبر نیست.', 'hamnoor')); }
        $legacy = array(
            'title'=>(string)($data['title'] ?? ''),
            'intention'=>(string)($data['intention'] ?? ''),
            'description'=>(string)($data['description'] ?? ''),
            'khatm_type'=>$unit,
            'khatm_count'=>max(1, (int)($quran['rounds'] ?? 1)),
            'selected_surah_number'=>max(1, min(114, (int)($quran['surah_number'] ?? 1))),
            'repeat_count'=>max(1, min(1000, (int)($quran['repeat_count'] ?? 100))),
            'privacy'=>Hamnoor_Campaign_Domain::ACCESS_PUBLIC === (string)$data['access_mode'] ? 'public' : 'private',
            'indexable'=>!empty($data['indexable']) ? 1 : 0,
            'starts_at'=>isset($data['starts_at']) ? (string)$data['starts_at'] : '',
            'ends_at'=>isset($data['ends_at']) ? (string)$data['ends_at'] : '',
            'auto_release_mode'=>'none',
            'completion_mode'=>'manual_done',
        );
        $clean = Hamnoor_DB::sanitize_khatm_data($legacy);
        $id = Hamnoor_DB::create_khatm($clean, array(
            'access_mode'=>(string)$data['access_mode'],
            'participation_policy'=>(string)$data['participation_policy'],
            'owner_user_id'=>(int)$owner_user_id,
        ));
        if (is_wp_error($id)) { return $id; }
        $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$id);
        if (!$campaign || (int)$campaign->owner_user_id !== (int)$owner_user_id) { return new WP_Error('hamnoor_campaign_owner_mismatch', __('مالک پویش ایجادشده معتبر نیست.', 'hamnoor')); }
        return $campaign;
    }

    /** Explicit backend capability: one owner reads one complete Quran from start to finish. */
    public static function create_personal_quran($payload, $owner_user_id) {
        $data = Hamnoor_Command_Contracts::personal_quran_create($payload);
        if (is_wp_error($data)) { return $data; }
        return self::create($data, (int)$owner_user_id);
    }

    private static function create_unsegmented_campaign($data, $owner_user_id) {
        global $wpdb;
        $user = Hamnoor_Mobile_Access::get_user((int)$owner_user_id);
        if (!$user) { return new WP_Error('hamnoor_campaign_owner_invalid', __('مالک پویش معتبر نیست.', 'hamnoor')); }
        $type = sanitize_key((string)$data['content_type']);
        $strategy = sanitize_key((string)$data['participation_strategy']);
        $access = sanitize_key((string)($data['access_mode'] ?? Hamnoor_Campaign_Domain::ACCESS_PUBLIC));
        $personal_quran = Hamnoor_Campaign_Domain::CONTENT_QURAN === $type && Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR === $strategy;
        $now = current_time('mysql');
        $title = Hamnoor_Validator::trim_len(sanitize_text_field((string)($data['title'] ?? '')), 191);
        if (!$title) { return new WP_Error('hamnoor_campaign_title_required', __('عنوان پویش الزامی است.', 'hamnoor')); }
        $review = $personal_quran ? Hamnoor_Campaign_Domain::REVIEW_NOT_REQUIRED : Hamnoor_Campaign_Domain::REVIEW_PENDING;
        $lifecycle = $personal_quran ? Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE : Hamnoor_Campaign_Domain::LIFECYCLE_DRAFT;
        $legacy_status = $personal_quran ? 'active' : 'pending_review';
        $token = Hamnoor_DB::generate_unique_token();
        if (is_wp_error($token)) { return $token; }
        $values = array(
            'token'=>$token, 'campaign_public_id'=>Hamnoor_DB::generate_public_id('CMP'), 'slug'=>sanitize_title($title), 'title'=>$title,
            'intention'=>wp_kses_post((string)($data['intention'] ?? '')), 'description'=>wp_kses_post((string)($data['description'] ?? '')),
            'owner_user_id'=>(int)$owner_user_id, 'owner_name'=>(string)$user->display_name, 'owner_phone'=>(string)$user->phone,
            'content_type'=>$type, 'participation_strategy'=>$strategy, 'access_mode'=>$access,
            'participation_policy'=>sanitize_key((string)$data['participation_policy']), 'lifecycle_status'=>$lifecycle, 'review_status'=>$review,
            'content_item_id'=>!empty($data['content_item_id']) ? (int)$data['content_item_id'] : null,
            'target_quantity'=>$personal_quran ? Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX : (int)($data['target_quantity'] ?? 0),
            'domain_version'=>2,
            // No Parts are generated for personal Quran or contribution-ledger campaigns.
            'khatm_type'=>$personal_quran ? 'personal' : 'page','khatm_count'=>1,'parts_per_khatm'=>0,'total_parts'=>0,
            'privacy'=>Hamnoor_Campaign_Domain::ACCESS_PUBLIC === $access ? 'public' : 'private','status'=>$legacy_status,
            'indexable'=>Hamnoor_Campaign_Domain::ACCESS_PUBLIC === $access && !empty($data['indexable']) ? 1 : 0,
            'published_at'=>$personal_quran ? $now : null,'created_at'=>$now,'updated_at'=>$now,
        );
        $wpdb->query('START TRANSACTION');
        try {
            if ($personal_quran) {
                // Serialize personal-Quran creation per owner. Locking the owner row
                // makes the "one open completion" invariant race-safe even when
                // two create requests arrive at the same time.
                $users = Hamnoor_DB::table('users');
                $locked_owner = $wpdb->get_var($wpdb->prepare("SELECT id FROM $users WHERE id=%d LIMIT 1 FOR UPDATE", (int)$owner_user_id));
                if (!$locked_owner) { throw new RuntimeException('personal_quran_owner_lock_failed'); }
                $existing = Hamnoor_Campaign_Repository::find_open_personal_quran_for_owner((int)$owner_user_id, true);
                if ($existing) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error(
                        'hamnoor_personal_quran_active_exists',
                        __('شما هم‌اکنون یک ختم شخصی در حال انجام دارید.', 'hamnoor'),
                        array('campaign_public_id'=>(string)$existing->campaign_public_id)
                    );
                }
            }
            $ok = $wpdb->insert(Hamnoor_DB::table('khatms'), $values);
            if (!$ok) { throw new RuntimeException($wpdb->last_error ?: 'campaign_insert_failed'); }
            $id = (int)$wpdb->insert_id;
            if ($personal_quran) {
                $progress = Hamnoor_Personal_Quran_Progress_Service::initialize($id, (int)$owner_user_id);
                if (is_wp_error($progress)) { throw new RuntimeException($progress->get_error_message()); }
            }
            $wpdb->query('COMMIT');
            $created = Hamnoor_Campaign_Repository::get_by_id($id);
            if ($personal_quran && $created) { do_action('hamnoor_personal_quran_created', $id, (int)$owner_user_id, $created); }
            return $created;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[Hamnoor] Unsegmented campaign create failed: ' . $e->getMessage());
            return new WP_Error('hamnoor_campaign_create_failed', __('ایجاد پویش انجام نشد.', 'hamnoor'));
        }
    }

    /** Backend creator. UI adapters must pass through Command_Contracts first. */
    public static function create($data, $owner_user_id) {
        $owner_user_id = (int)$owner_user_id;
        if (!$owner_user_id) { return new WP_Error('hamnoor_campaign_owner_required', __('مالک پویش مشخص نیست.', 'hamnoor')); }
        $schedule = self::normalize_schedule(
            isset($data['starts_at']) ? $data['starts_at'] : '',
            isset($data['ends_at']) ? $data['ends_at'] : ''
        );
        if (is_wp_error($schedule)) { return $schedule; }
        $data['starts_at'] = $schedule['starts_at'];
        $data['ends_at'] = $schedule['ends_at'];


        $definition = self::validate_definition($data, true);
        if (is_wp_error($definition)) { return $definition; }
        $type = sanitize_key((string)$data['content_type']);
        $strategy = sanitize_key((string)$data['participation_strategy']);
        $is_personal_quran = Hamnoor_Campaign_Domain::CONTENT_QURAN === $type
            && Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR === $strategy
            && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === sanitize_key((string)($data['access_mode'] ?? ''))
            && Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY === sanitize_key((string)($data['participation_policy'] ?? ''));
        $policy_action = $is_personal_quran ? 'personal_quran_create' : 'campaign_create';
        if (!Hamnoor_Access_Policy::allows($policy_action, null, $owner_user_id)) {
            return $is_personal_quran
                ? new WP_Error('hamnoor_personal_quran_create_denied', __('برای شروع ختم شخصی، یک نشست عادی هم‌نور لازم است.', 'hamnoor'))
                : new WP_Error('hamnoor_campaign_create_denied', __('برای ایجاد پویش، تأیید این نشست لازم است.', 'hamnoor'));
        }
        if (Hamnoor_Campaign_Domain::CONTENT_QURAN === $type && Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED === $strategy) {
            return self::create_segmented_quran($data, $owner_user_id);
        }
        return self::create_unsegmented_campaign($data, $owner_user_id);
    }
}
