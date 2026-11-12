<?php
/**
 * The Yoast SEO checks that generated copy must pass, computed the way Yoast computes them for Persian:
 * passive voice (a sentence is "passive" when it contains one word of Yoast's participle list), keyphrase count
 * (per sentence: every content word of the keyphrase present), the recommended count for the text length and
 * keyphrase-in-subheadings (30-75 % of H2/H3 carry more than half of the keyphrase words).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

if (!defined('ABSPATH')) {
    exit;
}

final class YoastRules {

    const PASSIVE_MAX_PERCENT   = 10.0;
    const DENSITY_MIN_PERCENT   = 0.5;
    const DENSITY_MAX_PERCENT   = 3.0;
    const SUBHEADING_MIN_SHARE  = 0.3;
    const SUBHEADING_MAX_SHARE  = 0.75;

    /** Single-word entries of Yoast's Persian participle list (multi-word entries can never match a single token). */
    const PARTICIPLES = array(
        'آراسته', 'آزرده', 'آزموده', 'آشفته', 'آغشته', 'آفریده', 'آلوده', 'آمده', 'آورده', 'آمیخته', 'آویخته', 'برانگیخته', 'باریده', 'بافته',
        'بخشوده', 'بخشیده', 'برده', 'برگردانده', 'برگزیده', 'بسته', 'بلعیده', 'بوسیده', 'بوییده', 'پاشیده', 'پخته', 'پذیرفته', 'پراکنده', 'پرداخته',
        'پرستیده', 'پرسیده', 'پژمرده', 'پسندیده', 'پنداریده', 'پوشیده', 'پوشانده', 'پیچیده', 'پیوسته', 'پیموده', 'تافته', 'تراشیده', 'ترسانده', 'ترکیده',
        'ترکانده', 'تنیده', 'توانسته', 'جوشانده', 'جوشیده', 'جوییده', 'چاپیده', 'چرخیده', 'چسبانده', 'چسبیده', 'چشیده', 'چیده', 'خاسته', 'خشکیده',
        'خراشیده', 'خریده', 'خوانده', 'خورده', 'داده', 'دریده', 'دزدیده', 'دوخته', 'دوشیده', 'دیده', 'رانده', 'ربوده', 'رسانده', 'رسیده',
        'رشته', 'رفته', 'ریخته', 'زاده', 'زده', 'زدوده', 'ساخته', 'سابیده', 'ساییده', 'ستانده', 'ستوده', 'سرشته', 'سروده', 'سوخته',
        'سوزانده', 'شده', 'شسته', 'شکافته', 'شکانده', 'شکسته', 'شکفته', 'شکافیده', 'شمرده', 'شناسانده', 'شنفته', 'شنیده', 'طلبیده', 'فرساییده',
        'فرستاده', 'فرسوده', 'فرموده', 'فروخته', 'فریفته', 'فشرده', 'کاسته', 'کاشته', 'کشته', 'کشیده', 'گفته', 'کوبیده', 'گداخته', 'گذاشته',
        'گردانده', 'گرفته', 'گزارده', 'گسسته', 'گسیخته', 'گشته', 'گشوده', 'گماشته', 'مالیده', 'نامیده', 'نگاشته', 'نواخته', 'نوردیده', 'نوشیده',
        'نهاده', 'نهفته', 'وابسته', 'واگذاشته', 'واگفته', 'وانهاده', 'انداخته', 'افتاده', 'ورانداخته', 'ورزیده', 'وزیده', 'یافته', 'آغازشده', 'آماده',
        'آغازشدن', 'آراستن', 'آزردن', 'آزمودن', 'آشفتن', 'آغشتن', 'آفریدن', 'آلودن', 'آمدن', 'آمیختن', 'آوردن', 'آویختن', 'برانگیختن', 'بافتن',
        'بخشودن', 'بخشیدن', 'بردن', 'برگرداندن', 'برگزیدن', 'بستن', 'بلعیدن', 'بوسیدن', 'بوییدن', 'پاشیدن', 'پختن', 'پذیرفتن', 'پراکندن', 'پرداختن',
        'پرستیدن', 'پرسیدن', 'پژمردن', 'پسندیدن', 'پوشیدن', 'پوشاندن', 'پیچیدن', 'پیوستن', 'پیمودن', 'تافتن', 'تراشیدن', 'ترساندن', 'ترکیدن', 'ترکاندن',
        'تنیدن', 'توانستن', 'جوشاندن', 'جوشیدن', 'جوییدن', 'چاپیدن', 'چرخییدن', 'چسباندن', 'چسبیدن', 'چشیدن', 'چیدن', 'خاستن', 'خشکاندن', 'خراشیدن',
        'خریدن', 'خواندن', 'خوردن', 'دادن', 'دریدن', 'دزدیدن', 'دوختن', 'دوشیدن', 'راندن', 'ربودن', 'رساندن', 'رسیدن', 'ریختن', 'زادن',
        'زدن', 'زدودن', 'ساختن', 'سابیدن', 'ساییدن', 'ستاندن', 'ستودن', 'سرشتن', 'سرودن', 'سوختن', 'سوزاندن', 'شستن', 'شکافتن', 'شکاندن',
        'شکستن', 'شمردن', 'شناساندن', 'شنیدن', 'طلبیدن', 'فرساییدن', 'فرستادن', 'فرسودن', 'فرمودن', 'فروختن', 'فریفتن', 'کاستن', 'کاشتن', 'کشیدن',
        'کوبیدن', 'گذاشتن', 'گرداندن', 'گرفتن', 'گزاردن', 'گشودن', 'گفتن', 'مالیدن', 'نامیدن', 'نگاشتن', 'نواختن', 'نوشیدن', 'نهادن', 'نهفتن',
        'واگذاشتن', 'وانهادن', 'افتادن', 'ورانداختن', 'ورزیدن', 'یافتن', 'آمد', 'آمدند', 'آمده‌اند',
    );

    /** Words Yoast ignores inside a keyphrase (subset that can occur in product / article keyphrases). */
    const FUNCTION_WORDS = array('از', 'و', 'با', 'در', 'به', 'برای', 'که', 'را', 'تا', 'یا', 'بر', 'یک', 'این', 'آن', 'هر', 'هم', 'ی');

    private static $participle_set = null;

    public static function normalize($text) {
        return strtr((string) $text, array('ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{00a0}" => ' ', '&nbsp;' => ' ', '&zwnj;' => "\u{200c}"));
    }

    /** Tokens as Yoast sees them: split on whitespace only (a half-space keeps a word together), outer punctuation dropped. */
    public static function tokens($text) {
        $text = self::normalize(html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8'));
        $out = array();
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $w = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $w);
            if ($w !== '' && $w !== null) $out[] = mb_strtolower($w);
        }
        return $out;
    }

    /** Plain-text sentences of an HTML body: every block is its own unit, split on sentence punctuation. */
    public static function sentences($html) {
        $text = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', (string) $html);
        $text = preg_replace('~</?(?:p|h[1-6]|li|ul|ol|td|th|tr|table|thead|tbody|div|br|blockquote)\b[^>]*>~i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $out = array();
        foreach (preg_split('/\n+/u', $text) as $block) {
            foreach (preg_split('/(?<=[.!?؟…])\s+/u', trim($block)) as $s) {
                $s = trim($s);
                if ($s !== '' && preg_match('/[\p{L}\p{N}]/u', $s)) $out[] = $s;
            }
        }
        return $out;
    }

    public static function is_passive($sentence) {
        return (bool) self::passive_words($sentence);
    }

    /** Participle-list words of a sentence (empty = active for Yoast). */
    public static function passive_words($sentence) {
        if (self::$participle_set === null) self::$participle_set = array_flip(self::PARTICIPLES);
        $hits = array();
        foreach (self::tokens($sentence) as $t) if (isset(self::$participle_set[$t])) $hits[] = $t;
        return $hits;
    }

    /** @return array total, passives (string[]), percent, words (distinct trigger words) */
    public static function passive($html) {
        $sentences = self::sentences($html);
        $passives = array(); $words = array();
        foreach ($sentences as $s) {
            $hits = self::passive_words($s);
            if ($hits) { $passives[] = $s; foreach ($hits as $h) $words[$h] = true; }
        }
        $total = count($sentences);
        return array('total' => $total, 'passives' => $passives, 'percent' => $total ? round(count($passives) * 100 / $total, 1) : 0.0, 'words' => array_keys($words));
    }

    /** Keyphrase words that count for Yoast (function words dropped; all words kept when nothing else is left). */
    public static function content_words($keyphrase) {
        $all = self::tokens($keyphrase);
        $kept = array_values(array_filter($all, function ($w) { return !in_array($w, self::FUNCTION_WORDS, true); }));
        return array_map(array(__CLASS__, 'bare'), $kept ?: $all);
    }

    private static function bare($word) {
        return str_replace(array("\u{200c}", "\u{200d}", 'ـ'), '', $word);
    }

    /** How often a keyphrase word occurs in a token list (basic Persian word forms count, as in Yoast Premium). */
    private static function word_hits(array $tokens, $word) {
        $n = 0;
        foreach ($tokens as $t) {
            $t = self::bare($t);
            if ($t === $word || $t === $word . 'ها' || $t === $word . 'های' || $t === $word . 'هایی' || $t === $word . 'ی') $n++;
        }
        return $n;
    }

    /** Yoast keyphrase count: per sentence, the smallest number of occurrences among the keyphrase content words. */
    public static function keyphrase_count($html, $keyphrase) {
        $words = self::content_words($keyphrase);
        if (!$words) return 0;
        $count = 0;
        foreach (self::sentences($html) as $s) {
            $tokens = self::tokens($s);
            $min = PHP_INT_MAX;
            foreach ($words as $w) { $min = min($min, self::word_hits($tokens, $w)); if ($min === 0) break; }
            if ($min !== PHP_INT_MAX) $count += $min;
        }
        return $count;
    }

    /** Recommended [min, max] keyphrase count of Yoast for a text of $word_count words. */
    public static function keyphrase_bounds($word_count, $keyphrase) {
        $length = max(1, count(self::content_words($keyphrase)));
        $factor = 0.7 + $length / 3;
        $calc = function ($percent, $ceil) use ($word_count, $factor) {
            $v = $percent * max(0, (int) $word_count) / (100 * $factor);
            if ($v < 2) return 2;
            return (int) ($ceil ? ceil($v) : floor($v));
        };
        $min = $calc(self::DENSITY_MIN_PERCENT, true);
        return array($min, max($min, $calc(self::DENSITY_MAX_PERCENT, false)));
    }

    public static function word_count($html) {
        return count(self::tokens(preg_replace('~<[^>]+>~', ' ', (string) $html)));
    }

    /** Does a subheading reflect the keyphrase for Yoast (more than half of its content words)? */
    public static function reflects($heading, $keyphrase) {
        $words = self::content_words($keyphrase);
        if (!$words) return false;
        $tokens = self::tokens($heading);
        $found = 0;
        foreach ($words as $w) if (self::word_hits($tokens, $w) > 0) $found++;
        return $found * 100 / count($words) > 50;
    }

    /** @return array count (H2+H3), matches, min, max */
    public static function subheadings($html, $keyphrase) {
        $count = 0; $matches = 0;
        if (preg_match_all('~<h[23]\b[^>]*>(.*?)</h[23]>~is', (string) $html, $m)) {
            foreach ($m[1] as $h) { $count++; if (self::reflects($h, $keyphrase)) $matches++; }
        }
        return array('count' => $count, 'matches' => $matches, 'min' => (int) ceil($count * self::SUBHEADING_MIN_SHARE), 'max' => (int) floor($count * self::SUBHEADING_MAX_SHARE));
    }
}
