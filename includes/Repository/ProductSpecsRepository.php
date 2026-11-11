<?php
/**
 * Data access for the "quick product attributes manager": reading / saving the admin-curated
 * technical specs (sole, upper leather, lining, stitching) that the AI prompt must treat as ground
 * truth, and the paginated/search product list for the admin table.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductSpecsRepository {

    /**
     * فقط فیلدهای مجاز و غیرخالی؛ کلید => برچسب در Settings::PRODUCT_SPEC_FIELDS تعیین شده است
     *
     * @return array<string,string>
     */
    public function get($product_id) {
        $product_id = absint($product_id);
        if (!$product_id) return array();
        $stored = get_post_meta($product_id, Settings::META_PRODUCT_CUSTOM_SPECS, true);
        $stored = is_array($stored) ? $stored : array();
        $specs = array();
        foreach (Settings::PRODUCT_SPEC_FIELDS as $key => $label) {
            $value = isset($stored[$key]) ? trim((string) $stored[$key]) : '';
            if ($value !== '') $specs[$key] = $value;
        }
        return $specs;
    }

    /**
     * Features borrowed from similar products, only for the fields this product has no real value for.
     *
     * @return array<string,string>
     */
    public function get_inferred($product_id) {
        $product_id = absint($product_id);
        if (!$product_id) return array();
        $stored = get_post_meta($product_id, Settings::META_PRODUCT_INFERRED_SPECS, true);
        $stored = is_array($stored) && isset($stored['specs']) && is_array($stored['specs']) ? $stored['specs'] : array();
        $real = $this->get($product_id);
        $out = array();
        foreach (Settings::PRODUCT_SPEC_FIELDS as $key => $label) {
            if (!isset($real[$key]) && !empty($stored[$key])) $out[$key] = trim((string) $stored[$key]);
        }
        return $out;
    }

    /**
     * ذخیره سریع ایجکس؛ فقط فیلدهای تعریف‌شده و غیرخالی نگه داشته می‌شوند
     *
     * @return array|\WP_Error مشخصات ذخیره‌شده
     */
    public function save($product_id, array $values) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== Settings::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $product_id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این محصول را ندارید.', array('status' => 403));
        }
        $clean = array();
        foreach (Settings::PRODUCT_SPEC_FIELDS as $key => $label) {
            $value = isset($values[$key]) ? trim((string) $values[$key]) : '';
            if ($value !== '') $clean[$key] = $value;
        }
        if ($clean) {
            update_post_meta($product_id, Settings::META_PRODUCT_CUSTOM_SPECS, $clean);
        } else {
            delete_post_meta($product_id, Settings::META_PRODUCT_CUSTOM_SPECS);
        }
        return $clean;
    }

    /**
     * فهرست صفحه‌بندی‌شده/جستجوپذیر محصولات برای جدول مدیریت ویژگی‌ها
     *
     * @param array $args search, page, per_page
     * @return array{items:array,has_more:bool,total:int}
     */
    public function list_products(array $args) {
        $search   = isset($args['search']) ? (string) $args['search'] : '';
        $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $per_page = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 20;

        $query_args = array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        if ($search !== '') $query_args['s'] = $search;

        $query = new \WP_Query($query_args);
        $items = array();
        foreach ($query->posts as $post) {
            $items[] = array(
                'id'         => (int) $post->ID,
                'title'      => get_the_title($post),
                'thumbnail'  => (string) get_the_post_thumbnail_url($post, 'thumbnail'),
                'categories' => $this->category_names((int) $post->ID),
                'specs'      => $this->get((int) $post->ID),
            );
        }
        return array(
            'items'    => $items,
            'has_more' => $page < (int) $query->max_num_pages,
            'total'    => (int) $query->found_posts,
        );
    }

    private function category_names($product_id) {
        $terms = get_the_terms($product_id, Settings::category_taxonomy());
        if (!is_array($terms)) return array();
        return array_values(array_map(function ($term) { return $term->name; }, $terms));
    }
}
