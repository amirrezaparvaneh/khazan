<?php
/**
 * Generates one product-category description end to end: context -> spider link plan -> prompt and AI call ->
 * (optional) inline image before the 3rd H2 -> archive log -> save description + Yoast / Rank Math SEO.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Content;

use Khazan\Core\Settings;
use Khazan\Repository\CategoryRepository;
use Khazan\Repository\ContentTargetRepository;
use Khazan\Repository\DatabaseRepository;
use Khazan\Services\Links\CategoryLinkPlanner;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoryBulkGenerator {

    /** @var CategoryRepository Exposes find(), save(), mark_generated(). */
    private $categories;

    /** @var ContentTargetRepository Exposes get_context(). */
    private $targets;

    /** @var DatabaseRepository Exposes insert_content_history(). */
    private $database;

    /** @var object|null Exposes generate_bulk_category_content($params). */
    private $ai;

    /** @var object|null Exposes article_candidates(array $assigned): guide-article candidates for the link plan. */
    private $articles;

    /** @var object|null Exposes embed($params, $result). */
    private $images;

    public function __construct($categories = null, $targets = null, $database = null, $ai = null, $articles = null, $images = null) {
        $this->categories = $categories ?: new CategoryRepository();
        $this->targets    = $targets ?: new ContentTargetRepository();
        $this->database   = $database ?: new DatabaseRepository();
        $this->ai         = $ai;
        $this->articles   = $articles;
        $this->images     = $images;
    }

    /**
     * @param int   $term_id
     * @param array $options inject_seo, auto_links, run_id, queue_ids
     * @return array|\WP_Error
     */
    public function process($term_id, array $options) {
        $term_id  = absint($term_id);
        $category = $this->categories->find($term_id);
        if (!$category) return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        $context = $this->targets->get_context(Settings::KIND_CATEGORY, $term_id);
        if (is_wp_error($context)) return $context;
        if (function_exists('set_time_limit')) @set_time_limit(600);

        $words  = CategoryRules::target_words();
        $plan   = empty($options['auto_links']) ? array('links' => array()) : $this->plan_links($category, $words);
        $params = $this->build_params($category, $context, $plan['links'], $words);

        $result = $this->ai()->generate_bulk_category_content($params);
        if (is_wp_error($result)) return $result;

        // one repair pass for exactly what the audit found (length, density, links, headings, title/meta)
        $repaired = false;
        if (!$result['audit']['ok']) {
            $params['prev_title'] = $result['seo_title'];
            $params['prev_meta']  = $result['meta_description'];
            $fixed = $this->ai()->repair_category_content($params, \Khazan\Services\Content\Guard\WrapperGuard::unwrap($result['content']), $result['audit']['failures'], $result['missing_links']);
            if (!is_wp_error($fixed) && count($fixed["audit"]["failures"]) < count($result["audit"]["failures"])) {
                $result = $fixed;
                $repaired = true;
            }
        }

        $result = $this->images()->embed($params, $result);
        $this->database->insert_content_history($params, $result);

        $inject_seo = !empty($options['inject_seo']);
        $saved = $this->categories->save($term_id, $result['content'], $inject_seo ? array('title' => $result['seo_title'], 'desc' => $result['meta_description'], 'focuskw' => $params['focus_keyword']) : array());
        if (is_wp_error($saved)) return $saved;

        return array(
            'term_id'             => $term_id,
            'title'               => $category['name'],
            'url'                 => $category['url'],
            'edit_url'            => $saved['edit_url'],
            'internal_link_count' => $result['internal_link_count'],
            'word_count'          => $result['audit']['words'],
            'seo_title'           => $result['seo_title'],
            'meta_description'    => $result['meta_description'],
            'audit'               => $result['audit'],
            'repaired'            => $repaired,
            'notes'               => $result['notes'],
            'link_plan'           => array('target' => $plan['target'] ?? 0, 'available' => $plan['available'] ?? 0, 'missing_roles' => $plan['missing_roles'] ?? array()),
            'image'               => isset($result['image']) ? $result['image'] : array('status' => 'skipped'),
            'generated'           => $this->categories->mark_generated($term_id),
        );
    }

    /** Real targets only: parent, children, siblings, this category's products and a relevant guide article. */
    private function plan_links(array $category, $words) {
        $articles = array();
        if ($this->articles === null) $this->articles = new \Khazan\Services\Links\WordPressArticleLinkSource();
        foreach ($this->articles->article_candidates(array()) as $row) {
            $articles[] = array('id' => $row['id'], 'title' => $row['title'], 'url' => $row['url']);
        }
        return CategoryLinkPlanner::plan(
            $category,
            $this->categories->all_for_linking(),
            $this->targets->category_products($category['id']),
            $articles,
            $words
        );
    }

    private function build_params(array $category, array $context, array $links, $words) {
        $slug = BusinessFacts::slug_from_url($category['url']);
        $keywords = KeywordMap::for_page($slug, $category['name']);
        return array(
            'content_kind'       => Settings::KIND_CATEGORY,
            'target_id'          => $category['id'],
            'h1_title'           => $category['name'],
            'focus_keyword'      => $keywords['main'],
            'secondary_keywords' => $keywords['secondary'],
            'long_tail'          => '',
            'seo_synonyms'       => '',
            'word_count'         => (int) $words,
            'content_type'       => Settings::kind_label(Settings::KIND_CATEGORY),
            'funnel_stage'       => 'BOFU',
            'description'        => '',
            'internal_links'     => $links,
            'verified_sources'   => BusinessFacts::verified_sources(),
            'use_orphan_links'   => false,
            'brand'              => Settings::brand_name(),
            'image_before_h2'    => 3,
            'target'             => $context,
        );
    }

    private function ai() {
        if ($this->ai === null) $this->ai = new \Khazan\Services\Ai\AiService();
        return $this->ai;
    }

    private function images() {
        if ($this->images === null) $this->images = new \Khazan\Services\Image\ImageGenerationService();
        return $this->images;
    }
}
