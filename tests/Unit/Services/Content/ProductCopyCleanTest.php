<?php
/** php tests/Unit/Services/Content/ProductCopyCleanTest.php */
define('ABSPATH', '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
if (!function_exists('esc_html')) { function esc_html($s) { return $s; } function wp_strip_all_tags($s) { return strip_tags($s); } }
use Khazan\Services\Content\HtmlSanitizer as H;

$passed = 0; $failed = 0;
function check($ok, $m) { global $passed, $failed; if ($ok) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$t = '<table><thead><tr><th>ویژگی</th><th>مشخصات</th></tr></thead><tbody><tr><td>نوع</td><td>بوت زنانه</td></tr><tr><td>رویه</td><td>چرم طبیعی</td></tr><tr><td>زیره</td><td>اعلام نشده</td></tr><tr><td>آستر</td><td>اعلام نشده</td></tr></tbody></table>';
$out = H::clean($t, 'product');
check(strpos($out, 'اعلام نشده') === false && strpos($out, 'چرم طبیعی') !== false, 'rows without a real value are removed, real rows stay');
check(H::clean('<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>نوع</td><td>بوت</td><td></td></tr><tr><td>زیره</td><td>اعلام نشده</td></tr></tbody></table><p>ok</p>', 'product') === '<p>ok</p>', 'fewer than 2 real rows: no table at all');
$p = H::clean('<p>رویه چرم است. جنس زیره اعلام نشده است. به مشخصات رسمی تکیه کنید.</p><h3>زیره؟</h3><p>در داده‌ها ثبت نشده است.</p><h3>ست؟</h3><p>با پالتو.</p>', 'product');
check($p === '<p>رویه چرم است.</p><h3>ست؟</h3><p>با پالتو.</p>', 'sentences announcing missing facts are cut; a question left without an answer goes');
check(strpos($out, 'style="') !== false && strpos($out, 'border:1px solid') !== false && substr_count($out, '<td style') === substr_count($out, '<td'), 'tables carry inline borders without theme CSS');
check(H::style_tables(H::style_tables($t)) === H::style_tables($t), 'styling is idempotent');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
