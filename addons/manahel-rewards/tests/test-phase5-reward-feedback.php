<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/manahel-rewards.php');
$plugin = file_get_contents($root . '/includes/class-manahel-rewards-plugin.php');
$adapter = file_get_contents($root . '/includes/class-manahel-rewards-hamnoor-adapter.php');
$processor = file_get_contents($root . '/includes/class-manahel-rewards-event-processor.php');
$integration = file_get_contents($root . '/includes/class-manahel-rewards-hamnoor-feedback-integration.php');
$js = file_get_contents($root . '/assets/js/reward-feedback.js');
$css = file_get_contents($root . '/assets/css/frontend.css');
$fail = array();
function mr5($ok, $message) { global $fail; echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; if (!$ok) { $fail[] = $message; } }
mr5(strpos($main, "MANAHEL_REWARDS_VERSION', '0.8.11'") !== false, 'plugin version is 0.8.11');
mr5(strpos($main, "MANAHEL_REWARDS_DB_VERSION', '1.3.0'") !== false, 'schema baseline now 1.3.0');
mr5(strpos($plugin, 'Manahel_Rewards_Hamnoor_Feedback_Integration::init()') !== false, 'feedback integration initializes');
mr5(strpos($integration, "add_filter('hamnoor_completion_extension_feedback'") !== false, 'generic Hamnoor feedback filter is used');
mr5(strpos($adapter, 'request_result_for_payload') !== false, 'request-local processor result is available');
mr5(strpos($processor, "'balance' => \$balance_after") !== false, 'processor returns exact post-award balance');
mr5(strpos($integration, "'policy_excluded:dhikr'") === false, 'Dhikr is not opted into feedback announcements');
mr5(strpos($integration, 'limit.daily_awards') !== false && strpos($integration, 'limit.daily_points') !== false && strpos($integration, 'limit.cooldown') !== false, 'human limit feedback is supported');
mr5(strpos($integration, "'identity_unverified'") === false, 'current completion feedback no longer exposes OTP earning failure');
mr5(strpos($integration, '!empty($result[\'duplicate\'])') !== false, 'duplicate receipt feedback is suppressed');
mr5(strpos($js, "hamnoor:completion-feedback") !== false, 'browser listens to generic completion feedback event');
mr5(strpos($js, 'feedback_id') !== false && strpos($js, 'seen[id]') !== false, 'client prevents duplicate toast display');
mr5(strpos($js, 'data-mr-today-earned') !== false && strpos($js, 'data-mr-balance') !== false, 'dashboard card can update without refresh');
mr5(strpos($css, '.mr-feedback-toast') !== false && strpos($css, 'prefers-reduced-motion') !== false, 'accessible responsive toast styling exists');
if ($fail) { fwrite(STDERR, count($fail) . " Phase 5 check(s) failed.\n"); exit(1); }
echo "All Phase 5 reward-feedback source-contract tests passed.\n";
