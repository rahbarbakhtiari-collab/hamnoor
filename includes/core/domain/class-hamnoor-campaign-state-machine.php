<?php
if (!defined('ABSPATH')) { exit; }

/** Canonical lifecycle/review transition contract for future Campaign commands. */
final class Hamnoor_Campaign_State_Machine {
    public static function can_transition_lifecycle($from, $to) {
        $from = sanitize_key((string)$from); $to = sanitize_key((string)$to);
        if ($from === $to) { return true; }
        $map = array(
            Hamnoor_Campaign_Domain::LIFECYCLE_DRAFT=>array(Hamnoor_Campaign_Domain::LIFECYCLE_SCHEDULED,Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED,Hamnoor_Campaign_Domain::LIFECYCLE_FAILED),
            Hamnoor_Campaign_Domain::LIFECYCLE_SCHEDULED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED,Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED,Hamnoor_Campaign_Domain::LIFECYCLE_FAILED),
            Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE=>array(Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED,Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED,Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED,Hamnoor_Campaign_Domain::LIFECYCLE_FAILED),
            Hamnoor_Campaign_Domain::LIFECYCLE_PAUSED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_ACTIVE,Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED,Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED,Hamnoor_Campaign_Domain::LIFECYCLE_FAILED),
            Hamnoor_Campaign_Domain::LIFECYCLE_COMPLETED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED),
            Hamnoor_Campaign_Domain::LIFECYCLE_EXPIRED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED),
            Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED),
            Hamnoor_Campaign_Domain::LIFECYCLE_FAILED=>array(Hamnoor_Campaign_Domain::LIFECYCLE_DRAFT,Hamnoor_Campaign_Domain::LIFECYCLE_CANCELLED),
            Hamnoor_Campaign_Domain::LIFECYCLE_ARCHIVED=>array(),
        );
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }

    public static function can_transition_review($from, $to) {
        $from = sanitize_key((string)$from); $to = sanitize_key((string)$to);
        if ($from === $to) { return true; }
        $map = array(
            Hamnoor_Campaign_Domain::REVIEW_NOT_REQUIRED=>array(),
            Hamnoor_Campaign_Domain::REVIEW_PENDING=>array(Hamnoor_Campaign_Domain::REVIEW_APPROVED,Hamnoor_Campaign_Domain::REVIEW_NEEDS_REVISION,Hamnoor_Campaign_Domain::REVIEW_REJECTED),
            Hamnoor_Campaign_Domain::REVIEW_NEEDS_REVISION=>array(Hamnoor_Campaign_Domain::REVIEW_PENDING,Hamnoor_Campaign_Domain::REVIEW_REJECTED),
            Hamnoor_Campaign_Domain::REVIEW_APPROVED=>array(Hamnoor_Campaign_Domain::REVIEW_PENDING),
            Hamnoor_Campaign_Domain::REVIEW_REJECTED=>array(Hamnoor_Campaign_Domain::REVIEW_PENDING),
        );
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }

    public static function assert_lifecycle($from, $to) {
        return self::can_transition_lifecycle($from,$to) ? true : new WP_Error('hamnoor_campaign_transition_invalid', __('تغییر وضعیت پویش در این مرحله مجاز نیست.', 'hamnoor'), array('from'=>$from,'to'=>$to));
    }
}
