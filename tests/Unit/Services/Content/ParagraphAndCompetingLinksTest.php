<?php
/**
 * Unit tests: paragraph guard, competing-link neutralizer, keyphrase formula (no WordPress required).
 *
 *   php tests/Unit/Services/Content/ParagraphAndCompetingLinksTest.php
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
use Khazan\Services\Content\Guard\ParagraphGuard as P;
use Khazan\Services\Seo\KeyphraseExtractor as K;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function sentence($n, $tag = 'w') { $w = array(); for ($i = 1; $i <= $n; $i++) $w[] = $tag . $i; return implode(' ', $w) . '.'; }
function paragraphs($html) { preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $html, $m); return $m[1]; }

echo "1. Keyphrase formula\n";
check(K::from_title('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر') === 'کفش مردانه ونس فلوتر', '«کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر» -> «کفش مردانه ونس فلوتر»');
check(K::from_title('بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت چرم زنانه آلفردو', '«بوت زنانه چرم طبیعی تبریز مدل آلفردو» -> «بوت چرم زنانه آلفردو» (1-word model: چرم fills the 4th slot)');
check(K::optimal('بوت چرم زنانه آلفردو', 'بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت چرم زنانه آلفردو', 'the stored keyphrase «بوت چرم زنانه آلفردو» is already the ideal 4 words');
$stored = Khazan\Services\Seo\SeoMetaService::post_meta_map(array('title' => 't', 'desc' => 'd', 'focuskw' => 'بوت چرم زنانه آلفردو تبریز', 'synonyms' => ''));
check($stored['_yoast_wpseo_focuskw'] === 'بوت چرم زنانه آلفردو' && strpos($stored['rank_math_focus_keyword'], 'بوت چرم زنانه آلفردو') === 0, 'the same short phrase is what Yoast and Rank Math store');

echo "2. No outgoing link repeats the page keyphrase\n";
$cat = 'https://aka.test/product-category/boots/'; $p1 = 'https://aka.test/product/barshka/'; $wiki = 'https://fa.wikipedia.org/wiki/x';
$focus = 'بوت چرم زنانه آلفردو';
$links = array(
    array('anchor' => 'انواع بوت چرم زنانه', 'url' => $cat, 'required' => true, 'type' => 'category', 'category_name' => 'بوت چرم زنانه'),
    array('anchor' => 'بوت مدل برشکا', 'url' => $p1, 'locked' => true, 'required' => true, 'type' => 'product'),
);
$html = '<p>ببینید <a href="' . $cat . '">بوت چرم زنانه</a> و <a href="' . $p1 . '">بوت مدل برشکا</a> و <a href="' . $wiki . '">بوت زنانه</a> و <a href="https://aka.test/c/other/">بوت زنانه آلفردو</a> و <a href="https://aka.test/c/two/">کیف مردانه</a>.</p>';
$out = ContentGuard::neutralize_competing_links($html, $focus, $links, array('پوتین زنانه'));
preg_match_all('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~', $out, $m);
$byUrl = array_combine($m[1], $m[2]);
check($byUrl[$cat] === 'انواع بوت چرم زنانه', 'the raw category anchor «بوت چرم زنانه» becomes prefix + the real name: «انواع بوت چرم زنانه»');
$already = ContentGuard::neutralize_competing_links('<a href="' . $cat . '">کلکسیون بوت چرم زنانه</a>', $focus, $links);
check(strpos($already, '>کلکسیون بوت چرم زنانه</a>') !== false, 'an anchor that already is «کلکسیون» + the real name is left as it is');
$wrong = ContentGuard::neutralize_competing_links('<a href="' . $cat . '">سایر مدل‌های این دسته</a>', $focus, $links);
check(strpos($wrong, '>انواع بوت چرم زنانه</a>') !== false && strpos($wrong, 'سایر مدل‌ها') === false, 'a cliché such as «سایر مدل‌های این دسته» is replaced by prefix + the real category name');
check($byUrl[$p1] === 'بوت مدل برشکا', 'the planned product link keeps its model-name anchor');
check($byUrl[$wiki] === 'بوت زنانه', 'the external link is never touched');
check(strpos($out, '<a href="https://aka.test/c/other/"') === false && strpos($out, 'بوت زنانه آلفردو') !== false, 'an unplanned internal link repeating the keyphrase is unwrapped (text stays, tag goes)');
check($byUrl['https://aka.test/c/two/'] === 'کیف مردانه', 'an unrelated internal anchor stays');
$leak = false;
preg_match_all('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~', $out, $mm);
foreach ($mm[2] as $i => $anchor) { if ($mm[1][$i] !== $wiki && K::count_words($anchor) >= K::count_words($focus) && K::overlaps($anchor, $focus)) $leak = true; }
check(!$leak, 'no internal anchor in the final HTML contains the whole page keyphrase');
$noPlan = ContentGuard::neutralize_competing_links($html, '', $links);
check($noPlan === str_replace('>بوت چرم زنانه</a>', '>انواع بوت چرم زنانه</a>', $html), 'without a focus keyphrase only the category anchor is normalised; every other link is untouched');

echo "2b. Category anchor survives the pipeline (insert + 4-word cap exemption)\n";
$catLinks = array(array('anchor' => 'مجموعه مدل‌های بوت چرم زنانه', 'url' => $cat, 'required' => true, 'type' => 'category', 'category_name' => 'بوت چرم زنانه'));
$ins = ContentGuard::ensure_internal_links('<p>متن</p>', $catLinks);
check(strpos($ins, '>مجموعه مدل‌های بوت چرم زنانه</a>') !== false, 'ensure_internal_links inserts the full 5-word category anchor («چرم» is not stripped, nothing is cut)');
$capped = ContentGuard::cap_anchor_length($ins, 4, array($cat));
check(strpos($capped, '>مجموعه مدل‌های بوت چرم زنانه</a>') !== false, 'cap_anchor_length leaves the planned category anchor at 5 words');
$other = ContentGuard::cap_anchor_length('<a href="https://aka.test/p/zz/">یک دو سه چهار پنج</a>', 4, array($cat));
check(strpos($other, '>یک دو سه چهار</a> پنج') !== false, 'other anchors are still capped at 4 words');
$final = ContentGuard::neutralize_competing_links($capped, $focus, $catLinks);
preg_match('~<a[^>]*>(.*?)</a>~', $final, $fa);
check(K::count_words($fa[1]) === 5 && !array_diff(K::words('بوت چرم زنانه'), K::words($fa[1])), 'the final anchor «' . $fa[1] . '» contains the real category name and is not equal to the page keyphrase');
check(!(K::overlaps($fa[1], $focus) && K::count_words($fa[1]) >= K::count_words($focus)), 'it does not contain the whole page keyphrase «بوت چرم زنانه آلفردو» (the Yoast / Rank Math competing-link trigger)');

echo "3. Paragraph guard: explicit <p> everywhere\n";
$raw = '<h2>عنوان</h2>' . "\nمتن اول است. دوم است.\n\nمتن دوم با <a href=\"/x\">لینک</a> است.\n<ul><li>الف</li></ul>\nمتن آخر<br><br>بعد از br" ;
$fixed = ContentGuard::enforce_paragraphs($raw);
check(substr_count($fixed, '<p>') === 4 && substr_count($fixed, '</p>') === 4, 'four loose text blocks (blank line / <br><br> separated) become four <p> tags');
check(strpos($fixed, '<h2>عنوان</h2>') !== false && strpos($fixed, '<ul><li>الف</li></ul>') !== false, 'headings and lists are left as they are');
check(!preg_match('~(^|</h2>|</ul>|</p>)\s*[^<\s]~u', $fixed), 'no loose text remains outside <p>, <h2> and <ul>');
$strip = preg_replace('~\s+~', ' ', strip_tags($raw));
check(preg_replace('~\s+~', ' ', strip_tags($fixed)) === preg_replace('~\s+~', '', $strip) || str_replace(' ', '', strip_tags($fixed)) === str_replace(' ', '', $strip), 'the visible text is unchanged');
check(ContentGuard::enforce_paragraphs('<p>سلام</p>') === '<p>سلام</p>' && ContentGuard::enforce_paragraphs('') === '', 'existing <p> and empty input are fine');

echo "4. Long paragraphs are split at a sentence boundary (<= 110 words)\n";
$long = '<h2>t</h2>' . "\n" . sentence(40, 'a') . ' ' . sentence(40, 'b') . ' ' . sentence(40, 'c') . ' ' . sentence(40, 'd');
$res = ContentGuard::enforce_paragraphs($long);
$ps = paragraphs($res);
$wc = array_map(function ($p) { return P::words($p); }, $ps);
check(count($ps) >= 2 && max($wc) <= 110, '160 words in one block -> ' . count($ps) . ' paragraphs (' . implode('/', $wc) . ' words)');
check(array_sum($wc) === 160, 'no word is lost');
check(preg_match('~^\S.*\.$~u', $ps[0]) === 1 && preg_match('~^[a-d]1 ~', $ps[1]) === 1, 'the cut is right after a full stop, the next <p> begins with a fresh sentence');
$huge = '<p>' . implode(' ', array_map(function ($i) { return sentence(30, 's' . $i); }, range(1, 12))) . '</p>';
$wc = array_map(function ($p) { return P::words($p); }, paragraphs(ContentGuard::enforce_paragraphs($huge)));
check(max($wc) <= 110 && array_sum($wc) === 360, '360 words (12 sentences) -> every paragraph <= 110 words (' . implode('/', $wc) . ')');
$withLink = '<p>' . sentence(30, 'x') . ' ' . '<a href="/u">' . sentence(20, 'l') . ' ' . sentence(20, 'm') . '</a> ' . sentence(30, 'y') . ' ' . sentence(30, 'z') . '</p>';
$res = ContentGuard::enforce_paragraphs($withLink);
$balanced = true; foreach (paragraphs($res) as $p) { if (substr_count($p, '<a ') !== substr_count($p, '</a>')) $balanced = false; }
check($balanced && max(array_map(function ($p) { return P::words($p); }, paragraphs($res))) <= 110, 'a split never happens inside an <a> tag (anchors stay balanced)');
$noStop = '<p>' . implode(' ', range(1, 150)) . '</p>';
check(ContentGuard::enforce_paragraphs($noStop) === $noStop, 'a block without any sentence boundary is left alone instead of being cut mid-sentence');
$comma = '<p>' . implode('، ', array_map(function ($i) { return 'ب' . $i . ' ' . sentence(0) . 'ج' . $i . ' د' . $i; }, range(1, 60))) . '</p>';
check(max(array_map(function ($p) { return P::words($p); }, paragraphs(ContentGuard::enforce_paragraphs($comma)))) <= 110, 'with no full stop the Persian comma is the fallback cut');

echo "5. End to end on a typical model answer\n";
$answer = '<h2>معرفی</h2>' . "\n" . sentence(70, 'q') . ' ' . sentence(70, 'r') . "\n\n" . sentence(50, 's') . '<h2>مزایا</h2><ul><li>الف</li></ul>' . "\n" . sentence(40, 'z') . ' ' . sentence(40, 'y') . ' ' . sentence(40, 'x') . ' ' . sentence(40, 'w');
$res = ContentGuard::enforce_paragraphs($answer);
$all = paragraphs($res);
$wc = array_map(function ($p) { return P::words($p); }, $all);
check(max($wc) <= 110, 'all ' . count($all) . ' paragraphs are <= 110 words (' . implode('/', $wc) . ')');
$loose = preg_replace('~<(h2|ul|p)\b.*?</\1>~s', '', $res);
check(trim($loose) === '', 'nothing but <h2>, <ul> and <p> blocks remains');

echo "6. Prompt rules\n";
foreach (array('article', 'product', 'product_cat') as $k) {
    $r = ContentRules::writing_rules($k);
    check(strpos($r, '<p>...</p>') !== false && strpos($r, '۶۰ تا ۹۰ کلمه') !== false && strpos($r, '۱۱۰ کلمه') !== false, "writing_rules($k): explicit <p>, 60-90 words per paragraph");
}
$r = ContentRules::writing_rules('product');
check(strpos($r, '[نوع کالا] + [جنسیت] + [نام اختصاصی مدل]') !== false && strpos($r, 'طبیعی، تبریز، مدل، طرح، اصل، دست‌دوز و کد') !== false && strpos($r, 'بوت چرم زنانه آلفردو') !== false, 'the keyphrase formula, its forbidden words and the 1-word-model چرم rule are dictated');
check(strpos($r, '«انواع»، «مجموعه مدل‌های» یا «کلکسیون» به‌همراه نام واقعی دسته') !== false, 'the category anchor must be prefix + the real category name');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
