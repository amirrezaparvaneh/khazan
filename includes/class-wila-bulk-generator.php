<?php
/**
 * تولید محتوای انبوه محصولات از ردیف‌های شیت: تطبیق با محصولات سایت و پردازش تک‌به‌تک
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Bulk_Generator {

    const GENERATED_META = \Khazan\Services\Csv\ProductMatcher::GENERATED_META;

    public static function normalize($text) {
        return \Khazan\Services\Csv\TextNormalizer::normalize($text);
    }

    public static function normalize_slug($slug) {
        return \Khazan\Services\Csv\TextNormalizer::normalize_slug($slug);
    }

    public static function match_rows($rows, $file_name = '') {
        return \Khazan\Services\Csv\ProductMatcher::match_rows($rows, $file_name);
    }

    private static function get_generated_info($product_id) {
        return \Khazan\Services\Csv\ProductMatcher::generated_info($product_id);
    }

    /**
     * پردازش یک محصول؛ به Khazan\Services\Csv\BulkRowProcessor تفویض می‌شود
     */
    public static function process($product_id, $raw_row, $options) {
        return (new \Khazan\Services\Csv\BulkRowProcessor())->process($product_id, $raw_row, $options);
    }
}
