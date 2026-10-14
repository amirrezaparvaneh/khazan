<?php
/**
 * نگهبان خروجی محتوا: پوشش wrapper سفارشی، لینک خارجی پیش‌فرض و سقف تکرار کلیدواژه کانونی
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Guard {

    const WRAPPER_CLASS = 'aka-desc-wrapper';
    const MAX_KEYWORD_OCCURRENCES = 8;
    const FALLBACK_REFERENCE_URL = 'https://fa.wikipedia.org/wiki/Special:Search?search=';

    /**
     * پوشش کل محتوا با کلاس اختصاصی سایت؛ اجرای دوباره روی محتوای پوشش‌داده‌شده تاثیری ندارد
     */
    public static function wrap($html) {
        $html = self::unwrap($html);
        if ($html === '') return '';
        return '<div class="' . self::WRAPPER_CLASS . '">' . $html . '</div>';
    }

    public static function unwrap($html) {
        $html = trim((string) $html);
        if (preg_match('~^<div\s+class="' . self::WRAPPER_CLASS . '">(.*)</div>$~is', $html, $m)) return trim($m[1]);
        return $html;
    }

    /**
     * اگر محتوا هیچ لینک خارجی ندارد، نخستین تکرار کلیدواژه در متن به صفحه جستجوی ویکی‌پدیای فارسی لینک می‌شود
     */
    public static function ensure_external_link($html, $keyword) {
        if (self::has_external_link($html)) return $html;
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;

        $candidates = array_unique(array($keyword, self::first_word($keyword)));
        foreach ($candidates as $term) {
            if ($term === '' || mb_strlen($term) < 2) continue;
            $linked = self::link_first_text_occurrence($html, $term);
            if ($linked !== null) return $linked;
        }
        return $html;
    }

    private static function has_external_link($html) {
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $matches)) return false;
        foreach ($matches[2] as $href) {
            $host = strtolower((string) wp_parse_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
            if ($host !== '' && $host !== $site_host) return true;
        }
        return false;
    }

    private static function first_word($text) {
        $parts = preg_split('/\s+/u', trim($text));
        return isset($parts[0]) ? $parts[0] : '';
    }

    /**
     * نخستین تکرار متن بیرون از لینک و هدینگ را لینک می‌کند؛ اگر پیدا نشد null برمی‌گرداند
     */
    private static function link_first_text_occurrence($html, $term) {
        $tokens = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $skip_depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<(a|h[1-6]|script|style)\b~i', $token)) $skip_depth++;
                elseif (preg_match('~^</(a|h[1-6]|script|style)>~i', $token)) $skip_depth = max(0, $skip_depth - 1);
                continue;
            }
            if ($skip_depth > 0) continue;
            $pos = mb_strpos($token, $term);
            if ($pos === false) continue;
            $url = self::FALLBACK_REFERENCE_URL . rawurlencode($term);
            $link = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $term . '</a>';
            $tokens[$i] = mb_substr($token, 0, $pos) . $link . mb_substr($token, $pos + mb_strlen($term));
            return implode('', $tokens);
        }
        return null;
    }

    /**
     * اگر کلیدواژه بیش از سقف مجاز تکرار شد، تکرارهای اضافه (بیرون از لینک) با مترادف‌ها جایگزین می‌شوند
     */
    public static function cap_keyword_density($html, $keyword, $synonyms, $max = self::MAX_KEYWORD_OCCURRENCES) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;
        $alternatives = array_values(array_filter(array_map('trim', preg_split('/[,،]/u', (string) $synonyms)), function ($item) use ($keyword) {
            return $item !== '' && $item !== $keyword;
        }));

        $tokens = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        // تکرار داخل لینک‌ها حذف نمی‌شود تا لینک خراب نشود؛ پس بودجه متن عادی از سقف کم می‌شود.
        $anchor_count = self::count_anchor_occurrences($tokens, $keyword);
        if ($anchor_count + self::count_text_occurrences($tokens, $keyword) <= $max) return $html;
        $budget = max(0, $max - $anchor_count);

        $seen = 0;
        $alt_index = 0;
        $anchor_depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth > 0) continue;
            $pieces = explode($keyword, $token);
            if (count($pieces) === 1) continue;
            $out = $pieces[0];
            for ($k = 1; $k < count($pieces); $k++) {
                $seen++;
                $replace = $seen > $budget && $alternatives;
                $out .= $replace ? $alternatives[$alt_index++ % count($alternatives)] : $keyword;
                $out .= $pieces[$k];
            }
            $tokens[$i] = $out;
        }
        return implode('', $tokens);
    }

    private static function count_anchor_occurrences($tokens, $keyword) {
        $count = 0;
        $anchor_depth = 0;
        foreach ($tokens as $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth > 0) $count += substr_count($token, $keyword);
        }
        return $count;
    }

    private static function count_text_occurrences($tokens, $keyword) {
        $count = 0;
        $anchor_depth = 0;
        foreach ($tokens as $token) {
            if ($token[0] === '<') {
                if (preg_match('~^<a\b~i', $token)) $anchor_depth++;
                elseif (preg_match('~^</a>~i', $token)) $anchor_depth = max(0, $anchor_depth - 1);
                continue;
            }
            if ($anchor_depth === 0) $count += substr_count($token, $keyword);
        }
        return $count;
    }
}
