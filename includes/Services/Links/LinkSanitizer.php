<?php
/**
 * Keeps the links of a generated text honest. It never ADDS canned sentences; it only removes what breaks the rules:
 *   - internal links to URLs that are not in the plan (and every link to the page itself) are unwrapped;
 *   - external links are kept only when the host is a verified source;
 *   - a URL is linked once (the first occurrence wins), a paragraph keeps at most N links;
 *   - an anchor that is not one of the planned options is replaced by the primary option (the text still reads fine
 *     because the options are real names of the target).
 * Planned links the model forgot are reported (missing) so the repair pass can ask for them.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

use Khazan\Services\Content\ContentAudit;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkSanitizer {

    /**
     * @param string  $html
     * @param array[] $plan             planned internal links {url, anchor, anchor_options}
     * @param string  $self_url         URL of the page being written
     * @param string  $home_host        own host (internal when empty host or equal)
     * @param string[] $verified_urls   approved external URLs
     * @param int     $per_paragraph    max links per paragraph
     * @return array{html:string,missing:array[],removed:int}
     */
    public static function apply($html, array $plan, $self_url, $home_host, array $verified_urls, $per_paragraph = 2) {
        $by_url = array();
        foreach ($plan as $link) $by_url[self::key($link['url'])] = $link;
        $verified = array_map(array(__CLASS__, 'key'), $verified_urls);
        $self_key = $self_url !== '' ? self::key($self_url) : '';
        $seen = array();
        $removed = 0;

        // paragraph by paragraph so the per-paragraph cap is simple
        $html = preg_replace_callback('~<(p|li)\b[^>]*>.*?</\1>~is', function ($block) use (&$seen, &$removed, $by_url, $verified, $self_key, $home_host, $per_paragraph) {
            $in_block = 0;
            return preg_replace_callback('~<a\b([^>]*)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)</a>~is', function ($m) use (&$seen, &$removed, &$in_block, $by_url, $verified, $self_key, $home_host, $per_paragraph) {
                $key = self::key($m[3]);
                $host = strtolower((string) parse_url(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
                $internal = $host === '' || $host === $home_host || $host === 'www.' . $home_host;
                $allowed = $internal ? (isset($by_url[$key]) && $key !== $self_key) : in_array($key, $verified, true);
                if (!$allowed || isset($seen[$key]) || $in_block >= $per_paragraph) { $removed++; return $m[5]; }
                $seen[$key] = true;
                $in_block++;
                if ($internal) {
                    $options = isset($by_url[$key]['anchor_options']) ? $by_url[$key]['anchor_options'] : array($by_url[$key]['anchor']);
                    $text = trim(html_entity_decode(strip_tags($m[5]), ENT_QUOTES, 'UTF-8'));
                    if (!self::anchor_ok($text, $options)) return '<a href="' . $m[3] . '">' . esc_html($by_url[$key]['anchor']) . '</a>';
                }
                return $m[0];
            }, $block[0]);
        }, (string) $html);

        $missing = array();
        foreach ($by_url as $key => $link) if (!isset($seen[$key])) $missing[] = $link;
        return array('html' => $html, 'missing' => $missing, 'removed' => $removed);
    }

    /** Anchor is fine when it equals an option or contains the option's distinctive text (natural variants). */
    public static function anchor_ok($text, array $options) {
        $t = ContentAudit::normalize($text);
        if ($t === '' || mb_strlen($t) > 80) return false;
        foreach ($options as $option) {
            $o = ContentAudit::normalize($option);
            if ($o !== '' && ($t === $o || mb_strpos($t, $o) !== false)) return true;
        }
        return false;
    }

    public static function key($url) {
        $url = preg_replace('/#.*$/', '', trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8')));
        return rtrim(rawurldecode($url), '/');
    }
}
