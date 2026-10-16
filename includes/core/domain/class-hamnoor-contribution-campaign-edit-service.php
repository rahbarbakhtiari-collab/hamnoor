<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical owner-edit service for contribution-ledger campaigns.
 *
 * Dua/Dhikr campaigns do not own Parts/Claims. This service keeps their edit
 * writes inside Campaign domain fields and enforces the first-participation
 * structural lock before one repository update is issued.
 */
final class Hamnoor_Contribution_Campaign_Edit_Service {
    private static function is_supported($campaign) {
        return $campaign && in_array((string)($campaign->participation_strategy ?? ''), array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true);
    }

    /** Validate/sanitize a canonical partial update without persisting it. */
    public static function validate_update($campaign, $fields) {
        if (!self::is_supported($campaign)) {
            return new WP_Error('hamnoor_contribution_campaign_edit_invalid', __('این پویش از مدل مشارکت دعا/ذکر استفاده نمی‌کند.', 'hamnoor'));
        }

        $fields = is_array($fields) ? $fields : array();
        $allowed = array(
            'title','intention','description','starts_at','ends_at','access_mode','indexable',
            'content_item_id','target_quantity',
        );
        $requested = array();
        foreach ($fields as $key=>$value) {
            if (in_array($key, $allowed, true)) { $requested[$key] = $value; }
        }

        $mutable = Hamnoor_Campaign_Service::assert_mutable($campaign, $requested);
        if (is_wp_error($mutable)) { return $mutable; }

        $clean = array();
        foreach ($requested as $key=>$value) {
            if ('title' === $key) {
                $title = Hamnoor_Validator::trim_len(sanitize_text_field((string)$value), 191);
                if (!$title) { return new WP_Error('hamnoor_campaign_title_required', __('عنوان پویش الزامی است.', 'hamnoor'), array('field'=>'title')); }
                $clean[$key] = $title;
            } elseif (in_array($key, array('intention','description'), true)) {
                $clean[$key] = wp_kses_post((string)$value);
            } elseif ('access_mode' === $key) {
                $access = sanitize_key((string)$value);
                if (!in_array($access, array(Hamnoor_Campaign_Domain::ACCESS_PUBLIC, Hamnoor_Campaign_Domain::ACCESS_UNLISTED), true)) {
                    return new WP_Error('hamnoor_campaign_access_invalid', __('سطح دسترسی پویش معتبر نیست.', 'hamnoor'), array('field'=>'privacy'));
                }
                $clean[$key] = $access;
                $clean['privacy'] = Hamnoor_Campaign_Domain::ACCESS_UNLISTED === $access ? 'private' : 'public';
            } elseif ('indexable' === $key) {
                $clean[$key] = !empty($value) ? 1 : 0;
            } elseif (in_array($key, array('starts_at','ends_at'), true)) {
                if (!$value) { $clean[$key] = null; continue; }
                $normalized = Hamnoor_Validator::normalize_datetime((string)$value, 'ends_at' === $key);
                if (!$normalized) {
                    return new WP_Error('hamnoor_campaign_date_invalid', __('زمان‌بندی پویش معتبر نیست.', 'hamnoor'), array('field'=>$key));
                }
                $clean[$key] = $normalized;
            } elseif ('content_item_id' === $key) {
                $item_id = (int)$value;
                if ($item_id < 1) { return new WP_Error('hamnoor_campaign_content_reference_required', __('محتوای پویش باید مشخص باشد.', 'hamnoor'), array('field'=>'content_id')); }
                $clean[$key] = $item_id;
            } elseif ('target_quantity' === $key) {
                $target = (int)$value;
                if ($target < 1) { return new WP_Error('hamnoor_campaign_target_required', __('هدف پویش باید عددی بزرگ‌تر از صفر باشد.', 'hamnoor'), array('field'=>'target')); }
                $clean[$key] = $target;
            }
        }

        $effective_access = isset($clean['access_mode']) ? (string)$clean['access_mode'] : (string)$campaign->access_mode;
        if (Hamnoor_Campaign_Domain::ACCESS_PUBLIC !== $effective_access) {
            $clean['indexable'] = 0;
        } elseif (array_key_exists('indexable', $clean)) {
            $clean['indexable'] = !empty($clean['indexable']) ? 1 : 0;
        }

        if (array_key_exists('starts_at', $clean) || array_key_exists('ends_at', $clean)) {
            $starts = array_key_exists('starts_at', $clean) ? $clean['starts_at'] : (string)$campaign->starts_at;
            $ends = array_key_exists('ends_at', $clean) ? $clean['ends_at'] : (string)$campaign->ends_at;
            $date_check = Hamnoor_Validator::validate_dates($starts ?: '', $ends ?: '');
            if (is_wp_error($date_check)) { return $date_check; }
        }

        $has_structure = array_key_exists('content_item_id', $clean) || array_key_exists('target_quantity', $clean);
        if ($has_structure) {
            $candidate = array(
                'content_type'=>(string)$campaign->content_type,
                'participation_strategy'=>(string)$campaign->participation_strategy,
                'access_mode'=>$effective_access,
                'participation_policy'=>(string)$campaign->participation_policy,
                'content_item_id'=>array_key_exists('content_item_id', $clean) ? (int)$clean['content_item_id'] : (int)$campaign->content_item_id,
                'target_quantity'=>array_key_exists('target_quantity', $clean) ? (int)$clean['target_quantity'] : (int)$campaign->target_quantity,
            );
            $content_changed = array_key_exists('content_item_id', $clean) && (int)$clean['content_item_id'] !== (int)$campaign->content_item_id;
            $definition = Hamnoor_Campaign_Service::validate_definition($candidate, $content_changed);
            if (is_wp_error($definition)) { return $definition; }
        }

        return $clean;
    }

    /** Persist one canonical partial update after policy and invariant checks. */
    public static function update($campaign_id, $fields) {
        $campaign = Hamnoor_Campaign_Repository::get_by_id((int)$campaign_id);
        if (!self::is_supported($campaign)) {
            return new WP_Error('hamnoor_contribution_campaign_edit_invalid', __('پویش دعا/ذکر پیدا نشد.', 'hamnoor'));
        }
        if (!Hamnoor_Access_Policy::allows('campaign_edit', $campaign)) {
            return new WP_Error('hamnoor_campaign_edit_denied', __('برای ویرایش این پویش، مالکیت و تأیید این نشست لازم است.', 'hamnoor'));
        }

        $clean = self::validate_update($campaign, $fields);
        if (is_wp_error($clean)) { return $clean; }
        if (!$clean) { return $campaign; }

        if (!Hamnoor_Campaign_Repository::update_fields((int)$campaign->id, $clean)) {
            return new WP_Error('hamnoor_campaign_update_failed', __('ویرایش پویش انجام نشد.', 'hamnoor'));
        }
        return Hamnoor_Campaign_Repository::get_by_id((int)$campaign->id);
    }
}
