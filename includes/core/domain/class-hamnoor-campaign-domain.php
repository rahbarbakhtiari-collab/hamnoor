<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical campaign-domain vocabulary.
 *
 * A personal Quran completion is deliberately NOT a segmented claim campaign.
 * It is one owner-only Quran completion with a single linear reading position.
 */
final class Hamnoor_Campaign_Domain {
    const CONTENT_QURAN = 'quran';
    const CONTENT_DUA   = 'dua';
    const CONTENT_DHIKR = 'dhikr';

    const STRATEGY_SEGMENTED    = 'segmented_claim';
    const STRATEGY_QURAN_LINEAR = 'linear_quran_progress';
    const STRATEGY_REPEATABLE   = 'repeatable_completion';
    const STRATEGY_NUMERIC      = 'numeric_contribution';

    // Manahel Quran Core global verse indexes cover the canonical Quran once.
    const QURAN_START_GLOBAL_INDEX = 1;
    const QURAN_END_GLOBAL_INDEX   = 6236;

    const ACCESS_PUBLIC     = 'public';
    const ACCESS_UNLISTED   = 'unlisted';
    const ACCESS_OWNER_ONLY = 'owner_only';

    const PARTICIPATION_ANYONE     = 'anyone';
    const PARTICIPATION_OWNER_ONLY = 'owner_only';

    const LIFECYCLE_DRAFT     = 'draft';
    const LIFECYCLE_SCHEDULED = 'scheduled';
    const LIFECYCLE_ACTIVE    = 'active';
    const LIFECYCLE_PAUSED    = 'paused';
    const LIFECYCLE_COMPLETED = 'completed';
    const LIFECYCLE_EXPIRED   = 'expired';
    const LIFECYCLE_CANCELLED = 'cancelled';
    const LIFECYCLE_ARCHIVED  = 'archived';
    const LIFECYCLE_FAILED    = 'failed';

    const REVIEW_NOT_REQUIRED = 'not_required';
    const REVIEW_PENDING      = 'pending';
    const REVIEW_APPROVED     = 'approved';
    const REVIEW_NEEDS_REVISION = 'needs_revision';
    const REVIEW_REJECTED     = 'rejected';

    public static function content_types() {
        return array(self::CONTENT_QURAN, self::CONTENT_DUA, self::CONTENT_DHIKR);
    }

    public static function strategies() {
        return array(self::STRATEGY_SEGMENTED, self::STRATEGY_QURAN_LINEAR, self::STRATEGY_REPEATABLE, self::STRATEGY_NUMERIC);
    }

    /** Default strategy for ordinary campaign creation; product-specific commands may choose another allowed strategy. */
    public static function strategy_for_content($content_type) {
        $content_type = sanitize_key((string)$content_type);
        if (self::CONTENT_DUA === $content_type) { return self::STRATEGY_REPEATABLE; }
        if (self::CONTENT_DHIKR === $content_type) { return self::STRATEGY_NUMERIC; }
        return self::STRATEGY_SEGMENTED;
    }

    public static function strategies_for_content($content_type) {
        $content_type = sanitize_key((string)$content_type);
        if (self::CONTENT_QURAN === $content_type) { return array(self::STRATEGY_SEGMENTED, self::STRATEGY_QURAN_LINEAR); }
        if (self::CONTENT_DUA === $content_type) { return array(self::STRATEGY_REPEATABLE); }
        if (self::CONTENT_DHIKR === $content_type) { return array(self::STRATEGY_NUMERIC); }
        return array();
    }

    public static function strategy_allowed_for_content($content_type, $strategy) {
        return in_array(sanitize_key((string)$strategy), self::strategies_for_content($content_type), true);
    }

    public static function access_modes() {
        return array(self::ACCESS_PUBLIC, self::ACCESS_UNLISTED, self::ACCESS_OWNER_ONLY);
    }

    public static function participation_policies() {
        return array(self::PARTICIPATION_ANYONE, self::PARTICIPATION_OWNER_ONLY);
    }

    public static function lifecycle_statuses() {
        return array(
            self::LIFECYCLE_DRAFT, self::LIFECYCLE_SCHEDULED, self::LIFECYCLE_ACTIVE,
            self::LIFECYCLE_PAUSED, self::LIFECYCLE_COMPLETED, self::LIFECYCLE_EXPIRED,
            self::LIFECYCLE_CANCELLED, self::LIFECYCLE_ARCHIVED, self::LIFECYCLE_FAILED,
        );
    }

    public static function review_statuses() {
        return array(self::REVIEW_NOT_REQUIRED, self::REVIEW_PENDING, self::REVIEW_APPROVED, self::REVIEW_NEEDS_REVISION, self::REVIEW_REJECTED);
    }

    public static function is_personal_quran($campaign) {
        return $campaign
            && self::CONTENT_QURAN === (string)($campaign->content_type ?? '')
            && self::STRATEGY_QURAN_LINEAR === (string)($campaign->participation_strategy ?? '')
            && self::ACCESS_OWNER_ONLY === (string)($campaign->access_mode ?? '')
            && self::PARTICIPATION_OWNER_ONLY === (string)($campaign->participation_policy ?? '');
    }

    /** Lifecycle states that still occupy the owner's single active personal-Quran slot. */
    public static function personal_quran_open_statuses() {
        return array(self::LIFECYCLE_ACTIVE, self::LIFECYCLE_PAUSED);
    }

    public static function legacy_privacy_to_access($privacy) {
        // Legacy `private` campaigns were link-accessible (unlisted), not personal owner-only campaigns.
        return 'private' === sanitize_key((string)$privacy) ? self::ACCESS_UNLISTED : self::ACCESS_PUBLIC;
    }

    public static function legacy_status_to_domain($status) {
        $status = sanitize_key((string)$status);
        $map = array(
            'pending_review' => array(self::LIFECYCLE_DRAFT, self::REVIEW_PENDING),
            'needs_revision' => array(self::LIFECYCLE_DRAFT, self::REVIEW_NEEDS_REVISION),
            'rejected'       => array(self::LIFECYCLE_DRAFT, self::REVIEW_REJECTED),
            'active'         => array(self::LIFECYCLE_ACTIVE, self::REVIEW_APPROVED),
            'paused'         => array(self::LIFECYCLE_PAUSED, self::REVIEW_APPROVED),
            'expired'        => array(self::LIFECYCLE_EXPIRED, self::REVIEW_APPROVED),
            'completed'      => array(self::LIFECYCLE_COMPLETED, self::REVIEW_APPROVED),
            'cancelled'      => array(self::LIFECYCLE_CANCELLED, self::REVIEW_APPROVED),
            'archived'       => array(self::LIFECYCLE_ARCHIVED, self::REVIEW_APPROVED),
            'failed'         => array(self::LIFECYCLE_FAILED, self::REVIEW_PENDING),
        );
        return isset($map[$status]) ? $map[$status] : array(self::LIFECYCLE_DRAFT, self::REVIEW_PENDING);
    }

    public static function structural_fields() {
        return array(
            'content_type', 'participation_strategy', 'content_item_id',
            // Public <-> unlisted may remain editable. Owner-only personal Quran
            // is immutable as a product mode after creation.
            'target_quantity', 'participation_policy', 'khatm_type', 'khatm_count',
            'parts_per_khatm', 'total_parts', 'owner_user_id',
        );
    }
}
