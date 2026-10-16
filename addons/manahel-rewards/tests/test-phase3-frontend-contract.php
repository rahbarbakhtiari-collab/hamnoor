<?php
$root = dirname(__DIR__);
$must_exist = array(
    $root . '/includes/class-manahel-rewards-user-summary-service.php',
    $root . '/frontend/class-manahel-rewards-frontend.php',
    $root . '/assets/css/frontend.css',
);
foreach ($must_exist as $file) {
    if (!is_file($file)) { fwrite(STDERR, "Missing: {$file}\n"); exit(1); }
}
$bootstrap = file_get_contents($root . '/manahel-rewards.php');
$frontend = file_get_contents($root . '/frontend/class-manahel-rewards-frontend.php');
$summary = file_get_contents($root . '/includes/class-manahel-rewards-user-summary-service.php');
$installer = file_get_contents($root . '/includes/class-manahel-rewards-installer.php');
$checks = array(
    "version_current" => strpos($bootstrap, "MANAHEL_REWARDS_VERSION', '0.8.11") !== false,
    "schema_unchanged" => strpos($bootstrap, "MANAHEL_REWARDS_DB_VERSION', '1.3.0") !== false,
    "my_points_shortcode" => strpos($frontend, "manahel_rewards_my_points") !== false,
    "balance_shortcode" => strpos($frontend, "manahel_rewards_balance") !== false,
    "current_user_only" => strpos($frontend, 'get_current_user_id()') !== false,
    "no_shortcode_user_attr" => strpos($frontend, 'shortcode_atts') === false,
    "cache_guard" => strpos($frontend, 'DONOTCACHEPAGE') !== false,
    "read_only_no_account_create" => strpos($summary, 'get_or_create') === false,
    "ledger_history" => strpos($summary, 'recent_ledger') !== false,
    "daily_projection" => strpos($summary, 'today_earned') !== false,
    "policy_projection" => strpos($summary, 'policy_statuses') !== false,
    "no_new_schema_phase3" => strpos($installer, 'manahel_reward_user') === false,
);
foreach ($checks as $name=>$ok) {
    echo $name . ': ' . ($ok?'PASS':'FAIL') . PHP_EOL;
    if (!$ok) { exit(1); }
}
