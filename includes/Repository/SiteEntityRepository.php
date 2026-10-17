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

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb = null) {
        $this->wpdb = $wpdb !== null ? $wpdb : (isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null);
    }

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

    /**
     * Every published product id (ids only; the global link pool needs them all, hydrating happens later for a shortlist).
     *
     * @return int[]
     */
    public function published_product_ids($exclude_id = 0, $limit = 5000) {
        $ids = get_posts(array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'post__not_in'   => $exclude_id ? array((int) $exclude_id) : array(),
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
        return array_map('intval', (array) $ids);
    }

    /**
     * Published post ids of one post type (blog articles for the article link pool).
     *
     * @return int[]
     */
    public function published_post_ids($post_type, $limit = 2000) {
        $ids = get_posts(array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));
        return array_map('intval', (array) $ids);
    }

    /**
     * Published products whose title contains one of the given words (the planner re-checks the title, WP search also scans content).
     *
     * @param string[] $tokens
     * @return int[]
     */
    public function product_ids_matching_title(array $tokens, $limit = 60) {
        $ids = array();
        foreach (array_slice(array_values(array_filter($tokens)), 0, 4) as $token) {
            $found = get_posts(array(
                'post_type'      => Settings::product_post_type(),
                'post_status'    => 'publish',
                's'              => $token,
                'posts_per_page' => (int) $limit,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));
            $ids = array_merge($ids, array_map('intval', (array) $found));
        }
        return array_values(array_unique($ids));
    }

    // ---- lightweight content statistics (aggregate queries, no per-row loops) ----

    /**
     * Content coverage counters for products, articles and categories.
     * A page "has content" when its text is not blank (tags-only content such as <p></p> counts as content).
     *
     * @return array{products:array,articles:array,categories:array}
     */
    public function content_stats() {
        $product_type = Settings::product_post_type();
        $article_types = array('post');
        $blog_type = apply_filters(Settings::FILTER_BLOG_POST_TYPE, 'blog');
        if ($blog_type && post_type_exists($blog_type)) {
            $article_types[] = $blog_type;
        }
        $article_types = array_values(array_diff(array_unique($article_types), array($product_type)));

        $product_terms = $this->term_description_counts(Settings::category_taxonomy());
        $article_terms = $this->term_description_counts('category');
        if (Settings::category_taxonomy() === 'category') {
            $article_terms = array('total' => 0, 'with_description' => 0, 'empty' => 0);
        }

        return array(
            'products'   => $this->post_content_counts(array($product_type)),
            'articles'   => $this->post_content_counts($article_types),
            'categories' => array(
                'total'            => $product_terms['total'] + $article_terms['total'],
                'with_description' => $product_terms['with_description'] + $article_terms['with_description'],
                'empty'            => $product_terms['empty'] + $article_terms['empty'],
                'product'          => $product_terms,
                'article'          => $article_terms,
            ),
        );
    }

    /**
     * @param string[] $post_types
     * @return array{total:int,has_content:int,empty_content:int}
     */
    private function post_content_counts(array $post_types) {
        if (!$post_types) {
            return array('total' => 0, 'has_content' => 0, 'empty_content' => 0);
        }
        $in = implode(',', array_fill(0, count($post_types), '%s'));
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN TRIM(post_content) = '' THEN 1 ELSE 0 END), 0) AS empty_content FROM {$this->wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})",
            $post_types
        ), ARRAY_A);
        $total = isset($row['total']) ? (int) $row['total'] : 0;
        $empty = isset($row['empty_content']) ? (int) $row['empty_content'] : 0;
        return array('total' => $total, 'has_content' => $total - $empty, 'empty_content' => $empty);
    }

    /**
     * @return array{total:int,with_description:int,empty:int}
     */
    private function term_description_counts($taxonomy) {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN TRIM(description) = '' THEN 1 ELSE 0 END), 0) AS empty_count FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = %s",
            $taxonomy
        ), ARRAY_A);
        $total = isset($row['total']) ? (int) $row['total'] : 0;
        $empty = isset($row['empty_count']) ? (int) $row['empty_count'] : 0;
        return array('total' => $total, 'with_description' => $total - $empty, 'empty' => $empty);
    }

    private function category_entry($term) {
        $link = get_term_link($term);
        return array('id' => (int) $term->term_id, 'name' => $term->name, 'url' => is_wp_error($link) ? '' : $link, 'parent' => (int) $term->parent);
    }
}
