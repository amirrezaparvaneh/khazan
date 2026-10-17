<?php
/**
 * Unit tests: image brief parsing, injection position/markup, orchestration with fake transport/media.
 *
 *   php tests/Unit/Services/Image/ImageGenerationTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function apply_filters($t, $v) { return $v; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return (string) $s; }
function absint($n) { return abs((int) $n); }
$GLOBALS['opt'] = array();
function get_option($k, $d = '') { return isset($GLOBALS['opt'][$k]) ? $GLOBALS['opt'][$k] : $d; }
function avalai_key() { return 'k'; }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Image\ImageBrief;
use Khazan\Services\Image\ContentImageInjector as Inj;
use Khazan\Services\Image\ImageGenerationService;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

class FakeHttp {
    public $img;
    function __construct($img) { $this->img = $img; }
    function chat() { return '{"prompt":"Close-up of women\'s brown leather boots","slug":"Women\'s Leather Boots / Alfredo!","alt":"نمای نزدیک بوت چرم زنانه آلفردو","caption":"بوت چرم آلفردو"}'; }
    function generate_image() { return $this->img; }
}
class FakeMedia extends Khazan\Repository\MediaRepository {
    public $meta;
    function store(array $s, array $m, $p = 0) { $this->meta = $m; return array('id' => 77, 'url' => 'https://aka.test/wp-content/uploads/' . $m['slug'] . '.jpg'); }
}

$params = array('focus_keyword' => 'بوت چرم زنانه', 'h1_title' => 'بوت چرم زنانه آلفردو', 'content_kind' => 'product', 'target_id' => 5);
$html = '<h2>الف</h2><p>یک</p><h2>ب</h2><p>دو</p><h2>ج</h2><p>سه</p><h2>د</h2><p>چهار</p>';

echo "brief\n";
$b = ImageBrief::parse('noise {"prompt":"Boots","slug":"Women\'s Leather Boots / Alfredo!","alt":"بوت قهوه‌ای","caption":""} tail', $params);
check($b['slug'] === 'womens-leather-boots-alfredo', 'slug cleaned to ascii words: ' . $b['slug']);
check(mb_strpos($b['alt'], 'بوت چرم زنانه') !== false, 'alt gets the focus keyword when missing');
check(strpos($b['prompt'], 'no watermark') === false && stripos($b['prompt'], 'No watermark') !== false && strpos($b['prompt'], 'soft diffused studio lighting') !== false, 'studio standard appended to prompt');
check($b['title'] === 'بوت چرم زنانه' && $b['caption'] !== '', 'title = focus keyword, caption fallback');
$f = ImageBrief::parse('not json', $params);
check($f['slug'] === 'leather-product' && $f['prompt'] !== '', 'garbage reply falls back safely');

echo "injector\n";
check(Inj::insert_offset($html) === strpos($html, '<h2>ج'), 'inserted before the 3rd H2');
check(Inj::insert_offset('<h2>a</h2><p>x</p><h2>b</h2><p>y</p>') === strpos('<h2>a</h2><p>x</p><h2>b</h2><p>y</p>', '<h2>b'), 'two H2s: before the 2nd');
check(Inj::insert_offset('<p>a</p><p>b</p><p>c</p>') > 0 && Inj::insert_offset('<p>a</p>') === -1, 'paragraph fallback / no position');
$out = Inj::inject($html, array('url' => 'https://x/y.jpg', 'alt' => 'ا"ب', 'id' => 9, 'caption' => 'توضیح', 'slug' => 's'));
check(strpos($out, '<figure class="wp-block-image size-large aka-content-img">') !== false && strpos($out, 'class="wp-image-9" loading="lazy"') !== false && strpos($out, '<figcaption>توضیح</figcaption>') !== false, 'standard figure markup');
check(strpos($out, 'alt="ا&quot;ب"') !== false, 'alt escaped');
check(strpos($out, '</p>' . "\n" . '<figure') !== false && strpos($out, "</figure>\n<h2>ج") !== false, 'sits between section 2 and 3');
check(preg_replace('~\s*<figure.*?</figure>\s*~s', '', $out) === $html, 'original content untouched');
check(Inj::inject($out, array('url' => 'u', 'alt' => 'a', 'id' => 1, 'caption' => 'c')) === $out, 'idempotent');

echo "service\n";
$svc = new ImageGenerationService(new FakeHttp(array('url' => 'https://img/1.png')), $media = new FakeMedia());
$r = $svc->embed($params, array('content' => $html));
check($r['image']['status'] === 'skipped' && $r['content'] === $html, 'disabled by default: nothing happens');
$GLOBALS['opt']['wila_auto_image_enabled'] = '1';
$r = $svc->embed($params, array('content' => $html));
check($r['image']['status'] === 'added' && strpos($r['content'], 'wp-image-77') !== false, 'enabled: image added');
check($media->meta['slug'] === 'womens-leather-boots-alfredo' && $media->meta['title'] === 'بوت چرم زنانه', 'file name slug + title passed to media');
$bad = new ImageGenerationService(new FakeHttp(new WP_Error('e', 'boom')), new FakeMedia());
$r = $bad->embed($params, array('content' => $html));
check($r['image']['status'] === 'failed' && $r['content'] === $html, 'API failure leaves content untouched');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
