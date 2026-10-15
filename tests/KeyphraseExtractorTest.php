<?php
/**
 * Standalone unit tests for Khazan\Services\Seo\KeyphraseExtractor (no WordPress required).
 *
 *   php tests/KeyphraseExtractorTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

/** Minimal stand-in for the WordPress filter used by Settings::keyphrase_words(). */
$GLOBALS['kp_filter'] = null;
function apply_filters($tag, $value) { return $GLOBALS['kp_filter'] ? call_user_func($GLOBALS['kp_filter'], $value) : $value; }

use Khazan\Services\Seo\KeyphraseExtractor as K;

$passed = 0; $failed = 0;
function check($condition, $message) {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok: $message\n"; } else { $failed++; echo "  FAIL: $message\n"; }
}
function wc($s) { return K::count_words($s); }

echo "1. The reference example\n";
$title = 'کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر';
check(K::from_title($title) === 'کفش مردانه ونس فلوتر', 'full product name -> «کفش مردانه ونس فلوتر»');
check(K::from_title($title, 'کفش مردانه') === 'کفش مردانه ونس فلوتر', 'same result when the main category (کفش مردانه) is supplied');
check(wc(K::from_title($title)) === 4, 'result has exactly 4 words');

echo "2. Titles of 5 to 8 words always end up with at most 4 words\n";
$titles = array(
    5 => array('کفش چرم طبیعی مردانه تبریز', 'کیف دوشی زنانه چرم اصل مجلسی', 'نیم‌بوت زنانه چرم طبیعی تبریز مشکی'),
    6 => array('کفش روزمره مردانه چرم طبیعی تبریز', 'کیف دوشی چرم طبیعی زنانه مدل آیسا', 'صندل زنانه چرم اصل تبریز مدل مهسا'),
    7 => array('کفش اسپرت مردانه چرم طبیعی تبریز مدل پارسا', 'کیف دستی زنانه چرم طبیعی اصل تبریز مدل نیلوفر', 'نیم‌بوت مردانه چرم طبیعی تبریز مدل آرشا قهوه‌ای'),
    8 => array('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر', 'کیف دوشی چرم طبیعی زنانه مدل آیسا مجلسی لوکس', 'کفش اسپرت مردانه چرم اصل تبریز مدل ونس اولد اسکول'),
);
$allOk = true; $minOk = true; $subsetOk = true;
foreach ($titles as $n => $list) foreach ($list as $t) {
    $out = K::from_title($t);
    $allOk = $allOk && wc($t) >= 5 && wc($t) <= 10 && wc($out) <= 4;
    $minOk = $minOk && wc($out) >= 2;
    foreach (K::words($out) as $w) $subsetOk = $subsetOk && in_array($w, K::words($t), true);
    echo "     [$n words] $t  =>  $out\n";
}
check($allOk, 'every 5-8 word title yields <= 4 words');
check($minOk, 'and never fewer than 2 words');
check($subsetOk, 'every derived word comes from the product title');

echo "3. limit() is an exact hard cap\n";
$ok = true;
foreach (array(5, 6, 7, 8) as $n) {
    $phrase = implode(' ', array_map(function ($i) { return "واژه$i"; }, range(1, $n)));
    $ok = $ok && wc(K::limit($phrase)) === 4 && K::limit($phrase) === 'واژه1 واژه2 واژه3 واژه4' && wc(K::cap($phrase)) >= 2 && wc(K::cap($phrase)) <= 4;
}
check($ok, 'inputs of 5, 6, 7 and 8 words are cut by limit() to exactly the first 4 words, and cap() gives 2-4 words');
check(K::limit('یک دو سه') === 'یک دو سه' && K::cap('') === '' && K::limit("  کفش   مردانه \n ونس ") === 'کفش مردانه ونس', 'shorter / blank / messy-whitespace input is normalized, not padded');
check(K::limit('الف ب ج د هـ', 2) === 'الف ب', 'explicit smaller limit works');

echo "4. Stored focus keyphrase is reused when it fits (1-4 words)\n";
check(K::optimal('کفش مردانه ونس', $title) === 'کفش مردانه ونس فلوتر', 'a stored 3-word keyphrase that drops part of the model name is replaced by the 4-word title form');
check(K::optimal('کفش مردانه ونس', 'x') === 'کفش مردانه ونس', 'without a usable title a stored 3-word keyphrase is used as it is');
check(K::optimal('  کفش   مردانه  ونس فلوتر ', $title) === 'کفش مردانه ونس فلوتر', 'a stored 4-word keyphrase is used (whitespace normalized)');
check(K::optimal('کفش مردانه چرم طبیعی ونس فلوتر', $title) === 'کفش مردانه ونس فلوتر', 'a stored 5-word keyphrase is NOT used; it is derived from the title instead');
check(K::optimal('', $title) === 'کفش مردانه ونس فلوتر', 'no stored keyphrase -> derived from the title');
check(K::optimal('کفش', 'x') === 'کفش', 'a stored single word is valid');
check(K::is_valid('الف ب ج د') && !K::is_valid('الف ب ج د ه') && !K::is_valid(''), 'is_valid: 1-4 words only');

echo "5. Short titles are kept; fillers, materials and cities are dropped\n";
check(K::from_title('کفش مردانه ونس') === 'کفش مردانه ونس', 'a 3-word title is returned unchanged');
check(K::from_title('کفش مردانه ونس فلوتر') === 'کفش مردانه ونس فلوتر', 'a clean 4-word title is not touched (already within the limit)');
check(K::from_title('کفش چرم طبیعی مردانه') === 'کفش چرم مردانه', 'a 4-word title loses «طبیعی» (unconditional) but keeps «چرم» (free slot after the type)');
$d = K::from_title('کیف چرم طبیعی اصل تبریز دست‌دوز لوکس شیک');
check(wc($d) >= 2 && wc($d) <= 4 && strpos($d, 'تبریز') === false && strpos($d, 'طبیعی') === false && strpos($d, 'اصل') === false, 'generic words (طبیعی، اصل، تبریز، لوکس…) never survive: «' . $d . '»');
check(strpos(K::from_title('کفش اسپرت مردانه چرم اصل تبریز مدل ونس اولد اسکول'), 'مدل') === false, 'the «مدل» marker itself is never part of the phrase');
check(K::from_title('کفش اسپرت مردانه تبریز چرم اصل ونس اولد اسکول') === 'کفش مردانه اولد اسکول', 'without «مدل»: product type + gender + the last distinctive words');
check(K::from_title('کیف دوشی چرم طبیعی زنانه مدل آیسا مجلسی') === 'کیف مجلسی زنانه آیسا', 'gender qualifier is preserved; the free 4th slot goes to the usage word «مجلسی» (before «چرم»)');
check(K::from_title('کیف دوشی چرم طبیعی زنانه مدل آیسا مجلسی', 'کیف دوشی زنانه') === 'کیف دوشی زنانه آیسا', 'when the category words are given they form the base (کیف دوشی زنانه) and the model name is kept');

echo "6. Robustness\n";
check(K::from_title('') === '' && K::from_title('   ') === '' && K::limit(null) === '', 'empty input -> empty string');
check(K::from_title('<b>کفش</b> «مردانه»، چرم طبیعی (اصل) تبریز - مدل: ونس!') === 'کفش چرم مردانه ونس', 'HTML tags and punctuation are stripped: «' . K::from_title('<b>کفش</b> «مردانه»، چرم طبیعی (اصل) تبریز - مدل: ونس!') . '»');
check(K::from_title("نیم\u{200c}بوت زنانه چرم طبیعی تبریز مدل ماهان") === "نیم\u{200c}بوت چرم زنانه ماهان", 'ZWNJ inside a word is kept and the word counts once');
check(K::from_title('کفش روزمرۀ مردانه چرم طبیعی تبريز مدل ونس') !== '' && strpos(K::from_title('کفش روزمره مردانه چرم طبيعي تبريز مدل ونس'), 'تبريز') === false, 'Arabic yeh variants (تبريز، طبيعي) are matched against the word lists');
check(K::from_title('Vans Old Skool Classic Black Leather Sneaker Men') !== '' && wc(K::from_title('Vans Old Skool Classic Black Leather Sneaker Men')) <= 4, 'Latin titles also stay within 4 words');
check(K::from_title('Nike Air Max 90 Essential Black White') === 'Nike Air Max 90' || wc(K::from_title('Nike Air Max 90 Essential Black White')) <= 4, 'mixed model codes stay within 4 words');

echo "7. Property test: 600 random 5-12 word titles\n";
mt_srand(14);
$pool = array('کفش', 'کیف', 'صندل', 'نیم‌بوت', 'مردانه', 'زنانه', 'چرم', 'طبیعی', 'اصل', 'تبریز', 'مدل', 'ونس', 'فلوتر', 'آیسا', 'روزمره', 'مجلسی', 'اسپرت', 'لوکس', 'قهوه‌ای', 'مشکی', 'سایز', '۴۲', 'نایک', 'پارسا', 'ست', 'و');
$bad = 0; $short = 0; $subsetBad = 0;
for ($i = 0; $i < 600; $i++) {
    $n = mt_rand(5, 12); $ws = array();
    for ($j = 0; $j < $n; $j++) $ws[] = $pool[mt_rand(0, count($pool) - 1)];
    $t = implode(' ', $ws); $out = K::from_title($t);
    if (wc($out) > 4) $bad++;
    $fill = array_flip(array_merge(\Khazan\Core\Settings::KEYPHRASE_FILLERS, array('مدل')));
    $distinct = count(array_unique(array_diff($ws, array_keys($fill), \Khazan\Core\Settings::KEYPHRASE_CITIES, array("۴۲"))));
    if ($distinct >= 2 && wc($out) < 2) $short++;
    foreach (K::words($out) as $w) if (!in_array($w, $ws, true)) $subsetBad++;
    if (wc(K::optimal($t, $t)) > 4) $bad++;
}
check($bad === 0, 'never more than 4 words across 600 random titles (violations: ' . $bad . ')');
check($short === 0, 'never fewer than 2 words when the title has >= 2 distinctive words (violations: ' . $short . ')');
check($subsetBad === 0, 'derived words always come from the title');

echo "8. Word lists can be extended with the wila_keyphrase_words filter\n";
$GLOBALS['kp_filter'] = function ($lists) { $lists['fillers'][] = 'فلوتر'; $lists['cities'][] = 'ونس'; return $lists; };
check(strpos(K::from_title($title), 'فلوتر') === false, 'a word added to the fillers via the filter is dropped');
$GLOBALS['kp_filter'] = function ($lists) { return 'garbage'; };
check(K::from_title($title) === 'کفش مردانه ونس فلوتر', 'a malformed filter result falls back to the defaults');
$GLOBALS['kp_filter'] = null;

echo "9. Always-removed stop words and conditional modifiers (چرم، چرمی، روزمره، مجلسی)\n";
check(K::from_title('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر') === 'کفش مردانه ونس فلوتر', '2-word model: the core is already 4 words -> no چرم / روزمره added: «کفش مردانه ونس فلوتر»');
check(K::from_title('بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت چرم زنانه آلفردو', '1-word model: the 4th slot is filled with «چرم» right after the type: «بوت چرم زنانه آلفردو»');
check(K::count_words(K::from_title('بوت زنانه چرم طبیعی تبریز مدل آلفردو')) === 4 && K::count_words(K::from_title('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر')) === 4, 'both examples reach the 4-word ceiling');
check(K::optimal('بوت چرم زنانه آلفردو', 'x') === 'بوت چرم زنانه آلفردو', 'a stored «بوت چرم زنانه آلفردو» (core 3 + چرم) is a fixed point');
check(K::optimal('بوت زنانه آلفردو', 'x') === 'بوت زنانه آلفردو', 'without چرم in the source nothing is invented');
check(K::from_title('بوت زنانه طبیعی تبریز مدل آلفردو') === 'بوت زنانه آلفردو', 'no چرم in the title -> 3 words (nothing to add)');
check(K::from_title('کفش روزمره زنانه چرم طبیعی تبریز مدل آنا') === 'کفش چرم زنانه آنا', 'priority: چرم before روزمره when only one slot is free');
check(K::from_title('کیف مجلسی زنانه طبیعی تبریز مدل آیسا') === 'کیف مجلسی زنانه آیسا', 'مجلسی completes the phrase when there is no چرم and a free slot');
check(K::from_title('کفش زنانه چرم چرمی روزمره مجلسی مدل آنا') === 'کفش چرم چرمی زنانه آنا' || K::count_words(K::from_title('کفش زنانه چرم چرمی روزمره مجلسی مدل آنا')) === 4, 'never more than 4 words even with many modifiers');
check(K::optimal('کفش روزمره مردانه ونس', 'x') === 'کفش روزمره مردانه ونس', 'a stored 4-word phrase with روزمره keeps it (core 3 + 1 free slot)');
check(K::optimal('کفش روزمره مردانه ونس فلوتر', 'کفش روزمره مردانه ونس فلوتر') === 'کفش مردانه ونس فلوتر', 'a 5-word stored phrase is rebuilt from the title: the core fills 4 words, روزمره is dropped');
check(K::cap('بوت زنانه چرم طبیعی آلفردو') === 'بوت چرم زنانه آلفردو' && K::cap(K::cap('بوت زنانه چرم طبیعی آلفردو')) === K::cap('بوت زنانه چرم طبیعی آلفردو'), 'cap() applies the same rules and is idempotent');
check(K::optimal('چرم طبیعی', 'x') === 'چرم طبیعی', 'a phrase that would end up with < 2 words is kept as stored');
$bad = false; $w4 = true;
foreach (array('طبیعی', 'تبریز', 'مدل', 'طرح', 'اصل', 'دست‌دوز', 'کد') as $w) {
    foreach (array(K::from_title("کفش $w مردانه $w ونس فلوتر $w مدل ماهان"), K::optimal("کفش $w مردانه ونس", 'x'), K::cap("کفش $w زنانه آنا")) as $o) {
        if (in_array($w, K::words($o), true)) { $bad = true; echo "     leaked: $w => $o\n"; }
    }
}
check(!$bad, 'unconditional stop words (طبیعی، تبریز، مدل، طرح، اصل، دست‌دوز، کد) never survive');
mt_srand(5); $pool = array('کفش', 'بوت', 'کیف', 'مردانه', 'زنانه', 'چرم', 'چرمی', 'روزمره', 'مجلسی', 'طبیعی', 'تبریز', 'مدل', 'ونس', 'فلوتر', 'آلفردو', 'آیسا', 'اصل', 'طرح', 'کد');
$over = 0; $leak = 0;
for ($i = 0; $i < 500; $i++) {
    $ws = array(); for ($j = 0, $n = mt_rand(3, 11); $j < $n; $j++) $ws[] = $pool[mt_rand(0, 18)];
    $o = K::from_title(implode(' ', $ws)); $oc = K::cap(implode(' ', $ws));
    if (K::count_words($o) > 4 || K::count_words($oc) > 4) $over++;
    $uncond = array('طبیعی', 'تبریز', 'مدل', 'طرح', 'اصل', 'کد');
    $real = array_diff($ws, $uncond, array('چرم', 'چرمی', 'روزمره', 'مجلسی'));
    if (count(array_unique($real)) >= 2) foreach ($uncond as $x) { if (in_array($x, K::words($o), true)) $leak++; }
}
check($over === 0, '500 random titles: never more than 4 words');
check($leak === 0, '500 random titles: unconditional stop words never leak (' . $leak . ')');

echo "10. Semantic 4-word formula: what (type + usage) + for whom (gender) + model\n";
check(K::from_title('کفش طبی مردانه چرم طبیعی تبریز مدل مارکو') === 'کفش طبی مردانه مارکو', '«کفش طبی مردانه چرم طبیعی تبریز مدل مارکو» -> «کفش طبی مردانه مارکو»');
check(K::from_title('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر') === 'کفش مردانه ونس فلوتر', '«… مدل ونس فلوتر» -> «کفش مردانه ونس فلوتر» (2-word model: no modifier)');
check(K::from_title('بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت چرم زنانه آلفردو', '«بوت زنانه چرم … مدل آلفردو» -> «بوت چرم زنانه آلفردو» (no usage word: material fills the slot)');
check(K::from_title('کفش مجلسی زنانه چرم مدل لارا') === 'کفش مجلسی زنانه لارا', 'usage (مجلسی) beats material (چرم) for the 4th slot');
check(K::from_title('کفش اداری مردانه چرم طبیعی مدل آرمان') === 'کفش اداری مردانه آرمان' && K::from_title('صندل راحتی زنانه چرم مدل مهسا') === 'صندل راحتی زنانه مهسا' && K::from_title('کفش کلاسیک مردانه چرمی مدل آرتین') === 'کفش کلاسیک مردانه آرتین', 'اداری / راحتی / کلاسیک are usage words too');
$genderKept = true;
foreach (array('کفش طبی مردانه چرم طبیعی تبریز مدل مارکو', 'بوت زنانه چرم طبیعی تبریز مدل آلفردو', 'کفش مجلسی زنانه چرم مدل لارا', 'کیف چرم طبیعی زنانه مدل آیسا', 'کمربند چرم مردانه مدل کلاسیک رویال') as $t) {
    $o = K::from_title($t); $g = preg_match('/(مردانه|زنانه)/u', $t, $gm) ? $gm[1] : '';
    $genderKept = $genderKept && ($g === '' || in_array($g, K::words($o), true)) && K::count_words($o) <= 4;
}
check($genderKept, 'gender (مردانه / زنانه) is never dropped nor replaced by چرم');
foreach (array('فروش', 'خرید', 'قیمت') as $w) check(!in_array($w, K::words(K::from_title("خرید کفش $w طبی مردانه مدل مارکو قیمت")), true), "«{$w}» is an unconditional stop word");

echo "11. Stored / incomplete meta is not trusted blindly\n";
$title = 'کفش طبی مردانه چرم طبیعی تبریز مدل مارکو';
check(K::optimal('کفش چرم مارکو', $title) === 'کفش طبی مردانه مارکو', 'stored «کفش چرم مارکو» (gender lost) -> rebuilt from the title: «کفش طبی مردانه مارکو»');
check(K::optimal('کفش مردانه', $title) === 'کفش طبی مردانه مارکو', 'stored phrase without the model name -> rebuilt from the title');
check(K::optimal('کفش مردانه مارکو', $title) === 'کفش طبی مردانه مارکو', 'stored 3-word phrase while the title supports 4 words -> rebuilt from the title');
check(K::optimal('', $title) === 'کفش طبی مردانه مارکو', 'no stored meta at all -> built from the title');
check(K::optimal('کفش طبی مردانه مارکو', $title) === 'کفش طبی مردانه مارکو', 'a conforming stored phrase is kept');
check(K::optimal('بوت چرم زنانه آلفردو', 'x') === 'بوت چرم زنانه آلفردو', 'without a usable title the stored phrase is kept');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
