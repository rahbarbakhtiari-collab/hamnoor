<?php
if (!defined('ABSPATH')) { exit; }

final class Hamnoor_Participation_Strategy_Registry {
    private static $strategies = array();

    public static function register(Hamnoor_Participation_Strategy $strategy) {
        $key = sanitize_key((string)$strategy->key());
        if (!$key || !in_array($key, Hamnoor_Campaign_Domain::strategies(), true)) {
            return new WP_Error('hamnoor_strategy_invalid', __('راهبرد مشارکت معتبر نیست.', 'hamnoor'));
        }
        self::$strategies[$key] = $strategy;
        return true;
    }

    public static function get($key) {
        $key = sanitize_key((string)$key);
        return isset(self::$strategies[$key]) ? self::$strategies[$key] : null;
    }

    public static function all() { return self::$strategies; }

    public static function boot_defaults() {
        if (self::$strategies) { return; }
        self::register(new Hamnoor_Segmented_Claim_Strategy());
        self::register(new Hamnoor_Linear_Quran_Progress_Strategy());
        self::register(new Hamnoor_Repeatable_Completion_Strategy());
        self::register(new Hamnoor_Numeric_Contribution_Strategy());
    }
}

final class Hamnoor_Segmented_Claim_Strategy implements Hamnoor_Participation_Strategy {
    public function key() { return Hamnoor_Campaign_Domain::STRATEGY_SEGMENTED; }
    public function validate_campaign($campaign) { return $campaign && 'quran' === (string)$campaign->content_type ? true : new WP_Error('hamnoor_strategy_content_mismatch', __('راهبرد سهم‌بندی فقط برای محتوای بخش‌بندی‌شده مجاز است.', 'hamnoor')); }
    public function progress($campaign) { return Hamnoor_DB::get_progress((int)$campaign->id); }
    public function is_complete($campaign) { $p = $this->progress($campaign); return !empty($p['total']) && (int)$p['done'] >= (int)$p['total']; }
}

final class Hamnoor_Linear_Quran_Progress_Strategy implements Hamnoor_Participation_Strategy {
    public function key() { return Hamnoor_Campaign_Domain::STRATEGY_QURAN_LINEAR; }
    public function validate_campaign($campaign) { return Hamnoor_Campaign_Domain::is_personal_quran($campaign) ? true : new WP_Error('hamnoor_personal_quran_strategy_mismatch', __('پیشرفت خطی قرآن فقط برای ختم شخصی مالک مجاز است.', 'hamnoor')); }
    public function progress($campaign) { return Hamnoor_Personal_Quran_Progress_Service::progress($campaign); }
    public function is_complete($campaign) { $p = $this->progress($campaign); return !is_wp_error($p) && !empty($p['is_completed']); }
}

final class Hamnoor_Repeatable_Completion_Strategy implements Hamnoor_Participation_Strategy {
    public function key() { return Hamnoor_Campaign_Domain::STRATEGY_REPEATABLE; }
    public function validate_campaign($campaign) { return $campaign && (int)$campaign->target_quantity > 0 ? true : new WP_Error('hamnoor_target_required', __('هدف پویش باید بیشتر از صفر باشد.', 'hamnoor')); }
    public function progress($campaign) { return Hamnoor_Contribution_Service::progress((int)$campaign->id, (int)$campaign->target_quantity); }
    public function is_complete($campaign) { $p = $this->progress($campaign); return (int)$p['current'] >= (int)$p['target']; }
}

final class Hamnoor_Numeric_Contribution_Strategy implements Hamnoor_Participation_Strategy {
    public function key() { return Hamnoor_Campaign_Domain::STRATEGY_NUMERIC; }
    public function validate_campaign($campaign) { return $campaign && (int)$campaign->target_quantity > 0 ? true : new WP_Error('hamnoor_target_required', __('هدف عددی پویش باید بیشتر از صفر باشد.', 'hamnoor')); }
    public function progress($campaign) { return Hamnoor_Contribution_Service::progress((int)$campaign->id, (int)$campaign->target_quantity); }
    public function is_complete($campaign) { $p = $this->progress($campaign); return (int)$p['current'] >= (int)$p['target']; }
}
