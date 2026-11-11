<?php
/** php tests/Unit/Services/Links/LinkSpreaderTest.php */
define('ABSPATH', '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Links\LinkSpreader as S;

$passed = 0; $failed = 0;
function check($ok, $m) { global $passed, $failed; if ($ok) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
$long = 'این یک پاراگراف معمولی و به اندازه‌ی کافی بلند است که بتواند یک جمله‌ی تازه را در پایان خود بپذیرد.';
$a = '<a href="https://x.ir/a">لارا</a>'; $b = '<a href="/b">مارلو</a>'; $c = '<a href="https://x.ir/c">استلا</a>';
$html = "<p>$long</p><h2>جنس</h2><p>$long</p><p>مدل $a و $b برای مقایسه مفیدند.</p><h2>استفاده</h2><p>$long</p><p>$long $c</p><h2>سوالات متداول</h2><h3>س؟</h3><p>$long</p><p>$long</p>";
$out = S::spread($html, 'x.ir');
preg_match_all('~<p\b[^>]*>.*?</p>~is', $out, $ps);
$max = 0; foreach ($ps[0] as $p) $max = max($max, substr_count($p, '<a '));
check($max === 1, 'no paragraph keeps more than one internal link');
check(substr_count($out, '<a ') === 3 && strpos($out, 'href="/b"') !== false, 'no link is lost');
check(strpos($out, "مدل $a و مارلو برای مقایسه مفیدند.") !== false, 'the moved link keeps its words in the original sentence');
check(substr_count($ps[0][5], '<a ') === 0 && substr_count($ps[0][6], '<a ') === 0, 'FAQ answers and the closing paragraph receive nothing');
check(S::spread($out, 'x.ir') === $out, 'idempotent');
$tight = "<p>$long</p><p>مدل $a و $b.</p><p>$long $c</p>";
check(substr_count(S::spread($tight, 'x.ir'), '<a ') === 3, 'without a free paragraph the links stay');
$ext = "<p>$long</p><p>$a و <a href=\"https://other.com/z\">منبع</a></p><p>$long</p><p>$long</p>";
check(S::spread($ext, 'x.ir') === $ext, 'an external link does not count as a second internal link');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
