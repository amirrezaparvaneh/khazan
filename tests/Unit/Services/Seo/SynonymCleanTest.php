<?php
/** php tests/Unit/Services/Seo/SynonymCleanTest.php */
define('ABSPATH', '/');
define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
require WILA_PLUGIN_DIR . 'includes/Core/Autoloader.php';
Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR . 'includes/');
use Khazan\Services\Seo\SeoMetaService as S;

$passed = 0; $failed = 0;
function check($ok, $m) { global $passed, $failed; if ($ok) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }

$r = S::clean_synonyms('بوت چرم زنانه، بوت چرم زنانه آلفردو، نیم بوت چرمی بانوان, ارزان, کفش ساق بلند زنانه ، نیم بوت چرمی بانوان، یک دو سه چهار پنج شش', 'بوت چرم زنانه آلفردو');
check($r === array('بوت چرم زنانه', 'نیم بوت چرمی بانوان', 'کفش ساق بلند زنانه'), 'drops the focus phrase, single words, duplicates and phrases longer than 5 words');
check(count(S::clean_synonyms('الف ب، پ ت، ج چ، ح خ، د ذ، ر ز، س ش، ص ض', 'x')) === 6, 'at most 6 secondary keywords');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
