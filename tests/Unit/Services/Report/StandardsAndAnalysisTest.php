<?php
/**
 * Unit tests: StandardsStore (validated owner settings) and LinkAnalysis (existing links and content).
 *
 *   php tests/Unit/Services/Report/StandardsAndAnalysisTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $d; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_title($s) { return strtolower(trim(preg_replace('/[^a-z0-9\-]+/i', '-', (string) $s), '-')); }
function esc_url_raw($u, $protocols = null) { $u = trim((string) $u); return preg_match('~^(https?)://[^\s]+$~i', $u) && (!$protocols || in_array(strtolower(parse_url($u, PHP_URL_SCHEME)), $protocols, true)) ? $u : ''; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Content\StandardsStore as S;
use Khazan\Services\Report\LinkAnalysis as A;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

// ---- sources: https only, unique, with titles
$src = S::parse_sources("https://example.org/a | منبع الف\nhttp://insecure.example/x\nnot a url\nhttps://example.org/a | تکرار\nhttps://example.com/b");
check('only https sources are kept, deduplicated', count($src) === 2 && $src[0]['url'] === 'https://example.org/a' && $src[1]['title'] === '');
check('sources round trip', count(S::parse_sources(S::sources_text($src))) === 2);

// ---- facts
$f = S::sanitize_facts(array('free_shipping' => 0, 'return_days' => '900', 'plain_leather_slugs' => 'Casual-Bags, office-bags', 'unknown' => 'x', 'verified_sources' => "https://example.org/a | t"));
check('facts: bool, clamped days, slugs, sources, unknown key dropped', $f['free_shipping'] === false && $f['return_days'] === 60 && $f['plain_leather_slugs'] === array('casual-bags', 'office-bags') && !isset($f['unknown']) && $f['verified_sources'][0]['url'] === 'https://example.org/a');

// ---- seo standards
$s = S::sanitize_seo(array('density_min' => '1.5', 'density_max' => '1', 'links_product' => '4-7', 'title_min' => '52', 'h2_min' => '4', 'bogus' => 1, 'links_category' => array(9, 2)));
check('seo: inverted density dropped, ranges parsed, invalid range dropped, unknown dropped', !isset($s['density_min']) && $s['links_product'] === array(4, 7) && $s['title_min'] === 52 && $s['h2_min'] === 4 && !isset($s['bogus']) && !isset($s['links_category']));

// ---- keyword map from ownership.csv
$csv = "keyword_or_group,owner_url,owner_type\nکیف چرم زنانه,https://shop.test/product-category/women-bags/,category\nکیف دوشی (گروه),https://shop.test/product-category/women-bags/,subcategory\n";
$map = S::keyword_map_from_csv($csv);
check('keyword map: main and secondary by slug', isset($map['women-bags']) && $map['women-bags']['main'] === 'کیف چرم زنانه' && in_array('کیف دوشی', $map['women-bags']['secondary'], true));
check('keyword map: header only gives nothing', S::keyword_map_from_csv("a,b\n") === array());

// ---- link analysis
$out = array(
    array('source_id' => 1, 'source_type' => 'product', 'source_title' => 'p1', 'source_url' => 'u1', 'links' => 1),
    array('source_id' => 2, 'source_type' => 'product', 'source_title' => 'p2', 'source_url' => 'u2', 'links' => 4),
    array('source_id' => 3, 'source_type' => 'product_cat', 'source_title' => 'c1', 'source_url' => 'u3', 'links' => 12),
    array('source_id' => 4, 'source_type' => 'page', 'source_title' => 'about', 'source_url' => 'u4', 'links' => 99),
);
$anchors = array(
    array('anchor_text' => 'اینجا', 'target_id' => 1, 'target_type' => 'product', 'target_url' => 'u1', 'uses' => 6),
    array('anchor_text' => 'کیف چرم', 'target_id' => 1, 'target_type' => 'product', 'target_url' => 'u1', 'uses' => 2),
    array('anchor_text' => 'کیف چرم', 'target_id' => 2, 'target_type' => 'product', 'target_url' => 'u2', 'uses' => 1),
    array('anchor_text' => 'کیف چرم', 'target_id' => 3, 'target_type' => 'product_cat', 'target_url' => 'u3', 'uses' => 1),
    array('anchor_text' => 'بوت برشکا', 'target_id' => 9, 'target_type' => 'product', 'target_url' => 'u9', 'uses' => 8),
    array('anchor_text' => 'مدل برشکا', 'target_id' => 9, 'target_type' => 'product', 'target_url' => 'u9', 'uses' => 1),
);
$inbound = array(array('target_id' => 9, 'target_type' => 'product', 'target_url' => 'u9', 'inbound' => 9), array('target_id' => 1, 'target_type' => 'product', 'target_url' => 'u1', 'inbound' => 3));
$pages = array(array('id' => 9, 'type' => 'product', 'title' => 'x', 'url' => 'u9'), array('id' => 5, 'type' => 'product', 'title' => 'orphan', 'url' => 'u5'), array('id' => 7, 'type' => 'page', 'title' => 'about', 'url' => 'u7'));
$cats = array(array('id' => 3, 'name' => 'کیف', 'url' => 'cu', 'products' => 12, 'words' => 50), array('id' => 4, 'name' => 'خالی', 'url' => 'cu2', 'products' => 0, 'words' => 0));
$r = A::build($out, $anchors, $inbound, $cats, $pages);
check('links per page: product with 1 link is too few, 4 is fine, category with 12 is too many, pages ignored', $r['links_per_page']['counts'] === array('few' => 1, 'many' => 1, 'ok' => 1));
check('generic anchors counted', $r['anchors']['generic_total'] === 6);
check('one anchor for 3 targets is flagged', count($r['anchors']['shared']) === 1 && $r['anchors']['shared'][0]['anchor'] === 'کیف چرم');
check('flooded target found (8 of 9 uses)', count($r['anchors']['flooded']) === 1 && $r['anchors']['flooded'][0]['share'] === 89);
check('page without inbound links found, non content type ignored', $r['inbound']['orphan_total'] === 1 && $r['inbound']['orphans'][0]['id'] === 5);
check('thin category with products reported, empty category not', count($r['content_gaps']) === 1 && $r['content_gaps'][0]['id'] === 3 && $r['content_gaps'][0]['missing'] > 0);
check('summary mirrors the report', $r['summary']['pages_without_inbound'] === 1 && $r['summary']['thin_categories'] === 1);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
