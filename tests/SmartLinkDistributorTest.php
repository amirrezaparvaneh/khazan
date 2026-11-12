<?php
/**
 * Standalone unit tests for Khazan\Services\Links\SmartLinkDistributor (no WordPress required).
 *
 *   php tests/SmartLinkDistributorTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Links\InMemoryAssignmentLedger;
use Khazan\Services\Links\LinkCandidateProvider;
use Khazan\Services\Links\GlobalLinkPool;
use Khazan\Services\Links\SmartLinkDistributor;

/** In-memory catalog implementing the provider contract. */
class FakeCatalog implements LinkCandidateProvider {
    public $products = array();
    public $categories = array();
    public $db_inbound = array();

    public function addCategory($id, $name, $parent = 0) {
        $this->categories[$id] = array('id' => $id, 'name' => $name, 'url' => "https://shop.test/c/$id/", 'parent' => $parent);
    }
    public function addProduct($id, $title, array $cats, $primary = 0, $anchor = null) {
        $this->products[$id] = array('id' => $id, 'title' => $title, 'url' => "https://shop.test/p/$id/", 'anchor' => $anchor === null ? "kw $id" : $anchor, 'category_ids' => $cats, 'primary_category_id' => $primary);
    }
    public function product($product_id) { return isset($this->products[$product_id]) ? $this->products[$product_id] : null; }
    public function category($category_id) { return isset($this->categories[$category_id]) ? $this->categories[$category_id] : null; }
    /** Global pool, like the WordPress provider: every other product, shortlisted by effective inbound count. */
    public function candidates_for(array $product, array $assigned = array()) {
        $all = array_keys($this->products);
        $related = array();
        foreach ($this->products as $p) {
            if ($p['id'] !== $product['id'] && array_intersect($p['category_ids'], $product['category_ids'])) $related[] = $p['id'];
        }
        $ids = GlobalLinkPool::shortlist($all, $this->inbound_counts($all), $assigned, $product['id'], $related);
        return array_map(function ($id) { return $this->products[$id]; }, $ids);
    }
    public function inbound_counts(array $product_ids) {
        $out = array();
        foreach ($product_ids as $id) $out[$id] = isset($this->db_inbound[$id]) ? $this->db_inbound[$id] : 0;
        return $out;
    }
}

$passed = 0; $failed = 0;
function check($condition, $message) {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok: $message\n"; } else { $failed++; echo "  FAIL: $message\n"; }
}
function spread(array $counts) { return $counts ? max($counts) - min($counts) : 0; }

/** Runs a whole queue in the given order; returns [inbound counts, plans by product]. */
function run_queue(FakeCatalog $catalog, array $order, $limit, array $base = array()) {
    $dist = new SmartLinkDistributor($catalog, new InMemoryAssignmentLedger());
    $counts = array();
    foreach ($catalog->products as $id => $_) $counts[$id] = isset($base[$id]) ? $base[$id] : 0;
    $plans = array();
    foreach ($order as $pid) {
        $plan = $dist->plan($pid, 'run-1', $order, $limit);
        $plans[$pid] = $plan;
        foreach ($plan['related'] as $r) $counts[$r['id']]++;
    }
    return array($counts, $plans);
}

echo "1. 20 same-category products: inbound spread <= 1 and category link is the product's own category\n";
$catalog = new FakeCatalog();
$catalog->addCategory(10, 'Boots');
for ($i = 1; $i <= 20; $i++) $catalog->addProduct($i, "Leather boot model $i", array(10), 10);
mt_srand(2026);
$worst = 0; $categoryOk = true; $uniqueOk = true; $sameCatOk = true;
for ($seed = 0; $seed < 50; $seed++) {
    $order = range(1, 20); shuffle($order);
    list($counts, $plans) = run_queue($catalog, $order, 2);
    $worst = max($worst, spread($counts));
    foreach ($plans as $pid => $plan) {
        $categoryOk = $categoryOk && $plan['category'] && $plan['category']['id'] === 10 && $plan['internal_links'][0]['url'] === 'https://shop.test/c/10/' && $plan['internal_links'][0]['type'] === 'category';
        $ids = array_column($plan['related'], 'id');
        $uniqueOk = $uniqueOk && count($ids) === 2 && count(array_unique($ids)) === 2 && !in_array($pid, $ids, true);
        foreach ($ids as $rid) $sameCatOk = $sameCatOk && in_array(10, $catalog->products[$rid]['category_ids'], true);
    }
}
check($worst <= 1, "max-min inbound count over 50 random queue orders is $worst (<= 1)");
check($categoryOk, 'every plan links exactly its own category (Boots)');
check($uniqueOk, 'each row gets 2 distinct related products, never itself');
check($sameCatOk, 'every related product belongs to the same category');
list($counts) = run_queue($catalog, range(1, 20), 2);
check(array_sum($counts) === 40 && spread($counts) === 0, 'in-order queue: 40 links, every product receives exactly 2');

echo "2. Variance holds for other queue sizes and per-row link counts\n";
$allOk = true; $worst = 0;
foreach (array(3, 4, 5, 8, 13, 21, 40) as $n) foreach (array(1, 2, 3) as $limit) {
    if ($limit >= $n) continue;
    $c = new FakeCatalog(); $c->addCategory(1, 'A');
    for ($i = 1; $i <= $n; $i++) $c->addProduct($i, "item $i", array(1), 1);
    for ($t = 0; $t < 20; $t++) { $order = range(1, $n); shuffle($order); list($counts) = run_queue($c, $order, $limit); $worst = max($worst, spread($counts)); $allOk = $allOk && spread($counts) <= 1; }
}
check($allOk, "spread <= 1 for n in {3..40}, limit in {1,2,3} (worst $worst)");

echo "3. Category link points to the product's own (primary / deepest) category\n";
$c = new FakeCatalog();
$c->addCategory(1, 'Shoes'); $c->addCategory(2, 'Boots', 1); $c->addCategory(3, 'Sale');
$c->addProduct(1, 'Boot A', array(1, 2, 3), 2);   // primary = Boots
$c->addProduct(2, 'Boot B', array(1, 2, 3), 3);   // primary = Sale (explicit choice wins)
$c->addProduct(3, 'Boot C', array(1, 2), 0);      // no primary -> deepest = Boots
$c->addProduct(4, 'No category', array(), 0);
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
check($d->plan(1)['category']['id'] === 2, 'explicit primary (Boots) is used');
check($d->plan(2)['category']['id'] === 3, 'primary Sale is respected even though other categories exist');
check($d->plan(3)['category']['id'] === 2, 'without primary the deepest category (Boots) is used');
$pn = $d->plan(4); check($pn['category'] === null && count($pn['related']) <= 2 && !in_array(4, array_column($pn['related'], 'id'), true), 'product without categories gets no category link (it still receives related links from the global pool, never itself)');
check($d->plan(999)['internal_links'] === array(), 'unknown product yields an empty plan');

echo "4. Global pool: unrelated products are valid targets, related ones win ties\n";
$c = new FakeCatalog();
$c->addCategory(1, 'Boots'); $c->addCategory(2, 'Bags');
for ($i = 1; $i <= 5; $i++) $c->addProduct($i, "Boot $i", array(1), 1);
for ($i = 6; $i <= 10; $i++) $c->addProduct($i, "Bag $i", array(2), 2);
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$p = $d->plan(1, '', array(), 2);
check(count($p['related']) === 2 && !array_diff(array_column($p['related'], 'id'), range(2, 5)), 'with equal inbound counts the two links go to related (same-category) products');
$c->db_inbound = array(2 => 4, 3 => 4, 4 => 4, 5 => 4);
$p = $d->plan(1, '', array(), 2);
$c->db_inbound = array();

echo "5. Existing inbound counts steer links to the least-linked products\n";
$c = new FakeCatalog(); $c->addCategory(1, 'Boots');
for ($i = 1; $i <= 8; $i++) $c->addProduct($i, "Boot $i", array(1), 1);
$c->db_inbound = array(2 => 13, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3);   // product 1 has 0, product 2 has 13
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$p = $d->plan(8, 'run', array(8), 2);
check(in_array(1, array_column($p['related'], 'id'), true), 'the product with 0 inbound links is selected first');
check(!in_array(2, array_column($p['related'], 'id'), true), 'the product with 13 inbound links is not selected');
$picks = array();
$order = array(8, 7, 6, 5, 4, 3);
$dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
foreach ($order as $pid) foreach ($dist->plan($pid, 'run2', $order, 2)['related'] as $r) $picks[] = $r['id'];
check(!in_array(2, $picks, true), 'the over-linked product receives nothing during this run');
$stateless = $d->related(8, 2);
check(count($stateless) === 2 && $stateless[0]['id'] === 1, 'stateless related() also uses database counts');

echo "6. Counters update immediately so the next row rotates to other products\n";
$c = new FakeCatalog(); $c->addCategory(1, 'Boots');
for ($i = 1; $i <= 6; $i++) $c->addProduct($i, "Boot $i", array(1), 1);
$dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$a = array_column($dist->plan(1, 'x', range(1, 6), 2)['related'], 'id');
$b = array_column($dist->plan(2, 'x', range(1, 6), 2)['related'], 'id');
check($a !== $b && count(array_intersect($a, $b)) <= 1, 'consecutive rows receive different targets (' . implode(',', $a) . ' then ' . implode(',', $b) . ')');
$fresh = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
check(array_column($fresh->plan(2, 'other-run', range(1, 6), 2)['related'], 'id') !== $b, 'a different run id starts from a clean ledger');

echo "7. Candidates without a focus-keyword anchor are skipped\n";
$c = new FakeCatalog(); $c->addCategory(1, 'Boots');
$c->addProduct(1, 'Boot 1', array(1), 1); $c->addProduct(2, 'Boot 2', array(1), 1, ''); $c->addProduct(3, 'Boot 3', array(1), 1); $c->addProduct(4, 'Boot 4', array(1), 1);
$dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$ids = array_column($dist->plan(1, 'r', array(1), 2)['related'], 'id');
check(!in_array(2, $ids, true) && count($ids) === 2, 'product 2 (no anchor) is never linked');

echo "8. Outgoing anchors never overlap the current product's focus keyphrase\n";
$c = new FakeCatalog(); $c->addCategory(1, 'Shoes');
$c->addProduct(1, 'p1', array(1), 1, 'کفش مردانه ونس');
$c->addProduct(2, 'p2', array(1), 1, 'کفش مردانه ونس');
$c->addProduct(3, 'p3', array(1), 1, 'مردانه ونس');
$c->addProduct(4, 'p4', array(1), 1, 'کفش مردانه ونس فلوتر');
$c->addProduct(5, 'p5', array(1), 1, 'کفش مردانه اولد اسکول');
$c->addProduct(6, 'p6', array(1), 1, 'کفش مردانه آرتین');
$c->addProduct(7, 'p7', array(1), 1, 'بوت چرم');
$dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$plan = $dist->plan(1, '', array(), 3);
$ids = array_column($plan['related'], 'id');
check(!array_intersect($ids, array(2, 3, 4)), 'equal, contained and containing anchors are skipped (picked: ' . implode(',', $ids) . ')');
check(count($ids) === 3 && !array_diff($ids, array(5, 6, 7)), 'the three non-overlapping products are linked instead');
check(!in_array(1, $ids, true), 'never links itself');
$anchors = array_column(array_filter($plan['internal_links'], function ($l) { return $l['type'] === 'product'; }), 'anchor');
$clean = true; foreach ($anchors as $a) $clean = $clean && !Khazan\Services\Seo\KeyphraseExtractor::overlaps($a, 'کفش مردانه ونس');
check($clean, 'the final internal_links anchors do not overlap the focus keyphrase');

$c = new FakeCatalog(); $c->addCategory(1, 'Shoes');
$focus = array(1 => 'کفش مردانه ونس', 2 => 'کفش مردانه ونس فلوتر', 3 => 'ونس', 4 => 'کیف دوشی زنانه آیسا', 5 => 'کیف دوشی زنانه', 6 => 'نیم\u{200c}بوت زنانه لارا', 7 => 'نیم‌بوت زنانه', 8 => 'صندل چرم مهسا', 9 => 'صندل مهسا', 10 => 'کفش اسپرت پارسا');
foreach ($focus as $id => $a) $c->addProduct($id, "p$id", array(1), 1, $a);
$all = true; $dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
foreach (array_keys($focus) as $pid) {
    foreach ($dist->plan($pid, 'q', array_keys($focus), 2)['related'] as $r) $all = $all && !Khazan\Services\Seo\KeyphraseExtractor::overlaps($r['anchor'], $focus[$pid]);
}
check($all, 'whole queue of 10 products with deliberately overlapping keyphrases: zero overlapping outgoing anchors');
check(Khazan\Services\Seo\KeyphraseExtractor::overlaps('کفش مردانه', 'کفش مردانه ونس') && !Khazan\Services\Seo\KeyphraseExtractor::overlaps('کفش مردانه ونس', 'کفش مردانه اولد') && !Khazan\Services\Seo\KeyphraseExtractor::overlaps('', 'کفش'), 'overlaps(): containment yes, shared prefix only no, empty never');

echo "9. Category and synonym anchors never compete with the page's own keyphrase\n";
use Khazan\Services\Seo\KeyphraseExtractor as KX;
$mk = function ($catName, $focus, array $synonyms = array()) {
    $c = new FakeCatalog(); $c->addCategory(1, $catName);
    $c->addProduct(1, 'p1', array(1), 1, $focus); $c->products[1]['synonyms'] = $synonyms;
    $c->addProduct(2, 'p2', array(1), 1, 'بوت مدل برشکا');
    $c->addProduct(3, 'p3', array(1), 1, 'بوت مدل سلنا');
    $c->addProduct(4, 'p4', array(1), 1, 'نیم‌بوت چرم زنانه');
    $c->addProduct(5, 'p5', array(1), 1, 'چرم طبیعی');
    return new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
};
$catAnchor = function ($plan) { foreach ($plan['internal_links'] as $l) if ($l['type'] === 'category') return $l['anchor']; return null; };
$prefixes = Khazan\Core\Settings::CATEGORY_ANCHOR_PREFIXES;
$prefixOf = function ($anchor, $name) use ($prefixes) { foreach ($prefixes as $pf) if ($anchor === $pf . ' ' . $name) return true; return false; };
foreach (array(array('بوت چرم زنانه', 'بوت چرم زنانه آلفردو'), array('بوت زنانه', 'نیم‌بوت زنانه آلفردو'), array('کفش مردانه', 'کفش مردانه ونس فلوتر'), array('کفش اسپرت مردانه', 'کفش اسپرت مردانه اولد')) as $pair) {
    $a = $catAnchor($mk($pair[0], $pair[1])->plan(1, '', array(), 2));
    check($prefixOf($a, $pair[0]) && $a !== $pair[0] && KX::count_words($a) >= 3 && KX::count_words($a) <= 5, "category «{$pair[0]}»: prefix + the real name («{$a}»), 3-5 words");
    check(!(KX::overlaps($a, $pair[1]) && KX::count_words($a) >= KX::count_words($pair[1])), "…and it never contains the whole page keyphrase «{$pair[1]}» (no competing link)");
}
$seen = array();
for ($pid = 1; $pid <= 6; $pid++) { $c2 = new FakeCatalog(); $c2->addCategory(1, 'بوت چرم زنانه'); $c2->addProduct($pid, 'p', array(1), 1, 'بوت چرم زنانه آلفردو'); $x = new SmartLinkDistributor($c2, new InMemoryAssignmentLedger()); $seen[$catAnchor($x->plan($pid, '', array(), 0))] = true; }
check(count($seen) === count($prefixes) && !array_diff(array_keys($seen), array_map(function ($pf) { return $pf . ' بوت چرم زنانه'; }, $prefixes)), 'all three prefixes (انواع / مجموعه مدل‌های / کلکسیون) are used across products, always with the same real name');
check(SmartLinkDistributor::compose_category_anchor('بوت چرم زنانه', 1) !== SmartLinkDistributor::compose_category_anchor('بوت چرم زنانه', 2) && KX::count_words(SmartLinkDistributor::compose_category_anchor('یک دو سه چهار', 1)) <= 5 && SmartLinkDistributor::compose_category_anchor('', 1) === '', 'compose_category_anchor: seeded prefix, 5-word ceiling for long names, empty name -> empty');
$d = $mk('بوت زنانه', 'نیم‌بوت چرم زنانه آلفردو', array('بوت مدل برشکا', 'چرم طبیعی'));
$ids = array_column($d->plan(1, '', array(), 4)['related'], 'id');
check(!in_array(2, $ids, true) && !in_array(5, $ids, true), 'candidates whose anchor equals / contains a synonym of this product are skipped');
check(in_array(3, $ids, true), 'unrelated model anchors are still linked');
$d = $mk('بوت زنانه', 'نیم‌بوت چرم زنانه آلفردو', array('بوت'));
check(in_array(3, array_column($d->plan(1, '', array(), 4)['related'], 'id'), true) === false, 'a one-word synonym that appears inside another anchor rejects it (strict contain)');
$all = true;
foreach (array(array('کفش مردانه', 'کفش مردانه ونس'), array('بوت زنانه', 'بوت زنانه آلفردو'), array('کیف', 'کیف دوشی آیسا'), array('کفش اسپرت مردانه', 'کفش اسپرت مردانه اولد')) as $pair) {
    $p = $mk($pair[0], $pair[1])->plan(1, '', array(), 2);
    $an = $catAnchor($p);
    $all = $all && KX::count_words($an) <= 5 && $an !== $pair[0] && !(KX::overlaps($an, $pair[1]) && KX::count_words($an) >= KX::count_words($pair[1]));
    foreach ($p['related'] as $r) $all = $all && !KX::overlaps($r['anchor'], $pair[1]);
}
check($all, 'several category / keyphrase pairs: no outgoing anchor equals or contains the whole page keyphrase');

echo "10. Mesh architecture: no product stays without inbound links\n";
$c = new FakeCatalog();
$c->addCategory(1, 'Boots'); $c->addCategory(2, 'Bags'); $c->addCategory(3, 'Belts'); $c->addCategory(4, 'Lonely');
$id = 0;
foreach (array(1 => 12, 2 => 9, 3 => 6, 4 => 1) as $cat => $n) for ($k = 0; $k < $n; $k++) { $id++; $c->addProduct($id, "p$id", array($cat), $cat); }
$total = $id;
$mesh = true; $lonelyFed = true; $worst = 0;
for ($seed = 0; $seed < 30; $seed++) {
    $order = range(1, $total); shuffle($order);
    list($counts) = run_queue($c, $order, 2);
    $mesh = $mesh && min($counts) >= 1;
    $worst = max($worst, spread($counts));
    $lonelyFed = $lonelyFed && $counts[$total] >= 1;
}

$distinct = array();
$dist = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
foreach (range(1, $total) as $pid) foreach ($dist->plan($pid, 'rr', range(1, $total), 2)['related'] as $r) $distinct[$r['id']] = true;

$c->db_inbound = array_fill_keys(range(1, $total), 5); $c->db_inbound[17] = 0; $c->db_inbound[3] = 0;
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$ids = array_column($d->plan(25, '', array(), 2)['related'], 'id');
sort($ids);
$c->db_inbound = array();

echo "10b. Relevance and gender\n";
$c = new FakeCatalog();
$c->addCategory(1, 'Shoes'); $c->addCategory(2, 'Bags');
$c->addProduct(1, 'کفش مردانه الف', array(1), 1);
$c->addProduct(2, 'کفش مردانه ب', array(1), 1);
$c->addProduct(3, 'کفش مردانه ج', array(1), 1);
$c->addProduct(4, 'کفش زنانه د', array(1), 1);
$c->addProduct(5, 'کیف مردانه ه', array(2), 2);
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
$ids = array_column($d->plan(1, '', array(), 2)['related'], 'id');
check(!in_array(4, $ids, true), 'a men\'s product never links to a women\'s product');
check(!in_array(5, $ids, true) && count($ids) === 2, 'enough same-category products: no link goes to another category');
$ids = array_column($d->plan(5, '', array(), 2)['related'], 'id');
check(!in_array(4, $ids, true) && count($ids) === 2, 'tiny category: unrelated products only fill the gap, still never the other gender');
$c = new FakeCatalog();
$c->addCategory(10, 'Women'); $c->addCategory(11, 'Gloves', 10); $c->addCategory(12, 'Jackets', 10);
$c->addProduct(1, 'دستکش زنانه الف', array(11), 11);
$c->addProduct(2, 'دستکش زنانه ب', array(11), 11);
$c->addProduct(3, 'کاپشن زنانه ج', array(12), 12);
$c->addProduct(4, 'کاپشن زنانه د', array(12), 12);
$c->db_inbound = array(2 => 9, 3 => 0, 4 => 0);
$d = new SmartLinkDistributor($c, new InMemoryAssignmentLedger());
check(array_column($d->plan(1, '', array(), 1)['related'], 'id') === array(2), 'a same-category product wins over an unlinked product of a sibling category, however many links it already has');
$two = array_column($d->plan(1, '', array(), 2)['related'], 'id');
check($two[0] === 2 && count($two) === 2, 'the sibling category only fills the slots the own category cannot');
check(SmartLinkDistributor::gender_of('بوت چرم زنانه') === 'f' && SmartLinkDistributor::gender_of('کفش بچگانه') === 'k' && SmartLinkDistributor::gender_of('کیف') === '', 'gender_of recognises زنانه / بچگانه / none');

echo "11. GlobalLinkPool::shortlist\n";
$all = range(1, 200);
$sl = GlobalLinkPool::shortlist($all, array_fill_keys($all, 3), array(), 50);
check(count($sl) === GlobalLinkPool::SIZE && !in_array(50, $sl, true), 'size-limited and never contains the product itself');
check($sl[0] === 51 && $sl[1] === 52, 'equal counts: cyclic order starts right after the current product id (51, 52 ...), not at id 1');
$counts = array_fill_keys($all, 4); $counts[199] = 0; $counts[2] = 1;
$sl = GlobalLinkPool::shortlist($all, $counts, array(), 50);
check($sl[0] === 199 && $sl[1] === 2, 'lowest effective inbound first, wherever the product sits (199 with 0, then 2 with 1)');
$sl = GlobalLinkPool::shortlist($all, $counts, array(199 => 5), 50);
check($sl[0] === 2 && !in_array(199, $sl, true), 'links already assigned in this run count immediately (199 drops back out of the shortlist)');
$sl = GlobalLinkPool::shortlist($all, array_fill_keys($all, 2), array(), 50, array(150, 10));
check($sl[0] === 150 && $sl[1] === 10, 'related products win among equal counts (in cyclic order)');
check(GlobalLinkPool::shortlist(array(7), array(), array(), 7) === array() && GlobalLinkPool::shortlist(array(), array(), array(), 1) === array(), 'single-product shop / empty catalogue: no candidates');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
