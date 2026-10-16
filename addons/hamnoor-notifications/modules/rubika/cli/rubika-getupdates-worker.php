#!/usr/bin/env php
<?php
/** Hamnoor Rubika real worker for DirectAdmin cron. */
if ('cli' !== PHP_SAPI) { exit(1); }

$wp_load = getenv('HAMNOOR_WP_LOAD');
if (!$wp_load) { $wp_load = dirname(__DIR__, 6) . '/wp-load.php'; }
if (!is_file($wp_load) || !is_readable($wp_load)) {
    fwrite(STDERR, "Hamnoor Rubika: wp-load.php not found.\n"); exit(2);
}
require_once $wp_load;

if (!class_exists('Hamnoor_Rubika')) {
    fwrite(STDERR, "Hamnoor Rubika module is not active.\n"); exit(3);
}
$result = Hamnoor_Rubika::run_cli_worker(55, 5);
if (is_array($result) && isset($result['status']) && in_array($result['status'], array('completed','locked'), true)) { exit(0); }
exit(4);
