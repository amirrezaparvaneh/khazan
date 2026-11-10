<?php
/**
 * Unit tests: pure parts of the image modules (settings, conversion paths, alt-text cleanup, duplicate keeper).
 *
 *   php tests/Unit/Modules/ImagesModuleTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $d; }
function wp_basename($p) { return basename($p); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Modules\Images\AltText;
use Khazan\Modules\Images\Deduplicator;
use Khazan\Modules\Images\ImageConverter;
use Khazan\Modules\Images\ImageSettings;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

// settings
$d = ImageSettings::sanitize(array());
check('defaults are safe: nothing automatic, originals kept', !$d['convert_on_upload'] && !$d['delete_original'] && !$d['alt_on_upload'] && !$d['alt_overwrite'] && $d['serve_next_gen']);
$s = ImageSettings::sanitize(array('format' => 'gif', 'quality' => 500, 'alt_model' => 'bad model!', 'delete_original' => '1', 'unknown' => 1));
check('invalid format falls back to webp', $s['format'] === 'webp');
check('quality is clamped', $s['quality'] === 100 && ImageSettings::sanitize(array('quality' => 1))['quality'] === 30);
check('invalid model id is dropped', $s['alt_model'] === '' && ImageSettings::sanitize(array('alt_model' => 'gpt-5.4-mini'))['alt_model'] === 'gpt-5.4-mini');
check('booleans parsed, unknown keys dropped', $s['delete_original'] === true && !isset($s['unknown']));

// conversion paths
check('webp target next to source', ImageConverter::target_path('/u/2026/a.jpg', 'webp', function () { return false; }) === '/u/2026/a.webp');
check('avif target', ImageConverter::target_path('/u/a.PNG', 'avif', function () { return false; }) === '/u/a.avif');
$taken = array('/u/a.webp' => 1, '/u/a-1.webp' => 1);
check('never overwrites an existing file', ImageConverter::target_path('/u/a.jpg', 'webp', function ($p) use ($taken) { return isset($taken[$p]); }) === '/u/a-2.webp');
check('mime for format', ImageConverter::mime_for('avif') === 'image/avif' && ImageConverter::mime_for('webp') === 'image/webp');

$tmp = sys_get_temp_dir() . '/khz-img-' . getmypid(); mkdir($tmp . '/2026', 0777, true); touch($tmp . '/2026/a.webp');
$base = 'https://s.test/wp-content/uploads';
check('swaps to the sibling that exists', ImageConverter::sibling_url($base . '/2026/a.jpg', $base, $tmp, 'webp') === $base . '/2026/a.webp');
check('keeps the url when no sibling exists', ImageConverter::sibling_url($base . '/2026/b.jpg', $base, $tmp, 'webp') === $base . '/2026/b.jpg');
check('foreign host untouched', ImageConverter::sibling_url('https://cdn.test/a.jpg', $base, $tmp, 'webp') === 'https://cdn.test/a.jpg');
check('path traversal untouched', ImageConverter::sibling_url($base . '/../a.jpg', $base, $tmp, 'webp') === $base . '/../a.jpg');
@unlink($tmp . '/2026/a.webp'); @rmdir($tmp . '/2026'); @rmdir($tmp);

// alt text
check('lead-in and trailing dot removed', AltText::clean('تصویر کیف چرم مشکی زنانه.') === 'کیف چرم مشکی زنانه');
check('quotes and Alt: prefix removed', AltText::clean("«Alt: بوت چرم قهوه‌ای»") === 'بوت چرم قهوه‌ای');
check('long text cut at a word boundary within 125', mb_strlen(AltText::clean(str_repeat('کیف چرم زنانه ', 20))) <= 125);
check('empty answer stays empty', AltText::clean('  .  ') === '');
$p = AltText::prompt(array('title' => 'بوت زنانه آرسا', 'category' => 'بوت چرم زنانه'));
check('prompt carries the product context and forbids guessing', strpos($p, 'بوت زنانه آرسا') !== false && strpos($p, 'حدس نزن') !== false);
check('prompt without context has no empty context line', strpos(AltText::prompt(array()), 'استفاده شده است') === false);

// duplicates
$n = Deduplicator::needles('/u/2026/a.jpg', array('file' => '2026/a.jpg', 'sizes' => array('thumbnail' => array('file' => 'a-150x150.jpg'), 'medium' => array('file' => 'a-300x200.jpg'))), 7);
check('needles contain the original and every size', $n['names'] === array('a.jpg', 'a-150x150.jpg', 'a-300x200.jpg') && $n['id'] === 7);
$members = array(array('id' => 3, 'refs' => 0, 'date' => '2026-01-01'), array('id' => 2, 'refs' => 4, 'date' => '2026-03-01'), array('id' => 1, 'refs' => 4, 'date' => '2026-02-01'));
check('keeper = most referenced, then oldest', Deduplicator::pick_keeper($members) === 1);
check('unreferenced group: oldest wins', Deduplicator::pick_keeper(array(array('id' => 9, 'refs' => 0, 'date' => '2026-05-01'), array('id' => 8, 'refs' => 0, 'date' => '2026-01-01'))) === 8);

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
