<?php
/**
 * Anchor text candidates for links TO a page, best first: its focus keyphrase, the searches it really gets in Google
 * (Search Console), its synonyms and a cleaned title. Every candidate is 2-6 words and never a sales word alone.
 *
 * Pure PHP (no WordPress).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Links;

if (!defined('ABSPATH')) {
    exit;
}

final class AnchorPhrases {

    /** Words that make a search a buying query, not an anchor (they are trimmed from the edges). */
    const EDGE_WORDS = array('خرید', 'قیمت', 'فروش', 'سفارش', 'ارزان', 'بهترین', 'لیست', 'مدل', 'مدلهای', 'مدل‌های', 'انواع', 'راهنمای', 'آنلاین', 'اینترنتی', 'جدید', 'عکس', 'تصویر', 'تخفیف');

    public static function clean($phrase, $brand = '') {
        $p = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $phrase, ENT_QUOTES, 'UTF-8'))));
        // a title often ends with " | brand" or " - brand"
        $p = preg_split('/\s+[|\x{2013}\x{2014}\-:·]\s+/u', $p)[0];
        if ($brand !== '') $p = trim(str_replace($brand, '', $p));
        $words = $p === '' ? array() : explode(' ', $p);
        while ($words && in_array($words[0], self::EDGE_WORDS, true)) array_shift($words);
        while ($words && in_array(end($words), self::EDGE_WORDS, true)) array_pop($words);
        return implode(' ', $words);
    }

    /**
     * @param array $in focus (string), synonyms (string[]), queries (string[] best first), title (string), brand (string)
     * @return string[]
     */
    public static function build(array $in) {
        $in += array('focus' => '', 'synonyms' => array(), 'queries' => array(), 'title' => '', 'brand' => '');
        $raw = array_merge(array($in['focus']), (array) $in['queries'], (array) $in['synonyms'], array($in['title']));
        $out = array();
        $seen = array();
        foreach ($raw as $p) {
            $p = self::clean($p, $in['brand']);
            if ($p === '') continue;
            $n = HtmlLinkText::norm_phrase($p);
            $words = count(explode(' ', $n));
            if ($words < 2 || $words > 6 || isset($seen[$n])) continue;
            // a domain name or a latin-only brand search is not a useful anchor
            if (preg_match('/\.(com|ir|net|org)\b/i', $n) || ($in['brand'] !== '' && mb_strpos($n, HtmlLinkText::norm_phrase($in['brand']), 0, 'UTF-8') !== false)) continue;
            $seen[$n] = true;
            $out[] = $p;
        }
        return $out;
    }
}
