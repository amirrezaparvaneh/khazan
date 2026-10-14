<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Content\HtmlSanitizer (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Html_Sanitizer {

    const BLOCK_TAGS = \Khazan\Services\Content\HtmlSanitizer::BLOCK_TAGS;

    public static function clean($html, $kind) {
        return \Khazan\Services\Content\HtmlSanitizer::clean($html, $kind);
    }

    public static function tables_to_lists($html) {
        return \Khazan\Services\Content\HtmlSanitizer::tables_to_lists($html);
    }

    public static function tidy_whitespace($html) {
        return \Khazan\Services\Content\HtmlSanitizer::tidy_whitespace($html);
    }
}
