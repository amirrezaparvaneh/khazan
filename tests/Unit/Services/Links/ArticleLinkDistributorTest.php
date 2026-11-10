<?php
/**
 * Unit tests: article link budget, destination mix, balanced global pool, anchors and LinkGuard locking.
 *
 *   php tests/Unit/Services/Links/ArticleLinkDistributorTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($tag, $value) { return $value; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function home_url($p = '') { return 'https://aka.test' . $p; }
function get_option($k, $d = '') { return $d; }
function get_bloginfo($k) { return 'Site'; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Links\ArticleLinkBudget as B;
use Khazan\Services\Links\ArticleLinkDistributor;
use Khazan\Services\Links\ArticleLinkSource;
use Khazan\Services\Links\InMemoryAssignmentLedger;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function hrefs($html) { preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~i', $html, $m); return $m[2]; }

class FakeSource implements ArticleLinkSource {
    public $brand = array('url' => 'https://aka.test/', 'anchor' => 'چرم آکا');
    public $products = array(); public $articles = array();
    function __construct() {
        for ($i = 1; $i <= 5; $i++) $this->products[] = array('id' => 100 + $i, 'title' => "بوت چرم زنانه مدل $i", 'url' => "https://aka.test/product/p$i/", 'anchor' => "بوت چرم زنانه " . array('', 'برشکا', 'مارکو', 'آلفردو', 'رومینا', 'سارا')[$i], 'inbound' => $i === 3 ? 0 : 5, 'category_ids' => array(7));
        for ($i = 1; $i <= 4; $i++) $this->articles[] = array('id' => 200 + $i, 'title' => "راهنمای چرم " . array('', 'الف', 'ب', 'ج', 'د')[$i], 'url' => "https://aka.test/blog/a$i/", 'anchor' => "نگهداری چرم " . array('', 'الف', 'ب', 'ج', 'د')[$i], 'inbound' => $i === 2 ? 0 : 3);
    }
    function categories() { return array(array('id' => 7, 'name' => 'بوت چرم زنانه', 'url' => 'https://aka.test/cat/boots/'), array('id' => 8, 'name' => 'کمربند', 'url' => 'https://aka.test/cat/belt/', 'parent' => 0)); }
    function product_candidates(array $a, array $c, array $r, array $t) { return $this->products; }
    function article_candidates(array $a) { return $this->articles; }
    function brand_page() { return $this->brand; }
}
function types($links) { return array_count_values(array_map(function ($l) { return $l['type']; }, $links)); }
function params($words) { return array('h1_title' => 'راهنمای انتخاب بوت چرم زنانه', 'focus_keyword' => 'بوت چرم زنانه', 'long_tail' => '', 'word_count' => $words, 'internal_links' => array()); }

echo "budget\n";
list($lmin, $lmax) = Khazan\Services\Content\SeoStandards::internal_range('post');
check(B::for_words(300) === $lmin && B::for_words(1000) === $lmin, 'short articles get the rule minimum');
check(B::for_words(1500) === $lmax && B::for_words(4000) === $lmax, 'long articles get the rule maximum');
$prev = 0; $mono = true;
foreach (array(300, 1000, 1200, 1400, 1500, 3000) as $w) { $n = B::for_words($w); if ($n < $prev || $n < $lmin || $n > $lmax) $mono = false; $prev = $n; }
check($mono, 'budget grows with length and stays inside the rule range');

echo "mix\n";
$d = new ArticleLinkDistributor($src = new FakeSource(), new InMemoryAssignmentLedger());
$r = $d->apply(params(600));
check(count($r['internal_links']) === 3 && $r['link_budget'] === 3, '600 words -> 3 links');
check(types($r['internal_links']) === array('category' => 1, 'product' => 1, 'article' => 1), 'mix = category + product + article');
$r = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(1250));
check(count($r['internal_links']) === $r['link_budget'] && isset(types($r['internal_links'])['page']), '1250 words -> budget filled incl. brand page');
$r = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(1400));
$t = types($r['internal_links']);
check(count($r['internal_links']) === $r['link_budget'] && $t['category'] === 1 && $t['product'] === 3 && $t['article'] === 2 && $t['page'] === 1, '1400 words -> budget filled (1 cat, 3 prod, 2 art, 1 page)');
$r = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(2500));
$t = types($r['internal_links']);
check(count($r['internal_links']) === $lmax && $t['product'] === 3 && $t['article'] === 3 && $t['category'] === 1 && $t['page'] === 1, '2500 words -> rule maximum, never above 1/3/3/1');
$urls = array_column($r['internal_links'], 'url');
check(count($urls) === count(array_unique($urls)), 'no URL twice');
$none = new FakeSource(); $none->brand = null;
$r = (new ArticleLinkDistributor($none, new InMemoryAssignmentLedger()))->apply(params(1250));
check(count($r['internal_links']) === $r['link_budget'] && !isset(types($r['internal_links'])['page']), 'no brand context: no page link, slot goes to another kind');
$empty = new FakeSource(); $empty->articles = []; $empty->brand = null;
check(count((new ArticleLinkDistributor($empty, new InMemoryAssignmentLedger()))->apply(params(2500))['internal_links']) === 4, 'missing kinds: fewer links (category + 3 products), never padded');

echo "balanced pool\n";
$r = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(600));
$by = array(); foreach ($r['internal_links'] as $l) $by[$l['type']] = $l;
check($by['product']['url'] === 'https://aka.test/product/p3/', 'least-linked product (0 inbound) wins');
check($by['article']['url'] === 'https://aka.test/blog/a2/', 'least-linked article (0 inbound) wins');
$ledger = new InMemoryAssignmentLedger(); $seen = array();
for ($i = 0; $i < 5; $i++) { $fs = new FakeSource(); foreach ($fs->products as &$pr) $pr['inbound'] = 0; unset($pr); $x = (new ArticleLinkDistributor($fs, $ledger))->apply(params(600)); foreach ($x['internal_links'] as $l) if ($l['type'] === 'product') $seen[] = $l['url']; }
check(count(array_unique($seen)) >= 4, 'consecutive articles rotate over the pool: ' . count(array_unique($seen)) . ' distinct products in 5 runs');

echo "anchors\n";
check($by['product']['anchor'] === Khazan\Services\Seo\KeyphraseExtractor::cap('بوت چرم زنانه آلفردو') && Khazan\Services\Seo\KeyphraseExtractor::count_words($by['product']['anchor']) <= 4 && $by['product']['locked'], 'product: 4-word keyphrase, locked');
check($by['article']['anchor'] === 'نگهداری چرم ب' && $by['article']['locked'], 'article: target focus keyword, locked');
check(mb_strpos($by['category']['anchor'], 'بوت چرم زنانه') !== false && $by['category']['anchor'] !== 'بوت چرم زنانه' && $by['category']['url'] === 'https://aka.test/cat/boots/', 'category: prefix + name, matching category');
$pg = array(); foreach ((new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(1250))['internal_links'] as $l) $pg[$l['type']] = $l;
check($pg['page']['anchor'] === 'چرم آکا' && $pg['page']['url'] === 'https://aka.test/', 'page: brand name, homepage');
$manual = params(600); $manual['internal_links'] = array(array('anchor' => 'x', 'url' => 'https://aka.test/manual/'));
$r = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply($manual);
check(count($r['internal_links']) === 3 && $r['internal_links'][0]['url'] === 'https://aka.test/manual/', 'manual links kept and count against the budget');

echo "relevance tiers\n";
class TierSource extends FakeSource {
    function __construct() {
        parent::__construct();
        $this->brand = null; $this->articles = array();
        $this->products = array(
            array('id' => 1, 'title' => 'بوت چرم زنانه برشکا', 'url' => 'https://aka.test/product/boot1/', 'anchor' => 'بوت چرم زنانه برشکا', 'inbound' => 0, 'category_ids' => array(21)),
            array('id' => 2, 'title' => 'بوت چرم مردانه مارکو', 'url' => 'https://aka.test/product/boot2/', 'anchor' => 'بوت چرم مردانه مارکو', 'inbound' => 0, 'category_ids' => array(21)),
            array('id' => 3, 'title' => 'کاپشن چرم مردانه رادین', 'url' => 'https://aka.test/product/jacket1/', 'anchor' => 'کاپشن چرم مردانه رادین', 'inbound' => 9, 'category_ids' => array(11)),
            array('id' => 4, 'title' => 'کاپشن چرم زنانه سورنا', 'url' => 'https://aka.test/product/jacket2/', 'anchor' => 'کاپشن چرم زنانه سورنا', 'inbound' => 7, 'category_ids' => array(11)),
            array('id' => 5, 'title' => 'کت چرم کلاسیک آرمین', 'url' => 'https://aka.test/product/coat/', 'anchor' => 'کت چرم کلاسیک آرمین', 'inbound' => 2, 'category_ids' => array(12)),
            array('id' => 6, 'title' => 'کیف چرم اداری', 'url' => 'https://aka.test/product/bag/', 'anchor' => 'کیف چرم اداری', 'inbound' => 1, 'category_ids' => array(31)),
        );
    }
    function categories() { return array(
        array('id' => 10, 'name' => 'پوشاک چرم', 'url' => 'https://aka.test/cat/clothes/', 'parent' => 0),
        array('id' => 11, 'name' => 'کاپشن چرم', 'url' => 'https://aka.test/cat/jackets/', 'parent' => 10),
        array('id' => 12, 'name' => 'کت چرم', 'url' => 'https://aka.test/cat/coats/', 'parent' => 10),
        array('id' => 21, 'name' => 'بوت چرم', 'url' => 'https://aka.test/cat/boots/', 'parent' => 0),
        array('id' => 31, 'name' => 'کیف چرم', 'url' => 'https://aka.test/cat/bags/', 'parent' => 0)); }
}
$jacket = array('h1_title' => 'راهنمای خرید کاپشن چرم', 'focus_keyword' => 'کاپشن چرم', 'long_tail' => '', 'word_count' => 1000, 'internal_links' => array());
$r = (new ArticleLinkDistributor(new TierSource(), new InMemoryAssignmentLedger()))->apply($jacket);
$prod = array_values(array_filter($r['internal_links'], function ($l) { return $l['type'] === 'product'; }));
$purls = array_column($prod, 'url');
check($purls === array('https://aka.test/product/jacket2/', 'https://aka.test/product/jacket1/'), 'jacket article: both jackets (inbound 7 and 9) beat boots with 0 inbound; least-linked jacket first');
check(!in_array('https://aka.test/product/boot1/', $purls) && !in_array('https://aka.test/product/boot2/', $purls), 'no link from a jacket article to boots');
$cat = array_values(array_filter($r['internal_links'], function ($l) { return $l['type'] === 'category'; }));
check($cat && $cat[0]['url'] === 'https://aka.test/cat/jackets/', 'category link is the jacket category');

$one = new TierSource(); $one->products = array_values(array_filter($one->products, function ($p) { return $p['id'] !== 4; }));
$purls = array_column(array_filter((new ArticleLinkDistributor($one, new InMemoryAssignmentLedger()))->apply($jacket)['internal_links'], function ($l) { return $l['type'] === 'product'; }), 'url');
check($purls === array('https://aka.test/product/jacket1/', 'https://aka.test/product/coat/'), 'one jacket only: 2nd slot goes to the sibling category (coat, Tier 2), not boots with 0 inbound');

$none = new TierSource(); $none->products = array_values(array_filter($none->products, function ($p) { return in_array($p['id'], array(1, 2, 6)); }));
$purls = array_column(array_filter((new ArticleLinkDistributor($none, new InMemoryAssignmentLedger()))->apply($jacket)['internal_links'], function ($l) { return $l['type'] === 'product'; }), 'url');
check(count($purls) === 2 && $purls[0] === 'https://aka.test/product/boot1/', 'no jacket / related product at all: global Tier 3 fallback, least inbound first');

$led = new InMemoryAssignmentLedger(); $seen = array();
for ($i = 0; $i < 4; $i++) { $x = (new ArticleLinkDistributor(new TierSource(), $led))->apply($jacket); foreach ($x['internal_links'] as $l) if ($l['type'] === 'product') $seen[] = $l['url']; }
check(count(array_diff($seen, array('https://aka.test/product/jacket1/', 'https://aka.test/product/jacket2/', 'https://aka.test/product/coat/'))) === 0, 'repeated jacket articles keep rotating inside the relevant group only');
$title = array('h1_title' => 'نگهداری از بوت', 'focus_keyword' => 'نگهداری بوت', 'long_tail' => '', 'word_count' => 600, 'internal_links' => array());
$bt = array_values(array_filter((new ArticleLinkDistributor(new TierSource(), new InMemoryAssignmentLedger()))->apply($title)['internal_links'], function ($l) { return $l['type'] === 'product'; }));
check($bt && strpos($bt[0]['url'], 'boot') !== false, 'boot article links to a boot (generic words such as چرم / نگهداری never decide relevance)');

echo "LinkGuard\n";
$links = (new ArticleLinkDistributor(new FakeSource(), new InMemoryAssignmentLedger()))->apply(params(1250))['internal_links'];
$u = array(); foreach ($links as $l) $u[$l['type']] = $l;
$html = '<p>یک <a href="' . $u['product']['url'] . '">کفش دیگر</a> و <a href="' . $u['article']['url'] . '">اینجا کلیک کنید</a> و <a href="' . $u['category']['url'] . '">فروشگاه</a>.</p>'
    . '<p>و <a href="https://aka.test/random/">لینک اضافه</a> و <a href="https://aka.test/random2/">دیگر</a> و <a href="https://aka.test/product/p3/">تکرار</a> و <a href="https://moz.com/x">منبع</a>.</p>';
$out = ContentGuard::cap_internal_links($html, $links, 4);
check(!in_array('https://aka.test/random/', hrefs($out)) && !in_array('https://aka.test/random2/', hrefs($out)), 'extra internal links beyond the budget are unwrapped');
check(in_array('https://moz.com/x', hrefs($out)), 'external link untouched');
check(count(array_filter(hrefs($out), function ($h) use ($u) { return $h === $u['product']['url']; })) === 1, 'duplicate URL unwrapped');
$out = ContentGuard::lock_product_anchors($out, $links, array('product', 'article', 'category', 'page'));
check(strpos($out, '>اینجا کلیک کنید</a>') === false && strpos($out, '>' . $u['article']['anchor'] . '</a>') !== false, 'article anchor replaced by the standard one');
check(strpos($out, '>کفش دیگر</a>') === false && strpos($out, '>' . $u['product']['anchor'] . '</a>') !== false, 'product anchor replaced by the standard one');
check(strpos($out, '>' . $u['category']['anchor'] . '</a>') !== false, 'category anchor locked to prefix + name');
$missing = ContentGuard::ensure_internal_links('<p>متن</p>', $links);
check(in_array($u['article']['url'], hrefs($missing)) && in_array($u['product']['url'], hrefs($missing)) && strpos($missing, 'مطالعه بیشتر') !== false, 'missing planned links are appended (articles worded as further reading)');
check(!in_array($u['page']['url'], hrefs($missing)), 'optional brand link is not forced');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
