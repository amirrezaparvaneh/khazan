<?php
/**
 * لایه ارجاع‌دهنده: تنظیمات و ثابت‌ها در Khazan\Core\Settings و دسترسی به داده در
 * Khazan\Repository\ContentTargetRepository هستند؛ امضای متدهای عمومی قبلی حفظ شده است.
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Targets {

    const KIND_ARTICLE  = \Khazan\Core\Settings::KIND_ARTICLE;
    const KIND_PRODUCT  = \Khazan\Core\Settings::KIND_PRODUCT;
    const KIND_CATEGORY = \Khazan\Core\Settings::KIND_CATEGORY;

    const CSV_FOCUS_META = \Khazan\Core\Settings::META_CSV_FOCUS;

    private static function repository() {
        return new \Khazan\Repository\ContentTargetRepository();
    }

    public static function product_post_type() { return \Khazan\Core\Settings::product_post_type(); }
    public static function category_taxonomy() { return \Khazan\Core\Settings::category_taxonomy(); }
    public static function normalize_kind($kind) { return \Khazan\Core\Settings::normalize_kind($kind); }
    public static function is_target_kind($kind) { return \Khazan\Core\Settings::is_target_kind($kind); }
    public static function kind_label($kind) { return \Khazan\Core\Settings::kind_label($kind); }
    public static function kind_available($kind) { return \Khazan\Core\Settings::kind_available($kind); }

    public static function list_targets($kind, $search = '') { return self::repository()->list_targets($kind, $search); }
    public static function get_context($kind, $id) { return self::repository()->get_context($kind, $id); }
    public static function related_products($id, $limit = 5) { return self::repository()->related_products($id, $limit); }
    public static function current_content($kind, $id) { return self::repository()->current_content($kind, $id); }
    public static function apply($kind, $id, $data) { return self::repository()->apply($kind, $id, $data); }
    public static function write_post_seo($id, $seo) { self::repository()->write_post_seo($id, $seo); }
    public static function create_review_draft($id, $content, $seo) { return self::repository()->create_review_draft($id, $content, $seo); }

    public static function product_link_anchor($id) { return self::repository()->product_link_anchor($id); }
    public static function remember_csv_focus($id, $focus) { self::repository()->remember_csv_focus($id, $focus); }
    public static function product_id_from_url($url) { return self::repository()->product_id_from_url($url); }
}
