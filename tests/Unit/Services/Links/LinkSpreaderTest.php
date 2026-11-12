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
$base = "<p>$long</p><h2>جنس</h2><p>$long $a</p><p>$long</p><h2>استفاده</h2><p>$long</p><p>$long</p><h2>سوالات متداول</h2><p>$long</p><p>$long</p>";
$r = S::place($base, array(array('url' => 'https://x.ir/m', 'anchor' => 'نیم‌بوت مارلو'), array('url' => 'https://x.ir/l', 'anchor' => 'نیم‌بوت لارا')), 'x.ir');
preg_match_all('~<p\b[^>]*>.*?</p>~is', $r['html'], $ps);
$max = 0; foreach ($ps[0] as $p) $max = max($max, substr_count($p, '<a '));
check(!$r['missing'] && substr_count($r['html'], '<a ') === 3 && $max === 1, 'missing planned links are placed, one per free paragraph');
check(substr_count($ps[0][5], '<a ') === 0 && substr_count($ps[0][6], '<a ') === 0, 'placed links stay out of the FAQ and the closing paragraph');
preg_match_all('~([^.]*<a href="https://x.ir/[ml]">[^.]*\.)~u', $r['html'], $sent);
check(count($sent[1]) === 2 && $sent[1][0] !== str_replace('مارلو', 'لارا', $sent[1][1]), 'two placed links use different sentences');
$r2 = S::place("<p>$long $a</p><p>$long $c</p><p>$long</p>", array(array('url' => '/q', 'anchor' => 'ق')), 'x.ir');
check(count($r2['missing']) === 1, 'no free paragraph: the link is reported as still missing');
$faq = "<p>$long</p><h2>جنس</h2><p>$long $a</p><p>$long</p><h2>سوالات متداول</h2><h3>س؟</h3><p>جواب $b</p><p>فراخوان خرید.</p><p>گزینه‌های بیشتر: $c و <a href=\"https://x.ir/a\">لارا دوباره</a></p>";
$e = S::evict_closing($faq, 'x.ir');
check(substr_count($e['html'], '<a ') === 2, 'links after the last FAQ answer are unwrapped (FAQ answer and body links stay)');
check(count($e['links']) === 1 && $e['links'][0]['url'] === 'https://x.ir/c' && strpos($e['html'], 'گزینه‌های بیشتر') === false, 'a short link-only paragraph after the call to action is removed; only a link that would be lost is handed back');
$mixed = "<p>$long</p><h2>سوالات متداول</h2><h3>س؟</h3><p>جواب.</p><p>فراخوان خرید.</p><p>$long $c برای دیدن مدل‌های بیشتر.</p>";
$e2 = S::evict_closing($mixed, 'x.ir');
check(substr_count($e2['html'], '<a ') === 0 && count($e2['links']) === 1 && strpos($e2['html'], $long) !== false, 'a long paragraph after the call to action keeps its words');
check(S::evict_closing("<p>$long $a</p>", 'x.ir')['links'] === array(), 'no FAQ heading: nothing evicted');
$cat = '<a href="https://x.ir/cat">کلکسیون بوت</a>';
$near = "<p>مقدمه بوت $a</p><p>$cat را ببینید.</p><h2>جنس</h2><p>$long</p><p>$long</p><p>$long</p><h2>ست</h2><p>$long</p><p>$long</p><h2>سوالات متداول</h2><h3>س؟</h3><p>جواب.</p><p>فراخوان.</p>";
$sep = S::separate($near, 'x.ir', array('https://x.ir/cat'), 70);
preg_match_all('~<p\b[^>]*>.*?</p>~is', $sep, $pp);
check(substr_count($sep, '<a ') === 2 && strpos($pp[0][0], 'https://x.ir/cat') !== false && strpos($sep, 'مقدمه بوت') === false, 'a product link too close to the category link moves out of the intro (its mention sentence goes with it); the category link stays');
check(Khazan\Services\Content\ContentAudit::min_link_gap($sep, 'x.ir') >= 70 || Khazan\Services\Content\ContentAudit::min_link_gap($sep, 'x.ir') === null, 'after separating, the gap meets the standard');
check(S::separate($sep, 'x.ir', array('https://x.ir/cat'), 70) === $sep, 'idempotent');
$tiny = "<p>مقدمه $a</p><p>$cat</p>";
check(S::separate($tiny, 'x.ir', array('https://x.ir/cat'), 70) === $tiny, 'no free paragraph: links stay, nothing is dropped');
// the same link repeated: moving it never leaves unlinked copies of the mention sentence behind
$dup = "<p>مقدمه کوتاه درباره کفش و $cat را ببینید.</p><h2>الف</h2><p>$long برای مقایسه نگاهی به $a بیندازید.</p><p>$long $a هم هست.</p><h2>ب</h2><p>$long</p><p>$long</p><h2>سوالات متداول</h2><h3>س؟</h3><p>جواب.</p><p>فراخوان.</p>";
$d1 = S::separate(S::spread($dup, 'x.ir'), 'x.ir', array('https://x.ir/cat'), 70);
check(substr_count($d1, 'لارا') <= 2, 'a moved link leaves no extra plain mentions of its anchor');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
