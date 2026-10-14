<?php
/**
 * مدیریت مقصدهای تولید محتوا (محصول و دسته‌بندی محصول): فهرست، زمینه برای هوش مصنوعی و انتشار مستقیم
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Content_Targets {

    const KIND_ARTICLE  = 'article';
    const KIND_PRODUCT  = 'product';
    const KIND_CATEGORY = 'product_cat';

    /**
     * نوع محتوای محصول: تنظیمات افزونه، سپس فیلتر، سپس product و در نهایت اولین نوع عمومی که نامش شامل product باشد
     */
    public static function product_post_type() {
        $type = sanitize_key(get_option('wila_product_post_type', ''));
        if ($type === '') $type = sanitize_key(apply_filters('wila_product_post_type', 'product'));
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
        $tax = sanitize_key(get_option('wila_product_category_taxonomy', ''));
        if ($tax === '') $tax = sanitize_key(apply_filters('wila_product_category_taxonomy', 'product_cat'));
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

    const CSV_FOCUS_META = '_wila_csv_focus_keyword';

    /**
     * انکرتکست لینک به یک محصول: دقیقاً کلیدواژه کانونی مقصد، بدون کوتاه‌سازی یا تغییر.
     * ترتیب منبع: RankMath، Yoast، سپس ستون کلیدواژه کانونی شیت CSV که هنگام آپلود ثبت شده است.
     * اگر هیچ‌کدام نبود رشته خالی برمی‌گردد و لینک قفل‌شده ساخته نمی‌شود.
     */
    public static function product_link_anchor($id) {
        $rank = WILA_Seo_Meta::split_keywords((string) get_post_meta($id, 'rank_math_focus_keyword', true));
        if ($rank) return $rank[0];
        foreach (array('_yoast_wpseo_focuskw', self::CSV_FOCUS_META) as $meta_key) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) get_post_meta($id, $meta_key, true)));
            if ($value !== '') return $value;
        }
        return '';
    }

    /**
     * کلیدواژه کانونی ستون شیت را روی محصول ثبت می‌کند تا محصولات دیگر پیش از تولید محتوای آن هم بتوانند با انکر درست به آن لینک دهند
     */
    public static function remember_csv_focus($id, $focus) {
        $focus = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $focus)));
        if (!$id || $focus === '' || get_post_type($id) !== self::product_post_type()) return;
        update_post_meta($id, self::CSV_FOCUS_META, $focus);
    }

    /**
     * شناسه محصولِ یک لینک داخلی؛ برای اصلاح انکرتکست پس از تولید محتوا
     */
    public static function product_id_from_url($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host !== '' && $host !== $site_host) return 0;
        $id = url_to_postid($url);
        return $id && get_post_type($id) === self::product_post_type() ? (int) $id : 0;
    }

    // ---- delegates to Khazan\Repository\ContentTargetRepository (public API kept for legacy callers) ----

    private static function repository() {
        return new \Khazan\Repository\ContentTargetRepository();
    }

    public static function list_targets($kind, $search = '') {
        return self::repository()->list_targets($kind, $search);
    }

    public static function get_context($kind, $id) {
        return self::repository()->get_context($kind, $id);
    }

    public static function related_products($id, $limit = 5) {
        return self::repository()->related_products($id, $limit);
    }

    public static function current_content($kind, $id) {
        return self::repository()->current_content($kind, $id);
    }

    public static function apply($kind, $id, $data) {
        return self::repository()->apply($kind, $id, $data);
    }

    public static function write_post_seo($id, $seo) {
        self::repository()->write_post_seo($id, $seo);
    }

    public static function create_review_draft($id, $content, $seo) {
        return self::repository()->create_review_draft($id, $content, $seo);
    }
}
