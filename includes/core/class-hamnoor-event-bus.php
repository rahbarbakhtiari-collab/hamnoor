<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Stable event facade for future add-ons.
 *
 * Event names are dot-separated, e.g. part.completed. Subscribers registered
 * through this bus are wrapped in a Throwable boundary. Direct WordPress hooks
 * remain available for backwards compatibility, but do not receive that
 * isolation guarantee.
 */
class Hamnoor_Event_Bus {
    private static $instance = null;
    private $wrappers = array();

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function emit($event, $payload = array(), $context = array()) {
        $event = $this->normalize_event($event);
        if (!$event) { return false; }
        $payload = is_array($payload) ? $payload : array('value' => $payload);
        $context = is_array($context) ? $context : array();
        $context = wp_parse_args($context, array(
            'event' => $event,
            'emitted_at' => current_time('mysql'),
            'core_version' => defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '',
        ));

        if (!empty($context['durable']) && !empty($context['event_key']) && class_exists('Hamnoor_Domain_Event_Store')) {
            $stored = Hamnoor_Domain_Event_Store::append($event, $payload, $context);
            if (is_wp_error($stored)) {
                $context['durable_event_error'] = $stored->get_error_code();
                if (function_exists('hamnoor_record_runtime_error')) {
                    hamnoor_record_runtime_error(new RuntimeException($stored->get_error_message()), 'domain_event_store');
                }
            } else {
                $context['durable_event_id'] = (int)$stored;
            }
        }

        do_action('hamnoor/event/' . $event, $payload, $context);
        do_action('hamnoor/event', $event, $payload, $context);
        return true;
    }

    public function on($event, $callback, $priority = 10, $owner = '') {
        $event = $this->normalize_event($event);
        if (!$event || !is_callable($callback)) { return false; }
        $owner = sanitize_key((string) $owner);
        $wrapper = $this->wrap_callback($callback, $owner, $event, 2);
        add_action('hamnoor/event/' . $event, $wrapper, (int) $priority, 2);
        $this->wrappers[] = array('hook' => 'hamnoor/event/' . $event, 'callback' => $wrapper, 'priority' => (int) $priority);
        return true;
    }

    public function on_any($callback, $priority = 10, $owner = '') {
        if (!is_callable($callback)) { return false; }
        $owner = sanitize_key((string) $owner);
        $wrapper = $this->wrap_callback($callback, $owner, '*', 3);
        add_action('hamnoor/event', $wrapper, (int) $priority, 3);
        $this->wrappers[] = array('hook' => 'hamnoor/event', 'callback' => $wrapper, 'priority' => (int) $priority);
        return true;
    }

    private function wrap_callback($callback, $owner, $event, $accepted_args) {
        return static function () use ($callback, $owner, $event, $accepted_args) {
            $args = array_slice(func_get_args(), 0, $accepted_args);
            try {
                return call_user_func_array($callback, $args);
            } catch (Throwable $error) {
                if (class_exists('Hamnoor_Addon_Manager')) {
                    Hamnoor_Addon_Manager::instance()->record_runtime_failure($owner ?: 'anonymous-listener', $error, 'event:' . $event);
                } elseif (function_exists('hamnoor_record_runtime_error')) {
                    hamnoor_record_runtime_error($error, 'event_' . sanitize_key(str_replace('.', '_', $event)));
                }
                return null;
            }
        };
    }

    private function normalize_event($event) {
        $event = strtolower(trim((string) $event));
        if (!$event || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $event)) { return ''; }
        return $event;
    }
}
