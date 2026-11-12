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
$q = H::clean('<p>رویه از چرم تبریز با پایه چرم گاوی است ، و سبک است. کلکسیون <a href="/c">بوت چرم زنانه</a></p><p>متن عادی با <a href="/x">لینک</a> وسط.</p><p><a href="/y">کفش روزمره</a></p><table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>', 'product');
check(strpos($q, 'با پایه') === false && strpos($q, 'چرم گاوی تبریز') !== false && strpos($q, ' ،') === false, 'machine-sounding material phrase and comma spacing are fixed');
check(strpos($q, 'سبک است.') !== false && strpos($q, 'می‌توانید به <a href="/c">بوت چرم زنانه</a> سر بزنید.') !== false, 'a link left alone after a sentence is wrapped into a sentence');
check(strpos($q, '<p>متن عادی با <a href="/x">لینک</a> وسط.</p>') !== false, 'inline links stay untouched');
check(strpos($q, 'به <a href="/y">کفش روزمره</a> سر بزنید.</p>') !== false, 'an anchor-only paragraph becomes a sentence');
$li = H::clean('<ul><li>زیره EVA → قدم‌ها سبک‌تر می‌شود.</li><li><strong>کفی طبی</strong> → پشتیبانی بهتر.</li><li>این مدل با ارسال رایگان، خرید ۴ قسطه و ضمانت بازگشت همراه است → خیال خریدار راحت‌تر می‌شود.</li><li>بدون پیکان.</li></ul>', 'product');
check(strpos($li, '→') === false && strpos($li, '<li><strong>زیره EVA:</strong> قدم‌ها سبک‌تر می‌شود.</li>') !== false && strpos($li, '<li><strong>کفی طبی:</strong> پشتیبانی بهتر.</li>') !== false, 'benefit arrows become a bold label with a colon');
check(strpos($li, 'همراه است؛ خیال خریدار راحت‌تر می‌شود.</li>') !== false && strpos($li, '<li>بدون پیکان.</li>') !== false, 'a sentence before the arrow is joined as text; other bullets stay');
check(H::clean('<p>رویه این بوت از چرم طبیعی و چرم تبریز است و جنس رویه چرم تبریز و چرم طبیعی.</p>', 'product') === '<p>رویه این بوت از چرم طبیعی تبریز است و جنس رویه چرم طبیعی تبریز.</p>', 'one leather is not written as two materials');
$f = H::clean('<h2>مزایا</h2><p>ارسال رایگان.</p><h2>سوالات متداول</h2><h3>نگهداری؟</h3><p>با دستمال نرم.</p><h3>مزیت خرید؟</h3><p>اقساط ۴ قسطه و ضمانت بازگشت.</p><h3>ست؟</h3><p>با پالتو.</p>', 'product');
check(strpos($f, 'اقساط') === false && strpos($f, 'مزیت خرید') === false && strpos($f, 'ارسال رایگان') !== false && strpos($f, 'نگهداری؟') !== false && strpos($f, 'ست؟') !== false, 'a FAQ answer repeating shop terms is dropped with its question; benefits and other FAQs stay');
$g = H::clean('<p>رویه از چرم تبریز با ماهیت گاوی است و چرم تبریز با جنس گاوی و چرم تبریز با کیفیت چرم گاوی.</p><table><tr><td>جنس رویه</td><td>چرم تبریز با ماهیت گاوی</td></tr><tr><td>زیره</td><td>EVA</td></tr></table>', 'product');
check(strpos($g, 'با ماهیت') === false && strpos($g, 'با جنس') === false && strpos($g, 'با کیفیت') === false && substr_count($g, 'چرم گاوی تبریز') === 4, 'every "چرم تبریز با … گاوی" variant becomes "چرم گاوی تبریز"');
$a = H::clean('<p>با تکیه بر <a href="/c">مجموعه بوت مردانه </a>، برای شما.</p><p>و <a href="/d"> دستکش</a> هم هست.</p>', 'product');
check(strpos($a, '</a>،') !== false && strpos($a, ' ،') === false && strpos($a, ' <a href="/d">دستکش</a> هم') !== false, 'spaces inside a link text move outside it');
$b = H::clean('<p>مقدمه.</p><ul><li><strong>خرید مطمئن:</strong> ارسال رایگان و خرید ۴ قسطه.</li></ul><p>متن خوب. همچنین ارسال رایگان است و اقساط هم دارد. جمله‌ی آخر.</p><p>فقط ارسال رایگان.</p>', 'product');
check(substr_count($b, 'ارسال رایگان') === 1 && strpos($b, 'متن خوب. جمله‌ی آخر.') !== false && strpos($b, 'فقط') === false, 'shop terms repeated in body paragraphs are cut; the benefits bullet stays');
$none = H::clean('<p>ارسال رایگان.</p>', 'product');
check($none === '<p>ارسال رایگان.</p>', 'with no benefits list the sentence is left alone');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
