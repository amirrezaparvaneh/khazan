<?php
/** php tests/Unit/Services/Links/LinkSpreadTest.php */
define('ABSPATH', '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
if (!function_exists('apply_filters')) { function apply_filters($n, $v) { return $v; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
use Khazan\Services\Content\ContentAudit as A;
use Khazan\Services\Links\SmartLinkDistributor as D;

$passed = 0; $failed = 0;
function check($ok, $m) { global $passed, $failed; if ($ok) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

check(D::value_weight(0) === 1.0 && D::value_weight(10000) > 1.99 && D::value_weight(100) > D::value_weight(10), 'value weight: 1.0 without traffic, about 2.0 at 10 000 impressions, rising with traffic');
check(D::value_weight(1000000) <= 2.0, 'value weight never exceeds 2.0 (a valueless page is never starved)');

$w = str_repeat('کلمه ', 120);
check(A::min_link_gap("<p>یک <a href=\"/a\">الف</a> دو <a href=\"/b\">ب</a></p>", 'x.com') === 1, 'bunched links: gap of 1 word');
check(A::min_link_gap("<p>$w<a href=\"/a\">الف</a></p><p>$w<a href=\"/b\">ب</a></p>", 'x.com') >= 100, 'spread links: gap above 100 words');
check(A::min_link_gap("<p>$w <a href=\"https://other.org/x\">خارج</a> کلمه <a href=\"/b\">ب</a></p>", 'x.com') === null, 'external links do not count');

$bunched = '<p>' . $w . '<a href="/a">الف</a> و <a href="/b">ب</a></p>';
$r = A::analyze($bunched, 'کلمه', array('kind' => 'product', 'home_host' => 'x.com'));
check(isset($r['failures']['links_clustered']), 'the audit reports bunched internal links');
$spread = '<p>' . $w . '<a href="/a">الف</a></p><p>' . $w . '<a href="/b">ب</a></p>';
$r = A::analyze($spread, 'کلمه', array('kind' => 'product', 'home_host' => 'x.com'));
check(!isset($r['failures']['links_clustered']), 'spread links pass');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
