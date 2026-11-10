<?php
/**
 * Unit tests: in-place image recompression (decision rules, real GD round trip with a fake editor).
 *
 *   php tests/Unit/Modules/ImageShrinkerTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = '') { return $d; }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { private $c; function __construct($c = '', $m = '') { $this->c = $c; } function get_error_code() { return $this->c; } }

class FakeEditor {
    private $path; private $q = 82;
    function __construct($p) { $this->path = $p; }
    function set_quality($q) { $this->q = $q; }
    function save($to, $mime) {
        $im = imagecreatefromstring(file_get_contents($this->path));
        if ($mime === 'image/webp') imagewebp($im, $to, $this->q); else imagejpeg($im, $to, $this->q);
        return array('path' => $to);
    }
}
$GLOBALS['editor_mode'] = 'real';
function wp_get_image_editor($p) { return new FakeEditor($p); }

require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Images\ImageShrinker as S;
use Khazan\Modules\Images\ImageSettings;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

check('small files are not worth it', !S::worth_shrinking(100 * 1024, 300) && S::worth_shrinking(300 * 1024, 300));
check('accepts a clearly smaller file of the same size', S::acceptable(1000, 800, array(10, 10), array(10, 10)));
check('rejects a file that is not 10% smaller', !S::acceptable(1000, 950, array(10, 10), array(10, 10)));
check('rejects different dimensions', !S::acceptable(1000, 500, array(10, 10), array(10, 9)));
check('rejects an empty result', !S::acceptable(1000, 0, array(10, 10), array(10, 10)));

$d = ImageSettings::sanitize(array());
check('defaults: quality 80, 300 KB threshold', $d['shrink_quality'] === 80 && $d['shrink_min_kb'] === 300);
check('quality and threshold are clamped', ImageSettings::sanitize(array('shrink_quality' => 10))['shrink_quality'] === 50 && ImageSettings::sanitize(array('shrink_quality' => 100))['shrink_quality'] === 95 && ImageSettings::sanitize(array('shrink_min_kb' => 1))['shrink_min_kb'] === 50);

// animated webp detection
$tmp = sys_get_temp_dir() . '/khz-shrink-' . getmypid(); mkdir($tmp);
file_put_contents($tmp . '/anim.webp', 'RIFF' . pack('V', 100) . 'WEBPVP8X' . pack('V', 10) . chr(0x02) . str_repeat("\0", 20));
file_put_contents($tmp . '/still.webp', 'RIFF' . pack('V', 100) . 'WEBPVP8X' . pack('V', 10) . chr(0x00) . str_repeat("\0", 20));
check('animated WebP is detected', S::is_animated_webp($tmp . '/anim.webp') && !S::is_animated_webp($tmp . '/still.webp'));
check('animated WebP is never re-encoded', is_wp_error(S::shrink_file($tmp . '/anim.webp', 'image/webp', 80, 1)) && S::shrink_file($tmp . '/anim.webp', 'image/webp', 80, 1)->get_error_code() === 'animated');

// real round trip: a noisy 600x600 JPEG saved at quality 100 is much heavier than at 70
$im = imagecreatetruecolor(600, 600);
mt_srand(3);
for ($i = 0; $i < 4000; $i++) imagefilledrectangle($im, mt_rand(0, 580), mt_rand(0, 580), mt_rand(0, 600), mt_rand(0, 600), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
imagejpeg($im, $tmp . '/big.jpg', 100);
$before = filesize($tmp . '/big.jpg');
$r = S::shrink_file($tmp . '/big.jpg', 'image/jpeg', 70, 50);
clearstatcache();
$size = getimagesize($tmp . '/big.jpg');
check('real file shrinks and the saved bytes are exact', !is_wp_error($r) && $r === $before - filesize($tmp . '/big.jpg') && filesize($tmp . '/big.jpg') < $before * 0.9);
check('dimensions are unchanged and no temp file is left', $size[0] === 600 && $size[1] === 600 && !file_exists($tmp . '/big.khazan-tmp.jpg'));
$again = S::shrink_file($tmp . '/big.jpg', 'image/jpeg', 99, 1);
check('a second pass at higher quality never makes it bigger (original kept)', is_wp_error($again) && $again->get_error_code() === 'not_smaller');
check('below the threshold nothing happens', S::shrink_file($tmp . '/big.jpg', 'image/jpeg', 70, 100000)->get_error_code() === 'small_enough');
check('missing file is an error, not a crash', is_wp_error(S::shrink_file($tmp . '/nope.jpg', 'image/jpeg', 70, 50)));
check('unsupported type is refused', S::shrink_file($tmp . '/big.jpg', 'image/gif', 70, 50)->get_error_code() === 'unsupported_mime');

array_map('unlink', glob($tmp . '/*')); rmdir($tmp);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
