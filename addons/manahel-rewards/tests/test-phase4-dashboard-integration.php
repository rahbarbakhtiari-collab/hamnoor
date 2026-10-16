<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/manahel-rewards.php');
$plugin = file_get_contents($root . '/includes/class-manahel-rewards-plugin.php');
$integration = file_get_contents($root . '/includes/class-manahel-rewards-hamnoor-dashboard-integration.php');
$frontend = file_get_contents($root . '/frontend/class-manahel-rewards-frontend.php');
$summary = file_get_contents($root . '/includes/class-manahel-rewards-user-summary-service.php');
$admin = file_get_contents($root . '/admin/class-manahel-rewards-admin.php');
$css = file_get_contents($root . '/assets/css/frontend.css');
$fail = array();
function mr4($ok, $message) { global $fail; echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; if (!$ok) { $fail[] = $message; } }

mr4(strpos($main, "MANAHEL_REWARDS_VERSION', '0.8.11'") !== false, 'plugin version is 0.8.11');
mr4(strpos($main, "MANAHEL_REWARDS_DB_VERSION', '1.3.0'") !== false, 'schema baseline now 1.3.0');
mr4(strpos($plugin, 'Manahel_Rewards_Hamnoor_Dashboard_Integration::init()') !== false, 'optional dashboard integration initializes');
mr4(strpos($integration, "add_action('hamnoor_dashboard_summary_extension'") !== false, 'integration listens only through generic Hamnoor dashboard hook');
mr4(strpos($integration, "add_filter('manahel_rewards_viewer_wp_user_id'") !== false, 'Hamnoor session can resolve Rewards viewer without WP auth cookie');
mr4(strpos($integration, '->wp_user_id') !== false && strpos($integration, "'mr_context'") !== false && strpos($integration, "'account_id'") === false && strpos($integration, "'user_id'") === false, 'dashboard identity comes from server-resolved Hamnoor user; context flag carries no user/account identifier');
mr4(strpos($summary, 'get_compact_for_wp_user') !== false, 'compact dashboard read model exists');
mr4(strpos($summary, 'get_compact_for_wp_user') < strpos($summary, 'get_for_wp_user'), 'compact read model is first-class service method');
mr4(strpos($integration, 'get_compact_for_wp_user') !== false && strpos($integration, 'get_for_wp_user') === false, 'dashboard card avoids full history/policy projection');
mr4(strpos($frontend, "OPTION_MY_POINTS_PAGE_ID") !== false && strpos($frontend, 'my_points_url') !== false, 'My Points destination is configurable');
mr4(strpos($admin, 'manahel_rewards_save_display_page') !== false && strpos($admin, 'my_points_page_id') !== false, 'admin can save published My Points page');
mr4(strpos($frontend, 'manahel_rewards_viewer_wp_user_id') !== false, 'shortcodes use extensible current-viewer resolution');
mr4(strpos($frontend, "'tab'=>'growth'") !== false && strpos($frontend, "'section'=>\$section") !== false, 'dashboard details link uses unified Hamnoor growth hub without embedding identity');
mr4(strpos($css, '.mr-hamnoor-dashboard-card') !== false, 'dashboard card styles are scoped');
mr4(strpos($frontend, "has_shortcode(\$content, 'hamnoor_dashboard')") !== false, 'dashboard stylesheet is pre-enqueued on the Hamnoor dashboard page');
mr4(strpos($integration, 'get_or_create') === false && strpos($integration, 'INSERT ') === false && strpos($integration, 'UPDATE ') === false, 'dashboard integration is read-only');
mr4(strpos($integration, 'DONOTCACHEPAGE') !== false, 'personalized dashboard card is cache protected');

if ($fail) { fwrite(STDERR, count($fail) . " Phase 4 check(s) failed.\n"); exit(1); }
echo "All Phase 4 dashboard integration source-contract tests passed.\n";
