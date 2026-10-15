<?php
/**
 * نگهبان خروجی محتوا: پوشش wrapper سفارشی، لینک خارجی پیش‌فرض و سقف تکرار کلیدواژه کانونی
 * (هماهنگ‌کننده: هر قاعده در Khazan\Services\Content\Guard\* پیاده‌سازی شده و امضای متدهای عمومی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Services\Content\Guard\HeadingGuard;
use Khazan\Services\Content\Guard\KeywordDensityGuard;
use Khazan\Services\Content\Guard\LinkGuard;
use Khazan\Services\Content\Guard\WrapperGuard;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentGuard {

    const WRAPPER_CLASS = WrapperGuard::WRAPPER_CLASS;
    const MAX_KEYWORD_OCCURRENCES = KeywordDensityGuard::MAX_KEYWORD_OCCURRENCES;
    const HEADING_MIN_RATIO = HeadingGuard::HEADING_MIN_RATIO;
    const HEADING_MAX_RATIO = HeadingGuard::HEADING_MAX_RATIO;
    const HEADING_TARGET_RATIO = HeadingGuard::HEADING_TARGET_RATIO;

    public static function wrap($html) {
        return WrapperGuard::wrap($html);
    }

    public static function unwrap($html) {
        return WrapperGuard::unwrap($html);
    }

    public static function ensure_external_link($html, $keyword) {
        return LinkGuard::ensure_external_link($html, $keyword);
    }

    public static function ensure_internal_links($html, $links) {
        return LinkGuard::ensure_internal_links($html, $links);
    }

    public static function enforce_unique_links($html, $links = array(), $max_products = 2, $max_external = 1) {
        return LinkGuard::enforce_unique_links($html, $links, $max_products, $max_external);
    }

    public static function neutralize_competing_links($html, $focus, $links = array(), $synonyms = array()) {
        return LinkGuard::neutralize_competing_links($html, $focus, $links, $synonyms);
    }

    public static function enforce_paragraphs($html, $max = 110) {
        return Guard\ParagraphGuard::enforce($html, $max);
    }

    public static function cap_anchor_length($html, $max = 4) {
        return LinkGuard::cap_anchor_length($html, $max);
    }

    public static function pick_reference_url($term) {
        return LinkGuard::pick_reference_url($term);
    }

    public static function cap_keyword_density($html, $keyword, $synonyms, $max = self::MAX_KEYWORD_OCCURRENCES) {
        return KeywordDensityGuard::cap_keyword_density($html, $keyword, $synonyms, $max);
    }

    public static function enforce_heading_keyphrases($html, $keyword, $synonyms) {
        return HeadingGuard::enforce_heading_keyphrases($html, $keyword, $synonyms);
    }

    public static function enforce_link_anchors($html, $map = array()) {
        return LinkGuard::enforce_link_anchors($html, $map);
    }
}
