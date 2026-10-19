<?php
/**
 * Unit tests: one-click SEO scan and AI meta-patch validation for the repair metabox.
 *
 *   php tests/Unit/Services/Seo/SeoRepairTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function apply_filters($t, $v) { return $v; }
function get_option($k, $d = '') { return $k === 'wila_brand_name' ? 'آکا چرم' : $d; }
function get_bloginfo($k) { return 'آکا چرم'; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Services\Seo\SeoIssueScanner as S;
use Khazan\Services\Ai\ContentParser as P;
use Khazan\Services\Ai\PromptBuilder;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$meta = array('title' => 'راهنمای خرید | کاپشن چرم', 'desc' => '', 'focuskw' => 'کاپشن چرم مردانه');
$issues = S::scan(array(str_repeat('کلمه ', 130)), $meta);
$joined = implode("\n", $issues);
check(strpos($joined, 'ابتدای عنوان سئو') !== false, 'detects focus keyphrase missing from SEO title');
check(strpos($joined, 'توضیحات متا خالی') !== false, 'detects empty meta description');
check(strpos($joined, 'بسیار طولانی') !== false, 'detects long paragraph');
check(strpos($joined, 'در متن اصلی استفاده نشده') !== false, 'detects focus keyphrase absent from body');

$ok = S::scan(array('این متن کوتاه درباره کاپشن چرم مردانه است.'), array('title' => 'کاپشن چرم مردانه | راهنما', 'desc' => str_repeat('الف', 140) . ' کاپشن چرم مردانه', 'focuskw' => 'کاپشن چرم مردانه'));
check(!array_filter($ok, function ($i) { return strpos($i, 'ابتدای عنوان') !== false; }), 'no title issue when exact keyphrase leads title');

$parsed = array('meta_patches' => array(
    array('field' => 'title', 'after' => 'راهنمای کاپشن چرم مردانه', 'reason' => 'کلیدواژه'),
    array('field' => 'bogus', 'after' => 'x'),
    array('field' => 'desc', 'after' => '   ', 'reason' => 'empty'),
));
$patches = P::repair_meta_patches($parsed, $meta, 'post');
check(count($patches) === 1 && $patches[0]['field'] === 'title', 'only valid title patch survives');
check(mb_strpos($patches[0]['after'], 'کاپشن چرم مردانه') === 0, 'title patch is run through guard: exact focus keyphrase first');
check(mb_strlen($patches[0]['after']) <= 60, 'title patch within 60 chars');
check(P::repair_meta_patches(array(), $meta) === array(), 'missing meta_patches yields empty list');

$prompt = (new PromptBuilder(''))->seo_repairs(array('سلام دنیا'), '', $meta, $issues);
check(strpos($prompt, 'meta_patches') !== false && strpos($prompt, 'issues_found') !== false, 'prompt asks for meta patches and includes scan issues');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
