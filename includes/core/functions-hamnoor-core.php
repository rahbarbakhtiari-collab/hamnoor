<?php
if (!defined('ABSPATH')) { exit; }

/** Public helper functions intended for add-on authors. */
function hamnoor_core_api() { return Hamnoor_Core_API::instance(); }
function hamnoor_events() { return Hamnoor_Event_Bus::instance(); }
function hamnoor_addon_manager() { return Hamnoor_Addon_Manager::instance(); }
function hamnoor_register_addon($manifest, $bootstrap = null) { return Hamnoor_Addon_Manager::instance()->register($manifest, $bootstrap); }
function hamnoor_register_addon_instance($addon) { return Hamnoor_Addon_Manager::instance()->register_instance($addon); }

/** Return the optional notification engine contract, or null when disabled/unavailable. */
function hamnoor_notification_engine() { return Hamnoor_Core_API::instance()->notifications(); }
