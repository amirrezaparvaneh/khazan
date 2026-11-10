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
use Khazan\Services\Seo\CategorySeoWriter;
use Khazan\Services\Seo\SeoMetaRules;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function sentence($n, $tag = 'w') { $w = array(); for ($i = 1; $i <= $n; $i++) $w[] = $tag . $i; return implode(' ', $w) . '.'; }

$focus = 'بوت چرم زنانه';

echo "1. SEO title: starts with the keyword, ends with the brand, 50-60 chars, no emoji, no free shipping\n";
$ok = 'بوت چرم زنانه؛ خرید انواع مدل، قیمت و اقساط | آکا چرم';
check(SeoMetaRules::fit_title($ok, $focus, 'آکا چرم') === $ok && mb_strlen($ok) >= 50 && mb_strlen($ok) <= 60, 'a valid model title is kept (unique per page): ' . mb_strlen($ok) . ' chars');
foreach (array('', 'ساختار نامعتبر', '👢 بوت چرم زنانه | قیمت', 'بوت چرم زنانه ارسال رایگان و خرید انواع مدل‌ها در فروشگاه | آکا چرم', 'خرید بوت چرم زنانه | آکا چرم') as $bad) {
    $t = SeoMetaRules::fit_title($bad, $focus, 'آکا چرم'); $n = mb_strlen($t);
    check(mb_strpos($t, $focus) === 0 && $n >= 50 && $n <= 60 && mb_substr($t, -10) === ' | آکا چرم' && mb_strpos($t, 'ارسال رایگان') === false && !preg_match(SeoMetaRules::EMOJI, $t), "repaired «{$bad}» -> «{$t}» ({$n})");
}
$t = SeoMetaRules::fit_title('', 'کفش مجلسی چرم زنانه پاشنه بلند', 'آکا چرم');
check(mb_strpos($t, 'کفش مجلسی چرم زنانه پاشنه بلند') === 0 && mb_strlen($t) <= 60, 'a long keyword keeps the whole keyword: ' . $t . ' (' . mb_strlen($t) . ')');

echo "2. Meta: 140-155 chars with the keyword, trimmed never padded\n";
$meta = 'بوت چرم زنانه آکا چرم برای روزمره و مجلس؛ ارسال رایگان، بازگشت کالا تا ۷ روز و خرید اقساطی ۴ قسطه با اسنپ‌پی و ترب‌پی. مدل‌ها را ببینید و مقایسه کنید.';
check(SeoMetaRules::meta_ok($meta, $focus), 'a valid meta passes (' . mb_strlen($meta) . ' chars)');
$long = SeoMetaRules::fit_meta($meta . ' ' . $meta, $focus);
check(mb_strlen($long) <= 155 && mb_strlen($long) >= 140, 'a too long meta is cut at a word boundary (' . mb_strlen($long) . ')');
check(SeoMetaRules::fit_meta('کوتاه', $focus) === 'کوتاه', 'a too short meta is not padded with canned text');
check(!SeoMetaRules::meta_ok('کوتاه', $focus) && !SeoMetaRules::meta_ok($meta . ' 👢', $focus), 'short meta and emoji meta fail the check');

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

echo "4. Parser: planned links only, no injected sentences, audit attached\n";
$plan = array(
    array('type' => 'category', 'role' => 'parent', 'url' => 'https://aka.test/category/shoes/', 'anchor' => 'کفش چرم', 'anchor_options' => array('کفش چرم', 'انواع کفش چرم')),
    array('type' => 'product', 'role' => 'product', 'url' => 'https://aka.test/p/arsa/', 'anchor' => 'بوت زنانه آرسا', 'anchor_options' => array('بوت زنانه آرسا')),
);
$self = 'https://aka.test/category/women-boots/';
$raw = "===WILA_CONTENT_START===\n<h1>عنوان نباید بماند</h1><p>{$focus} برای روزهای سرد گزینه خوبی است. " . sentence(25) . "</p>"
    . "<h2>راهنمای خرید {$focus}</h2><p>" . sentence(40) . " <a href=\"https://aka.test/category/shoes/\">یک متن دلخواه</a> " . sentence(8) . " <a href=\"https://aka.test/unknown/\">ناشناس</a></p>"
    . "<h2>تفاوت مدل‌ها</h2><table><tr><td>الف</td></tr></table><p>" . sentence(30) . " <a href=\"{$self}\">همین صفحه</a></p>"
    . "<div class=\"wila-image-placeholder\"><p>Image Prompt: x</p></div>\n===WILA_CONTENT_END===\n"
    . "===WILA_SEO_TITLE_START===\nبوت چرم زنانه 👢 | خرید\n===WILA_SEO_TITLE_END===\n===WILA_META_DESCRIPTION_START===\nکوتاه\n===WILA_META_DESCRIPTION_END===\n===WILA_NOTES_START===\nمشخصات چرم حذف شد\n===WILA_NOTES_END===";
$params = array('content_kind' => 'product_cat', 'focus_keyword' => $focus, 'word_count' => 1400, 'internal_links' => $plan, 'brand' => 'آکا چرم', 'verified_sources' => array(), 'target' => array('url' => $self));
$r = CategoryContentParser::parse($raw, $params);
check(!is_wp_error($r) && strpos($r['content'], '<div class="aka-desc-wrapper">') === 0, 'result is wrapped');
check(stripos($r['content'], '<h1') === false && stripos($r['content'], 'Image Prompt') === false, 'H1 and image placeholders removed');
check(strpos($r['content'], 'unknown') === false && strpos($r['content'], 'href="' . $self . '"') === false, 'unknown internal link and self link unwrapped');
check(strpos($r['content'], '>کفش چرم</a>') !== false, 'an off-plan anchor is replaced by the planned primary anchor');
check(count($r['missing_links']) === 1 && $r['missing_links'][0]['url'] === 'https://aka.test/p/arsa/', 'the forgotten product link is reported, not injected');
check(isset($r['audit']['failures']['length_short']) && isset($r['audit']['failures']['internal_links']) && !$r['audit']['ok'], 'audit reports the short text and the link count');
check($r['audit']['tables'] === 1 && $r['audit']['h1'] === 0, 'audit sees the table and no H1');
check($r['notes'] === 'مشخصات چرم حذف شد', 'notes of removed claims are returned');
check(mb_strlen($r['seo_title']) >= 50 && mb_strlen($r['seo_title']) <= 60 && SeoMetaRules::title_ok($r['seo_title'], $focus, 'آکا چرم'), 'title rebuilt within the rules: ' . $r['seo_title']);
check(is_wp_error(CategoryContentParser::parse('nothing useful', $params)), 'unusable model output is an error');

echo "5. Orchestration with fakes (plan -> AI -> repair pass -> image -> archive -> Yoast save)\n";
class FakeCategories { public $saved = array(); public $generated = 0; public $cat;
    function __construct($cat) { $this->cat = $cat; }
    function find($id) { return (int) $id === $this->cat['id'] ? $this->cat : null; }
    function all_for_linking() { return array($this->cat, array('id' => 1, 'name' => 'کفش چرم', 'url' => 'https://aka.test/category/shoes/', 'parent' => 0), array('id' => 11, 'name' => 'نیم‌بوت زنانه', 'url' => 'https://aka.test/category/half/', 'parent' => 10)); }
    function save($id, $content, array $seo = array()) { $this->saved = compact('id', 'content', 'seo'); return array('target_id' => $id, 'edit_url' => 'https://aka.test/wp-admin/term.php?tag_ID=' . $id, 'view_url' => ''); }
    function mark_generated($id) { $this->generated++; return array('at' => '2026-01-01 10:00:00', 'model' => 'm', 'mode' => 'replace'); } }
class FakeTargets { function get_context($kind, $id) { return array('kind' => $kind, 'id' => $id, 'title' => 'بوت چرم زنانه', 'url' => 'https://aka.test/category/women-boots/', 'subcategories' => array(), 'product_count' => 12); }
    function category_products($id) { return array(array('id' => 5, 'title' => 'بوت زنانه آرسا', 'url' => 'https://aka.test/p/arsa/', 'model' => 'بوت زنانه آرسا')); } }
class FakeDb { public $rows = array(); function insert_content_history($p, $r) { $this->rows[] = array($p, $r); } }
class FakeAi { public $params; public $repairs = 0; public $raw; public $fixed_raw;
    function __construct($raw, $fixed_raw = null) { $this->raw = $raw; $this->fixed_raw = $fixed_raw; }
    function generate_bulk_category_content($params) { $this->params = $params; return CategoryContentParser::parse($this->raw, $params); }
    function repair_category_content($params, $content, array $failures, array $missing) { $this->repairs++; $this->repair_failures = $failures; return CategoryContentParser::parse($this->fixed_raw !== null ? $this->fixed_raw : $this->raw, $params); } }
class FakeArticles { function article_candidates(array $a) { return array(array('id' => 9, 'title' => 'راهنمای انتخاب بوت زنانه', 'url' => 'https://aka.test/blog/boot-guide/')); } }
class FakeImages { public $calls = 0; function embed(array $params, array $result, $force = false) { $this->calls++; $result['image'] = array('status' => 'added'); return $result; } }

$cat = array('id' => 10, 'name' => $focus, 'url' => $self, 'parent' => 1);
$good_body = '<p>' . $focus . ' ' . str_repeat('این جمله واقعی و متفاوت درباره انتخاب و خرید بوت است. ', 1) . '</p>';
$cats = new FakeCategories($cat); $db = new FakeDb(); $images = new FakeImages();
$ai = new FakeAi($raw, $raw);
$gen = new CategoryBulkGenerator($cats, new FakeTargets(), $db, $ai, new FakeArticles(), $images);
$res = $gen->process(10, array('inject_seo' => true, 'auto_links' => true));
check(!is_wp_error($res) && $res['term_id'] === 10 && $cats->generated === 1, 'one category processed and marked generated');
check($ai->params['content_kind'] === 'product_cat' && $ai->params['focus_keyword'] === $focus && $ai->params['word_count'] >= 1300 && $ai->params['word_count'] <= 1500, 'AI params: kind, keyword and a word target inside 1300-1500');
check(array_column($ai->params['internal_links'], 'role') === array('parent', 'child', 'product', 'article'), 'links planned from real targets only (parent, child, product, article): ' . count($ai->params['internal_links']));
check($ai->repairs === 1 && !empty($ai->repair_failures), 'a text that fails the audit gets exactly one repair pass');
check(isset($res['audit']['failures']) && $res['repaired'] === false, 'a repair that is not better than the first text is not used');
check($cats->saved['seo']['focuskw'] === $focus && SeoMetaRules::title_ok($cats->saved['seo']['title'], $focus, 'آکا چرم'), 'saved SEO: focus keyword and a valid title');
check($images->calls === 1 && count($db->rows) === 1, 'image step and archive log ran once');
$res = $gen->process(10, array('inject_seo' => false, 'auto_links' => false));
check($cats->saved['seo'] === array() && !$ai->params['internal_links'], 'inject_seo off -> SEO untouched; auto_links off -> no planned links');
check(is_wp_error($gen->process(999, array())), 'unknown category -> WP_Error');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
