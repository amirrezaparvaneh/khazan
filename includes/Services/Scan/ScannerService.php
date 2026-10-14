<?php
/**
 * موتور استخراج، تفکیک و ایندکس لینک‌های داخلی (با پشتیبانی از Custom Post Types)
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Scan;

use Khazan\Repository\DatabaseRepository;
use Khazan\Repository\LinkRepository;
use Khazan\Services\Links\OrphanFinder;

if (!defined('ABSPATH')) {
    exit;
}

final class ScannerService {

    private $site_url;
    private $site_host;

    /** @var DatabaseRepository */
    private $database;

    /** @var LinkRepository */
    private $links;

    public function __construct(DatabaseRepository $database = null, LinkRepository $links = null) {
        $this->database  = $database ?: new DatabaseRepository();
        $this->links     = $links ?: new LinkRepository();
        $this->site_url  = untrailingslashit(home_url());
        $parsed_url      = wp_parse_url($this->site_url);
        $this->site_host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
    }

    public function get_all_scannable_items() {
        $items = array();

        // ۱. دریافت تمام پست‌تایپ‌های عمومی (نوشته‌ها، محصولات، مقالات آکامد و...)
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']); // نادیده گرفتن فایل‌های رسانه

        $posts = get_posts(array(
            'post_type'      => array_values($post_types),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ));

        foreach ($posts as $post_id) {
            $items[] = array(
                'id'    => $post_id,
                'type'  => get_post_type($post_id),
                'title' => get_the_title($post_id),
                'url'   => get_permalink($post_id)
            );
        }

        // ۲. دریافت تمام دسته‌بندی‌های عمومی (دسته‌بندی بلاگ آکامد، دسته محصول و...)
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        // دسته‌بندی پیش‌فرض نوشته‌های بلاگ یک صفحه مستقل در معماری این سایت نیست.
        unset($taxonomies['post_tag'], $taxonomies['nav_menu'], $taxonomies['category']);

        $terms = get_terms(array(
            'taxonomy'   => array_values($taxonomies),
            'hide_empty' => false,
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $items[] = array(
                    'id'    => $term->term_id,
                    'type'  => $term->taxonomy,
                    'title' => $term->name,
                    'url'   => get_term_link($term)
                );
            }
        }

        return $items;
    }

    /**
     * صفحات و دسته‌بندی‌های بدون هیچ لینک ورودی
     */
    public function find_orphans() {
        $result = OrphanFinder::calculate($this->get_all_scannable_items());
        return $result['list'];
    }

    public function scan_item($item_id, $item_type) {
        $content = '';
        $title   = '';
        $url     = '';

        $post_types = get_post_types(array('public' => true), 'names');
        
        if (in_array($item_type, $post_types)) {
            $post = get_post($item_id);
            if (!$post) return false;
            $content = $post->post_content;
            $title   = $post->post_title;
            $url     = get_permalink($item_id);
        } else {
            $term = get_term($item_id, $item_type);
            if (!$term || is_wp_error($term)) return false;
            $content = $term->description;
            $title   = $term->name;
            $url     = get_term_link($term);
        }

        $this->database->delete_source_links($item_id, $item_type);

        if (empty(trim($content))) {
            return array('links_found' => 0);
        }

        $links = $this->extract_links_from_html($content);

        foreach ($links as $link) {
            if (!$this->is_internal_url($link['url'])) continue;

            $resolved = $this->resolve_url_to_entity($link['url']);

            $this->links->insert_link(array(
                'source_id'     => $item_id,
                'source_type'   => $item_type,
                'source_title'  => $title,
                'source_url'    => $url,
                'target_url'    => $link['url'],
                'target_id'     => $resolved['id'],
                'target_type'   => $resolved['type'],
                'anchor_text'   => mb_substr($link['anchor'], 0, 250, 'UTF-8'),
                'rel_attribute' => $link['rel'],
                'created_at'    => current_time('mysql')
            ));
        }

        return array('links_found' => count($links));
    }

    private function extract_links_from_html($html) {
        $extracted_links = array();
        if (empty(trim($html))) return $extracted_links;

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tags = $dom->getElementsByTagName('a');
        foreach ($tags as $tag) {
            $href = trim($tag->getAttribute('href'));
            $rel  = trim($tag->getAttribute('rel'));
            $text = trim($tag->textContent);

            if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'javascript:') === 0) continue;

            $extracted_links[] = array(
                'url'    => untrailingslashit($href),
                'anchor' => !empty($text) ? $text : '[بدون انکر تکست]',
                'rel'    => $rel
            );
        }

        return $extracted_links;
    }

    private function is_internal_url($url) {
        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) return true;
        $host = strtolower($parsed['host']);
        return ($host === $this->site_host || strpos($host, '.' . $this->site_host) !== false);
    }

    private function resolve_url_to_entity($url) {
        $clean_url = strtok($url, '?');
        $clean_url = strtok($clean_url, '#');

        $post_id = url_to_postid($clean_url);
        if ($post_id > 0) {
            return array('id' => $post_id, 'type' => get_post_type($post_id));
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_tag'], $taxonomies['nav_menu'], $taxonomies['category']);
        $path = wp_parse_url($clean_url, PHP_URL_PATH);
        
        if ($path) {
            $slugs = array_values(array_filter(explode('/', trim($path, '/'))));
            if (!empty($slugs)) {
                $candidate_slug = end($slugs);
                foreach ($taxonomies as $tax) {
                    $term = get_term_by('slug', $candidate_slug, $tax);
                    if ($term && !is_wp_error($term)) {
                        return array('id' => $term->term_id, 'type' => $tax);
                    }
                }
            }
        }

        return array('id' => null, 'type' => 'unknown_internal');
    }
}