<?php
/**
 * Data access for content targets (products and product categories): listing,
 * AI context, current content, applying generated content and review drafts.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Services\Seo\SeoMetaService;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentTargetRepository {

    /** @var SeoMetaService */
    private $seo;

    public function __construct(SeoMetaService $seo = null) {
        $this->seo = $seo ?: new SeoMetaService();
    }

    /**
     * فهرست محصولات یا دسته‌بندی‌ها برای انتخاب در رابط کاربری
     */
    public function list_targets($kind, $search = '') {
        if (!\WILA_Content_Targets::is_target_kind($kind) || !\WILA_Content_Targets::kind_available($kind)) {
            return new \WP_Error('target_kind_unavailable', 'ووکامرس یا نوع محتوای درخواستی در این سایت فعال نیست.', array('status' => 400));
        }

        $items = array();
        if ($kind === \WILA_Content_Targets::KIND_PRODUCT) {
            $args = array(
                'post_type'      => \WILA_Content_Targets::product_post_type(),
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

        $terms = get_terms(array('taxonomy' => \WILA_Content_Targets::category_taxonomy(), 'hide_empty' => false, 'number' => 300, 'search' => $search, 'orderby' => 'name'));
        if (is_wp_error($terms)) return $terms;
        foreach ($terms as $term) {
            $link = get_term_link($term);
            $items[] = array('id' => (int) $term->term_id, 'title' => $this->term_path_label($term), 'url' => is_wp_error($link) ? '' : $link, 'status' => 'publish');
        }
        return $items;
    }

    private function term_path_label($term) {
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
    public function get_context($kind, $id) {
        $id = absint($id);
        if (!$id || !\WILA_Content_Targets::is_target_kind($kind) || !\WILA_Content_Targets::kind_available($kind)) {
            return new \WP_Error('invalid_target', 'صفحه مقصد انتخاب‌شده معتبر نیست.', array('status' => 400));
        }
        return $kind === \WILA_Content_Targets::KIND_PRODUCT ? $this->product_context($id) : $this->category_context($id);
    }

    private function plain($html, $words) {
        return wp_strip_all_tags(wp_trim_words(strip_shortcodes((string) $html), $words, '…'));
    }

    private function product_context($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== \WILA_Content_Targets::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $context = array(
            'kind'              => \WILA_Content_Targets::KIND_PRODUCT,
            'id'                => $id,
            'title'             => get_the_title($id),
            'url'               => get_permalink($id),
            'short_description' => $this->plain($post->post_excerpt, 80),
            'current_content'   => $this->plain($post->post_content, 120),
            'parent_categories' => $this->product_parent_categories($id),
            'related_products'  => $this->related_products($id),
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
    private function product_parent_categories($id) {
        $terms = get_the_terms($id, \WILA_Content_Targets::category_taxonomy());
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

    public function related_products($id, $limit = 5) {
        $ids = array();
        if (function_exists('wc_get_related_products')) {
            $ids = wc_get_related_products($id, $limit * 3);
        } else {
            $term_ids = wp_get_post_terms($id, \WILA_Content_Targets::category_taxonomy(), array('fields' => 'ids'));
            if (!is_wp_error($term_ids) && $term_ids) {
                $ids = get_posts(array(
                    'post_type'      => \WILA_Content_Targets::product_post_type(),
                    'post_status'    => 'publish',
                    'posts_per_page' => $limit * 3,
                    'post__not_in'   => array($id),
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'tax_query'      => array(array('taxonomy' => \WILA_Content_Targets::category_taxonomy(), 'field' => 'term_id', 'terms' => $term_ids)),
                ));
            }
        }
        $related = array();
        foreach ($ids as $related_id) {
            if (get_post_status($related_id) !== 'publish') continue;
            // انکرتکست لینک محصول فقط کلیدواژه کانونی مقصد است؛ محصول بدون کلیدواژه کنار گذاشته می‌شود.
            $anchor = \WILA_Content_Targets::product_link_anchor($related_id);
            if ($anchor === '') continue;
            $related[] = array(
                'title'  => get_the_title($related_id),
                'anchor' => $anchor,
                'url'    => get_permalink($related_id),
            );
            if (count($related) >= $limit) break;
        }
        return $related;
    }

    private function category_context($id) {
        $term = get_term($id, \WILA_Content_Targets::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        $link = get_term_link($term);
        $children = get_terms(array('taxonomy' => \WILA_Content_Targets::category_taxonomy(), 'parent' => $id, 'hide_empty' => false, 'number' => 20));
        $products = get_posts(array(
            'post_type'      => \WILA_Content_Targets::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'fields'         => 'ids',
            'tax_query'      => array(array('taxonomy' => \WILA_Content_Targets::category_taxonomy(), 'field' => 'term_id', 'terms' => $id)),
        ));
        $context = array(
            'kind'            => \WILA_Content_Targets::KIND_CATEGORY,
            'id'              => $id,
            'title'           => $term->name,
            'url'             => is_wp_error($link) ? '' : $link,
            'current_content' => $this->plain($term->description, 120),
            'product_count'   => (int) $term->count,
            'sample_products' => array_map('get_the_title', $products),
            'subcategories'   => is_wp_error($children) ? array() : array_values(array_filter(array_map(function ($child) {
                $child_link = get_term_link($child);
                return is_wp_error($child_link) ? null : array('name' => $child->name, 'url' => $child_link);
            }, $children))),
        );
        if ($term->parent) {
            $parent = get_term($term->parent, \WILA_Content_Targets::category_taxonomy());
            $parent_link = $parent && !is_wp_error($parent) ? get_term_link($parent) : null;
            if ($parent_link && !is_wp_error($parent_link)) $context['parent_category'] = array('name' => $parent->name, 'url' => $parent_link);
        }
        return $context;
    }

    /**
     * محتوای فعلی صفحه مقصد به‌همراه متاهای سئوی آن؛ ورودی رفع خطای سئو و نمایش در پنل
     */
    public function current_content($kind, $id) {
        $id = absint($id);
        if (!$id || !\WILA_Content_Targets::is_target_kind($kind) || !\WILA_Content_Targets::kind_available($kind)) {
            return new \WP_Error('invalid_target', 'صفحه مقصد انتخاب‌شده معتبر نیست.', array('status' => 400));
        }
        return $kind === \WILA_Content_Targets::KIND_PRODUCT ? $this->current_product($id) : $this->current_category($id);
    }

    private function current_product($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== \WILA_Content_Targets::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $seo = $this->seo->read_post_seo($id);
        return array(
            'title'   => get_the_title($id),
            'url'     => get_permalink($id),
            'content' => $post->post_content,
            'seo'     => $seo,
        );
    }

    private function current_category($id) {
        $term = get_term($id, \WILA_Content_Targets::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        $link = get_term_link($term);
        return array(
            'title'   => $term->name,
            'url'     => is_wp_error($link) ? '' : $link,
            'content' => $term->description,
            'seo'     => $this->seo->read_term_seo(\WILA_Content_Targets::category_taxonomy(), $id),
        );
    }

    /**
     * جاگذاری محتوای تولیدشده در صفحه مقصد؛ نسخه قبلی برای بازگردانی نگه داشته می‌شود
     */
    public function apply($kind, $id, $data) {
        $id = absint($id);
        $content = isset($data['content']) ? \WILA_Content_Guard::wrap(wp_kses_post($data['content'])) : '';
        if (!$id || !\WILA_Content_Targets::is_target_kind($kind) || !\WILA_Content_Targets::kind_available($kind) || trim($content) === '') {
            return new \WP_Error('missing_apply_content', 'مقصد و محتوای انتشار الزامی هستند.', array('status' => 400));
        }

        $seo = array(
            'title'    => isset($data['seo_title']) ? sanitize_text_field($data['seo_title']) : '',
            'desc'     => isset($data['meta_description']) ? sanitize_textarea_field($data['meta_description']) : '',
            'focuskw'  => isset($data['focus_keyword']) ? sanitize_text_field($data['focus_keyword']) : '',
            'synonyms' => isset($data['keyword_synonyms']) ? sanitize_text_field($data['keyword_synonyms']) : '',
        );

        $mode = isset($data['mode']) ? sanitize_key($data['mode']) : 'replace';
        if ($kind === \WILA_Content_Targets::KIND_PRODUCT) return $this->apply_to_product($id, $content, $seo, $mode);
        return $this->apply_to_category($id, $content, $seo);
    }

    /**
     * ذخیره متای سئو محصول در یوست و رنک‌مث (فقط مقادیر غیرخالی)
     */
    public function write_post_seo($id, $seo) {
        $this->seo->write_post_seo($id, $seo);
    }

    /**
     * ذخیره محتوا به‌صورت پیش‌نویس بازبینی (نسخه جدید از همان نوع محتوا)؛ صفحه زنده دست‌نخورده می‌ماند
     */
    public function create_review_draft($id, $content, $seo) {
        $post = get_post($id);
        if (!$post || $post->post_type !== \WILA_Content_Targets::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || !current_user_can($type->cap->create_posts)) {
            return new \WP_Error('cannot_create_draft', 'اجازه ایجاد پیش‌نویس را ندارید.', array('status' => 403));
        }
        $draft_id = wp_insert_post(array('post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => '[بازبینی] ' . $post->post_title, 'post_content' => $content), true);
        if (is_wp_error($draft_id)) return $draft_id;
        update_post_meta($draft_id, '_wila_source_product', $id);
        $this->write_post_seo($draft_id, $seo);
        return array('target_id' => $draft_id, 'edit_url' => get_edit_post_link($draft_id, 'raw'), 'view_url' => '');
    }

    private function apply_to_product($id, $content, $seo, $mode = 'replace') {
        $post = get_post($id);
        if (!$post || $post->post_type !== \WILA_Content_Targets::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این محصول را ندارید.', array('status' => 403));
        }
        if ($mode === 'draft') return $this->create_review_draft($id, $content, $seo);
        update_post_meta($id, '_wila_previous_content', $post->post_content);
        $new_content = $mode === 'append'
            ? \WILA_Content_Guard::wrap(\WILA_Content_Guard::unwrap($post->post_content) . "\n\n" . \WILA_Content_Guard::unwrap($content))
            : $content;
        $result = wp_update_post(array('ID' => $id, 'post_content' => $new_content), true);
        if (is_wp_error($result)) return $result;

        $this->write_post_seo($id, $seo);
        return array('target_id' => $id, 'edit_url' => get_edit_post_link($id, 'raw'), 'view_url' => get_permalink($id));
    }

    private function apply_to_category($id, $content, $seo) {
        $term = get_term($id, \WILA_Content_Targets::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_term', $id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این دسته‌بندی را ندارید.', array('status' => 403));
        }
        update_term_meta($id, '_wila_previous_description', $term->description);
        $result = wp_update_term($id, \WILA_Content_Targets::category_taxonomy(), array('description' => $content));
        if (is_wp_error($result)) return $result;

        $this->seo->write_term_seo(\WILA_Content_Targets::category_taxonomy(), $id, $seo);
        $link = get_term_link($term);
        return array('target_id' => $id, 'edit_url' => get_edit_term_link($id, \WILA_Content_Targets::category_taxonomy(), \WILA_Content_Targets::product_post_type()), 'view_url' => is_wp_error($link) ? '' : $link);
    }

    public function find_product_id_by_title($name) {
        global $wpdb;
        if ($name === '') return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status IN ('draft', 'pending', 'private', 'publish') ORDER BY ID DESC LIMIT 1",
            \WILA_Content_Targets::product_post_type(),
            $name
        ));
    }

    /**
     * ساخت محصول پیش‌نویس از ردیف شیت (نام و نامک)؛ وردپرس اگر نامک تکراری باشد پسوند عددی می‌دهد
     */
    public function create_draft_product($row) {
        if ($row['name'] === '') return new \WP_Error('missing_product_name', 'برای ساخت محصول جدید، نام محصول در ردیف الزامی است.', array('status' => 400));
        $type = get_post_type_object(\WILA_Content_Targets::product_post_type());
        if (!$type || !current_user_can($type->cap->create_posts)) return new \WP_Error('cannot_create_product', 'اجازه ساخت محصول جدید را ندارید.', array('status' => 403));

        $post_id = wp_insert_post(array(
            'post_type'   => \WILA_Content_Targets::product_post_type(),
            'post_status' => 'draft',
            'post_title'  => $row['name'],
            'post_name'   => $row['slug'] !== '' ? sanitize_title(\Khazan\Services\Csv\TextNormalizer::normalize_slug($row['slug'])) : '',
        ), true);
        return $post_id;
    }

    /**
     * Creates a blog draft (title, content, English slug) and writes its SEO meta.
     *
     * @return int|\WP_Error Post ID on success.
     */
    public function create_blog_draft($title, $content, $slug, array $seo) {
        $blog_post_type = apply_filters('wila_blog_post_type', 'blog');
        if (!post_type_exists($blog_post_type)) {
            return new \WP_Error('blog_post_type_not_found', 'نوع محتوای «وبلاگ» یافت نشد. با فیلتر wila_blog_post_type نام post type وبلاگ را تنظیم کنید.', array('status' => 400));
        }
        if (!current_user_can(get_post_type_object($blog_post_type)->cap->create_posts)) {
            return new \WP_Error('cannot_create_blog_draft', 'اجازه ایجاد پیش‌نویس در بخش وبلاگ را ندارید.', array('status' => 403));
        }
        $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'draft', 'post_type' => $blog_post_type, 'post_name' => $slug), true);
        if (is_wp_error($post_id)) return $post_id;

        $this->write_post_seo($post_id, $seo);
        return $post_id;
    }
}
