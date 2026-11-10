<?php
/** Run: php tests/Unit/Modules/RedirectCheckTest.php */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
require __DIR__ . '/../../../includes/Modules/Search/RedirectCheck.php';
use Khazan\Modules\Search\RedirectCheck as R;
$passed = $failed = 0;
function check($n, $c) { global $passed, $failed; if ($c) $passed++; else { $failed++; echo "FAIL: $n\n"; } }

$a = R::classify(301, 'https://x.com/new/', 'https://x.com/old/');
check('301 is a permanent redirect', $a['state'] === 'redirect' && $a['permanent'] && $a['to'] === 'https://x.com/new/');
$b = R::classify(302, '/new/', 'https://x.com/old/');
check('302 is temporary and relative target is made absolute', $b['state'] === 'redirect' && !$b['permanent'] && $b['to'] === 'https://x.com/new/');
check('308 is permanent', R::classify(308, 'https://x.com/n/', 'https://x.com/o/')['permanent']);
check('redirect without location is an error', R::classify(301, '', 'https://x.com/o/')['state'] === 'error');
check('200 is ok', R::classify(200, '', 'https://x.com/o/')['state'] === 'ok');
check('404 and 410 are gone', R::classify(404, '', 'https://x.com/o/')['state'] === 'gone' && R::classify(410, '', 'https://x.com/o/')['state'] === 'gone');
check('500 is an error, not a verdict', R::classify(500, '', 'https://x.com/o/')['state'] === 'error' && R::classify(0, '', 'https://x.com/o/')['state'] === 'error');
check('absolute: protocol relative and dir relative', R::absolute('//y.com/a', 'https://x.com/o/') === 'https://y.com/a' && R::absolute('n/', 'https://x.com/a/b') === 'https://x.com/a/n/');
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
