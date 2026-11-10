<?php
/**
 * Unit tests: finding a natural link spot in existing HTML and inserting it without touching anything else.
 *
 *   php tests/Unit/Services/Links/HtmlLinkTextTest.php
 */
define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Links\HtmlLinkText as H;
use Khazan\Services\Links\AnchorPhrases as P;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }
$host = 'akaleather.com';
$opts = array('target_key' => '/category/men-leather-hats', 'home_host' => $host);

// url keys
check('absolute url', H::url_key('https://www.akaleather.com/category/men-leather-hats/', $host) === '/category/men-leather-hats');
check('relative + query + fragment', H::url_key('/category/men-leather-hats/?a=1#x', $host) === '/category/men-leather-hats');
check('encoded persian slug equals decoded', H::url_key('https://akaleather.com/%DA%A9%D9%84%D8%A7%D9%87/', $host) === H::url_key('/کلاه', $host));
check('upper-case encoding', H::url_key('/%da%a9', $host) === H::url_key('/%DA%A9', $host));
check('other site is not internal', H::url_key('https://google.com/a', $host) === null);
check('mailto and anchors ignored', H::url_key('mailto:a@b.c', $host) === null && H::url_key('#top', $host) === null);
check('home', H::url_key('https://akaleather.com', $host) === '/' && H::url_key('/', $host) === '/');
check('protocol-relative', H::url_key('//akaleather.com/x/', $host) === '/x');
check('amp suffix removed', H::url_key('/blog/post/amp/', $host) === '/blog/post');

// finding
$html = "<!-- wp:paragraph -->\n<p>در این مقاله درباره کلاه چرم مردانه و نگهداری آن صحبت می‌کنیم.</p>\n<!-- /wp:paragraph -->\n<h2>کلاه چرم مردانه چیست؟</h2>\n<p>کلاه چرم مردانه در زمستان گرم است.</p>";
$r = H::find($html, array('کلاه چرم مردانه'), $opts);
check('finds the first paragraph, not the heading', $r['reason'] === '' && count($r['spots']) === 2 && $r['spots'][0]['anchor'] === 'کلاه چرم مردانه' && $r['spots'][0]['block'] === 'b0');
check('context around the anchor', strpos($r['spots'][0]['context']['before'], 'درباره') !== false && strpos($r['spots'][0]['context']['after'], 'نگهداری') !== false);
$new = H::insert($html, $r['spots'][0], 'https://akaleather.com/category/men-leather-hats/');
check('inserted once, everything else unchanged', $new === str_replace('درباره کلاه چرم مردانه و', 'درباره <a href="https://akaleather.com/category/men-leather-hats/">کلاه چرم مردانه</a> و', $html));
check('after inserting, the target is already linked', H::find($new, array('کلاه چرم مردانه'), $opts)['reason'] === 'already_linked');

check('arabic yeh and kaf in the text still match', count(H::find('<p>كلاه چرم مردانه زيبا</p>', array('کلاه چرم مردانه'), $opts)['spots']) === 1);
check('anchor keeps the original characters', H::find('<p>كلاه چرم مردانه زيبا</p>', array('کلاه چرم مردانه'), $opts)['spots'][0]['anchor'] === 'كلاه چرم مردانه');
check('part of a longer word is not matched', H::find('<p>کلاه چرم مردانه‌ای خریدم و کلاه چرم مردانهها</p>', array('کلاه چرم مردانه'), $opts)['spots'] === array());
check('text inside an existing link is skipped', H::find('<p>سلام <a href="/x">کلاه چرم مردانه</a></p><p>کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts)['spots'][0]['block'] === 'b1');
check('paragraph that already has a link is skipped', H::find('<p><a href="/x">چیزی</a> و کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts)['spots'] === array());
check('shortcode text is skipped', H::find('<p>[gallery title="کلاه چرم مردانه"]</p>', array('کلاه چرم مردانه'), $opts)['spots'] === array());
check('caption and table text skipped', H::find('<figure><figcaption>کلاه چرم مردانه</figcaption></figure><table><tr><td>کلاه چرم مردانه</td></tr></table>', array('کلاه چرم مردانه'), $opts)['spots'] === array());
check('already linked with another url form', H::find('<p><a href="https://www.akaleather.com/category/men-leather-hats">x</a> کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts)['reason'] === 'already_linked');
check('full source is skipped', H::find('<p><a href="/a">a</a></p><p><a href="/b">b</a></p><p>کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts + array('max_total' => 2))['reason'] === 'full');
check('anchor containing the source keyword is avoided', H::find('<p>کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts + array('avoid' => array('کلاه چرم')))['reason'] === 'no_phrase');
check('best phrase wins over earlier position', H::find('<p>کلاه چرم</p><p>کلاه چرم مردانه</p>', array('کلاه چرم مردانه', 'کلاه چرم'), $opts)['spots'][0]['anchor'] === 'کلاه چرم مردانه');
check('one spot per paragraph', count(H::find('<p>کلاه چرم مردانه و باز کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts)['spots']) === 1);

// classic editor text without <p>
$classic = "مقدمه درباره کیف.\n\nکلاه چرم مردانه را اینجا ببینید.\n\n<a href=\"/x\">لینک</a> و کلاه چرم مردانه";
$r = H::find($classic, array('کلاه چرم مردانه'), $opts);
check('free text: paragraph with a link is skipped, the clean one is found', count($r['spots']) === 1 && strpos($r['spots'][0]['context']['after'], 'اینجا') !== false);
check('free text insert', strpos(H::insert($classic, $r['spots'][0], '/category/men-leather-hats/'), '<a href="/category/men-leather-hats/">کلاه چرم مردانه</a> را اینجا') !== false);

// strong inside p
$r = H::find('<p>این <strong>کلاه چرم مردانه</strong> است</p>', array('کلاه چرم مردانه'), $opts);
check('bold text can be linked', count($r['spots']) === 1 && H::insert('<p>این <strong>کلاه چرم مردانه</strong> است</p>', $r['spots'][0], '/u') === '<p>این <strong><a href="/u">کلاه چرم مردانه</a></strong> است</p>');

// insert refuses a changed post
check('changed text is not overwritten', H::insert('<p>متن دیگر</p>', array('token' => 1, 'offset' => 0, 'anchor' => 'کلاه'), '/u') === null);
check('url is escaped', strpos(H::insert('<p>کلاه چرم مردانه</p>', H::find('<p>کلاه چرم مردانه</p>', array('کلاه چرم مردانه'), $opts)['spots'][0], '/a?b=1&c="x"'), 'href="/a?b=1&amp;c=&quot;x&quot;"') !== false);
check('internal count', H::internal_count('<p><a href="/a">a</a><a href="https://google.com">g</a><a href="#x">x</a></p>', $host) === 1);
check('plain text', H::plain('<p>سلام <b>دنیا</b></p><!-- c -->') === 'سلام دنیا');

// anchor phrases
$ph = P::build(array('focus' => 'کلاه چرم مردانه', 'queries' => array('خرید کلاه چرم مردانه', 'قیمت کلاه چرم مردانه', 'آکا چرم', 'akaleather.com', 'کلاه شاپو چرم'), 'title' => 'کلاه چرم مردانه | آکا چرم', 'synonyms' => array('کلاه چرمی مردانه'), 'brand' => 'آکا چرم'));
check('anchor phrases are cleaned, deduplicated and brand-free', $ph === array('کلاه چرم مردانه', 'کلاه شاپو چرم', 'کلاه چرمی مردانه'));
check('one word is not an anchor', P::build(array('focus' => 'کلاه')) === array());

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
