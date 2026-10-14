<?php
/**
 * لایه ارجاع‌دهنده به Khazan\Services\Seo\SeoMetaService (امضای متدهای عمومی قبلی حفظ شده است)
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Seo_Meta {

    public static function split_keywords($raw, $exclude = '') {
        return \Khazan\Services\Seo\SeoMetaService::split_keywords($raw, $exclude);
    }

    public static function yoast_synonyms_json($synonyms) {
        return \Khazan\Services\Seo\SeoMetaService::yoast_synonyms_json($synonyms);
    }

    public static function yoast_related_json($synonyms) {
        return \Khazan\Services\Seo\SeoMetaService::yoast_related_json($synonyms);
    }

    public static function parse_yoast_synonyms($json) {
        return \Khazan\Services\Seo\SeoMetaService::parse_yoast_synonyms($json);
    }

    public static function post_meta_map($seo) {
        return \Khazan\Services\Seo\SeoMetaService::post_meta_map($seo);
    }

    public static function term_meta_map($seo) {
        return \Khazan\Services\Seo\SeoMetaService::term_meta_map($seo);
    }
}
