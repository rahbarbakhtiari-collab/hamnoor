#!/usr/bin/env php
<?php
/** Hamnoor core lifecycle scheduler for DirectAdmin cron (recommended: every minute). */
if ('cli' !== PHP_SAPI) { exit(1); }

$wp_load = getenv('HAMNOOR_WP_LOAD');
if (!$wp_load) { $wp_load = dirname(__DIR__, 4) . '/wp-load.php'; }
if (!is_file($wp_load) || !is_readable($wp_load)) {
    fwrite(STDERR, "Hamnoor Scheduler: wp-load.php not found.\n");
    exit(2);
}
require_once $wp_load;

if (!class_exists('Hamnoor_DB')) {
    fwrite(STDERR, "Hamnoor Scheduler: core plugin is not active.\n");
    exit(3);
}

// Prevent overlapping cron runs. The option/transient is shared with web/WP-Cron fallbacks.
if (get_transient('hamnoor_core_scheduler_cli_lock')) { exit(0); }
set_transient('hamnoor_core_scheduler_cli_lock', '1', 50);
try {
    Hamnoor_DB::release_due_parts();
    if (class_exists('Hamnoor_Personal_Reminders')) { Hamnoor_Personal_Reminders::run_due(); }
    delete_transient('hamnoor_core_scheduler_cli_lock');
    exit(0);
} catch (Throwable $e) {
    delete_transient('hamnoor_core_scheduler_cli_lock');
    fwrite(STDERR, 'Hamnoor Scheduler: ' . $e->getMessage() . "\n");
    exit(4);
}
