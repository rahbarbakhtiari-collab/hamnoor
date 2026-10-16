<?php
if (!defined('ABSPATH')) {
    exit;
}

class Hamnoor_Activator {
    public static function activate() {
        $scheduled = array();
        try {
            Hamnoor_DB::install();
            Hamnoor_DB::assert_tables_health('activation-final');

            self::schedule_required_event('hamnoor_auto_release_parts', 'hourly', $scheduled);
            self::schedule_required_event('hamnoor_daily_cleanup', 'daily', $scheduled);
            self::schedule_required_event('hamnoor_internal_reminders', 'hourly', $scheduled);

            do_action('hamnoor_activate_addons');
            Hamnoor_Router::instance()->register_rewrite_rules();
            flush_rewrite_rules(false);

            update_option('hamnoor_activation_status', array(
                'status' => 'ok',
                'version' => HAMNOOR_VERSION,
                'db_version' => HAMNOOR_DB_VERSION,
                'at' => current_time('mysql'),
            ), false);
            delete_option('hamnoor_last_boot_error');
        } catch (Throwable $error) {
            foreach ($scheduled as $hook) {
                wp_clear_scheduled_hook($hook);
            }
            $message = sprintf('Hamnoor activation error: %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine());
            error_log($message);
            update_option('hamnoor_last_boot_error', $message, false);
            update_option('hamnoor_activation_status', array(
                'status' => 'failed',
                'version' => defined('HAMNOOR_VERSION') ? HAMNOOR_VERSION : '',
                'db_version' => defined('HAMNOOR_DB_VERSION') ? HAMNOOR_DB_VERSION : '',
                'at' => current_time('mysql'),
                'error' => substr($error->getMessage(), 0, 500),
            ), false);

            // WordPress must see activation failure. Swallowing this exception
            // can leave a partially installed plugin marked as active.
            throw $error;
        }
    }

    private static function schedule_required_event($hook, $recurrence, &$scheduled) {
        if (wp_next_scheduled($hook)) {
            return;
        }
        $result = wp_schedule_event(time() + HOUR_IN_SECONDS, $recurrence, $hook, array(), true);
        if (is_wp_error($result)) {
            throw new RuntimeException(sprintf(
                'Hamnoor could not schedule %s: %s',
                $hook,
                $result->get_error_message()
            ));
        }
        if (true !== $result) {
            throw new RuntimeException(sprintf('Hamnoor could not schedule required cron event: %s', $hook));
        }
        $scheduled[] = $hook;
    }
}
