<?php
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/admin/class-manahel-rewards-admin.php');
$main = file_get_contents($root . '/manahel-rewards.php');
$installer = file_get_contents($root . '/includes/class-manahel-rewards-installer.php');
$fail = array();
function p8($ok, $message) { global $fail; echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . "\n"; if (!$ok) { $fail[] = $message; } }

p8(false !== strpos($main, "Version: 0.8.11") && false !== strpos($main, "MANAHEL_REWARDS_VERSION', '0.8.11'"), 'plugin version is 0.8.11');
p8(false !== strpos($main, "MANAHEL_REWARDS_DB_VERSION', '1.3.0'"), 'rule editing adds redemption + digital code inventory schema baseline is 1.3.0');
p8(false !== strpos($admin, "admin_post_manahel_rewards_update_rule") && false !== strpos($admin, "handle_update_rule"), 'dedicated update action is registered');
p8(false !== strpos($admin, "check_admin_referer('manahel_rewards_update_rule_' . \$id)"), 'per-rule update nonce is enforced');
p8(false !== strpos($admin, "self::guard();") && false !== strpos($admin, "current_user_can(MANAHEL_REWARDS_CAP)"), 'Rewards capability gate remains enforced');
p8(false !== strpos($admin, "original_updated_at") && false !== strpos($admin, "hash_equals((string)\$rule->updated_at, \$original_updated_at)"), 'stale edit conflict detection is present');
p8(false !== strpos($admin, "Deliberately immutable here: code, source, event_name, entity_type, conditions_json"), 'technical rule identity is explicitly immutable in update flow');
p8(false !== strpos($admin, "'title' => \$title") && false !== strpos($admin, "'points' => \$points") && false !== strpos($admin, "'enabled' => !empty(\$_POST['enabled'])") && false !== strpos($admin, "'limits_json' => wp_json_encode(\$limits"), 'economic policy fields are persisted by edit flow');
p8(false !== strpos($admin, "limits_valid_for_points") && false !== strpos($admin, "rule_invalid_limits"), 'impossible point caps are rejected');
p8(false !== strpos($admin, "admin_datetime_to_utc_mysql") && false !== strpos($admin, "utc_mysql_to_admin_datetime") && false !== strpos($admin, "policy_timezone()"), 'policy date UI converts through configured policy timezone');
p8(false !== strpos($admin, "rule_invalid_window") && false !== strpos($admin, "\$starts >= \$ends"), 'invalid or zero-length policy windows are rejected');
p8(false !== strpos($admin, "ویرایش") && false !== strpos($admin, "edit_rule"), 'existing-rule table exposes edit action');
p8(false !== strpos($admin, "تغییر امتیازها روی سوابق قبلی اثر نمی‌گذارد"), 'admin UI explains historical Ledger immutability');
p8(false !== strpos($installer, "if (\$exists) { continue; }"), 'version upgrade does not overwrite existing administrator-edited rules');

// Scope the handler and assert immutable integration columns are absent from the UPDATE payload.
$start = strpos($admin, 'public static function handle_update_rule()');
$end = strpos($admin, 'public static function handle_toggle_rule()', $start);
$handler = ($start !== false && $end !== false) ? substr($admin, $start, $end - $start) : '';
p8($handler !== '', 'update handler can be isolated for contract inspection');
$update_start = strpos($handler, '$wpdb->update');
$update_block = $update_start !== false ? substr($handler, $update_start) : '';
p8(false === strpos($update_block, "'code' =>") && false === strpos($update_block, "'source' =>") && false === strpos($update_block, "'event_name' =>") && false === strpos($update_block, "'entity_type' =>") && false === strpos($update_block, "'conditions_json' =>"), 'update payload cannot mutate technical integration identity');

if ($fail) { fwrite(STDERR, count($fail) . " test(s) failed.\n"); exit(1); }
echo "All Phase 8 rule-editing contract tests passed.\n";
