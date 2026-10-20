<?php
/**
 * Unit tests for PersianTextNormalizer: anchor/strong tag-boundary spacing, ZWNJ/NBSP cleanup,
 * and that it runs automatically through ContentGuard::wrap (every content pipeline).
 *
 *   php tests/Unit/Services/Content/PersianTextNormalizerTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Content\Guard\PersianTextNormalizer;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$ZWNJ = "\xE2\x80\x8C";
$ZWSP = "\xE2\x80\x8B";
$NBSP = "\xC2\xA0";

echo "1. The reported case: anchor tags glued to the surrounding word get a standard space\n";
$in = 'خرید<a href="https://aka.test/p/">کفش</a>طبی';
$out = PersianTextNormalizer::normalize($in);
check($out === 'خرید <a href="https://aka.test/p/">کفش</a> طبی', "«{$in}» -> «{$out}»");

echo "2. strong tags get the same treatment\n";
$in = 'این محصول<strong>چرم طبیعی</strong>است';
$out = PersianTextNormalizer::normalize($in);
check($out === 'این محصول <strong>چرم طبیعی</strong> است', "«{$in}» -> «{$out}»");

echo "3. Already-spaced boundaries are left alone (idempotent)\n";
$ok = 'خرید <a href="https://aka.test/p/">کفش</a> طبی';
check(PersianTextNormalizer::normalize($ok) === $ok, 'no double space is introduced');
check(PersianTextNormalizer::normalize(PersianTextNormalizer::normalize($in)) === PersianTextNormalizer::normalize($in), 'running twice gives the same result');

echo "4. Punctuation right after a closing tag is not pushed away\n";
$in = 'مدل <a href="https://aka.test/p/">کفش طبی</a>. قیمت مناسب';
check(PersianTextNormalizer::normalize($in) === $in, 'a period right after </a> keeps its place: ' . PersianTextNormalizer::normalize($in));

echo "5. Broken ZWNJ (half-space) next to a real space collapses to one standard space\n";
check(PersianTextNormalizer::normalize("کفش{$ZWNJ} طبی") === 'کفش طبی', 'ZWNJ immediately followed by a space becomes one space');
check(PersianTextNormalizer::normalize("کفش {$ZWNJ}طبی") === 'کفش طبی', 'a space immediately followed by ZWNJ becomes one space');
check(PersianTextNormalizer::normalize("می{$ZWNJ}شود") === "می{$ZWNJ}شود", 'a legitimate half-space inside a compound word (می‌شود) is never touched');

echo "6. Zero-width space is stripped, non-breaking space becomes a normal space, repeated spaces collapse\n";
check(PersianTextNormalizer::normalize("کفش{$ZWSP}طبی") === 'کفشطبی', 'zero-width space removed (no visual gap to begin with)');
check(PersianTextNormalizer::normalize("کفش{$NBSP}طبی") === 'کفش طبی', 'non-breaking space becomes a standard space');
check(PersianTextNormalizer::normalize('کفش   طبی') === 'کفش طبی', 'repeated spaces inside text collapse to one');

echo "7. Attribute values and tag markup are never touched by the text cleanup\n";
$in = '<a href="https://aka.test/p?x=1&y=2" class="a  b" data-x="1   2">متن</a>';
check(PersianTextNormalizer::normalize($in) === $in, 'spacing inside tag attributes is untouched: ' . PersianTextNormalizer::normalize($in));

echo "8. Full realistic paragraph: several glued anchors and half-space artifacts fixed at once\n";
$in = "برای<a href=\"https://aka.test/c/\">کفش طبی مردانه</a>بهترین انتخاب <a href=\"https://aka.test/p/\">این مدل</a>است و با{$ZWNJ} کیفیت بالا دوخته شده.";
$out = PersianTextNormalizer::normalize($in);
check(strpos($out, '>کفش طبی مردانه</a> بهترین') !== false, 'closing tag separated from the following word: ' . $out);
check(strpos($out, 'برای <a href') !== false, 'opening tag separated from the preceding word');
check(strpos($out, '>این مدل</a> است') !== false, 'second anchor boundary fixed too');
check(strpos($out, 'با کیفیت') !== false, 'broken half-space collapsed to a normal space');

echo "9. ContentGuard::wrap runs the normalizer automatically before producing the final (saved) HTML\n";
$glued = 'خرید<a href="https://aka.test/p/">کفش</a>طبی';
$wrapped = ContentGuard::wrap($glued);
check(strpos($wrapped, '<div class="aka-desc-wrapper">') === 0, 'result is wrapped as usual');
check(strpos($wrapped, 'خرید <a href="https://aka.test/p/">کفش</a> طبی') !== false, 'the glued words were fixed inside the wrapper: ' . $wrapped);
check(ContentGuard::wrap($wrapped) === $wrapped, 'wrap stays idempotent after normalization');
check(ContentGuard::normalize_persian_text($glued) === PersianTextNormalizer::normalize($glued), 'ContentGuard exposes the normalizer directly for callers/tests');

echo "10. Empty / plain text input is left alone\n";
check(PersianTextNormalizer::normalize('') === '', 'empty string stays empty');
check(PersianTextNormalizer::normalize('متن ساده بدون تگ') === 'متن ساده بدون تگ', 'plain text without tags is unaffected');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
