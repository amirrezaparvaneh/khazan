<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Content\ContentGuard (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Guard {

    const WRAPPER_CLASS = \Khazan\Services\Content\ContentGuard::WRAPPER_CLASS;

    const MAX_KEYWORD_OCCURRENCES = \Khazan\Services\Content\ContentGuard::MAX_KEYWORD_OCCURRENCES;

    const HEADING_MIN_RATIO = \Khazan\Services\Content\ContentGuard::HEADING_MIN_RATIO;

    const HEADING_MAX_RATIO = \Khazan\Services\Content\ContentGuard::HEADING_MAX_RATIO;

    const HEADING_TARGET_RATIO = \Khazan\Services\Content\ContentGuard::HEADING_TARGET_RATIO;

    public static function wrap($html) {
        return \Khazan\Services\Content\ContentGuard::wrap($html);
    }

    public static function unwrap($html) {
        return \Khazan\Services\Content\ContentGuard::unwrap($html);
    }

    public static function ensure_external_link($html, $keyword) {
        return \Khazan\Services\Content\ContentGuard::ensure_external_link($html, $keyword);
    }

    public static function ensure_internal_links($html, $links) {
        return \Khazan\Services\Content\ContentGuard::ensure_internal_links($html, $links);
    }

    public static function pick_reference_url($term) {
        return \Khazan\Services\Content\ContentGuard::pick_reference_url($term);
    }

    public static function cap_keyword_density($html, $keyword, $synonyms, $max = self::MAX_KEYWORD_OCCURRENCES) {
        return \Khazan\Services\Content\ContentGuard::cap_keyword_density($html, $keyword, $synonyms, $max);
    }

    public static function enforce_heading_keyphrases($html, $keyword, $synonyms) {
        return \Khazan\Services\Content\ContentGuard::enforce_heading_keyphrases($html, $keyword, $synonyms);
    }

    public static function enforce_link_anchors($html, $map = array()) {
        return \Khazan\Services\Content\ContentGuard::enforce_link_anchors($html, $map);
    }
}
