<?php
/**
 * Deterministic one-click scan (pure, no AI): finds common SEO and readability problems in the
 * editor text and SEO metadata so the AI prompt can focus on real issues.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Seo;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoIssueScanner {

    const LONG_PARAGRAPH_WORDS = 120;
    const MAX_DENSITY_PERCENT  = 2.5;
    const TITLE_MAX_CHARS      = 60;

    /**
     * @param string[] $segments Plain-text editor segments.
     * @param array    $meta     {title, desc, focuskw}
     * @return string[] Human-readable Persian issue descriptions.
     */
    public static function scan($segments, $meta) {
        $issues = array();
        $focus = trim((string) ($meta['focuskw'] ?? ''));
        $title = trim((string) ($meta['title'] ?? ''));
        $desc  = trim((string) ($meta['desc'] ?? ''));

        if ($focus !== '') {
            if ($title === '') {
                $issues[] = 'عنوان سئو خالی است؛ عنوان باید با عبارت کلیدی «' . $focus . '» شروع شود.';
            } elseif (mb_strpos($title, $focus) !== 0) {
                $issues[] = 'عبارت کلیدی «' . $focus . '» عیناً در ابتدای عنوان سئو نیست (هم‌خوانی دقیق یافت نشد).';
            }
            if ($desc !== '' && mb_strpos($desc, $focus) === false) {
                $issues[] = 'عبارت کلیدی در توضیحات متا نیامده است.';
            }
        }
        if ($title !== '' && mb_strlen($title) > self::TITLE_MAX_CHARS) {
            $issues[] = 'عنوان سئو بیش از ' . self::TITLE_MAX_CHARS . ' کاراکتر است.';
        }
        if ($desc === '') {
            $issues[] = 'توضیحات متا خالی است.';
        } elseif (mb_strlen($desc) > 160 || mb_strlen($desc) < 120) {
            $issues[] = 'طول توضیحات متا (' . mb_strlen($desc) . ' کاراکتر) خارج از بازه ۱۲۰ تا ۱۶۰ است.';
        }

        $total_words = 0;
        $focus_hits = 0;
        foreach ($segments as $index => $segment) {
            $words = self::word_count($segment);
            $total_words += $words;
            if ($focus !== '') $focus_hits += mb_substr_count($segment, $focus);
            if ($words > self::LONG_PARAGRAPH_WORDS) {
                $issues[] = 'پاراگراف/قطعه ' . ($index + 1) . ' بسیار طولانی است (' . $words . ' واژه).';
            }
        }
        if ($focus !== '' && $total_words > 0) {
            $density = $focus_hits * max(1, self::word_count($focus)) * 100 / $total_words;
            if ($focus_hits === 0) {
                $issues[] = 'عبارت کلیدی در متن اصلی استفاده نشده است.';
            } elseif ($density > self::MAX_DENSITY_PERCENT) {
                $issues[] = 'چگالی عبارت کلیدی حدود ' . round($density, 1) . '٪ است و بیش از حد مجاز ۲٫۵٪ است.';
            }
        }
        return $issues;
    }

    private static function word_count($text) {
        $words = preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        return $words ? count($words) : 0;
    }
}
