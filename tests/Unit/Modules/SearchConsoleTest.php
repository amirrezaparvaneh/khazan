<?php
/**
 * Unit tests: service-account JWT and Search Console aggregation.
 *
 *   php tests/Unit/Modules/SearchConsoleTest.php
 */
define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Search\GoogleServiceAuth as A;
use Khazan\Modules\Search\SearchConsole as S;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

$res = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($res, $pem);
$pub = openssl_pkey_get_details($res)['key'];
$json = json_encode(array('type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => $pem));

$key = A::parse_key($json);
check('parses a key file', $key && $key['client_email'] === 'bot@proj.iam.gserviceaccount.com' && $key['token_uri'] === A::TOKEN_URI);
check('rejects garbage', A::parse_key('nope') === null && A::parse_key('{}') === null);
check('rejects a non service-account key', A::parse_key(json_encode(array('type' => 'authorized_user', 'client_email' => 'a@b.c', 'private_key' => $pem))) === null);
check('ignores a non-https token uri', A::parse_key(json_encode(array('client_email' => 'a@b.c', 'private_key' => $pem, 'token_uri' => 'http://evil')))['token_uri'] === A::TOKEN_URI);

$jwt = A::jwt($key, A::SCOPE_READONLY, 1000);
list($h, $c, $sig) = explode('.', $jwt);
$claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
check('claims', $claims['iss'] === $key['client_email'] && $claims['scope'] === A::SCOPE_READONLY && $claims['exp'] === 4600 && $claims['aud'] === A::TOKEN_URI);
check('signature verifies with the public key', openssl_verify($h . '.' . $c, base64_decode(strtr($sig, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256) === 1);
check('bad private key gives null', A::jwt(array('client_email' => 'a@b.c', 'private_key' => 'PRIVATE KEY junk', 'token_uri' => A::TOKEN_URI), 's', 1) === null);
check('url-safe base64', strpos($jwt, '+') === false && strpos($jwt, '/') === false && strpos($jwt, '=') === false);

$rows = array(
    array('keys' => array('چرم', '/a'), 'clicks' => 10, 'impressions' => 100, 'position' => 4),
    array('keys' => array('چرم', '/b'), 'clicks' => 0, 'impressions' => 300, 'position' => 8),
    array('keys' => array('کیف', '/a'), 'clicks' => 5, 'impressions' => 50, 'position' => 2),
    array('keys' => array('broken')),
);
$g = S::aggregate($rows);
check('queries aggregated and sorted', count($g['queries']) === 2 && $g['queries'][0]['query'] === 'چرم' && $g['queries'][0]['impressions'] === 400 && $g['queries'][0]['clicks'] === 10);
check('position is weighted by impressions', $g['queries'][0]['position'] === 7.0);
check('ctr from totals', $g['queries'][0]['ctr'] === 0.025);
check('pages aggregated', count($g['pages']) === 2 && $g['pages'][0]['page'] === '/b' && $g['pages'][1]['impressions'] === 150);
check('pairs keep query and page', count($g['pairs']) === 3 && $g['pairs'][0]['query'] === 'چرم' && $g['pairs'][0]['page'] === '/b');
check('empty input', S::aggregate(array()) === array('pages' => array(), 'queries' => array(), 'pairs' => array()));

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
