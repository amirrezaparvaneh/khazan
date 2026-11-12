<?php
/**
 * Pure content audit against SeoStandards: the same numbers an editor would put in a Yoast table.
 * No WordPress calls except SeoStandards (filter/option); fully unit-testable.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentAudit {

    /** Persian-aware normal form: Arabic yeh/kaf, ZWNJ, digits, case. */
    public static function normalize($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        $text = str_replace(array("\u{200c}", "\u{200f}", "\u{200e}", 'ي', 'ك', 'ە', 'ۀ'), array(' ', '', '', 'ی', 'ک', 'ه', 'ه'), $text);
        $text = strtr($text, array('٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'));
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** Visible words of an HTML fragment. */
    public static function words($html) {
        $text = self::normalize(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', (string) $html)));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens ? $tokens : array();
    }

    public static function word_count($html) {
        return count(self::words($html));
    }

    /** Non-overlapping occurrences of the phrase (word-bounded) in the visible text of $html. */
    public static function phrase_count($html, $phrase) {
        $needle = implode(' ', self::words($phrase));
        if ($needle === '') return 0;
        $haystack = ' ' . implode(' ', self::words($html)) . ' ';
        return substr_count($haystack, ' ' . $needle . ' ');
    }

    /** Does the phrase occur in the first $limit words of the text? */
    public static function phrase_in_first($html, $phrase, $limit) {
        $needle = implode(' ', self::words($phrase));
        if ($needle === '') return false;
        $head = ' ' . implode(' ', array_slice(self::words($html), 0, (int) $limit)) . ' ';
        return strpos($head, ' ' . $needle . ' ') !== false;
    }

    /**
     * @param string $html     body HTML
     * @param string $focus    focus keyphrase
     * @param array  $opts     kind, home_host (own host), title, meta, subkeywords (string[])
     * @return array metrics + 'failures' (code => message) + 'ok'
     */
    public static function analyze($html, $focus, array $opts = array()) {
        $std  = SeoStandards::all();
        $kind = isset($opts['kind']) ? $opts['kind'] : Settings::KIND_CATEGORY;
        $html = (string) $html;

        $words = self::word_count($html);
        $count = self::phrase_count($html, $focus);
        $m = array(
            'words'         => $words,
            'keyword_count' => $count,
            'density'       => $words > 0 ? round($count / $words * 100, 2) : 0.0,
            'keyword_in_first_words' => self::phrase_in_first($html, $focus, $std['keyword_in_first']),
            'h1'            => preg_match_all('/<h1\b/i', $html),
            'h2'            => 0,
            'h3'            => 0,
            'keyword_in_h2' => 0,
            'keyword_in_headings' => 0,
            'max_section_words'   => 0,
            'tables'        => preg_match_all('/<table\b/i', $html),
            'internal_links' => 0,
            'external_links' => 0,
            'duplicate_urls' => array(),
            'max_links_per_paragraph' => 0,
            'min_link_gap' => null,
        );

        if (preg_match_all('/<(h[23])\b[^>]*>(.*?)<\/\1>/is', $html, $heads, PREG_SET_ORDER)) {
            foreach ($heads as $h) {
                $has = self::phrase_count($h[2], $focus) > 0;
                if (strtolower($h[1]) === 'h2') { $m['h2']++; if ($has) $m['keyword_in_h2']++; } else { $m['h3']++; }
                if ($has) $m['keyword_in_headings']++;
            }
        }
        foreach (preg_split('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', $html) as $section) {
            $m['max_section_words'] = max($m['max_section_words'], self::word_count($section));
        }

        $host = isset($opts['home_host']) ? strtolower((string) $opts['home_host']) : '';
        $seen = array();
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $links)) {
            foreach ($links[2] as $href) {
                $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                if ($href === '' || $href[0] === '#' || stripos($href, 'mailto:') === 0 || stripos($href, 'tel:') === 0) continue;
                $link_host = strtolower((string) parse_url($href, PHP_URL_HOST));
                $internal = $link_host === '' || ($host !== '' && ($link_host === $host || $link_host === 'www.' . $host));
                $internal ? $m['internal_links']++ : $m['external_links']++;
                $key = rtrim(preg_replace('/#.*$/', '', rawurldecode($href)), '/');
                if (isset($seen[$key])) $m['duplicate_urls'][] = $href;
                $seen[$key] = true;
            }
        }
        if (preg_match_all('/<(p|li)\b[^>]*>(.*?)<\/\1>/is', $html, $blocks)) {
            foreach ($blocks[2] as $block) $m['max_links_per_paragraph'] = max($m['max_links_per_paragraph'], preg_match_all('/<a\b/i', $block));
        }

        $m['min_link_gap'] = self::min_link_gap($html, $host);

        $f = array();
        list($wmin) = SeoStandards::words_range($kind);
        if (isset($opts['words_min'])) $wmin = max(1, (int) $opts['words_min']);
        if ($words < $wmin) $f['length_short'] = "متن {$words} کلمه است؛ حداقل {$wmin} کلمه لازم است.";
        if ($m['density'] < $std['density_min']) $f['density_low'] = "تراکم کلمه اصلی {$m['density']}٪ است؛ حداقل {$std['density_min']}٪ لازم است.";
        if ($m['density'] > $std['density_max']) $f['density_high'] = "تراکم کلمه اصلی {$m['density']}٪ است؛ حداکثر {$std['density_max']}٪ مجاز است.";
        if (!$m['keyword_in_first_words']) $f['keyword_not_in_first'] = "کلمه اصلی در {$std['keyword_in_first']} کلمه اول نیامده است.";
        if ($m['h1'] > 0) $f['h1_in_body'] = 'متن اصلی نباید H1 داشته باشد.';
        if ($m['h2'] < $std['h2_min']) $f['h2_few'] = "تعداد H2 برابر {$m['h2']} است؛ حداقل {$std['h2_min']} لازم است.";
        if ($m['keyword_in_h2'] < 1) $f['keyword_not_in_heading'] = 'کلمه اصلی در هیچ H2 نیست؛ دست‌کم یک H2 باید کلمه اصلی را داشته باشد.';
        if ($m['keyword_in_h2'] > $std['h2_keyword_max']) $f['keyword_h2_many'] = "کلمه اصلی در {$m['keyword_in_h2']} عنوان H2 آمده؛ حداکثر {$std['h2_keyword_max']}.";
        if ($m['max_section_words'] > $std['section_words_max']) $f['section_long'] = "یک بخش {$m['max_section_words']} کلمه بدون زیرعنوان دارد؛ حداکثر {$std['section_words_max']}.";
        if ($kind !== Settings::KIND_PRODUCT && $m['tables'] < 1) $f['table_missing'] = 'جدول مقایسه یا راهنمای انتخاب در متن نیست.';
        list($imin, $imax) = SeoStandards::internal_range($kind);
        if ($m['internal_links'] < $imin || $m['internal_links'] > $imax) $f['internal_links'] = "لینک داخلی {$m['internal_links']} است؛ بازه مجاز {$imin} تا {$imax}.";
        list($emin, $emax) = SeoStandards::external_range();
        if ($m['external_links'] > $emax) $f['external_links'] = "لینک خارجی {$m['external_links']} است؛ حداکثر {$emax}.";
        if ($std['link_each_url_once'] && $m['duplicate_urls']) $f['duplicate_links'] = 'یک آدرس بیش از یک‌بار لینک شده است.';
        if ($m['min_link_gap'] !== null && isset($std['links_min_gap_words']) && $m['min_link_gap'] < (int) $std['links_min_gap_words']) $f['links_clustered'] = "دو لینک داخلی فقط {$m['min_link_gap']} کلمه با هم فاصله دارند؛ لینک‌ها باید در کل متن و در بخش‌های مختلف پخش شوند (حداقل {$std['links_min_gap_words']} کلمه فاصله).";
        if ($m['max_links_per_paragraph'] > $std['links_per_paragraph']) $f['links_per_paragraph'] = "یک پاراگراف {$m['max_links_per_paragraph']} لینک دارد؛ حداکثر {$std['links_per_paragraph']}.";

        if (isset($opts['title'])) {
            $len = mb_strlen($opts['title']);
            if ($len < $std['title_min'] || $len > $std['title_max']) $f['title_length'] = "عنوان سئو {$len} کاراکتر است؛ بازه {$std['title_min']} تا {$std['title_max']}.";
            foreach ((array) $std['title_forbidden'] as $bad) if ($bad !== '' && mb_strpos($opts['title'], $bad) !== false) $f['title_forbidden'] = "عنوان سئو نباید «{$bad}» داشته باشد.";
            if (self::phrase_count($opts['title'], $focus) < 1) $f['title_keyword'] = 'کلمه اصلی در عنوان سئو نیست.';
        }
        if (isset($opts['meta'])) {
            $len = mb_strlen($opts['meta']);
            if ($len < $std['meta_min'] || $len > $std['meta_max']) $f['meta_length'] = "متا {$len} کاراکتر است؛ بازه {$std['meta_min']} تا {$std['meta_max']}.";
            if (self::phrase_count($opts['meta'], $focus) < 1) $f['meta_keyword'] = 'کلمه اصلی در متا نیست.';
        }

        $m['failures'] = $f;
        $m['ok'] = !$f;
        return $m;
    }

    /**
     * Smallest number of words between two consecutive internal links (null with fewer than two).
     * Pure: links are replaced by a marker, tags are dropped, words are counted between markers.
     */
    public static function min_link_gap($html, $home_host = '') {
        $home_host = strtolower((string) $home_host);
        $marked = preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) use ($home_host) {
            $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            if ($href === '' || $href[0] === '#') return $m[3];
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            $internal = $h === '' || ($home_host !== '' && ($h === $home_host || $h === 'www.' . $home_host));
            return $internal ? ' ' . "\x01" . ' ' : $m[3];
        }, (string) $html);
        $text = html_entity_decode(strip_tags(preg_replace('~</(?:p|li|h[1-6]|div|tr)>~i', ' ', $marked)), ENT_QUOTES, 'UTF-8');
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $last = null; $min = null;
        foreach ($words as $i => $w) {
            if (strpos($w, "\x01") === false) continue;
            if ($last !== null) { $gap = $i - $last - 1; $min = $min === null ? $gap : min($min, $gap); }
            $last = $i;
        }
        return $min;
    }

    /** Persian summary of failures for a repair prompt. */
    public static function repair_brief(array $failures) {
        return $failures ? "- " . implode("\n- ", $failures) : '';
    }
}
