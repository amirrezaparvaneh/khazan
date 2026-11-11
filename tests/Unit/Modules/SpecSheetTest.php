<?php
/** Run: php tests/Unit/Modules/SpecSheetTest.php */
define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', __DIR__ . '/../../../');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Products\SpecSheet as S;
use Khazan\Modules\Products\SpecSheetService as SV;
$passed = $failed = 0;
function check($n, $c) { global $passed, $failed; if ($c) $passed++; else { $failed++; echo "FAIL: $n\n"; } }

$csv = "\xEF\xBB\xBFنام محصول;جنس زیره;رویه;آستر\n\"کفش افرا, تبریز\";پی‌یو;\"چرم \"\"طبیعی\"\" گاوی\";پارچه\n\nکیف رز;;چرم گوسفندی;\n";
$rows = S::parse_csv($csv);
check('semicolon csv with bom, quotes and blank line', count($rows) === 3 && $rows[0][0] === 'نام محصول' && $rows[1][0] === 'کفش افرا, تبریز' && $rows[1][2] === 'چرم "طبیعی" گاوی' && $rows[2][1] === '');
check('comma and tab delimiters', S::parse_csv("a,b\n1,2")[1] === array('1', '2') && S::parse_csv("a\tb\n1\t2")[1] === array('1', '2'));

$m = S::guess_mapping($rows[0]);
check('guesses key and fields from the header', $m['key'] === 0 && $m['fields']['sole_material'] === 1 && $m['fields']['upper_material'] === 2 && $m['fields']['lining_material'] === 3 && $m['fields']['stitching_type'] === null);
$m2 = S::guess_mapping(array('SKU', 'ویژگی های دیگر', 'نوع دوخت'));
check('sku key, other features and stitching', $m2['key'] === 0 && $m2['fields']['other_features'] === 1 && $m2['fields']['stitching_type'] === 2);

$products = array(
    array('id' => 1, 'title' => 'کفش مردانه افرا تبریز مدل کلاسیک', 'sku' => 'AF-1', 'slug' => 'afra-classic'),
    array('id' => 2, 'title' => 'کفش مردانه افرا تبریز مدل اسپرت', 'sku' => 'AF-2', 'slug' => 'afra-sport'),
    array('id' => 3, 'title' => 'کیف رز', 'sku' => '', 'slug' => 'rose-bag'),
    array('id' => 4, 'title' => 'کیف رز دستی بزرگ', 'sku' => '', 'slug' => 'rose-big'),
);
check('sku match', S::match_key('af-2', $products)['id'] === 2);
check('url slug match', S::match_key('https://x.com/product/afra-classic/', $products)['id'] === 1);
check('exact title match beats partial', S::match_key('کیف رز', $products)['id'] === 3);
check('persian variants are equal', S::match_key('كيف رز', $products)['id'] === 3);
$amb = S::match_key('کفش افرا', $products);
check('partial name shared by two products is ambiguous', $amb['state'] === 'ambiguous');
check('partial unique name matches the closest product', S::match_key('رز دستی', $products)['id'] === 4);
check('single word does not match loosely', S::match_key('کفش', $products)['state'] === 'none');
check('unknown product is none', S::match_key('کیف ناشناس', $products)['state'] === 'none');

$imp = S::import(array_slice($rows, 1), $m, $products);
check('import matches by title and skips unknown', count($imp['matched']) === 1 && isset($imp['matched'][3]) && $imp['matched'][3]['specs'] === array('upper_material' => 'چرم گوسفندی'));
check('import reports unmatched', count($imp['unmatched']) === 1 && $imp['unmatched'][0] === 'کفش افرا, تبریز');

$pool = array(
    array('id' => 10, 'title' => 'کفش مردانه افرا تبریز مدل کلاسیک', 'cats' => array(5), 'specs' => array('sole_material' => 'پی‌یو', 'upper_material' => 'چرم گاوی')),
    array('id' => 11, 'title' => 'کفش مردانه افرا تبریز مدل رسمی', 'cats' => array(5), 'specs' => array('sole_material' => 'پی‌یو', 'upper_material' => 'چرم گاوی')),
    array('id' => 12, 'title' => 'کفش مردانه افرا مدل تابستانی', 'cats' => array(5), 'specs' => array('sole_material' => 'لاستیک', 'upper_material' => 'چرم گاوی')),
    array('id' => 13, 'title' => 'کیف رز', 'cats' => array(9), 'specs' => array('sole_material' => 'نامربوط')),
);
$new = array('id' => 20, 'title' => 'کفش مردانه افرا تبریز مدل جدید', 'cats' => array(5));
$inf = S::infer($new, $pool, array('sole_material', 'upper_material', 'lining_material'));
check('borrows the majority value from same-category look-alikes', $inf['specs']['sole_material'] === 'پی‌یو' && $inf['specs']['upper_material'] === 'چرم گاوی');
check('never invents a feature no similar product has', !isset($inf['specs']['lining_material']));
check('other categories are never used', !in_array(13, $inf['from'], true) && $inf['from'] !== array());
$none = S::infer(array('id' => 21, 'title' => 'کلاه نمدی', 'cats' => array(7)), $pool, array('sole_material'));
check('no similar product means nothing is invented', $none['specs'] === array());
$weak = S::infer(array('id' => 22, 'title' => 'محصول کاملا متفاوت', 'cats' => array(5)), array($pool[0]), array('sole_material'));
check('a single weak look-alike is not enough', $weak['specs'] === array());
check('sheet link gives id and tab', SV::parse_link('https://docs.google.com/spreadsheets/d/1AbC_d-E/edit#gid=123') === array('id' => '1AbC_d-E', 'gid' => '123'));
check('sheet link without tab', SV::parse_link('https://docs.google.com/spreadsheets/d/XYZ/edit?usp=sharing') === array('id' => 'XYZ', 'gid' => null));
check('not a sheet link', SV::parse_link('https://example.com/a') === null && SV::parse_link('') === null);
$messy = array(array('لیست محصولات'), array(), array('ردیف', 'نام محصول', 'نامک', 'جنس زیره', 'رویه'), array('1', 'کفش', 'kafsh', 'پی‌یو', 'چرم'));
check('header is the fullest of the top rows, not the first', S::header_index($messy) === 2);
check('header is the first row when it is the fullest', S::header_index(array(array('a', 'b'), array('1')) ) === 0);
$mh = S::guess_mapping($messy[2]);
check('mapping of a real header with slug column picks the name column as key', $mh['key'] === 1 && $mh['fields']['sole_material'] === 3 && $mh['fields']['upper_material'] === 4);
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
