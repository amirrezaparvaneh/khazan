<?php
/**
 * Unit tests: quick product-attributes manager - AJAX save validation, repository save/get
 * round trip, injection into the product context object, and the mandatory PromptBuilder block
 * (including the "never guess" red-line rule when a product has no stored specs).
 *
 *   php tests/Unit/Repository/ProductSpecsRepositoryTest.php
 */

define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');

class WP_Error { public $c; public $m; public $d; function __construct($c = '', $m = '', $d = array()) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function absint($n) { return abs((int) $n); }
function sanitize_key($s) { return strtolower(preg_replace('~[^a-z0-9_\-]~i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function apply_filters($tag, $value) { return $value; }
function get_bloginfo($k) { return 'Site'; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_trim_words($s, $n, $more = '…') { return $s; }
function strip_shortcodes($s) { return $s; }
function wp_list_pluck($list, $field) { return array_map(function ($i) use ($field) { return is_object($i) ? $i->$field : $i[$field]; }, $list); }
function get_the_terms($id, $tax) { return false; }
function current_user_can($cap, $id = null) { return true; }
function post_type_exists($t) { return $t === 'product'; }
function taxonomy_exists($t) { return $t === 'product_cat'; }

$GLOBALS['options'] = array();
$GLOBALS['post_meta'] = array();
$GLOBALS['posts'] = array();
function get_option($k, $d = '') { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function get_post_meta($id, $key, $single = false) { return isset($GLOBALS['post_meta'][$id][$key]) ? $GLOBALS['post_meta'][$id][$key] : ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['post_meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['post_meta'][$id][$key]); return true; }
function get_post_type($id) { return isset($GLOBALS['posts'][$id]) ? $GLOBALS['posts'][$id]['post_type'] : false; }
function get_post($id) { return isset($GLOBALS['posts'][$id]) ? (object) $GLOBALS['posts'][$id] : null; }
function get_the_title($id) { $id = is_object($id) ? $id->ID : $id; return isset($GLOBALS['posts'][$id]) ? $GLOBALS['posts'][$id]['post_title'] : ''; }
function get_permalink($id) { return 'https://aka.test/product/' . (int) $id . '/'; }

require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');

use Khazan\Core\Settings;
use Khazan\Http\Requests\ProductSpecsSaveRequest;
use Khazan\Repository\ContentTargetRepository;
use Khazan\Repository\ProductSpecsRepository;
use Khazan\Services\Ai\PromptBuilder;

$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

echo "1. ProductSpecsSaveRequest: validation and sanitization of the AJAX payload\n";
$err = (new ProductSpecsSaveRequest(array('specs' => array('sole_material' => 'PU'))))->validated();
check(is_wp_error($err) && $err->get_error_code() === 'missing_product_id', 'missing product_id is rejected');

$result = (new ProductSpecsSaveRequest(array(
    'product_id' => '42',
    'specs' => array(
        'sole_material'  => '  PU استاندارد  ',
        'upper_material' => '<b>چرم طبیعی گاوی</b>',
        'not_a_real_key' => 'باید حذف شود',
    ),
)))->validated();
check(!is_wp_error($result) && $result['product_id'] === 42, 'product_id parsed as int');
check($result['specs']['sole_material'] === 'PU استاندارد', 'value trimmed/sanitized');
check($result['specs']['upper_material'] === 'چرم طبیعی گاوی', 'HTML tags stripped from the value');
check(!isset($result['specs']['not_a_real_key']), 'a key outside Settings::PRODUCT_SPEC_FIELDS is dropped');
check(!isset($result['specs']['lining_material']), 'a field the admin left untouched is not forced into the payload');

echo "2. ProductSpecsRepository: save() / get() round trip on post meta\n";
$GLOBALS['posts'][42] = array('ID' => 42, 'post_type' => 'product', 'post_title' => 'کفش طبی مردانه مارکو', 'post_content' => '', 'post_excerpt' => '');
$repo = new ProductSpecsRepository();
check($repo->get(42) === array(), 'no specs stored yet -> empty array');

$saved = $repo->save(42, array('sole_material' => ' PU استاندارد ', 'upper_material' => '', 'stitching_type' => 'دوخت دستی', 'bogus' => 'x'));
check(!is_wp_error($saved), 'save() succeeds for an existing product');
check($saved === array('sole_material' => 'PU استاندارد', 'stitching_type' => 'دوخت دستی'), 'only non-empty, declared fields are kept: ' . json_encode($saved, JSON_UNESCAPED_UNICODE));
check($repo->get(42) === $saved, 'get() reads back exactly what was saved');

$missing = $repo->save(999, array('sole_material' => 'PU'));
check(is_wp_error($missing) && $missing->get_error_code() === 'invalid_target', 'saving for a non-existent / non-product id fails');

$cleared = $repo->save(42, array());
check($cleared === array(), 'saving an empty set clears the stored specs');
check($repo->get(42) === array(), 'meta was actually deleted, not left as an empty array');
check(!array_key_exists(Settings::META_PRODUCT_CUSTOM_SPECS, $GLOBALS['post_meta'][42]), 'delete_post_meta really ran (no leftover key)');

echo "3. Injection into the product context object (ContentTargetRepository::get_context)\n";
$repo->save(42, array('sole_material' => 'PU استاندارد', 'upper_material' => 'چرم طبیعی گاوی'));
$targets = new ContentTargetRepository();
$context = $targets->get_context(Settings::KIND_PRODUCT, 42, false);
check(!is_wp_error($context), 'context resolved for product 42');
check($context['custom_specs'] === array('sole_material' => 'PU استاندارد', 'upper_material' => 'چرم طبیعی گاوی'), 'custom_specs carried into the AI context object: ' . json_encode($context['custom_specs'], JSON_UNESCAPED_UNICODE));

$GLOBALS['posts'][43] = array('ID' => 43, 'post_type' => 'product', 'post_title' => 'محصول بدون مشخصات', 'post_content' => '', 'post_excerpt' => '');
$empty_context = $targets->get_context(Settings::KIND_PRODUCT, 43, false);
check($empty_context['custom_specs'] === array(), 'a product with nothing saved gets an empty custom_specs, not a guess');

echo "4. PromptBuilder: the mandatory spec block and the red-line rule reach the actual prompt\n";
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function home_url($p = '') { return 'https://aka.test' . $p; }
function wp_unslash($s) { return $s; }

$pb = new PromptBuilder('');
$base_params = array('focus_keyword' => 'کفش طبی مردانه مارکو', 'word_count' => 900, 'internal_links' => array(), 'target' => array());

$row = array('name' => 'کفش طبی مردانه مارکو', 'seo_title' => 'عنوان ثابت', 'meta_description' => 'متای ثابت', 'related_keywords' => 'کفش طبی');
$params_no_specs = $base_params;
$prompt_no_specs = $pb->bulk_product($params_no_specs, $row);
check(strpos($prompt_no_specs, 'مشخصات قطعی و غیرقابل تغییر محصول') !== false, 'the mandatory spec header is always present (bulk_product)');
check(strpos($prompt_no_specs, 'هیچ مشخصات فنی ثبت‌شده‌ای') !== false, 'no stored specs -> explicit "nothing stored" notice');
check(strpos($prompt_no_specs, 'هرگز جنس زیره') === false && strpos($prompt_no_specs, 'حدس نزن') !== false, 'the red-line rule against guessing the sole/upper material is in the prompt');
check(strpos($prompt_no_specs, 'زیره استاندارد و باکیفیت') !== false, 'the generic fallback phrase is suggested instead of a guess');

$params_with_specs = $base_params;
$params_with_specs['target'] = array('custom_specs' => array('sole_material' => 'PU استاندارد', 'upper_material' => 'چرم طبیعی گاوی'));
$prompt_with_specs = $pb->bulk_product($params_with_specs, $row);
check(strpos($prompt_with_specs, '- جنس زیره: PU استاندارد') !== false, 'the exact admin-entered sole material line is injected verbatim');
check(strpos($prompt_with_specs, '- نوع چرم رویه: چرم طبیعی گاوی') !== false, 'the upper-material line is injected with its Persian label');
check(strpos($prompt_with_specs, 'هیچ مشخصات فنی ثبت‌شده‌ای') === false, 'the "nothing stored" notice disappears once specs exist');
check(strpos($prompt_with_specs, '- جنس آستر:') === false, 'a field the admin never filled in is not listed at all (no invented value)');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
