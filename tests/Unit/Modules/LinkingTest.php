<?php
/**
 * Unit tests: orphan audit counting and the pure parts of link suggestions.
 *
 *   php tests/Unit/Modules/LinkingTest.php
 */
define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Linking\OrphanAudit as A;
use Khazan\Modules\Linking\LinkSuggester as L;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }
$host = 'akaleather.com';

check('hrefs from html', A::hrefs('<a href="/a">x</a><a class="b" href=\'https://akaleather.com/b/\'>y</a>') === array('/a', 'https://akaleather.com/b/'));
check('hrefs from escaped builder json', A::hrefs('{"editor":"<a href=\"https:\/\/akaleather.com\/c\/\">c<\/a>"}', true) === array('https://akaleather.com/c/'));
check('builder button url', A::hrefs('{"link":{"url":"https:\/\/akaleather.com\/d\/","is_external":""}}', true) === array('https://akaleather.com/d/'));
check('no links', A::hrefs('plain text') === array());

$map = array('/category/hats' => 'product_cat_5', '/blog/a' => 'blog_10', '/کلاه' => 'product_cat_6');
$links = array(
    array('blog_10', 'https://www.akaleather.com/category/hats/', 'content'),
    array('blog_10', '/blog/a', 'content'),                       // self link
    array(null, '/%DA%A9%D9%84%D8%A7%D9%87/', 'menu'),           // encoded persian slug from a menu
    array('blog_11', 'https://google.com/category/hats', 'content'), // other site
);
$in = A::count($map, $links, $host);
check('content link counted with its source', $in['product_cat_5']['content'] === 1 && $in['product_cat_5']['sources'] === array('blog_10'));
check('self link ignored', !isset($in['blog_10']));
check('menu link to encoded slug counted as menu', $in['product_cat_6']['menu'] === 1 && $in['product_cat_6']['content'] === 0);
check('statuses', A::status($in['product_cat_5']) === 'linked' && A::status($in['product_cat_6']) === 'menu_only' && A::status(null) === 'orphan');

check('anchor must be a target phrase in any spelling', L::is_target_phrase('كلاه چرم مردانه', array('کلاه چرم مردانه')) && !L::is_target_phrase('چیز دیگر', array('کلاه چرم مردانه')));
$html = '<p>متن <a href="https://akaleather.com/x/">کلاه چرم</a> و ادامه</p>';
check('undo removes only our link and keeps the text', L::unlink_html($html, 'https://akaleather.com/x/', 'کلاه چرم') === '<p>متن کلاه چرم و ادامه</p>');
check('undo leaves a changed link alone', L::unlink_html('<p><a href="https://akaleather.com/x/" class="z">کلاه چرم</a></p>', 'https://akaleather.com/x/', 'کلاه چرم') === null);
check('undo with special characters in the anchor', L::unlink_html('<a href="/u">$1 \\ تست</a>', '/u', '$1 \\ تست') === '$1 \\ تست');

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
