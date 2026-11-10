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

$d = array(
    'queries' => array(
        array('query' => 'belt', 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 7),
        array('query' => 'hat', 'clicks' => 0, 'impressions' => 200, 'ctr' => 0, 'position' => 45),
        array('query' => 'tiny', 'clicks' => 0, 'impressions' => 5, 'ctr' => 0, 'position' => 9),
    ),
    'pages' => array(
        array('page' => '/jacket', 'clicks' => 0, 'impressions' => 40, 'ctr' => 0, 'position' => 8),
        array('page' => '/belts', 'clicks' => 20, 'impressions' => 200, 'ctr' => 0.1, 'position' => 9),
        array('page' => '/hats', 'clicks' => 3, 'impressions' => 570, 'ctr' => 0.005, 'position' => 51),
    ),
    'pairs' => array(
        array('query' => 'belt', 'page' => '/belts', 'impressions' => 60, 'position' => 6),
        array('query' => 'belt', 'page' => '/belts-old', 'impressions' => 40, 'position' => 9),
        array('query' => 'hat', 'page' => '/hats', 'impressions' => 200, 'position' => 45),
    ),
);
$o = S::opportunities($d);
check('near top lists belt with its page, not hat or tiny', count($o['near_top']) === 1 && $o['near_top'][0]['query'] === 'belt' && $o['near_top'][0]['page'] === '/belts');
check('low ctr finds the jacket page only', count($o['low_ctr']) === 1 && $o['low_ctr'][0]['page'] === '/jacket');
check('deep finds the hats page', count($o['deep']) === 1 && $o['deep'][0]['page'] === '/hats');
check('cannibalisation finds belt with two pages', count($o['cannibal']) === 1 && $o['cannibal'][0]['query'] === 'belt' && count($o['cannibal'][0]['pages']) === 2);
check('no data gives empty lists', S::opportunities(array()) === array('near_top' => array(), 'low_ctr' => array(), 'deep' => array(), 'cannibal' => array()));
check('expected ctr falls with position', S::expected_ctr(2) > S::expected_ctr(8) && S::expected_ctr(8) > S::expected_ctr(30));

$cards = S::plan($o);
$kinds = array_column($cards, 'kind');
check('plan has one card per opportunity', count($cards) === 4 && in_array('near_top', $kinds) && in_array('low_ctr', $kinds) && in_array('deep', $kinds) && in_array('cannibal', $kinds));
$byKind = array(); foreach ($cards as $c) $byKind[$c['kind']] = $c;
check('deep page below 30 gets a backlink action', in_array('backlink', array_column($byKind['deep']['actions'], 'type')));
check('near top at position 7 has no backlink action', !in_array('backlink', array_column($byKind['near_top']['actions'], 'type')));
check('low ctr is a meta job only', array_unique(array_column($byKind['low_ctr']['actions'], 'type')) === array('meta'));
check('cannibal suggests merging', in_array('merge', array_column($byKind['cannibal']['actions'], 'type')) && count($byKind['cannibal']['competing']) === 2);
check('cards sorted by potential', $cards[0]['potential'] >= $cards[count($cards) - 1]['potential']);
check('page kinds', S::page_kind('https://x.com/category/hats/') === 'category' && S::page_kind('https://x.com/') === 'home' && S::page_kind('https://x.com/blog/a') === 'page');
check('plan of nothing', S::plan(array('near_top' => array(), 'low_ctr' => array(), 'deep' => array(), 'cannibal' => array())) === array());

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
