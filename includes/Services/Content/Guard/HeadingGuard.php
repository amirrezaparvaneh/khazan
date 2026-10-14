<?php
/**
 * گارد کلیدواژه در هدینگ‌ها: نگه‌داشتن نسبت ۳۵٪ تا ۷۰٪ (هدف ۴۰٪) برای کلیدواژه/مترادف
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content\Guard;

if (!defined('ABSPATH')) {
    exit;
}

final class HeadingGuard {

    /**
     * بازه نسبت هدینگ‌های H2/H3 دارای کلیدواژه کانونی یا مترادف ثبت‌شده؛ کمتر از کف یا بیشتر از سقف به هدف ۴۰ تا ۵۰ درصد برمی‌گردد
     */
    const HEADING_MIN_RATIO = 0.35;

    const HEADING_MAX_RATIO = 0.7;

    const HEADING_TARGET_RATIO = 0.4;

    /**
     * Yoast دقیقاً دنبال کلیدواژه کانونی یا مترادف‌ها در H2/H3 می‌گردد؛ این تابع نسبت را بدون اتکا به مدل اصلاح می‌کند:
     * اگر کم بود کلیدواژه کانونی به هدینگ‌های فاقد آن اضافه می‌شود و اگر زیاد بود از هدینگ‌های اضافی حذف می‌شود.
     */
    public static function enforce_heading_keyphrases($html, $keyword, $synonyms) {
        $keyword = trim(preg_replace('/\s+/u', ' ', (string) $keyword));
        if ($keyword === '') return $html;
        $phrases = array_merge(array($keyword), \Khazan\Services\Seo\SeoMetaService::split_keywords($synonyms, $keyword));
        if (!preg_match_all('~<(h[23])\b([^>]*)>(.*?)</\1>~isu', $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $html;

        $headings = array();
        foreach ($found as $m) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($m[3][0]), ENT_QUOTES, 'UTF-8')));
            $headings[] = array('html' => $m[0][0], 'offset' => $m[0][1], 'tag' => $m[1][0], 'attrs' => $m[2][0], 'inner' => $m[3][0], 'text' => $text, 'match' => self::contains_phrase($text, $phrases));
        }
        $total = count($headings);
        $matched = count(array_filter(array_column($headings, 'match')));
        $target = max(1, (int) ceil($total * self::HEADING_TARGET_RATIO));
        $ratio = $matched / $total;

        if ($ratio < self::HEADING_MIN_RATIO) {
            $headings = self::add_keyword_to_headings($headings, $keyword, $target - $matched);
        } elseif ($ratio > self::HEADING_MAX_RATIO) {
            $headings = self::remove_keyword_from_headings($headings, $phrases, $matched - $target);
        } else {
            return $html;
        }

        // جایگزینی از انتها به ابتدا تا offsetها جابه‌جا نشوند
        for ($i = $total - 1; $i >= 0; $i--) {
            $h = $headings[$i];
            if (!isset($h['new_inner'])) continue;
            $replacement = '<' . $h['tag'] . $h['attrs'] . '>' . $h['new_inner'] . '</' . $h['tag'] . '>';
            $html = substr_replace($html, $replacement, $h['offset'], strlen($h['html']));
        }
        return $html;
    }

    private static function contains_phrase($text, $phrases) {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && mb_strpos($text, $phrase) !== false) return true;
        }
        return false;
    }

    private static function is_question($text) {
        return (bool) preg_match('/[؟?]\s*$/u', $text);
    }

    /**
     * کلیدواژه کانونی به هدینگ‌های فاقد آن اضافه می‌شود؛ اول H2های غیرسوالی، بعد H3های غیرسوالی و در آخر سوال‌ها
     */
    private static function add_keyword_to_headings($headings, $keyword, $needed) {
        $order = array();
        foreach ($headings as $i => $h) {
            if ($h['match'] || $h['text'] === '') continue;
            $order[$i] = (self::is_question($h['text']) ? 2 : 0) + (strtolower($h['tag']) === 'h3' ? 1 : 0);
        }
        asort($order);
        foreach (array_keys($order) as $i) {
            if ($needed <= 0) break;
            $inner = trim($headings[$i]['inner']);
            // سوال‌ها با پیشوند «کلیدواژه:» و بقیه با افزودن کلیدواژه در انتها، تا علامت سوال جابه‌جا نشود
            $headings[$i]['new_inner'] = self::is_question($headings[$i]['text'])
                ? esc_html($keyword) . ': ' . $inner
                : $inner . ' ' . esc_html($keyword);
            $needed--;
        }
        return $headings;
    }

    /**
     * کلیدواژه از هدینگ‌های اضافی (از انتها) حذف می‌شود؛ هدینگی که پس از حذف بی‌معنا یا خالی شود دست نمی‌خورد
     */
    private static function remove_keyword_from_headings($headings, $phrases, $excess) {
        usort($phrases, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        for ($i = count($headings) - 1; $i >= 0 && $excess > 0; $i--) {
            if (!$headings[$i]['match'] || $i === 0) continue;
            $inner = $headings[$i]['inner'];
            foreach ($phrases as $phrase) $inner = str_replace($phrase, '', $inner);
            $inner = preg_replace('/\s+([:،,؟?])/u', '$1', $inner);
            $inner = preg_replace('/^[\s:،,\-–|]+|[\s:،,\-–|]+$/u', '', preg_replace('/\s+/u', ' ', $inner));
            $plain = trim(wp_strip_all_tags($inner));
            if ($plain === '' || preg_match('/^[؟?]$/u', $plain) || count(preg_split('/\s+/u', $plain)) < 2) continue;
            $headings[$i]['new_inner'] = $inner;
            $excess--;
        }
        return $headings;
    }
}
