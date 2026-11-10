<?php
/**
 * Unit tests: page facts, AI analysis normalisation, update sanitising and section placement.
 *
 *   php tests/Unit/Modules/PageUpdateTest.php
 */
define('ABSPATH', __DIR__ . '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Modules\Search\PageAnalyzer as A;
use Khazan\Modules\Search\ContentUpdater as U;

$passed = 0; $failed = 0;
function check($name, $cond) { global $passed, $failed; if ($cond) { $passed++; } else { $failed++; echo "FAIL: $name\n"; } }

$html = '<p>متن اول درباره کلاه</p><h2>جنس کلاه</h2><p>چرم طبیعی <a href="/x">لینک</a> <a href="https://google.com">g</a></p><img src="a.jpg"><h2>سوالات متداول</h2><h3>قیمت چقدر است؟</h3><p>متفاوت است</p><iframe src="https://www.aparat.com/video/video/embed/x"></iframe><table><tr><td>a</td></tr></table>';
$f = A::facts($html, 'akaleather.com');
check('facts count structure', $f['h2'] === 2 && $f['h3'] === 1 && $f['images'] === 1 && $f['videos'] === 1 && $f['tables'] === 1 && $f['faq'] === 1);
check('facts count links', $f['links_internal'] === 1 && $f['links_external'] === 1);
check('facts words', $f['words'] > 10);

$n = A::normalize(array(
    'summary' => '<b>خلاصه</b>', 'content_state' => 'weird', 'keep' => array('مقدمه', ''),
    'add_sections' => array(array('heading' => 'راهنمای سایز', 'purpose' => 'x', 'covers_queries' => array('سایز کلاه'), 'words' => 9999), array('heading' => '')),
    'faq' => array(array('question' => 'کلاه چرم گرم است؟'), 'junk'),
    'media' => array(array('type' => 'hologram', 'what' => 'عکس از نزدیک دوخت'), array('type' => 'video', 'what' => '')),
    'meta' => array('title' => str_repeat('ا', 100)), 'offpage' => array('needed' => 1, 'anchors' => array('کلاه چرم')),
    'steps' => array('قدم یک', array('bad')), 'unknown' => 'x',
));
check('normalize: html stripped and state defaulted', $n['summary'] === 'خلاصه' && $n['content_state'] === 'thin');
check('normalize: empty entries removed and limits applied', count($n['keep']) === 1 && count($n['add_sections']) === 1 && $n['add_sections'][0]['words'] === 600 && count($n['faq']) === 1);
check('normalize: unknown media type becomes image, empty dropped', count($n['media']) === 1 && $n['media'][0]['type'] === 'image');
check('normalize: meta capped, offpage kept, unknown keys dropped', mb_strlen($n['meta']['title']) === 70 && $n['offpage']['needed'] === true && !isset($n['unknown']) && $n['steps'] === array('قدم یک'));
check('prompt carries the data as data', strpos(A::prompt(array('title' => 'کلاه')), '"title":"کلاه"') !== false);

check('sanitize removes links, scripts and attributes', U::sanitize('<h2 class="x">تیتر</h2><p style="a">متن <a href="/x">لینک</a></p><script>alert(1)</script><img src=x>') === '<h2>تیتر</h2><p>متن لینک</p>');
check('sanitize drops tables for products', U::sanitize('<table><tr><td>a</td></tr></table>', false) === 'a');
check('faq heading detection', U::is_faq_heading('سوالات متداول') && U::is_faq_heading('پرسش های متداول') && !U::is_faq_heading('جنس کلاه'));

$base = '<p>مقدمه</p><h2>جنس کلاه</h2><p>چرم</p><h3>دوخت</h3><p>ریز</p><h2>نگهداری</h2><p>خشک</p><h2>سوالات متداول</h2><h3>قیمت؟</h3><p>متفاوت</p>';
$m = U::merge($base, array(array('after' => 'جنس کلاه', 'html' => '<h2>راهنمای سایز</h2><p>سایز</p>'), array('after' => '', 'html' => '<h2>مقایسه</h2><p>م</p>')), '<h3>گرم است؟</h3><p>بله</p>');
$out = $m['html'];
check('existing text untouched', str_replace(array("\n", '<h2>راهنمای سایز</h2><p>سایز</p>', '<h2>مقایسه</h2><p>م</p>', '<h3>گرم است؟</h3><p>بله</p>'), '', $out) === $base);
check('section goes after the whole matching section (after its h3)', strpos($out, '<p>ریز</p>') < strpos($out, 'راهنمای سایز') && strpos($out, 'راهنمای سایز') < strpos($out, '<h2>نگهداری'));
check('section without a place goes before the FAQ', strpos($out, 'مقایسه') < strpos($out, 'سوالات متداول') && strpos($out, '<p>خشک</p>') < strpos($out, 'مقایسه'));
check('new questions join the existing FAQ at its end', strpos($out, 'گرم است؟') > strpos($out, '<p>متفاوت</p>'));
check('placements described', count($m['placements']) === 3 && strpos($m['placements'][0]['where'], 'جنس کلاه') !== false);
$m2 = U::merge('<p>متن</p>', array(), '<h3>سوال؟</h3><p>جواب</p>');
check('a new FAQ section is created when none exists', strpos($m2['html'], '<h2>پرسش‌های متداول</h2><h3>سوال؟</h3>') !== false);
$m3 = U::merge('', array(array('after' => '', 'html' => '<h2>یک</h2>'), array('after' => '', 'html' => '<h2>دو</h2>')), '<h3>س؟</h3><p>ج</p>');
check('empty page gets sections in order, FAQ last', strpos($m3['html'], 'یک') < strpos($m3['html'], 'دو') && strpos($m3['html'], 'دو') < strpos($m3['html'], 'پرسش‌های متداول'));
$gut = "<!-- wp:paragraph -->\n<p>الف</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">ب</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>ج</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">د</h2>\n<!-- /wp:heading -->";
$m4 = U::merge($gut, array(array('after' => 'ب', 'html' => '<h2>تازه</h2>')), '');
check('gutenberg: inserted before the next heading block comment, blocks intact', strpos($m4['html'], "<!-- /wp:paragraph -->\n<h2>تازه</h2>\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">د</h2>") !== false);

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
