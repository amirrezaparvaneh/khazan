<?php
/**
 * گارد پاراگراف: هر بلوک متنی بیرون از هدینگ/لیست/جدول داخل <p> قرار می‌گیرد (منطق مشابه wpautop) و
 * پاراگراف‌های بیش از حد بلند از محل نقطه‌ی میانی به دو <p> شکسته می‌شوند تا آنالیزور سئو پاراگراف بالای ۱۵۰ واژه نبیند.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class ParagraphGuard {

    /** حداکثر واژه‌ی هر <p> پس از پالایش (سقف آنالیزور ۱۵۰ است) */
    const MAX_WORDS = 110;

    /** کمترین واژه‌ی هر نیمه پس از شکستن */
    const MIN_SIDE_WORDS = 10;

    const BLOCK_TAGS = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'p', 'pre', 'figure');

    const INLINE_TAGS = array('a', 'strong', 'b', 'em', 'i', 'span', 'u', 'mark', 'small');

    public static function enforce($html, $max = self::MAX_WORDS) {
        return self::split_long(self::ensure_paragraphs($html), $max);
    }

    /**
     * متن آزاد (بیرون از تگ‌های بلوکی) را با جداکننده‌ی خط خالی یا <br><br> به پاراگراف‌های <p> تبدیل می‌کند
     */
    public static function ensure_paragraphs($html) {
        $html = (string) $html;
        $out = '';
        $loose_from = 0;
        $depth = 0;
        $block_start = 0;
        $block_name = '';
        preg_match_all('~<(/?)([a-z][a-z0-9]*)\b[^>]*?(/?)>~i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            $name = strtolower($tag[2][0]);
            if (!in_array($name, self::BLOCK_TAGS, true)) continue;
            $closing = $tag[1][0] === '/';
            $offset = $tag[0][1];
            if (!$closing && $tag[3][0] !== '/') {
                if ($depth === 0) {
                    $out .= self::paragraphs(substr($html, $loose_from, $offset - $loose_from));
                    $block_start = $offset;
                    $block_name = $name;
                }
                if ($depth === 0 || $name === $block_name) $depth++;
            } elseif ($closing && $depth > 0 && $name === $block_name) {
                $depth--;
                if ($depth === 0) {
                    $end = $offset + strlen($tag[0][0]);
                    $out .= substr($html, $block_start, $end - $block_start);
                    $loose_from = $end;
                }
            }
        }
        // بلوک بسته‌نشده یا متن پایانی
        return $out . self::paragraphs(substr($html, $loose_from));
    }

    /**
     * هر <p> بیش از $max واژه را از نزدیک‌ترین نقطه (. ! ؟) به وسط متن می‌شکند؛ بدون نقطه، از «،»
     */
    public static function split_long($html, $max = self::MAX_WORDS) {
        return preg_replace_callback('~<p\b([^>]*)>(.*?)</p>~is', function ($m) use ($max) {
            $parts = self::split_text($m[2], (int) $max);
            if (count($parts) < 2) return $m[0];
            return implode('', array_map(function ($part) use ($m) { return '<p' . $m[1] . '>' . $part . '</p>'; }, $parts));
        }, (string) $html);
    }

    public static function words($html) {
        $text = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'));
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    /** @return string[] */
    private static function split_text($inner, $max) {
        $total = self::words($inner);
        if ($total <= $max) return array($inner);
        $at = self::best_cut($inner, $total, '~(?<=[.!؟?])\s+~u');
        if ($at === null) $at = self::best_cut($inner, $total, '~(?<=[،؛])\s+~u');
        if ($at === null) return array($inner);
        $left = rtrim(substr($inner, 0, $at[0]));
        $right = ltrim(substr($inner, $at[0] + $at[1]));
        return array_merge(self::split_text($left, $max), self::split_text($right, $max));
    }

    /** @return array|null [offset, length] of the whitespace after the sentence end that is closest to the middle */
    private static function best_cut($inner, $total, $pattern) {
        if (!preg_match_all($pattern, $inner, $found, PREG_OFFSET_CAPTURE)) return null;
        $best = null; $best_gap = null;
        foreach ($found[0] as $hit) {
            $prefix = substr($inner, 0, $hit[1]);
            $left_words = self::words($prefix);
            if ($left_words < self::MIN_SIDE_WORDS || $total - $left_words < self::MIN_SIDE_WORDS || !self::balanced($prefix)) continue;
            $gap = abs($total / 2 - $left_words);
            if ($best === null || $gap < $best_gap) { $best = array($hit[1], strlen($hit[0])); $best_gap = $gap; }
        }
        return $best;
    }

    /** آیا همه تگ‌های درون‌خطی باز در این پیشوند بسته شده‌اند؟ */
    private static function balanced($prefix) {
        $names = implode('|', self::INLINE_TAGS);
        $open = preg_match_all('~<(?:' . $names . ')\b[^>]*>~i', $prefix);
        $close = preg_match_all('~</(?:' . $names . ')>~i', $prefix);
        return $open === $close;
    }

    private static function paragraphs($segment) {
        $segment = preg_replace('~<br\s*/?>\s*<br\s*/?>~i', "\n\n", (string) $segment);
        $out = '';
        foreach (preg_split('/\R\s*\R/u', $segment) as $chunk) {
            $chunk = trim(preg_replace('/\s*\R\s*/u', ' ', $chunk));
            if ($chunk === '' || trim(strip_tags($chunk)) === '' && stripos($chunk, '<img') === false) continue;
            $out .= '<p>' . $chunk . '</p>';
        }
        return $out;
    }
}
