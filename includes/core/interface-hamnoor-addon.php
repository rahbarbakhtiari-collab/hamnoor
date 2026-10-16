<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional contract for first-party and third-party Hamnoor add-ons.
 *
 * Add-ons should keep their file loading side-effect free. Runtime work belongs
 * in boot(), which is executed by Hamnoor_Addon_Manager inside an isolation
 * boundary so one failing add-on does not abort the remaining add-ons.
 */
interface Hamnoor_Addon_Interface {
    /**
     * Return add-on metadata.
     * Required key: id. Recommended: name, version, requires_core,
     * requires_api, dependencies.
     *
     * @return array
     */
    public function manifest();

    /**
     * Boot the add-on against the stable Core API.
     *
     * @param Hamnoor_Core_API $api
     * @return void
     */
    public function boot($api);
}
