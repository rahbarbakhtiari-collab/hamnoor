<?php
if (!defined('ABSPATH')) { exit; }

/** Versioned mutation boundary shared by REST/AJAX/UI adapters. */
final class Hamnoor_Command_Contracts {
    const VERSION = '1.3';

    public static function campaign_create($payload) {
        $payload = is_array($payload) ? $payload : array();
        $type = sanitize_key((string)($payload['content_type'] ?? ''));
        if (!in_array($type, Hamnoor_Campaign_Domain::content_types(), true)) { return new WP_Error('hamnoor_command_content_type_invalid', __('نوع محتوای پویش معتبر نیست.', 'hamnoor')); }
        $access = sanitize_key((string)($payload['access_mode'] ?? Hamnoor_Campaign_Domain::ACCESS_PUBLIC));
        if (!in_array($access, Hamnoor_Campaign_Domain::access_modes(), true)) { return new WP_Error('hamnoor_command_access_invalid', __('سطح دسترسی پویش معتبر نیست.', 'hamnoor')); }
        if (Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $access && Hamnoor_Campaign_Domain::CONTENT_QURAN !== $type) {
            return new WP_Error('hamnoor_command_personal_quran_only', __('در نسخه فعلی، پویش شخصی فقط برای یک ختم کامل قرآن پشتیبانی می‌شود.', 'hamnoor'));
        }
        $strategy = (Hamnoor_Campaign_Domain::CONTENT_QURAN === $type && Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $access)
            ? Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR
            : Hamnoor_Campaign_Domain::strategy_for_content($type);
        $data = array(
            'title'=>Hamnoor_Validator::trim_len(sanitize_text_field((string)($payload['title'] ?? '')), 191),
            'intention'=>wp_kses_post((string)($payload['intention'] ?? '')),
            'description'=>wp_kses_post((string)($payload['description'] ?? '')),
            'content_type'=>$type,
            'participation_strategy'=>$strategy,
            'access_mode'=>$access,
            'participation_policy'=>Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY === $access ? Hamnoor_Campaign_Domain::PARTICIPATION_OWNER_ONLY : Hamnoor_Campaign_Domain::PARTICIPATION_ANYONE,
            'indexable'=>Hamnoor_Campaign_Domain::ACCESS_PUBLIC === $access && !empty($payload['indexable']) ? 1 : 0,
            'starts_at'=>!empty($payload['starts_at']) ? Hamnoor_Validator::normalize_datetime((string)$payload['starts_at'], false) : null,
            'ends_at'=>!empty($payload['ends_at']) ? Hamnoor_Validator::normalize_datetime((string)$payload['ends_at'], true) : null,
        );
        if (!$data['title']) { return new WP_Error('hamnoor_command_title_required', __('عنوان پویش الزامی است.', 'hamnoor')); }

        if (Hamnoor_Campaign_Domain::CONTENT_QURAN === $type) {
            if (Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR === $strategy) {
                // No unit/part/round configuration exists in the personal-Quran product.
                $data['target_quantity'] = Hamnoor_Campaign_Domain::QURAN_END_GLOBAL_INDEX - Hamnoor_Campaign_Domain::QURAN_START_GLOBAL_INDEX + 1;
                return $data;
            }
            $q = isset($payload['quran']) && is_array($payload['quran']) ? $payload['quran'] : array();
            $data['quran'] = array(
                'unit'=>sanitize_key((string)($q['unit'] ?? 'page')),
                'rounds'=>max(1, min(100, (int)($q['rounds'] ?? 1))),
                'surah_number'=>max(1, min(114, (int)($q['surah_number'] ?? 1))),
                'repeat_count'=>max(1, min(1000, (int)($q['repeat_count'] ?? 100))),
            );
            return $data;
        }

        $item = Hamnoor_Content_Repository::get_by_public_id((string)($payload['content_id'] ?? ''));
        if (!$item || (string)$item->content_type !== $type || 'active' !== (string)$item->status || 1 !== (int)$item->campaign_enabled) { return new WP_Error('hamnoor_command_content_reference_invalid', __('محتوای انتخاب‌شده برای ایجاد پویش معتبر نیست.', 'hamnoor')); }
        $data['content_item_id'] = (int)$item->id;
        $data['target_quantity'] = max(0, (int)($payload['target'] ?? 0));
        return $data;
    }

    /** Product-level command: one complete Quran from start to finish, owned by one user. */
    public static function personal_quran_create($payload) {
        // Personal Quran is a product action, not the generic campaign wizard.
        // The user never chooses a title, division, schedule, visibility or copy.
        $payload = array(
            'title' => __('ختم شخصی قرآن', 'hamnoor'),
            'intention' => '',
            'description' => '',
            'content_type' => Hamnoor_Campaign_Domain::CONTENT_QURAN,
            'access_mode' => Hamnoor_Campaign_Domain::ACCESS_OWNER_ONLY,
            'indexable' => 0,
        );
        return self::campaign_create($payload);
    }

    /**
     * Canonical partial campaign edit contract.
     *
     * Ordinary fields remain backwards-compatible. Contribution campaigns may
     * additionally submit a CNT-* public content ID and target. Internal content
     * row IDs never cross the browser boundary; they are resolved here only.
     */
    public static function campaign_update($payload, $campaign = null) {
        $payload = is_array($payload) ? $payload : array();
        $out = array();
        foreach (array('title','intention','description','starts_at','ends_at','access_mode','indexable') as $key) {
            if (array_key_exists($key, $payload)) { $out[$key] = $payload[$key]; }
        }

        $has_content = array_key_exists('content_id', $payload);
        $has_target = array_key_exists('target', $payload);
        if (!$has_content && !$has_target) { return $out; }

        if (!$campaign || empty($campaign->id) || !in_array((string)($campaign->participation_strategy ?? ''), array(
            Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE,
            Hamnoor_Campaign_Domain::STRATEGY_NUMERIC,
        ), true)) {
            return new WP_Error('hamnoor_command_contribution_edit_invalid', __('ویرایش محتوا و هدف فقط برای پویش‌های دعا و ذکر مجاز است.', 'hamnoor'));
        }

        if ($has_content) {
            $public_id = strtoupper(sanitize_text_field((string)$payload['content_id']));
            $item = Hamnoor_Content_Repository::get_by_public_id($public_id);
            if (!$item
                || (string)$item->content_type !== (string)$campaign->content_type
                || 'active' !== (string)$item->status
                || 1 !== (int)$item->campaign_enabled) {
                return new WP_Error('hamnoor_command_content_reference_invalid', __('محتوای انتخاب‌شده برای ویرایش پویش معتبر نیست.', 'hamnoor'), array('field'=>'content_id'));
            }
            $out['content_item_id'] = (int)$item->id;
        }

        if ($has_target) {
            $target = (int)Hamnoor_Validator::normalize_digits((string)$payload['target']);
            if ($target < 1) {
                return new WP_Error('hamnoor_command_target_invalid', __('هدف پویش باید عددی بزرگ‌تر از صفر باشد.', 'hamnoor'), array('field'=>'target'));
            }
            $out['target_quantity'] = $target;
        }
        return $out;
    }

    public static function contribution($campaign, $payload) {
        if (!$campaign) { return new WP_Error('hamnoor_command_campaign_required', __('پویش معتبر نیست.', 'hamnoor')); }
        $payload = is_array($payload) ? $payload : array();
        $strategy = (string)$campaign->participation_strategy;
        if (Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE === $strategy) { $kind='completion'; $quantity=1; }
        elseif (Hamnoor_Campaign_Domain::STRATEGY_NUMERIC === $strategy) { $kind='numeric'; $quantity=max(0,(int)($payload['quantity'] ?? 0)); }
        else { return new WP_Error('hamnoor_command_contribution_strategy_invalid', __('این پویش از دفتر مشارکت عددی استفاده نمی‌کند.', 'hamnoor')); }
        $key = Hamnoor_Claim_Policy::normalize_idempotency_key((string)($payload['idempotency_key'] ?? ''));
        if (!$key) { return new WP_Error('hamnoor_command_idempotency_required', __('شناسه یکتای درخواست الزامی است.', 'hamnoor')); }
        return array('kind'=>$kind,'quantity'=>$quantity,'idempotency_key'=>$key);
    }
}
