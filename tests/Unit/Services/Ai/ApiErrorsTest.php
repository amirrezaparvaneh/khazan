<?php
/**
 * Every AI gateway failure becomes a Persian message with a next step; no English provider text leaks through.
 *
 *   php tests/Unit/Services/Ai/ApiErrorsTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
class WP_Error { public $c, $m, $d; function __construct($c = '', $m = '', $d = '') { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Ai\ApiErrors;
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function persian($s) { return !preg_match('/[A-Za-z]{4,}/', preg_replace('~https?://\S+|AvalAI~', '', $s)); }

$e = ApiErrors::wp_error(402, 'Your account credit has been exhausted. You don\'t have enough credit to make this request. Please top up your account or add more credit at https://ava.al/billing to continue using the service.');
check($e->get_error_code() === 'ai_no_credit' && strpos($e->get_error_message(), 'ava.al/billing') !== false, 'exhausted credit -> ai_no_credit with the billing link');
check(persian($e->get_error_message()), 'credit message is Persian');
$e = ApiErrors::wp_error(400, 'insufficient_quota: you exceeded your current quota');
check($e->get_error_code() === 'ai_no_credit', 'credit text under a 400 is still recognised');
check(ApiErrors::wp_error(401, 'Incorrect API key provided')->get_error_code() === 'ai_invalid_key', '401 -> invalid key');
check(ApiErrors::wp_error(404, 'The model `x` does not exist')->get_error_code() === 'ai_model_missing', 'missing model');
$e = ApiErrors::wp_error(429, 'Rate limit reached');
check($e->get_error_code() === 'ai_rate_limited' && $e->get_error_data()['status'] === 429, '429 keeps its status for the queue');
$e = ApiErrors::wp_error(503, '');
check($e->get_error_code() === 'ai_unavailable' && $e->get_error_data()['status'] === 503, '503 stays retryable');
check(ApiErrors::wp_error(500, 'Internal error')->get_error_data()['status'] === 503, '500 is retried too');
check(ApiErrors::wp_error(400, 'maximum context length is 128000 tokens')->get_error_code() === 'ai_too_long', 'context length');
check(ApiErrors::wp_error(400, 'Invalid schema for response_format')->get_error_code() === 'ai_error', 'other 400');
foreach (array(0, 400, 401, 402, 403, 404, 408, 413, 429, 500, 502, 503, 504) as $st) {
    check(persian(ApiErrors::wp_error($st, 'Some English provider text here')->get_error_message()), "status $st message is Persian");
}
foreach (array('cURL error 28: Operation timed out after 60001 milliseconds', 'cURL error 6: Could not resolve host: api.avalai.ir', 'cURL error 60: SSL certificate problem', 'cURL error 7: Failed to connect: Connection refused', 'weird') as $raw) {
    $e = ApiErrors::transport(new WP_Error('http_request_failed', $raw));
    check($e->get_error_code() === 'connection_failed' && persian($e->get_error_message()) && stripos($e->get_error_message(), 'curl') === false, 'transport: ' . substr($raw, 0, 24));
}
check(in_array('ai_no_credit', ApiErrors::FATAL, true), 'no credit stops a bulk run');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
