<?php
/**
 * Read-only queries for site entities (posts, terms) used as link candidates and targets.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteEntityRepository {

    public function all() {
        $candidates = array();
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        
        $posts = get_posts(array('post_type' => array_values($post_types), 'post_status' => 'publish', 'posts_per_page' => 40));
        foreach ($posts as $p) {
            $candidates[] = array('id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'url' => get_permalink($p->ID));
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_tag'], $taxonomies['nav_menu']);
        $terms = empty($taxonomies) ? array() : get_terms(array('taxonomy' => array_values($taxonomies), 'hide_empty' => false, 'number' => 25));
        
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $candidates[] = array('id' => $t->term_id, 'type' => $t->taxonomy, 'title' => $t->name, 'url' => get_term_link($t));
            }
        }
        return $candidates;
    }

    public function info($id, $type) {
        $post_types = get_post_types(array('public' => true), 'names');
        if (in_array($type, $post_types)) {
            $post = get_post($id);
            if (!$post) return null;
            return array('id' => $id, 'type' => $type, 'title' => $post->post_title, 'url' => get_permalink($id), 'excerpt' => wp_strip_all_tags(wp_trim_words($post->post_content, 50)));
        }
        $term = get_term($id, $type);
        if (!$term || is_wp_error($term)) return null;
        return array('id' => $id, 'type' => $type, 'title' => $term->name, 'url' => get_term_link($term), 'excerpt' => !empty($term->description) ? wp_strip_all_tags($term->description) : 'بدون توضیح');
    }

    // ---- product / category pool used by the link distributor ----

    /**
     * Every term of the product-category taxonomy: id => {id, name, url, parent}.
     *
     * @return array<int,array>
     */
    public function category_tree() {
        $terms = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false));
        $tree = array();
        if (is_wp_error($terms) || !is_array($terms)) {
            return $tree;
        }
        foreach ($terms as $term) {
            $tree[(int) $term->term_id] = $this->category_entry($term);
        }
        return $tree;
    }

    /**
     * @return array|null {id, name, url, parent}
     */
    public function category($term_id) {
        $term = get_term((int) $term_id, Settings::category_taxonomy());
        return ($term && !is_wp_error($term)) ? $this->category_entry($term) : null;
    }

    /**
     * Categories assigned to a product.
     *
     * @return array<int,array>
     */
    public function product_categories($product_id) {
        $terms = get_the_terms((int) $product_id, Settings::category_taxonomy());
        $out = array();
        if (!is_array($terms)) {
            return $out;
        }
        foreach ($terms as $term) {
            $out[(int) $term->term_id] = $this->category_entry($term);
        }
        return $out;
    }

    /**
     * Primary category chosen in Yoast / Rank Math (0 when none is set or it is not assigned to the product).
     */
    public function primary_category_id($product_id, array $assigned_ids) {
        $taxonomy = Settings::category_taxonomy();
        foreach (array('_yoast_wpseo_primary_' . $taxonomy, 'rank_math_primary_' . $taxonomy) as $meta_key) {
            $id = (int) get_post_meta((int) $product_id, $meta_key, true);
            if ($id && in_array($id, $assigned_ids, true)) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Published products inside any of the given categories.
     *
     * @param int[] $term_ids
     * @return int[]
     */
    public function published_product_ids_in_categories(array $term_ids, $exclude_id = 0, $limit = 400) {
        $term_ids = array_values(array_filter(array_map('intval', $term_ids)));
        if (!$term_ids) {
            return array();
        }
        $ids = get_posts(array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'post__not_in'   => $exclude_id ? array((int) $exclude_id) : array(),
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'tax_query'      => array(array('taxonomy' => Settings::category_taxonomy(), 'field' => 'term_id', 'terms' => $term_ids)),
        ));
        return array_map('intval', (array) $ids);
    }

    private function category_entry($term) {
        $link = get_term_link($term);
        return array('id' => (int) $term->term_id, 'name' => $term->name, 'url' => is_wp_error($link) ? '' : $link, 'parent' => (int) $term->parent);
    }
}
