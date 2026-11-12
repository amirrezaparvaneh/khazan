<?php
/**
 * Unit tests: SeoStandards, LinkPolicy and ContentAudit.
 *
 *   php tests/Unit/Services/Content/ContentAuditTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $d; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentAudit;
use Khazan\Services\Content\SeoStandards;
use Khazan\Services\Links\LinkPolicy;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

// ---- LinkPolicy: counts come from the rules, never exceed real candidates
check('category min at 1300 words', LinkPolicy::internal_target(Settings::KIND_CATEGORY, 1300) === 5);
check('category max at the max body length', LinkPolicy::internal_target(Settings::KIND_CATEGORY, 1500) === 8);
check('category mid at 1400 words', in_array(LinkPolicy::internal_target(Settings::KIND_CATEGORY, 1400), array(6, 7), true));
check('short text clamps to min', LinkPolicy::internal_target(Settings::KIND_CATEGORY, 400) === 5);
check('product range 3..6', LinkPolicy::internal_target(Settings::KIND_PRODUCT, 550) === 3 && LinkPolicy::internal_target(Settings::KIND_PRODUCT, 800) === 6);
list($pmin, $pmax) = Khazan\Services\Content\SeoStandards::words_range(Settings::KIND_PRODUCT);
check('product has its own length range', $pmin < 1300 && $pmax >= $pmin);
$prodHtml = '<h2>کیف چرم</h2><p>' . str_repeat('کیف چرم مدل ', 2) . str_repeat('متن ', $pmin) . '</p>';
$pr = ContentAudit::analyze($prodHtml, 'کیف چرم', array('kind' => Settings::KIND_PRODUCT));
check('product audit: length follows the product range and no table is required', !isset($pr['failures']['length_short']) && !isset($pr['failures']['table_missing']));
$pr = ContentAudit::analyze($prodHtml, 'کیف چرم', array('kind' => Settings::KIND_ARTICLE, 'words_min' => $pmin + 100));
check('article audit: table required, custom words_min honoured', isset($pr['failures']['table_missing']) && isset($pr['failures']['length_short']));
check('never more than real candidates', LinkPolicy::internal_target(Settings::KIND_CATEGORY, 2000, 4) === 4);
check('no candidates, no links', LinkPolicy::internal_target(Settings::KIND_CATEGORY, 1500, 0) === 0);
check('external capped at 2', LinkPolicy::external_target(5) === 2);
check('external 0 when no verified source', LinkPolicy::external_target(0) === 0);
check('internal_ok bounds', LinkPolicy::internal_ok(Settings::KIND_CATEGORY, 5) && LinkPolicy::internal_ok(Settings::KIND_CATEGORY, 8) && !LinkPolicy::internal_ok(Settings::KIND_CATEGORY, 4) && !LinkPolicy::internal_ok(Settings::KIND_CATEGORY, 9));

// ---- ContentAudit helpers
check('words ignore tags and punctuation', ContentAudit::word_count('<p>سلام، دنیا! <b>خوب</b></p>') === 3);
check('ZWNJ and yeh/kaf normalised', ContentAudit::phrase_count('<p>كيف چرم</p>', 'کیف‌ چرم') === 1);
check('phrase counted word-bounded', ContentAudit::phrase_count('<p>کیف چرمی و کیف چرم</p>', 'کیف چرم') === 1);
check('first words', ContentAudit::phrase_in_first('<p>کیف چرم عالی است</p>', 'کیف چرم', 100));

// ---- full analyze on a synthetic page
$filler = str_repeat('این جمله برای پر کردن متن آزمایشی نوشته شده و معنای خاصی ندارد. ', 1);
$para = function ($n) use ($filler) { return '<p>' . str_repeat($filler, $n) . '</p>'; };
$html = '<h2>راهنمای کیف چرم زنانه</h2><p>کیف چرم زنانه برای روزمره است. ' . $filler . '</p>' . $para(3)
    . '<h2>انتخاب مدل</h2>' . $para(3) . '<table><tr><td>الف</td></tr></table>'
    . '<h2>پرسش‌های متداول</h2><p>نگاه کنید به <a href="https://akaleather.com/a/">الف</a> و <a href="https://akaleather.com/a/">تکرار</a> و <a href="https://example.org/x">منبع</a>.</p>';
$r = ContentAudit::analyze($html, 'کیف چرم زنانه', array('kind' => Settings::KIND_CATEGORY, 'home_host' => 'akaleather.com'));
check('counts internal/external', $r['internal_links'] === 2 && $r['external_links'] === 1);
check('detects duplicate url', count($r['duplicate_urls']) === 1 && isset($r['failures']['duplicate_links']));
check('detects short length', isset($r['failures']['length_short']));
check('detects few internal links', isset($r['failures']['internal_links']));
check('detects links per paragraph', isset($r['failures']['links_per_paragraph']));
check('table found', $r['tables'] === 1 && !isset($r['failures']['table_missing']));
check('keyword in h2 counted', $r['keyword_in_h2'] === 1 && !isset($r['failures']['keyword_not_in_heading']));
$h3only = ContentAudit::analyze('<h2>انتخاب مدل</h2><p>متن.</p><h3>کیف چرم زنانه چطور است؟</h3><p>خوب.</p>', 'کیف چرم زنانه', array('kind' => Settings::KIND_CATEGORY, 'home_host' => 'akaleather.com'));
check('keyword only in an H3 still fails: an H2 must carry it', isset($h3only['failures']['keyword_not_in_heading']));
check('h1 in body flagged', isset(ContentAudit::analyze('<h1>الف</h1>', 'الف')['failures']['h1_in_body']));

// ---- titles / meta
$t = ContentAudit::analyze('<p>x</p>', 'کیف چرم', array('title' => 'کیف چرم؛ ارسال رایگان انواع مدل | آکا چرم', 'meta' => 'کوتاه'));
check('forbidden phrase in title', isset($t['failures']['title_forbidden']));
check('meta length flagged', isset($t['failures']['meta_length']) && isset($t['failures']['meta_keyword']));

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
