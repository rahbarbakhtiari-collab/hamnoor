<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Very small service registry. The container stays deliberately small: services are references/objects, not a
 * framework-level DI system. Optional add-ons may register/replace contracts.
 */
class Hamnoor_Service_Container {
    private $services = array();

    public function register($id, $service, $replace = false) {
        $id = sanitize_key((string) $id);
        if (!$id) { return false; }
        if (!$replace && array_key_exists($id, $this->services)) { return false; }
        $this->services[$id] = $service;
        return true;
    }

    public function has($id) {
        return array_key_exists(sanitize_key((string) $id), $this->services);
    }

    public function get($id, $default = null) {
        $id = sanitize_key((string) $id);
        return array_key_exists($id, $this->services) ? $this->services[$id] : $default;
    }

    public function all() {
        return $this->services;
    }
}
