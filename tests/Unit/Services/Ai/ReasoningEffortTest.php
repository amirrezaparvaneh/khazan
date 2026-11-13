<?php
/**
 * reasoning_effort is sent only for GPT-5 / o-series models and follows the setting.
 *
 *   php tests/Unit/Services/Ai/ReasoningEffortTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
$GLOBALS['opts'] = array();
function get_option($k, $d = '') { return isset($GLOBALS['opts'][$k]) ? $GLOBALS['opts'][$k] : $d; }
function apply_filters($t, $v) { return $v; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Ai\AvalaiHttpClient;
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$b = (new AvalaiHttpClient('k', 'gpt-5.4-mini'))->build_request_body('x', 100);
check(($b['reasoning_effort'] ?? '') === 'low', 'gpt-5 defaults to low');
check(!isset($b['temperature']) && $b['max_completion_tokens'] === 100, 'gpt-5 keeps max_completion_tokens');
$b = (new AvalaiHttpClient('k', 'gpt-4o-mini'))->build_request_body('x', 100);
check(!isset($b['reasoning_effort']) && $b['max_tokens'] === 100, 'non-reasoning model gets no reasoning_effort');
$GLOBALS['opts']['wila_ai_reasoning'] = 'high';
$b = (new AvalaiHttpClient('k', 'o3-mini'))->build_request_body('x', 100);
check(($b['reasoning_effort'] ?? '') === 'high', 'o-series follows the setting');
$GLOBALS['opts']['wila_ai_reasoning'] = 'default';
$b = (new AvalaiHttpClient('k', 'gpt-5.4-mini'))->build_request_body('x', 100);
check(!isset($b['reasoning_effort']), "'default' sends nothing");
$GLOBALS['opts']['wila_ai_reasoning'] = 'bogus';
check(Khazan\Core\Settings::ai_reasoning() === 'low', 'invalid value falls back to low');
$GLOBALS['opts']['wila_ai_reasoning'] = 'high';
AvalaiHttpClient::force_reasoning('low');
$b = (new AvalaiHttpClient('k', 'gpt-5.4-mini'))->build_request_body('x', 100);
check(($b['reasoning_effort'] ?? '') === 'low', 'a forced level wins over a higher setting');
AvalaiHttpClient::force_reasoning(null);
$b = (new AvalaiHttpClient('k', 'gpt-5.4-mini'))->build_request_body('x', 100);
check(($b['reasoning_effort'] ?? '') === 'high', 'the setting applies again after the forced pass');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
