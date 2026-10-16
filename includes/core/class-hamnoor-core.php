<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Hamnoor 4.x architecture kernel.
 *
 * Architecture 3.1 exposes canonical Campaign/domain services behind stable
 * service IDs while the existing Quran UI remains a compatibility adapter.
 * Optional capabilities stay behind add-on boundaries.
 */
class Hamnoor_Core {
    private static $instance = null;
    private $initialized = false;
    private $services;
    private $component_states = array();

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) { return; }
        $this->initialized = true;
        $this->services = new Hamnoor_Service_Container();
        $this->register_services();
        Hamnoor_Legacy_Event_Bridge::init();
        $this->component_states['core'] = array('status' => 'booted', 'error' => '');
        do_action('hamnoor_core_ready', Hamnoor_Core_API::instance(), $this);
    }

    public function services() {
        if (!$this->services) { $this->services = new Hamnoor_Service_Container(); $this->register_services(); }
        return $this->services;
    }

    /**
     * Isolate optional runtime boot failures. This cannot catch a PHP parse
     * error in a file loaded before the boundary; physical package extraction
     * in later 4.x milestones will address that stronger isolation layer.
     */
    public function boot_optional_component($id, $callback) {
        $id = sanitize_key((string) $id);
        if (!$id || !is_callable($callback)) { return false; }
        try {
            call_user_func($callback);
            $this->component_states[$id] = array('status' => 'booted', 'error' => '');
            return true;
        } catch (Throwable $error) {
            $this->component_states[$id] = array('status' => 'failed', 'error' => $error->getMessage());
            if (function_exists('hamnoor_record_runtime_error')) { hamnoor_record_runtime_error($error, 'component_' . $id); }
            do_action('hamnoor_component_failed', $id, $error);
            return false;
        }
    }

    public function mark_component($id, $status = 'booted', $error = '') {
        $id = sanitize_key((string) $id);
        if (!$id) { return; }
        $this->component_states[$id] = array('status' => sanitize_key((string) $status), 'error' => (string) $error);
    }

    public function component_states() { return $this->component_states; }

    private function register_services() {
        $map = array(
            // Canonical domain services. New add-ons must prefer these IDs.
            'campaigns' => 'Hamnoor_Campaign_Service',
            'command_contracts' => 'Hamnoor_Command_Contracts',
            'campaign_repository' => 'Hamnoor_Campaign_Repository',
            'content_repository' => 'Hamnoor_Content_Repository',
            'contributions' => 'Hamnoor_Contribution_Service',
            'participation_strategies' => 'Hamnoor_Participation_Strategy_Registry',
            'access_policy' => 'Hamnoor_Access_Policy',
            'reminder_schedules' => 'Hamnoor_Reminder_Schedule_Service',
            'domain_event_store' => 'Hamnoor_Domain_Event_Store',
            // Compatibility adapters for the existing Quran implementation.
            'khatms' => 'Hamnoor_Khatm_Service',
            'parts' => 'Hamnoor_Part_Service',
            'participants' => 'Hamnoor_Participant_Service',
            'mobile_access' => 'Hamnoor_Mobile_Access',
            'reading' => 'Hamnoor_Reading',
            'db' => 'Hamnoor_DB',
        );
        foreach ($map as $id => $service) { $this->services->register($id, $service); }
    }
}
