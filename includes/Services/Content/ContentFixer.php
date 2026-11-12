<?php
/**
 * Repairs existing content without rewriting it: the same guards that run on freshly generated text are applied to a
 * stored text (product, category or article) and every step that changed something is reported. Pure (no WordPress
 * calls): everything the site knows (correct anchors, category URLs, own URL) comes in through $opts.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;
use Khazan\Services\Content\Guard\WrapperGuard;
use Khazan\Services\Content\Guard\YoastGuard;
use Khazan\Services\Links\LinkSpreader;
use Khazan\Services\Seo\KeyphraseExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentFixer {

    /**
     * @param string $html stored HTML
     * @param array  $opts kind, focus, home_host, self_url,
     *                     anchors (url => array(anchor, title)): the correct anchor of each linked product,
     *                     keep (category urls that never move)
     * @return array{html:string,changes:string[]}
     */
    public static function fix($html, array $opts) {
        $kind  = isset($opts['kind']) ? $opts['kind'] : Settings::KIND_PRODUCT;
        $focus = isset($opts['focus']) ? trim((string) $opts['focus']) : '';
        $host  = isset($opts['home_host']) ? strtolower((string) $opts['home_host']) : '';
        $self  = isset($opts['self_url']) ? (string) $opts['self_url'] : '';
        $raw = (string) $html;
        $wrapped = WrapperGuard::unwrap($raw) !== trim($raw);
        $html = WrapperGuard::unwrap($raw);
        $changes = array();
        $step = function ($label, $new) use (&$html, &$changes) {
            if ($new !== $html && trim(strip_tags($new)) !== '') { $html = $new; $changes[] = $label; }
        };

        $step('پاک‌سازی نگارشی (فلش، فاصله، تکرار شرایط خرید و عبارت‌های ماشینی)', HtmlSanitizer::clean($html, $kind));
        $step('اصلاح انکر لینک‌ها به نام کامل محصول مقصد', self::fix_anchors($html, isset($opts['anchors']) ? (array) $opts['anchors'] : array(), $focus));
        $step('حذف لینک تکراری یا لینک به خود صفحه', LinkSpreader::dedupe($html, $host, $self));
        if ($kind === Settings::KIND_PRODUCT) {
            $evicted = LinkSpreader::evict_closing($html, $host);
            $moved = $evicted['html'];
            if ($evicted['links']) $moved = LinkSpreader::place($moved, $evicted['links'], $host)['html'];
            $step('انتقال لینک‌های بعد از جمع‌بندی به متن', $moved);
            $step('یک لینک در هر پاراگراف', LinkSpreader::spread($html, $host));
            $keep = isset($opts['keep']) ? (array) $opts['keep'] : array();
            $step('فاصله‌گذاری لینک‌های نزدیک به هم', LinkSpreader::separate($html, $host, $keep, (int) SeoStandards::get('links_min_gap_words')));
            if ($focus !== '') {
                $step('کلمه کلیدی در زیرعنوان‌ها', YoastGuard::ensure_subheadings($html, $focus));
                $step('تراکم کلمه کلیدی در حد مجاز Yoast', YoastGuard::cap_keyphrase($html, $focus));
            }
        }
        $step('کاهش جمله‌های مجهول از نگاه Yoast', YoastGuard::soften_passive($html, $focus));

        if ($wrapped) $html = WrapperGuard::wrap($html);
        elseif ($changes === array()) $html = $raw;
        return array('html' => $html, 'changes' => $changes);
    }

    /**
     * A link to a product whose text is a cut or shortened form of the product name («کاپشن زنانه سمیرا یقه» for
     * «... سمیرا یقه ایستاده») gets the correct anchor. Descriptive anchors (words that are not in the product title)
     * and anchors equal to this page's own keyphrase are left alone.
     */
    public static function fix_anchors($html, array $anchors, $own_focus = '') {
        if (!$anchors) return $html;
        $map = array();
        foreach ($anchors as $url => $row) {
            $key = rtrim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'), '/');
            if ($key !== '' && is_array($row) && !empty($row['anchor'])) $map[$key] = $row;
        }
        $own = $own_focus !== '' ? KeyphraseExtractor::key($own_focus) : '';
        return preg_replace_callback('~(<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\2[^>]*>)([^<]*)(</a>)~is', function ($m) use ($map, $own) {
            $key = rtrim(html_entity_decode(trim($m[3]), ENT_QUOTES, 'UTF-8'), '/');
            if (!isset($map[$key])) return $m[0];
            $text = trim(html_entity_decode($m[4], ENT_QUOTES, 'UTF-8'));
            $want = trim((string) $map[$key]['anchor']);
            if ($text === '' || $want === '' || KeyphraseExtractor::key($text) === KeyphraseExtractor::key($want)) return $m[0];
            if ($own !== '' && KeyphraseExtractor::key($want) === $own) return $m[0];
            $title_words = array_map(array(KeyphraseExtractor::class, 'key'), KeyphraseExtractor::words(isset($map[$key]['title']) ? $map[$key]['title'] . ' ' . $want : $want));
            $text_words  = array_map(array(KeyphraseExtractor::class, 'key'), KeyphraseExtractor::words($text));
            if (count($text_words) < 2 || array_diff($text_words, $title_words)) return $m[0];
            return $m[1] . htmlspecialchars($want, ENT_QUOTES, 'UTF-8') . $m[5];
        }, (string) $html);
    }

    /**
     * Plain-text blocks (<p>/<li> without inner tags) the AI may rewrite: index => text. Blocks with links or
     * formatting stay out so a patch can never break a link.
     */
    public static function segments($html) {
        $out = array();
        if (preg_match_all('~<(p|li)\b[^>]*>([^<]{20,})</\1>~u', WrapperGuard::unwrap((string) $html), $m)) {
            foreach ($m[2] as $text) $out[] = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        }
        return array_values(array_unique($out));
    }

    /** Applies validated text patches (before => after) to the plain blocks they came from. */
    public static function apply_patches($html, array $patches) {
        foreach ($patches as $p) {
            if (!isset($p['before'], $p['after'])) continue;
            $before = htmlspecialchars((string) $p['before'], ENT_NOQUOTES, 'UTF-8');
            $after  = htmlspecialchars((string) $p['after'], ENT_NOQUOTES, 'UTF-8');
            foreach (array($before, (string) $p['before']) as $needle) {
                $new = preg_replace('~(<(p|li)\b[^>]*>)' . preg_quote($needle, '~') . '(</\2>)~u', '${1}' . str_replace(array('\\', '$'), array('\\\\', '\$'), $after) . '${3}', $html, 1, $n);
                if ($n) { $html = $new; break; }
            }
        }
        return $html;
    }
}
