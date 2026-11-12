<?php
/**
 * Stored content is repaired with the plugin's own guards, without a rewrite.
 *
 *   php tests/Unit/Services/Content/ContentFixerTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function get_option($k, $d = '') { return $d; } function apply_filters($t, $v) { return $v; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Content\ContentFixer as F;
use Khazan\Services\Seo\YoastRules as Y;
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$kw = 'کاپشن زنانه پلیسی سفید';
$L = function ($u, $t) { return '<a href="https://a.test/' . $u . '/">' . $t . '</a>'; };
$long = str_repeat('این متن برای پر کردن طول نوشته می‌آید و حرف تازه‌ای ندارد و جمله‌ای کوتاه است. ', 6);
$html = '<p>' . $kw . ' در قرار عصرانه خودش را نشان می‌دهد. برای مقایسه نگاهی به ' . $L('yaghe', 'کاپشن زنانه سمیرا یقه') . ' هم بیندازید.</p>'
    . '<p>' . $long . ' ' . $L('cat', 'مجموعه مدل‌های کاپشن چرم زنانه') . ' را ببینید.</p>'
    . '<h2>جنس و ساخت</h2><p>رویه ' . $kw . ' از چرم ساخته شده است. ' . $long . '</p><p>' . $long . ' ' . $L('yaghe', 'کاپشن زنانه سمیرا یقه') . ' هم هست.</p>'
    . '<h2>مزایا و ارزش خرید ' . $kw . '</h2><p>' . $kw . ' خوب است. ' . $kw . ' شیک است. ' . $kw . ' گرم است. ' . $long . '</p>'
    . '<h2>موقعیت‌های استفاده</h2><p>' . $kw . ' با جین. ' . $kw . ' با بوت. ' . $kw . ' با کیف. ' . $long . '</p><p>' . $long . '</p>'
    . '<h2>سوالات متداول</h2><h3>چطور نگهداری کنم؟</h3><p>با دستمال نرم.</p><h3>این مدل با چه ست می‌شود؟</h3><p>با جین.</p>'
    . '<p>اگر یک انتخاب روشن می‌خواهید، ' . $kw . ' را جدی بگیرید.</p><p>برای دیدن بیشتر به ' . $L('black', 'کاپشن زنانه پلیسی مشکی') . ' سر بزنید.</p>';
$anchors = array('https://a.test/yaghe/' => array('anchor' => 'کاپشن زنانه سمیرا یقه ایستاده', 'title' => 'کاپشن چرم طبیعی زنانه مدل سمیرا یقه ایستاده'),
    'https://a.test/black/' => array('anchor' => 'کاپشن زنانه پلیسی مشکی', 'title' => 'کاپشن چرم زنانه مدل پلیسی مشکی'));
$r = F::fix($html, array('kind' => 'product', 'focus' => $kw, 'home_host' => 'a.test', 'self_url' => 'https://a.test/self/', 'anchors' => $anchors, 'keep' => array('https://a.test/cat/')));
$out = $r['html'];
check(strpos($out, 'سمیرا یقه ایستاده</a>') !== false && strpos($out, 'سمیرا یقه</a>') === false, 'cut anchor gets the whole product name');
check(substr_count($out, 'href="https://a.test/yaghe/"') === 1, 'a URL is linked once');
check(strpos($out, 'href="https://a.test/black/"') !== false && strpos($out, 'سر بزنید.</p>') === false || strpos(substr($out, strrpos($out, 'جدی بگیرید')), '<a ') === false, 'nothing is linked after the closing call to action');
list(, $max) = Y::keyphrase_bounds(Y::word_count($out), $kw);
check(Y::keyphrase_count($out, $kw) <= $max, 'keyphrase count inside Yoast range');
check(strpos($out, 'ساخته شده') === false, 'passive word softened');
check(count($r['changes']) >= 4, 'changes are reported (' . implode(' / ', $r['changes']) . ')');
$again = F::fix($out, array('kind' => 'product', 'focus' => $kw, 'home_host' => 'a.test', 'anchors' => $anchors, 'keep' => array('https://a.test/cat/')));
check($again['changes'] === array(), 'a fixed text needs no further change');
check(F::fix('<p>متن سالم و کوتاه.</p>', array('kind' => 'article'))['html'] === '<p>متن سالم و کوتاه.</p>', 'healthy text stays byte-identical');

// descriptive anchors and anchors equal to the own keyphrase are left alone
$desc = '<p>نگاهی به <a href="https://a.test/yaghe/">مدل‌های یقه‌دار زمستانی</a> بیندازید.</p>';
check(F::fix_anchors($desc, $anchors) === $desc, 'descriptive anchor kept');

// AI patches only touch plain blocks
$seg = F::segments('<p>جمله‌ای ساده بدون لینک که طولانی است.</p><p>دارای <a href="/x">لینک</a> است و طولانی است.</p>');
check($seg === array('جمله‌ای ساده بدون لینک که طولانی است.'), 'only plain blocks are offered to the AI');
$patched = F::apply_patches('<p>جمله‌ای ساده بدون لینک که طولانی است.</p>', array(array('before' => 'جمله‌ای ساده بدون لینک که طولانی است.', 'after' => 'جمله تازه $1 بدون مشکل.')));
check($patched === '<p>جمله تازه $1 بدون مشکل.</p>', 'patch applied literally');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
