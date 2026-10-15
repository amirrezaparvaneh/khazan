<?php
/**
 * Integration checks for the 4-word keyphrase / anchor standard (no WordPress required).
 *
 *   php tests/KeyphraseIntegrationTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__) . '/');
function apply_filters($tag, $value) { return $value; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function get_option($k, $d = '') { return $d; }
function get_bloginfo($k) { return 'Site'; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function esc_url($s) { return (string) $s; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Seo\KeyphraseExtractor as K;
use Khazan\Services\Seo\SeoMetaService;
use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Content\ContentRules;
use Khazan\Services\Links\SmartLinkDistributor;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

echo "1. LinkGuard::cap_anchor_length\n";
$long = '<p>ببینید <a href="https://s.test/p1/">کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر</a> را.</p>';
$out = ContentGuard::cap_anchor_length($long);
preg_match_all('~<a\b[^>]*>(.*?)</a>~s', $out, $m);
check(count($m[1]) === 1 && K::count_words($m[1][0]) === 4, 'an 8-word anchor is cut to 4 words: «' . ($m[1][0] ?? '') . '»');
check(strpos($out, 'طبیعی تبریز مدل ونس فلوتر') !== false, 'the removed words stay in the sentence as plain text');
$short = '<p><a href="/x">کفش مردانه ونس</a></p>';
check(ContentGuard::cap_anchor_length($short) === $short, 'anchors within 4 words are untouched');
$nested = '<a href="/x">یک دو <strong>سه چهار پنج شش</strong></a>';
check(ContentGuard::cap_anchor_length($nested) === $nested, 'anchors with nested tags are left alone');

echo "2. ensure_internal_links / enforce_link_anchors cap the anchor\n";
$links = array(array('anchor' => 'کفش روزمره مردانه چرم طبیعی تبریز مدل ونس', 'url' => 'https://s.test/p9/', 'required' => true, 'type' => 'product'));
$html = ContentGuard::ensure_internal_links('<p>متن</p>', $links);
preg_match_all('~<a\b[^>]*>(.*?)</a>~s', $html, $m);
check(count($m[1]) === 1 && K::count_words($m[1][0]) <= 4, 'the appended required link has <= 4 words: «' . ($m[1][0] ?? '') . '»');
$map = array('https://s.test/p9/' => 'الف ب ج د ه و ز');
$html = ContentGuard::enforce_link_anchors('<a href="https://s.test/p9/">x</a>', $map);
preg_match_all('~<a\b[^>]*>(.*?)</a>~s', $html, $m);
check(K::count_words($m[1][0]) >= 2 && K::count_words($m[1][0]) <= 4, 'enforce_link_anchors cuts a locked anchor to <= 4 words');

echo "3. SeoMetaService never stores more than 4 focus words\n";
$seo = array('title' => 't', 'desc' => 'd', 'focuskw' => 'کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر', 'synonyms' => 'الف, ب');
$post = SeoMetaService::post_meta_map($seo);
check(K::count_words($post['_yoast_wpseo_focuskw']) === 4, 'Yoast post focuskw has 4 words: «' . $post['_yoast_wpseo_focuskw'] . '»');
$first = explode(',', $post['rank_math_focus_keyword'])[0];
check(K::count_words($first) === 4, 'RankMath primary keyword has 4 words');
$term = SeoMetaService::term_meta_map($seo);
check(K::count_words($term['wpseo_focuskw']) === 4, 'Yoast term focuskw has 4 words');
$ok = SeoMetaService::post_meta_map(array('title' => '', 'desc' => '', 'focuskw' => 'کفش مردانه ونس', 'synonyms' => ''));
check($ok['_yoast_wpseo_focuskw'] === 'کفش مردانه ونس', 'a valid short keyphrase is stored unchanged');

echo "4. Prompt rules\n";
foreach (array('article', 'product', 'product_cat') as $kind) {
    $rules = ContentRules::writing_rules($kind);
    check(strpos($rules, 'بیش از ۹۵٪ جمله‌های متن باید معلوم') !== false && strpos($rules, 'حداکثر ۱ بار') !== false && strpos($rules, 'صنعتگران تبریز این مدل را طراحی و تولید کرده‌اند') !== false && strpos($rules, 'طراحان در بخش زیره از پلی‌اورتان بهره برده‌اند') !== false && strpos($rules, 'رویه کفش از چرم طبیعی تشکیل شده') !== false, "writing_rules($kind) demands >95% active voice, passive patterns at most once, with the three rewrite examples");
    check(strpos($rules, '۲ تا ۴ کلمه') !== false && strpos($rules, 'حداکثر ۴ کلمه') !== false && strpos($rules, 'هرگز کل نام محصول') !== false, "writing_rules($kind) demands a 2-4 word focus_keyword and <= 4 word anchors");
}
$pb = new Khazan\Services\Ai\PromptBuilder('');
$prompt = $pb->bulk_product(
    array('focus_keyword' => 'کفش مردانه ونس', 'internal_links' => array(), 'word_count' => 1000),
    array('name' => 'کفش', 'seo_title' => '', 'meta_description' => '', 'related_keywords' => '')
);
check(strpos($prompt, 'نه کل نام محصول') !== false && strpos($prompt, 'حداکثر ۴ کلمه') !== false, 'bulk prompt states the 2-4 word keyphrase and 4-word anchors');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
