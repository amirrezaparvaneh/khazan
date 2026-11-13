<?php
/**
 * Stored product copy gets the keyphrase in the first words and one H2, long sections are split, a low keyphrase count is raised.
 *
 *   php tests/Unit/Services/Content/StructureGuardTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function get_option($k, $d = '') { return $d; } function apply_filters($t, $v) { return $v; } function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Content\ContentFixer as F;
use Khazan\Services\Content\ContentAudit as A;
use Khazan\Services\Content\Guard\StructureGuard as S;
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$kw = 'کفش مجلسی زنانه پارمیس';
$fill = function ($n) { $w = array('رنگ','دوخت','پاشنه','کفی','راحتی','ظاهر','استفاده','روزانه','مهمانی','سبک','نرم','مقاوم'); $o = array(); for ($i = 0; $i < $n; $i++) $o[] = $w[$i % count($w)]; return implode(' ', $o); };
$html = '<p>این کفش برای قرارهای رسمی ساخته شده است. ' . $fill(110) . '.</p>'
    . '<h2>جنس و ساخت</h2><p>' . $fill(90) . '.</p><p>کفش را با لباس رسمی ست کنید. ' . $fill(90) . '.</p><p>' . $fill(90) . '.</p>'
    . '<h2>مزایا</h2><p>این کفش سبک است. ' . $fill(60) . ' کفش، همراه شماست.</p>'
    . '<h2>سوالات متداول</h2><h3>چطور نگهداری کنم؟</h3><p>' . $fill(30) . '.</p>';
$opts = array('kind' => 'product', 'home_host' => 'a.test');
$before = A::analyze($html, $kw, $opts)['failures'];
check(isset($before['keyword_not_in_first']) && isset($before['keyword_not_in_heading']) && isset($before['section_long']), 'the sample has the three structure failures');
$out = S::apply($html, $kw);
$after = A::analyze($out, $kw, $opts)['failures'];
check(!isset($after['keyword_not_in_first']), 'keyphrase now in the first 100 words');
check(!isset($after['keyword_not_in_heading']), 'one H2 carries the keyphrase');
check(!isset($after['section_long']), 'the long section is split by a subheading');
check(!isset($after['keyword_h2_many']), 'no more H2 with the keyphrase than allowed');
check(strpos($out, '<h2>سوالات متداول</h2>') !== false, 'the FAQ heading is untouched');

$many = '<h2>' . $kw . ' یک</h2><p>x</p><h2>' . $kw . ' دو</h2><p>x</p><h2>' . $kw . ' سه</h2><p>x</p>';
$cut = S::limit_h2_keyphrase($many, $kw);
check(substr_count($cut, $kw) === 2 && strpos($cut, '<h2>کفش سه</h2>') !== false, 'extra H2 fall back to the product type');

$r = F::fix($html, array('kind' => 'product', 'focus' => $kw, 'home_host' => 'a.test'));
$fa = A::analyze($r['html'], $kw, $opts)['failures'];
check(!isset($fa['keyword_not_in_first']) && !isset($fa['keyword_not_in_heading']) && !isset($fa['section_long']), 'the fixer applies the structure guard');
check(Khazan\Services\Seo\YoastRules::keyphrase_count($r['html'], $kw) > Khazan\Services\Seo\YoastRules::keyphrase_count($html, $kw), 'the keyphrase count goes up from the available spots');

// keyphrase still too rare: neutral closing sentences with the keyphrase are added to body paragraphs
$para = function ($n) use ($fill) { return '<p>' . $fill($n) . '.</p>'; };
$rare = '<h2>الف</h2>' . $para(90) . $para(90) . '<h2>ب</h2>' . $para(90) . $para(90) . $para(90) . '<h2>سوالات متداول</h2><h3>q</h3>' . $para(40) . $para(30);
$g = 'Khazan\\Services\\Content\\Guard\\YoastGuard';
$boosted = $g::boost_keyphrase($rare, $kw);
check(Khazan\Services\Seo\YoastRules::keyphrase_count($boosted, $kw) > Khazan\Services\Seo\YoastRules::keyphrase_count($rare, $kw), 'boost adds keyphrase sentences');
check(substr_count($boosted, '<h2>سوالات متداول</h2><h3>q</h3><p>' . $fill(40) . '.</p>') === 1, 'the FAQ answers are untouched');
$fl = array('https://a.test/c/');
$lk = function ($u) { return '<a href="https://a.test/' . $u . '/">محصول ' . $u . '</a>'; };
$close = '<p>' . $fill(60) . ' ' . $lk('p1') . ' ' . $fill(5) . '.</p><h2>الف</h2><p>' . $fill(30) . ' ' . $lk('p2') . ' ' . $fill(20) . '.</p>' . $para(80) . $para(80) . $para(80) . '<h2>ب</h2>' . $para(80) . $para(80) . '<h2>سوالات متداول</h2><h3>q</h3>' . $para(40) . $para(30);
check(A::min_link_gap($close, 'a.test') < 70, 'the sample has two links closer than 70 words');
$apart = Khazan\Services\Links\LinkSpreader::separate($close, 'a.test', array(), 70);
check(A::min_link_gap($apart, 'a.test') >= 70, 'the links are moved at least 70 words apart');

// the audit counts the exact phrase; raising must reach its minimum too
$rare2 = '<h2>الف</h2>' . $para(90) . $para(90) . '<h2>ب</h2>' . $para(90) . $para(90) . $para(90) . '<h2>سوالات متداول</h2><h3>q</h3>' . $para(40) . $para(30);
$g2 = $g::boost_keyphrase($g::raise_keyphrase($rare2, $kw), $kw);
$aud = A::analyze($g2, $kw, $opts)['failures'];
check(!isset($aud['density_low']), 'the exact-phrase minimum of the audit is reached' . (isset($aud['density_low']) ? ' (' . $aud['density_low'] . ')' : ''));
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
