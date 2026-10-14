<?php
/**
 * Selective SEO-repair UI inside the post editor and term edit screens (view comes from templates/).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Admin;

use Khazan\Core\View;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoRepairPanel {

    const POST_TYPES = array('post', 'page', 'blog', 'product');
    const TAXONOMIES = array('category', 'product_cat');

    public function register() {
        add_action('add_meta_boxes', array($this, 'meta_boxes'));
        foreach (self::TAXONOMIES as $taxonomy) {
            add_action($taxonomy . '_edit_form_fields', array($this, 'term_panel'));
        }
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }

    public function meta_boxes() {
        foreach (self::POST_TYPES as $type) {
            if (post_type_exists($type)) add_meta_box('wila-seo-repair', 'بررسی و اصلاح خوانایی و سئو', array($this, 'panel'), $type, 'normal', 'default', array('__block_editor_compatible_meta_box' => true));
        }
    }

    public function term_panel() {
        View::render('admin/seo-repair-term-row');
    }

    public function panel() {
        View::render('admin/seo-repair-panel');
    }

    public function assets() {
        $screen = get_current_screen();
        if (!$screen) return;
        $term = $screen->base === 'term' && in_array($screen->taxonomy, self::TAXONOMIES, true);
        $post = $screen->base === 'post' && in_array($screen->post_type, self::POST_TYPES, true);
        if (!$term && !$post) return;
        global $post;
        $id = $term ? absint(isset($_GET['tag_ID']) ? $_GET['tag_ID'] : 0) : ($post instanceof \WP_Post ? $post->ID : 0);
        wp_enqueue_script('wila-seo-repair', WILA_PLUGIN_URL . 'assets/js/wila-seo-repair.js', array('wp-data'), WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . 'assets/js/wila-seo-repair.js'), true);
        wp_localize_script('wila-seo-repair', 'wilaRepair', array('url' => rest_url('wila/v1/seo-repair'), 'nonce' => wp_create_nonce('wp_rest'), 'kind' => $term ? 'term' : 'post', 'id' => (int) $id));
    }
}
