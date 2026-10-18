<?php
/**
 * Unit tests: spider (mesh) linking between product categories - equal inbound distribution, exact target anchors,
 * one homepage link, no self links, anchor locking by LinkGuard.
 *
 *   php tests/Unit/Services/Links/CategorySpiderLinkerTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($tag, $value) { return $value; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function home_url($p = '') { return 'https://aka.test' . $p; }
function get_option($k, $d = '') { return $k === 'wila_brand_name' ? 'آکا چرم' : $d; }
function get_bloginfo($k) { return 'Site'; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Content\Guard\CategoryLinkGuard;
use Khazan\Services\Links\CategoryLinkSource;
use Khazan\Services\Links\CategorySpiderLinker;
use Khazan\Services\Links\GlobalCategoryPool;
use Khazan\Services\Links\InMemoryAssignmentLedger;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

/** In-memory site: categories plus the links that already sit in their descriptions (source id => target ids). */
class FakeCategorySource implements CategoryLinkSource {
    public $cats = array(); public $existing = array();
    function __construct(array $names, array $existing = array()) {
        $id = 10;
        foreach ($names as $name) { $parent = is_array($name) ? $name[1] : 0; $label = is_array($name) ? $name[0] : $name; $this->cats[$id] = array('id' => $id, 'name' => $label, 'url' => "https://aka.test/product-category/c$id/", 'parent' => $parent, 'anchor' => $label); $id++; }
        $this->existing = $existing;
    }
    function categories() { return array_values($this->cats); }
    function inbound_counts(array $exclude = array()) {
        $counts = array();
        foreach ($this->existing as $source => $targets) { if (in_array($source, $exclude, true)) continue; foreach ($targets as $t) $counts[$t] = (isset($counts[$t]) ? $counts[$t] : 0) + 1; }
        return $counts;
    }
    function ids() { return array_keys($this->cats); }
    function by_url($url) { foreach ($this->cats as $c) if ($c['url'] === $url) return $c['id']; return 0; }
}

function names($n) { $base = array('بوت چرم زنانه', 'کیف اداری چرم مردانه', 'کفش طبی مردانه', 'کمربند چرم مردانه', 'کیف دوشی چرم زنانه', 'کفش مجلسی زنانه', 'نیم‌بوت چرم زنانه', 'کیف پول چرم مردانه', 'صندل چرم زنانه', 'کتانی چرم مردانه', 'کوله پشتی چرم', 'جاکارتی چرم', 'ست هدیه چرم'); $out = array(); for ($i = 0; $i < $n; $i++) $out[] = $i < count($base) ? $base[$i] : "دسته نمونه شماره $i"; return $out; }

/** Runs the whole queue in the given order against one shared ledger; returns inbound counts and the plans. */
function run_queue(FakeCategorySource $src, array $order, $words = 900) {
    $linker = new CategorySpiderLinker($src, new InMemoryAssignmentLedger());
    $inbound = array_fill_keys($src->ids(), 0); $plans = array();
    foreach ($order as $id) {
        $plan = $linker->plan($src->cats[$id], $words, 'آکا چرم', 'run-1', $order);
        $plans[$id] = $plan;
        foreach ($plan['categories'] as $link) $inbound[$link['category_id']]++;
    }
    return array($inbound, $plans);
}

echo "1. Link count follows the length (about one per 250 words, 2-3 links)\n";
check(CategorySpiderLinker::link_count(900) === 3 && CategorySpiderLinker::link_count(1000) === 3, '900 / 1000 words -> 3 links');
check(CategorySpiderLinker::link_count(600) === 2 && CategorySpiderLinker::link_count(300) === 2 && CategorySpiderLinker::link_count(100) === 2, '600 words and shorter -> 2 links');
check(CategorySpiderLinker::link_count(5000) === 3, 'never more than 3');

echo "2. Equal spider distribution: nobody stays at zero, spread <= 1\n";
foreach (array(5, 8, 13, 24) as $n) {
    $worst = 0; $zero = false;
    for ($t = 0; $t < 25; $t++) {
        $src = new FakeCategorySource(names($n)); $order = $src->ids(); if ($t) shuffle($order);
        list($inbound) = run_queue($src, $order);
        $worst = max($worst, max($inbound) - min($inbound)); if (min($inbound) === 0) $zero = true;
    }
    check(!$zero && $worst <= 1, "$n categories x 25 random queue orders: no orphan category, max spread $worst");
}
$src = new FakeCategorySource(names(10)); list($inbound, $plans) = run_queue($src, $src->ids());
check(count(array_unique($inbound)) === 1 && reset($inbound) === 3, '10 categories x 3 links: every category receives exactly 3 inbound links');
$src = new FakeCategorySource(names(10), array(10 => array(11, 12, 13), 11 => array(12, 13, 14), 12 => array(13, 14, 15)));
list($inbound) = run_queue($src, $src->ids());
check(max($inbound) - min($inbound) <= 1 && min($inbound) >= 1, 'a full re-generation ignores the old links of the regenerated rows (they are replaced) and still balances');
$src = new FakeCategorySource(names(12), array(20 => array(13, 13), 21 => array(13, 14))); // rows 20 and 21 are outside the queue and keep their links
$queue = range(10, 19);
$first = (new CategorySpiderLinker($src, new InMemoryAssignmentLedger()))->plan($src->cats[10], 900, 'آکا چرم', 'run-2', $queue);
check(!array_intersect(array(13, 14), array_column($first['categories'], 'category_id')), 'categories that already have inbound links in other descriptions are skipped while less-linked ones exist');

echo "3. Anchors: exact target name / focus, locked, with the «خرید» distinction for subsets\n";
$src = new FakeCategorySource(names(6)); list($inbound, $plans) = run_queue($src, $src->ids());
$ok = true; $locked = true; $exact = true;
foreach ($plans as $id => $plan) foreach ($plan['categories'] as $link) {
    $target = $src->cats[$link['category_id']];
    if ($link['anchor'] !== $target['name'] && $link['anchor'] !== 'خرید ' . $target['name']) $exact = false;
    if (empty($link['locked']) || $link['type'] !== 'category') $locked = false;
    if ($link['category_id'] === $id) $ok = false;
}
check($exact, 'every anchor is the exact name of the target category (or «خرید » + name)');
check($locked, 'category links are flagged locked');
check($ok, 'a category never links to itself');
$sub = new FakeCategorySource(array('بوت چرم زنانه مجلسی', 'بوت چرم', 'کیف چرم')); // 11 «بوت چرم» is fully inside the focus of 10
$p = (new CategorySpiderLinker($sub, new InMemoryAssignmentLedger()))->plan($sub->cats[10], 900, 'آکا چرم');
$anchors = array_column($p['categories'], 'anchor', 'category_id');
check($anchors[11] === 'خرید بوت چرم', 'a target whose words are 100% inside the current keyphrase gets a distinguishing anchor: ' . $anchors[11]);
check($anchors[12] === 'کیف چرم', 'an unrelated target keeps the plain exact name');

echo "4. Exactly one homepage link\n";
foreach ($plans as $plan) { $homes = array_filter($plan['internal_links'], function ($l) { return $l['type'] === 'page'; }); if (count($homes) !== 1) $ok = false; }
$home = reset($plans)['home'];
check(count(array_filter(reset($plans)['internal_links'], function ($l) { return $l['type'] === 'page'; })) === 1 && $home['url'] === 'https://aka.test/', 'one link to home_url(/)');
check(mb_strpos($home['anchor'], 'آکا چرم') !== false && $home['locked'], 'home anchor carries the brand: ' . $home['anchor']);

echo "5. Tie-breaks: relatives first, then cyclic id rotation\n";
$fam = new FakeCategorySource(array('کیف مردانه', 'کیف زنانه', 'کفش مردانه', 'کمربند', array('کیف بچه', 12)));
$cats = $fam->categories();
$ids = function ($ranked) { return array_column($ranked, 'id'); };
check($ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[0])) === array(12, 11, 13, 14), 'equal counts: the category sharing the gender word (کفش مردانه) comes before strangers: ' . implode(',', $ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[0]))));
check($ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[2])) === array(14, 10, 13, 11), 'parent/child first, then shared gender, then strangers by cyclic id: ' . implode(',', $ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[2]))));
$plain = new FakeCategorySource(array('الف', 'ب', 'ج', 'د', 'ه'));
$cats = $plain->categories();
check($ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[2])) === array(13, 14, 10, 11), 'unrelated categories rotate: id+1, id+2, ... then wrap around');
check($ids(GlobalCategoryPool::rank($cats, array(11 => 2, 13 => 1), array(14 => 1), $cats[2]))[0] === 10, 'a lower inbound count (database + run ledger) always beats the rotation');
check($ids(GlobalCategoryPool::rank($cats, array(), array(), $cats[2], array(10)))[0] === 10, 'at equal counts a row that is still pending in the queue is linked first');

echo "6. LinkGuard locks the target anchors and the link set is exactly the plan\n";
$src = new FakeCategorySource(names(6)); $linker = new CategorySpiderLinker($src, new InMemoryAssignmentLedger());
$cat = $src->cats[10]; $plan = $linker->plan($cat, 900, 'آکا چرم'); $links = $plan['internal_links'];
$t1 = $plan['categories'][0]; $t2 = $plan['categories'][1];
$html = '<p>مقدمه درباره بوت چرم زنانه و انتخاب آن.</p>'
    . '<p>بخش دوم با <a href="' . $t1['url'] . '">کیف شیک</a> و توضیحات بیشتر.</p>'
    . '<p>بخش سوم با <a href="' . $t1['url'] . '">لینک تکراری</a> و <a href="' . $cat['url'] . '">لینک به خودش</a> و <a href="https://aka.test/product/x/">محصول ناخواسته</a>.</p>'
    . '<p>پایان با <a href="https://fa.wikipedia.org/wiki/x">منبع خارجی</a>.</p>';
$out = CategoryLinkGuard::enforce($html, $links, $cat['url']);
$internal = CategoryLinkGuard::internal_links($out); $keys = array_column($internal, 'key');
check(count($internal) === count($links) && count(array_unique($keys)) === count($keys), 'exactly ' . count($links) . ' internal links, each URL once: ' . count($internal));
check(!in_array(rtrim($cat['url'], '/'), $keys, true), 'the link to the category itself is removed');
check(strpos($out, 'محصول ناخواسته') !== false && strpos($out, 'href="https://aka.test/product/x/"') === false, 'unplanned internal link is unwrapped (its text stays)');
check(strpos($out, 'https://fa.wikipedia.org/wiki/x') !== false, 'external links are left alone');
$texts = array_column($internal, 'text', 'key');
check($texts[rtrim($t1['url'], '/')] === $t1['anchor'], 'AI-changed anchor «کیف شیک» replaced by the exact target anchor «' . $t1['anchor'] . '»');
check($texts[rtrim($t2['url'], '/')] === $t2['anchor'], 'a link the AI dropped is added with the exact anchor «' . $t2['anchor'] . '»');
check(isset($texts['https://aka.test']) && $texts['https://aka.test'] === $home['anchor'] && count(array_filter($keys, function ($k) { return $k === 'https://aka.test'; })) === 1, 'the missing homepage link is added exactly once with the brand anchor');
$again = CategoryLinkGuard::enforce($out, $links, $cat['url']);
check($again === $out, 'the guard is idempotent');
$shortened = str_replace('>' . $t2['anchor'] . '<', '>' . mb_substr($t2['anchor'], 0, 4) . '<', $out);
check(CategoryLinkGuard::internal_links(CategoryLinkGuard::enforce($shortened, $links, $cat['url']))[1]['text'] !== mb_substr($t2['anchor'], 0, 4), 'a shortened anchor is restored');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
