<?php
/**
 * Link guard for product-category descriptions. After the model wrote the text this guard makes the links exactly what
 * the CategorySpiderLinker planned:
 *   - every planned link (1 homepage + 2-3 target categories) appears exactly once; missing ones are added in a short
 *     sentence, repeated ones are unwrapped;
 *   - every other internal link - above all a link to the category itself - is unwrapped (text stays);
 *   - external links are left alone;
 *   - finally LinkGuard locks the anchor text: if the model changed or shortened the text of a link, it is replaced by
 *     the exact name / focus keyphrase of the target category (or the brand phrase of the homepage link).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryLinkGuard {

    const TYPES = array('category', 'page');

    const SENTENCES = array(
        'category' => ' برای مقایسه بیشتر، %s را هم ببینید.',
        'page'     => ' برای مشاهده سایر محصولات، به %s سر بزنید.',
    );

    /**
     * @param string  $html     description HTML
     * @param array[] $links    planned links {anchor, url, type: category|page}
     * @param string  $self_url permalink of the category being written (never linked)
     */
    public static function enforce($html, array $links, $self_url = '') {
        $planned = array();
        foreach ($links as $link) {
            if (empty($link['url']) || empty($link['anchor']) || !isset($link['type']) || !in_array($link['type'], self::TYPES, true)) continue;
            $planned[self::key($link['url'])] = $link;
        }
        $self_key = $self_url !== '' ? self::key($self_url) : '';
        unset($planned[$self_key]);

        $html = self::unwrap_unplanned((string) $html, $planned, $self_key);
        $html = self::add_missing($html, $planned);
        return LinkGuard::lock_product_anchors($html, array_values($planned), self::TYPES);
    }

    /** Internal links of the final text, by normalized URL (used by the tests and diagnostics). */
    public static function internal_links($html) {
        $out = array();
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', (string) $html, $found, PREG_SET_ORDER)) return $out;
        foreach ($found as $m) {
            if (!self::is_external($m[2])) $out[] = array('key' => self::key($m[2]), 'text' => trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8')));
        }
        return $out;
    }

    /** External links of the final text as {url, anchor} (the archive stores them). */
    public static function external_links($html) {
        $out = array();
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', (string) $html, $found, PREG_SET_ORDER)) return $out;
        foreach ($found as $m) {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (self::is_external($url) && in_array(wp_parse_url($url, PHP_URL_SCHEME), array('http', 'https'), true)) {
                $out[] = array('url' => esc_url_raw($url), 'anchor' => trim(wp_strip_all_tags($m[3])));
            }
        }
        return $out;
    }

    private static function unwrap_unplanned($html, array $planned, $self_key) {
        $seen = array();
        return preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) use ($planned, $self_key, &$seen) {
            if (self::is_external($m[2])) return $m[0];
            $key = self::key($m[2]);
            if ($key === '' || $key === $self_key || !isset($planned[$key]) || isset($seen[$key])) return $m[3];
            $seen[$key] = true;
            return $m[0];
        }, $html);
    }

    /** Planned links the model dropped go into short closing sentences, one per paragraph where possible. */
    private static function add_missing($html, array $planned) {
        $present = array();
        foreach (self::internal_links($html) as $link) $present[$link['key']] = true;
        $missing = array_filter($planned, function ($link, $key) use ($present) { return !isset($present[$key]); }, ARRAY_FILTER_USE_BOTH);
        if (!$missing) return $html;

        // categories first, homepage last (it belongs in the closing paragraph)
        uasort($missing, function ($a, $b) { return $a['type'] === $b['type'] ? 0 : ($a['type'] === 'page' ? 1 : -1); });
        foreach ($missing as $link) {
            $anchor = '<a href="' . esc_url($link['url']) . '">' . esc_html($link['anchor']) . '</a>';
            $html = self::append($html, sprintf(self::SENTENCES[$link['type']], $anchor), $link['type'] === 'page');
        }
        return $html;
    }

    /** Adds the sentence to a paragraph that has no link yet (the last one for $last), or as a new paragraph. */
    private static function append($html, $sentence, $last) {
        if (!preg_match_all('~<p\b[^>]*>.*?</p>~is', $html, $found, PREG_OFFSET_CAPTURE) || !$found[0]) {
            return rtrim($html) . '<p>' . trim($sentence) . '</p>';
        }
        $paragraphs = $found[0];
        $count = count($paragraphs);
        $order = $last ? range($count - 1, 0) : range(min(1, $count - 1), $count - 1);
        $pick = null;
        foreach ($order as $i) {
            if (stripos($paragraphs[$i][0], '<a ') === false) { $pick = $i; break; }
        }
        if ($pick === null) $pick = $last ? $count - 1 : min(1, $count - 1);

        list($paragraph, $offset) = $paragraphs[$pick];
        $plain = rtrim(html_entity_decode(strip_tags($paragraph), ENT_QUOTES, 'UTF-8'));
        $stop = preg_match('~[.!؟?…:]$~u', $plain) ? '' : '.';
        $new = substr($paragraph, 0, -strlen('</p>')) . $stop . $sentence . '</p>';
        return substr_replace($html, $new, $offset, strlen($paragraph));
    }

    private static function is_external($href) {
        $host = strtolower((string) wp_parse_url(html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
        return $host !== '' && $host !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /** Same normalization as LinkGuard (entities, #fragment, trailing slash, scheme/host case) plus relative URLs. */
    private static function key($url) {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        $url = preg_replace('/#.*$/', '', $url);
        if ($url === '') return '';
        if ($url[0] === '/' && strpos($url, '//') !== 0) $url = untrailingslashit(home_url('/')) . $url;
        $parts = wp_parse_url($url);
        if (!empty($parts['host'])) {
            $url = strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . strtolower($parts['host']) . (isset($parts['path']) ? $parts['path'] : '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        return untrailingslashit($url);
    }
}
