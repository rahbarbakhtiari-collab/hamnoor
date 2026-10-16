<?php
if (!defined('ABSPATH')) { exit; }

class Hamnoor_Core_Health {
    public static function snapshot() {
        $core = Hamnoor_Core::instance();
        $addons = Hamnoor_Addon_Manager::instance();
        return array(
            'core_version' => defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '',
            'core_api_version' => defined('HAMNOOR_CORE_API_VERSION') ? HAMNOOR_CORE_API_VERSION : '',
            'architecture_version' => defined('HAMNOOR_ARCHITECTURE_VERSION') ? HAMNOOR_ARCHITECTURE_VERSION : '',
            'db_code_version' => defined('HAMNOOR_DB_VERSION') ? HAMNOOR_DB_VERSION : '',
            'db_installed_version' => (string) get_option('hamnoor_db_version', ''),
            'components' => $core->component_states(),
            'services' => array_keys($core->services()->all()),
            'addons' => $addons->states(),
            'builtin_addons' => class_exists('Hamnoor_Builtin_Addon_Loader') ? Hamnoor_Builtin_Addon_Loader::states() : array(),
            'addon_runtime_errors' => get_option('hamnoor_addon_runtime_errors', array()),
        );
    }
}
