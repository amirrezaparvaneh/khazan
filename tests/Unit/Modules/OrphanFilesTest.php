<?php
/**
 * Unit tests: files outside the media library (pure rules + a real folder walk).
 *
 *   php tests/Unit/Modules/OrphanFilesTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Images\OrphanFiles as O;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

// names_from_meta: original, sizes, generated formats, original_image
$names = O::names_from_meta(array(
    'file' => '2026/09/a.webp',
    'original_image' => 'a-orig.webp',
    'sizes' => array('thumbnail' => array('file' => 'a-150x150.webp', 'sources' => array('image/avif' => array('file' => 'a-150x150.avif')))),
    'sources' => array('image/jpeg' => array('file' => 'a.jpg')),
), '2026/09/a.webp');
sort($names);
check('meta names include original', in_array('2026/09/a.webp', $names, true));
check('meta names include size', in_array('2026/09/a-150x150.webp', $names, true));
check('meta names include size source format', in_array('2026/09/a-150x150.avif', $names, true));
check('meta names include original_image and top-level sources', in_array('2026/09/a-orig.webp', $names, true) && in_array('2026/09/a.jpg', $names, true));
check('meta names are unique', count($names) === count(array_unique($names)));

// safe_rel
check('safe_rel accepts a normal path', O::safe_rel('2026/09/a.webp') === '2026/09/a.webp');
check('safe_rel strips a leading slash', O::safe_rel('/2026/a.webp') === '2026/a.webp');
check('safe_rel refuses traversal', O::safe_rel('../wp-config.php') === '' && O::safe_rel('2026/../../x.png') === '');
check('safe_rel refuses empty parts and NUL', O::safe_rel('2026//a.png') === '' && O::safe_rel("a\0.png") === '');
check('mime by extension', O::mime_for('x/a.WEBP') === 'image/webp' && O::mime_for('b.jpeg') === 'image/jpeg' && O::mime_for('c.txt') === '');

// classify
$twin = O::classify(array(array('rel' => 'a.webp', 'refs' => 0, 'mtime' => 5)), true);
check('unused file with a library twin is removable', $twin['a.webp'] === 'removable');
$used = O::classify(array(array('rel' => 'a.webp', 'refs' => 2, 'mtime' => 5)), true);
check('used file with a library twin is kept as used', $used['a.webp'] === 'used');
$single = O::classify(array(array('rel' => 'a.webp', 'refs' => 0, 'mtime' => 5)), false);
check('lone unused file is never auto removable', $single['a.webp'] === 'unused');
$lone_used = O::classify(array(array('rel' => 'a.webp', 'refs' => 1, 'mtime' => 5)), false);
check('lone used file is used', $lone_used['a.webp'] === 'used');
$grp = O::classify(array(
    array('rel' => 'x-1.webp', 'refs' => 0, 'mtime' => 20),
    array('rel' => 'x.webp', 'refs' => 0, 'mtime' => 10),
    array('rel' => 'x-2.webp', 'refs' => 3, 'mtime' => 30),
), false);
check('most referenced file is the keeper', $grp['x-2.webp'] === 'keep');
check('unreferenced twins of the keeper are removable', $grp['x.webp'] === 'removable' && $grp['x-1.webp'] === 'removable');
$grp2 = O::classify(array(
    array('rel' => 'y-1.webp', 'refs' => 0, 'mtime' => 20),
    array('rel' => 'y.webp', 'refs' => 0, 'mtime' => 10),
), false);
check('with no references the oldest is kept and the other removable', $grp2['y.webp'] === 'keep' && $grp2['y-1.webp'] === 'removable');
check('there is always exactly one keeper or library twin per removable', count(array_filter($grp2, function ($s) { return $s === 'keep'; })) === 1);

// walk (real folder)
$tmp = sys_get_temp_dir() . '/khz_orph_' . getmypid();
@mkdir($tmp . '/2026/09', 0777, true); @mkdir($tmp . '/khazan-quarantine', 0777, true); @mkdir($tmp . '/cache', 0777, true);
file_put_contents($tmp . '/2026/09/a.webp', str_repeat('a', 100));
file_put_contents($tmp . '/2026/09/b.png', str_repeat('b', 50));
file_put_contents($tmp . '/2026/09/doc.pdf', 'pdf');
file_put_contents($tmp . '/2026/09/c.khazan-tmp.webp', 'tmp');
file_put_contents($tmp . '/khazan-quarantine/q.webp', 'quarantined');
file_put_contents($tmp . '/cache/c.webp', 'cache');
$found = O::walk($tmp);
check('walk finds image files with relative paths', isset($found['2026/09/a.webp']) && isset($found['2026/09/b.png']) && $found['2026/09/a.webp']['size'] === 100);
check('walk ignores non-images and temp files', !isset($found['2026/09/doc.pdf']) && !isset($found['2026/09/c.khazan-tmp.webp']));
check('walk skips the quarantine and cache folders', !isset($found['khazan-quarantine/q.webp']) && !isset($found['cache/c.webp']));
check('walk respects the file cap', count(O::walk($tmp, 1)) === 1);

function rrmdir($d) { foreach (glob($d . '/{,.}*', GLOB_BRACE) as $f) { if (basename($f) === '.' || basename($f) === '..') continue; is_dir($f) ? rrmdir($f) : unlink($f); } rmdir($d); }
rrmdir($tmp);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
