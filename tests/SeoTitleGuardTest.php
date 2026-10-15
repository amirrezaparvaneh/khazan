<?php
/**
 * Unit tests for the 3-part product SEO title (length, emoji, brand, storage keys).
 *
 *   php tests/SeoTitleGuardTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $k === 'wila_brand_name' ? 'آکا چرم' : $d; }
function get_bloginfo($k) { return 'Site'; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Seo\SeoTitleGuard as G;
use Khazan\Services\Seo\SeoMetaService as S;
use Khazan\Services\Content\ContentRules;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function len($s) { return mb_strlen($s); }
function emojis($s) { preg_match_all(G::EMOJI_PATTERN, $s, $m); return count($m[0]); }
$brand = 'آکا چرم';

echo "1. Reference example\n";
$ref = 'نیم‌بوت چرم زنانه آلفردو | چرم طبیعی تبریز 👢 | آکا چرم';
check(G::fit($ref, $brand) === $ref, 'a correct title is left unchanged');
check(len($ref) >= 50 && len($ref) <= 60, 'the reference itself is 50-60 characters (' . len($ref) . ')');

echo "2. Brand is appended when missing\n";
$t = G::fit('نیم‌بوت چرم زنانه آلفردو | چرم طبیعی تبریز 👢', $brand);
check($t === $ref, 'AI title without brand gets « | آکا چرم »: ' . $t);
$t = G::fit('کیف دوشی چرم مردانه آرتین | دست‌دوز تبریز 👜 | آکا چرم', $brand);
check(substr_count($t, 'آکا چرم') === 1, 'the brand is never duplicated');
check(G::fit('کیف دوشی چرم مردانه آرتین', $brand) !== '' && mb_substr(G::fit('کیف دوشی چرم مردانه آرتین', $brand), -7) === 'آکا چرم', 'a title with no pipes still ends with the brand');

echo "3. Emoji by category\n";
$cases = array(
    array('نیم‌بوت چرم زنانه آلفردو | چرم طبیعی 👟', '👢'),
);
$map = array(
    'نیم‌بوت چرم زنانه آلفردو' => '👢', 'بوت چرم مردانه ماهان' => '👢', 'کفش پاشنه‌دار زنانه لارا' => '👠', 'کفش مجلسی زنانه ستاره' => '👠',
    'کفش مردانه رسمی چرم پارسا' => '👞', 'کتانی مردانه چرم اسپرت' => '👟', 'کیف دوشی چرم زنانه آیسا' => '👜', 'کیف مجلسی زنانه چرم نگین' => '👜',
    'کوله پشتی چرم مردانه آرمان' => '🎒', 'کیف پول چرم مردانه سینا' => '👝', 'کمربند چرم مردانه کلاسیک' => '👝',
);
$okAll = true;
foreach ($map as $first => $want) {
    $out = G::fit($first . ' | چرم طبیعی تبریز', $brand);
    $okAll = $okAll && emojis($out) === 1 && strpos($out, $want) !== false;
    if (strpos($out, $want) === false) echo "     $first => $out (wanted $want)\n";
}
check($okAll, 'every category gets its own single emoji');
$out = G::fit('نیم‌بوت چرم زنانه آلفردو | چرم طبیعی تبریز 👟 👜 | آکا چرم', $brand);
check(emojis($out) === 1, 'several AI emojis collapse to exactly one');
check(strpos(G::fit('محصول تست | مزیت', $brand), '|') !== false && emojis(G::fit('محصول تست | مزیت', $brand)) === 1, 'unknown category still gets the default emoji');

echo "4. Length <= 60 without losing keywords or brand\n";
$long = 'نیم‌بوت چرم زنانه آلفردو مشکی ساقدار | چرم طبیعی تبریز با دوخت دستی و زیره طبی ضد لغزش 👢 | آکا چرم';
$out = G::fit($long, $brand);
check(len($out) <= 60, 'long AI title is cut to <= 60 chars (' . len($out) . '): ' . $out);
check(strpos($out, 'نیم‌بوت چرم زنانه') === 0, 'the keyword part stays at the start');
check(mb_substr($out, -7) === 'آکا چرم' && emojis($out) === 1, 'brand and the single emoji survive');
$ok = true; mt_srand(7);
$w = array('کفش', 'کیف', 'چرم', 'طبیعی', 'تبریز', 'مردانه', 'زنانه', 'آلفردو', 'دست‌دوز', 'ضد', 'لغزش', 'سبک', 'راحت', 'مجلسی', 'کتانی');
for ($i = 0; $i < 400; $i++) {
    $a = array(); $b = array();
    for ($j = 0, $n = mt_rand(2, 9); $j < $n; $j++) $a[] = $w[mt_rand(0, 14)];
    for ($j = 0, $n = mt_rand(0, 9); $j < $n; $j++) $b[] = $w[mt_rand(0, 14)];
    $o = G::fit(implode(' ', $a) . ' | ' . implode(' ', $b), $brand);
    if (len($o) > 60 || emojis($o) !== 1 || mb_substr($o, -7) !== 'آکا چرم' || !G::is_valid($o, $brand)) { $ok = false; echo "     bad: $o\n"; break; }
}
check($ok, '400 random titles: always <= 60 chars, one emoji, brand last');

echo "5. Storage in Rank Math and Yoast\n";
$final = S::guard_title('نیم‌بوت چرم زنانه آلفردو | چرم طبیعی تبریز 👢', $brand);
$post = S::post_meta_map(array('title' => $final, 'desc' => 'd', 'focuskw' => 'نیم‌بوت زنانه آلفردو', 'synonyms' => ''));
check($post['rank_math_title'] === $ref && $post['_yoast_wpseo_title'] === $ref, 'rank_math_title and _yoast_wpseo_title hold the same final title');
$term = S::term_meta_map(array('title' => $final, 'desc' => '', 'focuskw' => '', 'synonyms' => ''));
check($term['wpseo_title'] === $ref, 'Yoast term title holds the final title');

echo "6. Prompt\n";
$rules = ContentRules::seo_title_rules('product');
check(strpos($rules, '[نوع کالا + جنس + جنسیت + نام مدل] | [مزیت') !== false && strpos($rules, '۶۰ کاراکتر') !== false, 'product rules dictate the 3-part formula and the 60-char cap');
check(strpos($rules, '👢') !== false && strpos($rules, '👠') !== false && strpos($rules, '👞') !== false && strpos($rules, '👟') !== false && strpos($rules, '👜') !== false && strpos($rules, '🎒') !== false && strpos($rules, '👝') !== false, 'all allowed emojis are listed');
check(strpos(ContentRules::seo_title_rules('article'), 'نام سایت را اضافه نکن') !== false, 'article rules are unchanged');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
