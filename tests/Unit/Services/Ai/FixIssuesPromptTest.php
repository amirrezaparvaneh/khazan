<?php
/**
 * The Fix tab's AI prompt carries the exact failures and the patch cap is raised.
 *
 *   php tests/Unit/Services/Ai/FixIssuesPromptTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_text_field($s) { return trim($s); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { public function __construct($c = '', $m = '', $d = null) {} }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$p = (new Khazan\Services\Ai\PromptBuilder())->fix_issues(array('متن یک'), array('کلمه اصلی کم آمده'), array('focuskw' => 'کاپشن چرم'));
check(strpos($p, 'کلمه اصلی کم آمده') !== false, 'failures are in the prompt');
check(strpos($p, 'کاپشن چرم') !== false, 'focus phrase is in the prompt');

$segs = array(); $patches = array();
for ($i = 0; $i < 25; $i++) { $segs[$i] = "قطعه شماره $i برای آزمون"; $patches[] = array('index' => $i, 'before' => $segs[$i], 'after' => "قطعه تازه $i برای آزمون", 'reason' => 'x'); }
$r = Khazan\Services\Ai\ContentParser::repair_patches(array('patches' => $patches), $segs, 30);
check(is_array($r) && count($r) === 25, 'up to 30 patches accepted');
$r = Khazan\Services\Ai\ContentParser::repair_patches(array('patches' => $patches), $segs);
check(is_array($r) && count($r) === 20, 'default cap stays 20');
echo "\n$passed passed, $failed failed\n"; exit($failed ? 1 : 0);
