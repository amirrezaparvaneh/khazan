<?php
/**
 * گارد تراکم کلیدواژه کانونی: سقف تکرار و جایگزینی با مترادف‌ها
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class KeywordDensityGuard {

    const MAX_KEYWORD_OCCURRENCES = 5;

    /**
     * اگر کلیدواژه بیش از سقف مجاز تکرار شد، تکرارهای اضافه (بیرون از لینک) با مترادف‌ها جایگزین می‌شوند
     */
    public static function cap_keyword_density($html, $keyword, $synonyms, $max = self::MAX_KEYWORD_OCCURRENCES) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return $html;
        $alternatives = \Khazan\Services\Seo\SeoMetaService::split_keywords($synonyms, $keyword);

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
