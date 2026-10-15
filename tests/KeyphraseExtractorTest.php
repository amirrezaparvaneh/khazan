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
check(K::optimal('کفش مردانه ونس', $title) === 'کفش مردانه ونس', 'a stored 3-word keyphrase is used as it is');
check(K::optimal('  کفش   مردانه  ونس فلوتر ', $title) === 'کفش مردانه ونس فلوتر', 'a stored 4-word keyphrase is used (whitespace normalized)');
check(K::optimal('کفش مردانه چرم طبیعی ونس فلوتر', $title) === 'کفش مردانه ونس فلوتر', 'a stored 5-word keyphrase is NOT used; it is derived from the title instead');
check(K::optimal('', $title) === 'کفش مردانه ونس فلوتر', 'no stored keyphrase -> derived from the title');
check(K::optimal('کفش', 'x') === 'کفش', 'a stored single word is valid');
check(K::is_valid('الف ب ج د') && !K::is_valid('الف ب ج د ه') && !K::is_valid(''), 'is_valid: 1-4 words only');

echo "5. Short titles are kept; fillers, materials and cities are dropped\n";
check(K::from_title('کفش مردانه ونس') === 'کفش مردانه ونس', 'a 3-word title is returned unchanged');
check(K::from_title('کفش مردانه ونس فلوتر') === 'کفش مردانه ونس فلوتر', 'a clean 4-word title is not touched (already within the limit)');
check(K::from_title('کفش چرم طبیعی مردانه') === 'کفش مردانه', 'a 4-word title still loses «چرم» and «طبیعی» (stop words)');
$d = K::from_title('کیف چرم طبیعی اصل تبریز دست‌دوز لوکس شیک');
check(wc($d) >= 2 && wc($d) <= 4 && strpos($d, 'تبریز') === false && strpos($d, 'طبیعی') === false && strpos($d, 'اصل') === false, 'generic words (طبیعی، اصل، تبریز، لوکس…) never survive: «' . $d . '»');
check(strpos(K::from_title('کفش اسپرت مردانه چرم اصل تبریز مدل ونس اولد اسکول'), 'مدل') === false, 'the «مدل» marker itself is never part of the phrase');
check(K::from_title('کفش اسپرت مردانه تبریز چرم اصل ونس اولد اسکول') === 'کفش مردانه اولد اسکول', 'without «مدل»: product type + gender + the last distinctive words');
check(K::from_title('کیف دوشی چرم طبیعی زنانه مدل آیسا مجلسی') === 'کیف زنانه آیسا', 'gender qualifier is preserved next to the product type; «مجلسی» is a stop word');
check(K::from_title('کیف دوشی چرم طبیعی زنانه مدل آیسا مجلسی', 'کیف دوشی زنانه') === 'کیف دوشی زنانه آیسا', 'when the category words are given they form the base (کیف دوشی زنانه) and the model name is kept');

echo "6. Robustness\n";
check(K::from_title('') === '' && K::from_title('   ') === '' && K::limit(null) === '', 'empty input -> empty string');
check(K::from_title('<b>کفش</b> «مردانه»، چرم طبیعی (اصل) تبریز - مدل: ونس!') === 'کفش مردانه ونس', 'HTML tags and punctuation are stripped: «' . K::from_title('<b>کفش</b> «مردانه»، چرم طبیعی (اصل) تبریز - مدل: ونس!') . '»');
check(K::from_title("نیم\u{200c}بوت زنانه چرم طبیعی تبریز مدل ماهان") === "نیم\u{200c}بوت زنانه ماهان", 'ZWNJ inside a word is kept and the word counts once');
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
    $distinct = count(array_unique(array_diff($ws, array_keys($fill))));
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

echo "9. Stop words are removed from stored keyphrases and from short titles too\n";
check(K::from_title('بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت زنانه آلفردو', '«بوت زنانه چرم طبیعی تبریز مدل آلفردو» -> «بوت زنانه آلفردو»');
check(K::optimal('بوت چرم زنانه آلفردو', 'بوت زنانه چرم طبیعی تبریز مدل آلفردو') === 'بوت زنانه آلفردو', 'a stored «بوت چرم زنانه آلفردو» is cleaned to «بوت زنانه آلفردو»');
check(K::optimal('کفش روزمره مردانه ونس', 'x') === 'کفش مردانه ونس', 'روزمره is removed from a stored phrase');
check(K::optimal('کیف مجلسی زنانه آیسا', 'x') === 'کیف زنانه آیسا' && K::optimal('کیف طرح زنانه آیسا', 'x') === 'کیف زنانه آیسا', 'مجلسی and طرح are removed');
check(K::optimal('چرم طبیعی', 'x') === 'چرم طبیعی', 'a phrase that would end up with < 2 words is kept as stored');
check(K::cap('بوت چرم زنانه آلفردو') === 'بوت زنانه آلفردو' && K::cap(K::cap('بوت چرم زنانه آلفردو')) === K::cap('بوت چرم زنانه آلفردو'), 'cap() is idempotent and strips stop words');
$bad = false; foreach (array('چرم', 'چرمی', 'طبیعی', 'تبریز', 'مدل', 'روزمره', 'مجلسی', 'طرح', 'اصل', 'دست‌دوز') as $w) {
    $o = K::from_title("کفش $w مردانه $w ونس فلوتر $w مدل ماهان"); $o2 = K::optimal("کفش $w مردانه ونس", 'x');
    if (in_array($w, K::words($o), true) || in_array($w, K::words($o2), true)) { $bad = true; echo "     leaked: $w => $o / $o2\n"; }
}
check(!$bad, 'none of چرم، چرمی، طبیعی، تبریز، مدل، روزمره، مجلسی، طرح، اصل، دست‌دوز survives');
$sh = K::from_title('کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر');
check($sh === 'کفش مردانه ونس فلوتر' && K::count_words($sh) <= 4, 'the formula example: «کفش روزمره مردانه چرم طبیعی تبریز مدل ونس فلوتر» -> «کفش مردانه ونس فلوتر»');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
