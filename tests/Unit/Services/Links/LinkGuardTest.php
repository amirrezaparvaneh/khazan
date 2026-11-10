<?php
/**
 * Unit tests for LinkGuard::enforce_unique_links (no WordPress required).
 *
 *   php tests/Unit/Services/Links/LinkGuardTest.php
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
use Khazan\Services\Content\ContentRules;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function hrefs($html) { preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~i', $html, $m); return $m[2]; }

$cat = 'https://aka.test/product-category/boots/';
$p1 = 'https://aka.test/product/barshka/';
$p2 = 'https://aka.test/product/selena/';
$p3 = 'https://aka.test/product/extra-one/';
$p4 = 'https://aka.test/product/extra-two/';
$wiki = 'https://fa.wikipedia.org/wiki/%DA%86%D8%B1%D9%85';
$links = array(
    array('anchor' => 'بوت زنانه', 'url' => $cat, 'required' => true, 'type' => 'category'),
    array('anchor' => 'بوت برشکا', 'url' => $p1, 'locked' => true, 'required' => true, 'type' => 'product'),
    array('anchor' => 'بوت سلنا', 'url' => $p2, 'locked' => true, 'required' => true, 'type' => 'product'),
);

echo "1. The reported case: 6 links (2 duplicates + 2 extra products) end as 4 unique links\n";
$html = '<p>در <a href="' . $cat . '">بوت زنانه</a> مدل <a href="' . $p1 . '">بوت برشکا</a> و <a href="' . $p2 . '">بوت سلنا</a> را ببینید. <a href="' . $wiki . '" target="_blank" rel="noopener">چرم طبیعی</a></p>'
    . '<ul><li>مزیت: <a href="' . $p1 . '">برشکا</a> سبک است</li><li>مزیت: <a href="' . $p3 . '">مدل اضافه</a></li></ul>'
    . '<h3>سوال: <a href="' . $p2 . '">سلنا</a> یا <a href="' . $p4 . '">مدل دیگر</a>؟</h3><p>پاسخ</p>';
check(count(hrefs($html)) === 8, 'input really has 8 <a> tags (2 duplicates + 2 extra products on top of the 4 wanted)');
$out = ContentGuard::enforce_unique_links($html, $links);
$h = hrefs($out);
check(count($h) === 4, 'exactly 4 links remain (' . count($h) . ')');
check(count(array_unique($h)) === 4, 'all 4 URLs are unique');
check($h === array($cat, $p1, $p2, $wiki), 'they are 1 category + 2 distinct planned products + 1 external, in document order');
check(strpos($out, 'target="_blank" rel="noopener"') !== false, 'the external link keeps target and rel');
check(strpos($out, '<li>مزیت: برشکا سبک است</li>') !== false && strpos($out, '<h3>سوال: سلنا یا مدل دیگر؟</h3>') !== false, 'duplicates in the benefits list and FAQ are unwrapped: the text stays, the <a> tag goes');
check(strpos($out, 'مدل اضافه') !== false && strpos($out, 'مدل دیگر') !== false, 'extra products keep their text');
check(strip_tags($out) === strip_tags($html), 'visible text is identical before and after');

echo "2. A plain 6-link text\n";
$six = '<p><a href="' . $cat . '">دسته</a> <a href="' . $p1 . '">الف</a> <a href="' . $p2 . '">ب</a> <a href="' . $wiki . '">ویکی</a></p><ul><li><a href="' . $p1 . '">الف</a></li></ul><h3><a href="' . $p3 . '">ج</a></h3>';
$h = hrefs(ContentGuard::enforce_unique_links($six, $links));
check(count(hrefs($six)) === 6 && count($h) === 4 && count(array_unique($h)) === 4, '6 links (1 duplicate + 1 extra product) -> 4 unique links');

echo "3. Planned products win over unplanned ones, whatever the order\n";
$mixed = '<p><a href="' . $p3 . '">اضافه</a> <a href="' . $p4 . '">اضافه ۲</a> <a href="' . $p1 . '">برشکا</a> <a href="' . $p2 . '">سلنا</a></p>';
check(hrefs(ContentGuard::enforce_unique_links($mixed, $links)) === array($p1, $p2), 'unplanned products are unwrapped when both planned ones are present');
$onlyOne = '<p><a href="' . $p3 . '">اضافه</a> <a href="' . $p1 . '">برشکا</a></p>';
check(hrefs(ContentGuard::enforce_unique_links($onlyOne, $links)) === array($p1), 'with 2 planned products, no unplanned slot exists (the missing one is added later by ensure_internal_links)');
$full = ContentGuard::ensure_internal_links(ContentGuard::enforce_unique_links($onlyOne, $links), $links);
$fh = hrefs($full);
check(count($fh) === 3 && count(array_unique($fh)) === 3 && in_array($cat, $fh, true) && in_array($p2, $fh, true), 'the pipeline (unique, then ensure) adds back exactly the missing planned links without duplicates');

echo "4. Without a plan the cap is still 2 internal links and 1 external\n";
$noplan = '<p><a href="' . $p1 . '">a</a> <a href="' . $p2 . '">b</a> <a href="' . $p3 . '">c</a> <a href="' . $wiki . '">w</a> <a href="https://example.org/x">e</a> <a href="' . $cat . '">cat</a> <a href="' . $cat . '">cat</a></p>';
$h = hrefs(ContentGuard::enforce_unique_links($noplan, array()));
check($h === array($p1, $p2, $wiki), 'first 2 internal links + first external kept, the rest unwrapped (an unplanned category link counts as internal)');

echo "5. URL normalisation\n";
$norm = '<p><a href="' . $p1 . '">a</a> <a href="' . rtrim($p1, '/') . '">b</a> <a href="HTTPS://AKA.TEST/product/barshka/#faq">c</a> <a href="https://aka.test/product/barshka/&amp;">d</a></p>';
check(count(hrefs(ContentGuard::enforce_unique_links($norm, $links))) <= 2, 'trailing slash, fragment and case do not make a URL "new"');
check(ContentGuard::enforce_unique_links('<p>no links &amp; text</p>', $links) === '<p>no links &amp; text</p>', 'text without links is untouched');
check(ContentGuard::enforce_unique_links('', $links) === '', 'empty html is fine');

echo "6. Prompt rules\n";
$rules = ContentRules::writing_rules('product');
check(strpos($rules, 'فقط یک‌بار') !== false && strpos($rules, 'مزایا') !== false && strpos($rules, 'پرسش‌های متداول') !== false && strpos($rules, '<strong>') !== false, 'product rules: planned links only, each once, no re-linking later');
check(strpos(ContentRules::writing_rules('article'), 'دقیقاً ۴ لینک') === false, 'article rules are unchanged');

echo "7. Planned product anchors are locked (AI cannot distort them)\n";
$marko = 'https://aka.test/product/marko/';
$plan = array(
    array('anchor' => 'انواع کفش طبی مردانه', 'url' => $cat, 'required' => true, 'type' => 'category', 'category_name' => 'کفش طبی مردانه'),
    array('anchor' => 'کفش طبی مردانه مارکو', 'url' => $marko, 'locked' => true, 'required' => true, 'type' => 'product'),
);
$ai = '<p>این مدل را با <a href="' . $marko . '">کفش چرم مارکو</a> مقایسه کنید.</p>';
$fixed = ContentGuard::lock_product_anchors($ai, $plan);
check($fixed === '<p>این مدل را با <a href="' . $marko . '">کفش طبی مردانه مارکو</a> مقایسه کنید.</p>', 'AI wrote «کفش چرم مارکو» -> forced to «کفش طبی مردانه مارکو»');
check(ContentGuard::lock_product_anchors('<a href="https://AKA.test/product/marko#x" class="c">کفش <strong>مارکو</strong></a>', $plan) === '<a href="https://AKA.test/product/marko#x" class="c">کفش طبی مردانه مارکو</a>', 'href variants (case, no slash, #fragment) and nested tags are matched; attributes are kept');
$ok = '<a href="' . $marko . '">کفش طبی مردانه مارکو</a>';
check(ContentGuard::lock_product_anchors($ok, $plan) === $ok, 'a correct anchor is left untouched');
$catHtml = '<a href="' . $cat . '">چیز دیگری</a> <a href="https://fa.wikipedia.org/wiki/x">ویکی</a>';
check(ContentGuard::lock_product_anchors($catHtml, $plan) === $catHtml, 'category and external links are not touched by the product lock');
check(ContentGuard::lock_product_anchors($ai, array()) === $ai, 'no plan -> no change');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
