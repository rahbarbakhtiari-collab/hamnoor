<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Manifest-driven add-on manager.
 *
 * Boots built-in and external add-ons through the same manifest/dependency
 * contract. Alpha3 uses this boundary for the separate Web Push package.
 */
class Hamnoor_Addon_Manager {
    private static $instance = null;
    private $addons = array();
    private $states = array();
    private $discovered = false;
    private $booted = false;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function register($manifest, $bootstrap = null) {
        if ($manifest instanceof Hamnoor_Addon_Interface) {
            return $this->register_instance($manifest);
        }
        if (!is_array($manifest)) { return new WP_Error('hamnoor_addon_manifest_invalid', __('مانیفست افزونه هم‌نور معتبر نیست.', 'hamnoor')); }

        $manifest = $this->normalize_manifest($manifest);
        if (is_wp_error($manifest)) { return $manifest; }
        $id = $manifest['id'];
        if (isset($this->addons[$id])) {
            return new WP_Error('hamnoor_addon_duplicate', sprintf(__('افزونه «%s» قبلاً ثبت شده است.', 'hamnoor'), $id));
        }
        if (null !== $bootstrap && !is_callable($bootstrap)) {
            return new WP_Error('hamnoor_addon_bootstrap_invalid', sprintf(__('Bootstrap افزونه «%s» قابل اجرا نیست.', 'hamnoor'), $id));
        }

        $this->addons[$id] = array('manifest' => $manifest, 'bootstrap' => $bootstrap, 'instance' => null);
        $this->states[$id] = array('status' => 'registered', 'reason' => '', 'booted_at' => '', 'last_error' => '');
        return true;
    }

    public function register_instance($addon) {
        if (!($addon instanceof Hamnoor_Addon_Interface)) {
            return new WP_Error('hamnoor_addon_interface_required', __('افزونه باید قرارداد Hamnoor_Addon_Interface را پیاده‌سازی کند.', 'hamnoor'));
        }
        $manifest = $this->normalize_manifest($addon->manifest());
        if (is_wp_error($manifest)) { return $manifest; }
        $id = $manifest['id'];
        if (isset($this->addons[$id])) {
            return new WP_Error('hamnoor_addon_duplicate', sprintf(__('افزونه «%s» قبلاً ثبت شده است.', 'hamnoor'), $id));
        }
        $this->addons[$id] = array(
            'manifest' => $manifest,
            'bootstrap' => array($addon, 'boot'),
            'instance' => $addon,
        );
        $this->states[$id] = array('status' => 'registered', 'reason' => '', 'booted_at' => '', 'last_error' => '');
        return true;
    }

    public function discover() {
        if ($this->discovered) { return; }
        $this->discovered = true;
        do_action('hamnoor_register_addons', $this, Hamnoor_Core_API::instance());
    }

    public function boot_all() {
        if ($this->booted) { return $this->states; }
        $this->booted = true;
        $this->discover();

        $pending = array_keys($this->addons);
        $passes = count($pending) + 1;
        while ($pending && $passes-- > 0) {
            $next = array();
            $progress = false;
            foreach ($pending as $id) {
                $result = $this->boot_one($id, true);
                if ('defer' === $result) { $next[] = $id; }
                else { $progress = true; }
            }
            $pending = $next;
            if (!$progress) { break; }
        }

        foreach ($pending as $id) {
            $missing = $this->unready_dependencies($id);
            $this->set_state($id, 'blocked', 'dependency_not_ready:' . implode(',', $missing));
        }
        do_action('hamnoor_addons_booted', $this->states, $this);
        return $this->states;
    }

    public function boot_one($id, $allow_defer = false) {
        $id = sanitize_key((string) $id);
        if (empty($this->addons[$id])) { return false; }
        $manifest = $this->addons[$id]['manifest'];

        if (!$this->is_enabled($id)) {
            $this->set_state($id, 'disabled', 'feature_flag');
            return false;
        }
        $compat = $this->compatibility_error($manifest);
        if ($compat) {
            $this->set_state($id, 'incompatible', $compat);
            return false;
        }
        $unready = $this->unready_dependencies($id);
        if ($unready) {
            if ($allow_defer) { return 'defer'; }
            $this->set_state($id, 'blocked', 'dependency_not_ready:' . implode(',', $unready));
            return false;
        }

        $bootstrap = $this->addons[$id]['bootstrap'];
        if (null === $bootstrap) {
            $this->set_state($id, 'booted', 'manifest_only');
            return true;
        }
        try {
            call_user_func($bootstrap, Hamnoor_Core_API::instance());
            $this->set_state($id, 'booted', '');
            return true;
        } catch (Throwable $error) {
            $this->record_runtime_failure($id, $error, 'boot');
            return false;
        }
    }

    public function set_enabled($id, $enabled) {
        $id = sanitize_key((string) $id);
        if (!$id) { return false; }
        $flags = get_option('hamnoor_addon_states', array());
        $flags = is_array($flags) ? $flags : array();
        $flags[$id] = $enabled ? '1' : '0';
        update_option('hamnoor_addon_states', $flags, false);
        return true;
    }

    public function is_enabled($id) {
        $id = sanitize_key((string) $id);
        $flags = get_option('hamnoor_addon_states', array());
        $flags = is_array($flags) ? $flags : array();
        if (array_key_exists($id, $flags)) { return '0' !== (string) $flags[$id]; }
        if (isset($this->addons[$id]['manifest']) && array_key_exists('default_enabled', $this->addons[$id]['manifest'])) {
            return (bool) $this->addons[$id]['manifest']['default_enabled'];
        }
        return true;
    }

    public function manifests() {
        $out = array();
        foreach ($this->addons as $id => $addon) { $out[$id] = $addon['manifest']; }
        return $out;
    }

    public function states() { return $this->states; }

    public function state($id) {
        $id = sanitize_key((string) $id);
        return isset($this->states[$id]) ? $this->states[$id] : null;
    }

    public function record_runtime_failure($id, $error, $context = 'runtime') {
        $id = sanitize_key((string) $id) ?: 'unknown-addon';
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $this->set_state($id, 'degraded', sanitize_key((string) $context), $message);

        $log = get_option('hamnoor_addon_runtime_errors', array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, array(
            'addon' => $id,
            'context' => sanitize_key((string) $context),
            'message' => substr(wp_strip_all_tags($message), 0, 500),
            'at' => current_time('mysql'),
        ));
        $log = array_slice($log, 0, 20);
        update_option('hamnoor_addon_runtime_errors', $log, false);
        error_log(sprintf('Hamnoor add-on %s %s error: %s', $id, $context, $message));
    }

    private function normalize_manifest($manifest) {
        if (!is_array($manifest)) { return new WP_Error('hamnoor_addon_manifest_invalid', __('مانیفست افزونه معتبر نیست.', 'hamnoor')); }
        $id = !empty($manifest['id']) ? sanitize_key($manifest['id']) : '';
        if (!$id) { return new WP_Error('hamnoor_addon_id_required', __('شناسه افزونه هم‌نور الزامی است.', 'hamnoor')); }
        $deps = !empty($manifest['dependencies']) && is_array($manifest['dependencies']) ? array_values(array_unique(array_filter(array_map('sanitize_key', $manifest['dependencies'])))) : array();
        $manifest['id'] = $id;
        $manifest['dependencies'] = $deps;
        return wp_parse_args($manifest, array(
            'id' => $id,
            'name' => $id,
            'version' => '0.0.0',
            'requires_core' => '4.0.0',
            'requires_api' => '1.0.0',
            'dependencies' => $deps,
            'default_enabled' => true,
        ));
    }

    private function compatibility_error($manifest) {
        $core = defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '0.0.0';
        $api = defined('HAMNOOR_CORE_API_VERSION') ? HAMNOOR_CORE_API_VERSION : '0.0.0';
        if (!empty($manifest['requires_core']) && version_compare($core, $manifest['requires_core'], '<')) {
            return 'requires_core_' . sanitize_key($manifest['requires_core']);
        }
        if (!empty($manifest['requires_api']) && version_compare($api, $manifest['requires_api'], '<')) {
            return 'requires_api_' . sanitize_key($manifest['requires_api']);
        }
        return '';
    }

    private function unready_dependencies($id) {
        $manifest = $this->addons[$id]['manifest'];
        $missing = array();
        foreach ((array) $manifest['dependencies'] as $dependency) {
            if (!isset($this->addons[$dependency])) { $missing[] = $dependency; continue; }
            $state = isset($this->states[$dependency]['status']) ? $this->states[$dependency]['status'] : 'registered';
            if ('booted' !== $state) { $missing[] = $dependency; }
        }
        return $missing;
    }

    private function set_state($id, $status, $reason = '', $error = '') {
        if (!isset($this->states[$id])) {
            $this->states[$id] = array('status' => 'registered', 'reason' => '', 'booted_at' => '', 'last_error' => '');
        }
        $this->states[$id]['status'] = sanitize_key((string) $status);
        $this->states[$id]['reason'] = (string) $reason;
        $this->states[$id]['last_error'] = (string) $error;
        if ('booted' === $status) { $this->states[$id]['booted_at'] = current_time('mysql'); }
    }
}
