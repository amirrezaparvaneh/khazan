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
    public function candidates_for(array $product) {
        $out = array();
        foreach ($this->products as $p) {
            if ($p['id'] !== $product['id'] && array_intersect($p['category_ids'], $product['category_ids'])) $out[] = $p;
        }
        return $out;
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
check($d->plan(4)['category'] === null && $d->plan(4)['related'] === array(), 'product without categories gets no category link and no related links');
check($d->plan(999)['internal_links'] === array(), 'unknown product yields an empty plan');

echo "4. No random links: unrelated categories are never linked\n";
$c = new FakeCatalog();
$c->addCategory(1, 'Boots'); $c->addCategory(2, 'Bags');
for ($i = 1; $i <= 5; $i++) $c->addProduct($i, "Boot $i", array(1), 1);
for ($i = 6; $i <= 10; $i++) $c->addProduct($i, "Bag $i", array(2), 2);
$c2 = new class extends FakeCatalog { public function candidates_for(array $product) { return array_values(array_filter($this->products, function ($p) use ($product) { return $p['id'] !== $product['id']; })); } };
$c2->categories = $c->categories; $c2->products = $c->products;   // provider that over-delivers candidates
$d = new SmartLinkDistributor($c2, new InMemoryAssignmentLedger());
$ok = true;
for ($i = 1; $i <= 10; $i++) { foreach ($d->plan($i, 'r', range(1, 10), 2)['related'] as $r) $ok = $ok && $c2->products[$r['id']]['primary_category_id'] === $c2->products[$i]['primary_category_id']; }
check($ok, 'even if the provider returns the whole catalog, only same-category products are chosen');

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
$d = $mk('بوت زنانه', 'نیم‌بوت چرم زنانه آلفردو');
check($catAnchor($d->plan(1, '', array(), 2)) === 'بوت زنانه', 'a general category name that does not collide is used as it is («بوت زنانه»)');
$d = $mk('کفش مردانه', 'کفش مردانه ونس');
$a = $catAnchor($d->plan(1, '', array(), 2));
check($a === 'خرید انواع کفش مردانه', 'a category name contained in the focus keyphrase becomes «خرید انواع …»: ' . $a);
check(!KX::overlaps($a, 'کفش مردانه ونس') || KX::count_words($a) > KX::count_words('کفش مردانه ونس'), 'and the variant neither equals nor contains the focus keyphrase');
$d = $mk('نیم‌بوت زنانه', 'نیم‌بوت زنانه');
$a = $catAnchor($d->plan(1, '', array(), 2));
check(KX::count_words($a) <= 4 && $a !== '', 'category name identical to the focus never yields an empty or >4-word anchor (' . $a . ')');
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
    $all = $all && KX::count_words($an) <= 4 && !(KX::overlaps($an, $pair[1]) && KX::count_words($an) >= KX::count_words($pair[1]) && $an !== $pair[0]);
    foreach ($p['related'] as $r) $all = $all && !KX::overlaps($r['anchor'], $pair[1]);
}
check($all, 'several category / keyphrase pairs: no outgoing anchor equals or contains the page keyphrase');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
