<?php
/**
 * Unit tests: article keyphrase extraction, article SEO title (emoji placement, exact focus first), density rule.
 *
 *   php tests/Unit/Services/Seo/ArticleSeoTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($t, $v) { return $v; }
function get_option($k, $d = '') { return $k === 'wila_brand_name' ? 'آکا چرم' : $d; }
function get_bloginfo($k) { return 'آکا چرم'; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Seo\KeyphraseExtractor as K;
use Khazan\Services\Seo\SeoTitleGuard as G;
use Khazan\Services\Seo\SeoMetaService as M;
use Khazan\Services\Content\ContentRules as R;
use Khazan\Services\Ai\ContentParser as P;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function emoji_count($t) { return preg_match_all(G::EMOJI_PATTERN, $t); }

$topic = 'جلوگیری از پوسته پوسته شدن کاپشن چرم';
echo "keyphrase\n";
$k = K::extract_for_article($topic, '');
check($k === 'جلوگیری از پوسته پوسته شدن کاپشن', 'topic of 7 words -> complete natural phrase: ' . $k);
check(strpos($k, 'جلوگیری شدن') === false && strpos($k, 'از پوسته') !== false, 'connectors in the middle are never dropped');
check(K::extract_for_article($topic, 'پوسته پوسته شدن کاپشن چرم') === 'پوسته پوسته شدن کاپشن چرم', 'user keyphrase (5 words) kept exactly');
check(K::extract_for_article($topic, 'نحوه تمیز کردن بوت چرم') === 'نحوه تمیز کردن بوت چرم', '5-word phrase untouched (no stop-word stripping)');
check(K::extract_for_article('x', 'راهنمای کامل جلوگیری از پوسته پوسته شدن کاپشن') === 'جلوگیری از پوسته پوسته شدن کاپشن', 'generic lead-in dropped only when the phrase is too long');
check(K::extract_for_article('یک دو سه چهار پنج شش هفت', '') === 'یک دو سه چهار پنج شش', 'cut at 6 words');
$cut = K::extract_for_article('تمیز کردن کاپشن چرم مردانه در خانه با', '');
check(!preg_match('/\sبا$|\sدر$|\sاز$/u', $cut), 'no dangling connector at the end: ' . $cut);

echo "seo title\n";
$focus = K::extract_for_article($topic, '');
$cases = array(
    'AI correct' => $focus . ' | راهنمای کامل 🧥 | آکا چرم',
    'emoji first' => '🧥 ' . $focus . ' | راهنمای کامل | آکا چرم',
    'no emoji' => $focus . ' | راهنمای کامل',
    'emoji mid-part' => $focus . ' | راهنمای 🧥 کامل | آکا چرم',
    'unrelated start' => 'راهنمای نگهداری کاپشن | ' . $focus . ' | آکا چرم',
    'two emoji' => $focus . ' 🧥 | راهنمای کامل 📘',
);
foreach ($cases as $name => $in) {
    $out = M::guard_article_title($in, $focus, 'آکا چرم', $topic);
    check(mb_strpos($out, $focus) === 0, "$name: starts with the exact focus keyword  → $out");
    check(emoji_count($out) === 1 && G::emoji_position_ok($out), "$name: one emoji, before | or at the end");
    check(mb_strlen($out) <= 60, "$name: <= 60 chars (" . mb_strlen($out) . ')');
}
check(M::guard_article_title($cases['AI correct'], $focus, 'آکا چرم', $topic) === 'جلوگیری از پوسته پوسته شدن کاپشن | راهنمای کامل 🧥 | آکا چرم', 'reference title kept as it is');
check(strpos(M::guard_article_title($focus . ' | راهنمای کامل 🧥', $focus, 'آکا چرم', $topic), '🧥') !== false, 'jacket topic gets the jacket emoji');
$long = M::guard_article_title('جلوگیری از پوسته پوسته شدن کاپشن چرم مردانه و زنانه | راهنمای بسیار کامل و جامع نگهداری | آکا چرم', 'جلوگیری از پوسته پوسته شدن کاپشن چرم مردانه و زنانه', 'آکا چرم', '');
check(mb_strpos($long, 'جلوگیری از پوسته پوسته شدن کاپشن چرم مردانه و زنانه') === 0 && emoji_count($long) === 1, 'long keyphrase: never cut, hook/brand shrink first: ' . $long);
check(!G::emoji_position_ok('🧥 عنوان | x') && G::emoji_position_ok('عنوان 🧥 | x') && G::emoji_position_ok('عنوان | x 🧥') && !G::emoji_position_ok('عنوان 🧥 x | y'), 'emoji position validator');
$cat = P::normalize_seo_title('🛍️ خرید کاپشن چرم | انواع کاپشن', 'product_cat', '', 'کاپشن چرم');
check(!preg_match('/[\x{1F300}-\x{1FAFF}]/u', $cat) && mb_strpos($cat, 'کاپشن چرم') === 0, 'title: no emoji, focus first -> ' . $cat);
$art = P::normalize_seo_title('🧥 ' . $focus . ' | راهنمای کامل', 'article', $topic, $focus);
check(mb_strpos($art, $focus) === 0 && !preg_match('/[\x{1F300}-\x{1FAFF}]/u', $art), 'article title follows the project title rule (no emoji)');

echo "density rule\n";
check(strpos(R::article_keyword_rule(600), 'حداقل 4 بار') !== false, '600 words: at least 4');
check(strpos(R::article_keyword_rule(1500), 'حداقل 6 بار') !== false, '1500 words: at least 6');
check(strpos(R::article_keyword_rule(1000), 'حداقل 4 بار') !== false, '1000 words: 4');
$rule = R::article_keyword_rule(1200);
check(strpos($rule, 'Exact Match') !== false && strpos($rule, 'پاراگراف اول') !== false && strpos($rule, 'H2') !== false && strpos($rule, 'میانه') !== false && strpos($rule, 'نتیجه‌گیری') !== false, 'rule lists intro, H2, middle and conclusion');
check(strpos(R::writing_rules('article', 1200), 'حداقل 5 بار') !== false && strpos(R::writing_rules('product'), 'چگالی کلیدواژه کانونی') !== false, 'article rule in writing rules; product uses the density standard');
check(strpos(R::seo_title_rules('article', $focus), 'ایموجی در عنوان ممنوع') !== false, 'prompt forbids emoji in titles');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
