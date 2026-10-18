<?php
/**
 * Data access for the bulk product-category queue: category list with content / SEO status, link-planner data and
 * saving a generated description together with its Yoast / Rank Math SEO fields.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Core\Settings;
use Khazan\Services\Content\ContentGuard;
use Khazan\Services\Seo\CategorySeoWriter;
use Khazan\Services\Seo\SeoMetaService;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryRepository {

    /** @var SeoMetaService */
    private $seo;

    /** @var CategorySeoWriter */
    private $writer;

    /** @var \WP_Term[]|null request cache of every category */
    private $terms = null;

    public function __construct(SeoMetaService $seo = null, CategorySeoWriter $writer = null) {
        $this->seo    = $seo ?: new SeoMetaService();
        $this->writer = $writer ?: new CategorySeoWriter();
    }

    /** @return \WP_Term[] every category, empty ones included (cached for the request) */
    private function all_terms() {
        if ($this->terms === null) {
            $terms = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC'));
            $this->terms = (is_wp_error($terms) || !is_array($terms)) ? array() : $terms;
        }
        return $this->terms;
    }

    /**
     * Link-planner view of the site's categories: the anchor is the exact name (= focus keyphrase) of each category.
     *
     * @return array[] {id, name, url, parent, anchor}
     */
    public function all_for_linking() {
        $out = array();
        foreach ($this->all_terms() as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) continue;
            $name = trim(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'));
            $out[] = array('id' => (int) $term->term_id, 'name' => $name, 'url' => $link, 'parent' => (int) $term->parent, 'anchor' => $name);
        }
        return $out;
    }

    /** @return array<int,string> term_id => description HTML */
    public function descriptions() {
        $out = array();
        foreach ($this->all_terms() as $term) {
            $out[(int) $term->term_id] = (string) $term->description;
        }
        return $out;
    }

    /** @return array|null {id, name, url, parent, anchor} */
    public function find($id) {
        $term = get_term((int) $id, Settings::category_taxonomy());
        if (!$term || is_wp_error($term)) return null;
        $link = get_term_link($term);
        $name = trim(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'));
        return array('id' => (int) $term->term_id, 'name' => $name, 'url' => is_wp_error($link) ? '' : $link, 'parent' => (int) $term->parent, 'anchor' => $name);
    }

    /**
     * Categories for the bulk queue with their current state.
     *
     * Counters describe the whole scope (search applied, filter ignored) so total = empty + has_content.
     *
     * @param array $args filter (all|empty|has_content), search, limit
     * @return array{items:array[],has_more:bool,counts:array{total_count:int,empty_count:int,has_content_count:int}}
     */
    public function list_for_queue(array $args) {
        $filter = isset($args['filter']) ? $args['filter'] : 'all';
        $search = isset($args['search']) ? mb_strtolower(trim((string) $args['search'])) : '';
        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : 300;

        $items = array();
        $has_more = false;
        $counts = array('total_count' => 0, 'empty_count' => 0, 'has_content_count' => 0);
        $tax = Settings::category_taxonomy();
        foreach ($this->all_terms() as $term) {
            if ($search !== '' && mb_strpos(mb_strtolower($term->name), $search) === false) continue;
            $words = self::word_count($term->description);
            $has_content = $words > 0;
            $counts['total_count']++;
            $counts[$has_content ? 'has_content_count' : 'empty_count']++;
            if (($filter === 'empty' && $has_content) || ($filter === 'has_content' && !$has_content)) continue;
            if (count($items) >= $limit) { $has_more = true; continue; }

            $seo = $this->seo->read_term_seo($tax, (int) $term->term_id);
            $link = get_term_link($term);
            $items[] = array(
                'id'              => (int) $term->term_id,
                'title'           => $term->name,
                'url'             => is_wp_error($link) ? '' : $link,
                'parent'          => (int) $term->parent,
                'product_count'   => (int) $term->count,
                'has_description' => $has_content,
                'word_count'      => $words,
                'seo'             => $seo,
                'keyword_status'  => $seo['focuskw'] === '' ? 'missing' : (self::same_phrase($seo['focuskw'], $term->name) ? 'ok' : 'different'),
                'generated'       => $this->generated_info((int) $term->term_id),
            );
        }
        return array('items' => $items, 'has_more' => $has_more, 'counts' => $counts);
    }

    /** @return array|null {at, model, mode} of the last bulk generation */
    public function generated_info($id) {
        $info = json_decode((string) get_term_meta($id, Settings::META_CATEGORY_GENERATED, true), true);
        return is_array($info) && !empty($info['at']) ? $info : null;
    }

    public function mark_generated($id) {
        $info = array('at' => current_time('mysql'), 'model' => Settings::AI_MODEL, 'mode' => 'replace');
        update_term_meta($id, Settings::META_CATEGORY_GENERATED, wp_json_encode($info));
        return $info;
    }

    /**
     * Writes the description and the SEO fields. The SEO fields go first so that Yoast's indexable, which is rebuilt
     * on the term update, already sees the new focus keyphrase, title and meta description.
     *
     * @param array $seo {title, desc, focuskw}; empty = leave the SEO fields alone
     * @return array|\WP_Error {target_id, edit_url, view_url}
     */
    public function save($id, $content, array $seo = array()) {
        $id = absint($id);
        $term = $id ? get_term($id, Settings::category_taxonomy()) : null;
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_term', $id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این دسته‌بندی را ندارید.', array('status' => 403));
        }
        $content = ContentGuard::wrap(wp_kses_post((string) $content));
        if (trim($content) === '') {
            return new \WP_Error('missing_apply_content', 'محتوای انتشار الزامی است.', array('status' => 400));
        }

        update_term_meta($id, Settings::META_PREVIOUS_DESCRIPTION, $term->description);
        if ($seo) $this->writer->write(Settings::category_taxonomy(), $id, $seo);
        $result = wp_update_term($id, Settings::category_taxonomy(), array('description' => $content));
        if (is_wp_error($result)) return $result;

        $link = get_term_link($term);
        return array('target_id' => $id, 'edit_url' => get_edit_term_link($id, Settings::category_taxonomy(), Settings::product_post_type()), 'view_url' => is_wp_error($link) ? '' : $link);
    }

    private static function word_count($html) {
        $text = trim(html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8'));
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    private static function same_phrase($a, $b) {
        $norm = function ($text) { return preg_replace('/\s+/u', ' ', trim(str_replace(array("\u{200c}", 'ي', 'ك'), array('', 'ی', 'ک'), mb_strtolower((string) $text)))); };
        return $norm($a) === $norm($b);
    }
}
