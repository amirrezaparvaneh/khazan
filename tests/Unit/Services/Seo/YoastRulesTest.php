<?php
/**
 * Generated product copy passes the Yoast checks (passive voice, keyphrase count, keyphrase in subheadings).
 *
 *   php tests/Unit/Services/Seo/YoastRulesTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function get_option($k, $d = '') { return $d; } function apply_filters($t, $v) { return $v; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Seo\YoastRules as Y;
use Khazan\Services\Content\Guard\YoastGuard as G;
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$kw = 'بوت چرم زنانه برشکا';
$filler = str_repeat('<p>این متن برای پر کردن طول نوشته می‌آید و حرف تازه‌ای ندارد. جمله دوم هم همین کار را می‌کند و کوتاه است.</p>', 12);
$html = '<p>وقتی بیرون می‌روید، ' . $kw . ' استایل شما را مرتب نشان می‌دهد. ' . $kw . ' انتخابی حساب شده است.</p>'
    . '<h2>جنس و ساخت</h2><p>رویه ' . $kw . ' از چرم ساخته شده است. این رویه هماهنگ‌تر دیده می‌شود. اگر دنبال ' . $kw . ' هستید، این ترکیب خوب است.</p>'
    . '<h2>مزایا و ارزش خرید ' . $kw . '</h2><ul><li><strong>کفی طبی:</strong> هنگام قدم زدن فشار را پخش می‌کند.</li><li><strong>خرید:</strong> برای خریدن و پوشیدن آماده است.</li></ul>'
    . '<p>جایگاه ' . $kw . ' را بهتر بشناسید. ' . $kw . ' در استایل شهری می‌درخشد.</p>'
    . '<h2>موقعیت‌های استفاده</h2><p>' . $kw . ' کنار شلوار جین خوب است. این ' . $kw . ' طراحی شده تا راحت باشد.</p>' . $filler
    . '<h2>سوالات متداول</h2><h3>چطور از چرم این بوت نگهداری کنم؟</h3><p>با دستمال نرم تمیز کنید.</p><h3>این بوت با چه لباس‌هایی ست می‌شود؟</h3><p>' . $kw . ' با جین ست می‌شود.</p><h3>برای استفاده روزانه مناسب است؟</h3><p>بله، مناسب است.</p>'
    . '<p>اگر بوتی می‌خواهید، ' . $kw . ' انتخابی منطقی است.</p>';

// --- rules (as Yoast counts)
check(Y::is_passive('رویه از چرم ساخته شده است.') && Y::is_passive('برای پوشیدن مناسب است') && Y::is_passive('آماده ارسال است'), 'listed words make a sentence passive');
check(!Y::is_passive('انتخابی حساب‌شده است') && !Y::is_passive('هنگام قدم‌زدن راحت است'), 'half-space compounds are not passive');
check(Y::keyphrase_count('<p>' . $kw . ' خوب است. برشکا یک بوت زنانه از چرم است.</p>', $kw) === 2, 'scattered keyphrase words in one sentence count too');
list($min, $max) = Y::keyphrase_bounds(600, $kw);
check($min === 2 && $max === 8, "bounds for 600 words / 4-word keyphrase are 2..8 (got $min..$max)");
$s = Y::subheadings($html, $kw);
check($s['count'] === 7 && $s['matches'] === 1 && $s['min'] === 3, 'before: 1 of 7 subheadings reflects the keyphrase, 3 needed');

// --- guard
$before = Y::passive($html);
$fixed = G::apply($html, $kw);
$after = Y::passive($fixed);
check($before['percent'] > 10 && $after['percent'] <= 9, "passive share drops under the limit ({$before['percent']}% -> {$after['percent']}%)");
check(strpos($fixed, 'به چشم می‌آید') !== false && strpos($fixed, "قدم\u{200c}زدن") !== false && strpos($fixed, "طراحی\u{200c}شده") !== false, 'safe rewrites and compound spelling applied');
$s = Y::subheadings($fixed, $kw);
check($s['matches'] >= $s['min'] && $s['matches'] <= $s['max'], "subheadings reflect the keyphrase ({$s['matches']} of {$s['count']})");
check(substr_count($fixed, '<h2>مزایا و ارزش خرید ' . $kw . '</h2>') === 1, 'H2 with the keyphrase is untouched');
list(, $max) = Y::keyphrase_bounds(Y::word_count($fixed), $kw);
$count = Y::keyphrase_count($fixed, $kw);
check($count <= $max && $count >= 2, "keyphrase count inside Yoast range ($count <= $max)");
check(strpos($fixed, '<p>وقتی بیرون می‌روید، ' . $kw) !== false && strpos($fixed, 'می‌خواهید، ' . $kw . ' انتخابی منطقی') !== false, 'first and last mention stay');
check(strpos($fixed, 'این این') === false, 'no doubled «این»');
check(G::apply($fixed, $kw) === $fixed, 'idempotent');
$link = '<p>ببینید <a href="/x">' . $kw . ' شده</a> را.</p>';
check(G::soften_passive($link, $kw) === $link, 'link text is never changed');

$audit = Khazan\Services\Content\ContentAudit::analyze($fixed, $kw, array('kind' => 'product'));
check(!isset($audit['failures']['passive_high']) && !isset($audit['failures']['density_high']) && !isset($audit['failures']['subheading_keyword_low']), 'audit: no Yoast failure after the guard');
$audit = Khazan\Services\Content\ContentAudit::analyze($html, $kw, array('kind' => 'product'));
check(isset($audit['failures']['passive_high'], $audit['failures']['subheading_keyword_low']), 'audit: raw text reports passive share and subheadings');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
