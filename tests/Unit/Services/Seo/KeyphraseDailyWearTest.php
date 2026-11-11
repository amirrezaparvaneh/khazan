<?php
/** php tests/Unit/Services/Seo/KeyphraseDailyWearTest.php */
define('ABSPATH', '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
if (!function_exists('apply_filters')) { function apply_filters($n, $v) { return $v; } }
use Khazan\Services\Seo\KeyphraseExtractor as K;
use Khazan\Modules\Search\PageAnalyzer as P;
use Khazan\Services\Ai\UsageLog as U;

$passed = 0; $failed = 0;
function check($ok, $m) { global $passed, $failed; if ($ok) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$t = 'چرم طبیعی تبریز مدل ';
check(K::from_title('کفش روزمره زنانه ' . $t . 'لیانا', 'کفش روزمره زنانه') === 'کفش روزمره زنانه لیانا', 'daily-wear category keeps «روزمره» (not replaced by «چرم»)');
check(K::from_title('کفش روزمره مردانه ' . $t . 'ونس فلوتر', 'کفش روزمره مردانه') === 'کفش مردانه ونس فلوتر', 'two-word model: type + gender + model win, modifier only when a slot is free');
check(K::from_title('کفش اسپرت مردانه ' . $t . 'رادین', 'کفش اسپرت مردانه') === 'کفش اسپرت مردانه رادین', 'sport category keeps «اسپرت»');
check(K::from_title('کفش طبی مردانه ' . $t . 'مارکو', 'کفش طبی مردانه') === 'کفش طبی مردانه مارکو', 'medical category keeps «طبی»');

$r = P::extract_rendered('<html><head><title>T | B</title><meta name="description" content="D"></head><body><header><nav>منو</nav></header><main><h1>کمربند</h1><p>متن بالا</p><ul class="products"><li>محصول</li></ul><div><h2>راهنما</h2><p>متن اصلی</p></div></main><footer>فوتر</footer></body></html>');
check($r['h1'] === 'کمربند' && $r['title'] === 'T | B' && $r['description'] === 'D', 'rendered page: H1, SEO title and meta description are read');
check(strpos($r['html'], 'متن اصلی') !== false && strpos($r['html'], 'متن بالا') !== false && strpos($r['html'], 'محصول') === false && strpos($r['html'], 'فوتر') === false && strpos($r['html'], 'منو') === false, 'rendered page: copy kept, menu / footer / product grid dropped');

$e = U::entry('m', array('prompt_tokens' => 10, 'completion_tokens' => 50, 'completion_tokens_details' => array('reasoning_tokens' => 30)), 'x', 't');
check($e['prompt'] === 10 && $e['completion'] === 50 && $e['reasoning'] === 30, 'usage entry reads prompt / completion / reasoning tokens');
check(U::label('Khazan\\Modules\\Search\\PageAnalyzer') === 'تحلیل صفحه (سرچ کنسول)', 'usage label for the page analysis');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
