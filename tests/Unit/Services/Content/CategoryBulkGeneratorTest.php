<?php
/**
 * Unit tests: bulk product-category generation - transactional SEO title / meta, Yoast taxonomy storage, balanced
 * keyphrase density and distribution, planned links, and the end-to-end orchestration with fake AI / repositories.
 *
 *   php tests/Unit/Services/Content/CategoryBulkGeneratorTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
class WP_Error { public $c; public $m; function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function apply_filters($tag, $value) { return $value; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function home_url($p = '') { return 'https://aka.test' . $p; }
function sanitize_key($s) { return strtolower(preg_replace('~[^a-z0-9_\-]~i', '', (string) $s)); }
function absint($n) { return abs((int) $n); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function get_bloginfo($k) { return 'Site'; }
$GLOBALS['options'] = array('wila_brand_name' => 'آکا چرم'); $GLOBALS['term_meta'] = array();
function get_option($k, $d = '') { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function update_term_meta($id, $k, $v) { $GLOBALS['term_meta'][$id][$k] = $v; return true; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Ai\CategoryContentParser;
use Khazan\Services\Content\CategoryBulkGenerator;
use Khazan\Services\Content\Guard\CategoryKeyphraseDensityGuard as Density;
use Khazan\Services\Content\Guard\CategoryLinkGuard;
use Khazan\Services\Links\CategoryLinkSource;
use Khazan\Services\Links\CategorySpiderLinker;
use Khazan\Services\Links\InMemoryAssignmentLedger;
use Khazan\Services\Seo\CategoryMetaGuard;
use Khazan\Services\Seo\CategorySeoWriter;
use Khazan\Services\Seo\CategoryTitleGuard;
use Khazan\Services\Seo\SeoTitleGuard;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function plain($html) { return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'); }
function sentence($n, $tag = 'w') { $w = array(); for ($i = 1; $i <= $n; $i++) $w[] = $tag . $i; return implode(' ', $w) . '.'; }

$focus = 'بوت چرم زنانه';

echo "1. SEO title: exact keyphrase first, purchase words, one emoji never first, <= 60 chars\n";
$t = CategoryTitleGuard::fit('بوت چرم زنانه | خرید و قیمت انواع مدل‌های طبیعی 👢 | آکا چرم', $focus, 'آکا چرم');
check($t === 'بوت چرم زنانه | خرید و قیمت انواع مدل‌های طبیعی 👢 | آکا چرم', 'the spec example is accepted as is: ' . $t);
foreach (array(
    'ساختار نامعتبر',
    '👢 بوت چرم زنانه | قیمت',
    'خرید بوت چرم زنانه با بهترین قیمت و کیفیت 👢 | آکا چرم',
    '',
    'بوت چرم زنانه 👢👠 | خرید و قیمت انواع مدل‌ها 👞 | آکا چرم | شعار طولانی فروشگاه',
) as $ai) {
    $t = CategoryTitleGuard::fit($ai, $focus, 'آکا چرم');
    check(mb_strpos($t, $focus) === 0 && mb_strpos($t, 'خرید') !== false && mb_strpos($t, 'قیمت') !== false && SeoTitleGuard::emoji_position_ok($t) && mb_strlen($t) <= 60 && CategoryTitleGuard::is_valid($t, $focus), 'repaired «' . $ai . '» -> «' . $t . '» (' . mb_strlen($t) . ' chars)');
}
$long = 'کفش مجلسی چرم زنانه پاشنه بلند';
$t = CategoryTitleGuard::fit('', $long, 'آکا چرم');
check(mb_strpos($t, $long) === 0 && mb_strlen($t) <= 60 && mb_strpos($t, 'خرید و قیمت') !== false, 'a long category name keeps the whole name and shortens the hook: ' . $t . ' (' . mb_strlen($t) . ')');
$t = CategoryTitleGuard::fit('', 'کیف اداری چرم مردانه', 'آکا چرم');
check(mb_strpos($t, '👜') !== false, 'emoji follows the product type (کیف -> 👜): ' . $t);
check(CategoryTitleGuard::fit('', 'کوله', '') === 'کوله | خرید و قیمت انواع مدل‌ها 🎒', 'without a brand the title has two parts: ' . CategoryTitleGuard::fit('', 'کوله', ''));
check(CategoryTitleGuard::fit('', 'ست هدیه چرم', 'آکا چرم') === 'ست هدیه چرم | خرید و قیمت انواع مدل‌ها 🛍️ | آکا چرم', 'a name without a known product type gets the generic shopping emoji');

echo "2. Meta description: 120-155 chars with keyphrase, purchase, price, guarantee, CTA\n";
foreach (array('', 'متن کوتاه', str_repeat('خرید بوت چرم زنانه با قیمت و ضمانت. ', 8), 'بوت چرم زنانه با بهترین قیمت و ضمانت اصالت کالا؛ برای خرید اینترنتی انواع مدل‌های شیک و راحت همین حالا سفارش دهید و ارسال سریع بگیرید.') as $ai) {
    $m = CategoryMetaGuard::fit($ai, $focus, 'آکا چرم'); $n = mb_strlen($m);
    check($n >= 120 && $n <= 155 && CategoryMetaGuard::is_valid($m, $focus), "meta ($n chars): $m");
}
foreach (array('کفش مجلسی چرم زنانه پاشنه بلند', 'کوله', 'کیف اداری چرم مردانه') as $name) {
    $m = CategoryMetaGuard::fit('', $name, 'آکا چرم'); $n = mb_strlen($m);
    check($n >= 120 && $n <= 155 && mb_strpos($m, $name) !== false, "template meta for «{$name}» is $n chars");
}
check(CategoryMetaGuard::fit('', $focus, '') !== '' && mb_strlen(CategoryMetaGuard::fit('', $focus, '')) >= 120, 'works without a brand');

echo "3. Yoast taxonomy storage (wpseo_taxonomy_meta) + term meta + Rank Math\n";
$GLOBALS['options']['wpseo_taxonomy_meta'] = array('product_cat' => array(7 => array('wpseo_noindex' => 'default')));
$seo = array('title' => 'بوت چرم زنانه | خرید و قیمت انواع مدل‌ها 👢 | آکا چرم', 'desc' => 'توضیح متا', 'focuskw' => 'کفش مجلسی چرم زنانه پاشنه بلند');
(new CategorySeoWriter())->write('product_cat', 7, $seo);
$stored = $GLOBALS['options']['wpseo_taxonomy_meta']['product_cat'][7];
check($stored['wpseo_focuskw'] === 'کفش مجلسی چرم زنانه پاشنه بلند', 'wpseo_focuskw holds the exact 5-word category name (not capped to 4 words)');
check($stored['wpseo_title'] === $seo['title'] && $stored['wpseo_desc'] === 'توضیح متا', 'wpseo_title and wpseo_desc stored under wpseo_taxonomy_meta[product_cat][term_id]');
check($stored['wpseo_noindex'] === 'default', 'other Yoast keys of the term are preserved');
check($GLOBALS['term_meta'][7][Khazan\Core\Settings::META_CATEGORY_FOCUS] === 'کفش مجلسی چرم زنانه پاشنه بلند' && !isset($GLOBALS['term_meta'][7]['rank_math_title']), 'plugin term meta written; Rank Math meta untouched while Rank Math is inactive');
define('RANK_MATH_VERSION', '1.0');
(new CategorySeoWriter())->write('product_cat', 8, $seo);
check($GLOBALS['term_meta'][8]['rank_math_title'] === $seo['title'] && $GLOBALS['term_meta'][8]['rank_math_description'] === 'توضیح متا' && $GLOBALS['term_meta'][8]['rank_math_focus_keyword'] === $seo['focuskw'], 'Rank Math term meta synced when Rank Math is active');
check(isset($GLOBALS['options']['wpseo_taxonomy_meta']['product_cat'][8]['wpseo_focuskw']), 'a second category gets its own entry');

echo "4. Keyphrase density 4-6 and balanced distribution\n";
$sparse = '<p>' . sentence(30) . '</p><h2>راهنمای انتخاب</h2><p>' . sentence(40) . '</p><h2>قیمت و ارزش</h2><p>' . sentence(40) . '</p><h2>دوخت و کیفیت</h2><p>' . sentence(40) . '</p><h2>سوالات متداول</h2><h3>پرسش اول؟</h3><p>' . sentence(20) . '</p><p>' . sentence(25) . '</p>';
$fixed = Density::enforce($sparse, $focus, 3);
$d = Density::distribution($fixed, $focus);
check($d['total'] >= 4 && $d['total'] <= 6, "a text without the keyphrase gets {$d['total']} natural occurrences (4-6)");
check($d['intro'] >= 1 && $d['middle'] >= 1 && $d['conclusion'] >= 1 && $d['h2'] >= 2, "spread: intro={$d['intro']}, H2 headings={$d['h2']}, middle={$d['middle']}, conclusion={$d['conclusion']}");
check(Density::enforce($fixed, $focus, 3) === $fixed, 'idempotent: a balanced text is not touched again');
$dense = '<p>' . $focus . ' ' . sentence(10) . ' ' . $focus . ' ' . $focus . '</p><h2>راهنمای خرید ' . $focus . '</h2><p>' . $focus . ' ' . $focus . ' ' . sentence(30) . '</p><h2>قیمت ' . $focus . '</h2><p>' . sentence(20) . ' ' . $focus . '</p><h2>دوخت ' . $focus . '</h2><p>' . $focus . ' ' . sentence(20) . '</p><h2>سوالات ' . $focus . '</h2><h2>جمع‌بندی ' . $focus . '</h2><p>' . sentence(15) . ' ' . $focus . ' ' . $focus . '.</p>';
check(Density::count($dense, $focus) > 10, 'fixture is over-optimized (' . Density::count($dense, $focus) . ' occurrences)');
$trimmed = Density::enforce($dense, $focus, 3);
$d = Density::distribution($trimmed, $focus);
check($d['total'] <= 6 && $d['total'] >= 4, "an over-dense text is cut to {$d['total']} occurrences");
check($d['intro'] >= 1 && $d['conclusion'] >= 1 && $d['h2'] >= 2 && $d['h2'] <= 3, "intro/closing/headings survive the cut: intro={$d['intro']}, H2={$d['h2']}, conclusion={$d['conclusion']}");
$linked = '<p>' . sentence(20) . '</p><h2>راهنمای خرید</h2><p>' . sentence(30) . ' <a href="https://aka.test/c/">' . $focus . '</a> ' . sentence(5) . '</p><h2>قیمت</h2><p>' . sentence(30) . '</p>';
$out = Density::enforce($linked, $focus, 1);
check(strpos($out, '<a href="https://aka.test/c/">' . $focus . '</a>') !== false, 'occurrences inside links are counted but never edited');
check(Density::enforce('', $focus) === '' && Density::enforce('<p>' . sentence(20) . '</p>', '') === '<p>' . sentence(20) . '</p>', 'empty input / empty keyphrase are left alone');

echo "5. Parser: planned links only, locked anchors, SEO fields, wrapper\n";
class FakeCategorySource implements CategoryLinkSource {
    public $cats = array();
    function __construct() { foreach (array(10 => 'بوت چرم زنانه', 11 => 'کیف اداری چرم مردانه', 12 => 'کفش طبی مردانه', 13 => 'کمربند چرم مردانه', 14 => 'کیف دوشی چرم زنانه') as $id => $name) $this->cats[$id] = array('id' => $id, 'name' => $name, 'url' => "https://aka.test/product-category/c$id/", 'parent' => 0, 'anchor' => $name); }
    function categories() { return array_values($this->cats); }
    function inbound_counts(array $exclude = array()) { return array(); }
}
$source = new FakeCategorySource();
$linker = new CategorySpiderLinker($source, new InMemoryAssignmentLedger());
$plan = $linker->plan($source->cats[10], 900, 'آکا چرم');
$links = $plan['internal_links']; $t1 = $plan['categories'][0]; $t2 = $plan['categories'][1];
$raw = "===WILA_CONTENT_START===\n<h1>عنوان نباید بماند</h1><p>{$focus} یکی از پرفروش‌ترین دسته‌های فروشگاه است. " . sentence(25) . "</p>"
    . "<h2>راهنمای خرید و انتخاب بهترین {$focus}</h2><p>" . sentence(40) . " <a href=\"{$t1['url']}\">کیف خوب</a> " . sentence(8) . "</p>"
    . "<h2>عوامل موثر بر قیمت {$focus} و ارزش خرید آن</h2><p>" . sentence(40) . "</p><ul><li>جنس چرم</li><li>دوخت</li></ul>"
    . "<h2>ویژگی‌های ظاهری و کیفیت دوخت در مدل‌ها</h2><p>" . sentence(40) . " <a href=\"{$source->cats[10]['url']}\">همین صفحه</a></p>"
    . "<h2>سوالات متداول درباره خرید {$focus}</h2><h3>پرسش اول؟</h3><p>" . sentence(20) . "</p><h3>پرسش دوم؟</h3><p>" . sentence(20) . "</p><h3>پرسش سوم؟</h3><p>" . sentence(20) . "</p>"
    . "<div class=\"wila-image-placeholder\"><p>Image Prompt: x</p></div><p>" . sentence(18) . "</p>\n===WILA_CONTENT_END===\n"
    . "===WILA_SEO_TITLE_START===\nبوت چرم زنانه 👢 | خرید\n===WILA_SEO_TITLE_END===\n===WILA_META_DESCRIPTION_START===\nکوتاه\n===WILA_META_DESCRIPTION_END===";
$params = array('content_kind' => 'product_cat', 'target_id' => 10, 'h1_title' => $focus, 'focus_keyword' => $focus, 'word_count' => 900, 'internal_links' => $links, 'brand' => 'آکا چرم', 'target' => array('url' => $source->cats[10]['url']));
$r = CategoryContentParser::parse($raw, $params);
check(!is_wp_error($r) && strpos($r['content'], '<div class="aka-desc-wrapper">') === 0, 'result is wrapped in the description wrapper');
check(stripos($r['content'], '<h1') === false && stripos($r['content'], 'Image Prompt') === false, 'H1 and image placeholders removed');
$internal = CategoryLinkGuard::internal_links($r['content']); $texts = array_column($internal, 'text', 'key');
check(count($internal) === count($links) && $r['internal_link_count'] === count($links), 'internal links = the plan (' . count($links) . ')');
check($texts[rtrim($t1['url'], '/')] === $t1['anchor'] && $texts[rtrim($t2['url'], '/')] === $t2['anchor'], 'target anchors locked to the exact category names');
check(isset($texts['https://aka.test']) && !isset($texts[rtrim($source->cats[10]['url'], '/')]), 'one homepage link, no self link');
$d = Density::distribution($r['content'], $focus);
check($d['total'] >= 4 && $d['total'] <= 6 && $d['intro'] >= 1 && $d['conclusion'] >= 1 && $d['middle'] >= 1 && $d['h2'] >= 2, "keyphrase balanced in the parsed text: total={$d['total']}, intro={$d['intro']}, H2={$d['h2']}, middle={$d['middle']}, conclusion={$d['conclusion']}");
check(CategoryTitleGuard::is_valid($r['seo_title'], $focus), 'SEO title repaired: ' . $r['seo_title']);
check(CategoryMetaGuard::is_valid($r['meta_description'], $focus), 'meta repaired: ' . mb_strlen($r['meta_description']) . ' chars');
check(is_wp_error(CategoryContentParser::parse('nothing useful', $params)), 'unusable model output is an error');

echo "6. Orchestration with fakes (links -> AI -> image -> archive -> Yoast save)\n";
class FakeCategories { public $saved = array(); public $generated = 0; public $cat;
    function __construct($cat) { $this->cat = $cat; }
    function find($id) { return (int) $id === $this->cat['id'] ? $this->cat : null; }
    function save($id, $content, array $seo = array()) { $this->saved = compact('id', 'content', 'seo'); return array('target_id' => $id, 'edit_url' => 'https://aka.test/wp-admin/term.php?tag_ID=' . $id, 'view_url' => ''); }
    function mark_generated($id) { $this->generated++; return array('at' => '2026-01-01 10:00:00', 'model' => 'm', 'mode' => 'replace'); } }
class FakeTargets { function get_context($kind, $id) { return array('kind' => $kind, 'id' => $id, 'title' => 'بوت چرم زنانه', 'url' => 'https://aka.test/product-category/c10/', 'subcategories' => array(), 'product_count' => 12); } }
class FakeDb { public $rows = array(); function insert_content_history($p, $r) { $this->rows[] = array($p, $r); } }
class FakeAi { public $params; public $raw; function __construct($raw) { $this->raw = $raw; } function generate_bulk_category_content($params) { $this->params = $params; $params['internal_links'] = $params['internal_links']; return CategoryContentParser::parse($this->raw, $params); } }
class FakeImages { public $calls = 0; public $params; function embed(array $params, array $result, $force = false) { $this->calls++; $this->params = $params; $result['image'] = array('status' => 'added'); return $result; } }

$cats = new FakeCategories($source->cats[10]); $db = new FakeDb(); $images = new FakeImages();
$ai = new class($raw) extends FakeAi {};
$gen = new CategoryBulkGenerator($cats, new FakeTargets(), $db, $ai, new CategorySpiderLinker(new FakeCategorySource(), new InMemoryAssignmentLedger()), $images);
$res = $gen->process(10, array('inject_seo' => true, 'auto_links' => true, 'run_id' => 'r1', 'queue_ids' => array(10, 11, 12, 13, 14)));
check(!is_wp_error($res) && $res['term_id'] === 10 && $cats->generated === 1, 'one category processed and marked generated');
check($ai->params['content_kind'] === 'product_cat' && $ai->params['focus_keyword'] === $focus && $ai->params['image_before_h2'] === 3, 'AI params: kind product_cat, focus = exact category name, image goes before the 3rd H2');
$types = array_count_values(array_column($ai->params['internal_links'], 'type'));
check($types['page'] === 1 && $types['category'] >= 2 && $types['category'] <= 3, 'planned links: 1 homepage + ' . $types['category'] . ' categories');
check($cats->saved['seo']['focuskw'] === $focus && CategoryTitleGuard::is_valid($cats->saved['seo']['title'], $focus) && mb_strlen($cats->saved['seo']['desc']) >= 120, 'saved SEO: focus = category name, valid title, meta >= 120 chars');
check($images->calls === 1 && count($db->rows) === 1 && $db->rows[0][0]['content_kind'] === 'product_cat', 'image step and archive log ran once');
$res = $gen->process(10, array('inject_seo' => false, 'auto_links' => false));
check($cats->saved['seo'] === array() && !$ai->params['internal_links'], 'inject_seo off -> SEO fields untouched; auto_links off -> no planned links');
check(is_wp_error($gen->process(999, array())) && is_wp_error((new CategoryBulkGenerator(new FakeCategories($source->cats[10]), new FakeTargets(), $db, new class('x') extends FakeAi {}, null, $images))->process(10, array())), 'unknown category / unusable AI output -> WP_Error, nothing saved');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
