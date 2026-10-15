<?php
/**
 * Central configuration: option keys, meta keys, filter names, network limits and the
 * content-kind / target settings that used to live in WILA_Content_Targets.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings {

    // ---- WordPress option keys ----
    const OPT_AVALAI_API_KEY             = 'wila_avalai_api_key';
    const OPT_AI_PROVIDER                = 'wila_ai_provider';
    const OPT_HOMEPAGE_KEYWORDS          = 'wila_homepage_keywords';
    const OPT_PRODUCT_POST_TYPE          = 'wila_product_post_type';
    const OPT_PRODUCT_CATEGORY_TAXONOMY  = 'wila_product_category_taxonomy';
    const OPT_DB_VERSION                 = 'wila_db_version';
    const OPT_DB_INSTALLED_VERSION_LEGACY = 'wila_db_installed_version';

    /** Third-party (Yoast) option holding taxonomy SEO data. */
    const OPT_YOAST_TAXONOMY_META = 'wpseo_taxonomy_meta';

    // ---- post / term meta keys ----
    const META_CSV_GENERATED         = '_wila_csv_generated_at';
    const META_CSV_FOCUS             = '_wila_csv_focus_keyword';
    const META_PREVIOUS_CONTENT      = '_wila_previous_content';
    const META_PREVIOUS_DESCRIPTION  = '_wila_previous_description';
    const META_SOURCE_PRODUCT        = '_wila_source_product';

    // ---- public filter hooks (names are part of the plugin API) ----
    const FILTER_PRODUCT_POST_TYPE         = 'wila_product_post_type';
    const FILTER_PRODUCT_CATEGORY_TAXONOMY = 'wila_product_category_taxonomy';
    const FILTER_BLOG_POST_TYPE            = 'wila_blog_post_type';
    const FILTER_ANCHOR_TERMS              = 'wila_anchor_terms';
    const FILTER_REFERENCE_URL_TEMPLATES   = 'wila_reference_url_templates';

    // ---- transients ----
    const TRANSIENT_SUGGEST_PREFIX     = 'wila_suggest_v2_';
    const TRANSIENT_REPAIR_LOCK_PREFIX = 'wila_repair_';
    const TRANSIENT_LINK_LEDGER_PREFIX = 'wila_link_ledger_';
    const LINK_LEDGER_TTL              = 21600; // 6 hours

    // ---- database table suffixes (prefix comes from $wpdb) ----
    const TABLE_LINKS           = 'wila_internal_links';
    const TABLE_BULK_ITEMS      = 'wila_bulk_items';
    const TABLE_CONTENT_HISTORY = 'wila_content_history';

    // ---- AI service ----
    const AI_PROVIDER = 'avalai';
    const AI_MODEL    = 'gpt-5.4-mini';
    const AI_ENDPOINT = 'https://api.avalai.ir/v1/chat/completions';

    // ---- network timeouts (seconds) ----
    const TIMEOUT_DEFAULT = 20;
    const TIMEOUT_LONG    = 35;
    const TIMEOUT_BULK    = 180;

    // ---- token ceilings ----
    const TOKENS_SUGGESTION = 2048;
    const TOKENS_GENERATION = 12000;

    // ---- content kinds ----
    const KIND_ARTICLE  = 'article';
    const KIND_PRODUCT  = 'product';
    const KIND_CATEGORY = 'product_cat';

    public static function avalai_api_key() {
        return trim(get_option(self::OPT_AVALAI_API_KEY, ''));
    }

    public static function homepage_keywords() {
        return get_option(self::OPT_HOMEPAGE_KEYWORDS, '');
    }

    /**
     * نوع محتوای محصول: تنظیمات افزونه، سپس فیلتر، سپس product و در نهایت اولین نوع عمومی که نامش شامل product باشد
     */
    public static function product_post_type() {
        $type = sanitize_key(get_option(self::OPT_PRODUCT_POST_TYPE, ''));
        if ($type === '') $type = sanitize_key(apply_filters(self::FILTER_PRODUCT_POST_TYPE, 'product'));
        if (post_type_exists($type)) return $type;
        foreach (get_post_types(array('public' => true), 'names') as $name) {
            if (stripos($name, 'product') !== false) return $name;
        }
        return $type;
    }

    /**
     * تاکسونومی دسته‌بندی محصول: تنظیمات، فیلتر، product_cat، سپس تاکسونومی سلسله‌مراتبی عمومی متصل به نوع محصول
     */
    public static function category_taxonomy() {
        $tax = sanitize_key(get_option(self::OPT_PRODUCT_CATEGORY_TAXONOMY, ''));
        if ($tax === '') $tax = sanitize_key(apply_filters(self::FILTER_PRODUCT_CATEGORY_TAXONOMY, 'product_cat'));
        if (taxonomy_exists($tax)) return $tax;
        foreach (get_object_taxonomies(self::product_post_type(), 'objects') as $object) {
            if ($object->public && $object->hierarchical) return $object->name;
        }
        return $tax;
    }

    public static function normalize_kind($kind) {
        $kind = sanitize_key($kind);
        return in_array($kind, array(self::KIND_PRODUCT, self::KIND_CATEGORY), true) ? $kind : self::KIND_ARTICLE;
    }

    public static function is_target_kind($kind) {
        return $kind === self::KIND_PRODUCT || $kind === self::KIND_CATEGORY;
    }

    public static function kind_label($kind) {
        $labels = array(
            self::KIND_ARTICLE  => 'مقاله',
            self::KIND_PRODUCT  => 'محصول',
            self::KIND_CATEGORY => 'دسته‌بندی محصول',
        );
        return isset($labels[$kind]) ? $labels[$kind] : $labels[self::KIND_ARTICLE];
    }

    public static function kind_available($kind) {
        if ($kind === self::KIND_PRODUCT) return post_type_exists(self::product_post_type());
        if ($kind === self::KIND_CATEGORY) return taxonomy_exists(self::category_taxonomy());
        return true;
    }
}
