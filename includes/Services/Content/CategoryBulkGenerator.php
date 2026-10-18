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
use Khazan\Services\Links\CategorySpiderLinker;

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

    /** @var CategorySpiderLinker|null */
    private $linker;

    /** @var object|null Exposes embed($params, $result). */
    private $images;

    public function __construct($categories = null, $targets = null, $database = null, $ai = null, CategorySpiderLinker $linker = null, $images = null) {
        $this->categories = $categories ?: new CategoryRepository();
        $this->targets    = $targets ?: new ContentTargetRepository();
        $this->database   = $database ?: new DatabaseRepository();
        $this->ai         = $ai;
        $this->linker     = $linker;
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
        if (function_exists('set_time_limit')) @set_time_limit(300);

        $params = $this->build_params($category, $context, $this->plan_links($category, $options));
        $result = $this->ai()->generate_bulk_category_content($params);
        if (is_wp_error($result)) return $result;

        $result = $this->images()->embed($params, $result);
        $this->database->insert_content_history($params, $result);

        $inject_seo = !empty($options['inject_seo']);
        $saved = $this->categories->save($term_id, $result['content'], $inject_seo ? array('title' => $result['seo_title'], 'desc' => $result['meta_description'], 'focuskw' => $category['name']) : array());
        if (is_wp_error($saved)) return $saved;

        return array(
            'term_id'             => $term_id,
            'title'               => $category['name'],
            'url'                 => $category['url'],
            'edit_url'            => $saved['edit_url'],
            'internal_link_count' => $result['internal_link_count'],
            'word_count'          => \Khazan\Services\Content\Guard\ParagraphGuard::words($result['content']),
            'seo_title'           => $result['seo_title'],
            'meta_description'    => $result['meta_description'],
            'image'               => isset($result['image']) ? $result['image'] : array('status' => 'skipped'),
            'generated'           => $this->categories->mark_generated($term_id),
        );
    }

    private function plan_links(array $category, array $options) {
        if (empty($options['auto_links'])) return array();
        $run_id = isset($options['run_id']) ? sanitize_key((string) $options['run_id']) : '';
        $queue  = isset($options['queue_ids']) && is_array($options['queue_ids']) ? array_values(array_filter(array_map('absint', array_slice($options['queue_ids'], 0, 5000)))) : array();
        $plan = $this->linker()->plan($category, Settings::CATEGORY_WORD_COUNT, Settings::brand_name(), $run_id === '' ? '' : Settings::CATEGORY_LINK_RUN_PREFIX . $run_id, $queue);
        return $plan['internal_links'];
    }

    private function build_params(array $category, array $context, array $links) {
        return array(
            'content_kind'    => Settings::KIND_CATEGORY,
            'target_id'       => $category['id'],
            'h1_title'        => $category['name'],
            'focus_keyword'   => $category['name'],
            'long_tail'       => '',
            'seo_synonyms'    => '',
            'word_count'      => Settings::CATEGORY_WORD_COUNT,
            'content_type'    => Settings::kind_label(Settings::KIND_CATEGORY),
            'funnel_stage'    => 'BOFU',
            'description'     => '',
            'internal_links'  => $links,
            'use_orphan_links' => false,
            'brand'           => Settings::brand_name(),
            'image_before_h2' => 3,
            'target'          => $context,
        );
    }

    private function ai() {
        if ($this->ai === null) $this->ai = new \Khazan\Services\Ai\AiService();
        return $this->ai;
    }

    private function linker() {
        if ($this->linker === null) $this->linker = CategorySpiderLinker::wordpress();
        return $this->linker;
    }

    private function images() {
        if ($this->images === null) $this->images = new \Khazan\Services\Image\ImageGenerationService();
        return $this->images;
    }
}
