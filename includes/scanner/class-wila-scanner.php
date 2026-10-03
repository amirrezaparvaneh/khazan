<?php
/**
 * موتور استخراج، تفکیک و ایندکس لینک‌های داخلی
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Scanner {

    private $site_url;
    private $site_host;

    public function __construct() {
        $this->site_url  = untrailingslashit(home_url());
        $parsed_url      = wp_parse_url($this->site_url);
        $this->site_host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
    }

    /**
     * دریافت لیست تمام آیتم‌های قابل اسکن (پست‌ها + دسته‌بندی‌های محصول و وبلاگ)
     */
    public function get_all_scannable_items() {
        $items = array();

        // ۱. دریافت مقالات منتشر شده
        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ));

        foreach ($posts as $post_id) {
            $items[] = array(
                'id'    => $post_id,
                'type'  => 'post',
                'title' => get_the_title($post_id),
                'url'   => get_permalink($post_id)
            );
        }

        // ۲. دریافت دسته‌بندی‌های محصولات ووکامرس (در صورت وجود)
        if (taxonomy_exists('product_cat')) {
            $product_cats = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ));

            if (!is_wp_error($product_cats)) {
                foreach ($product_cats as $term) {
                    $items[] = array(
                        'id'    => $term->term_id,
                        'type'  => 'product_cat',
                        'title' => $term->name,
                        'url'   => get_term_link($term)
                    );
                }
            }
        }

        // ۳. دریافت دسته‌بندی‌های استاندارد وردپرس
        $categories = get_terms(array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ));
        if (!is_wp_error($categories)) {
            foreach ($categories as $term) {
                $items[] = array(
                    'id'    => $term->term_id,
                    'type'  => 'category',
                    'title' => $term->name,
                    'url'   => get_term_link($term)
                );
            }
        }

        return $items;
    }

    /**
     * اسکن یک آیتم مشخص و ذخیره روابط لینک‌های آن
     */
    public function scan_item($item_id, $item_type) {
        global $wpdb;
        $table_name = WILA_DB::get_table_name();

        $content = '';
        $title   = '';
        $url     = '';

        if ($item_type === 'post') {
            $post = get_post($item_id);
            if (!$post) return false;
            $content = $post->post_content;
            $title   = $post->post_title;
            $url     = get_permalink($item_id);
        } else {
            // دسته‌بندی‌ها
            $term = get_term($item_id, $item_type);
            if (!$term || is_wp_error($term)) return false;
            $content = $term->description;
            $title   = $term->name;
            $url     = get_term_link($term);
        }

        // حذف رکوردهای قبلی همین منبع
        WILA_DB::delete_source_links($item_id, $item_type);

        if (empty(trim($content))) {
            return array('links_found' => 0);
        }

        // پارس کردن لینک‌ها از محتوا
        $links = $this->extract_links_from_html($content);

        foreach ($links as $link) {
            // فقط لینک‌های داخلی سایت بررسی می‌شوند
            if (!$this->is_internal_url($link['url'])) {
                continue;
            }

            $resolved_target = $this->resolve_url_to_entity($link['url']);

            $wpdb->insert(
                $table_name,
                array(
                    'source_id'     => $item_id,
                    'source_type'   => $item_type,
                    'source_title'  => $title,
                    'source_url'    => $url,
                    'target_url'    => $link['url'],
                    'target_id'     => $resolved_target['id'],
                    'target_type'   => $resolved_target['type'],
                    'anchor_text'   => mb_substr($link['anchor'], 0, 250, 'UTF-8'),
                    'rel_attribute' => $link['rel'],
                    'created_at'    => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        return array('links_found' => count($links));
    }

    /**
     * استخراج لینک‌ها و انکرتکست‌ها با استفاده از DOMDocument و حفظ کاراکترهای یونیکد فارسی
     */
    private function extract_links_from_html($html) {
        $extracted_links = array();
        if (empty(trim($html))) {
            return $extracted_links;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        // پشتیبانی کامل از حروف فارسی و کاراکترهای UTF-8
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tags = $dom->getElementsByTagName('a');

        foreach ($tags as $tag) {
            $href = trim($tag->getAttribute('href'));
            $rel  = trim($tag->getAttribute('rel'));
            $text = trim($tag->textContent);

            if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }

            $extracted_links[] = array(
                'url'    => untrailingslashit($href),
                'anchor' => !empty($text) ? $text : '[بدون انکر تکست / تصویر]',
                'rel'    => $rel
            );
        }

        return $extracted_links;
    }

    /**
     * بررسی تعلق آدرس به دامنه جاری سایت
     */
    private function is_internal_url($url) {
        $parsed = wp_parse_url($url);

        // آدرس‌های نسبی (/shop/cat) قطعا داخلی هستند
        if (empty($parsed['host'])) {
            return true;
        }

        $host = strtolower($parsed['host']);
        return ($host === $this->site_host || strpos($host, '.' . $this->site_host) !== false);
    }

    /**
     * تشخیص هوشمند این که لینک به چه پستی یا دسته‌بندی تعلق دارد
     */
    private function resolve_url_to_entity($url) {
        // نرمال‌سازی URL برای تطابق
        $clean_url = strtok($url, '?');
        $clean_url = strtok($clean_url, '#');

        // بررسی اینکه آیا پست است
        $post_id = url_to_postid($clean_url);
        if ($post_id > 0) {
            return array(
                'id'   => $post_id,
                'type' => get_post_type($post_id)
            );
        }

        // بررسی دسته‌بندی محصول ووکامرس
        if (taxonomy_exists('product_cat')) {
            $term = $this->find_term_by_url($clean_url, 'product_cat');
            if ($term) {
                return array(
                    'id'   => $term->term_id,
                    'type' => 'product_cat'
                );
            }
        }

        // بررسی دسته وبلاگ
        $term = $this->find_term_by_url($clean_url, 'category');
        if ($term) {
            return array(
                'id'   => $term->term_id,
                'type' => 'category'
            );
        }

        return array(
            'id'   => null,
            'type' => 'unknown_internal'
        );
    }

    /**
     * متد کمکی برای یافتن شناسه دسته‌بندی بر اساس آدرس URL
     */
    private function find_term_by_url($url, $taxonomy) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) return null;

        $slugs = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($slugs)) return null;

        $candidate_slug = end($slugs);
        $term = get_term_by('slug', $candidate_slug, $taxonomy);

        if ($term && !is_wp_error($term)) {
            return $term;
        }

        return null;
    }
}