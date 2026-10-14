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

    private static function kind_available($kind) {
        if ($kind === self::KIND_PRODUCT) return post_type_exists(self::product_post_type());
        if ($kind === self::KIND_CATEGORY) return taxonomy_exists(self::category_taxonomy());
        return true;
    }

    /**
     * فهرست محصولات یا دسته‌بندی‌ها برای انتخاب در رابط کاربری
     */
    public static function list_targets($kind, $search = '') {
        if (!self::is_target_kind($kind) || !self::kind_available($kind)) {
            return new WP_Error('target_kind_unavailable', 'ووکامرس یا نوع محتوای درخواستی در این سایت فعال نیست.', array('status' => 400));
        }

        $items = array();
        if ($kind === self::KIND_PRODUCT) {
            $args = array(
                'post_type'      => self::product_post_type(),
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => 300,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
            );
            if ($search !== '') $args['s'] = $search;
            foreach (get_posts($args) as $post_id) {
                $items[] = array('id' => (int) $post_id, 'title' => get_the_title($post_id), 'url' => get_permalink($post_id), 'status' => get_post_status($post_id));
            }
            return $items;
        }

        $terms = get_terms(array('taxonomy' => self::category_taxonomy(), 'hide_empty' => false, 'number' => 300, 'search' => $search, 'orderby' => 'name'));
        if (is_wp_error($terms)) return $terms;
        foreach ($terms as $term) {
            $link = get_term_link($term);
            $items[] = array('id' => (int) $term->term_id, 'title' => self::term_path_label($term), 'url' => is_wp_error($link) ? '' : $link, 'status' => 'publish');
        }
        return $items;
    }

    private static function term_path_label($term) {
        $names = array($term->name);
        $parent = (int) $term->parent;
        $guard = 0;
        while ($parent && $guard++ < 5) {
            $parent_term = get_term($parent, $term->taxonomy);
            if (!$parent_term || is_wp_error($parent_term)) break;
            array_unshift($names, $parent_term->name);
            $parent = (int) $parent_term->parent;
        }
        return implode(' › ', $names);
    }

    /**
     * داده‌های واقعی مقصد که به هوش مصنوعی داده می‌شود تا چیزی ساخته نشود
     */
    public static function get_context($kind, $id) {
        $id = absint($id);
        if (!$id || !self::is_target_kind($kind) || !self::kind_available($kind)) {
            return new WP_Error('invalid_target', 'صفحه مقصد انتخاب‌شده معتبر نیست.', array('status' => 400));
        }
        return $kind === self::KIND_PRODUCT ? self::product_context($id) : self::category_context($id);
    }

    private static function plain($html, $words) {
        return wp_strip_all_tags(wp_trim_words(strip_shortcodes((string) $html), $words, '…'));
    }

    private static function product_context($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::product_post_type()) {
            return new WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $context = array(
            'kind'              => self::KIND_PRODUCT,
            'id'                => $id,
            'title'             => get_the_title($id),
            'url'               => get_permalink($id),
            'short_description' => self::plain($post->post_excerpt, 80),
            'current_content'   => self::plain($post->post_content, 120),
            'parent_categories' => self::product_parent_categories($id),
            'related_products'  => self::related_products($id),
        );
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($id);
            if ($product) {
                if ($product->get_sku()) $context['sku'] = $product->get_sku();
                if ($product->get_price() !== '') $context['price'] = wp_strip_all_tags(html_entity_decode(wc_price($product->get_price())));
                $attributes = array();
                foreach ($product->get_attributes() as $attribute) {
                    if (!is_object($attribute) || !method_exists($attribute, 'get_name')) continue;
                    $name  = $attribute->get_name();
                    $value = $product->get_attribute($name);
                    if ($value !== '') $attributes[wc_attribute_label($name)] = $value;
                }
                if ($attributes) $context['attributes'] = $attributes;
            }
        }
        return $context;
    }

    /**
     * دسته‌بندی‌های والد محصول؛ فقط عمیق‌ترین دسته‌ها (آن‌هایی که والد دسته دیگری از همین محصول نیستند)
     */
    private static function product_parent_categories($id) {
        $terms = get_the_terms($id, self::category_taxonomy());
        if (!is_array($terms)) return array();
        $parent_ids = array_map('intval', wp_list_pluck($terms, 'parent'));
        $categories = array();
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $parent_ids, true) && count($terms) > 1) continue;
            $link = get_term_link($term);
            if (!is_wp_error($link)) $categories[] = array('name' => $term->name, 'url' => $link);
        }
        return array_slice($categories, 0, 3);
    }

    public static function related_products($id, $limit = 5) {
        $ids = array();
        if (function_exists('wc_get_related_products')) {
            $ids = wc_get_related_products($id, $limit);
        } else {
            $term_ids = wp_get_post_terms($id, self::category_taxonomy(), array('fields' => 'ids'));
            if (!is_wp_error($term_ids) && $term_ids) {
                $ids = get_posts(array(
                    'post_type'      => self::product_post_type(),
                    'post_status'    => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in'   => array($id),
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'tax_query'      => array(array('taxonomy' => self::category_taxonomy(), 'field' => 'term_id', 'terms' => $term_ids)),
                ));
            }
        }
        $related = array();
        foreach ($ids as $related_id) {
            if (get_post_status($related_id) !== 'publish') continue;
            $related[] = array('title' => get_the_title($related_id), 'url' => get_permalink($related_id));
        }
        return $related;
    }

    private static function category_context($id) {
        $term = get_term($id, self::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        $link = get_term_link($term);
        $children = get_terms(array('taxonomy' => self::category_taxonomy(), 'parent' => $id, 'hide_empty' => false, 'number' => 20));
        $products = get_posts(array(
            'post_type'      => self::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'fields'         => 'ids',
            'tax_query'      => array(array('taxonomy' => self::category_taxonomy(), 'field' => 'term_id', 'terms' => $id)),
        ));
        $context = array(
            'kind'            => self::KIND_CATEGORY,
            'id'              => $id,
            'title'           => $term->name,
            'url'             => is_wp_error($link) ? '' : $link,
            'current_content' => self::plain($term->description, 120),
            'product_count'   => (int) $term->count,
            'sample_products' => array_map('get_the_title', $products),
            'subcategories'   => is_wp_error($children) ? array() : array_values(array_filter(array_map(function ($child) {
                $child_link = get_term_link($child);
                return is_wp_error($child_link) ? null : array('name' => $child->name, 'url' => $child_link);
            }, $children))),
        );
        if ($term->parent) {
            $parent = get_term($term->parent, self::category_taxonomy());
            $parent_link = $parent && !is_wp_error($parent) ? get_term_link($parent) : null;
            if ($parent_link && !is_wp_error($parent_link)) $context['parent_category'] = array('name' => $parent->name, 'url' => $parent_link);
        }
        return $context;
    }

    /**
     * جاگذاری محتوای تولیدشده در صفحه مقصد؛ نسخه قبلی برای بازگردانی نگه داشته می‌شود
     */
    public static function apply($kind, $id, $data) {
        $id = absint($id);
        $content = isset($data['content']) ? wp_kses_post($data['content']) : '';
        if (!$id || !self::is_target_kind($kind) || !self::kind_available($kind) || trim($content) === '') {
            return new WP_Error('missing_apply_content', 'مقصد و محتوای انتشار الزامی هستند.', array('status' => 400));
        }

        $seo = array(
            'title'    => isset($data['seo_title']) ? sanitize_text_field($data['seo_title']) : '',
            'desc'     => isset($data['meta_description']) ? sanitize_textarea_field($data['meta_description']) : '',
            'focuskw'  => isset($data['focus_keyword']) ? sanitize_text_field($data['focus_keyword']) : '',
            'synonyms' => isset($data['keyword_synonyms']) ? sanitize_text_field($data['keyword_synonyms']) : '',
        );

        $mode = isset($data['mode']) ? sanitize_key($data['mode']) : 'replace';
        if ($kind === self::KIND_PRODUCT) return self::apply_to_product($id, $content, $seo, $mode);
        return self::apply_to_category($id, $content, $seo);
    }

    /**
     * ذخیره متای سئو محصول در یوست و رنک‌مث (فقط مقادیر غیرخالی)
     */
    public static function write_post_seo($id, $seo) {
        $focus_all = trim($seo['focuskw'] . ($seo['synonyms'] !== '' ? ', ' . $seo['synonyms'] : ''), ', ');
        $meta = array(
            '_yoast_wpseo_title'           => $seo['title'],
            '_yoast_wpseo_metadesc'        => $seo['desc'],
            '_yoast_wpseo_focuskw'         => $seo['focuskw'],
            '_yoast_wpseo_keywordsynonyms' => $seo['synonyms'],
            'rank_math_title'              => $seo['title'],
            'rank_math_description'        => $seo['desc'],
            'rank_math_focus_keyword'      => $focus_all,
        );
        foreach ($meta as $meta_key => $value) {
            if ($value !== '') update_post_meta($id, $meta_key, $value);
        }
    }

    /**
     * ذخیره محتوا به‌صورت پیش‌نویس بازبینی (نسخه جدید از همان نوع محتوا)؛ صفحه زنده دست‌نخورده می‌ماند
     */
    public static function create_review_draft($id, $content, $seo) {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::product_post_type()) {
            return new WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || !current_user_can($type->cap->create_posts)) {
            return new WP_Error('cannot_create_draft', 'اجازه ایجاد پیش‌نویس را ندارید.', array('status' => 403));
        }
        $draft_id = wp_insert_post(array('post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => '[بازبینی] ' . $post->post_title, 'post_content' => $content), true);
        if (is_wp_error($draft_id)) return $draft_id;
        update_post_meta($draft_id, '_wila_source_product', $id);
        self::write_post_seo($draft_id, $seo);
        return array('target_id' => $draft_id, 'edit_url' => get_edit_post_link($draft_id, 'raw'), 'view_url' => '');
    }

    private static function apply_to_product($id, $content, $seo, $mode = 'replace') {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::product_post_type()) {
            return new WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('cannot_edit_target', 'اجازه ویرایش این محصول را ندارید.', array('status' => 403));
        }
        if ($mode === 'draft') return self::create_review_draft($id, $content, $seo);
        update_post_meta($id, '_wila_previous_content', $post->post_content);
        $new_content = $mode === 'append' ? $post->post_content . "\n\n" . $content : $content;
        $result = wp_update_post(array('ID' => $id, 'post_content' => $new_content), true);
        if (is_wp_error($result)) return $result;

        self::write_post_seo($id, $seo);
        return array('target_id' => $id, 'edit_url' => get_edit_post_link($id, 'raw'), 'view_url' => get_permalink($id));
    }

    private static function apply_to_category($id, $content, $seo) {
        $term = get_term($id, self::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_term', $id)) {
            return new WP_Error('cannot_edit_target', 'اجازه ویرایش این دسته‌بندی را ندارید.', array('status' => 403));
        }
        update_term_meta($id, '_wila_previous_description', $term->description);
        $result = wp_update_term($id, self::category_taxonomy(), array('description' => $content));
        if (is_wp_error($result)) return $result;

        // Yoast keeps taxonomy SEO data in a single option keyed by taxonomy and term id.
        if (defined('WPSEO_VERSION')) {
            $option = get_option('wpseo_taxonomy_meta', array());
            if (!is_array($option)) $option = array();
            $keys = array('title' => 'wpseo_title', 'desc' => 'wpseo_desc', 'focuskw' => 'wpseo_focuskw', 'synonyms' => 'wpseo_keywordsynonyms');
            foreach ($keys as $key => $meta_key) {
                if ($seo[$key] !== '') $option[self::category_taxonomy()][$id][$meta_key] = $seo[$key];
            }
            update_option('wpseo_taxonomy_meta', $option);
        }
        $link = get_term_link($term);
        return array('target_id' => $id, 'edit_url' => get_edit_term_link($id, self::category_taxonomy(), self::product_post_type()), 'view_url' => is_wp_error($link) ? '' : $link);
    }
}
