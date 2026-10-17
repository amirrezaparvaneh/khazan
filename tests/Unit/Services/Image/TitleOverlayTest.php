<?php
/**
 * Unit tests: Persian shaping/ordering for GD, 2-line title fitting, and the no-GD / no-font fallback.
 *
 *   php tests/Unit/Services/Image/TitleOverlayTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($t, $v) { return $v; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Image\PersianShaper as S;
use Khazan\Services\Image\TitleLayout as L;
use Khazan\Services\Image\TitleOverlay;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
function cps($s) { return array_map(function ($c) { return strtoupper(dechex(mb_ord($c, 'UTF-8'))); }, preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY)); }

echo "shaper\n";
check(cps(S::visual('سلام')) === array('FEE1', 'FEFC', 'FEB3'), 'سلام: initial sin + final lam-alef ligature + isolated mim, visual (reversed) order');
check(cps(S::visual('لا')) === array('FEFB'), 'lam-alef ligature');
check(cps(S::visual('پ')) === array('FB56'), 'isolated pe (Persian letter forms)');
check(cps(S::visual('چرم')) === array('FEE1', 'FEAE', 'FB7C'), 'چرم: initial che, final re, isolated mim (re does not join forward)');
check(cps(S::visual('بی')) === array('FBFD', 'FE91'), 'farsi yeh final after beh initial');
check(!in_array('200C', cps(S::visual('می‌شود'))) && cps(S::visual('می‌شود'))[count(cps(S::visual('می‌شود'))) - 1] === 'FEE3', 'ZWNJ removed and breaks joining (می|شود)');
$mixed = S::visual('مدل 2024 چرم');
check(strpos($mixed, '2024') !== false, 'digit run keeps its order');
check(cps(S::visual('(الف)')) [0] === '28', 'parentheses mirrored');
check(S::visual('') === '', 'empty text');

echo "layout\n";
$measure = function ($line, $size) { return mb_strlen($line) * $size * 0.5; };
$short = L::fit('راهنمای خرید کفش', 1000, array(54, 50, 46), $measure);
check($short['size'] === 54 && count($short['lines']) === 1, 'short title: one line at the largest size');
$long = L::fit(str_repeat('راهنمای کامل انتخاب کفش چرم ', 3), 1000, array(54, 50, 46, 42, 38, 34), $measure);
check(count($long['lines']) <= 2, 'long title wraps to at most 2 lines (size ' . $long['size'] . ')');
foreach ($long['lines'] as $line) check($measure($line, $long['size']) <= 1000, 'line fits the frame: ' . mb_strlen($line) . ' chars');
$huge = L::fit(str_repeat('کلمه ', 80), 1000, array(40, 36), $measure);
check(count($huge['lines']) === 2 && mb_substr(end($huge['lines']), -1) === '…' && $measure(end($huge['lines']), 36) <= 1000, 'over-long title truncated with an ellipsis inside the frame');
check(L::fit('', 500, array(40), $measure)['lines'] === array(''), 'empty title does not crash');

echo "fallback\n";
$tmp = sys_get_temp_dir() . '/khazan-overlay-test.bin'; file_put_contents($tmp, 'not an image');
$ov = new TitleOverlay();
check($ov->apply($tmp, '') === null, 'empty title: raw image kept');
check($ov->apply($tmp, 'عنوان') === null, 'garbage image / missing GD: raw image kept, no error');
check($ov->apply('/nonexistent.png', 'عنوان') === null, 'unreadable file: raw image kept');
echo '  info: GD ' . (function_exists('imagecreatefromstring') ? 'available' : 'NOT available in this PHP') . ', font ' . (is_readable(TitleOverlay::font_path()) ? 'present' : 'missing') . "\n";
@unlink($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
