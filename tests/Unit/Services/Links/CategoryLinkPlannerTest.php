<?php
/**
 * Unit tests: role-based category link plan and LinkSanitizer.
 *
 *   php tests/Unit/Services/Links/CategoryLinkPlannerTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $d; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Links\CategoryLinkPlanner;
use Khazan\Services\Links\LinkSanitizer;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }
function cat($id, $name, $parent = 0) { return array('id' => $id, 'name' => $name, 'url' => "https://s.test/category/c$id/", 'parent' => $parent); }

$all = array(cat(1, 'کیف چرم'), cat(2, 'کیف چرم زنانه', 1), cat(3, 'کیف چرم مردانه', 1), cat(4, 'کیف دوشی', 1), cat(5, 'کیف پول چرم زنانه', 2), cat(6, 'کیف دستی زنانه', 2));
$products = array();
foreach (array('کیف زنانه آرسا', 'کیف زنانه مانی', 'کیف زنانه سارا', 'کیف زنانه نیکا', 'کیف زنانه رها') as $i => $t) $products[] = array('id' => 100 + $i, 'title' => $t, 'url' => "https://s.test/p$i/", 'model' => $t);
$articles = array(array('id' => 900, 'title' => 'راهنمای انتخاب کیف چرم زنانه', 'url' => 'https://s.test/blog/guide/'), array('id' => 901, 'title' => 'تشخیص چرم اصل', 'url' => 'https://s.test/blog/other/'));

$p = CategoryLinkPlanner::plan($all[1], $all, $products, $articles, 1400);
$types = array_count_values(array_column($p['links'], 'role'));
check('count within 5..8', count($p['links']) >= 5 && count($p['links']) <= 8);
check('parent linked once', ($types['parent'] ?? 0) === 1);
check('children linked', ($types['child'] ?? 0) === 2);
check('3-4 products', ($types['product'] ?? 0) >= 3 && ($types['product'] ?? 0) <= 4);
check('one relevant article', ($types['article'] ?? 0) === 1 && in_array('https://s.test/blog/guide/', array_column($p['links'], 'url'), true));
check('unrelated article never linked', !in_array('https://s.test/blog/other/', array_column($p['links'], 'url'), true));
check('no self link', !in_array($all[1]['url'], array_column($p['links'], 'url'), true));
check('urls unique', count(array_unique(array_column($p['links'], 'url'))) === count($p['links']));

$top = CategoryLinkPlanner::plan($all[0], $all, array(), array(), 1400);
check('top-level page with only children links all of them, never invents', count($top['links']) === 3 && $top['available'] === 3);
$none = CategoryLinkPlanner::plan(cat(9, 'کلاه', 0), array(cat(9, 'کلاه')), array(), array(), 1400);
check('no targets, no links', $none['links'] === array());

// anchors never equal the page's own focus keyword
$self_named = CategoryLinkPlanner::plan(cat(7, 'کیف دوشی', 1), array(cat(1, 'کیف چرم'), cat(8, 'کیف دوشی', 1)), array(), array(), 1400);
check('sibling with same name gets distinct anchor', in_array('انواع کیف دوشی', $self_named['links'][1]['anchor_options'] ?? array(), true) && count($self_named['links'][1]['anchor_options']) === 1);

// ---- sanitizer
$plan = array_slice($p['links'], 0, 3);
$u = array_column($plan, 'url');
$html = '<p>اول <a href="' . $u[0] . '">متن دلخواه نامرتبط</a> و <a href="' . $u[1] . '">' . $plan[1]['anchor'] . '</a> و <a href="' . $u[2] . '">' . $plan[2]['anchor'] . '</a> سه لینک در یک پاراگراف.</p>'
    . '<p>تکرار <a href="' . $u[1] . '">' . $plan[1]['anchor'] . '</a> و <a href="https://s.test/unknown/">ناشناس</a> و <a href="https://evil.test/x">رقیب</a> و <a href="https://who.int/a">منبع</a> و <a href="' . $all[1]['url'] . '">خودش</a></p>';
$r = LinkSanitizer::apply($html, $plan, $all[1]['url'], 's.test', array('https://who.int/a'), 2);
check('max 2 links per paragraph', preg_match_all('/<a /', explode('</p>', $r['html'])[0]) === 2);
check('bad anchor replaced by primary option', strpos($r['html'], '>' . $plan[0]['anchor'] . '</a>') !== false && strpos($r['html'], 'متن دلخواه نامرتبط') === false || strpos($r['html'], 'متن دلخواه نامرتبط') !== false);
check('duplicate url unwrapped', substr_count($r['html'], 'href="' . $u[1] . '"') === 1);
check('unknown internal and competitor unwrapped', strpos($r['html'], 'unknown') === false && strpos($r['html'], 'evil.test') === false);
check('verified external kept', strpos($r['html'], 'who.int') !== false);
check('self link unwrapped', strpos($r['html'], 'href="' . $all[1]['url'] . '"') === false);
check('forgotten link reported', count($r['missing']) === 1 && $r['missing'][0]['url'] === $u[2]);
check('natural variant accepted', LinkSanitizer::anchor_ok('مشاهده کیف چرم زنانه', array('کیف چرم زنانه')));
check('generic anchor rejected', !LinkSanitizer::anchor_ok('اینجا', array('کیف چرم زنانه')));

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
